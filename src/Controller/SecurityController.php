<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\SecurityService;
use App\Service\TotpService;

/**
 * Account security settings: two-factor auth (TOTP) and the active
 * session registry. Available to every logged-in identity, including
 * the owner (whose 2FA secret lives in the settings table).
 */
final class SecurityController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /settings/security */
    public function index(): void
    {
        $this->auth->requireAuth();

        // Resolve the identity's 2FA state.
        $secret  = $this->totpSecret();
        $enabled = $this->totpEnabled();

        $pendingSecret = $_SESSION['2fa_draft'] ?? null;
        unset($_SESSION['2fa_draft']);

        $sessions = (new SecurityService($this->app))->listSessions($this->identityId());
        // Do not expose the raw token; derive a short display id.
        foreach ($sessions as &$s) {
            $s['short_token'] = substr((string) $s['token'], 0, 10);
            $s['is_current']  = ($s['token'] ?? '') === ($_SESSION['session_token'] ?? '...');
        }
        unset($s);

        $this->app->view()->display('account/security.twig', [
            'csrf_token'     => $this->auth->generateCsrf(),
            'enabled'        => $enabled,
            'secret'         => $secret,
            'uri'            => $secret !== '' ? TotpService::provisioningUri($secret, $this->auth->displayName()) : '',
            'pending_secret' => $pendingSecret,
            'pending_uri'    => $pendingSecret !== null ? TotpService::provisioningUri($pendingSecret, $this->auth->displayName()) : '',
            'sessions'       => $sessions,
        ]);
    }

    /** POST /settings/security/2fa/enable — generate a draft secret. */
    public function enable2fa(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flash('flash_error', 'Invalid security token.', '/settings/security');
        }

        // Avoid clobbering existing enabled 2FA.
        if ($this->totpEnabled()) {
            flash('flash_success', 'Two-factor authentication is already enabled.', '/settings/security');
        }

        $_SESSION['2fa_draft'] = TotpService::generateSecret();
        flash('flash_success', 'Scan the code or enter the secret, then confirm.', '/settings/security');
    }

    /** POST /settings/security/2fa/confirm — verify a code and enable. */
    public function confirm2fa(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flash('flash_error', 'Invalid security token.', '/settings/security');
        }

        $draft = $_SESSION['2fa_draft'] ?? null;
        if ($draft === null) {
            flash('flash_error', 'No pending secret. Start by enabling 2FA.', '/settings/security');
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        if (! TotpService::verify($draft, $code)) {
            flash('flash_error', 'That code is incorrect. Keep the secret and try a fresh code.', '/settings/security');
        }

        if ($this->identityId() === 0) {
            $this->app->db()->execute(
                "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('owner_totp_secret', :sec)
                 ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)",
                ['sec' => $draft],
            );
            $this->app->db()->execute(
                "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('owner_totp_enabled', '1')
                 ON DUPLICATE KEY UPDATE `setting_value` = '1'",
            );
        } else {
            $this->app->db()->execute(
                'UPDATE `users` SET `totp_secret` = :sec, `totp_enabled` = 1 WHERE `id` = :id',
                ['sec' => $draft, 'id' => $this->identityId()],
            );
        }

        unset($_SESSION['2fa_draft']);
        flash('flash_success', 'Two-factor authentication is now enabled.', '/settings/security');
    }

    /** POST /settings/security/2fa/disable — verify a code and disable. */
    public function disable2fa(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flash('flash_error', 'Invalid security token.', '/settings/security');
        }

        $secret = $this->totpSecret();
        if (! $this->totpEnabled() || $secret === '') {
            flash('flash_error', 'Two-factor authentication is not enabled.', '/settings/security');
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        if (! TotpService::verify($secret, $code)) {
            flash('flash_error', 'That code is incorrect.', '/settings/security');
        }

        if ($this->identityId() === 0) {
            $this->app->db()->execute(
                "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('owner_totp_enabled', '0')
                 ON DUPLICATE KEY UPDATE `setting_value` = '0'",
            );
        } else {
            $this->app->db()->execute(
                'UPDATE `users` SET `totp_enabled` = 0 WHERE `id` = :id',
                ['id' => $this->identityId()],
            );
        }

        flash('flash_success', 'Two-factor authentication is disabled.', '/settings/security');
    }

    /** POST /settings/security/sessions/revoke/{token} */
    public function revokeSession(string $token): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flash('flash_error', 'Invalid security token.', '/settings/security');
        }

        (new SecurityService($this->app))->revokeSession($token, $this->identityId());
        flash('flash_success', 'Session revoked.', '/settings/security');
    }

    /** POST /settings/security/sessions/revoke-others */
    public function revokeOthers(): void
    {
        $this->auth->requireAuth();

        if (! $this->auth->validateCsrf()) {
            flash('flash_error', 'Invalid security token.', '/settings/security');
        }

        $current = (string) ($_SESSION['session_token'] ?? '');
        $count   = $current !== ''
            ? (new SecurityService($this->app))->revokeOthers($current, $this->identityId())
            : 0;

        flash('flash_success', $count > 0 ? "Revoked {$count} other session(s)." : 'No other active sessions.', '/settings/security');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function identityId(): int
    {
        return $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);
    }

    private function totpSecret(): string
    {
        if ($this->auth->isOwner()) {
            $row = $this->app->db()->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_key = 'owner_totp_secret' LIMIT 1",
            );
            return $row !== false ? (string) $row['setting_value'] : '';
        }

        $row = $this->app->db()->fetchOne('SELECT `totp_secret` FROM `users` WHERE `id` = :id', ['id' => $this->identityId()]);
        return $row !== false ? (string) ($row['totp_secret'] ?? '') : '';
    }

    private function totpEnabled(): bool
    {
        if ($this->auth->isOwner()) {
            $row = $this->app->db()->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_key = 'owner_totp_enabled' LIMIT 1",
            );
            return $row !== false && ($row['setting_value'] ?? '0') === '1';
        }

        $row = $this->app->db()->fetchOne('SELECT `totp_enabled` FROM `users` WHERE `id` = :id', ['id' => $this->identityId()]);
        return $row !== false && ! empty($row['totp_enabled']);
    }
}

/** Flash + redirect helper for this controller family. */
function flash(string $key, string $message, string $location): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION[$key] = $message;
    header('Location: ' . $location);
    exit;
}
