<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\DiffParser;
use App\Service\GitReader;
use App\Service\GitService;
use App\Service\MarkdownRenderer;
use App\Service\NotificationService;
use App\Service\WebhookService;

/**
 * GitHub-style pull requests built on top of the existing
 * compare + programmatic merge engine.
 */
final class PullRequestController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private GitReader $gitReader;
    private MarkdownRenderer $markdown;
    private AuditLogger $auditLogger;
    private NotificationService $notifier;
    private WebhookService $webhooks;

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->auth        = new Auth($app);
        $this->gitService  = new GitService();
        $this->gitReader   = new GitReader();
        $this->markdown    = new MarkdownRenderer();
        $this->auditLogger = new AuditLogger($app);
        $this->notifier    = new NotificationService($app);
        $this->webhooks    = new WebhookService($app);
    }

    /** GET /{user}/{repo}/pulls — list open & closed pull requests */
    public function index(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM `pull_requests` WHERE `repo_id` = :repo ORDER BY `updated_at` DESC LIMIT 100',
            ['repo' => (int) $dbRepo['id']],
        );

        $open = $closed = [];

        foreach ($rows as $pr) {
            if ($pr['status'] === 'open') {
                $open[] = $pr;
            } else {
                $closed[] = $pr;
            }
        }

        $this->app->view()->display('repo/pulls.twig', [
            'owner'       => $this->displayOwner(),
            'repo'        => $dbRepo,
            'open_pulls'  => $open,
            'closed_pulls'=> $closed,
            'pulls'       => array_merge($open, $closed),
            'pulls_open_count' => count($open),
            'can_write'   => $this->canWrite((int) $dbRepo['id']),
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/pulls/new?base=&head= — creation form */
    public function create(string $user, string $repo): void
    {
        $this->auth->requireAuth();

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath      = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches      = $this->gitReader->getBranches($repoPath);
        $defaultBranch = (string) ($dbRepo['default_branch'] ?? 'main');

        $base = trim((string) ($_GET['base'] ?? $_GET['into'] ?? $defaultBranch));
        $head = trim((string) ($_GET['head'] ?? ''));

        // Guard against refs that are not plain branch names
        foreach ([$base, $head] as $candidate) {
            if ($candidate !== '' && ! in_array($candidate, $branches, true)) {
                if ($candidate !== $base) { $head = ''; }
                if ($candidate === $base && $candidate !== $defaultBranch) { $base = $defaultBranch; }
            }
        }

        $comparison = null;
        $diffFiles  = [];
        $commits    = [];
        $error      = null;

        if ($base !== '' && $head !== '') {
            try {
                $comparison = $this->gitService->compare($dbRepo['slug'], $base, $head);
                $commits    = is_array($comparison['commits'] ?? null) ? $comparison['commits'] : [];
                $diffFiles  = DiffParser::parse((string) ($comparison['diff'] ?? ''));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->app->view()->display('repo/pull-new.twig', [
            'owner'       => $this->displayOwner(),
            'current_user'=> $user,
            'repo'        => $dbRepo,
            'branches'    => $branches,
            'base'        => $base,
            'head'        => $head,
            'comparison'  => $comparison,
            'commits'     => $commits,
            'diff_files'  => $diffFiles,
            'error'       => $error,
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/pulls/new — store a new pull request */
    public function store(string $user, string $repo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            flashAndExit('flash_error', 'Your account is restricted from creating pull requests.', "/{$user}/{$dbRepo['slug']}/pulls");
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pulls");
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $body  = (string) ($_POST['body'] ?? '');
        $base  = trim((string) ($_POST['base'] ?? ''));
        $head  = trim((string) ($_POST['head'] ?? ''));

        if ($title === '') {
            flashAndExit('flash_error', 'Pull request title is required.', "/{$user}/{$dbRepo['slug']}/pulls/new?base=" . rawurlencode($base) . '&head=' . rawurlencode($head));
        }

        if ($base === '' || $head === '' || $base === $head) {
            flashAndExit('flash_error', 'Invalid source or target branch.', "/{$user}/{$dbRepo['slug']}/pulls/new");
        }

        // Both branches must exist in the repository
        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);

        if (! in_array($base, $branches, true) || ! in_array($head, $branches, true)) {
            flashAndExit('flash_error', 'Source or target branch no longer exists.', "/{$user}/{$dbRepo['slug']}/pulls/new");
        }

        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->displayNameForAuthor();

        $db = $this->app->db()->connection();

        try {
            $db->beginTransaction();

            $next = (int) $db->query(
                'SELECT COALESCE(MAX(`number`), 0) + 1 FROM `pull_requests` WHERE `repo_id` = ' . (int) $dbRepo['id'] . ' FOR UPDATE',
            )->fetchColumn();

            $stmt = $db->prepare(
                'INSERT INTO `pull_requests`
                    (`repo_id`, `number`, `title`, `body`, `source_branch`, `target_branch`, `status`, `created_by`, `author_name`, `created_at`)
                 VALUES (?, ?, ?, ?, ?, ?, \'open\', ?, ?, NOW())',
            );
            $stmt->execute([
                (int) $dbRepo['id'],
                $next,
                mb_substr($title, 0, 255),
                $body !== '' ? $body : null,
                $head,
                $base,
                $userId,
                $userName,
            ]);

            $prId = (int) $db->lastInsertId();
            $db->commit();
        } catch (\Throwable $e) {
            try { $db->rollBack(); } catch (\Throwable) {}
            flashAndExit('flash_error', 'Failed to open the pull request: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/pulls");
        }

        $link = "/{$user}/{$dbRepo['slug']}/pull/{$next}";

        $this->auditLogger->log('pr.open', (int) $dbRepo['id'], "Opened PR #{$next}: {$title}", $userId, $userName);

        $this->notifier->notifyRepoUsers(
            (int) $dbRepo['id'],
            'pull_request',
            "{$userName} opened pull request #{$next}: {$title}",
            $link,
            $userId,
        );

        $this->webhooks->dispatch($dbRepo['slug'], 'pull_request', [
            'action' => 'opened',
            'number' => $next,
            'title'  => $title,
            'source' => $head,
            'target' => $base,
            'author' => $userName,
        ]);

        header("Location: {$link}");
        exit;
    }

    /** GET /{user}/{repo}/pull/{number} — conversation view */
    public function show(string $user, string $repo, int $number): void
    {
        $this->renderPr($user, $repo, $number, 'conversation');
    }

    /** GET /{user}/{repo}/pull/{number}/files — files-changed view */
    public function showFiles(string $user, string $repo, int $number): void
    {
        $this->renderPr($user, $repo, $number, 'files');
    }

    private function renderPr(string $user, string $repo, int $number, string $tab): void
    {
        [$dbRepo, $pr] = $this->resolvePr($repo, $number);
        if ($dbRepo === null || $pr === null) {
            $this->notFound();
            return;
        }

        $comments = $this->app->db()->fetchAll(
            'SELECT * FROM `pr_comments` WHERE `pr_id` = :pr ORDER BY `created_at` ASC',
            ['pr' => (int) $pr['id']],
        );

        foreach ($comments as &$c) {
            $c['body_html'] = $this->markdown->renderHtml((string) $c['body']);
        }
        unset($c);

        $reviewComments = $this->app->db()->fetchAll(
            'SELECT * FROM `pr_review_comments` WHERE `pr_id` = :pr ORDER BY `created_at` ASC',
            ['pr' => (int) $pr['id']],
        );

        // Key review comments by "path|line|side" so the template can drop
        // them directly under the matching diff line.
        $reviewByKey = [];
        foreach ($reviewComments as $rc) {
            $key = $rc['file_path'] . '|' . (int) $rc['line_number'] . '|' . ($rc['side'] ?? 'new');
            $reviewByKey[$key][] = [
                'author_name' => (string) $rc['author_name'],
                'body_html'   => $this->markdown->renderHtml((string) $rc['body']),
                'line_number' => (int) $rc['line_number'],
                'created_at'  => $rc['created_at'],
                'anchor'      => 'r-' . md5($rc['file_path'] . ':' . $rc['line_number']),
            ];
        }

        // Multi-reviewer panel data (latest decision per reviewer).
        $reviewers = $this->app->db()->fetchAll(
            'SELECT rv.`decision`, rv.`created_at`, u.`username`
             FROM `pr_reviews` rv
             LEFT JOIN `users` u ON u.id = rv.user_id
             WHERE rv.`pr_id` = :pr
             ORDER BY rv.`created_at` DESC',
            ['pr' => (int) $pr['id']],
        );

        $comparison = null;
        $diffFiles  = [];
        $commits    = [];
        $aheadCount  = 0;
        $behindCount = 0;
        $canMerge   = false;

        $isOpen = ((string) $pr['status']) === 'open';

        if ($isOpen) {
            try {
                $comparison = $this->gitService->compare($dbRepo['slug'], (string) $pr['source_branch'], (string) $pr['target_branch']);
                $diffFiles  = DiffParser::parse((string) ($comparison['diff'] ?? ''));
                $commits    = is_array($comparison['commits'] ?? null) ? $comparison['commits'] : [];
                $aheadCount  = (int) ($comparison['ahead_count'] ?? 0);
                $behindCount = (int) ($comparison['behind_count'] ?? 0);
                $canMerge   = (bool) ($comparison['can_merge'] ?? false) && $aheadCount > 0;
            } catch (\Throwable) {
                // Source or target branch may be gone; show what we have.
            }
        }

        $bodyHtml = '';
        if (! empty($pr['body'])) {
            $bodyHtml = $this->markdown->renderHtml((string) $pr['body']);
        }

        $this->app->view()->display('repo/pull-show.twig', [
            'owner'          => $this->displayOwner(),
            'current_user'   => $user,
            'repo'           => $dbRepo,
            'pr'             => $pr,
            'pr_body_html'   => $bodyHtml,
            'tab'            => $tab,
            'comments'       => $comments,
            'review_by_key'  => $reviewByKey,
            'review_total'   => count($reviewComments),
            'reviewers'      => $reviewers,
            'commits'        => $commits,
            'diff_files'     => $diffFiles,
            'ahead_count'    => $aheadCount,
            'behind_count'   => $behindCount,
            'can_merge'      => $canMerge,
            'is_open'        => $isOpen,
            'can_write'      => $this->canWrite((int) $dbRepo['id']),
            'is_creator'     => $this->isCreator($pr),
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/pull/{number}/comment — conversation comment */
    public function comment(string $user, string $repo, int $number): void
    {
        $this->auth->requireAuth();
        [$dbRepo, $pr] = $this->resolvePr($repo, $number);
        if ($dbRepo === null || $pr === null) {
            $this->notFound();
            return;
        }

        // Read-only and bot accounts cannot comment (consistent with issues).
        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            flashAndExit('flash_error', 'Your account is restricted from posting comments.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            flashAndExit('flash_error', 'Comment cannot be empty.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        // Cap comment length (matches the issues comment limit).
        if (mb_strlen($body) > 20000) {
            flashAndExit('flash_error', 'Comment is too long (maximum 20,000 characters).', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $this->insertComment((int) $pr['id'], (string) $this->displayNameForAuthor(), $this->identityId(), $body);

        if ((int) $pr['created_by'] > 0 && (int) $pr['created_by'] !== $this->identityId()) {
            $this->notifier->notifyUser(
                (int) $pr['created_by'],
                (int) $dbRepo['id'],
                'pull_request',
                $this->displayNameForAuthor() . " commented on pull request #{$number}",
                "/{$user}/{$dbRepo['slug']}/pull/{$number}",
            );
        }

        header("Location: /{$user}/{$dbRepo['slug']}/pull/{$number}");
        exit;
    }

    /** POST /{user}/{repo}/pull/{number}/review-comment — line comment */
    public function reviewComment(string $user, string $repo, int $number): void
    {
        $this->auth->requireAuth();
        [$dbRepo, $pr] = $this->resolvePr($repo, $number);
        if ($dbRepo === null || $pr === null) {
            $this->notFound();
            return;
        }

        // Read-only and bot accounts cannot comment (consistent with issues).
        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            flashAndExit('flash_error', 'Your account is restricted from posting comments.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $filePath = trim((string) ($_POST['file_path'] ?? ''));
        $line     = (int) ($_POST['line_number'] ?? 0);
        $side     = ($_POST['side'] ?? 'new') === 'old' ? 'old' : 'new';
        $body     = trim((string) ($_POST['body'] ?? ''));

        if ($filePath === '' || $body === '') {
            flashAndExit('flash_error', 'File path and comment body are required.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        // Cap comment length (matches the issues comment limit).
        if (mb_strlen($body) > 20000) {
            flashAndExit('flash_error', 'Comment is too long (maximum 20,000 characters).', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        try {
            $this->app->db()->execute(
                'INSERT INTO `pr_review_comments` (`pr_id`, `user_id`, `author_name`, `file_path`, `line_number`, `side`, `body`, `created_at`)
                 VALUES (:pr, :user, :author, :path, :line, :side, :body, NOW())',
                [
                    'pr'     => (int) $pr['id'],
                    'user'   => $this->identityId(),
                    'author' => $this->displayNameForAuthor(),
                    'path'   => mb_substr($filePath, 0, 500),
                    'line'   => max(0, min(1000000, $line)),
                    'side'   => $side,
                    'body'   => $body,
                ],
            );
        } catch (\Throwable $e) {
            flashAndExit('flash_error', 'Failed to save review comment: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        if ((int) $pr['created_by'] > 0 && (int) $pr['created_by'] !== $this->identityId()) {
            $this->notifier->notifyUser(
                (int) $pr['created_by'],
                (int) $dbRepo['id'],
                'pull_request',
                $this->displayNameForAuthor() . " reviewed pull request #{$number} ({$filePath})",
                "/{$user}/{$dbRepo['slug']}/pull/{$number}/files#r-" . md5($filePath . ':' . $line),
            );
        }

        header("Location: /{$user}/{$dbRepo['slug']}/pull/{$number}/files#r-" . md5($filePath . ':' . $line));
        exit;
    }

    /** POST /{user}/{repo}/pull/{number}/approval — approve or request changes. */
    public function approval(string $user, string $repo, int $number): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $pr = $this->loadPr($dbRepo, $number);
        if ($pr === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $decision = (string) ($_POST['decision'] ?? '');
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            flashAndExit('flash_error', 'Invalid decision.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $userName = $this->displayNameForAuthor();

        // Multi-reviewer ledger: one row per reviewer with UPSERT semantics
        // (the latest decision of each reviewer wins). The legacy
        // `approval` column is kept in sync for backward compatibility.
        $userId = $this->identityId();
        if ($userId > 0 || $this->auth->isOwner()) {
            $this->app->db()->execute(
                'INSERT INTO `pr_reviews` (`pr_id`, `user_id`, `decision`)
                 VALUES (:pr, :uid, :decision)
                 ON DUPLICATE KEY UPDATE `decision` = VALUES(`decision`), `created_at` = NOW()',
                ['pr' => (int) $pr['id'], 'uid' => $userId, 'decision' => $decision],
            );
        }

        $this->app->db()->execute(
            'UPDATE `pull_requests`
             SET `approval` = :decision, `approved_by` = :who, `approved_at` = NOW()
             WHERE `id` = :id',
            ['decision' => $decision, 'who' => $userName, 'id' => (int) $pr['id']],
        );

        $this->webhooks->dispatch($dbRepo['slug'], 'pull_request', [
            'action'   => 'review',
            'number'   => $number,
            'decision' => $decision,
            'reviewer' => $userName,
            'title'    => $pr['title'],
        ]);

        $this->notifier->notifyRepoUsers(
            (int) $dbRepo['id'],
            'pull_request',
            "{$userName} " . ($decision === 'approved' ? 'approved' : 'requested changes on') . " pull request #{$number}",
            "/{$user}/{$dbRepo['slug']}/pull/{$number}",
            $this->identityId(),
        );

        flashAndExit('flash_success', $decision === 'approved' ? 'Pull request approved.' : 'Changes requested.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
    }

    /** POST /{user}/{repo}/pull/{number}/merge — merge the pull request */
    public function merge(string $user, string $repo, int $number): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if ($this->auth->isReadOnly()) {
            flashAndExit('flash_error', 'Your account is restricted to Read-Only mode.', "/{$user}/{$repo}/pull/{$number}");
        }

        [$prOk, $pr] = [true, $this->loadPr($dbRepo, $number)];
        if ($pr === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        if ((string) $pr['status'] !== 'open') {
            flashAndExit('flash_error', "Pull request #{$number} is not open.", "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        // Branch protection may require an approved review before merging.
        $prot = $this->app->db()->fetchOne(
            'SELECT `allow_admin`, `require_approval` FROM `branch_protections`
             WHERE `repo_id` = :repo AND `branch_name` = :branch LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'branch' => (string) $pr['target_branch']],
        );

        if (
            $prot !== false
            && ! empty($prot['require_approval'])
            && (string) $pr['approval'] !== 'approved'
            && ! $this->auth->isOwner()
        ) {
            flashAndExit(
                'flash_error',
                'A review approval is required before this pull request can be merged.',
                "/{$user}/{$dbRepo['slug']}/pull/{$number}",
            );
        }

        $mergeMsg = trim((string) ($_POST['merge_message'] ?? ''));
        $strategy = (string) ($_POST['merge_strategy'] ?? 'merge');
        $strategy = in_array($strategy, ['merge', 'squash', 'rebase', 'ff'], true) ? $strategy : 'merge';
        $authorName  = $this->displayNameForAuthor();
        $authorEmail = $this->emailForAuthor();

        $result = $this->gitService->merge(
            $dbRepo['slug'],
            (string) $pr['target_branch'],
            (string) $pr['source_branch'],
            $authorName,
            $authorEmail,
            $mergeMsg !== '' ? $mergeMsg : "Merge pull request #{$number} from " . $pr['source_branch'],
            $strategy,
        );

        if (! $result['ok']) {
            flashAndExit('flash_error', $result['error'] ?? 'Merge failed.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $commitSha = (string) ($result['commit_sha'] ?? '');

        try {
            $this->app->db()->execute(
                'UPDATE `pull_requests`
                 SET `status` = \'merged\', `merged_commit` = :sha, `merged_at` = NOW(), `merge_strategy` = :strategy
                 WHERE `id` = :id AND `status` = \'open\'',
                ['sha' => $commitSha, 'strategy' => $strategy, 'id' => (int) $pr['id']],
            );
        } catch (\Throwable $e) {
            // Merge succeeded at git level; DB bookkeeping failure is logged only.
            error_log('[PR] failed to mark merged: ' . $e->getMessage());
        }

        $userId   = $this->identityId();
        $userName = $authorName;

        $this->auditLogger->log('pr.merge', (int) $dbRepo['id'], "Merged PR #{$number} ({$strategy})", $userId, $userName);

        $this->notifier->notifyRepoUsers(
            (int) $dbRepo['id'],
            'pull_request',
            "{$userName} merged pull request #{$number}",
            "/{$user}/{$dbRepo['slug']}/pull/{$number}",
            $userId,
        );

        if ((int) $pr['created_by'] > 0 && (int) $pr['created_by'] !== $userId) {
            $this->notifier->notifyUser(
                (int) $pr['created_by'],
                (int) $dbRepo['id'],
                'pull_request',
                "Your pull request #{$number} was merged",
                "/{$user}/{$dbRepo['slug']}/pull/{$number}",
            );
        }

        $this->webhooks->dispatch($dbRepo['slug'], 'pull_request', [
            'action'      => 'merged',
            'number'      => $number,
            'title'       => $pr['title'],
            'source'      => $pr['source_branch'],
            'target'      => $pr['target_branch'],
            'merged_by'   => $userName,
            'commit_sha'  => $commitSha,
        ]);

        // Refresh cached views of the target branch
        foreach (["tree:" . $pr['target_branch'] . ":ROOT", "commits:" . $pr['target_branch'] . ":1"] as $suffix) {
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:{$suffix}");
        }

        flashAndExit('flash_success', "Pull request #{$number} merged successfully.", "/{$user}/{$dbRepo['slug']}/pull/{$number}");
    }

    /** POST /{user}/{repo}/pull/{number}/close — close/reopen */
    public function toggle(string $user, string $repo, int $number): void
    {
        $this->auth->requireAuth();
        [$dbRepo, $pr] = $this->resolvePr($repo, $number);
        if ($dbRepo === null || $pr === null) {
            $this->notFound();
            return;
        }

        if (! $this->canWrite((int) $dbRepo['id']) && ! $this->isCreator($pr)) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 403, 'message' => 'You cannot change this pull request.']);
            return;
        }

        if (! $this->auth->validateCsrf()) {
            flashAndExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        if ((string) $pr['status'] === 'merged') {
            flashAndExit('flash_error', 'A merged pull request cannot be reopened.', "/{$user}/{$dbRepo['slug']}/pull/{$number}");
        }

        $closing = ((string) $pr['status']) === 'open';

        $this->app->db()->execute(
            $closing
                ? 'UPDATE `pull_requests` SET `status` = \'closed\', `closed_at` = NOW() WHERE `id` = :id'
                : 'UPDATE `pull_requests` SET `status` = \'open\', `closed_at` = NULL WHERE `id` = :id',
            ['id' => (int) $pr['id']],
        );

        $action  = $closing ? 'closed' : 'reopened';
        $userId  = $this->identityId();
        $userName= $this->displayNameForAuthor();

        $this->auditLogger->log('pr.' . $action, (int) $dbRepo['id'], "PR #{$number} {$action}", $userId, $userName);

        $this->webhooks->dispatch($dbRepo['slug'], 'pull_request', [
            'action' => $action,
            'number' => $number,
            'title'  => $pr['title'],
            'actor'  => $userName,
        ]);

        flashAndExit('flash_success', "Pull request #{$number} {$action}.", "/{$user}/{$dbRepo['slug']}/pull/{$number}");
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** @return array{?array<string,mixed>, ?array<string,mixed>} */
    private function resolvePr(string $slug, int $number): array
    {
        $dbRepo = $this->resolveRepo($slug);
        if ($dbRepo === null) {
            return [null, null];
        }

        $pr = $this->loadPr($dbRepo, $number);

        return [$dbRepo, $pr];
    }

    /** @return array<string, mixed>|null */
    private function loadPr(array $dbRepo, int $number): ?array
    {
        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `pull_requests` WHERE `repo_id` = :repo AND `number` = :num LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'num' => $number],
        );

        return $row !== false ? $row : null;
    }

    private function insertComment(int $prId, string $authorName, int $userId, string $body): void
    {
        $this->app->db()->execute(
            'INSERT INTO `pr_comments` (`pr_id`, `user_id`, `author_name`, `body`, `created_at`)
             VALUES (:pr, :user, :author, :body, NOW())',
            [
                'pr'     => $prId,
                'user'   => max(0, $userId),
                'author' => mb_substr($authorName, 0, 100),
                'body'   => $body,
            ],
        );
    }

    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    private function displayNameForAuthor(): string
    {
        if ($this->auth->isOwner()) return 'owner';

        return (string) ($this->auth->user()['username'] ?? 'unknown');
    }

    private function emailForAuthor(): string
    {
        return $this->auth->isOwner() ? 'owner@localhost' : (string) ($this->auth->user()['email'] ?? 'user@localhost');
    }

    private function isCreator(array $pr): bool
    {
        return ! $this->auth->isOwner()
            && $this->auth->isLoggedIn()
            && (int) $pr['created_by'] > 0
            && (int) $pr['created_by'] === (int) ($this->auth->user()['id'] ?? -1);
    }

    private function canWrite(int $repoId): bool
    {
        return $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo($repoId));
    }

    private function displayOwner(): string
    {
        return (string) $this->app->config('app.owner', 'admin');
    }

    /** @return array<string, mixed>|null */
    private function resolveRepo(string $slug): ?array
    {
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;
        if ($slug === '') return null;

        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );
        if ($row === false) return null;

        if (! $this->auth->canViewRepo((int) $row['id'], (string) $row['visibility'])) {
            if (! $this->auth->isLoggedIn()) {
                header('Location: /login');
                exit;
            }
            return null;
        }

        return $row;
    }

    private function requireWriteAccess(string $slug, ?array &$dbRepo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($slug);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if (! $this->canWrite((int) $dbRepo['id'])) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 403, 'message' => 'You do not have write access to this repository.']);
            $dbRepo = null;
        }
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->app->view()->render('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository or pull request not found.',
        ]);
    }
}

/** Set a session flash and redirect+exit (works even after sessions closed). */
function flashAndExit(string $key, string $message, string $location): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION[$key] = $message;
    header('Location: ' . $location);
    exit;
}
