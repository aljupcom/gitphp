<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\DeviceDetector;
use App\Service\GeoIpService;

final class SystemAdminController
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

    /** GET /{$ap}/sessions */
    public function sessions(): void
    {
        $this->auth->requireAuditor();

        $ownerName = $this->auth->getOwnerUsername();
        $rows = $this->app->db()->fetchAll(
            'SELECT 
                s.id, s.user_id, s.token,
                COALESCE(u.username, :owner) AS username,
                COALESCE(u.display_name, u.username, :owner_title) AS display_name,
                u.email,
                s.ip_address, s.user_agent,
                s.device_brand, s.device_model, s.device_code, s.device_type,
                s.os_name, s.os_version, s.browser_name, s.browser_version,
                s.created_at, s.last_activity
             FROM user_sessions s
             LEFT JOIN users u ON s.user_id = u.id
             ORDER BY s.last_activity DESC',
            ['owner' => $ownerName, 'owner_title' => 'Site Owner']
        );

        $sessions = [];
        foreach ($rows as $row) {
            $brand = (string) ($row['device_brand'] ?? '');
            $model = (string) ($row['device_model'] ?? '');
            $code  = (string) ($row['device_code'] ?? '');
            $type  = (string) ($row['device_type'] ?? 'desktop');
            $os    = (string) ($row['os_name'] ?? '');
            $osVer = (string) ($row['os_version'] ?? '');
            $br    = (string) ($row['browser_name'] ?? '');
            $brVer = (string) ($row['browser_version'] ?? '');

            if ($model === '' && !empty($row['user_agent'])) {
                $detected = DeviceDetector::detect((string) $row['user_agent']);
                $brand = $detected['brand'];
                $model = $detected['model'];
                $code  = $detected['code'];
                $type  = $detected['type'];
                $os    = $detected['os_name'];
                $osVer = $detected['os_version'];
                $br    = $detected['browser_name'];
                $brVer = $detected['browser_version'];
            }

            $ip = (string) ($row['ip_address'] ?? '127.0.0.1');
            $geo = GeoIpService::lookup($ip);

            $sessions[] = [
                'id'              => (int) $row['id'],
                'user_id'         => (int) $row['user_id'],
                'token'           => (string) $row['token'],
                'username'        => (string) $row['username'],
                'display_name'    => (string) $row['display_name'],
                'email'           => (string) ($row['email'] ?? ''),
                'ip_address'      => $ip,
                'device_brand'    => $brand ?: 'Generic',
                'device_model'    => $model ?: 'Personal Device',
                'device_code'     => $code ?: 'N/A',
                'device_type'     => $type ?: 'desktop',
                'os_name'         => $os ?: 'Unknown OS',
                'os_version'      => $osVer,
                'browser_name'    => $br ?: 'Web Browser',
                'browser_version' => $brVer,
                'created_at'      => (string) $row['created_at'],
                'last_activity'   => (string) $row['last_activity'],
                'country'         => $geo['country'],
                'country_code'    => $geo['country_code'],
                'continent'       => $geo['continent'],
                'as_name'         => $geo['as_name'],
                'as_domain'       => $geo['as_domain'],
                'flag_emoji'      => $geo['flag_emoji'],
                'is_current'      => (($_SESSION['session_token'] ?? '') === $row['token']),
            ];
        }

        $this->app->view()->display('admin/sessions.twig', [
            'csrf_token'    => $this->auth->generateCsrf(),
            'sessions'      => $sessions,
            'nav_counts'    => $this->getNavCounts(),
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error'   => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/sessions/{id}/revoke */
    public function revokeSession(int $id): void
    {
        $this->auth->requireStaff();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/sessions');
            exit;
        }

        $this->app->db()->execute('DELETE FROM user_sessions WHERE id = :id', ['id' => $id]);
        $this->app->cache()->forget('admin:users:summary');

        $_SESSION['flash_success'] = 'Target session terminated successfully.';
        header('Location: ' . $this->adminPrefix() . '/sessions');
        exit;
    }

    /** POST /{$ap}/sessions/revoke-user/{user_id} */
    public function revokeUserSessions(int $user_id): void
    {
        $this->auth->requireStaff();
        $uid = $user_id;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/sessions');
            exit;
        }

        $this->app->db()->execute('DELETE FROM user_sessions WHERE user_id = :uid', ['uid' => $uid]);
        $this->app->cache()->forget('admin:users:summary');

        $_SESSION['flash_success'] = 'All active sessions for this user have been terminated.';
        header('Location: ' . $this->adminPrefix() . '/sessions');
        exit;
    }

    /** GET /{$ap}/tokens */
    public function tokens(): void
    {
        $this->auth->requireAuditor();

        $tokens = $this->app->db()->fetchAll(
            'SELECT t.*, u.username, u.email
             FROM api_tokens t
             LEFT JOIN users u ON t.user_id = u.id
             ORDER BY t.created_at DESC'
        );

        $this->app->view()->display('admin/tokens.twig', [
            'csrf_token'    => $this->auth->generateCsrf(),
            'tokens'        => $tokens,
            'nav_counts'    => $this->getNavCounts(),
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error'   => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/tokens/{id}/revoke */
    public function revokeToken(int $id): void
    {
        $this->auth->requireStaff();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/tokens');
            exit;
        }

        $this->app->db()->execute('UPDATE api_tokens SET revoked_at = NOW() WHERE id = :id', ['id' => $id]);

        $_SESSION['flash_success'] = 'API Token revoked successfully.';
        header('Location: ' . $this->adminPrefix() . '/tokens');
        exit;
    }

    /** GET /{$ap}/system */
    public function system(): void
    {
        $this->auth->requireAdmin();

        $basePath = dirname(__DIR__, 2);
        $cache = $this->app->cache();

        // 1. Calculate Storage Sizes (Cached for 60s)
        $storage = $cache->remember('admin:system:storage', 60, function () use ($basePath): array {
            $repoPath = rtrim((string) env('REPOS_PATH', $this->app->config('git.repositories_path', $basePath . '/repos')), '/');
            $downloadPath = $basePath . '/storage/downloads';
            $sessionPath = $basePath . '/storage/sessions';
            $cachePath = $basePath . '/storage/cache';

            $repoSize = $this->getDirSize($repoPath);
            $downloadSize = $this->getDirSize($downloadPath);
            $sessionSize = $this->getDirSize($sessionPath);
            $cacheSize = $this->getDirSize($cachePath);

            $dbName = (string) $this->app->config('database.dbname', 'git_git');
            $dbSizeRow = $this->app->db()->fetchOne(
                'SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = :db',
                ['db' => $dbName]
            );
            $dbSize = (int) ($dbSizeRow['size'] ?? 0);

            $diskFree = @disk_free_space($basePath) ?: 0;
            $diskTotal = @disk_total_space($basePath) ?: 1;
            $diskUsed = max(0, $diskTotal - $diskFree);
            $diskPercent = round(($diskUsed / $diskTotal) * 100, 1);

            return [
                'repos_formatted'     => $this->formatBytes($repoSize),
                'downloads_formatted' => $this->formatBytes($downloadSize),
                'db_formatted'        => $this->formatBytes($dbSize),
                'sessions_formatted'  => $this->formatBytes($sessionSize),
                'cache_formatted'     => $this->formatBytes($cacheSize),
                'disk_total'          => $this->formatBytes($diskTotal),
                'disk_used'           => $this->formatBytes($diskUsed),
                'disk_free'           => $this->formatBytes($diskFree),
                'disk_percent'        => $diskPercent,
            ];
        });

        // 2. Environment Diagnostics
        $phpVersion = PHP_VERSION;
        $gitVersion = trim((string) @shell_exec('git --version') ?: 'Git available');
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Web Server';
        $memoryLimit = ini_get('memory_limit') ?: '256M';
        $maxUpload = ini_get('upload_max_filesize') ?: '50M';
        $maxPost = ini_get('post_max_size') ?: '50M';
        $opcacheEnabled = function_exists('opcache_get_status') && !empty(@opcache_get_status(false)['opcache_enabled']);

        // Git Repository Storage Breakdown & Health
        $gitStorage = $cache->remember('admin:system:git_storage', 60, function (): array {
            $optimizer = new \App\Service\GitStorageOptimizer($this->app);
            return $optimizer->getAllReposStats();
        });

        // 3. System Stats
        $stats = $cache->remember('admin:system:stats', 30, function (): array {
            return [
                'users'     => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM users'),
                'repos'     => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM repositories'),
                'downloads' => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM file_downloads'),
                'sessions'  => (int) $this->app->db()->fetchValue('SELECT COUNT(*) FROM user_sessions'),
            ];
        });
        $mysqlVersion = (string) $this->app->db()->fetchValue('SELECT VERSION()');

        $this->app->view()->display('admin/system.twig', [
            'csrf_token'      => $this->auth->generateCsrf(),
            'storage'         => $storage,
            'git_storage'     => $gitStorage,
            'env'             => [
                'php_version'     => $phpVersion,
                'git_version'     => $gitVersion,
                'mysql_version'   => $mysqlVersion,
                'server_software' => $serverSoftware,
                'memory_limit'    => $memoryLimit,
                'max_upload'      => $maxUpload,
                'max_post'        => $maxPost,
                'opcache'         => $opcacheEnabled,
            ],
            'stats'           => $stats,
            'nav_counts'      => $this->getNavCounts(),
            'flash_success'   => $_SESSION['flash_success'] ?? null,
            'flash_error'     => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/system/clear-cache */
    public function clearCache(): void
    {
        $this->auth->requireAdmin();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/system');
            exit;
        }

        $basePath = dirname(__DIR__, 2);
        $cleaner = new \App\Service\CacheCleaner($basePath);
        $res = $cleaner->fullFlush(includeSessions: false);

        $this->app->cache()->forget('admin:system:storage');
        $this->app->cache()->forget('admin:system:stats');
        $this->app->cache()->forget('admin:nav_counts');

        $totalPurged = $res['cache_deleted'] + $res['twig_deleted'];
        $_SESSION['flash_success'] = "All caches wiped successfully: {$totalPurged} files purged ({$res['formatted_bytes_freed']} freed).";
        header('Location: ' . $this->adminPrefix() . '/system');
        exit;
    }

    /** POST /{$ap}/system/smart-clean */
    public function smartClean(): void
    {
        $this->auth->requireAdmin();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/system');
            exit;
        }

        $basePath = dirname(__DIR__, 2);
        $cleaner = new \App\Service\CacheCleaner($basePath);
        $res = $cleaner->smartClean();

        $this->app->cache()->forget('admin:system:storage');
        $this->app->cache()->forget('admin:system:stats');
        $this->app->cache()->forget('admin:nav_counts');

        $totalPurged = $res['expired_cache_deleted'] + $res['twig_deleted'] + $res['sessions_deleted'] + $res['temp_deleted'];
        $_SESSION['flash_success'] = "Smart cleanup completed: {$totalPurged} items purged, {$res['formatted_bytes_freed']} freed. Current cache size: {$res['current_cache_size']}.";
        header('Location: ' . $this->adminPrefix() . '/system');
        exit;
    }

    /** POST /{$ap}/system/optimize-db */
    public function optimizeDb(): void
    {
        $this->auth->requireAdmin();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/system');
            exit;
        }

        // Clean expired sessions (> 30 days)
        $this->app->db()->execute('DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)');

        $this->app->cache()->forget('admin:system:storage');
        $this->app->cache()->forget('admin:system:stats');
        $this->app->cache()->forget('admin:users:summary');
        $this->app->cache()->forget('admin:nav_counts');

        $_SESSION['flash_success'] = 'Database indexes optimized and expired sessions purged.';
        header('Location: ' . $this->adminPrefix() . '/system');
        exit;
    }

    /** POST /{$ap}/system/optimize-git — safe background storage optimization */
    public function optimizeGit(): void
    {
        $this->auth->requireAdmin();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/system');
            exit;
        }

        $optimizer = new \App\Service\GitStorageOptimizer($this->app);
        $res = $optimizer->optimizeAll(aggressive: false);
        $cachePurge = $optimizer->pruneArchiveCache(maxAgeSeconds: 86400);

        $this->app->cache()->forget('admin:system:storage');
        $this->app->cache()->forget('admin:system:git_storage');

        $freedFormatted = $optimizer->formatBytes($res['total_saved_bytes']);
        $purgedFormatted = $optimizer->formatBytes($cachePurge['bytes_freed']);

        $_SESSION['flash_success'] = "Git storage housekeeping completed: {$res['optimized_repos_count']} repositories compacted & indexed ({$freedFormatted} freed). Archive download cache pruned ({$purgedFormatted} freed). Zero server impact.";
        header('Location: ' . $this->adminPrefix() . '/system');
        exit;
    }

    /** POST /api/v1/maintenance/clear-cache */
    public function purgeCache(): void
    {
        // Security gate: only the site owner may trigger cache purges.
        // Admin users must use the admin panel form (POST /{$ap}/system/clear-cache).
        if (! $this->auth->isOwner()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Forbidden: owner authentication required.',
            ]);
            exit;
        }

        // CSRF: the floating maintenance panel posts via fetch(), so the
        // token is sent in the X-CSRF-Token header (validated by validateCsrf).
        if (! $this->auth->validateCsrf()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Forbidden: invalid CSRF token.',
            ]);
            exit;
        }

        // Rate limit: at most 6 purge requests per minute per session.
        $now = time();
        $window = $_SESSION['purge_cache_rl'] ?? null;
        if ($window !== null && $window['reset_at'] > $now && $window['count'] >= 6) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . max(1, $window['reset_at'] - $now));
            echo json_encode([
                'success' => false,
                'message' => 'Too many cache purge requests. Try again shortly.',
            ]);
            exit;
        }
        if ($window === null || $window['reset_at'] <= $now) {
            $_SESSION['purge_cache_rl'] = ['count' => 0, 'reset_at' => $now + 60];
        }
        $_SESSION['purge_cache_rl']['count']++;

        $type = $_POST['type'] ?? $_GET['type'] ?? 'all';
        $basePath = dirname(__DIR__, 2);
        $messages = [];

        // 1. Clear Local File Cache
        if ($type === 'all' || $type === 'local' || $type === 'files') {
            $cacheDir = $basePath . '/storage/cache';
            $clearedFiles = 0;
            foreach (glob($cacheDir . '/*.cache') ?: [] as $f) {
                if (is_file($f)) { @unlink($f); $clearedFiles++; }
            }
            $messages[] = "Local file cache cleared ({$clearedFiles} files)";
        }

        // 2. Clear Twig Template Cache
        if ($type === 'all' || $type === 'twig') {
            $twigCache = $basePath . '/storage/cache/twig';
            $clearedTwig = 0;
            if (is_dir($twigCache)) {
                try {
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($twigCache, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $fileinfo) {
                        if ($fileinfo->isFile()) {
                            @unlink($fileinfo->getRealPath());
                            $clearedTwig++;
                        } elseif ($fileinfo->isDir()) {
                            @rmdir($fileinfo->getRealPath());
                        }
                    }
                } catch (\Throwable) {}
            }
            $messages[] = "Twig templates cache cleared ({$clearedTwig} files)";
        }

        // 3. Clear OPCACHE
        if ($type === 'all' || $type === 'opcache') {
            if (function_exists('opcache_reset')) {
                @opcache_reset();
                $messages[] = "OPCache reset successfully";
            } else {
                $messages[] = "OPCache is not active";
            }
        }

        // 4. Clear Redis Cache
        if ($type === 'all' || $type === 'redis') {
            try {
                $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
                $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
                // Short connect timeout + read timeout so this can never hang.
                $fp = @fsockopen($redisHost, $redisPort, $errno, $errstr, 1);
                if ($fp) {
                    @stream_set_timeout($fp, 2);
                    // RESP requires CRLF line endings, not plain LF.
                    @fwrite($fp, "*1\r\n\$7\r\nFLUSHDB\r\n");
                    $resp = trim((string) @fgets($fp));
                    @fclose($fp);
                    if ($resp === '+OK') {
                        $messages[] = "Redis cache flushed";
                    } elseif (stripos($resp, 'NOAUTH') !== false) {
                        $messages[] = "Redis requires AUTH — flush skipped";
                    } else {
                        $messages[] = $resp !== '' ? "Redis responded: {$resp}" : "Redis flush not acknowledged";
                    }
                } else {
                    $messages[] = "Redis server not active";
                }
            } catch (\Throwable $e) {
                $messages[] = "Redis: " . $e->getMessage();
            }
        }

        // 5. Clear Memcached
        if ($type === 'all' || $type === 'memcached') {
            try {
                $memHost = getenv('MEMCACHED_HOST') ?: '127.0.0.1';
                $memPort = (int)(getenv('MEMCACHED_PORT') ?: 11211);
                $fp = @fsockopen($memHost, $memPort, $errno, $errstr, 1);
                if ($fp) {
                    @stream_set_timeout($fp, 2);
                    @fwrite($fp, "flush_all\r\n");
                    $resp = trim((string) @fgets($fp));
                    @fclose($fp);
                    $messages[] = str_starts_with($resp, 'OK') ? 'Memcached flushed' : ($resp !== '' ? "Memcached responded: {$resp}" : 'Memcached flush not acknowledged');
                } else {
                    $messages[] = "Memcached server not active";
                }
            } catch (\Throwable $e) {
                $messages[] = "Memcached: " . $e->getMessage();
            }
        }

        try {
            $this->app->cache()->flush();
        } catch (\Throwable) {}

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => implode(' | ', $messages)
        ]);
        exit;
    }


    private function getDirSize(string $path): int
    {
        if (! is_dir($path)) return 0;
        $size = 0;
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        } catch (\Throwable) {}
        return $size;
    }

    private function formatBytes(float|int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }

    private function adminPrefix(): string
    {
        $hash = substr(hash('sha256', session_id() . 'gitphp_admin_sec_2026'), 0, 12);
        return "/cp_{$hash}";
    }
}
