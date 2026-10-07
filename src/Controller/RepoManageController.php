<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\GitService;

final class RepoManageController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitService = new GitService();
    }

    /** Admin nav counters cached for 60s to avoid repeated COUNT(*) queries. */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }


    /** GET /admin — admin dashboard with repo list, stats, activity. */
        /** GET /admin — Intelligent stealth gateway for the owner's session hash */
    public function gateway(): void
    {
        if (! $this->auth->isOwner()) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Page Not Found',
            ]);
            return;
        }

        // /admin is the owner's canonical profile path (the catch-all
        // /{username} profile route registers after this one). Because the
        // admin panel lives on a dynamic per-session prefix, this exact route
        // must render the owner profile instead of redirecting — otherwise
        // account-menu links like /admin?tab=repositories or /admin?tab=stars
        // land on the admin panel gateway.
        (new \App\Controller\AccountController($this->app))->profile((string) $this->app->config('app.owner', 'admin'));
    }


    public function index(): void
    {
        $this->auth->requireOwner();

        $repos = $this->app->db()->fetchAll(
            'SELECT r.*, u.username AS owner_username
             FROM `repositories` r
             LEFT JOIN `users` u ON u.id = r.owner_user_id
             ORDER BY r.updated_at DESC',
        );

        $stats = [
            'total'   => count($repos),
            'public'  => count(array_filter($repos, fn($r) => $r['visibility'] === 'public')),
            'private' => count(array_filter($repos, fn($r) => $r['visibility'] === 'private')),
            'users'   => (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS `c` FROM `users`',
            )['c'] ?? 0),
            'bug_reports' => (int) ($this->app->db()->fetchOne(
                "SELECT COUNT(*) AS `c` FROM `bug_reports` WHERE `status` = 'open'",
            )['c'] ?? 0),
            'stars'    => (int) ($this->app->db()->fetchOne(
                'SELECT COALESCE(SUM(`stars_count`), 0) AS `c` FROM `repositories`',
            )['c'] ?? 0),
            'open_prs' => (int) ($this->app->db()->fetchOne(
                "SELECT COUNT(*) AS `c` FROM `pull_requests` WHERE `status` = 'open'",
            )['c'] ?? 0),
            'forks'    => (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS `c` FROM `repositories` WHERE `forked_from_id` IS NOT NULL',
            )['c'] ?? 0),
        ];

        // Git storage aggregate usage (cached 120s)
        $stats['storage_size'] = $this->app->cache()->remember('admin:dashboard:storage_size', 120, function (): string {
            $optimizer = new \App\Service\GitStorageOptimizer($this->app);
            return $optimizer->formatBytes($optimizer->getAllReposStats()['total_bytes']);
        });

        // Reuse the shared admin nav_counts cache (60s TTL) — avoids duplicate DB queries
        $cache = $this->app->cache();
        $navCounts = $cache->remember('admin:nav_counts', 60, function () use ($stats): array {
            return [
                'repos'     => $stats['total'],
                'issues'    => $stats['bug_reports'],
                'downloads' => (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM `file_downloads`')['c'] ?? 0),
                'ssh_keys'  => (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM `ssh_keys`')['c'] ?? 0),
            ];
        });

        $activity = $this->app->db()->fetchAll(
            'SELECT a.*, r.name AS repo_name, r.slug AS repo_slug
             FROM `activity_log` a
             LEFT JOIN `repositories` r ON r.id = a.repo_id
             ORDER BY a.created_at DESC
             LIMIT 10',
        );

        // Chart: repository events per day over the last 14 days.
        $chartRows = $this->app->db()->fetchAll(
            'SELECT DATE(`created_at`) AS `day`, COUNT(*) AS `count`
             FROM `activity_log`
             WHERE `created_at` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
             GROUP BY DATE(`created_at`)',
        );

        $countsByDay = [];
        foreach ($chartRows as $row) $countsByDay[(string) $row['day']] = (int) $row['count'];

        $chartDays = [];
        for ($i = 13; $i >= 0; $i--) {
            $day        = date('Y-m-d', strtotime("-{$i} days"));
            $chartDays[] = [
                'label' => date('M j', strtotime($day)),
                'count' => $countsByDay[$day] ?? 0,
            ];
        }

        $chartMax = 0;
        foreach ($chartDays as $d) $chartMax = max($chartMax, $d['count']);

        // Bug report totals per status (for the status bars).
        $bugStatuses = [];
        foreach (['open', 'resolved', 'closed'] as $statusKey) {
            $bugStatuses[$statusKey] = (int) ($this->app->db()->fetchOne(
                'SELECT COUNT(*) AS `c` FROM `bug_reports` WHERE `status` = :status',
                ['status' => $statusKey],
            )['c'] ?? 0);
        }

        // Latest reported issues for the dashboard review card.
        $recentBugs = $this->app->db()->fetchAll(
            'SELECT b.id, b.title, b.status, b.created_at,
                    r.name AS repo_name, r.slug AS repo_slug, u.username AS reporter
             FROM `bug_reports` b
             LEFT JOIN `repositories` r ON r.id = b.repo_id
             LEFT JOIN `users` u ON u.id = b.user_id
             ORDER BY b.created_at DESC
             LIMIT 5',
        );

        $csrf = $this->auth->generateCsrf();

        $this->app->view()->display('admin/dashboard.twig', [
            'repos'        => $repos,
            'stats'        => $stats,
            'nav_counts'   => $navCounts,
            'activity'     => $activity,
            'chart_days'   => $chartDays,
            'chart_max'    => $chartMax,
            'bug_statuses' => $bugStatuses,
            'recent_bugs'  => $recentBugs,
            'owner'        => (string) $this->app->config('app.owner', 'admin'),
            'csrf_token'   => $csrf,
            // success/error flashes are injected globally during App::boot()
        ]);
    }

    /** GET /admin/repos — repository management list. */
    public function repos(): void
    {
        $this->auth->requireOwner();

        $repos = $this->app->db()->fetchAll(
            'SELECT r.*, u.username AS owner_username
             FROM `repositories` r
             LEFT JOIN `users` u ON u.id = r.owner_user_id
             ORDER BY r.updated_at DESC',
        );

        $stats = [
            'total'   => count($repos),
            'public'  => count(array_filter($repos, fn($r) => $r['visibility'] === 'public')),
            'private' => count(array_filter($repos, fn($r) => $r['visibility'] === 'private')),
        ];

        $this->app->view()->display('admin/repos/index.twig', [
            'repos'        => $repos,
            'stats'        => $stats,
            'nav_counts'   => $this->getNavCounts(),
            'admin_prefix' => $this->auth->adminPrefix(),
            'owner'        => (string) $this->app->config('app.owner', 'admin'),
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /admin/repos/create — show create form. */
    public function create(): void
    {
        $this->auth->requireOwner();

        $csrf = $this->auth->generateCsrf();

        $this->app->view()->display('admin/repos/form.twig', [
            'csrf_token'   => $csrf,
            'repo'         => null,
            'admin_prefix' => $this->auth->adminPrefix(),
            'nav_counts'   => $this->getNavCounts(),
        ]);
    }

    /** POST /admin/repos — validate and create a new repository. */
    public function store(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        // CSRF
        $csrf = $_POST['csrf_token'] ?? '';
        if (!$this->auth->validateCsrf($csrf)) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $ap . '/repos/create');
            exit;
        }

        $name           = trim((string) ($_POST['name'] ?? ''));
        $slug           = trim((string) ($_POST['slug'] ?? ''));
        $description    = trim((string) ($_POST['description'] ?? ''));
        $visibility     = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $defaultBranch  = trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main';

        // Validate name
        if ($name === '') {
            $_SESSION['flash_error'] = 'Repository name is required.';
            header('Location: ' . $ap . '/repos/create');
            exit;
        }

        // Auto-generate slug from name if empty
        if ($slug === '') $slug = strtolower($name);

        // Slug validation: lowercase, alphanumeric + hyphens only
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $slug) && !preg_match('/^[a-z0-9]$/', $slug)) {
            $_SESSION['flash_error'] = 'Slug must be lowercase alphanumeric with hyphens only.';
            header('Location: ' . $ap . '/repos/create');
            exit;
        }

        // Check uniqueness
        $existing = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($existing !== false) {
            $_SESSION['flash_error'] = 'A repository with that slug already exists.';
            header('Location: ' . $ap . '/repos/create');
            exit;
        }

        // Create DB record
        $this->app->db()->execute(
            'INSERT INTO `repositories` (`slug`, `name`, `description`, `visibility`, `default_branch`)
             VALUES (:slug, :name, :desc, :vis, :branch)',
            [
                'slug'   => $slug,
                'name'   => $name,
                'desc'   => $description !== '' ? $description : null,
                'vis'    => $visibility,
                'branch' => $defaultBranch,
            ],
        );

        $repoId = $this->app->db()->lastInsertId();

        // Init bare repo on disk
        try {
            $this->gitService->initRepo($slug, $defaultBranch);
        } catch (\RuntimeException $e) {
            // Roll back DB record if git init fails
            $this->app->db()->execute('DELETE FROM `repositories` WHERE `id` = :id', ['id' => $repoId]);
            $_SESSION['flash_error'] = 'Failed to create repository: ' . $e->getMessage();
            header('Location: ' . $ap . '/repos/create');
            exit;
        }

        // Invalidate cache
        $this->app->cache()->forget('admin:nav_counts');
        $this->app->cache()->forget('dashboard:repos');
        $this->app->cache()->forgetPrefix('repos:');

        // Log activity
        $this->app->db()->execute(
            'INSERT INTO `activity_log` (`repo_id`, `action`, `details`) VALUES (?, ?, ?)',
            [$repoId, 'created', "Repository '{$name}' created"],
        );

        $_SESSION['flash_success'] = "Repository '{$name}' created successfully.";
        header('Location: ' . $ap . '/repos');
        exit;
    }

    /** GET /admin/repos/{slug}/edit — show edit form. */
    public function edit(string $slug): void
    {
        $this->auth->requireOwner();

        $repo = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            http_response_code(404);
            $this->app->view()->display('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Repository not found.',
            ]);
            return;
        }

        $collaborators = $this->app->db()->fetchAll(
            'SELECT c.*, u.username
             FROM `repo_collaborators` c
             JOIN `users` u ON u.id = c.user_id
             WHERE c.repo_id = :repo
             ORDER BY u.username',
            ['repo' => (int) $repo['id']],
        );

        $csrf = $this->auth->generateCsrf();

        $this->app->view()->display('admin/repos/form.twig', [
            'csrf_token'    => $csrf,
            'repo'          => $repo,
            'collaborators' => $collaborators,
            'admin_prefix'  => $this->auth->adminPrefix(),
            'nav_counts'    => $this->getNavCounts(),
        ]);
    }

    /** POST /admin/repos/{slug} — validate and update a repository. */
    public function update(string $slug): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        // CSRF
        $csrf = $_POST['csrf_token'] ?? '';
        if (!$this->auth->validateCsrf($csrf)) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/repos/{$slug}/edit");
            exit;
        }

        $repo = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            http_response_code(404);
            $this->app->view()->display('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Repository not found.',
            ]);
            return;
        }

        $name           = trim((string) ($_POST['name'] ?? ''));
        $description    = trim((string) ($_POST['description'] ?? ''));
        $visibility     = ($_POST['visibility'] ?? 'public') === 'private' ? 'private' : 'public';
        $defaultBranch  = trim((string) ($_POST['default_branch'] ?? 'main')) ?: 'main';

        if ($name === '') {
            $_SESSION['flash_error'] = 'Repository name is required.';
            header("Location: {$ap}/repos/{$slug}/edit");
            exit;
        }

        $this->app->db()->execute(
            'UPDATE `repositories`
             SET `name` = :name, `description` = :desc, `visibility` = :vis, `default_branch` = :branch
             WHERE `slug` = :slug',
            [
                'name'   => $name,
                'desc'   => $description !== '' ? $description : null,
                'vis'    => $visibility,
                'branch' => $defaultBranch,
                'slug'   => $slug,
            ],
        );

        // Keep the bare repo's HEAD in sync with the configured default branch
        if ($this->gitService->repoExists($slug)) {
            try {
                $this->gitService->setHead($slug, $defaultBranch);
            } catch (\RuntimeException) {
                // Non-fatal: the branch may not exist yet (empty repo)
            }
        }

        // Invalidate cache
        $this->app->cache()->forgetPrefix("repo:{$slug}:");

        // Log activity
        $this->app->db()->execute(
            'INSERT INTO `activity_log` (`repo_id`, `action`, `details`) VALUES (?, ?, ?)',
            [(int) $repo['id'], 'updated', "Repository '{$name}' updated"],
        );

        $_SESSION['flash_success'] = "Repository '{$name}' updated successfully.";
        header('Location: ' . $ap . '/repos');
        exit;
    }

    /** POST /admin/repos/{slug}/delete — delete a repository. */
    public function delete(string $slug): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        // CSRF
        $csrf = $_POST['csrf_token'] ?? '';
        if (!$this->auth->validateCsrf($csrf)) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $ap . '/repos');
            exit;
        }

        $repo = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            $_SESSION['flash_error'] = 'Repository not found.';
            header('Location: ' . $ap . '/repos');
            exit;
        }

        $repoName = $repo['name'];
        $repoId   = (int) $repo['id'];

        // Log before deleting (repo_id FK will SET NULL)
        $this->app->db()->execute(
            'INSERT INTO `activity_log` (`repo_id`, `action`, `details`) VALUES (?, ?, ?)',
            [$repoId, 'deleted', "Repository '{$repoName}' deleted"],
        );

        // Delete bare repo from disk
        try {
            $this->gitService->deleteRepo($slug);
        } catch (\RuntimeException $e) {
            // Log but continue
        }

        // Delete DB record
        $this->app->db()->execute(
            'DELETE FROM `repositories` WHERE `id` = :id',
            ['id' => $repoId],
        );

        // Invalidate cache
        $this->app->cache()->forget('admin:nav_counts');
        $this->app->cache()->forget('dashboard:repos');
        $this->app->cache()->forgetPrefix('repos:');
        $this->app->cache()->forgetPrefix("repo:{$slug}:");

        $_SESSION['flash_success'] = "Repository '{$repoName}' deleted.";
        header('Location: ' . $ap . '/repos');
        exit;
    }

    /** POST /admin/repos/{slug}/sync — fetch upstream updates into the mirror. */
    public function sync(string $slug): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $ap . '/repos');
            exit;
        }

        $repo = $this->app->db()->fetchOne(
            'SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            $_SESSION['flash_error'] = 'Repository not found.';
            header('Location: ' . $ap . '/repos');
            exit;
        }

        // Only repos with a recorded source can be synced (the token for
        // private sources is never persisted, so public syncs work fully).
        if (empty($repo['source_url'])) {
            $_SESSION['flash_error'] = 'This repository has no recorded source to sync from.';
            header('Location: ' . $ap . '/repos');
            exit;
        }

        @set_time_limit(1200);

        try {
            $this->gitService->syncRemote($slug);
        } catch (\RuntimeException $e) {
            $_SESSION['flash_error'] = 'Sync failed: ' . $e->getMessage();
            header('Location: ' . $ap . '/repos');
            exit;
        }

        // Invalidate cache
        $this->app->cache()->forgetPrefix("repo:{$slug}:");

        $this->app->db()->execute(
            'INSERT INTO `activity_log` (`repo_id`, `action`, `details`) VALUES (?, ?, ?)',
            [(int) $repo['id'], 'synced', "Repository '{$repo['name']}' synced with {$repo['source_url']}"],
        );

        $_SESSION['flash_success'] = "Repository '{$repo['name']}' synced with its source.";
        header('Location: ' . $ap . '/repos');
        exit;
    }

    /** POST /admin/repos/{slug}/collaborators — share the repo with a user. */
    public function collaboratorsAdd(string $slug): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/repos/{$slug}/edit");
            exit;
        }

        $repo = $this->app->db()->fetchOne(
            'SELECT `id`, `name` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            $_SESSION['flash_error'] = 'Repository not found.';
            header('Location: /admin');
            exit;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $role     = ($_POST['role'] ?? 'read') === 'write' ? 'write' : 'read';

        $user = $this->app->db()->fetchOne(
            'SELECT `id`, `username` FROM `users`
             WHERE `username` = :name OR `email` = :email LIMIT 1',
            ['name' => $username, 'email' => strtolower($username)],
        );

        if ($user === false) {
            $_SESSION['flash_error'] = 'No registered user matches that username or email.';
            header("Location: {$ap}/repos/{$slug}/edit");
            exit;
        }

        $this->app->db()->execute(
            'INSERT INTO `repo_collaborators` (`repo_id`, `user_id`, `role`)
             VALUES (:repo, :user, :role)
             ON DUPLICATE KEY UPDATE `role` = VALUES(`role`)',
            ['repo' => (int) $repo['id'], 'user' => (int) $user['id'], 'role' => $role],
        );

        $this->app->db()->execute(
            'INSERT INTO `notifications` (`user_id`, `repo_id`, `type`, `message`, `link`)
             VALUES (:user, :repo, :type, :message, :link)',
            [
                'user'    => (int) $user['id'],
                'repo'    => (int) $repo['id'],
                'type'    => 'collaborator',
                'message' => "You are now a collaborator on \"{$repo['name']}\".",
                'link'    => '/' . $this->auth->getOwnerUsername() . "/{$slug}",
            ],
        );

        $_SESSION['flash_success'] = "User '{$user['username']}' can now access this repository.";
        header("Location: {$ap}/repos/{$slug}/edit");
        exit;
    }

    /** POST /admin/repos/{slug}/collaborators/{id}/delete — revoke access. */
    public function collaboratorsRemove(string $slug, string $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/repos/{$slug}/edit");
            exit;
        }

        $repo = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($repo === false) {
            $_SESSION['flash_error'] = 'Repository not found.';
            header('Location: /admin');
            exit;
        }

        $this->app->db()->execute(
            'DELETE FROM `repo_collaborators` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => (int) $id, 'repo' => (int) $repo['id']],
        );

        $_SESSION['flash_success'] = 'Collaborator access revoked.';
        header("Location: {$ap}/repos/{$slug}/edit");
        exit;
    }
}
