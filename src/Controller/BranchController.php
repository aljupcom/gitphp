<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\Cache;
use App\Service\GitReader;
use App\Service\GitService;

final class BranchController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private GitReader $gitReader;
    private Cache $cache;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitService = new GitService();
        $this->gitReader  = new GitReader();
        $this->cache      = $app->cache();
    }

    /** GET /{user}/{repo}/pulls — list pull requests */
    public function pulls(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $owner = (string) $this->app->config('app.owner', 'admin');
        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        $this->app->view()->display('repo/pulls.twig', [
            'owner'        => $owner,
            'repo'         => $dbRepo,
            'pulls'        => [],
            'open_pulls'   => [],
            'closed_pulls' => [],
            'can_write'    => $canWrite,
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/branches — list all branches */
    public function index(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);

        // Enrich branches with commit details
        $enriched = [];
        foreach ($branches as $b) {
            $log = $this->gitReader->getLog($repoPath, $b, 1);
            $lastCommit = $log[0] ?? null;
            $enriched[] = [
                'name'        => $b,
                'is_default'  => $b === ($dbRepo['default_branch'] ?? 'main'),
                'last_commit' => $lastCommit,
            ];
        }

        $owner = (string) $this->app->config('app.owner', 'admin');
        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        $this->app->view()->display('repo/branches.twig', [
            'owner'          => $owner,
            'repo'           => $dbRepo,
            'branches'       => $enriched,
            'current_ref'    => $dbRepo['default_branch'] ?? 'main',
            'can_write'      => $canWrite,
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /{user}/{repo}/branches/new — new branch form */
    public function createForm(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);
        $owner    = (string) $this->app->config('app.owner', 'admin');

        $from = (string) ($_GET['from'] ?? $dbRepo['default_branch'] ?? 'main');

        $this->app->view()->display('repo/branch-new.twig', [
            'owner'       => $owner,
            'repo'        => $dbRepo,
            'branches'    => $branches,
            'from_ref'    => $from,
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/branches/new — store new branch */
    public function store(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches/new");
            exit;
        }

        $branchName = trim((string) ($_POST['branch_name'] ?? ''));
        $fromRef    = trim((string) ($_POST['from_ref'] ?? $dbRepo['default_branch'] ?? 'main'));

        if ($branchName === '') {
            $_SESSION['flash_error'] = 'Branch name cannot be empty.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches/new");
            exit;
        }

        try {
            $this->gitService->createBranch($dbRepo['slug'], $branchName, $fromRef);
            $this->cache->forget("repo:{$dbRepo['slug']}:branches");

            $_SESSION['flash_success'] = "Branch '{$branchName}' created successfully.";
            header("Location: /{$user}/{$dbRepo['slug']}/tree/{$branchName}");
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to create branch: ' . $e->getMessage();
            header("Location: /{$user}/{$dbRepo['slug']}/branches/new");
            exit;
        }
    }

    /** GET /{user}/{repo}/compare/{spec?} — branch compare & diff view */
    public function compare(string $user, string $repo, string $spec = ''): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);
        $defaultBranch = $dbRepo['default_branch'] ?? 'main';

        $base = $defaultBranch;
        $head = '';

        if ($spec !== '') {
            if (str_contains($spec, '...')) {
                $parts = explode('...', $spec, 2);
                $base = trim($parts[0]);
                $head = trim($parts[1]);
            } elseif (str_contains($spec, '..')) {
                $parts = explode('..', $spec, 2);
                $base = trim($parts[0]);
                $head = trim($parts[1]);
            } else {
                $head = trim($spec);
            }
        } else {
            $base = (string) ($_GET['base'] ?? $defaultBranch);
            $head = (string) ($_GET['head'] ?? '');
            if ($head === '' && count($branches) > 1) {
                foreach ($branches as $b) {
                    if ($b !== $base) {
                        $head = $b;
                        break;
                    }
                }
            }
        }

        $comparison = null;
        $error = null;
        $diffFiles = [];
        $diffStats = ['files' => 0, 'additions' => 0, 'deletions' => 0];

        if ($base !== '' && $head !== '' && $base !== $head) {
            try {
                $comparison = $this->gitService->compare($dbRepo['slug'], $base, $head);
                if (!empty($comparison['diff'])) {
                    $diffFiles = $this->parseDiff($comparison['diff']);
                    $additions = 0;
                    $deletions = 0;
                    foreach ($diffFiles as $file) {
                        $additions += $file['additions'];
                        $deletions += $file['deletions'];
                    }
                    $diffStats = [
                        'files'     => count($diffFiles),
                        'additions' => $additions,
                        'deletions' => $deletions,
                    ];
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $owner = (string) $this->app->config('app.owner', 'admin');
        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        $this->app->view()->display('repo/compare.twig', [
            'owner'       => $owner,
            'repo'        => $dbRepo,
            'branches'    => $branches,
            'base'        => $base,
            'head'        => $head,
            'comparison'  => $comparison,
            'diff_files'  => $diffFiles,
            'diff_stats'  => $diffStats,
            'error'       => $error,
            'can_write'   => $canWrite,
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/merge — merge branches */
    public function merge(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches");
            exit;
        }

        $base = trim((string) ($_POST['base'] ?? ''));
        $head = trim((string) ($_POST['head'] ?? ''));
        $msg  = trim((string) ($_POST['message'] ?? ''));

        if ($base === '' || $head === '' || $base === $head) {
            $_SESSION['flash_error'] = 'Invalid base or head branch selected for merge.';
            header("Location: /{$user}/{$dbRepo['slug']}/compare");
            exit;
        }

        $authorName  = $this->auth->displayName();
        $authorEmail = $this->auth->isOwner() ? 'owner@localhost' : ($this->auth->user()['email'] ?? 'user@localhost');

        $result = $this->gitService->merge($dbRepo['slug'], $base, $head, $authorName, $authorEmail, $msg);

        if (! $result['ok']) {
            $_SESSION['flash_error'] = $result['error'] ?? 'Merge failed.';
            header("Location: /{$user}/{$dbRepo['slug']}/compare/{$base}...{$head}");
            exit;
        }

        // Invalidate caches
        $this->cache->forget("repo:{$dbRepo['slug']}:tree:{$base}:ROOT");
        $this->cache->forget("repo:{$dbRepo['slug']}:commits:{$base}:1");

        $_SESSION['flash_success'] = "Successfully merged '{$head}' into '{$base}'.";
        header("Location: /{$user}/{$dbRepo['slug']}/tree/{$base}");
        exit;
    }

    /** POST /{user}/{repo}/branches/delete — delete a branch */
    public function delete(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches");
            exit;
        }

        $branchName = trim((string) ($_POST['branch_name'] ?? ''));

        // Check Branch Protection rules
        $db = $this->app->db()->connection();
        $protStmt = $db->prepare('SELECT prevent_delete FROM branch_protections WHERE repo_id = ? AND branch_name = ? LIMIT 1');
        $protStmt->execute([$dbRepo['id'], $branchName]);
        $prot = $protStmt->fetch();

        if ($prot && !empty($prot['prevent_delete'])) {
            $_SESSION['flash_error'] = "Branch '{$branchName}' is protected and cannot be deleted.";
            header("Location: /{$user}/{$dbRepo['slug']}/branches");
            exit;
        }

        try {
            $this->gitService->deleteBranch($dbRepo['slug'], $branchName, $dbRepo['default_branch'] ?? 'main');
            $this->cache->forget("repo:{$dbRepo['slug']}:branches");

            $audit = new \App\Service\AuditLogger($this->app);
            $currUser = $this->auth->user();
            $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
            $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');
            $audit->log('branch.delete', (int) $dbRepo['id'], "Deleted branch {$branchName}", $userId, $userName);

            $_SESSION['flash_success'] = "Branch '{$branchName}' deleted successfully.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to delete branch: ' . $e->getMessage();
        }

        header("Location: /{$user}/{$dbRepo['slug']}/branches");
        exit;
    }

    /** POST /{user}/{repo}/branches/default — set default branch */
    public function setDefault(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches");
            exit;
        }

        $branchName = trim((string) ($_POST['default_branch'] ?? ''));
        if ($branchName === '') {
            $_SESSION['flash_error'] = 'Invalid default branch name.';
            header("Location: /{$user}/{$dbRepo['slug']}/branches");
            exit;
        }

        try {
            $this->gitService->setDefaultBranch($dbRepo['slug'], $branchName);
            $this->app->db()->execute(
                'UPDATE `repositories` SET `default_branch` = :branch WHERE `id` = :id',
                ['branch' => $branchName, 'id' => (int) $dbRepo['id']],
            );
            $this->cache->forget("repo:{$dbRepo['slug']}:branches");

            $_SESSION['flash_success'] = "Default branch changed to '{$branchName}'.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to set default branch: ' . $e->getMessage();
        }

        header("Location: /{$user}/{$dbRepo['slug']}/branches");
        exit;
    }

    private function requireWriteAccess(string $slug, ?array &$dbRepo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($slug);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        if (! $this->auth->isOwner() && ! $this->auth->canWriteRepo((int) $dbRepo['id'])) {
            $_SESSION['flash_error'] = 'You do not have write permission for this repository.';
            $owner = (string) $this->app->config('app.owner', 'admin');
            header("Location: /{$owner}/{$dbRepo['slug']}");
            exit;
        }
    }

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
        echo $this->app->view()->render('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }

    /**
     * Parse unified diff output into structured, GitHub-style file entries.
     */
    private function parseDiff(string $diff): array
    {
        if (trim($diff) === '') return [];

        $files    = [];
        $current  = null;
        $oldNo    = 0;
        $newNo    = 0;
        $lineCap  = 2000;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                if ($current !== null) $files[] = $current;

                $path = '';
                if (preg_match('#^diff --git a/(.+) b/(.+)$#', $line, $m)) {
                    $path = $m[2];
                }

                $current = [
                    'path'      => $path,
                    'old_path'  => null,
                    'status'    => 'modified',
                    'binary'    => false,
                    'additions' => 0,
                    'deletions' => 0,
                    'truncated' => false,
                    'lines'     => [],
                ];
                continue;
            }

            if ($current === null) continue;

            if (str_starts_with($line, 'new file mode'))   { $current['status'] = 'added';   continue; }
            if (str_starts_with($line, 'deleted file mode')) { $current['status'] = 'deleted'; continue; }
            if (str_starts_with($line, 'rename from '))    { $current['old_path'] = substr($line, 12); $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'rename to '))      { $current['path'] = substr($line, 10);     $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'Binary files') || str_starts_with($line, 'GIT binary patch')) {
                $current['binary'] = true;
                continue;
            }
            if (
                str_starts_with($line, 'index ') ||
                str_starts_with($line, 'old mode') ||
                str_starts_with($line, 'new mode') ||
                str_starts_with($line, 'similarity index') ||
                str_starts_with($line, 'dissimilarity index') ||
                str_starts_with($line, 'copy from') ||
                str_starts_with($line, 'copy to') ||
                str_starts_with($line, '--- ') ||
                str_starts_with($line, '+++ ')
            ) {
                continue;
            }

            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m)) {
                $oldNo = (int) $m[1];
                $newNo = (int) $m[2];

                if (count($current['lines']) < $lineCap) {
                    $current['lines'][] = ['type' => 'hunk', 'content' => $line];
                }
                continue;
            }

            if (count($current['lines']) >= $lineCap) {
                $current['truncated'] = true;
            }

            if (str_starts_with($line, '+')) {
                $current['additions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'add', 'old' => null, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $newNo++;
                }
            } elseif (str_starts_with($line, '-')) {
                $current['deletions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'del', 'old' => $oldNo++, 'new' => null, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                }
            } elseif (str_starts_with($line, '\\')) {
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'meta', 'content' => substr($line, 2)];
                }
            } else {
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'ctx', 'old' => $oldNo++, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                    $newNo++;
                }
            }
        }

        if ($current !== null) $files[] = $current;

        return $files;
    }
}
