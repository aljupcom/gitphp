<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use App\Auth;

/**
 * Manages per-session dynamic security hash routing for the server admin control panel.
 * Provides stealth protection: only the authenticated platform owner with a matching
 * session cryptographic hash can access admin routes.
 */
final class AdminSecurityService
{
    /**
     * Get or generate the dynamic security hash prefix for the owner's session.
     * Example return: '/cp_8f93c72b4a16'
     */
    public static function getAdminPrefix(App $app): string
    {
        $auth = new Auth($app);
        if (! $auth->isOwner()) {
            return '/admin';
        }

        if (session_status() !== PHP_SESSION_ACTIVE && session_status() !== PHP_SESSION_DISABLED) {
            @session_start();
        }

        $stored = (string) ($_SESSION['admin_sec_prefix'] ?? '');
        if ($stored !== '' && preg_match('/^cp_[a-f0-9]{12}$/', $stored)) {
            return '/' . $stored;
        }

        $appSecret = (string) ($app->config('app.key') ?? $app->config('app.owner') ?? 'git_sec_salt');
        $sessionId = session_id() ?: bin2hex(random_bytes(8));
        $hash      = substr(hash_hmac('sha256', $sessionId . '_admin_scope_' . $appSecret, 'admin_guard'), 0, 12);
        $prefix    = 'cp_' . $hash;

        $_SESSION['admin_sec_prefix'] = $prefix;

        return '/' . $prefix;
    }

    /**
     * Validate that the given URL prefix matches the current authenticated owner's session hash.
     */
    public static function validatePrefix(string $prefix, App $app): bool
    {
        $auth = new Auth($app);
        if (! $auth->isOwner()) {
            return false;
        }

        $expected = trim(self::getAdminPrefix($app), '/');
        $given    = trim($prefix, '/');

        return hash_equals($expected, $given);
    }
}
