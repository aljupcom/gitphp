<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\AuthResult;
use App\Middleware\RateLimit;

final class AuthController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /login — render the login form (member + admin tabs). */
    public function showLogin(): void
    {
        // Already logged in? Go to the admin/home depending on the identity.
        if ($this->auth->isLoggedIn()) {
            $this->redirectAfterLogin();
        }

        $csrf  = $this->auth->generateCsrf();
        $error = $_SESSION['login_error'] ?? null;
        $errorCode = $_SESSION['login_error_code'] ?? null;
        unset($_SESSION['login_error'], $_SESSION['login_error_code']);

        // ?tab=admin keeps the admin pane open after a failed attempt.
        $tab = ($_GET['tab'] ?? '') === 'admin' ? 'admin' : 'member';

        $this->app->view()->display('auth/login.twig', [
            'csrf_token'         => $csrf,
            'error'              => $error,
            'error_code'         => $errorCode,
            'owner_name'         => $this->auth->getOwnerUsername(),
            'owner_password_set' => $this->auth->ownerPasswordSet(),
            'login_tab'          => $tab,
            'hide_global_flash'  => true,
        ]);
    }

    /** POST /login — process login credentials (member or admin tab). */
    public function login(): void
    {
        $csrfToken = $_POST['csrf_token'] ?? '';

        if (! $this->auth->validateCsrf($csrfToken)) {
            $_SESSION['login_error'] = 'Invalid security token. Please try again.';
            header('Location: /login');
            exit;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimit($this->app);
        $rateKey     = 'login_' . $clientIp;

        if (! $rateLimiter->check($rateKey, maxAttempts: 5, decayMinutes: 15)) {
            $_SESSION['login_error'] = 'Too many login attempts. Please try again in 15 minutes.';
            header('Location: /login');
            exit;
        }

        $clientIp = \App\Service\SecurityService::resolveClientIp();
        $rateLimiter = new RateLimit($this->app);
        $rateKey     = 'login_' . $clientIp;

        $username    = trim((string) ($_POST['username'] ?? ''));
        $password    = (string) ($_POST['password'] ?? '');
        $remember    = ! empty($_POST['remember']);
        $clientHints = $_POST['_client_hints'] ?? null;

        // The admin tab only accepts the owner identity and password; the
        // member tab only accepts registered user accounts.
        $isAdminTab = ($_POST['account_type'] ?? 'member') === 'admin';
        $result     = $isAdminTab
            ? $this->auth->loginOwner($username, $password, $remember, $clientHints)
            : $this->auth->loginUser($username, $password, $remember, $clientHints);

        // Account uses two-factor auth: hand off to the code step.
        if ($this->auth->authPending2fa) {
            header('Location: /2fa' . ($isAdminTab ? '?tab=admin' : ''));
            exit;
        }

        if ($result === AuthResult::SUCCESS) {
            $rateLimiter->reset($rateKey);
            try {
                $uid = $this->auth->userId() ?? 0;
                $uname = $this->auth->userName() ?? $username;
                $dev = \App\Service\DeviceDetector::detect(null, $clientHints);
                $details = sprintf(
                    'Signed in via %s from %s (%s, %s)',
                    $isAdminTab ? 'Admin Console' : 'Member Portal',
                    $dev['full_name'],
                    $dev['os_name'],
                    $dev['browser_name']
                );
                (new \App\Service\AuditLogger($this->app))->log('auth.login', null, $details, $uid, $uname);
            } catch (\Throwable) {}
            $this->redirectAfterLogin();
        }

        // Failed attempt: record audit log and set error
        $rateLimiter->increment($rateKey);

        try {
            $dev = \App\Service\DeviceDetector::detect(null, $clientHints);
            $failDetails = sprintf(
                'Failed sign-in attempt (%s) for "%s" from %s (%s)',
                $result->name,
                $username,
                $clientIp,
                $dev['browser_name']
            );
            (new \App\Service\AuditLogger($this->app))->log('auth.login_failed', null, $failDetails, 0, $username ?: 'guest');
        } catch (\Throwable) {}

        $_SESSION['login_error']      = $this->auth->loginErrorMessage($result, $isAdminTab);
        $_SESSION['login_error_code'] = $result->name;

        header('Location: /login' . ($isAdminTab ? '?tab=admin' : ''));
        exit;
    }

    /** GET /register — public registration form for visitors. */
    public function showRegister(): void
    {
        if ($this->auth->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $csrf  = $this->auth->generateCsrf();
        $error = $_SESSION['register_error'] ?? null;
        unset($_SESSION['register_error']);

        $old = $_SESSION['register_old'] ?? [];
        unset($_SESSION['register_old']);

        $this->app->view()->display('auth/register.twig', [
            'csrf_token' => $csrf,
            'error'      => $error,
            'old'        => $old,
            'hide_global_flash' => true,
        ]);
    }

    /** POST /register — validate and create a new user account. */
    public function register(): void
    {
        if ($this->auth->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';

        if (! $this->auth->validateCsrf($csrfToken)) {
            $_SESSION['register_error'] = 'Invalid security token. Please try again.';
            header('Location: /register');
            exit;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimit($this->app);
        $rateKey     = 'register_' . $clientIp;

        if (! $rateLimiter->check($rateKey, maxAttempts: 5, decayMinutes: 15)) {
            $_SESSION['register_error'] = 'Too many registration attempts. Please try again in 15 minutes.';
            header('Location: /register');
            exit;
        }

        $username    = trim((string) ($_POST['username'] ?? ''));
        $email       = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password    = (string) ($_POST['password'] ?? '');
        $password2   = (string) ($_POST['password_confirm'] ?? '');

        $fail = function (string $message) use ($username, $email): never {
            $_SESSION['register_error'] = $message;
            $_SESSION['register_old']   = ['username' => $username, 'email' => $email];
            header('Location: /register');
            exit;
        };

        // Username: 3-50 chars, letters/digits plus . _ - (no spaces)
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,49}$/', $username)) {
            $fail('Username must be 3-50 characters using letters, numbers, dots, dashes or underscores.');
        }

        if (strcasecmp($username, $this->auth->getOwnerUsername()) === 0) {
            $fail('That username is reserved. Please choose a different one.');
        }

        // Check global registration policy & domain rules
        $sysSettingsRows = $this->app->db()->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('allow_registration', 'email_domain_whitelist', 'email_domain_blacklist', 'default_user_role')");
        $sysPolicy = [];
        foreach ($sysSettingsRows as $sr) {
            $sysPolicy[$sr['key_name']] = (string) $sr['value'];
        }

        if (($sysPolicy['allow_registration'] ?? '1') === '0') {
            $fail('New user registrations are currently disabled by the system administrator.');
        }

        // Email format verification
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fail('Please provide a valid email address.');
        }

        $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        // Whitelist check
        $whitelist = array_filter(array_map('trim', explode(',', strtolower($sysPolicy['email_domain_whitelist'] ?? ''))));
        if (!empty($whitelist)) {
            $matched = false;
            foreach ($whitelist as $allowedDomain) {
                $allowedDomain = ltrim($allowedDomain, '@');
                if ($emailDomain === $allowedDomain || str_ends_with($emailDomain, '.' . $allowedDomain)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $fail('Registration is restricted to authorized email domains only.');
            }
        }

        // Blacklist check
        $blacklist = array_filter(array_map('trim', explode(',', strtolower($sysPolicy['email_domain_blacklist'] ?? ''))));
        if (!empty($blacklist)) {
            foreach ($blacklist as $blockedDomain) {
                $blockedDomain = ltrim($blockedDomain, '@');
                if ($emailDomain === $blockedDomain || str_ends_with($emailDomain, '.' . $blockedDomain)) {
                    $fail('Registration using disposable or temporary email addresses is prohibited.');
                }
            }
        }

        // Password verification: length + mixed character classes + match
        if (strlen($password) < 8) {
            $fail('Password must be at least 8 characters long.');
        }

        if (! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            $fail('Password must contain both letters and numbers.');
        }

        if ($password !== $password2) {
            $fail('The two passwords do not match.');
        }

        // Uniqueness (username and email)
        $existing = $this->app->db()->fetchOne(
            'SELECT `username`, `email` FROM `users`
             WHERE `username` = :u OR `email` = :e LIMIT 1',
            ['u' => $username, 'e' => $email],
        );

        if ($existing !== false) {
            if (strcasecmp((string) $existing['username'], $username) === 0) {
                $fail('That username is already taken.');
            }
            $fail('An account with that email address already exists.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Email verification: when SMTP is configured (MAIL_FROM), require a
        // confirmation step; on self-hosted setups without mail config the
        // account is verified immediately.
        $mailFrom = trim((string) env('MAIL_FROM', ''));
        $token    = null;
        $verified = 'NOW()';

        if ($mailFrom !== '') {
            $token = bin2hex(random_bytes(24));
            $verified = 'NULL';
        }

        $defaultRole = in_array($sysPolicy['default_user_role'] ?? 'user', ['user', 'restricted'], true) ? $sysPolicy['default_user_role'] : 'user';
        $this->app->db()->execute(
            "INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `email_verified_at`, `verification_token`)
             VALUES (:u, :e, :h, :r, {$verified}, :t)",
            [
                'u' => $username,
                'e' => $email,
                'h' => $hash,
                'r' => $defaultRole,
                't' => $token,
            ],
        );

        $userId = (int) $this->app->db()->lastInsertId();

        if ($token !== null) {
            $appName = (string) $this->app->config('app.name', 'GitPHP');
            $appUrl  = rtrim((string) $this->app->config('app.url', ''), '/');
            $link    = "{$appUrl}/verify-email/{$token}";

            @mail(
                $email,
                "Verify your {$appName} account",
                "Hello {$username},\n\nPlease confirm your email address by opening this link:\n{$link}\n",
                "From: {$mailFrom}\r\n",
            );
        }

        // Log the new user in immediately (verified or awaiting confirmation).
        session_regenerate_id(true);
        $_SESSION['is_logged_in'] = true;
        $_SESSION['user_id']      = $userId;
        $_SESSION['user_name']    = $username;
        $_SESSION['is_owner']     = false;
        $_SESSION['login_time']   = time();

        $_SESSION['flash_success'] = $token === null
            ? "Welcome, {$username}! Your account has been created."
            : "Welcome, {$username}! A verification email has been sent to {$email}.";

        // Transactional welcome email + in-app notification (best-effort).
        try {
            (new \App\Service\Notifier($this->app))->event(
                'user.registered',
                $email,
                ['username' => $username],
                $userId,
                'Welcome to ' . $this->app->config('app.name', 'GitPHP') . '!',
                '/',
            );
        } catch (\Throwable) {
        }

        header('Location: /');
        exit;
    }

    /** GET /verify-email/{token} — confirm a user's email address. */
    public function verifyEmail(string $token): void
    {
        if ($token === '' || strlen($token) > 64) {
            header('Location: /login');
            exit;
        }

        $updated = $this->app->db()->execute(
            'UPDATE `users`
             SET `email_verified_at` = NOW(), `verification_token` = NULL
             WHERE `verification_token` = :token',
            ['token' => $token],
        )->rowCount();

        $_SESSION['flash_success'] = $updated > 0
            ? 'Email address verified. Thank you!'
            : 'This verification link is invalid or has already been used.';

        header('Location: /');
        exit;
    }

    /** GET /logout — destroy session and redirect home. */
    public function logout(): void
    {
        $this->auth->logout();
        header('Location: /');
        exit;
    }

    // ── Two-factor authentication step ───────────────────────────────

    /** GET /2fa — enter the 6-digit authenticator code. */
    public function show2fa(): void
    {
        if ($this->auth->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        if ($this->auth->pending2fa() === null) {
            header('Location: /login');
            exit;
        }

        $csrf  = $this->auth->generateCsrf();
        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        $this->app->view()->display('auth/2fa.twig', [
            'csrf_token' => $csrf,
            'error'      => $error,
            'hide_global_flash' => true,
        ]);
    }

    /** POST /2fa — verify the code and finish login. */
    public function verify2fa(): void
    {
        if ($this->auth->isLoggedIn() || $this->auth->pending2fa() === null) {
            header('Location: /login');
            exit;
        }

        if (! $this->auth->validateCsrf()) {
            $_SESSION['login_error'] = 'Invalid security token. Please try again.';
            header('Location: /2fa');
            exit;
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        $remember = ! empty($_POST['remember']);

        if (! $this->auth->complete2fa($code, $remember)) {
            $_SESSION['login_error'] = 'That code is invalid or has expired. Try again.';
            header('Location: /2fa');
            exit;
        }

        header('Location: /');
        exit;
    }

    // ── Password reset (email) ───────────────────────────────────────

    /** GET /forgot-password — request a reset link. */
    public function showForgot(): void
    {
        $csrf  = $this->auth->generateCsrf();
        $error = $_SESSION['forgot_error'] ?? null;
        unset($_SESSION['forgot_error']);
        $success = $_SESSION['forgot_success'] ?? null;
        unset($_SESSION['forgot_success']);

        $this->app->view()->display('auth/forgot.twig', [
            'csrf_token' => $csrf,
            'error'      => $error,
            'success'    => $success,
            'mail_enabled' => (new \App\Service\Mailer())->enabled(),
            'hide_global_flash' => true,
        ]);
    }

    /** POST /forgot-password — issue (or silently fake) a reset link. */
    public function forgot(): void
    {
        if (! $this->auth->validateCsrf()) {
            $_SESSION['forgot_error'] = 'Invalid security token. Please try again.';
            header('Location: /forgot-password');
            exit;
        }

        $mailer = new \App\Service\Mailer();
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $user = $identifier !== '' ? (new \App\Service\SecurityService($this->app))->findUserByIdentifier($identifier) : false;

        if ($user !== false && $mailer->enabled()) {
            (new \App\Service\SecurityService($this->app))->issuePasswordReset((int) $user['id'], (string) $user['email']);
        }

        // Always respond identically to avoid account enumeration.
        $_SESSION['forgot_success'] = 'If that account exists and mail is configured, a reset link is on its way.';
        header('Location: /forgot-password');
        exit;
    }

    /** GET /reset-password/{token} — show the new-password form. */
    public function showReset(string $token): void
    {
        $security = new \App\Service\SecurityService($this->app);
        $valid    = $security->redeemPasswordReset($token) !== false;

        $csrf  = $this->auth->generateCsrf();
        $error = $_SESSION['reset_error'] ?? null;
        unset($_SESSION['reset_error']);

        $this->app->view()->display('auth/reset.twig', [
            'csrf_token' => $csrf,
            'error'      => $error,
            'token'      => $valid ? $token : '',
            'hide_global_flash' => true,
        ]);
    }

    /** POST /reset-password/{token} — set a new password. */
    public function reset(string $token): void
    {
        if (! $this->auth->validateCsrf()) {
            $_SESSION['reset_error'] = 'Invalid security token. Please try again.';
            header('Location: /reset-password/' . rawurlencode($token));
            exit;
        }

        $security = new \App\Service\SecurityService($this->app);
        $userId   = $security->redeemPasswordReset($token);

        if ($userId === false) {
            $_SESSION['reset_error'] = 'This reset link is invalid or has expired.';
            header('Location: /forgot-password');
            exit;
        }

        $password  = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password_confirm'] ?? '');
        $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $rateLimiter = new RateLimit($this->app);

        if (! $rateLimiter->check('reset_' . $clientIp, maxAttempts: 6, decayMinutes: 15)) {
            $_SESSION['reset_error'] = 'Too many attempts. Please try again later.';
            header('Location: /reset-password/' . rawurlencode($token));
            exit;
        }

        if (strlen($password) < 8 || $password !== $password2) {
            $_SESSION['reset_error'] = 'Password must be at least 8 characters and the confirmation must match.';
            header('Location: /reset-password/' . rawurlencode($token));
            exit;
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);

        // User rows only (owner password is managed elsewhere).
        $this->app->db()->execute(
            'UPDATE `users` SET `password_hash` = :hash WHERE `id` = :id',
            ['hash' => $hash, 'id' => $userId],
        );

        $rateLimiter->reset('reset_' . $clientIp);

        $_SESSION['flash_success'] = 'Your password has been changed. Please sign in.';
        header('Location: /login');
        exit;
    }

    /** Send an authenticated user to their landing page. */
    /** GET /language/{code} */
    public function switchLanguage(string $code = 'en'): void
    {
        \App\Service\Locale::set($code);
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        if (str_contains($referer, '/lang/')) {
            $referer = '/';
        }
        header('Location: ' . $referer);
        exit;
    }

    private function redirectAfterLogin(): void
    {
        header('Location: ' . ($this->auth->isOwner() ? \App\Service\AdminSecurityService::getAdminPrefix($this->app) : '/'));
        exit;
    }
}
