<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\Cache;

final class AdminSettingsController
{
    private App $app;
    private Auth $auth;
    private AuditLogger $auditLogger;

    /** Admin nav counters cached for 60s to avoid 4 COUNT(*) queries per page load. */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->auth        = new Auth($app);
        $this->auditLogger = new AuditLogger($app);
    }

    /** GET /admin/settings — system settings control page */
    public function settings(): void
    {
        $this->auth->requireAdmin();

        $db = $this->app->db()->connection();
        $stmt = $db->query('SELECT key_name, value FROM system_settings');
        $raw = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $timezones = [
            'Asia/Riyadh'      => 'Asia/Riyadh (UTC+3 - Saudi Arabia)',
            'Asia/Dubai'       => 'Asia/Dubai (UTC+4 - UAE)',
            'Africa/Cairo'     => 'Africa/Cairo (UTC+3 - Egypt)',
            'Asia/Amman'       => 'Asia/Amman (UTC+3 - Jordan)',
            'Asia/Baghdad'     => 'Asia/Baghdad (UTC+3 - Iraq)',
            'Asia/Kuwait'      => 'Asia/Kuwait (UTC+3 - Kuwait)',
            'Africa/Casablanca'=> 'Africa/Casablanca (UTC+1 - Morocco)',
            'UTC'              => 'UTC (Universal Coordinated Time)',
            'Europe/London'    => 'Europe/London (UTC+1 / GMT)',
            'Europe/Paris'     => 'Europe/Paris (UTC+2)',
            'Europe/Berlin'    => 'Europe/Berlin (UTC+2)',
            'America/New_York' => 'America/New_York (UTC-4 / EDT)',
            'America/Los_Angeles' => 'America/Los_Angeles (UTC-7 / PDT)',
            'Asia/Tokyo'       => 'Asia/Tokyo (UTC+9)',
        ];

        $settings = [
            'app_name'                => $raw['app_name'] ?? $this->app->config('app.name', 'GitPHP'),
            'timezone'                => $raw['timezone'] ?? $this->app->config('app.timezone', 'Asia/Riyadh'),
            'default_branch'          => $raw['default_branch'] ?? 'main',
            'default_visibility'      => $raw['default_visibility'] ?? 'public',
            'allow_registration'      => ($raw['allow_registration'] ?? '1') === '1',
            'require_email_verify'    => ($raw['require_email_verify'] ?? '0') === '1',
            'max_upload_mb'           => (int) ($raw['max_upload_mb'] ?? 50),
            'short_links_domain'      => $raw['short_links_domain'] ?? $this->app->config('app.url', 'http://localhost:8080'),
            'git_receive_pack'        => ($raw['git_receive_pack'] ?? '1') === '1',
            'session_lifetime'        => (int) ($raw['session_lifetime'] ?? 24),
            // Performance & Caching
            'cache_driver'            => $raw['cache_driver'] ?? 'file',
            'redis_host'              => $raw['redis_host'] ?? '127.0.0.1',
            'redis_port'              => (int) ($raw['redis_port'] ?? 6379),
            'redis_password'          => $raw['redis_password'] ?? '',
            'redis_db'                => (int) ($raw['redis_db'] ?? 0),
            'memcached_host'          => $raw['memcached_host'] ?? '127.0.0.1',
            'memcached_port'          => (int) ($raw['memcached_port'] ?? 11211),
            'enable_gzip'             => ($raw['enable_gzip'] ?? '1') === '1',
            'enable_security_headers' => ($raw['enable_security_headers'] ?? '1') === '1',
            'session_driver'          => $raw['session_driver'] ?? 'file',
            'rate_limit_enabled'      => ($raw['rate_limit_enabled'] ?? '1') === '1',
            'rate_limit_per_minute'   => (int) ($raw['rate_limit_per_minute'] ?? 120),
        ];

        $cache       = $this->app->cache();
        $diagnostics = $cache->remember('admin:diagnostics', 30, fn() => Cache::getDiagnostics());
        $activeTab   = (string) ($_GET['tab'] ?? 'general');

        $this->app->view()->display('admin/settings.twig', [
            'settings'     => $settings,
            'timezones'    => $timezones,
            'diagnostics'  => $diagnostics,
            'active_tab'   => $activeTab,
            'admin_prefix' => $this->auth->adminPrefix(),
            'nav_counts'   => $this->getNavCounts(),
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /admin/settings — update system settings */
    public function updateSettings(): void
    {
        $this->auth->requireAdmin();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
            header("Location: {$ap}/settings");
            exit;
        }

        $appName           = trim((string) ($_POST['app_name'] ?? 'GitPHP'));
        $timezone          = trim((string) ($_POST['timezone'] ?? 'Asia/Riyadh'));
        $defaultBranch     = trim((string) ($_POST['default_branch'] ?? 'main'));
        $defaultVisibility = in_array($_POST['default_visibility'] ?? '', ['public', 'private'], true) ? $_POST['default_visibility'] : 'public';
        $allowRegistration = !empty($_POST['allow_registration']) ? '1' : '0';
        $requireEmail      = !empty($_POST['require_email_verify']) ? '1' : '0';
        $gitReceivePack    = !empty($_POST['git_receive_pack']) ? '1' : '0';
        $maxUploadMb       = max(1, (int) ($_POST['max_upload_mb'] ?? 50));
        $sessionLifetime   = max(1, (int) ($_POST['session_lifetime'] ?? 24));
        $shortLinksDomain  = trim((string) ($_POST['short_links_domain'] ?? ''));

        // Performance & Caching Settings
        $cacheDriver       = in_array($_POST['cache_driver'] ?? '', ['file', 'redis', 'memcached', 'array'], true) ? $_POST['cache_driver'] : 'file';
        $redisHost         = trim((string) ($_POST['redis_host'] ?? '127.0.0.1'));
        $redisPort         = max(1, (int) ($_POST['redis_port'] ?? 6379));
        $redisPass         = trim((string) ($_POST['redis_password'] ?? ''));
        $redisDb           = max(0, (int) ($_POST['redis_db'] ?? 0));
        $memHost           = trim((string) ($_POST['memcached_host'] ?? '127.0.0.1'));
        $memPort           = max(1, (int) ($_POST['memcached_port'] ?? 11211));
        $enableGzip        = !empty($_POST['enable_gzip']) ? '1' : '0';
        $enableSecHeaders  = !empty($_POST['enable_security_headers']) ? '1' : '0';
        $sessionDriver     = in_array($_POST['session_driver'] ?? '', ['file', 'redis', 'memcached'], true) ? $_POST['session_driver'] : 'file';
        $rateLimitEnabled  = !empty($_POST['rate_limit_enabled']) ? '1' : '0';
        $rateLimitPerMin   = max(10, (int) ($_POST['rate_limit_per_minute'] ?? 120));

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            INSERT INTO system_settings (key_name, value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ');

        $toSave = [
            'app_name'                => $appName,
            'timezone'                => $timezone,
            'default_branch'          => $defaultBranch,
            'default_visibility'      => $defaultVisibility,
            'allow_registration'      => $allowRegistration,
            'require_email_verify'    => $requireEmail,
            'git_receive_pack'        => $gitReceivePack,
            'max_upload_mb'           => (string) $maxUploadMb,
            'session_lifetime'        => (string) $sessionLifetime,
            'short_links_domain'      => $shortLinksDomain,
            'cache_driver'            => $cacheDriver,
            'redis_host'              => $redisHost,
            'redis_port'              => (string) $redisPort,
            'redis_password'          => $redisPass,
            'redis_db'                => (string) $redisDb,
            'memcached_host'          => $memHost,
            'memcached_port'          => (string) $memPort,
            'enable_gzip'             => $enableGzip,
            'enable_security_headers' => $enableSecHeaders,
            'session_driver'          => $sessionDriver,
            'rate_limit_enabled'      => $rateLimitEnabled,
            'rate_limit_per_minute'   => (string) $rateLimitPerMin,
        ];

        foreach ($toSave as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Apply timezone immediately
        @date_default_timezone_set($timezone);

        $this->auditLogger->log('settings.update', null, "Updated system settings (Driver: {$cacheDriver}, Timezone: {$timezone})");

        // Invalidate settings & diagnostics cache
        $cache = $this->app->cache();
        $cache->forget('admin:diagnostics');
        $cache->forget('admin:nav_counts');

        $tab = trim((string) ($_POST['redirect_tab'] ?? 'general'));
        $_SESSION['flash_success'] = 'System settings updated successfully.';
        $ap = $this->auth->adminPrefix();
        header("Location: {$ap}/settings?tab=" . urlencode($tab));
        exit;
    }

    /** AJAX API endpoint: Test Redis/Memcached connection */
    public function testCache(): void
    {
        $this->auth->requireOwner();
        header('Content-Type: application/json');

        $driver = trim((string) ($_POST['driver'] ?? 'redis'));
        if ($driver === 'redis') {
            $host = trim((string) ($_POST['host'] ?? '127.0.0.1'));
            $port = max(1, (int) ($_POST['port'] ?? 6379));
            $pass = trim((string) ($_POST['pass'] ?? ''));
            $res = Cache::testRedis($host, $port, $pass);
            echo json_encode($res);
            exit;
        }

        if ($driver === 'memcached') {
            $host = trim((string) ($_POST['host'] ?? '127.0.0.1'));
            $port = max(1, (int) ($_POST['port'] ?? 11211));
            $res = Cache::testMemcached($host, $port);
            echo json_encode($res);
            exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Unknown cache driver specified']);
        exit;
    }

    /** GET /admin/audit-logs — security audit trail */
    public function auditLogs(): void
    {
        $this->auth->requireAuditor();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;

        $db = $this->app->db()->connection();
        $countStmt = $db->query('SELECT COUNT(*) FROM audit_logs');
        $totalLogs = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare('
            SELECT a.*, r.name AS repo_name, r.slug AS repo_slug
            FROM audit_logs a
            LEFT JOIN repositories r ON a.repo_id = r.id
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->bindValue(1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $this->app->view()->display('admin/audit-logs.twig', [
            'logs'        => $logs,
            'total_logs'  => $totalLogs,
            'page'        => $page,
            'total_pages' => max(1, (int) ceil($totalLogs / $perPage)),
            'nav_counts'  => $this->getNavCounts(),
        ]);
    }
    /** GET /{$ap}/limits — Global Limits & Quotas Policy Dashboard */
    public function limits(): void
    {
        $this->auth->requireAdmin();

        $raw = $this->app->db()->fetchAll('SELECT key_name, value FROM system_settings');
        $settings = [];
        foreach ($raw as $row) {
            $settings[$row['key_name']] = $row['value'];
        }

        $limits = [
            // Repositories Policy
            'max_repos_per_user'              => (int) ($settings['max_repos_per_user'] ?? 50),
            'max_private_repos_per_user'      => (int) ($settings['max_private_repos_per_user'] ?? 10),
            'allow_public_repo_creation'      => ($settings['allow_public_repo_creation'] ?? '1') === '1',
            'allow_private_repo_creation'     => ($settings['allow_private_repo_creation'] ?? '1') === '1',
            'allow_repo_deletion'             => ($settings['allow_repo_deletion'] ?? '1') === '1',
            'allow_repo_forking'              => ($settings['allow_repo_forking'] ?? '1') === '1',
            'max_collaborators_per_repo'      => (int) ($settings['max_collaborators_per_repo'] ?? 20),

            // Storage & Quotas
            'user_storage_quota_mb'           => (int) ($settings['user_storage_quota_mb'] ?? 5120),
            'repo_max_size_mb'                => (int) ($settings['repo_max_size_mb'] ?? 2048),
            'max_upload_mb'                   => (int) ($settings['max_upload_mb'] ?? 100),
            'max_release_asset_mb'            => (int) ($settings['max_release_asset_mb'] ?? 500),
            'max_avatar_size_kb'              => (int) ($settings['max_avatar_size_kb'] ?? 2048),

            // User & Security Quotas
            'max_active_sessions_per_user'    => (int) ($settings['max_active_sessions_per_user'] ?? 5),
            'session_idle_timeout_hours'      => (int) ($settings['session_idle_timeout_hours'] ?? 72),
            'max_pats_per_user'               => (int) ($settings['max_pats_per_user'] ?? 20),
            'max_ssh_keys_per_user'           => (int) ($settings['max_ssh_keys_per_user'] ?? 10),
            'default_user_role'               => (string) ($settings['default_user_role'] ?? 'user'),
            'email_domain_whitelist'          => (string) ($settings['email_domain_whitelist'] ?? ''),
            'email_domain_blacklist'          => (string) ($settings['email_domain_blacklist'] ?? ''),
            'enforce_2fa_policy'              => (string) ($settings['enforce_2fa_policy'] ?? 'none'),

            // Network & Rate Limits
            'git_push_rate_limit_per_minute'  => (int) ($settings['git_push_rate_limit_per_minute'] ?? 30),
            'api_rate_limit_per_hour'         => (int) ($settings['api_rate_limit_per_hour'] ?? 5000),
            'webhook_max_deliveries_per_hour' => (int) ($settings['webhook_max_deliveries_per_hour'] ?? 1000),
            'max_webhooks_per_repo'           => (int) ($settings['max_webhooks_per_repo'] ?? 10),
        ];

        $this->app->view()->display('admin/limits.twig', [
            'limits'       => $limits,
            'admin_prefix' => $this->auth->adminPrefix(),
            'nav_counts'   => $this->getNavCounts(),
            'csrf_token'   => $this->auth->generateCsrf(),
            'flash_success'=> $_SESSION['flash_success'] ?? null,
            'flash_error'  => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/limits — Update Global Limits & Quotas Policy */
    public function updateLimits(): void
    {
        $this->auth->requireAdmin();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid or expired security token.';
            header("Location: {$ap}/limits");
            exit;
        }

        $toSave = [
            'max_repos_per_user'              => (string) max(0, (int) ($_POST['max_repos_per_user'] ?? 50)),
            'max_private_repos_per_user'      => (string) max(0, (int) ($_POST['max_private_repos_per_user'] ?? 10)),
            'allow_public_repo_creation'      => !empty($_POST['allow_public_repo_creation']) ? '1' : '0',
            'allow_private_repo_creation'     => !empty($_POST['allow_private_repo_creation']) ? '1' : '0',
            'allow_repo_deletion'             => !empty($_POST['allow_repo_deletion']) ? '1' : '0',
            'allow_repo_forking'              => !empty($_POST['allow_repo_forking']) ? '1' : '0',
            'max_collaborators_per_repo'      => (string) max(0, (int) ($_POST['max_collaborators_per_repo'] ?? 20)),

            'user_storage_quota_mb'           => (string) max(0, (int) ($_POST['user_storage_quota_mb'] ?? 5120)),
            'repo_max_size_mb'                => (string) max(0, (int) ($_POST['repo_max_size_mb'] ?? 2048)),
            'max_upload_mb'                   => (string) max(1, (int) ($_POST['max_upload_mb'] ?? 100)),
            'max_release_asset_mb'            => (string) max(1, (int) ($_POST['max_release_asset_mb'] ?? 500)),
            'max_avatar_size_kb'              => (string) max(100, (int) ($_POST['max_avatar_size_kb'] ?? 2048)),

            'max_active_sessions_per_user'    => (string) max(0, (int) ($_POST['max_active_sessions_per_user'] ?? 5)),
            'session_idle_timeout_hours'      => (string) max(1, (int) ($_POST['session_idle_timeout_hours'] ?? 72)),
            'max_pats_per_user'               => (string) max(0, (int) ($_POST['max_pats_per_user'] ?? 20)),
            'max_ssh_keys_per_user'           => (string) max(0, (int) ($_POST['max_ssh_keys_per_user'] ?? 10)),
            'default_user_role'               => in_array($_POST['default_user_role'] ?? '', ['user', 'restricted'], true) ? $_POST['default_user_role'] : 'user',
            'email_domain_whitelist'          => trim((string) ($_POST['email_domain_whitelist'] ?? '')),
            'email_domain_blacklist'          => trim((string) ($_POST['email_domain_blacklist'] ?? '')),
            'enforce_2fa_policy'              => in_array($_POST['enforce_2fa_policy'] ?? '', ['none', 'admins_only', 'all_users'], true) ? $_POST['enforce_2fa_policy'] : 'none',

            'git_push_rate_limit_per_minute'  => (string) max(1, (int) ($_POST['git_push_rate_limit_per_minute'] ?? 30)),
            'api_rate_limit_per_hour'         => (string) max(10, (int) ($_POST['api_rate_limit_per_hour'] ?? 5000)),
            'webhook_max_deliveries_per_hour' => (string) max(10, (int) ($_POST['webhook_max_deliveries_per_hour'] ?? 1000)),
            'max_webhooks_per_repo'           => (string) max(0, (int) ($_POST['max_webhooks_per_repo'] ?? 10)),
        ];

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            INSERT INTO system_settings (key_name, value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ');

        foreach ($toSave as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // Record audit
        try {
            $this->app->db()->execute(
                'INSERT INTO audit_logs (user_id, user_name, action, details, ip_address, created_at)
                 VALUES (:uid, :uname, :act, :det, :ip, NOW())',
                [
                    'uid'   => (int) ($_SESSION['user']['id'] ?? 1),
                    'uname' => $_SESSION['user']['username'] ?? 'admin',
                    'act'   => 'policy.limits_updated',
                    'det'   => 'Updated global platform quotas and security restrictions',
                    'ip'    => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ]
            );
        } catch (\Throwable) {}

        // Invalidate cache
        $this->app->cache()->forget('admin:nav_counts');

        $_SESSION['flash_success'] = 'Global limits, quotas, and security restrictions updated successfully.';
        header("Location: {$ap}/limits");
        exit;
    }

}
