<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;

/**
 * Social interactions: starring, watching, and user notifications.
 *
 * Stars and watch subscriptions are per registered user account (the owner
 * account has no row in `users`, so it cannot star/watch — matching GitHub,
 * where the admin interacts through the admin dashboard instead).
 */
final class SocialController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** POST /{user}/{repo}/star — toggle the current user's star. */
    public function toggleStar(string $user, string $repo): void
    {
        $this->auth->requireAuth();

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            || ! empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || isset($_GET['ajax']);

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
                exit;
            }
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $repoId = (int) $dbRepo['id'];
        $userId = $this->auth->isOwner() ? 0 : $this->auth->userId();

        $existing = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_likes` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        $isStarred = false;
        if ($existing !== false) {
            $this->app->db()->execute(
                'DELETE FROM `repo_likes` WHERE `id` = :id',
                ['id' => (int) $existing['id']],
            );
            $this->app->db()->execute(
                'UPDATE `repositories` SET `stars_count` = GREATEST(`stars_count` - 1, 0) WHERE `id` = :id',
                ['id' => $repoId],
            );
            $isStarred = false;
        } else {
            $this->app->db()->execute(
                'INSERT INTO `repo_likes` (`repo_id`, `user_id`) VALUES (:repo, :user)',
                ['repo' => $repoId, 'user' => $userId],
            );
            $this->app->db()->execute(
                'UPDATE `repositories` SET `stars_count` = `stars_count` + 1 WHERE `id` = :id',
                ['id' => $repoId],
            );
            $isStarred = true;
        }

        $starsCount = (int) ($this->app->db()->fetchOne(
            'SELECT `stars_count` FROM `repositories` WHERE `id` = :id',
            ['id' => $repoId],
        )['stars_count'] ?? 0);

        // The cached repo row carries the old counter — flush the family.
        $this->app->cache()->forgetPrefix("repo:{$dbRepo['slug']}:");

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'          => true,
                'is_starred'  => $isStarred,
                'stars_count' => $starsCount,
            ]);
            exit;
        }

        header("Location: /{$user}/{$dbRepo['slug']}");
        exit;
    }

    /** POST /{user}/{repo}/watch — toggle the current user's subscription. */
    public function toggleWatch(string $user, string $repo): void
    {
        $this->auth->requireAuth();

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            || ! empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || isset($_GET['ajax']);

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
                exit;
            }
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$user}/{$dbRepo['slug']}");
            exit;
        }

        $repoId = (int) $dbRepo['id'];
        $userId = $this->auth->isOwner() ? 0 : $this->auth->userId();

        $existing = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_subscriptions` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        $isWatching = false;
        if ($existing !== false) {
            $this->app->db()->execute(
                'DELETE FROM `repo_subscriptions` WHERE `id` = :id',
                ['id' => (int) $existing['id']],
            );
            $isWatching = false;
        } else {
            $this->app->db()->execute(
                'INSERT INTO `repo_subscriptions` (`repo_id`, `user_id`) VALUES (:repo, :user)',
                ['repo' => $repoId, 'user' => $userId],
            );
            $isWatching = true;
        }

        $watchCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `repo_subscriptions` WHERE `repo_id` = :repo',
            ['repo' => $repoId],
        )['c'] ?? 0);

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'          => true,
                'is_watching' => $isWatching,
                'watch_count' => $watchCount,
            ]);
            exit;
        }

        header("Location: /{$user}/{$dbRepo['slug']}");
        exit;
    }

    /** GET /notifications — the current user's notification feed. */
    public function notifications(): void
    {
        $this->auth->requireUser();

        $userId = $this->auth->userId();

        $notifications = $this->app->db()->fetchAll(
            'SELECT n.*, r.slug AS repo_slug, r.name AS repo_name
             FROM `notifications` n
             LEFT JOIN `repositories` r ON r.id = n.repo_id
             WHERE n.user_id = :user
             ORDER BY n.created_at DESC
             LIMIT 100',
            ['user' => $userId],
        );

        $this->app->view()->display('notifications.twig', [
            'notifications' => $notifications,
            'csrf_token'    => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /notifications/read — mark every notification as read. */
    public function markAllRead(): void
    {
        $this->auth->requireUser();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: /notifications');
            exit;
        }

        $this->app->db()->execute(
            'UPDATE `notifications` SET `is_read` = 1 WHERE `user_id` = :user AND `is_read` = 0',
            ['user' => $this->auth->userId()],
        );

        header('Location: /notifications');
        exit;
    }

    /**
     * GET /api/v1/notifications/unread — lightweight JSON for the real-time
     * header badge (polled by the client). Guest/owner -> zero.
     */
    public function apiUnread(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (! $this->auth->isLoggedIn() || $this->auth->isOwner()) {
            echo json_encode(['count' => 0, 'items' => []]);
            return;
        }

        $userId = $this->auth->userId();
        if ($userId <= 0) {
            echo json_encode(['count' => 0, 'items' => []]);
            return;
        }

        $count = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS c FROM `notifications` WHERE `user_id` = :u AND `is_read` = 0',
            ['u' => $userId],
        )['c'] ?? 0);

        $items = $this->app->db()->fetchAll(
            'SELECT n.id, n.type, n.message, n.link, n.created_at,
                    r.slug AS repo_slug, r.name AS repo_name
             FROM `notifications` n
             LEFT JOIN `repositories` r ON r.id = n.repo_id
             WHERE n.user_id = :u AND n.is_read = 0
             ORDER BY n.created_at DESC
             LIMIT 6',
            ['u' => $userId],
        );

        echo json_encode(['count' => $count, 'items' => $items]);
    }

    /**
     * Look up a repo by slug and enforce visibility (public for everyone,
     * private only for the owner and collaborators).
     * @return array<string, mixed>|null
     */
    /** POST /u/{username}/follow — toggle following a user. */
    public function toggleFollow(string $username): void
    {
        $this->auth->requireAuth();
        $username = trim($username);

        $ownerName = (string) $this->app->config('app.owner', 'admin');
        $isOwnerTarget = strcasecmp($username, $ownerName) === 0;

        $targetUserId = 0;
        if (! $isOwnerTarget) {
            $userRow = $this->app->db()->fetchOne('SELECT `id` FROM `users` WHERE `username` = :name LIMIT 1', ['name' => $username]);
            if ($userRow === false) {
                $this->notFound();
                return;
            }
            $targetUserId = (int) $userRow['id'];
        }

        $currentUserId = $this->auth->isOwner() ? 0 : (int) $this->auth->userId();

        // Cannot follow yourself
        if ($currentUserId === $targetUserId && (($this->auth->isOwner() && $isOwnerTarget) || (! $this->auth->isOwner() && ! $isOwnerTarget))) {
            header("Location: /{$username}");
            exit;
        }

        $isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')
            || ! empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || isset($_GET['ajax']);

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
                exit;
            }
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: /{$username}");
            exit;
        }

        $existing = $this->app->db()->fetchOne(
            'SELECT `id` FROM `user_follows` WHERE `follower_id` = :f AND `following_id` = :t LIMIT 1',
            ['f' => $currentUserId, 't' => $targetUserId],
        );

        $isFollowing = false;
        if ($existing !== false) {
            $this->app->db()->execute(
                'DELETE FROM `user_follows` WHERE `id` = :id',
                ['id' => (int) $existing['id']],
            );
            $isFollowing = false;
        } else {
            $this->app->db()->execute(
                'INSERT INTO `user_follows` (`follower_id`, `following_id`) VALUES (:f, :t)',
                ['f' => $currentUserId, 't' => $targetUserId],
            );
            $isFollowing = true;
        }

        $followersCount = (int) ($this->app->db()->fetchOne(
            'SELECT COUNT(*) AS `c` FROM `user_follows` WHERE `following_id` = :t',
            ['t' => $targetUserId],
        )['c'] ?? 0);

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'              => true,
                'is_following'    => $isFollowing,
                'followers_count' => $followersCount,
            ]);
            exit;
        }

        header("Location: /{$username}");
        exit;
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
        $this->app->view()->display('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }
}
