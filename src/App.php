<?php

declare(strict_types=1);

namespace App;

use App\Service\Cache;
use RuntimeException;

final class App
{
    private static ?App $instance = null;

    /** @var array<string, string> */
    private array $env = [];

    /** @var array<string, mixed> */
    private array $config = [];

    private Database $db;
    private Router $router;
    private View $view;
    private Cache $cache;

    private string $basePath;

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    public static function boot(string $basePath): self
    {
        @umask(0000);
        if (self::$instance !== null) return self::$instance;

        $app = new self($basePath);
        $app->loadEnv();
        $app->registerEnvHelper();
        $app->loadConfig();
        $app->initCache();
        $app->initDatabase();
        $app->initTimezoneAndSettings();
        $app->initRouter();
        $app->tryRememberLogin();
        $app->touchActiveSession();
        $app->initView();

        self::$instance = $app;
        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) throw new RuntimeException('Application has not been booted.');
        return self::$instance;
    }

    private function loadEnv(): void
    {
        $envFile = $this->basePath . '/.env';

        if (! file_exists($envFile)) return;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) return;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if (str_starts_with($line, '#')) continue;

            // Must contain =
            if (! str_contains($line, '=')) continue;

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $this->env[$key] = $value;
            $_ENV[$key]      = $value;
            putenv("{$key}={$value}");
        }
    }

    private function registerEnvHelper(): void
    {
        // The global env() function is defined at the bottom of this file.
    }

    private function loadConfig(): void
    {
        $configPath = $this->basePath . '/config';

        foreach (glob($configPath . '/*.php') as $file) {
            $key = basename($file, '.php');
            if ($key === 'routes') continue; // routes are loaded separately
            $this->config[$key] = require $file;
        }
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->config;

        foreach ($parts as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }

        return $value;
    }

    private function initCache(): void
    {
        // Ensure the storage tree (including the Twig compiled-template
        // subdirectory) exists so a fresh/partial install never 500s.
        @umask(0000);
        foreach (['storage', 'storage/cache', 'storage/cache/twig', 'storage/downloads', 'storage/tmp', 'storage/sessions', 'storage/logs'] as $dir) {
            $path = $this->basePath($dir);
            if (! is_dir($path)) @mkdir($path, 0777, true);
            @chmod($path, 0777);
        }

        $this->cache = new Cache($this->basePath('storage/cache'));
    }

    public function cache(): Cache
    {
        return $this->cache;
    }

    private function initDatabase(): void
    {
        /** @var array<string, mixed> $dbConfig */
        $dbConfig = $this->config['database'] ?? [];
        $this->db = new Database($dbConfig);
    }

    public function db(): Database
    {
        return $this->db;
    }

    private function initTimezoneAndSettings(): void
    {
        try {
            $db = $this->db->connection();
            $stmt = $db->query('SELECT key_name, value FROM system_settings');
            $settings = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            $tz = !empty($settings['timezone']) ? (string) $settings['timezone'] : (string) $this->config('app.timezone', 'Asia/Riyadh');
            @date_default_timezone_set($tz);

            if (!empty($settings['app_name'])) {
                $this->config['app']['name'] = $settings['app_name'];
            }

            // Re-initialize Cache with dynamic database settings (Redis, Memcached, or File)
            $cacheDriver = strtolower((string) ($settings['cache_driver'] ?? 'file'));
            $this->cache = new Cache($this->basePath('storage/cache'), [
                'driver'     => $cacheDriver,
                'redis_host' => $settings['redis_host'] ?? '127.0.0.1',
                'redis_port' => (int) ($settings['redis_port'] ?? 6379),
                'redis_pass' => $settings['redis_password'] ?? '',
                'redis_db'   => (int) ($settings['redis_db'] ?? 0),
                'mem_host'   => $settings['memcached_host'] ?? '127.0.0.1',
                'mem_port'   => (int) ($settings['memcached_port'] ?? 11211),
            ]);

            // Enterprise security & Client Hints headers
            if (!headers_sent()) {
                if (($settings['enable_security_headers'] ?? '1') === '1') {
                    @header('X-Content-Type-Options: nosniff');
                    @header('X-Frame-Options: SAMEORIGIN');
                    @header('X-XSS-Protection: 1; mode=block');
                    @header('Referrer-Policy: strict-origin-when-cross-origin');
                }
                // Force browser to send exact hardware model & platform client hints
                @header('Accept-CH: Sec-CH-UA, Sec-CH-UA-Mobile, Sec-CH-UA-Platform, Sec-CH-UA-Platform-Version, Sec-CH-UA-Model, Sec-CH-UA-Arch, Sec-CH-UA-Bitness');
                @header('Permissions-Policy: ch-ua-model=(self), ch-ua-platform=(self), ch-ua-platform-version=(self)');
            }

            // HTTP Compression
            if (($settings['enable_gzip'] ?? '1') === '1' && !headers_sent() && !ini_get('zlib.output_compression')) {
                @ini_set('zlib.output_compression', 'On');
            }
        } catch (\Throwable $e) {
            @date_default_timezone_set('Asia/Riyadh');
        }
    }

    private function initRouter(): void
    {
        $this->router = new Router();

        $routesFile = $this->basePath . '/config/routes.php';
        if (file_exists($routesFile)) {
            $loader = require $routesFile;
            $loader($this->router);
        }
    }

    /**
     * Re-establish an authenticated session from a valid "remember me"
     * cookie, before the view globals are computed so the first restored
     * request already renders as the logged-in user.
     */
    private function tryRememberLogin(): void
    {
        if (empty($_COOKIE) || (isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true)) return;

        try {
            (new Auth($this))->loginFromRemember();
        } catch (\Throwable) {
            // Best-effort: never break a guest request.
        }
    }

    
    /**
     * Keep the active hardware session record in user_sessions fresh
     * and ensure every active visitor/user has a valid tracked session.
     */
    private function touchActiveSession(): void
    {
        if (empty($_SESSION['is_logged_in'])) return;

        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $token  = (string) ($_SESSION['session_token'] ?? '');
            $lastTouch = (int) ($_SESSION['_last_session_touch'] ?? 0);

            if ($token === '' || (time() - $lastTouch) > 60) {
                $_SESSION['session_token'] = (new \App\Service\SecurityService($this))->trackSession($userId);
                $_SESSION['_last_session_touch'] = time();
            }
        } catch (\Throwable) {}
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function initView(): void
    {
        // Theme-aware template root: APP_THEME selects templates/<theme>.
        // The legacy theme (bare templates/ directory) was removed; unknown
        // or missing themes now fall back to the shipped "github" theme so a
        // bad env value can never 500 the app.
        $templatesBase = $this->basePath . '/templates';
        $theme         = strtolower((string) ($this->config['app']['theme'] ?? 'github'));
        $templatesPath = $templatesBase . '/' . $theme;

        if ($theme === 'legacy' || ! is_dir($templatesPath)) {
            $templatesPath = $templatesBase . '/github';
        }

        // Session-derived globals for the multi-user auth model.
        $isLoggedIn = isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true;
        $isOwner    = $isLoggedIn && (
            ! empty($_SESSION['is_owner']) ||
            empty($_SESSION['user_id'])
        );
        $currentUser = $isLoggedIn
            ? ($_SESSION['user_name'] ?? ($isOwner ? ($this->config['app']['owner'] ?? 'admin') : null))
            : null;

        // Unread notification badge for logged-in user accounts. The table
        // may not exist yet on a brand-new install, so never fail the boot.
        $unreadNotifications = 0;
        if ($isLoggedIn && ! $isOwner && ! empty($_SESSION['user_id'])) {
            try {
                $row = $this->db->fetchOne(
                    'SELECT COUNT(*) AS `c` FROM `notifications` WHERE `user_id` = :u AND `is_read` = 0',
                    ['u' => (int) $_SESSION['user_id']],
                );
                $unreadNotifications = $row !== false ? (int) $row['c'] : 0;
            } catch (\Throwable) {
                // Schema not migrated yet — badge simply stays at 0.
            }
        }

        // Determine active avatar for header display
        $currentAvatar = null;
        if ($isLoggedIn) {
            if ($isOwner) {
                try {
                    $row = $this->db->fetchOne("SELECT value FROM system_settings WHERE key_name = 'owner_avatar_path' LIMIT 1");
                    $currentAvatar = !empty($row['value']) ? (string)$row['value'] : null;
                } catch (\Throwable) {}
            } elseif (!empty($_SESSION['user_id'])) {
                try {
                    $row = $this->db->fetchOne("SELECT avatar_path FROM users WHERE id = :id LIMIT 1", ['id' => (int)$_SESSION['user_id']]);
                    $currentAvatar = !empty($row['avatar_path']) ? (string)$row['avatar_path'] : null;
                } catch (\Throwable) {}
            }
        }

        // Check 2FA enforcement policy
        $enforce2faAlert = false;
        if ($isLoggedIn && ! $isOwner && ! empty($currentUser)) {
            try {
                $policy2fa = (string) ($this->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'enforce_2fa_policy'")['value'] ?? 'none');
                $hasTotp   = ! empty($currentUser['totp_enabled']);
                $uRole     = (string) ($currentUser['role'] ?? 'user');

                if (! $hasTotp) {
                    if ($policy2fa === 'all_users') {
                        $enforce2faAlert = true;
                    } elseif ($policy2fa === 'admins_only' && in_array($uRole, ['admin', 'staff'], true)) {
                        $enforce2faAlert = true;
                    }
                }
            } catch (\Throwable) {}
        }

        // Consume one-time flash session messages
        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $this->view = new View(
            templatesPath: $templatesPath,
            globals: [
                'app_name'              => $this->config['app']['name']     ?? 'GitPHP',
                'app_url'               => $this->config['app']['url']      ?? '',
                'owner'                 => $this->config['app']['owner']    ?? 'admin',
                'is_logged_in'          => $isLoggedIn,
                'is_owner'              => $isOwner,
                'is_admin'              => $isOwner || (($currentUser['role'] ?? '') === 'admin'),
                'is_staff'              => $isOwner || in_array($currentUser['role'] ?? '', ['admin', 'staff'], true),
                'is_auditor'            => $isOwner || in_array($currentUser['role'] ?? '', ['admin', 'staff', 'auditor'], true),
                'user_role'             => $isOwner ? 'admin' : ($currentUser['role'] ?? 'user'),
                'enforce_2fa_alert'     => $enforce2faAlert,
                'admin_prefix'          => \App\Service\AdminSecurityService::getAdminPrefix($this),
                'current_user'          => $currentUser,
                'current_avatar'        => $currentAvatar,
                'unread_notifications'  => $unreadNotifications,
                'flash_success'         => $flashSuccess,
                'flash_error'           => $flashError,
                'success'               => $flashSuccess,
                'error'                 => $flashError,
                // Session CSRF token as a plain global (not a Twig function)
                // so inline JS fetch() calls in layout can send X-CSRF-Token.
                'session_csrf_token'    => $_SESSION['csrf_token'] ?? '',
            ],
            cachePath: $this->basePath('storage/cache/twig'),
        );
    }

    public function view(): View
    {
        return $this->view;
    }

    public function basePath(string $suffix = ''): string
    {
        return $this->basePath . ($suffix ? DIRECTORY_SEPARATOR . ltrim($suffix, '/\\') : '');
    }
}

if (! function_exists('env')) {
    /** Retrieve an environment variable with an optional default. */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) return $default;

        // Cast common string representations
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
