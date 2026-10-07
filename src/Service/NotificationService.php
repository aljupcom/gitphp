<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * In-app notification fan-out helpers shared by web controllers and
 * CLI/hook contexts.
 */
final class NotificationService
{
    /** Severity levels (drive badge/toast/browser delivery). */
    public const SEV_INFO     = 'info';
    public const SEV_WARNING  = 'warning';
    public const SEV_CRITICAL = 'critical';

    /** Notification categories (drive per-category user preferences). */
    public const CAT_AUTH     = 'auth';
    public const CAT_SECURITY = 'security';
    public const CAT_REPO     = 'repo';
    public const CAT_LICENSE  = 'license';
    public const CAT_SYSTEM   = 'system';
    public const CAT_ISSUE    = 'issue';

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Interpolate {placeholders} in a message template with context values,
     * so callers build messages from variables (reason, ip, device, until,
     * limit, repo…) instead of hard-coding fully composed strings.
     */
    public function format(string $template, array $meta = []): string
    {
        $out = $template;
        foreach ($meta as $key => $value) {
            if (! is_scalar($value)) continue;
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }
        return $out;
    }

    /**
     * Notify every watcher + write collaborator of a repository,
     * excluding one user (usually the actor).
     */
    public function notifyRepoUsers(int $repoId, string $type, string $message, string $link, int $excludeUserId = -1): void
    {
        $db = $this->app->db()->connection();

        $stmt = $db->prepare(
            'INSERT INTO `notifications` (`user_id`, `repo_id`, `type`, `message`, `link`, `created_at`)
             SELECT DISTINCT s.`user_id`, :repo, :type, :message, :link, CURRENT_TIMESTAMP
             FROM `repo_subscriptions` s
             WHERE s.`repo_id` = :repo2 AND s.`user_id` <> :excluded
             UNION
             SELECT DISTINCT c.`user_id`, :repo3, :type2, :message2, :link2, CURRENT_TIMESTAMP
             FROM `repo_collaborators` c
             WHERE c.`repo_id` = :repo4 AND c.`role` = \'write\' AND c.`user_id` <> :excluded2',
        );

        $stmt->execute([
            'repo'      => $repoId,
            'repo2'     => $repoId,
            'repo3'     => $repoId,
            'repo4'     => $repoId,
            'type'      => mb_substr($type, 0, 30),
            'type2'     => mb_substr($type, 0, 30),
            'message'   => mb_substr($message, 0, 500),
            'message2'  => mb_substr($message, 0, 500),
            'link'      => mb_substr($link, 0, 500),
            'link2'     => mb_substr($link, 0, 500),
            'excluded'  => $excludeUserId,
            'excluded2' => $excludeUserId,
        ]);
    }

    /** Notify a single user (no-op for the sentinel owner id 0). */
    public function notifyUser(int $userId, ?int $repoId, string $type, string $message, string $link): void
    {
        if ($userId <= 0) return;

        try {
            $this->app->db()->execute(
                'INSERT INTO `notifications` (`user_id`, `repo_id`, `type`, `message`, `link`, `created_at`)
                 VALUES (:user, :repo, :type, :message, :link, CURRENT_TIMESTAMP)',
                [
                    'user'    => $userId,
                    'repo'    => $repoId,
                    'type'    => mb_substr($type, 0, 30),
                    'message' => mb_substr($message, 0, 500),
                    'link'    => mb_substr($link, 0, 500),
                ],
            );
        } catch (\Throwable) {
            // Notifications must never break the primary action.
        }
    }
}
