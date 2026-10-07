<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\GitReader;
use App\Service\GitService;

final class ForkController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private GitReader $gitReader;
    private AuditLogger $auditLogger;

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->auth        = new Auth($app);
        $this->gitService  = new GitService();
        $this->gitReader   = new GitReader();
        $this->auditLogger = new AuditLogger($app);
    }

    /** GET /{user}/{repo}/fork — show fork confirmation / naming form */
    public function create(string $user, string $repo): void
    {
        $this->auth->requireAuth();

        // Check policy
        if (! $this->auth->isOwner() && ! $this->auth->isAdmin()) {
            $userRole = (string) ($_SESSION['user']['role'] ?? 'user');
            if ($userRole === 'restricted' || $userRole === 'bot') {
                $_SESSION['flash_error'] = 'Your account role is restricted from forking repositories.';
                header("Location: /{$user}/{$repo}");
                exit;
            }
            $forkSetting = (string) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'allow_repo_forking'")['value'] ?? '1');
            if ($forkSetting === '0') {
                $_SESSION['flash_error'] = 'Repository forking is disabled by administrator policy.';
                header("Location: /{$user}/{$repo}");
                exit;
            }
        }

        $dbRepo = $this->resolveRepo($repo);
        if ($this->auth->isOwner()) {
            if ($user === 'admin' || (int) ($dbRepo['owner_user_id'] ?? 0) === 0) {
                $_SESSION['flash_error'] = 'You cannot fork your own repository.';
                header("Location: /{$user}/{$repo}");
                exit;
            }
        } elseif ((int) ($dbRepo['owner_user_id'] ?? 0) === (int) $this->auth->userId()) {
            $_SESSION['flash_error'] = 'You cannot fork your own repository.';
            header("Location: /{$user}/{$repo}");
            exit;
        }

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $owner = (string) $this->app->config('app.owner', 'admin');
        $currentUser = $this->auth->user();
        $targetUser  = $this->auth->isOwner() ? $owner : ($currentUser['username'] ?? 'user');

        $suggestedName = $dbRepo['name'] . ' Fork';
        $suggestedSlug = $dbRepo['slug'] . '-fork';

        $this->app->view()->display('repo/fork.twig', [
            'owner'          => $owner,
            'repo'           => $dbRepo,
            'target_user'    => $targetUser,
            'suggested_name' => $suggestedName,
            'suggested_slug' => $suggestedSlug,
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/fork — execute the repository fork */
    public function fork(string $user, string $repo): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->isOwner() && ! $this->auth->isAdmin()) {
            $userRole = (string) ($_SESSION['user']['role'] ?? 'user');
            if ($userRole === 'restricted' || $userRole === 'bot') {
                $_SESSION['flash_error'] = 'Your account role is restricted from forking repositories.';
                header("Location: /{$user}/{$repo}");
                exit;
            }
            $forkSetting = (string) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'allow_repo_forking'")['value'] ?? '1');
            if ($forkSetting === '0') {
                $_SESSION['flash_error'] = 'Repository forking is disabled by administrator policy.';
                header("Location: /{$user}/{$repo}");
                exit;
            }
            $maxRepos = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_repos_per_user'")['value'] ?? 50);
            if ($maxRepos > 0) {
                $uid = (int) ($this->auth->userId() ?? 0);
                $cnt = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE owner_user_id = :uid', ['uid' => $uid])['c'] ?? 0);
                if ($cnt >= $maxRepos) {
                    $_SESSION['flash_error'] = "Repository quota limit reached (Maximum allowed: {$maxRepos}).";
                    header("Location: /{$user}/{$repo}");
                    exit;
                }
            }
        }

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /{$user}/{$repo}/fork");
            exit;
        }

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $name = trim((string) ($_POST['name'] ?? $dbRepo['name']));
        $slug = trim((string) ($_POST['slug'] ?? ''));

        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $name));
        }

        $db = $this->app->db()->connection();

        // Check if slug already exists in DB
        $stmt = $db->prepare('SELECT id FROM repositories WHERE slug = ?');
        $stmt->execute([$slug]);
        if ($stmt->fetch()) {
            $_SESSION['flash_error'] = "A repository with the identifier '{$slug}' already exists.";
            header("Location: /{$user}/{$repo}/fork");
            exit;
        }

        try {
            // 1. Git bare clone
            $this->gitService->forkRepo($dbRepo['slug'], $slug);

            // 2. Insert into database — the fork belongs to the forking
            //    identity's namespace (registered user) or the owner.
            $visibility    = (string) ($dbRepo['visibility'] ?? 'public');
            $desc          = (string) ($dbRepo['description'] ?? '');
            $defaultBranch = (string) ($dbRepo['default_branch'] ?? 'main');
            $ownerUserId   = $this->auth->isOwner() ? null : $this->identityId();

            $insert = $db->prepare('
                INSERT INTO repositories (name, slug, description, visibility, default_branch, owner_user_id, forked_from_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ');
            $insert->execute([$name, $slug, $desc, $visibility, $defaultBranch, $ownerUserId, $dbRepo['id']]);
            $newRepoId = (int) $db->lastInsertId();

            // 3. Log audit event
            $currUser = $this->auth->user();
            $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
            $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

            $this->auditLogger->log(
                'repo.fork',
                $newRepoId,
                "Forked from {$dbRepo['slug']} (ID: {$dbRepo['id']}) as {$slug}",
                $userId,
                $userName
            );

            $_SESSION['flash_success'] = "Repository '{$name}' forked successfully.";
            $targetOwner = $this->auth->isOwner()
                ? (string) $this->app->config('app.owner', 'admin')
                : (string) ($this->auth->user()['username'] ?? 'user');
            header("Location: /{$targetOwner}/{$slug}");
            exit;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Fork failed: ' . $e->getMessage();
            header("Location: /{$user}/{$repo}");
            exit;
        }
    }

    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function resolveRepo(string $slug): ?array
    {
        // Tolerate clone-URL style paths (/{user}/{repo}.git)
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;
        if ($slug === '') return null;

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            SELECT r.*, p.slug AS parent_slug, p.name AS parent_name
            FROM repositories r
            LEFT JOIN repositories p ON r.forked_from_id = p.id
            WHERE r.slug = ?
            LIMIT 1
        ');
        $stmt->execute([$slug]);
        $repo = $stmt->fetch();
        if ($repo === false || $repo === null) return null;

        // Private repos may only be forked by the owner or collaborators.
        if (! $this->auth->canViewRepo((int) $repo['id'], (string) $repo['visibility'])) {
            return null;
        }

        return $repo;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->app->view()->render('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }
}
