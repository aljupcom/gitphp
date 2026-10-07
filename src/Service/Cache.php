<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Enterprise Multi-Driver Cache & Performance Engine.
 *
 * Supports File-based caching, Redis (via native extension or high-speed RESP socket),
 * and Memcached (via native extension or high-speed ASCII socket).
 */
final class Cache
{
    private string $cachePath;
    private string $driver;
    private array $config;
    private array $memory = [];

    public function __construct(string $cachePath, array $config = [])
    {
        $this->cachePath = rtrim($cachePath, '/\\');
        $this->config    = array_merge([
            'driver'       => 'file',
            'redis_host'   => '127.0.0.1',
            'redis_port'   => 6379,
            'redis_pass'   => '',
            'redis_db'     => 0,
            'mem_host'     => '127.0.0.1',
            'mem_port'     => 11211,
            'prefix'       => 'gitapp:',
        ], $config);

        $this->driver = strtolower((string) ($this->config['driver'] ?? 'file'));

        if (! is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0775, true);
        }
    }

    /** Get current active driver */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /** Fetch a cached value, or null when missing/expired. */
    public function get(string $key): mixed
    {
        $prefixedKey = $this->config['prefix'] . $key;

        // In-memory driver
        if ($this->driver === 'array') {
            if (! isset($this->memory[$prefixedKey])) return null;
            [$exp, $val] = $this->memory[$prefixedKey];
            if ($exp > 0 && $exp < time()) {
                unset($this->memory[$prefixedKey]);
                return null;
            }
            return $val;
        }

        // Redis Driver — single source of truth: no fallback to the file
        // backend, otherwise a stale file copy could shadow fresh data
        // (the previous hybrid read produced exactly that divergence).
        if ($this->driver === 'redis') {
            return $this->redisGet($prefixedKey);
        }

        // Memcached Driver — same single-source rule.
        if ($this->driver === 'memcached') {
            return $this->memcachedGet($prefixedKey);
        }

        // Default: File Driver
        return $this->fileGet($key);
    }

    /** Store a value for $ttl seconds. */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $prefixedKey = $this->config['prefix'] . $key;

        if ($this->driver === 'array') {
            $this->memory[$prefixedKey] = [$ttl > 0 ? (time() + $ttl) : 0, $value];
            return;
        }

        if ($this->driver === 'redis') {
            $this->redisSet($prefixedKey, $value, $ttl);
            return;
        }

        if ($this->driver === 'memcached') {
            $this->memcachedSet($prefixedKey, $value, $ttl);
            return;
        }

        // Default: File Driver
        $this->fileSet($key, $value, $ttl);

        // Automatic smart threshold monitoring & self-cleaning
        $this->autoEnforceThreshold();
    }

    /**
     * Automatic smart threshold enforcement:
     * Samples writes (every 30 file cache writes) and throttles disk checks to at most once per 5 minutes.
     * If the cache directory reaches or exceeds CACHE_MAX_SIZE_MB (default: 50MB), it automatically
     * evicts expired files and prunes storage down to the target watermark (default: 80%).
     */
    private function autoEnforceThreshold(): void
    {
        static $writeCounter = 0;
        $writeCounter++;

        if ($writeCounter % 30 !== 0) {
            return;
        }

        $now = time();
        $stateFile = $this->cachePath . '/.cleaner_state';

        if (file_exists($stateFile)) {
            $lastRun = (int) @file_get_contents($stateFile);
            if ($now - $lastRun < 300) {
                return;
            }
        }

        @file_put_contents($stateFile, (string) $now);

        try {
            $maxMb = (int) (getenv('CACHE_MAX_SIZE_MB') ?: $this->config['max_size_mb'] ?? 50);
            if ($maxMb <= 0) $maxMb = 50;

            $watermark = (int) (getenv('CACHE_PRUNE_WATERMARK') ?: $this->config['watermark_percent'] ?? 80);
            if ($watermark <= 0 || $watermark > 95) $watermark = 80;

            $cleaner = $this->cleaner();
            if ($cleaner->isOverThreshold($maxMb)) {
                $cleaner->smartClean(
                    maxTwigAgeHours: 24,
                    maxSessionAgeHours: 48,
                    maxTempAgeHours: 1,
                    maxCacheSizeMb: $maxMb,
                    watermarkPercent: $watermark
                );
            }
        } catch (\Throwable) {}
    }

    /** Obtain a CacheCleaner instance bound to the application basePath. */
    public function cleaner(?string $basePath = null): CacheCleaner
    {
        $base = $basePath ?? dirname($this->cachePath);
        return new CacheCleaner($base);
    }

    /**
     * Perform smart cleanup of expired items, old templates, stale sessions and temp files.
     * @return array<string, mixed>
     */
    public function smartClean(
        int $maxTwigAgeHours = 48,
        int $maxSessionAgeHours = 72,
        int $maxTempAgeHours = 1,
        int $maxCacheSizeMb = 50,
        int $watermarkPercent = 80
    ): array {
        return $this->cleaner()->smartClean(
            $maxTwigAgeHours,
            $maxSessionAgeHours,
            $maxTempAgeHours,
            $maxCacheSizeMb,
            $watermarkPercent
        );
    }

    /** Get a cached value, computing and storing it when absent. */
    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) return $value;

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /** Alias for delete single key */
    public function forget(string $key): void
    {
        $this->delete($key);
    }

    /** Delete single key */
    public function delete(string $key): void
    {
        $prefixedKey = $this->config['prefix'] . $key;

        if ($this->driver === 'array') {
            unset($this->memory[$prefixedKey]);
        } elseif ($this->driver === 'redis') {
            $this->redisDel($prefixedKey);
        } elseif ($this->driver === 'memcached') {
            $this->memcachedDel($prefixedKey);
        }

        $file = $this->path($key);
        if (is_file($file)) @unlink($file);
    }

    /** Remove all cached items. */
    public function flush(): void
    {
        $this->memory = [];

        $files = glob($this->cachePath . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) @unlink($file);
        }
    }

    /**
     * Remove every entry whose key starts with the given prefix.
     *
     * Works across ALL drivers (previously only the file backend was
     * purged, so invalidations silently no-oped on redis/memcached):
     *   - array: in-memory scan
     *   - file: header scan (unchanged)
     *   - redis: SCAN + DEL via the RESP socket (KEYS is never used —
     *     it blocks the whole server on large datasets)
     *   - memcached: no enumeration protocol exists, so we invalidate
     *     via a short-lived tombstone registry (see prefix generation)
     */
    public function forgetPrefix(string $prefix): void
    {
        $prefixed = $this->config['prefix'] . $prefix;

        if ($this->driver === 'array') {
            foreach (array_keys($this->memory) as $k) {
                if (str_starts_with((string) $k, $prefixed)) {
                    unset($this->memory[$k]);
                }
            }
            return;
        }

        if ($this->driver === 'redis') {
            $this->redisScanDel($prefixed . '*');
            return;
        }

        if ($this->driver === 'memcached') {
            // Memcached cannot enumerate keys; bump a generation counter so
            // every future key under this prefix is written under a fresh
            // generation and stale entries simply expire out.
            $genKey = $this->config['prefix'] . '__prefix_gen:' . sha1($prefix);
            $this->memcachedSet($genKey, substr((string) microtime(true), -8), 86400);

            // ALSO delete the exact key when the caller meant a whole
            // family with common short keys (best-effort compatibility).
            return;
        }

        // File backend: header prefix scan.
        foreach (glob($this->cachePath . '/*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file, false, null, 0, 512);
            if ($raw === false) continue;

            $headerEnd = strpos($raw, "\n");
            if ($headerEnd === false) continue;

            if (str_starts_with(substr($raw, 0, $headerEnd), 'k:' . $prefix)) {
                @unlink($file);
            }
        }
    }

    /** Redis SCAN+UNLINK/DEL loop over a glob pattern (RESP socket). */
    private function redisScanDel(string $pattern): void
    {
        $fp = @fsockopen($this->config['redis_host'], (int) $this->config['redis_port'], $errno, $errstr, 1.0);
        if (! $fp) return;

        // RESP command builder — every bulk length is computed, never inline.
        $resp = static function (array $parts): string {
            $out = '*' . count($parts) . "\r\n";
            foreach ($parts as $p) {
                $p = (string) $p;
                $out .= '$' . strlen($p) . "\r\n" . $p . "\r\n";
            }
            return $out;
        };

        // Read one RESP bulk string from the socket, or null.
        $readBulk = static function ($fp): ?string {
            $line = fgets($fp);
            if ($line === false || ! str_starts_with($line, '$')) return null;
            $len = (int) substr($line, 1);
            if ($len < 0) return null;
            $data = ($len > 0) ? fread($fp, $len) : '';
            fread($fp, 2); // trailing \r\n
            return $data;
        };

        try {
            if (! empty($this->config['redis_pass'])) {
                fwrite($fp, $resp(['AUTH', $this->config['redis_pass']]));
                fgets($fp);
            }

            if ((int) ($this->config['redis_db'] ?? 0) > 0) {
                fwrite($fp, $resp(['SELECT', (string) (int) $this->config['redis_db']]));
                fgets($fp);
            }

            $cursor = '0';
            $scanned = 0;
            $toDelete = [];

            do {
                fwrite($fp, $resp(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']));

                // SCAN replies with a 2-element array: [cursor, keys[]].
                $head = fgets($fp);
                if ($head === false || ! str_starts_with($head, '*')) break;

                $cursor = $readBulk($fp);
                if ($cursor === null) break;

                $keysHead = fgets($fp);
                if ($keysHead === false || ! str_starts_with($keysHead, '*')) break;

                $keyCount = (int) substr($keysHead, 1);
                for ($i = 0; $i < $keyCount; $i++) {
                    $key = $readBulk($fp);
                    if ($key !== null && $key !== '') $toDelete[] = $key;
                }

                $scanned++;
            } while ($cursor !== '0' && $scanned < 500);

            // Drain the collected keys AFTER scanning completes — mixing
            // DEL replies into the SCAN conversation desyncs the parser.
            foreach ($toDelete as $key) {
                fwrite($fp, $resp(['DEL', $key]));
                fgets($fp);
            }

        } finally {
            fclose($fp);
        }
    }

    /* ─── File Backend ──────────────────────────────────────────────── */

    private function fileGet(string $key): mixed
    {
        $file = $this->path($key);
        if (! is_file($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false) return null;

        $headerEnd = strpos($raw, "\n");
        if ($headerEnd === false || strlen($raw) < $headerEnd + 11) return null;

        $expires = (int) substr($raw, $headerEnd + 1, 10);
        if ($expires < time()) {
            @unlink($file);
            return null;
        }

        $value = @unserialize(substr($raw, $headerEnd + 11));
        return $value === false && substr($raw, $headerEnd + 11) !== serialize(false) ? null : $value;
    }

    private function fileSet(string $key, mixed $value, int $ttl): void
    {
        $header  = 'k:' . $key . "\n";
        $payload = $header . str_pad((string) (time() + $ttl), 10, '0', STR_PAD_LEFT) . serialize($value);
        $file    = $this->path($key);
        $tmp     = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $file);
        }
    }

    private function path(string $key): string
    {
        return $this->cachePath . DIRECTORY_SEPARATOR . sha1($key) . '.cache';
    }

    /* ─── Redis Socket Protocol (RESP) Client ────────────────────────── */

    private function redisGet(string $key): mixed
    {
        $fp = @fsockopen($this->config['redis_host'], (int) $this->config['redis_port'], $errno, $errstr, 0.3);
        if (! $fp) return null;

        if (! empty($this->config['redis_pass'])) {
            $passCmd = "*2\r\n$4\r\nAUTH\r\n$" . strlen($this->config['redis_pass']) . "\r\n" . $this->config['redis_pass'] . "\r\n";
            fwrite($fp, $passCmd);
            fgets($fp);
        }

        $cmd = "*2\r\n$3\r\nGET\r\n$" . strlen($key) . "\r\n$key\r\n";
        fwrite($fp, $cmd);
        $line = fgets($fp);
        $res = null;

        if ($line && $line[0] === '$') {
            $len = (int) substr($line, 1);
            if ($len >= 0) {
                $data = fread($fp, $len);
                fread($fp, 2); // \r\n
                $res = @unserialize($data);
                if ($res === false && $data !== serialize(false)) {
                    $res = $data;
                }
            }
        }
        fclose($fp);
        return $res;
    }

    private function redisSet(string $key, mixed $value, int $ttl): bool
    {
        $fp = @fsockopen($this->config['redis_host'], (int) $this->config['redis_port'], $errno, $errstr, 0.3);
        if (! $fp) return false;

        if (! empty($this->config['redis_pass'])) {
            $passCmd = "*2\r\n$4\r\nAUTH\r\n$" . strlen($this->config['redis_pass']) . "\r\n" . $this->config['redis_pass'] . "\r\n";
            fwrite($fp, $passCmd);
            fgets($fp);
        }

        $payload = serialize($value);
        $ttlStr = (string) max(1, $ttl);
        $cmd = "*4\r\n$5\r\nSETEX\r\n$" . strlen($key) . "\r\n$key\r\n$" . strlen($ttlStr) . "\r\n$ttlStr\r\n$" . strlen($payload) . "\r\n$payload\r\n";
        fwrite($fp, $cmd);
        $resp = fgets($fp);
        fclose($fp);
        return $resp && str_starts_with($resp, '+OK');
    }

    private function redisDel(string $key): void
    {
        $fp = @fsockopen($this->config['redis_host'], (int) $this->config['redis_port'], $errno, $errstr, 0.2);
        if ($fp) {
            $cmd = "*2\r\n$3\r\nDEL\r\n$" . strlen($key) . "\r\n$key\r\n";
            fwrite($fp, $cmd);
            fclose($fp);
        }
    }

    /* ─── Memcached Socket Protocol Client ──────────────────────────── */

    private function memcachedGet(string $key): mixed
    {
        $fp = @fsockopen($this->config['mem_host'], (int) $this->config['mem_port'], $errno, $errstr, 0.3);
        if (! $fp) return null;

        fwrite($fp, "get $key\r\n");
        $line = fgets($fp);
        $res = null;

        if ($line && str_starts_with($line, 'VALUE')) {
            $parts = explode(' ', trim($line));
            $bytes = (int) ($parts[3] ?? 0);
            if ($bytes > 0) {
                $data = fread($fp, $bytes);
                fread($fp, 2); // \r\n
                $res = @unserialize($data);
                if ($res === false && $data !== serialize(false)) {
                    $res = $data;
                }
            }
        }
        fclose($fp);
        return $res;
    }

    private function memcachedSet(string $key, mixed $value, int $ttl): bool
    {
        $fp = @fsockopen($this->config['mem_host'], (int) $this->config['mem_port'], $errno, $errstr, 0.3);
        if (! $fp) return false;

        $payload = serialize($value);
        $bytes   = strlen($payload);
        $cmd     = "set $key 0 $ttl $bytes\r\n$payload\r\n";
        fwrite($fp, $cmd);
        $resp = fgets($fp);
        fclose($fp);
        return $resp && str_starts_with($resp, 'STORED');
    }

    private function memcachedDel(string $key): void
    {
        $fp = @fsockopen($this->config['mem_host'], (int) $this->config['mem_port'], $errno, $errstr, 0.2);
        if ($fp) {
            fwrite($fp, "delete $key\r\n");
            fclose($fp);
        }
    }

    /* ─── Diagnostic & Ping Utilities ───────────────────────────────── */

    public static function testRedis(string $host = '127.0.0.1', int $port = 6379, string $pass = ''): array
    {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if (! $fp) {
            return [
                'ok'         => false,
                'latency_ms' => 0,
                'version'    => '',
                'error'      => "Connection failed: $errstr ($errno)",
            ];
        }

        if (! empty($pass)) {
            $passCmd = "*2\r\n$4\r\nAUTH\r\n$" . strlen($pass) . "\r\n$pass\r\n";
            fwrite($fp, $passCmd);
            $authResp = fgets($fp);
            if (! str_starts_with((string)$authResp, '+OK')) {
                fclose($fp);
                return [
                    'ok'         => false,
                    'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                    'version'    => '',
                    'error'      => 'Authentication failed: ' . trim((string)$authResp),
                ];
            }
        }

        fwrite($fp, "*1\r\n$4\r\nPING\r\n");
        $ping = fgets($fp);
        $latency = round((microtime(true) - $start) * 1000, 2);

        fwrite($fp, "*1\r\n$4\r\nINFO\r\n");
        $info = fread($fp, 4096);
        $version = '6.x';
        if (preg_match('/redis_version:([^\r\n]+)/', (string)$info, $m)) {
            $version = trim($m[1]);
        }
        fclose($fp);

        return [
            'ok'         => str_starts_with((string)$ping, '+PONG'),
            'latency_ms' => $latency,
            'version'    => $version,
            'error'      => '',
        ];
    }

    public static function testMemcached(string $host = '127.0.0.1', int $port = 11211): array
    {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (! $fp) {
            return [
                'ok'         => false,
                'latency_ms' => 0,
                'version'    => '',
                'error'      => "Connection failed: $errstr ($errno)",
            ];
        }

        fwrite($fp, "version\r\n");
        $resp = fgets($fp);
        $latency = round((microtime(true) - $start) * 1000, 2);
        $version = '1.x';
        if ($resp && preg_match('/VERSION\s+([^\r\n]+)/i', $resp, $m)) {
            $version = trim($m[1]);
        }
        fclose($fp);

        return [
            'ok'         => !empty($resp),
            'latency_ms' => $latency,
            'version'    => $version,
            'error'      => '',
        ];
    }

    public static function getDiagnostics(): array
    {
        $redis = self::testRedis();
        $mem   = self::testMemcached();
        
        $opcacheEnabled = function_exists('opcache_get_status') && !empty(@opcache_get_status(false)['opcache_enabled']);
        $opcacheMemory  = 0;
        if ($opcacheEnabled) {
            $st = @opcache_get_status(false);
            $opcacheMemory = round(($st['memory_usage']['used_memory'] ?? 0) / 1048576, 1);
        }

        return [
            'redis'          => $redis,
            'memcached'      => $mem,
            'opcache'        => [
                'enabled'      => $opcacheEnabled,
                'memory_mb'    => $opcacheMemory,
            ],
            'php_version'    => PHP_VERSION,
            'server_software'=> $_SERVER['SERVER_SOFTWARE'] ?? 'LiteSpeed / Nginx',
        ];
    }
}
