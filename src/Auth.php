<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private App $app;

    /** Set when a login needed a 2FA step (has stored a pending identity). */
    public bool $authPending2fa = false;

    /** Context captured during loginUser() for the suspension/lock message. */
    public ?string $lastSuspensionReason = null;
    public ?string $lastSuspensionUntil  = null;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Verify credentials and start an authenticated session.
     *
     * Accepts either the owner account (when $username is empty or matches
     * APP_OWNER) or a registered user account from the `users` table.
     * Prefer the explicit loginOwner()/loginUser() methods when the account
     * type is known in advance (e.g. the login page tabs).
     */
    public function login(string $password, string $username = ''): bool
    {
        $owner = (string) $this->app->config('app.owner', 'admin');

        $result = ($username === '' || hash_equals($owner, $username))
            ? $this->loginOwner($username === '' ? $owner : $username, $password)
            : $this->loginUser($username, $password);

        return $result === AuthResult::SUCCESS;
    }

    /**
     * Owner (admin) account login: only the APP_OWNER identity and its
     * dedicated password are accepted — never a user-table account.
     */
    public function loginOwner(string $username, string $password, bool $remember = false, array|string|null $clientHints = null): AuthResult
    {
        $owner = (string) $this->app->config('app.owner', 'admin');

        if (! hash_equals($owner, $username)) return AuthResult::WRONG_CREDENTIALS;

        $hash = $this->getSetting('owner_password_hash');

        if ($hash === null || $hash === '' || ! password_verify($password, $hash)) return AuthResult::WRONG_CREDENTIALS;

        // Owner 2FA lives in settings (owner_totp_*).
        if ($this->ownerTotpEnabled() && ! isset($_SESSION['2fa_pending'])) {
            $_SESSION['2fa_pending'] = ['identity' => $owner, 'owner' => true];
            $this->authPending2fa = true;
            return AuthResult::TWOFA_PENDING;
        }

        $this->startSession(null, $owner, true, $clientHints);

        if ($remember) {
            (new \App\Service\SecurityService($this->app))->issueRemember(0);
        }

        return AuthResult::SUCCESS;
    }

    /**
     * Registered user account login (by username or email). The owner
     * identity can never log in through this path.
     *
     * Returns a reason-specific result so the caller can tell a banned
     * account apart from a wrong password — the suspension gate runs before
     * password verification (no credential leak, correct message always).
     * read_only / shadow_banned accounts ARE allowed to sign in; the
     * restrictions are enforced post-login via isReadOnly()/isBot().
     */
    public function loginUser(string $username, string $password, bool $remember = false, array|string|null $clientHints = null): AuthResult
    {
        $owner = (string) $this->app->config('app.owner', 'admin');

        // The owner name is reserved — reject it here as well.
        if (hash_equals($owner, $username)) return AuthResult::WRONG_CREDENTIALS;

        $user = $this->app->db()->fetchOne(
            'SELECT * FROM `users` WHERE `username` = :name OR `email` = :email LIMIT 1',
            ['name' => $username, 'email' => strtolower($username)],
        );

        if ($user === false) return AuthResult::WRONG_CREDENTIALS;

        $this->lastSuspensionReason = trim((string) ($user['status_reason'] ?? ''));
        $this->lastSuspensionUntil  = (string) ($user['suspension_until'] ?? '');

        // Check account suspension, expiration, or security lockdown
        if (! empty($user['is_suspended'])) {
            $sType = (string) ($user['suspension_type'] ?? 'suspended');

            // read_only / shadow_banned / bot are never a hard login block
            // (bot is handled below as its own message).
            if ($sType === 'read_only' || $sType === 'shadow_banned') {
                // allow sign-in; the app enforces the restriction afterward.
            } elseif (! empty($user['suspension_until']) && strtotime((string) $user['suspension_until']) <= time()) {
                // Auto-lift expired suspension
                $this->app->db()->execute(
                    'UPDATE `users` SET `is_suspended` = 0, `suspension_type` = "none", `suspension_until` = NULL, `status_reason` = NULL WHERE `id` = :id',
                    ['id' => (int) $user['id']]
                );
                $this->lastSuspensionUntil = '';
            } elseif ($sType === 'locked') {
                return AuthResult::LOCKED;
            } elseif ($sType === 'bot') {
                return AuthResult::BOT;
            } else {
                return AuthResult::SUSPENDED;
            }
        }

        if (! password_verify($password, (string) $user['password_hash'])) return AuthResult::WRONG_CREDENTIALS;

        if (! empty($user['totp_enabled']) && ! empty($user['totp_secret']) && ! isset($_SESSION['2fa_pending'])) {
            $_SESSION['2fa_pending'] = ['identity' => (int) $user['id'], 'owner' => false];
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $this->authPending2fa = true;
            return AuthResult::TWOFA_PENDING;
        }

        $this->startSession((int) $user['id'], (string) $user['username'], false, $clientHints);

        if ($remember) {
            (new \App\Service\SecurityService($this->app))->issueRemember((int) $user['id']);
        }

        return AuthResult::SUCCESS;
    }

    /** Human message for a login outcome (reason-aware, no credential leak). */
    public function loginErrorMessage(AuthResult $result, bool $adminTab = false): string
    {
        if ($adminTab && $result === AuthResult::WRONG_CREDENTIALS) {
            return 'Incorrect administrator password.';
        }

        return match ($result) {
            AuthResult::WRONG_CREDENTIALS => 'Incorrect username or password.',
            AuthResult::SUSPENDED          => $this->suspensionMessage('suspended'),
            AuthResult::LOCKED             => $this->suspensionMessage('locked'),
            AuthResult::BOT                => 'Interactive web login is disabled for Service Bot accounts. Use API tokens or deploy keys.',
            AuthResult::NOT_VERIFIED       => 'Please verify your email address before signing in.',
            AuthResult::SUCCESS, AuthResult::TWOFA_PENDING => '',
        };
    }

    /** Compose a human suspension/lock message from the stored reason context. */
    private function suspensionMessage(string $type): string
    {
        $msg = $type === 'locked'
            ? 'This account has been locked for security.'
            : 'This account has been suspended by an administrator.';

        if ($this->lastSuspensionReason !== '') {
            $msg .= ' Reason: ' . $this->lastSuspensionReason;
        }

        if ($type === 'locked') {
            $msg .= ' Please contact your site administrator to unlock.';
        } elseif ($this->lastSuspensionUntil !== '') {
            $ts = strtotime($this->lastSuspensionUntil);
            if ($ts !== false) {
                $msg .= ' (Suspension active until ' . date('M j, Y H:i', $ts) . ' UTC)';
            }
        }

        return $msg;
    }

    /** Whether an owner password has been configured yet. */
    public function ownerPasswordSet(): bool
    {
        $hash = $this->getSetting('owner_password_hash');

        return $hash !== null && $hash !== '';
    }

    /** Start a fresh authenticated session (regenerates the ID). */
    private function startSession(?int $userId, string $userName, bool $isOwner, array|string|null $clientHints = null): void
    {
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION['is_logged_in'] = true;
        $_SESSION['user_id']      = $userId;
        $_SESSION['user_name']    = $userName;
        $_SESSION['is_owner']     = $isOwner;
        $_SESSION['login_time']   = time();

        // Record the session in the registry (list / revoke).
        $_SESSION['session_token'] = (new \App\Service\SecurityService($this->app))->trackSession($userId ?? 0, $clientHints);
    }

    /** Destroy the current session (and any remember cookie). */
    public function logout(): void
    {
        // Invalidate the tracked session row.
        $token = (string) ($_SESSION['session_token'] ?? '');
        if ($token !== '') {
            try {
                (new \App\Service\SecurityService($this->app))->revokeSession($token, $this->userId());
            } catch (\Throwable) {}
        }

        (new \App\Service\SecurityService($this->app))->clearRemember();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        session_destroy();
    }

    /** Check whether the current session is authenticated. */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true;
    }

    /** Return current user role ('admin', 'staff', 'auditor', 'user', 'restricted', 'bot'). */
    public function role(): string
    {
        if ($this->isOwner()) return 'admin';
        $user = $this->user();
        return (string) ($user['role'] ?? 'user');
    }

    /** Check if current user is an Administrator (Owner or user with role 'admin'). */
    public function isAdmin(): bool
    {
        if (! $this->isLoggedIn()) return false;
        if ($this->isOwner()) return true;
        return $this->role() === 'admin';
    }

    /** Check if current user is Staff / Moderator (Admin or Staff). */
    public function isStaff(): bool
    {
        if (! $this->isLoggedIn()) return false;
        if ($this->isAdmin()) return true;
        return $this->role() === 'staff';
    }

    /** Check if current user is Security Auditor (Admin, Staff, or Auditor). */
    public function isAuditor(): bool
    {
        if (! $this->isLoggedIn()) return false;
        if ($this->isStaff()) return true;
        return $this->role() === 'auditor';
    }

    /** Check if current user is Restricted. */
    public function isRestricted(): bool
    {
        if (! $this->isLoggedIn() || $this->isOwner()) return false;
        return $this->role() === 'restricted';
    }

    /** Check if current user is a Bot account. */
    public function isBot(): bool
    {
        if (! $this->isLoggedIn() || $this->isOwner()) return false;
        return $this->role() === 'bot';
    }

    /** Check if current user account is restricted to Read-Only mode. */
    public function isReadOnly(): bool
    {
        if (! $this->isLoggedIn() || $this->isOwner()) return false;
        $user = $this->user();
        if ($user === null) return false;
        if (! empty($user['is_suspended']) && ($user['suspension_type'] ?? '') === 'read_only') {
            if (! empty($user['suspension_until']) && strtotime((string) $user['suspension_until']) <= time()) {
                return false;
            }
            return true;
        }
        return false;
    }

    /** Check if identity has admin control panel access. */
    public function canAccessAdmin(): bool
    {
        return $this->isAuditor();
    }

    /** Require Admin access. */
    public function requireAdmin(): void
    {
        $this->requireAuth();
        if (! $this->isAdmin()) {
            $_SESSION['flash_error'] = 'Administrator privileges required.';
            header('Location: /');
            exit;
        }
    }

    /** Require Staff / Moderator access. */
    public function requireStaff(): void
    {
        $this->requireAuth();
        if (! $this->isStaff()) {
            $_SESSION['flash_error'] = 'Staff or Moderator privileges required.';
            header('Location: /');
            exit;
        }
    }

    /** Require Security Auditor or higher access. */
    public function requireAuditor(): void
    {
        $this->requireAuth();
        if (! $this->isAuditor()) {
            $_SESSION['flash_error'] = 'Auditor or Staff privileges required.';
            header('Location: /');
            exit;
        }
    }

    /** Check whether the authenticated identity is the owner (admin). */

    /** Return the session-specific dynamic admin route prefix (e.g. '/cp_a1c0987abc99'). */
    public function adminPrefix(): string
    {
        return \App\Service\AdminSecurityService::getAdminPrefix($this->app);
    }
    public function isOwner(): bool
    {
        if (! $this->isLoggedIn()) return false;

        if (! empty($_SESSION['is_owner'])) return true;

        // Legacy owner session (created before multi-user auth): logged in
        // with no user identity is, by definition, the owner account.
        return empty($_SESSION['user_id']);
    }

    /** Return the current logged-in user row, or null for the owner/guests. */
    public function user(): ?array
    {
        if (! $this->isLoggedIn() || empty($_SESSION['user_id'])) return null;

        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `users` WHERE `id` = :id LIMIT 1',
            ['id' => (int) $_SESSION['user_id']],
        );

        return $row !== false ? $row : null;
    }

    /** Return the current user's display name (owner name or username). */
    public function displayName(): string
    {
        if ($this->isOwner()) {
            return (string) ($this->app->config('app.owner') ?? 'admin');
        }

        $u = $this->user();
        if ($u && ! empty($u['username'])) {
            return (string) $u['username'];
        }

        if (! empty($_SESSION['user_name'])) {
            return (string) $_SESSION['user_name'];
        }

        return (string) ($this->app->config('app.owner') ?? 'admin');
    }

    /** Canonical username resolution for Git and API actions. */
    public function username(): string
    {
        return $this->displayName();
    }

    /** Redirect to /login if the user is not authenticated. */
    public function requireAuth(): void
    {
        if (! $this->isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }

    /** Redirect away unless the authenticated identity is the owner. */
    public function requireOwner(): void
    {
        if (! $this->isLoggedIn()) {
            header('Location: /login');
            exit;
        }

        if (! $this->isOwner()) {
            $_SESSION['flash_error'] = 'Only the administrator can access that area.';
            header('Location: /');
            exit;
        }
    }

    /**
     * Redirect unless the authenticated identity is a registered user
     * account (the owner/admin account does not qualify).
     */
    public function requireUser(): void
    {
        if (! $this->isLoggedIn()) {
            header('Location: /login');
            exit;
        }

        if ($this->isOwner() || empty($_SESSION['user_id'])) {
            $_SESSION['flash_error'] = 'Only registered user accounts can use this feature.';
            header('Location: /');
            exit;
        }
    }

    /** Return the current user's database id (0 for guests and the owner). */
    public function userId(): int
    {
        if (! $this->isLoggedIn() || $this->isOwner()) return 0;

        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /** Alias for userId() */
    public function id(): int
    {
        return $this->userId();
    }

    /**
     * Whether the current identity may view a repository: public repos are
     * open to everyone; private repos require the owner or a collaborator.
     */
    public function canViewRepo(int $repoId, string $visibility): bool
    {
        if ($visibility === 'public') return true;
        if (! $this->isLoggedIn()) return false;
        if ($this->isOwner()) return true;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId === 0) return false;

        // 1. Direct owner of repository
        $isRepoOwner = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `id` = :repo AND `owner_user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId]
        );
        if ($isRepoOwner !== false && $isRepoOwner !== null) {
            return true;
        }

        // 2. Collaborator with any role
        $row = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_collaborators` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        return $row !== false && $row !== null;
    }

    /**
     * Whether the current identity may push/write to a repository: the
     * platform owner always can; the repository creator always can;
     * registered users need a "write" or "admin" role in repo_collaborators.
     */
    public function canWriteRepo(int $repoId): bool
    {
        if (! $this->isLoggedIn()) return false;
        if ($this->isOwner()) return true;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId === 0) return false;

        // 1. Direct owner/creator of repository
        $isRepoOwner = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `id` = :repo AND `owner_user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId]
        );
        if ($isRepoOwner !== false && $isRepoOwner !== null) {
            return true;
        }

        // 2. Collaborator with write or admin role
        $row = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_collaborators`
             WHERE `repo_id` = :repo AND `user_id` = :user AND (`role` = \'write\' OR `role` = \'admin\') LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        return $row !== false && $row !== null;
    }

    /**
     * Whether the current identity may administer a repository: platform owner
     * and repo owner always can; collaborators need "admin" role.
     */
    public function canAdminRepo(int $repoId): bool
    {
        if (! $this->isLoggedIn()) return false;
        if ($this->isOwner()) return true;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId === 0) return false;

        $isRepoOwner = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `id` = :repo AND `owner_user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId]
        );
        if ($isRepoOwner !== false && $isRepoOwner !== null) {
            return true;
        }

        $row = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_collaborators`
             WHERE `repo_id` = :repo AND `user_id` = :user AND `role` = \'admin\' LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        return $row !== false && $row !== null;
    }

    /** Get the session CSRF token, generating it on first use. */
    public function generateCsrf(): string
    {
        // The token lives for the whole session: pages render several forms
        // (star + watch, per-row admin actions) that all share it, so it must
        // survive being used by any one of them.
        if (isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
            return $_SESSION['csrf_token'];
        }

        return $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /** Validate a CSRF token against the session value. */
    public function validateCsrf(?string $token = null): bool
    {
        if (! isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') return false;

        $token = $token ?? (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? '');
        if ($token === '') return false;

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /** Get the owner username from application config. */
    public function getOwnerUsername(): string
    {
        return (string) $this->app->config('app.owner', 'admin');
    }

    /** Fetch a value from the `settings` table by key. */
    private function getSetting(string $key): ?string
    {
        $row = $this->app->db()->fetchOne(
            'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :key LIMIT 1',
            ['key' => $key],
        );

        if ($row === false) return null;

        return $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
    }

    // ── Two-factor authentication (TOTP) ─────────────────────────────

    /** Whether the pending 2FA identity is the owner account. */
    public function pending2fa(): array|null
    {
        return isset($_SESSION['2fa_pending'])
            ? ['owner' => (bool) $_SESSION['2fa_pending']['owner'], 'identity' => $_SESSION['2fa_pending']['identity']]
            : null;
    }

    /**
     * Complete a pending 2FA login. Returns true when the code was valid
     * and the session was fully established (remember cookie re-issued).
     */
    public function complete2fa(string $code, ?bool $remember = null): bool
    {
        $pending = $this->pending2fa();
        if ($pending === null) return false;

        $owner = $pending['owner'];
        $identity = $pending['identity'];
        $isValid = $owner
            ? $this->ownerTotpEnabled() && \App\Service\TotpService::verify((string) $this->getSetting('owner_totp_secret'), $code)
            : $this->userTotpExists((int) $identity) && \App\Service\TotpService::verify($this->userTotpSecret((int) $identity), $code);

        if (! $isValid) return false;

        $remember = $remember ?? isset($_POST['remember']) || isset($_COOKIE['gitphp_remember']);
        if ($owner) {
            $this->startSession(null, (string) $this->app->config('app.owner', 'admin'), true);
            if ($remember) (new \App\Service\SecurityService($this->app))->issueRemember(0);
        } else {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id', ['id' => (int) $identity]);
            $username = $u !== false ? (string) $u['username'] : 'user';
            $this->startSession((int) $identity, $username, false);
            if ($remember) (new \App\Service\SecurityService($this->app))->issueRemember((int) $identity);
        }

        unset($_SESSION['2fa_pending']);

        return true;
    }

    /** Restore a session from a "remember me" cookie. Returns true if a session was created. */
    public function loginFromRemember(): bool
    {
        if ($this->isLoggedIn()) return false;

        $identity = (new \App\Service\SecurityService($this->app))->consumeRemember();
        if ($identity === null) return false;

        $this->startSession((int) $identity['user_id'], (string) $identity['username'], false);

        return true;
    }

    private function userTotpSecret(int $userId): string
    {
        $row = $this->app->db()->fetchOne('SELECT `totp_secret` FROM `users` WHERE `id` = :id', ['id' => $userId]);
        return $row !== false ? (string) ($row['totp_secret'] ?? '') : '';
    }

    private function userTotpExists(int $userId): bool
    {
        $row = $this->app->db()->fetchOne(
            'SELECT `totp_enabled` FROM `users` WHERE `id` = :id AND `totp_secret` IS NOT NULL',
            ['id' => $userId],
        );
        return $row !== false && ! empty($row['totp_enabled']);
    }

    private function ownerTotpEnabled(): bool
    {
        return (string) $this->getSetting('owner_totp_enabled') === '1'
            && (string) $this->getSetting('owner_totp_secret') !== '';
    }
}
