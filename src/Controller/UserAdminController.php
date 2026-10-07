<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\DeviceDetector;
use App\Service\GeoIpService;

final class UserAdminController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    /** GET /{$ap}/users */
    public function index(): void
    {
        $this->auth->requireStaff();

        $query = trim((string) ($_GET['q'] ?? ''));
        $roleFilter = trim((string) ($_GET['role'] ?? 'all'));
        $statusFilter = trim((string) ($_GET['status'] ?? 'all'));

        $sql = 'SELECT u.*, 
                (SELECT COUNT(*) FROM repositories r WHERE r.owner_user_id = u.id) AS repo_count,
                (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id) AS active_sessions_count,
                (SELECT MAX(s.last_activity) FROM user_sessions s WHERE s.user_id = u.id) AS last_seen
                FROM users u WHERE 1=1';
        $params = [];

        if ($query !== '') {
            $sql .= ' AND (u.username LIKE :q OR u.email LIKE :q OR u.display_name LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        if ($roleFilter === 'admin') {
            $sql .= ' AND u.role = "admin"';
        } elseif ($roleFilter === 'user') {
            $sql .= ' AND u.role = "user"';
        }

        if ($statusFilter === 'suspended') {
            $sql .= ' AND u.is_suspended = 1';
        } elseif ($statusFilter === 'active') {
            $sql .= ' AND u.is_suspended = 0';
        }

        $sql .= ' ORDER BY u.created_at DESC';
        $users = $this->app->db()->fetchAll($sql, $params);

        // Calculate summary counts with cache
        $cache = $this->app->cache();
        $counts = $cache->remember('admin:users:summary', 30, function (): array {
            return [
                'total'     => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM users'),
                'admins'    => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM users WHERE role = "admin"'),
                'suspended' => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM users WHERE is_suspended = 1'),
                'active_24h'=> (int) $this->app->db()->fetchValue('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND user_id > 0'),
            ];
        });

        $this->app->view()->display('admin/users/index.twig', [
            'csrf_token'      => $this->auth->generateCsrf(),
            'users'           => $users,
            'search_query'    => $query,
            'role_filter'     => $roleFilter,
            'status_filter'   => $statusFilter,
            'total_users'     => $counts['total'],
            'total_admins'    => $counts['admins'],
            'total_suspended' => $counts['suspended'],
            'active_24h'      => $counts['active_24h'],
            'nav_counts'      => $this->getNavCounts(),
            'flash_success'   => $_SESSION['flash_success'] ?? null,
            'flash_error'     => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/users/create */
    public function create(): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim(strtolower((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($username === '' || ! preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $username)) {
            $_SESSION['flash_error'] = 'Username must be 3-30 characters alphanumeric.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        if (strlen($password) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        // Check if username or email exists
        $exists = $this->app->db()->fetchOne(
            'SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1',
            ['u' => $username, 'e' => $email]
        );
        if ($exists) {
            $_SESSION['flash_error'] = 'Username or email is already registered.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->app->db()->execute(
            'INSERT INTO users (username, display_name, email, password_hash, role, email_verified_at, created_at, updated_at) 
             VALUES (:u, :u, :e, :p, :r, NOW(), NOW(), NOW())',
            [
                'u' => $username,
                'e' => $email,
                'p' => $hash,
                'r' => $role,
            ]
        );

        $this->bustCache();

        $_SESSION['flash_success'] = "User @{$username} created successfully.";
        header('Location: ' . $this->adminPrefix() . '/users');
        exit;
    }

    /** GET /{$ap}/users/{id} */
    public function view(int $id): void
    {
        $this->auth->requireOwner();

        $user = $this->app->db()->fetchOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
        if (! $user) {
            $_SESSION['flash_error'] = 'User not found.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        $activeTab = trim((string) ($_GET['tab'] ?? 'info'));
        if (! in_array($activeTab, ['info', 'repos', 'sessions', 'activity', 'options'], true)) {
            $activeTab = 'info';
        }

        // Repositories owned by user
        $repos = $this->app->db()->fetchAll(
            'SELECT * FROM repositories WHERE owner_user_id = :id ORDER BY updated_at DESC',
            ['id' => $id]
        );

        // Active sessions for user (enriched with device detection and GeoIP)
        $ownerName = (string) $this->app->config('app.owner', 'admin');
        $isAdminTarget = ($targetUser['username'] === $ownerName || $targetUser['role'] === 'admin');
        $rawSessions = $this->app->db()->fetchAll(
            'SELECT * FROM user_sessions WHERE user_id = :id OR (user_id = 0 AND :is_admin = 1) ORDER BY last_activity DESC',
            ['id' => $id, 'is_admin' => $isAdminTarget ? 1 : 0]
        );
        $sessions = [];
        foreach ($rawSessions as $s) {
            $brand = (string) ($s['device_brand'] ?? '');
            $model = (string) ($s['device_model'] ?? '');
            $code  = (string) ($s['device_code'] ?? '');
            $type  = (string) ($s['device_type'] ?? 'desktop');
            $os    = (string) ($s['os_name'] ?? '');
            $osVer = (string) ($s['os_version'] ?? '');
            $br    = (string) ($s['browser_name'] ?? '');
            $brVer = (string) ($s['browser_version'] ?? '');

            if ($model === '' && !empty($s['user_agent'])) {
                $detected = DeviceDetector::detect((string) $s['user_agent']);
                $brand = $detected['brand'];
                $model = $detected['model'];
                $code  = $detected['code'];
                $type  = $detected['type'];
                $os    = $detected['os_name'];
                $osVer = $detected['os_version'];
                $br    = $detected['browser_name'];
                $brVer = $detected['browser_version'];
            }

            $ip = (string) ($s['ip_address'] ?? '127.0.0.1');
            $geo = GeoIpService::lookup($ip);

            $sessions[] = [
                'id'              => (int) $s['id'],
                'token'           => (string) $s['token'],
                'ip_address'      => $ip,
                'device_brand'    => $brand ?: 'Generic',
                'device_model'    => $model ?: 'Personal Device',
                'device_code'     => $code ?: 'N/A',
                'device_type'     => $type ?: 'desktop',
                'os_name'         => $os ?: 'Unknown OS',
                'os_version'      => $osVer,
                'browser_name'    => $br ?: 'Web Browser',
                'browser_version' => $brVer,
                'created_at'      => (string) $s['created_at'],
                'last_activity'   => (string) $s['last_activity'],
                'country'         => $geo['country'],
                'country_code'    => $geo['country_code'],
                'continent'       => $geo['continent'],
                'as_name'         => $geo['as_name'],
                'as_domain'       => $geo['as_domain'],
                'flag_emoji'      => $geo['flag_emoji'],
            ];
        }

        // Determine Last Active Device & Geo info
        $lastSession = $sessions[0] ?? null;
        if (! $lastSession) {
            // Fallback to latest audit log IP if no active session
            $latestAudit = $this->app->db()->fetchOne(
                'SELECT * FROM audit_logs WHERE user_id = :id OR user_name = :u ORDER BY created_at DESC LIMIT 1',
                ['id' => $id, 'u' => $user['username']]
            );
            if ($latestAudit && !empty($latestAudit['ip_address'])) {
                $geo = GeoIpService::lookup((string) $latestAudit['ip_address']);
                $lastSession = [
                    'ip_address'      => (string) $latestAudit['ip_address'],
                    'device_brand'    => 'Unknown Device',
                    'device_model'    => 'Web Session',
                    'device_code'     => 'N/A',
                    'device_type'     => 'desktop',
                    'os_name'         => 'N/A',
                    'os_version'      => '',
                    'browser_name'    => 'N/A',
                    'browser_version' => '',
                    'created_at'      => (string) $latestAudit['created_at'],
                    'last_activity'   => (string) $latestAudit['created_at'],
                    'country'         => $geo['country'],
                    'country_code'    => $geo['country_code'],
                    'continent'       => $geo['continent'],
                    'as_name'         => $geo['as_name'],
                    'as_domain'       => $geo['as_domain'],
                    'flag_emoji'      => $geo['flag_emoji'],
                ];
            }
        }

        // Tokens for user
        $tokens = $this->app->db()->fetchAll(
            'SELECT * FROM api_tokens WHERE user_id = :id ORDER BY created_at DESC',
            ['id' => $id]
        );

        // Activity & Audit Logs for user
        $activities = $this->app->db()->fetchAll(
            'SELECT * FROM audit_logs WHERE user_id = :id OR user_name = :u ORDER BY created_at DESC LIMIT 50',
            ['id' => $id, 'u' => $user['username']]
        );

        $this->app->view()->display('admin/users/view.twig', [
            'csrf_token'    => $this->auth->generateCsrf(),
            'target_user'   => $user,
            'active_tab'    => $activeTab,
            'repos'         => $repos,
            'sessions'      => $sessions,
            'tokens'        => $tokens,
            'activities'    => $activities,
            'last_session'  => $lastSession,
            'nav_counts'    => $this->getNavCounts(),
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error'   => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/users/{id}/role */
    public function updateRole(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . "/users/{$id}?tab=options");
            exit;
        }

        $allowedRoles = ['admin', 'staff', 'auditor', 'user', 'restricted', 'bot'];
        $role = trim((string) ($_POST['role'] ?? 'user'));
        if (! in_array($role, $allowedRoles, true)) {
            $role = 'user';
        }

        $this->app->db()->execute(
            'UPDATE users SET role = :r, updated_at = NOW() WHERE id = :id',
            ['r' => $role, 'id' => $id]
        );

        // Record audit event
        $currUser = $_SESSION['user']['username'] ?? 'admin';
        try {
            $this->app->db()->execute(
                'INSERT INTO audit_logs (user_id, user_name, action, details, ip_address, created_at)
                 VALUES (:uid, :uname, :act, :det, :ip, NOW())',
                [
                    'uid'   => (int) ($_SESSION['user']['id'] ?? 1),
                    'uname' => $currUser,
                    'act'   => 'user.role_changed',
                    'det'   => "Changed role for User #{$id} to '{$role}'",
                    'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]
            );
        } catch (\Throwable) {}

        $this->bustCache();

        $_SESSION['flash_success'] = "User role successfully changed to " . ucfirst($role) . ".";
        header('Location: ' . $this->adminPrefix() . "/users/{$id}?tab=options");
        exit;
    }

    /** POST /{$ap}/users/{id}/status */
    public function updateStatus(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . "/users/{$id}?tab=options");
            exit;
        }

        $action = trim((string) ($_POST['action'] ?? 'apply'));

        if ($action === 'reinstate') {
            $this->app->db()->execute(
                'UPDATE users SET is_suspended = 0, suspension_type = "none", status_reason = NULL, suspension_until = NULL, updated_at = NOW() WHERE id = :id',
                ['id' => $id]
            );

            // Audit
            try {
                $this->app->db()->execute(
                    'INSERT INTO audit_logs (user_id, user_name, action, details, ip_address, created_at)
                     VALUES (:uid, :uname, :act, :det, :ip, NOW())',
                    [
                        'uid'   => (int) ($_SESSION['user']['id'] ?? 1),
                        'uname' => $_SESSION['user']['username'] ?? 'admin',
                        'act'   => 'user.reinstated',
                        'det'   => "Reinstated User #{$id} to active standing",
                        'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ]
                );
            } catch (\Throwable) {}

            $this->bustCache();
            $_SESSION['flash_success'] = 'Account reinstated to active standing successfully.';
            header('Location: ' . $this->adminPrefix() . "/users/{$id}?tab=options");
            exit;
        }

        // Apply moderation / suspension
        $suspensionType = trim((string) ($_POST['suspension_type'] ?? 'suspended'));
        $allowedTypes = ['suspended', 'locked', 'read_only', 'shadow_banned'];
        if (! in_array($suspensionType, $allowedTypes, true)) {
            $suspensionType = 'suspended';
        }

        $duration = trim((string) ($_POST['duration'] ?? 'permanent'));
        $until = null;

        if ($duration === '24h') {
            $until = date('Y-m-d H:i:s', strtotime('+24 hours'));
        } elseif ($duration === '7d') {
            $until = date('Y-m-d H:i:s', strtotime('+7 days'));
        } elseif ($duration === '30d') {
            $until = date('Y-m-d H:i:s', strtotime('+30 days'));
        } elseif ($duration === 'custom' && !empty($_POST['custom_until'])) {
            $parsed = strtotime((string) $_POST['custom_until']);
            if ($parsed && $parsed > time()) {
                $until = date('Y-m-d H:i:s', $parsed);
            }
        }

        $category = trim((string) ($_POST['category'] ?? ''));
        $reasonText = trim((string) ($_POST['reason'] ?? ''));
        $fullReason = $category ? ($category . ($reasonText ? ' - ' . $reasonText : '')) : $reasonText;
        $internalNote = trim((string) ($_POST['internal_note'] ?? ''));

        $this->app->db()->execute(
            'UPDATE users SET is_suspended = 1, suspension_type = :st, status_reason = :r, suspension_until = :u, internal_note = :n, updated_at = NOW() WHERE id = :id',
            [
                'st' => $suspensionType,
                'r'  => $fullReason ?: null,
                'u'  => $until,
                'n'  => $internalNote ?: null,
                'id' => $id,
            ]
        );

        // Revoke active sessions if requested or on full suspension
        if (!empty($_POST['revoke_sessions']) || in_array($suspensionType, ['suspended', 'locked'], true)) {
            $this->app->db()->execute('DELETE FROM user_sessions WHERE user_id = :id', ['id' => $id]);
        }

        // Revoke tokens if requested
        if (!empty($_POST['revoke_tokens'])) {
            try {
                $this->app->db()->execute('DELETE FROM personal_access_tokens WHERE user_id = :id', ['id' => $id]);
            } catch (\Throwable) {}
        }

        // Record audit event
        try {
            $this->app->db()->execute(
                'INSERT INTO audit_logs (user_id, user_name, action, details, ip_address, created_at)
                 VALUES (:uid, :uname, :act, :det, :ip, NOW())',
                [
                    'uid'   => (int) ($_SESSION['user']['id'] ?? 1),
                    'uname' => $_SESSION['user']['username'] ?? 'admin',
                    'act'   => 'user.moderation_applied',
                    'det'   => "Applied '{$suspensionType}' moderation to User #{$id} (Duration: {$duration})",
                    'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]
            );
        } catch (\Throwable) {}

        $this->bustCache();

        $_SESSION['flash_success'] = "Account moderation ('" . ucfirst($suspensionType) . "') applied successfully.";
        header('Location: ' . $this->adminPrefix() . "/users/{$id}?tab=options");
        exit;
    }

    /** POST /{$ap}/users/{id}/password */
    public function resetPassword(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . "/users/{$id}");
            exit;
        }

        $newPass = (string) ($_POST['new_password'] ?? '');
        if (strlen($newPass) < 8) {
            $_SESSION['flash_error'] = 'Password must be at least 8 characters.';
            header('Location: ' . $this->adminPrefix() . "/users/{$id}");
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $this->app->db()->execute(
            'UPDATE users SET password_hash = :p, updated_at = NOW() WHERE id = :id',
            ['p' => $hash, 'id' => $id]
        );

        // Terminate old sessions so user must log in with new password
        $this->app->db()->execute('DELETE FROM user_sessions WHERE user_id = :id', ['id' => $id]);

        $this->bustCache();

        $_SESSION['flash_success'] = 'Password updated successfully. User sessions invalidated.';
        header('Location: ' . $this->adminPrefix() . "/users/{$id}");
        exit;
    }

    /** POST /{$ap}/users/{id}/delete */
    public function delete(int $id): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/users');
            exit;
        }

        $user = $this->app->db()->fetchOne('SELECT username FROM users WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($user) {
            $uName = $user['username'];
            $this->app->db()->execute('DELETE FROM user_sessions WHERE user_id = :id', ['id' => $id]);
            $this->app->db()->execute('DELETE FROM api_tokens WHERE user_id = :id', ['id' => $id]);
            $this->app->db()->execute('DELETE FROM repositories WHERE owner_user_id = :id', ['id' => $id]);
            $this->app->db()->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);

            $this->bustCache();

            $_SESSION['flash_success'] = "User @{$uName} deleted permanently.";
        }

        header('Location: ' . $this->adminPrefix() . '/users');
        exit;
    }

    private function bustCache(): void
    {
        $this->app->cache()->forget('admin:users:summary');
        $this->app->cache()->forget('admin:nav_counts');
    }

    private function adminPrefix(): string
    {
        $hash = substr(hash('sha256', session_id() . 'gitphp_admin_sec_2026'), 0, 12);
        return "/cp_{$hash}";
    }
}
