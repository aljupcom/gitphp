<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Security helpers shared by Auth / auth controller and the security
 * settings page: persistent sessions registry, "remember me" cookies
 * and the email password-reset token flow.
 */
final class SecurityService
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ── "Remember me" cookies ────────────────────────────────────────

    private const REMEMBER_COOKIE = 'gitphp_remember';
    private const REMEMBER_DAYS   = 30;

    /** Issue a persistent-login cookie for an identity (0 = owner). */
    public function issueRemember(int $userId): void
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $exp  = time() + self::REMEMBER_DAYS * 86400;

        try {
            $this->app->db()->execute(
                'INSERT INTO `remember_tokens` (`user_id`, `token_hash`, `expires_at`, `created_at`)
                 VALUES (:uid, :hash, FROM_UNIXTIME(:exp), NOW())',
                ['uid' => max(0, $userId), 'hash' => $hash, 'exp' => $exp],
            );
        } catch (\Throwable $e) {
            error_log('[SecurityService] remember issue failed: ' . $e->getMessage());
            return;
        }

        $this->setCookie(self::REMEMBER_COOKIE, $raw, $exp);
    }

    /**
     * Consume a remember cookie for a logged-out request.
     * @return array{user_id:int, username:string, is_owner:bool}|null
     */
    public function consumeRemember(): ?array
    {
        $raw = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($raw === '' || ! preg_match('/^[a-f0-9]{64}$/', $raw)) return null;

        $hash = hash('sha256', $raw);

        $row = $this->app->db()->fetchOne(
            'SELECT u.id, u.username, u.password_hash, t.id AS token_id
             FROM `remember_tokens` t
             LEFT JOIN `users` u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND t.expires_at > NOW() AND t.user_id > 0
             LIMIT 1',
            ['hash' => $hash],
        );

        if ($row === false) {
            return null;
        }

        // Rotate the token (one-time use).
        $this->app->db()->execute('DELETE FROM `remember_tokens` WHERE `token_hash` = :hash', ['hash' => $hash]);

        return [
            'user_id'  => (int) $row['id'],
            'username' => (string) $row['username'],
            'is_owner' => false,
        ];
    }

    /** Clear the remember cookie (logout). */
    public function clearRemember(): void
    {
        $this->setCookie(self::REMEMBER_COOKIE, '', time() - 3600);
    }

    // ── Session registry ─────────────────────────────────────────────

    
    /**
     * Resolve real client IP address with Cloudflare and reverse-proxy support.
     */
    public static function resolveClientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                if (str_contains((string) $candidate, ',')) {
                    $candidate = trim(explode(',', (string) $candidate)[0]);
                }
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return (string) $candidate;
                }
            }
        }

        return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function trackSession(int $userId, array|string|null $clientHints = null): string
    {
        $token = (string) ($_SESSION['session_token'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = mb_substr(self::resolveClientIp(), 0, 45);
        $hints = $clientHints ?? ($_POST['_client_hints'] ?? null);

        // Run detection and Google certified device resolution
        $dev = DeviceDetector::detect($ua, $hints);

        try {
            // 1. If the current active session already has a token, update its details
            if ($token !== '') {
                $existing = $this->app->db()->fetchOne(
                    'SELECT `id` FROM `user_sessions` WHERE `token` = :token AND `user_id` = :uid LIMIT 1',
                    ['token' => $token, 'uid' => max(0, $userId)],
                );
                if ($existing !== false) {
                    $this->app->db()->execute(
                        'UPDATE `user_sessions` SET
                            `ip_address` = :ip, `user_agent` = :ua,
                            `device_brand` = :brand, `device_model` = :model, `device_code` = :code, `device_type` = :type,
                            `os_name` = :os_name, `os_version` = :os_version, `browser_name` = :browser_name, `browser_version` = :browser_version,
                            `client_hints` = :hints, `last_activity` = NOW()
                         WHERE `id` = :id',
                        [
                            'ip'              => $ip,
                            'ua'              => mb_substr($ua, 0, 255),
                            'brand'           => $dev['brand'],
                            'model'           => $dev['model'],
                            'code'            => $dev['code'],
                            'type'            => $dev['type'],
                            'os_name'         => $dev['os_name'],
                            'os_version'      => $dev['os_version'],
                            'browser_name'    => $dev['browser_name'],
                            'browser_version' => $dev['browser_version'],
                            'hints'           => $dev['client_hints_json'],
                            'id'              => (int) $existing['id'],
                        ],
                    );
                    return $token;
                }
            }

            // 2. Prevent duplicate rapid insertions (e.g. from rapid login redirects within 120s on same IP & device)
            $recent = $this->app->db()->fetchOne(
                'SELECT `token`, `id` FROM `user_sessions`
                 WHERE `user_id` = :uid AND `ip_address` = :ip AND `user_agent` = :ua
                   AND `created_at` >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
                 ORDER BY `id` DESC LIMIT 1',
                [
                    'uid' => max(0, $userId),
                    'ip'  => $ip,
                    'ua'  => mb_substr($ua, 0, 255),
                ],
            );

            if ($recent !== false && ! empty($recent['token'])) {
                $this->app->db()->execute(
                    'UPDATE `user_sessions` SET
                        `device_brand` = :brand, `device_model` = :model, `device_code` = :code, `device_type` = :type,
                        `os_name` = :os_name, `os_version` = :os_version, `browser_name` = :browser_name, `browser_version` = :browser_version,
                        `client_hints` = :hints, `last_activity` = NOW()
                     WHERE `id` = :id',
                    [
                        'brand'           => $dev['brand'],
                        'model'           => $dev['model'],
                        'code'            => $dev['code'],
                        'type'            => $dev['type'],
                        'os_name'         => $dev['os_name'],
                        'os_version'      => $dev['os_version'],
                        'browser_name'    => $dev['browser_name'],
                        'browser_version' => $dev['browser_version'],
                        'hints'           => $dev['client_hints_json'],
                        'id'              => (int) $recent['id'],
                    ],
                );
                return (string) $recent['token'];
            }

            // 2.5 Prune expired idle sessions and enforce max active sessions per user
            try {
                $idleHours = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'session_idle_timeout_hours'")['value'] ?? 72);
                if ($idleHours > 0) {
                    $this->app->db()->execute(
                        'DELETE FROM `user_sessions` WHERE `last_activity` < DATE_SUB(NOW(), INTERVAL :h HOUR)',
                        ['h' => $idleHours]
                    );
                }

                $maxSessions = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_active_sessions_per_user'")['value'] ?? 5);
                if ($maxSessions > 0 && $userId > 0) {
                    $count = (int) ($this->app->db()->fetchOne(
                        'SELECT COUNT(*) AS c FROM `user_sessions` WHERE `user_id` = :uid',
                        ['uid' => $userId]
                    )['c'] ?? 0);

                    if ($count >= $maxSessions) {
                        $toDeleteCount = ($count - $maxSessions) + 1;
                        $this->app->db()->execute(
                            "DELETE FROM `user_sessions` WHERE `user_id` = :uid ORDER BY `last_activity` ASC LIMIT {$toDeleteCount}",
                            ['uid' => $userId]
                        );
                    }
                }
            } catch (\Throwable) {}

            // 3. Issue a fresh session token
            $newToken = bin2hex(random_bytes(24));
            $this->app->db()->execute(
                'INSERT INTO `user_sessions` (
                    `user_id`, `token`, `ip_address`, `user_agent`,
                    `device_brand`, `device_model`, `device_code`, `device_type`,
                    `os_name`, `os_version`, `browser_name`, `browser_version`,
                    `client_hints`, `created_at`, `last_activity`
                ) VALUES (
                    :uid, :token, :ip, :ua,
                    :brand, :model, :code, :type,
                    :os_name, :os_version, :browser_name, :browser_version,
                    :hints, NOW(), NOW()
                )',
                [
                    'uid'             => max(0, $userId),
                    'token'           => $newToken,
                    'ip'              => $ip,
                    'ua'              => mb_substr($ua, 0, 255),
                    'brand'           => $dev['brand'],
                    'model'           => $dev['model'],
                    'code'            => $dev['code'],
                    'type'            => $dev['type'],
                    'os_name'         => $dev['os_name'],
                    'os_version'      => $dev['os_version'],
                    'browser_name'    => $dev['browser_name'],
                    'browser_version' => $dev['browser_version'],
                    'hints'           => $dev['client_hints_json'],
                ],
            );
            return $newToken;
        } catch (\Throwable $e) {
            error_log('[SecurityService] track session failed: ' . $e->getMessage());
        }

        return bin2hex(random_bytes(24));
    }

    /** @return array<int, array<string, mixed>> */
    public function listSessions(int $userId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT `id`, `token`, `ip_address`, `user_agent`,
                    `device_brand`, `device_model`, `device_code`, `device_type`,
                    `os_name`, `os_version`, `browser_name`, `browser_version`,
                    `created_at`, `last_activity`
             FROM `user_sessions` WHERE `user_id` = :uid ORDER BY `last_activity` DESC LIMIT 25',
            ['uid' => max(0, $userId)],
        );

        foreach ($rows as &$row) {
            if (empty($row['device_model'])) {
                $dev = DeviceDetector::detect($row['user_agent'] ?? '');
                $row['device_brand']    = $dev['brand'];
                $row['device_model']    = $dev['model'];
                $row['device_code']     = $dev['code'];
                $row['device_type']     = $dev['type'];
                $row['os_name']         = $dev['os_name'];
                $row['os_version']      = $dev['os_version'];
                $row['browser_name']    = $dev['browser_name'];
                $row['browser_version'] = $dev['browser_version'];
            }
        }
        unset($row);

        return $rows;
    }

    /** Revoke a session by token; returns whether a row changed. */
    public function revokeSession(string $token, int $userId): bool
    {
        return $this->app->db()->execute(
            'DELETE FROM `user_sessions` WHERE `token` = :token AND `user_id` = :uid',
            ['token' => $token, 'uid' => max(0, $userId)],
        )->rowCount() > 0;
    }

    /** Revoke every session except the current one (other devices). */
    public function revokeOthers(string $currentToken, int $userId): int
    {
        return $this->app->db()->execute(
            'DELETE FROM `user_sessions` WHERE `user_id` = :uid AND `token` <> :cur',
            ['uid' => max(0, $userId), 'cur' => $currentToken],
        )->rowCount();
    }

    // ── Password reset ───────────────────────────────────────────────

    /**
     * Issue a reset token and email the link.
     * @return string The raw token (also emailed).
     */
    public function issuePasswordReset(int $userId, string $email): string
    {
        $raw  = bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);

        // Invalidate older tokens for the same account.
        $this->app->db()->execute(
            'UPDATE `password_reset_tokens` SET `used_at` = NOW()
             WHERE `user_id` = :uid AND `used_at` IS NULL',
            ['uid' => max(0, $userId)],
        );

        $this->app->db()->execute(
            'INSERT INTO `password_reset_tokens` (`user_id`, `token_hash`, `expires_at`, `created_at`)
             VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NOW())',
            ['uid' => max(0, $userId), 'hash' => $hash],
        );

        $appUrl = rtrim((string) $this->app->config('app.url', ''), '/');
        $link   = "{$appUrl}/reset-password/{$raw}";

        $appName = (string) $this->app->config('app.name', 'GitPHP');
        $html = '<p>Hello,</p><p>We received a request to reset your <strong>' . htmlspecialchars($appName) . '</strong> password.</p>'
              . '<p><a href="' . htmlspecialchars($link) . '">Reset your password</a></p>'
              . '<p>This link is valid for <strong>30 minutes</strong>. If you did not request this, you can safely ignore this email.</p>';
        $text = "Reset your {$appName} password within 30 minutes: {$link}";

        (new Mailer())->send($email, "[{$appName}] Password reset", $html, $text);

        return $raw;
    }

    /**
     * Redeem a reset token (mark consumed) and return the user id.
     * @return int|false The user id, or false when invalid/expired/used.
     */
    public function redeemPasswordReset(string $raw): int|false
    {
        $raw = trim($raw);
        if ($raw === '' || ! preg_match('/^[a-f0-9]{48}$/', $raw)) return false;

        $hash = hash('sha256', $raw);

        $row = $this->app->db()->fetchOne(
            'SELECT `id`, `user_id` FROM `password_reset_tokens`
             WHERE `token_hash` = :hash AND `used_at` IS NULL AND `expires_at` > NOW()
             LIMIT 1',
            ['hash' => $hash],
        );

        if ($row === false) return false;

        $this->app->db()->execute(
            'UPDATE `password_reset_tokens` SET `used_at` = NOW() WHERE `id` = :id',
            ['id' => (int) $row['id']],
        );

        return (int) $row['user_id'];
    }

    /** Find a user by username OR email (for forgot-password). */
    public function findUserByIdentifier(string $identifier): array|false
    {
        $identifier = trim($identifier);

        return $this->app->db()->fetchOne(
            'SELECT `id`, `username`, `email` FROM `users`
             WHERE `username` = :name OR `email` = :email LIMIT 1',
            ['name' => $identifier, 'email' => mb_strtolower($identifier)],
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function setCookie(string $name, string $value, int $expiry): void
    {
        $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie($name, $value, [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
