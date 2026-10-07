<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Single source of truth for the admin sidebar navigation counters.
 *
 * Previously every admin controller carried a private copy of
 * getNavCounts(); one copy-pasted query targeted a nonexistent
 * `issues` table (the real table is `bug_reports`), which made the
 * exception swallow silently and the counters render as zero/empty
 * on every admin page.
 */
final class NavCounts
{
    /**
     * Cached navigation counters shared by all admin pages.
     * @return array{repos:int, issues:int, downloads:int, ssh_keys:int, users:int}
     */
    public static function get(App $app): array
    {
        try {
            return $app->cache()->remember('admin:nav_counts', 60, static function () use ($app): array {
                return [
                    'repos'     => (int) ($app->db()->fetchOne('SELECT COUNT(*) AS c FROM `repositories`')['c'] ?? 0),
                    'issues'    => (int) ($app->db()->fetchOne("SELECT COUNT(*) AS c FROM `bug_reports` WHERE `status` = 'open'")['c'] ?? 0),
                    'downloads' => (int) ($app->db()->fetchOne('SELECT COUNT(*) AS c FROM `file_downloads`')['c'] ?? 0),
                    'download_reports' => (int) ($app->db()->fetchOne("SELECT COUNT(*) AS c FROM `download_reports` WHERE `status` = 'open'")['c'] ?? 0),
                    'ssh_keys'  => (int) ($app->db()->fetchOne('SELECT COUNT(*) AS c FROM `ssh_keys`')['c'] ?? 0),
                    'users'     => (int) ($app->db()->fetchOne('SELECT COUNT(*) AS c FROM `users`')['c'] ?? 0),
                ];
            });
        } catch (\Throwable) {
            return [
                'repos'     => 0,
                'issues'    => 0,
                'downloads' => 0,
                'ssh_keys'  => 0,
                'users'     => 0,
            ];
        }
    }
}
