<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\GitService;

/**
 * Repository settings for the owner OR the registered user who created
 * the repository (repositories.owner_user_id).
 */
final class RepoSettingsController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private AuditLogger $auditLogger;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitService = new GitService();
        $this->auditLogger = new AuditLogger($app);
    }

    /** GET /{user}/{repo}/settings — overview */
    public function index(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        $this->render($dbRepo, 'overview');
    }

    /** POST /{user}/{repo}/settings — save overview fields */
    public function update(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->isOwner() && ! $this->auth->isAdmin()) {
            $allowDel = (string) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'allow_repo_deletion'")['value'] ?? '1');
            if ($allowDel === '0') {
                flashExit('flash_error', 'Repository deletion is disabled by administrator policy.', "/{$user}/{$dbRepo['slug']}/settings");
            }
        }

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings");
        }

        $oldSlug     = (string) $dbRepo['slug'];
        $name        = trim((string) ($_POST['name'] ?? $dbRepo['name']));
        $newSlug     = strtolower(trim((string) ($_POST['slug'] ?? $oldSlug)));
        $description = trim((string) ($_POST['description'] ?? ''));
        $visibility  = ($_POST['visibility'] ?? $dbRepo['visibility']) === 'private' ? 'private' : 'public';
        $homepage    = trim((string) ($_POST['homepage'] ?? ''));
        $sourceUrl   = isset($_POST['source_url'])
            ? trim((string) $_POST['source_url'])
            : trim((string) ($dbRepo['source_url'] ?? ''));

        if ($name === '') {
            flashExit('flash_error', 'Repository name is required.', "/{$user}/{$oldSlug}/settings");
        }
        if (mb_strlen($name) > 255) {
            flashExit('flash_error', 'Repository name must be 255 characters or fewer.', "/{$user}/{$oldSlug}/settings");
        }

        if ($newSlug === '') {
            flashExit('flash_error', 'Repository slug is required.', "/{$user}/{$oldSlug}/settings");
        }
        if (str_ends_with($newSlug, '.git')) {
            $newSlug = substr($newSlug, 0, -4);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,98}[a-z0-9]?$/', $newSlug) || $newSlug === '.' || $newSlug === '..') {
            flashExit('flash_error', 'Repository slug must be 1–100 lowercase alphanumeric characters with dots, hyphens or underscores.', "/{$user}/{$oldSlug}/settings");
        }

        $slugChanged = ($newSlug !== $oldSlug);

        if ($slugChanged) {
            $existing = $this->app->db()->fetchOne(
                'SELECT `id` FROM `repositories` WHERE `slug` = :slug AND `id` != :id',
                ['slug' => $newSlug, 'id' => (int) $dbRepo['id']],
            );
            if ($existing !== null && $existing !== false) {
                flashExit('flash_error', "A repository with the slug '{$newSlug}' already exists.", "/{$user}/{$oldSlug}/settings");
            }

            try {
                $this->gitService->renameRepo($oldSlug, $newSlug);
            } catch (\Throwable $e) {
                flashExit('flash_error', 'Failed to rename repository on disk: ' . $e->getMessage(), "/{$user}/{$oldSlug}/settings");
            }

            // Invalidate caches for old slug
            $this->app->cache()->delete("home:{$oldSlug}:lastcommit");
            $this->app->cache()->delete("home:{$oldSlug}:commitcount");
            $this->app->cache()->delete("home:{$oldSlug}:filecount");
            $this->app->cache()->delete("repo:{$oldSlug}:lang_info_v5");
        }

        // Only https(s) upstreams from supported platforms are accepted.
        if ($sourceUrl !== '') {
            $parsed = (new \App\Service\RepoImporter())->parseRemoteUrl($sourceUrl);
            $sourceUrl = $parsed['clone_url'] ?? '';
            if ($sourceUrl === '') {
                flashExit('flash_error', 'Upstream URL must point to github.com, gitlab.com or codeberg.org over HTTPS.', "/{$user}/" . ($slugChanged ? $newSlug : $oldSlug) . "/settings");
            }
        }

        $this->app->db()->execute(
            'UPDATE `repositories`
             SET `name` = :name, `slug` = :slug, `description` = :desc, `visibility` = :vis, `homepage` = :home, `source_url` = :src, `updated_at` = NOW()
             WHERE `id` = :id',
            [
                'name' => $name,
                'slug' => $newSlug,
                'desc' => $description !== '' ? $description : null,
                'vis'  => $visibility,
                'home' => $homepage !== '' ? mb_substr($homepage, 0, 500) : null,
                'src'  => $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : null,
                'id'   => (int) $dbRepo['id'],
            ],
        );

        // Invalidate caches for new slug
        $this->app->cache()->delete("home:{$newSlug}:lastcommit");
        $this->app->cache()->delete("home:{$newSlug}:commitcount");
        $this->app->cache()->delete("home:{$newSlug}:filecount");
        $this->app->cache()->delete("repo:{$newSlug}:lang_info_v5");

        $this->auditLogger->log(
            'repo.settings',
            (int) $dbRepo['id'],
            "Updated settings (name='{$name}', slug='{$newSlug}', visibility={$visibility})",
            $this->identityId(),
            $this->displayName(),
        );

        flashExit('flash_success', 'Repository settings saved.', "/{$user}/{$newSlug}/settings");
    }

    /**
     * POST /{user}/{repo}/settings/sync — mirror-sync from the configured
     * upstream remote. Available to the repo creator or platform owner;
     * requires an upstream URL set on the settings page.
     */
    public function sync(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings");
        }

        $sourceUrl = trim((string) ($dbRepo['source_url'] ?? ''));

        if ($sourceUrl === '') {
            flashExit('flash_error', 'Set an upstream URL in settings before syncing.', "/{$user}/{$dbRepo['slug']}/settings");
        }

        $repoId = (int) $dbRepo['id'];

        $this->app->db()->execute(
            'INSERT INTO `sync_log` (`repo_id`, `status`, `message`, `created_at`) VALUES (:repo, \'started\', NULL, NOW())',
            ['repo' => $repoId],
        );
        $syncId = (int) $this->app->db()->lastInsertId();

        // Optional access token for a private upstream (used once, never stored).
        $authHeader = '';
        $token      = trim((string) ($_POST['access_token'] ?? ''));
        if ($token !== '') {
            try {
                $authHeader = (new \App\Service\RepoImporter())->authHeader($sourceUrl, $token);
            } catch (\Throwable) {
                $authHeader = '';
            }
        }

        try {
            (new \App\Service\GitService())->syncRemote($dbRepo['slug'], $authHeader);

            $this->app->db()->execute(
                'UPDATE `repositories` SET `last_synced_at` = NOW() WHERE `id` = :id',
                ['id' => $repoId],
            );
            $this->app->db()->execute(
                'UPDATE `sync_log` SET `status` = \'success\', `message` = :msg WHERE `id` = :id',
                ['msg' => 'Synced from ' . $sourceUrl, 'id' => $syncId],
            );

            $this->auditLogger->log('repo.sync', $repoId, "Synced from {$sourceUrl}", $this->identityId(), $this->displayName());

            // Notify hooked systems that upstream changes arrived.
            (new \App\Service\WebhookService($this->app))->dispatch($dbRepo['slug'], 'push', [
                'source' => 'mirror-sync',
                'remote' => $sourceUrl,
            ]);

            flashExit('flash_success', "Repository synced with {$sourceUrl}.", "/{$user}/{$dbRepo['slug']}/settings");
        } catch (\Throwable $e) {
            $this->app->db()->execute(
                'UPDATE `sync_log` SET `status` = \'failed\', `message` = :msg WHERE `id` = :id',
                ['msg' => mb_substr($e->getMessage(), 0, 500), 'id' => $syncId],
            );
            flashExit('flash_error', 'Sync failed: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/settings");
        }
    }

    /** GET /{user}/{repo}/settings/collaborators */
    public function collaborators(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        $rows = $this->app->db()->fetchAll(
            'SELECT rc.`id`, rc.`role`, rc.`created_at`, u.`username`, u.`email`
             FROM `repo_collaborators` rc
             JOIN `users` u ON u.`id` = rc.`user_id`
             WHERE rc.`repo_id` = :repo
             ORDER BY rc.`created_at` ASC',
            ['repo' => (int) $dbRepo['id']],
        );

        $this->render($dbRepo, 'collaborators', [
            'collaborators' => $rows,
        ]);
    }

    /** POST /{user}/{repo}/settings/collaborators — add collaborator */
    public function collaboratorsAdd(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/collaborators");
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $role     = ($_POST['role'] ?? 'read') === 'write' ? 'write' : 'read';

        $target = $username !== ''
            ? $this->app->db()->fetchOne(
                'SELECT `id`, `username` FROM `users` WHERE `username` = :name OR `email` = :email LIMIT 1',
                ['name' => $username, 'email' => strtolower($username)],
            )
            : false;

        if ($target === false) {
            flashExit('flash_error', 'No registered user matches that username or email.', "/{$user}/{$dbRepo['slug']}/settings/collaborators");
        }

        try {
            $this->app->db()->execute(
                'INSERT INTO `repo_collaborators` (`repo_id`, `user_id`, `role`, `created_at`)
                 VALUES (:repo, :user, :role, NOW())
                 ON DUPLICATE KEY UPDATE `role` = VALUES(`role`)',
                ['repo' => (int) $dbRepo['id'], 'user' => (int) $target['id'], 'role' => $role],
            );
        } catch (\Throwable $e) {
            flashExit('flash_error', 'Failed to add collaborator: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/settings/collaborators");
        }

        (new \App\Service\NotificationService($this->app))->notifyUser(
            (int) $target['id'],
            (int) $dbRepo['id'],
            'collaborator',
            "You were granted {$role} access to {$dbRepo['name']}",
            "/{$user}/{$dbRepo['slug']}",
        );

        flashExit('flash_success', "User '{$target['username']}' can now access this repository.", "/{$user}/{$dbRepo['slug']}/settings/collaborators");
    }

    /** POST /{user}/{repo}/settings/collaborators/{id}/delete */
    public function collaboratorsRemove(string $user, string $repo, int $id): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/collaborators");
        }

        $this->app->db()->execute(
            'DELETE FROM `repo_collaborators` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        flashExit('flash_success', 'Collaborator access revoked.', "/{$user}/{$dbRepo['slug']}/settings/collaborators");
    }

    /** GET /{user}/{repo}/settings/branches — branch protection rules */
    public function branches(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        $rules = $this->app->db()->fetchAll(
            'SELECT * FROM `branch_protections` WHERE `repo_id` = :repo ORDER BY `branch_name` ASC',
            ['repo' => (int) $dbRepo['id']],
        );

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        $this->render($dbRepo, 'branches', [
            'rules'     => $rules,
            'branches'  => (new \App\Service\GitReader())->getBranches($repoPath),
        ]);
    }

    /** POST /{user}/{repo}/settings/branches — add protection rule */
    public function branchesAdd(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/branches");
        }

        $branchName = trim((string) ($_POST['branch_name'] ?? ''));

        if ($branchName === '' || ! preg_match('/^[a-zA-Z0-9._\-\/]{1,255}$/', $branchName)) {
            flashExit('flash_error', 'Invalid branch name.', "/{$user}/{$dbRepo['slug']}/settings/branches");
        }

        try {
            $this->app->db()->execute(
                'INSERT INTO `branch_protections`
                    (`repo_id`, `branch_name`, `prevent_force_push`, `prevent_delete`, `allow_admin`, `require_approval`, `created_at`)
                 VALUES (:repo, :branch, 1, 1, :admin, :req_appr, NOW())
                 ON DUPLICATE KEY UPDATE
                    `prevent_force_push` = VALUES(`prevent_force_push`),
                    `prevent_delete` = VALUES(`prevent_delete`),
                    `allow_admin` = VALUES(`allow_admin`),
                    `require_approval` = VALUES(`require_approval`)',
                [
                    'repo'     => (int) $dbRepo['id'],
                    'branch'   => $branchName,
                    'admin'    => isset($_POST['allow_admin']) ? 1 : 0,
                    'req_appr' => isset($_POST['require_approval']) ? 1 : 0,
                ],
            );
        } catch (\Throwable $e) {
            flashExit('flash_error', 'Failed to protect branch: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/settings/branches");
        }

        // Make sure the repo has the current hooks installed.
        try { $this->gitService->installHooks($dbRepo['slug']); } catch (\Throwable) {}

        flashExit('flash_success', "Branch '{$branchName}' is now protected.", "/{$user}/{$dbRepo['slug']}/settings/branches");
    }

    /** POST /{user}/{repo}/settings/branches/{id}/delete — remove rule */
    public function branchesRemove(string $user, string $repo, int $id): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/branches");
        }

        $this->app->db()->execute(
            'DELETE FROM `branch_protections` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        flashExit('flash_success', 'Protection rule removed.', "/{$user}/{$dbRepo['slug']}/settings/branches");
    }

    /** GET /{user}/{repo}/settings/webhooks */
    public function webhooks(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        $hooks = $this->app->db()->fetchAll(
            'SELECT w.*, 
                    (SELECT COUNT(*) FROM `webhook_deliveries` d WHERE d.`webhook_id` = w.`id`) AS deliveries_count,
                    (SELECT MAX(d.`created_at`) FROM `webhook_deliveries` d WHERE d.`webhook_id` = w.`id`) AS last_delivery
             FROM `webhooks` w
             WHERE w.`repo_id` = :repo
             ORDER BY w.`created_at` ASC',
            ['repo' => (int) $dbRepo['id']],
        );

        $deliveries = [];
        if ($hooks !== []) {
            $hookIds = array_map(static fn($h) => (int) $h['id'], $hooks);
            $in      = implode(',', $hookIds);
            $deliveries = $this->app->db()->fetchAll(
                "SELECT d.* FROM `webhook_deliveries` d
                 WHERE d.`webhook_id` IN ({$in})
                 ORDER BY d.`created_at` DESC
                 LIMIT 25",
            );
        }

        $this->render($dbRepo, 'webhooks', [
            'hooks'      => $hooks,
            'deliveries' => $deliveries,
            'events'     => \App\Service\WebhookService::SUPPORTED_EVENTS,
        ]);
    }

    /** POST /{user}/{repo}/settings/webhooks — add webhook */
    public function webhooksAdd(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        $url    = trim((string) ($_POST['url'] ?? ''));
        $secret = trim((string) ($_POST['secret'] ?? ''));
        $events = $_POST['events'] ?? [];

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            flashExit('flash_error', 'Webhook URL must be a valid http(s) URL.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        if (! is_array($events) || $events === []) {
            flashExit('flash_error', 'Select at least one event.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        $validEvents = array_values(array_intersect(
            (array) $events,
            \App\Service\WebhookService::SUPPORTED_EVENTS,
        ));

        if ($validEvents === []) {
            flashExit('flash_error', 'Unsupported event selected.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        try {
            $this->app->db()->execute(
                'INSERT INTO `webhooks` (`repo_id`, `url`, `secret`, `events`, `is_active`, `created_at`)
                 VALUES (:repo, :url, :secret, :events, 1, NOW())',
                [
                    'repo'   => (int) $dbRepo['id'],
                    'url'    => mb_substr($url, 0, 500),
                    'secret' => $secret !== '' ? mb_substr($secret, 0, 255) : null,
                    'events' => implode(',', $validEvents),
                ],
            );
        } catch (\Throwable $e) {
            flashExit('flash_error', 'Failed to create webhook: ' . $e->getMessage(), "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        flashExit('flash_success', 'Webhook created.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
    }

    /** POST /{user}/{repo}/settings/webhooks/{id}/delete */
    public function webhooksRemove(string $user, string $repo, int $id): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
        }

        $this->app->db()->execute(
            'DELETE FROM `webhooks` WHERE `id` = :id AND `repo_id` = :repo',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        flashExit('flash_success', 'Webhook deleted.', "/{$user}/{$dbRepo['slug']}/settings/webhooks");
    }

    /** POST /{user}/{repo}/settings/delete — danger zone */
    public function delete(string $user, string $repo): void
    {
        [$dbRepo] = $this->requireManageAccess($user, $repo);

        if (! $this->auth->validateCsrf()) {
            flashExit('flash_error', 'Invalid security token.', "/{$user}/{$dbRepo['slug']}/settings");
        }

        if (trim((string) ($_POST['confirm_slug'] ?? '')) !== (string) $dbRepo['slug']) {
            flashExit('flash_error', 'Type the repository slug exactly to confirm deletion.', "/{$user}/{$dbRepo['slug']}/settings");
        }

        try {
            $this->gitService->deleteRepo($dbRepo['slug']);
        } catch (\Throwable $e) {
            error_log('[RepoSettings] disk deletion failed: ' . $e->getMessage());
        }

        $name = (string) $dbRepo['name'];
        $slug = (string) $dbRepo['slug'];

        $this->app->db()->execute('DELETE FROM `repositories` WHERE `id` = :id', ['id' => (int) $dbRepo['id']]);

        $this->auditLogger->log('repo.delete', null, "Deleted repository {$slug}", $this->identityId(), $this->displayName());

        flashExit('flash_success', "Repository '{$name}' deleted.", '/');
    }

    // ── Shared plumbing ─────────────────────────────────────────────

    /** Resolve repo + enforce that current identity may manage it. @return array<int, array<string,mixed>> */
    private function requireManageAccess(string $user, string $repo): array
    {
        $this->auth->requireAuth();

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 404, 'message' => 'Repository not found.']);
            exit;
        }

        $isCreator = ! $this->auth->isOwner()
            && (int) ($dbRepo['owner_user_id'] ?? 0) > 0
            && (int) $dbRepo['owner_user_id'] === $this->identityId();

        if (! $this->auth->isOwner() && ! $isCreator) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 403, 'message' => 'Only the repository owner can manage settings.']);
            exit;
        }

        return [$dbRepo];
    }

    /** @param array<string, mixed> $extra */
    private function render(array $dbRepo, string $tab, array $extra = []): void
    {
        $ownerName = $this->resolveOwnerName($dbRepo);

        $allowDel = $this->auth->isOwner() || $this->auth->isAdmin() || (($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'allow_repo_deletion'")['value'] ?? '1') === '1');
        $maxCollabs = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_collaborators_per_repo'")['value'] ?? 20);
        $maxWebhooks = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_webhooks_per_repo'")['value'] ?? 10);

        $this->app->view()->display('repo/settings.twig', array_merge([
            'owner_name'          => $ownerName,
            'current_user'        => $ownerName,
            'repo'                => $dbRepo,
            'tab'                 => $tab,
            'show_settings_tab'   => true,
            'allow_repo_deletion' => $allowDel,
            'max_collaborators'   => $maxCollabs,
            'max_webhooks'        => $maxWebhooks,
            'csrf_token'          => $this->auth->generateCsrf(),
        ], $extra));
    }

    private function resolveOwnerName(array $dbRepo): string
    {
        $uid = (int) ($dbRepo['owner_user_id'] ?? 0);

        if ($uid > 0) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id', ['id' => $uid]);
            if ($u !== false) return (string) $u['username'];
        }

        return (string) $this->app->config('app.owner', 'admin');
    }

    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    private function displayName(): string
    {
        if ($this->auth->isOwner()) return 'owner';
        return (string) ($this->auth->user()['username'] ?? 'user');
    }

    /** @return array<string, mixed>|null */
    private function resolveRepo(string $slug): ?array
    {
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;
        if ($slug === '') return null;

        $row = $this->app->db()->fetchOne(
            'SELECT r.*, p.slug AS parent_slug, p.name AS parent_name
             FROM `repositories` r
             LEFT JOIN `repositories` p ON r.forked_from_id = p.id
             WHERE r.slug = :slug LIMIT 1',
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
}

/** Set session flash + redirect + exit. */
function flashExit(string $key, string $message, string $location): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION[$key] = $message;
    header('Location: ' . $location);
    exit;
}
