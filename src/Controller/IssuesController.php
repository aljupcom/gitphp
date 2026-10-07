<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Middleware\RateLimit;
use App\Service\MarkdownRenderer;

/**
 * Bug reports ("Issues"): users describe bugs in a repository with technical
 * details; the admin reviews the list and can open/resolve/close each report.
 */
final class IssuesController
{
    private App $app;
    private Auth $auth;
    private MarkdownRenderer $markdown;

    public function __construct(App $app)
    {
        $this->app      = $app;
        $this->auth     = new Auth($app);
        $this->markdown = new MarkdownRenderer();
    }

    /** GET /{user}/{repo}/issues — list the repository's bug reports. */
    public function index(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoId = (int) $dbRepo['id'];

        // GitHub-style state tabs: open | closed (resolved+closed) | all
        $state = (string) ($_GET['state'] ?? 'open');
        $state = in_array($state, ['open', 'closed', 'all'], true) ? $state : 'open';

        // ── Filters: label / milestone / assignee / sort + pagination ──
        $labelSlug     = trim((string) ($_GET['label'] ?? ''));
        $milestoneId   = (int) ($_GET['milestone'] ?? 0);
        $assigneeId    = (int) ($_GET['assignee'] ?? 0);
        $sort          = (string) ($_GET['sort'] ?? 'newest');
        $sort          = in_array($sort, ['newest', 'oldest', 'updated'], true) ? $sort : 'newest';
        $page          = max(1, (int) ($_GET['page'] ?? 1));
        $perPage       = 25;

        $where  = ['b.repo_id = :repo'];
        $params = ['repo' => $repoId];

        if ($state === 'closed') {
            $where[] = "b.status IN ('resolved','closed')";
        } elseif ($state !== 'all') {
            $where[] = "b.status = 'open'";
        }

        if ($milestoneId > 0) {
            $where[] = 'b.milestone_id = :milestone';
            $params['milestone'] = $milestoneId;
        }
        if ($assigneeId > 0) {
            $where[] = 'b.assigned_to = :assignee';
            $params['assignee'] = $assigneeId;
        }
        if ($labelSlug !== '') {
            $where[] = 'b.id IN (SELECT ilm.issue_id FROM issue_label_map ilm
                                 JOIN issue_labels il ON il.id = ilm.label_id
                                 WHERE il.repo_id = :repo AND il.name = :label)';
            $params['label'] = $labelSlug;
        }

        $orderBy = match ($sort) {
            'oldest' => 'b.created_at ASC',
            'updated' => 'b.updated_at DESC',
            default => 'b.created_at DESC',
        };

        $whereSql = implode(' AND ', $where);

        $total = (int) ($this->app->db()->fetchOne(
            "SELECT COUNT(*) AS c FROM bug_reports b WHERE {$whereSql}",
            $params,
        )['c'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $issues = $this->app->db()->fetchAll(
            "SELECT b.*, u.username AS reporter, au.username AS assignee_name,
                    m.title AS milestone_title
             FROM `bug_reports` b
             LEFT JOIN `users` u ON u.id = b.user_id
             LEFT JOIN `users` au ON au.id = b.assigned_to
             LEFT JOIN `issue_milestones` m ON m.id = b.milestone_id
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        // Attach labels to each listed issue (one grouped query).
        $labelsByIssue = [];
        if ($issues !== []) {
            $ids = implode(',', array_map(static fn(array $i): int => (int) $i['id'], $issues));
            foreach ($this->app->db()->fetchAll(
                "SELECT ilm.issue_id, il.name, il.color
                 FROM issue_label_map ilm JOIN issue_labels il ON il.id = ilm.label_id
                 WHERE ilm.issue_id IN ({$ids})"
            ) as $row) {
                $labelsByIssue[(int) $row['issue_id']][] = ['name' => $row['name'], 'color' => $row['color']];
            }
        }
        foreach ($issues as &$i) {
            $i['labels'] = $labelsByIssue[(int) $i['id']] ?? [];
        }
        unset($i);

        $closedCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `bug_reports`
             WHERE `repo_id` = :repo AND `status` != :status',
            ['repo' => $repoId, 'status' => 'open'],
        )['c'] ?? 0);

        $openCount = (int) ($this->app->db()->fetchOne(
            "SELECT COUNT(*) AS `c` FROM `bug_reports`
             WHERE `repo_id` = :repo AND `status` = 'open'",
            ['repo' => $repoId],
        )['c'] ?? 0);

        $canWrite = $this->auth->isOwner()
            || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo($repoId));

        // Filter chips data
        $repoLabels = $canWrite || true
            ? $this->app->db()->fetchAll('SELECT * FROM `issue_labels` WHERE `repo_id` = :repo ORDER BY `name`', ['repo' => $repoId])
            : [];
        $milestones = $this->app->db()->fetchAll(
            'SELECT m.*, (SELECT COUNT(*) FROM bug_reports b WHERE b.milestone_id = m.id AND b.status = \'open\') AS open_count
             FROM `issue_milestones` m WHERE m.repo_id = :repo ORDER BY m.due_date IS NULL, m.due_date ASC, m.id DESC',
            ['repo' => $repoId],
        );
        $collaborators = $this->app->db()->fetchAll(
            'SELECT u.id, u.username FROM `repo_collaborators` rc JOIN `users` u ON u.id = rc.user_id
             WHERE rc.repo_id = :repo ORDER BY u.username',
            ['repo' => $repoId],
        );

        $this->app->view()->display('repo/issues.twig', [
            'repo'              => $dbRepo,
            'owner'             => $user,
            'active_tab'        => 'issues',
            'issues'            => $issues,
            'state'             => $state,
            'closed_count'      => $closedCount,
            'issues_open_count' => $openCount,
            'can_write'         => $canWrite,
            'repo_labels'       => $repoLabels,
            'milestones'        => $milestones,
            'collaborators'     => $collaborators,
            'current_filters'   => [
                'label'     => $labelSlug,
                'milestone' => $milestoneId,
                'assignee'  => $assigneeId,
                'sort'      => $sort,
            ],
            'pagination'        => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'last_page'   => max(1, (int) ceil($total / $perPage)),
            ],
            'csrf_token'        => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/issues/new — report-a-bug form. */
    public function newIssue(string $user, string $repo): void
    {
        $this->auth->requireUser();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $this->app->view()->display('repo/issue-new.twig', [
            'repo'              => $dbRepo,
            'owner'             => $user,
            'active_tab'        => 'issues',
            'csrf_token'        => $this->auth->generateCsrf(),
            // error flash is injected globally during App::boot()
            'old'               => $_SESSION['issue_old'] ?? [],
            'issues_open_count' => $this->openCount((int) $dbRepo['id']),
        ]);

        unset($_SESSION['issue_old']);
    }

    /** POST /{user}/{repo}/issues — validate and store a bug report. */
    public function create(string $user, string $repo): void
    {
        $this->auth->requireUser();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $fail = function (string $message, array $old) use ($user, $dbRepo): never {
            $_SESSION['flash_error'] = $message;
            $_SESSION['issue_old']   = $old;
            header("Location: /{$user}/{$dbRepo['slug']}/issues/new");
            exit;
        };

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $fail('Your account is restricted from submitting bug reports or issues.', []);
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $fail('Invalid security token.', []);
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimit($this->app);
        $rateKey     = "issue_{$clientIp}_{$dbRepo['id']}";

        if (! $rateLimiter->check($rateKey, maxAttempts: 10, decayMinutes: 30)) {
            $fail('Too many bug reports. Please wait before submitting another one.', []);
        }

        $title      = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $environment = trim((string) ($_POST['environment'] ?? ''));
        $steps      = trim((string) ($_POST['steps_to_reproduce'] ?? ''));

        $old = [
            'title'               => $title,
            'description'         => $description,
            'environment'         => $environment,
            'steps_to_reproduce'  => $steps,
        ];

        if ($title === '' || mb_strlen($title) > 255) {
            $fail('Please provide a title (max 255 characters).', $old);
        }

        if ($description === '' || mb_strlen($description) > 20000) {
            $fail('Please describe the bug (max 20000 characters).', $old);
        }

        if (mb_strlen($environment) > 5000) {
            $fail('The environment details are too long (max 5000 characters).', $old);
        }

        if (mb_strlen($steps) > 10000) {
            $fail('The reproduction steps are too long (max 10000 characters).', $old);
        }

        $rateLimiter->increment($rateKey);

        $this->app->db()->execute(
            'INSERT INTO `bug_reports`
                (`repo_id`, `user_id`, `title`, `description`, `environment`, `steps_to_reproduce`)
             VALUES (:repo, :user, :title, :desc, :env, :steps)',
            [
                'repo'  => (int) $dbRepo['id'],
                'user'  => $this->auth->userId(),
                'title' => $title,
                'desc'  => $description,
                'env'   => $environment !== '' ? $environment : null,
                'steps' => $steps !== '' ? $steps : null,
            ],
        );

        $issueId = (int) $this->app->db()->lastInsertId();

        $_SESSION['flash_success'] = 'Bug report submitted. The administrator will review it.';
        header("Location: /{$user}/{$dbRepo['slug']}/issues/{$issueId}");
        exit;
    }

    /** GET /{user}/{repo}/issues/{id} — a single bug report. */
    public function show(string $user, string $repo, string $id): void
    {
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $issue = $this->app->db()->fetchOne(
            'SELECT b.*, u.username AS reporter
             FROM `bug_reports` b
             LEFT JOIN `users` u ON u.id = b.user_id
             WHERE b.id = :id AND b.repo_id = :repo
             LIMIT 1',
            ['id' => (int) $id, 'repo' => (int) $dbRepo['id']],
        );

        if ($issue === false) {
            $this->notFound();
            return;
        }

        // Conversation comments, newest last.
        $comments = $this->app->db()->fetchAll(
            'SELECT * FROM `bug_comments` WHERE `bug_id` = :bug ORDER BY `created_at` ASC',
            ['bug' => (int) $issue['id']],
        );

        foreach ($comments as &$c) {
            $c['body_html'] = $this->markdown->renderHtml((string) $c['body']);
        }
        unset($c);

        // Permission model:
        //   manager  = owner or write-collaborator → any status change
        //   reporter = creator of this report      → open <-> closed only
        $canManage = $this->auth->isOwner()
            || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        $isReporter = $this->auth->isLoggedIn()
            && $issue['user_id'] !== null
            && (int) $issue['user_id'] === $this->identityId();

        // Metadata for the management panel (labels / milestone / assignee).
        $issueLabels = $this->app->db()->fetchAll(
            'SELECT il.`id`, il.`name`, il.`color`
             FROM `issue_label_map` ilm JOIN `issue_labels` il ON il.id = ilm.label_id
             WHERE ilm.issue_id = :iid ORDER BY il.`name`',
            ['iid' => (int) $issue['id']],
        );
        $repoLabels   = $this->app->db()->fetchAll('SELECT * FROM `issue_labels` WHERE `repo_id` = :repo ORDER BY `name`', ['repo' => (int) $dbRepo['id']]);
        $milestones   = $this->app->db()->fetchAll('SELECT * FROM `issue_milestones` WHERE `repo_id` = :repo ORDER BY `title`', ['repo' => (int) $dbRepo['id']]);
        $collaborators = $this->app->db()->fetchAll(
            'SELECT u.`id`, u.`username` FROM `repo_collaborators` rc JOIN `users` u ON u.id = rc.user_id
             WHERE rc.repo_id = :repo ORDER BY u.`username`',
            ['repo' => (int) $dbRepo['id']],
        );

        $this->app->view()->display('repo/issue-show.twig', [
            'repo'              => $dbRepo,
            'owner'             => $user,
            'active_tab'        => 'issues',
            'issue'             => $issue,
            'description_html'  => $this->markdown->renderHtml((string) $issue['description']),
            'steps_html'        => $this->markdown->renderHtml((string) ($issue['steps_to_reproduce'] ?? '')),
            'comments'          => $comments,
            'can_manage'        => $canManage,
            'is_reporter'       => $isReporter,
            'issue_labels'      => $issueLabels,
            'repo_labels'       => $repoLabels,
            'milestones'        => $milestones,
            'collaborators'     => $collaborators,
            'csrf_token'        => $this->auth->generateCsrf(),
            'issues_open_count' => $this->openCount((int) $dbRepo['id']),
        ]);
    }

    /** POST /{user}/{repo}/issues/{id}/comment — join the conversation. */
    public function comment(string $user, string $repo, string $id): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        // Same restriction policy as updateStatus(): read-only and bot
        // accounts cannot post comments.
        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $_SESSION['flash_error'] = 'Your account is restricted from posting comments.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            $_SESSION['flash_error'] = 'Comment cannot be empty.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        // Cap comment length (consistent with the issue description limit).
        if (mb_strlen($body) > 20000) {
            $_SESSION['flash_error'] = 'Comment is too long (maximum 20,000 characters).';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        $issue = $this->app->db()->fetchOne(
            'SELECT `id`, `title`, `user_id` FROM `bug_reports`
             WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => (int) $id, 'repo' => (int) $dbRepo['id']],
        );

        if ($issue === false) {
            $this->notFound();
            return;
        }

        $authorName = $this->auth->isOwner() ? 'owner' : (string) ($this->auth->user()['username'] ?? 'anonymous');
        $authorId   = $this->auth->isOwner() ? 0 : $this->identityId();

        $this->app->db()->execute(
            'INSERT INTO `bug_comments` (`bug_id`, `user_id`, `author_name`, `body`, `created_at`)
             VALUES (:bug, :uid, :author, :body, NOW())',
            [
                'bug'    => (int) $issue['id'],
                'uid'    => $authorId,
                'author' => mb_substr($authorName, 0, 100),
                'body'   => $body,
            ],
        );

        // Notify the reporter when someone else joins their thread.
        // user_id NULL = anonymous guest (no account to notify).
        if ($issue['user_id'] !== null && (int) $issue['user_id'] !== $authorId) {
            $this->app->db()->execute(
                'INSERT INTO `notifications` (`user_id`, `repo_id`, `type`, `message`, `link`)
                 VALUES (:user, :repo, \'issue\', :message, :link)',
                [
                    'user'    => (int) $issue['user_id'],
                    'repo'    => (int) $dbRepo['id'],
                    'message' => "{$authorName} commented on \"{$issue['title']}\"",
                    'link'    => "/{$user}/{$dbRepo['slug']}/issues/{$id}",
                ],
            );
        }

        header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}#comments");
        exit;
    }

    /** POST /{user}/{repo}/issues/{id}/status — status change with tiered permissions. */
    public function updateStatus(string $user, string $repo, string $id): void
    {
        $this->auth->requireAuth();

        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if ($this->auth->isReadOnly() || $this->auth->isBot()) {
            $_SESSION['flash_error'] = 'Your account is restricted from posting comments.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        $status = (string) ($_POST['status'] ?? '');
        if (! in_array($status, ['open', 'resolved', 'closed'], true)) {
            $_SESSION['flash_error'] = 'Invalid status.';
            header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
            exit;
        }

        // Managers (owner / write collaborators) may set any status.
        // Reporters may only close or reopen their own report.
        $canManage = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);

        if (! $canManage) {
            $issue = $this->app->db()->fetchOne(
                'SELECT `user_id` FROM `bug_reports`
                 WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
                ['id' => (int) $id, 'repo' => (int) $dbRepo['id']],
            );

            $isReporter = $issue !== false
                && $issue['user_id'] !== null
                && (int) $issue['user_id'] === $this->identityId();

            if (! $isReporter || ! in_array($status, ['open', 'closed'], true)) {
                http_response_code(403);
                echo $this->app->view()->render('partials/error.html.twig', [
                    'code'    => 403,
                    'message' => 'You can only close or reopen your own reports.',
                ]);
                return;
            }
        }

        $updated = $this->app->db()->execute(
            'UPDATE `bug_reports` SET `status` = :status
             WHERE `id` = :id AND `repo_id` = :repo',
            ['status' => $status, 'id' => (int) $id, 'repo' => (int) $dbRepo['id']],
        )->rowCount();

        if ($updated === 0) {
            $this->notFound();
            return;
        }

        // Let the reporter know their bug report was processed.
        $issue = $this->app->db()->fetchOne(
            'SELECT `title`, `user_id` FROM `bug_reports` WHERE `id` = :id LIMIT 1',
            ['id' => (int) $id],
        );

        if ($issue !== false && $issue['user_id'] !== null) {
            $this->app->db()->execute(
                'INSERT INTO `notifications` (`user_id`, `repo_id`, `type`, `message`, `link`)
                 VALUES (:user, :repo, :type, :message, :link)',
                [
                    'user'    => (int) $issue['user_id'],
                    'repo'    => (int) $dbRepo['id'],
                    'type'    => 'issue',
                    'message' => "Your bug report \"" . $issue['title'] . "\" is now {$status}.",
                    'link'    => "/{$user}/{$dbRepo['slug']}/issues/{$id}",
                ],
            );
        }

        $_SESSION['flash_success'] = "Bug report marked as {$status}.";
        header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
        exit;
    }

    // ── Issue metadata management (writers): labels / milestone / assignee ──

    /** POST /{user}/{repo}/issues/labels — create a label (writers). */
    public function createLabel(string $user, string $repo): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo);
        }

        $name  = trim((string) ($_POST['name'] ?? ''));
        $color = strtolower(trim((string) ($_POST['color'] ?? '#8b949e')));

        if ($name === '' || mb_strlen($name) > 50) {
            $this->flashIssueError('Label name is required (max 50 characters).', $user, $dbRepo);
        }
        if (! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $color = '#8b949e';
        }

        $this->app->db()->execute(
            'INSERT INTO `issue_labels` (`repo_id`, `name`, `color`)
             VALUES (:repo, :name, :color)
             ON DUPLICATE KEY UPDATE `color` = :color2',
            ['repo' => (int) $dbRepo['id'], 'name' => $name, 'color' => $color, 'color2' => $color],
        );

        header("Location: /{$user}/{$dbRepo['slug']}/issues");
        exit;
    }

    /** POST /{user}/{repo}/issues/labels/{id}/delete — remove a label (writers). */
    public function deleteLabel(string $user, string $repo, int $id): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo);
        }

        $this->app->db()->execute(
            'DELETE FROM `issue_labels` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        header("Location: /{$user}/{$dbRepo['slug']}/issues");
        exit;
    }

    /** POST /{user}/{repo}/issues/{id}/metadata — set labels/milestone/assignee (writers). */
    public function updateMetadata(string $user, string $repo, int $id): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo, (string) $id);
        }

        $issue = $this->app->db()->fetchOne(
            'SELECT `id` FROM `bug_reports` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );
        if ($issue === false) {
            $this->notFound();
            return;
        }

        // Labels: replace-set semantics (absent = unchanged, "labels" = new set).
        if (isset($_POST['labels']) && is_array($_POST['labels'])) {
            $this->app->db()->execute('DELETE FROM `issue_label_map` WHERE `issue_id` = :iid', ['iid' => $id]);

            $wanted = [];
            foreach ($_POST['labels'] as $lid) {
                $lid = (int) $lid;
                if ($lid > 0) $wanted[$lid] = true;
            }
            if ($wanted !== []) {
                $valid = $this->app->db()->fetchAll(
                    'SELECT `id` FROM `issue_labels` WHERE `repo_id` = :repo AND `id` IN (' . implode(',', array_keys($wanted)) . ')',
                    ['repo' => (int) $dbRepo['id']],
                );
                foreach ($valid as $v) {
                    $this->app->db()->execute(
                        'INSERT IGNORE INTO `issue_label_map` (`issue_id`, `label_id`) VALUES (:iid, :lid)',
                        ['iid' => $id, 'lid' => (int) $v['id']],
                    );
                }
            }
        }

        if (array_key_exists('milestone_id', $_POST)) {
            $mid = (int) $_POST['milestone_id'];
            if ($mid > 0) {
                $ok = $this->app->db()->fetchOne(
                    'SELECT `id` FROM `issue_milestones` WHERE `id` = :mid AND `repo_id` = :repo LIMIT 1',
                    ['mid' => $mid, 'repo' => (int) $dbRepo['id']],
                );
                $mid = $ok !== false ? $mid : null;
            } else {
                $mid = null;
            }
            $this->app->db()->execute(
                'UPDATE `bug_reports` SET `milestone_id` = :mid WHERE `id` = :id',
                ['mid' => $mid, 'id' => $id],
            );
        }

        if (array_key_exists('assigned_to', $_POST)) {
            $uid = (int) $_POST['assigned_to'];
            if ($uid > 0) {
                $ok = $this->app->db()->fetchOne(
                    'SELECT `rc`.`id` FROM `repo_collaborators` rc WHERE `repo_id` = :repo AND `user_id` = :uid LIMIT 1',
                    ['repo' => (int) $dbRepo['id'], 'uid' => $uid],
                );
                $uid = $ok !== false ? $uid : null;
            } else {
                $uid = null;
            }
            $this->app->db()->execute(
                'UPDATE `bug_reports` SET `assigned_to` = :uid WHERE `id` = :id',
                ['uid' => $uid, 'id' => $id],
            );
        }

        header("Location: /{$user}/{$dbRepo['slug']}/issues/{$id}");
        exit;
    }

    /** POST /{user}/{repo}/issues/milestones — create a milestone (writers). */
    public function createMilestone(string $user, string $repo): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $desc  = trim((string) ($_POST['description'] ?? ''));
        $due   = trim((string) ($_POST['due_date'] ?? ''));

        if ($title === '' || mb_strlen($title) > 120) {
            $this->flashIssueError('Milestone title is required (max 120 characters).', $user, $dbRepo);
        }

        $dueDate = null;
        if ($due !== '' && ($ts = strtotime($due)) !== false) {
            $dueDate = date('Y-m-d', $ts);
        }

        $this->app->db()->execute(
            'INSERT INTO `issue_milestones` (`repo_id`, `title`, `description`, `due_date`)
             VALUES (:repo, :title, :desc, :due)',
            ['repo' => (int) $dbRepo['id'], 'title' => $title, 'desc' => $desc !== '' ? $desc : null, 'due' => $dueDate],
        );

        header("Location: /{$user}/{$dbRepo['slug']}/issues");
        exit;
    }

    /** POST /{user}/{repo}/issues/milestones/{id}/close — toggle milestone state (writers). */
    public function toggleMilestone(string $user, string $repo, int $id): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo);
        }

        $this->app->db()->execute(
            'UPDATE `issue_milestones` SET `is_closed` = 1 - `is_closed` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        header("Location: /{$user}/{$dbRepo['slug']}/issues");
        exit;
    }

    /** POST /{user}/{repo}/issues/milestones/{id}/delete — remove milestone (writers). */
    public function deleteMilestone(string $user, string $repo, int $id): void
    {
        $this->requireIssueWriter($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->flashIssueError('Invalid security token.', $user, $dbRepo);
        }

        // Issues referencing it fall back to NULL (ON DELETE SET NULL).
        $this->app->db()->execute(
            'DELETE FROM `issue_milestones` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        header("Location: /{$user}/{$dbRepo['slug']}/issues");
        exit;
    }

    /**
     * Shared writer gate for issue metadata endpoints. Sets $dbRepo to null
     * after emitting an error page when access is denied.
     */
    private function requireIssueWriter(string $repo, ?array &$dbRepo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 403,
                'message' => 'You do not have write permissions for this repository.',
            ]);
            $dbRepo = null;
        }
    }

    /** Admin nav counters cached for 60s to avoid 4 COUNT(*) queries per page load. */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    /** GET /admin/bug-reports — review queue across all repositories with caching. */
    public function adminIndex(): void
    {
        $this->auth->requireStaff();
        $cache = $this->app->cache();

        $status = (string) ($_GET['status'] ?? 'open');
        if (! in_array($status, ['open', 'resolved', 'closed'], true)) $status = 'open';

        // Cache bug reports list for 30 seconds
        $reports = $cache->remember("admin:bug_reports:list:{$status}", 30, function () use ($status): array {
            return $this->app->db()->fetchAll(
                'SELECT b.*, r.name AS repo_name, r.slug AS repo_slug, u.username AS reporter
                 FROM `bug_reports` b
                 LEFT JOIN `repositories` r ON r.id = b.repo_id
                 LEFT JOIN `users` u ON u.id = b.user_id
                 WHERE b.status = :status
                 ORDER BY b.created_at DESC',
                ['status' => $status],
            );
        });

        // Cache status counts for 30 seconds
        $counts = $cache->remember('admin:bug_reports:counts', 30, function (): array {
            $res = [];
            foreach (['open', 'resolved', 'closed'] as $key) {
                $res[$key] = (int) ($this->app->db()->fetchOne(
                    'SELECT COUNT(*) AS `c` FROM `bug_reports` WHERE `status` = :status',
                    ['status' => $key],
                )['c'] ?? 0);
            }
            return $res;
        });

        $this->app->view()->display('admin/bug-reports.twig', [
            'reports'      => $reports,
            'counts'       => $counts,
            'status'       => $status,
            'nav_counts'   => $this->getNavCounts(),
            'admin_prefix' => $this->auth->adminPrefix(),
            'owner'        => (string) $this->app->config('app.owner', 'admin'),
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /admin/bug-reports/{id}/status — status change from the admin panel. */
    public function adminStatus(string $id): void
    {
        $this->auth->requireStaff();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/bug-reports");
            exit;
        }

        $status = (string) ($_POST['status'] ?? '');
        if (! in_array($status, ['open', 'resolved', 'closed'], true)) {
            $_SESSION['flash_error'] = 'Invalid status.';
            header("Location: {$ap}/bug-reports");
            exit;
        }

        $this->app->db()->execute(
            'UPDATE `bug_reports` SET `status` = :status WHERE `id` = :id',
            ['status' => $status, 'id' => (int) $id],
        );

        // Clear bug reports cache & nav counters
        $cache = $this->app->cache();
        $cache->forget('admin:bug_reports:counts');
        $cache->forget('admin:nav_counts');
        $cache->forgetPrefix('admin:bug_reports:list');

        $_SESSION['flash_success'] = "Bug report #{$id} marked as {$status}.";
        header("Location: {$ap}/bug-reports");
        exit;
    }

    /**
     * Number of open bug reports for a repository (tab badge).
     */
    private function openCount(int $repoId): int
    {
        return (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `bug_reports` WHERE `repo_id` = :repo AND `status` = :status',
            ['repo' => $repoId, 'status' => 'open'],
        )['c'] ?? 0);
    }

    /** Current identity id: 0 for the owner, users row id otherwise. */
    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    /**
     * Look up a repo by slug and enforce visibility (public for everyone,
     * private only for the owner and collaborators).
     * @return array<string, mixed>|null
     */
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

    private function notFound(): void
    {
        http_response_code(404);
        $this->app->view()->display('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }

    /** Flash an error and redirect back to the issues list (or a single issue). */
    private function flashIssueError(string $message, string $user, array $dbRepo, ?string $issueId = null): never
    {
        $_SESSION['flash_error'] = $message;
        $slug = (string) ($dbRepo['slug'] ?? '');
        header("Location: /{$user}/{$slug}/issues" . ($issueId !== null ? '/' . $issueId : ''));
        exit;
    }
}
