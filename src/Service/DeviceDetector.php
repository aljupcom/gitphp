<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use Throwable;

/**
 * High-Entropy Device & Client Hints Detection Engine
 * Integrates official Google Play Certified Devices (53,000+ models via SQLite)
 * and custom Apple Hardware Matrix (apple_devices.json).
 */
final class DeviceDetector
{
    private static ?array $appleCatalog = null;
    private static ?PDO $sqliteDb = null;

    private const GOOGLE_SOURCE_URL = 'https://storage.googleapis.com/play_public/supported_devices.html';
    private const SQLITE_PATH = __DIR__ . '/../../storage/data/google_play_devices.sqlite';
    private const APPLE_JSON_PATH = __DIR__ . '/../../storage/data/apple_devices.json';
    private const META_PATH = __DIR__ . '/../../storage/data/devices_meta.json';

    /**
     * Resolve device details from current HTTP request / headers and optional Client Hints payload.
     *
     * @param string|null $userAgent
     * @param array<string, mixed>|string|null $clientHints
     * @return array{
     *   brand: string,
     *   model: string,
     *   code: string,
     *   type: string,
     *   os_name: string,
     *   os_version: string,
     *   browser_name: string,
     *   browser_version: string,
     *   full_name: string,
     *   client_hints_json: string
     * }
     */
    public static function detect(?string $userAgent = null, array|string|null $clientHints = null): array
    {
        $ua = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $hints = self::extractClientHints($clientHints);

        // 1. Detect Operating System & Version
        [$osName, $osVersion] = self::detectOS($ua, $hints);

        // 2. Detect Browser & Version
        [$browserName, $browserVersion] = self::detectBrowser($ua, $hints);

        // 3. Detect Device Model & Brand
        [$brand, $model, $code, $type] = self::detectHardware($ua, $hints, $osName);

        $fullName = self::formatDisplayName($brand, $model, $code, $osName, $type);

        return [
            'brand'             => $brand,
            'model'             => $model,
            'code'              => $code,
            'type'              => $type,
            'os_name'           => $osName,
            'os_version'        => $osVersion,
            'browser_name'      => $browserName,
            'browser_version'   => $browserVersion,
            'full_name'         => $fullName,
            'client_hints_json' => json_encode($hints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    /** @return array<string, mixed> */
    private static function extractClientHints(array|string|null $explicitHints): array
    {
        $hints = [];

        if (is_string($explicitHints) && $explicitHints !== "") {
            $decoded = json_decode($explicitHints, true);
            if (is_array($decoded)) {
                $hints = $decoded;
            }
        } elseif (is_array($explicitHints)) {
            $hints = $explicitHints;
        }

        if (isset($_SERVER['HTTP_SEC_CH_UA_MODEL'])) {
            $hints['model'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_MODEL'], "\" \t\n\r\0\x0B");
        }
        if (isset($_SERVER['HTTP_SEC_CH_UA_PLATFORM'])) {
            $hints['platform'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_PLATFORM'], "\" \t\n\r\0\x0B");
        }
        if (isset($_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'])) {
            $hints['platformVersion'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'], "\" \t\n\r\0\x0B");
        }
        if (isset($_SERVER['HTTP_SEC_CH_UA_MOBILE'])) {
            $hints['mobile'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_MOBILE'], "? \t\n\r\0\x0B");
        }
        if (isset($_SERVER['HTTP_SEC_CH_UA_ARCH'])) {
            $hints['architecture'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_ARCH'], "\" \t\n\r\0\x0B");
        }
        if (isset($_SERVER['HTTP_SEC_CH_UA_BITNESS'])) {
            $hints['bitness'] = trim((string) $_SERVER['HTTP_SEC_CH_UA_BITNESS'], "\" \t\n\r\0\x0B");
        }

        foreach (['model', 'platform', 'platformVersion', 'architecture', 'bitness'] as $k) {
            if (isset($hints[$k]) && is_scalar($hints[$k])) {
                $hints[$k] = trim((string) $hints[$k], "\" \t\n\r\0\x0B");
            }
        }
        if (isset($hints['mobile'])) {
            if (is_bool($hints['mobile'])) {
                $hints['mobile'] = $hints['mobile'] ? '1' : '0';
            } elseif (is_scalar($hints['mobile'])) {
                $hints['mobile'] = trim((string) $hints['mobile'], "? \t\n\r\0\x0B");
            }
        }

        return $hints;
    }

    /** @return array{0: string, 1: string} */
    private static function detectOS(string $ua, array $hints): array
    {
        $platform = $hints['platform'] ?? '';
        $pVer = $hints['platformVersion'] ?? '';

        if (stripos($platform, 'Android') !== false || stripos($ua, 'Android') !== false) {
            $ver = '';
            if ($pVer !== '') {
                $ver = explode('.', $pVer)[0];
            } elseif (preg_match('/Android\s+([0-9\.]+)/i', $ua, $m)) {
                $ver = $m[1];
            }
            return ['Android', $ver];
        }

        if (stripos($platform, 'iOS') !== false || preg_match('/(iPhone|iPad|iPod)/i', $ua)) {
            $ver = '';
            if (preg_match('/OS\s+([0-9_]+)/i', $ua, $m)) {
                $ver = str_replace('_', '.', $m[1]);
            }
            return ['iOS', $ver];
        }

        if (stripos($platform, 'macOS') !== false || stripos($ua, 'Macintosh') !== false) {
            $ver = '';
            if (preg_match('/Mac OS X\s+([0-9_]+)/i', $ua, $m)) {
                $ver = str_replace('_', '.', $m[1]);
            }
            return ['macOS', $ver];
        }

        if (stripos($platform, 'Windows') !== false || stripos($ua, 'Windows') !== false) {
            $ver = '10/11';
            if ($pVer !== '') {
                $major = (int) explode('.', $pVer)[0];
                $ver = $major >= 13 ? '11' : '10';
            } elseif (preg_match('/Windows NT 10\.0/i', $ua)) {
                $ver = '10/11';
            } elseif (preg_match('/Windows NT 6\.3/i', $ua)) {
                $ver = '8.1';
            } elseif (preg_match('/Windows NT 6\.1/i', $ua)) {
                $ver = '7';
            }
            return ['Windows', $ver];
        }

        if (stripos($platform, 'Linux') !== false || stripos($ua, 'Linux') !== false) {
            return ['Linux', ''];
        }

        return ['Unknown OS', ''];
    }

    /** @return array{0: string, 1: string} */
    private static function detectBrowser(string $ua, array $hints): array
    {
        if (preg_match('/Edg\/([0-9\.]+)/i', $ua, $m)) {
            return ['Microsoft Edge', explode('.', $m[1])[0]];
        }
        if (preg_match('/SamsungBrowser\/([0-9\.]+)/i', $ua, $m)) {
            return ['Samsung Internet', explode('.', $m[1])[0]];
        }
        if (preg_match('/OPR\/([0-9\.]+)/i', $ua, $m) || preg_match('/Opera\/([0-9\.]+)/i', $ua, $m)) {
            return ['Opera', explode('.', $m[1])[0]];
        }
        if (preg_match('/Chrome\/([0-9\.]+)/i', $ua, $m)) {
            return ['Google Chrome', explode('.', $m[1])[0]];
        }
        if (preg_match('/Version\/([0-9\.]+)\s+Safari/i', $ua, $m)) {
            return ['Safari', explode('.', $m[1])[0]];
        }
        if (preg_match('/Firefox\/([0-9\.]+)/i', $ua, $m)) {
            return ['Mozilla Firefox', explode('.', $m[1])[0]];
        }

        return ['Web Browser', ''];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function detectHardware(string $ua, array $hints, string $osName): array
    {
        $rawModel = trim($hints['model'] ?? '');

        // 1. Check Apple Hardware Catalog (iPhone, iPad, Mac, Apple Watch)
        if ($osName === 'iOS' || $osName === 'macOS' || str_starts_with($rawModel, 'iPhone') || str_starts_with($rawModel, 'iPad') || str_starts_with($rawModel, 'Mac') || str_starts_with($rawModel, 'Watch')) {
            $apple = self::lookupApple($rawModel !== '' ? $rawModel : $ua);
            if ($apple !== null) {
                return ['Apple', $apple['model'], $rawModel ?: $apple['model'], $apple['type']];
            }
        }

        // 2. Lookup Google Play Certified Database (SQLite)
        if ($rawModel !== '') {
            $gdev = self::lookupGooglePlay($rawModel);
            if ($gdev !== null) {
                return [$gdev['brand'], $gdev['model'], $rawModel, $gdev['type']];
            }
        }

        // 3. Extract model candidate from User-Agent for Android
        if ($osName === 'Android') {
            if (preg_match('/;\s*([^;]+?)\s+Build\//i', $ua, $m)) {
                $candidate = trim($m[1]);
                $gdev = self::lookupGooglePlay($candidate);
                if ($gdev !== null) {
                    return [$gdev['brand'], $gdev['model'], $candidate, $gdev['type']];
                }
                $rawModel = $candidate;
            }

            // Fallback Brand Guessing
            if (str_starts_with($rawModel, 'SM-') || str_starts_with($rawModel, 'GT-')) {
                return ['Samsung', 'Samsung Galaxy (' . $rawModel . ')', $rawModel, 'mobile'];
            }
            if (str_starts_with($rawModel, 'Pixel')) {
                return ['Google', $rawModel, $rawModel, 'mobile'];
            }
            if (preg_match('/^(2\d{3}[A-Z0-9]+|M2\d{3}[A-Z0-9]+)/i', $rawModel)) {
                return ['Xiaomi', 'Xiaomi (' . $rawModel . ')', $rawModel, 'mobile'];
            }

            return ['Android', $rawModel !== '' ? $rawModel : 'Android Phone', $rawModel, 'mobile'];
        }

        if ($osName === 'iOS') {
            if (stripos($ua, 'iPad') !== false) {
                return ['Apple', 'Apple iPad', 'iPad', 'tablet'];
            }
            return ['Apple', 'Apple iPhone', 'iPhone', 'mobile'];
        }

        if ($osName === 'macOS') {
            $arch = $hints['architecture'] ?? '';
            $isArm = stripos($arch, 'arm') !== false || stripos($ua, 'AppleWebKit') !== false;
            return ['Apple', $isArm ? 'MacBook / Mac (Apple Silicon)' : 'Apple Mac', 'Mac', 'laptop'];
        }

        if ($osName === 'Windows') {
            return ['Microsoft', 'Windows PC', 'PC', 'desktop'];
        }

        if ($osName === 'Linux') {
            return ['Linux', 'Linux Workstation', 'PC', 'desktop'];
        }

        return ['Unknown', 'Desktop Computer', '', 'desktop'];
    }

    /**
     * Search Apple Hardware Catalog.
     * @return array{brand: string, model: string, type: string}|null
     */
    public static function lookupApple(string $query): ?array
    {
        $catalog = self::loadAppleCatalog();
        $query = trim($query);
        if ($query === '') return null;

        if (isset($catalog[$query])) {
            return [
                'brand' => 'Apple',
                'model' => $catalog[$query]['model'],
                'type'  => $catalog[$query]['type'] ?? 'mobile',
            ];
        }

        // Case-insensitive search
        $lower = strtolower($query);
        foreach ($catalog as $code => $info) {
            if (strtolower($code) === $lower || str_contains($lower, strtolower($code))) {
                return [
                    'brand' => 'Apple',
                    'model' => $info['model'],
                    'type'  => $info['type'] ?? 'mobile',
                ];
            }
        }

        return null;
    }

    /**
     * Search Google Play Certified Devices SQLite Database.
     * @return array{brand: string, model: string, type: string}|null
     */
    public static function lookupGooglePlay(string $modelOrDevice): ?array
    {
        $db = self::getSqliteDb();
        if ($db === null) return null;

        $target = trim($modelOrDevice);
        if ($target === '') return null;

        try {
            // 1. Exact match by model
            $stmt = $db->prepare('SELECT brand, marketing_name, device, model FROM google_devices WHERE model = :t LIMIT 1');
            $stmt->execute(['t' => $target]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. Exact match by device code
            if (!$row) {
                $stmt = $db->prepare('SELECT brand, marketing_name, device, model FROM google_devices WHERE device = :t LIMIT 1');
                $stmt->execute(['t' => $target]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // 3. Prefix match for model families (e.g. SM-S928)
            if (!$row && preg_match('/^(SM-[A-Z0-9]{4})/i', $target, $m)) {
                $stmt = $db->prepare('SELECT brand, marketing_name, device, model FROM google_devices WHERE model LIKE :p LIMIT 1');
                $stmt->execute(['p' => $m[1] . '%']);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($row) {
                $brand = trim((string) ($row['brand'] ?? ''));
                $mName = trim((string) ($row['marketing_name'] ?? ''));
                $model = trim((string) ($row['model'] ?? ''));

                $displayName = $mName !== '' ? $mName : ($brand !== '' ? "{$brand} {$model}" : $model);
                
                $type = 'mobile';
                if (stripos($displayName, 'Tab') !== false || stripos($displayName, 'Pad') !== false) {
                    $type = 'tablet';
                } elseif (stripos($displayName, 'TV') !== false) {
                    $type = 'tv';
                }

                return [
                    'brand' => $brand ?: 'Android',
                    'model' => $displayName,
                    'type'  => $type,
                ];
            }
        } catch (Throwable $e) {
            error_log('[DeviceDetector] SQLite lookup failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Check if a remote update is available from Google Play public list.
     * @return array{update_available: bool, local_count: int, remote_etag: string, local_etag: string, last_updated: string, remote_modified: string}
     */
    public static function checkUpdateAvailable(): array
    {
        $meta = self::loadMeta();
        $localEtag = $meta['etag'] ?? '';
        $localModified = $meta['last_modified'] ?? '';
        $localCount = (int) ($meta['google_count'] ?? 0);
        $lastUpdated = $meta['last_updated'] ?? 'Never';
        $googleRelease = $meta['google_release_date'] ?? '';

        $remoteEtag = '';
        $remoteModified = '';
        $remoteGoogleDate = '';
        $updateAvailable = false;

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init(self::GOOGLE_SOURCE_URL);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_RANGE          => '0-8192',
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_USERAGENT      => 'GitPHP-DeviceDetector/2.0',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HEADER         => true,
                ]);
                $response = (string) curl_exec($ch);
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $headerText = substr($response, 0, $headerSize);
                $bodyText   = substr($response, $headerSize);
                curl_close($ch);

                if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $headerText, $m)) {
                    $remoteEtag = trim($m[1]);
                }
                if (preg_match('/Last-Modified:\s*([^\r\n]+)/i', $headerText, $m)) {
                    $remoteModified = trim($m[1]);
                }
                if (preg_match('/Last\s+updated\s+on\s+([0-9\-]+)/i', $bodyText, $m)) {
                    $remoteGoogleDate = 'Last updated on ' . trim($m[1]);
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'HEAD',
                        'header' => "User-Agent: GitPHP-DeviceDetector/2.0\r\n",
                        'timeout' => 8,
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ]);
                $headers = @get_headers(self::GOOGLE_SOURCE_URL, true, $ctx);
                if (is_array($headers)) {
                    $remoteEtag = trim((string) ($headers['ETag'] ?? $headers['etag'] ?? ''), '" ');
                    $remoteModified = (string) ($headers['Last-Modified'] ?? $headers['last-modified'] ?? '');
                }
            }

            if ($localCount === 0) {
                $updateAvailable = true;
            } elseif ($remoteEtag !== '' && $localEtag !== '' && $remoteEtag !== $localEtag) {
                $updateAvailable = true;
            } elseif ($remoteModified !== '' && $localModified !== '' && $remoteModified !== $localModified) {
                $updateAvailable = true;
            } elseif ($remoteGoogleDate !== '' && $googleRelease !== '' && $remoteGoogleDate !== $googleRelease) {
                $updateAvailable = true;
            }
        } catch (Throwable $e) {
            error_log('[DeviceDetector] Update check failed: ' . $e->getMessage());
        }

        return [
            'update_available'   => $updateAvailable,
            'local_count'        => $localCount,
            'remote_etag'        => $remoteEtag ?: $localEtag,
            'local_etag'         => $localEtag,
            'last_updated'       => $lastUpdated,
            'remote_modified'    => $remoteModified ?: $localModified,
            'remote_google_date' => $remoteGoogleDate ?: $googleRelease,
        ];
    }

    /**
     * Download and rebuild local Google Play SQLite catalog.
     * @return array{success: bool, count: int, duration_seconds: float, message: string}
     */
    public static function syncGooglePlayCatalog(): array
    {
        $startTime = microtime(true);

        try {
            $dataDir = dirname(self::SQLITE_PATH);
            if (! is_dir($dataDir)) {
                @mkdir($dataDir, 0777, true);
            }
            @chmod($dataDir, 0777);

            // Download using cURL with headers capture
            $html = '';
            $etag = '';
            $remoteModified = '';
            if (function_exists('curl_init')) {
                $ch = curl_init(self::GOOGLE_SOURCE_URL);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 45,
                    CURLOPT_USERAGENT      => 'GitPHP-DeviceEngine/2.0',
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HEADER         => true,
                ]);
                $resp = (string) curl_exec($ch);
                $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $hText = substr($resp, 0, $hSize);
                $html  = substr($resp, $hSize);
                curl_close($ch);

                if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $hText, $m)) {
                    $etag = trim($m[1]);
                }
                if (preg_match('/Last-Modified:\s*([^\r\n]+)/i', $hText, $m)) {
                    $remoteModified = trim($m[1]);
                }
            }

            if ($html === '' || strlen($html) < 1000) {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => "User-Agent: GitPHP-DeviceEngine/2.0\r\n",
                        'timeout' => 45,
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ]);
                $html = (string) @file_get_contents(self::GOOGLE_SOURCE_URL, false, $ctx);
            }

            if ($html === '' || strlen($html) < 1000) {
                return [
                    'success' => false,
                    'count'   => 0,
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                    'message' => 'Failed to download supported_devices.html from Google servers.',
                ];
            }

            // Extract rows via regex
            preg_match_all('/<tr>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<td>(.*?)<\/td>\s*<\/tr>/s', $html, $matches, PREG_SET_ORDER);

            if (empty($matches)) {
                return [
                    'success' => false,
                    'count'   => 0,
                    'duration_seconds' => round(microtime(true) - $startTime, 2),
                    'message' => 'No device rows could be parsed from the downloaded file.',
                ];
            }

            $tempDb = self::SQLITE_PATH . '.tmp';
            if (file_exists($tempDb)) @unlink($tempDb);

            $pdo = new PDO('sqlite:' . $tempDb);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA synchronous = OFF');
            $pdo->exec('PRAGMA journal_mode = MEMORY');
            $pdo->exec('CREATE TABLE google_devices (brand TEXT, marketing_name TEXT, device TEXT, model TEXT)');

            $stmt = $pdo->prepare('INSERT INTO google_devices VALUES (?, ?, ?, ?)');
            $pdo->beginTransaction();

            $validCount = 0;
            foreach ($matches as $row) {
                $brand = trim(strip_tags($row[1] ?? ''));
                $mName = trim(strip_tags($row[2] ?? ''));
                $dev   = trim(strip_tags($row[3] ?? ''));
                $model = trim(strip_tags($row[4] ?? ''));

                if ($model === '' && $dev === '') continue;

                $stmt->execute([$brand, $mName, $dev, $model]);
                $validCount++;
            }

            $pdo->commit();

            // Build indexes
            $pdo->exec('CREATE INDEX idx_gdev_model ON google_devices(model COLLATE NOCASE)');
            $pdo->exec('CREATE INDEX idx_gdev_device ON google_devices(device COLLATE NOCASE)');
            $pdo->exec('CREATE INDEX idx_gdev_brand ON google_devices(brand COLLATE NOCASE)');
            unset($pdo);

            // Set file permissions and atomic replace
            @chmod($tempDb, 0666);
            @rename($tempDb, self::SQLITE_PATH);
            @chmod(self::SQLITE_PATH, 0666);

            // Extract Google official release date
            $googleReleaseDate = 'Last updated on ' . date('Y-m-d');
            if (preg_match('/Last\s+updated\s+on\s+([0-9\-]+)/i', $html, $m)) {
                $googleReleaseDate = 'Last updated on ' . trim($m[1]);
            }

            // Update metadata
            $meta = [
                'source_url'          => self::GOOGLE_SOURCE_URL,
                'etag'                => $etag,
                'last_modified'       => $remoteModified,
                'google_release_date' => $googleReleaseDate,
                'google_count'        => $validCount,
                'apple_count'         => count(self::loadAppleCatalog()),
                'last_updated'        => date('Y-m-d H:i:s'),
                'file_size'           => round(strlen($html) / (1024 * 1024), 2) . ' MB',
            ];
            @file_put_contents(self::META_PATH, json_encode($meta, JSON_PRETTY_PRINT));
            @chmod(self::META_PATH, 0666);

            // Reset SQLite connection instance
            self::$sqliteDb = null;

            return [
                'success' => true,
                'count'   => $validCount,
                'duration_seconds' => round(microtime(true) - $startTime, 2),
                'message' => "Successfully indexed {$validCount} Google Play certified devices.",
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'count'   => 0,
                'duration_seconds' => round(microtime(true) - $startTime, 2),
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    public static function loadMeta(): array
    {
        if (file_exists(self::META_PATH)) {
            $content = file_get_contents(self::META_PATH);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        return [
            'source_url'   => self::GOOGLE_SOURCE_URL,
            'google_count' => 0,
            'apple_count'  => 0,
            'last_updated' => 'Never',
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function loadAppleCatalog(): array
    {
        if (self::$appleCatalog !== null) return self::$appleCatalog;

        if (file_exists(self::APPLE_JSON_PATH)) {
            $content = file_get_contents(self::APPLE_JSON_PATH);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    self::$appleCatalog = $decoded;
                    return self::$appleCatalog;
                }
            }
        }

        self::$appleCatalog = [];
        return self::$appleCatalog;
    }

    private static function getSqliteDb(): ?PDO
    {
        if (self::$sqliteDb !== null) return self::$sqliteDb;

        if (file_exists(self::SQLITE_PATH)) {
            try {
                self::$sqliteDb = new PDO('sqlite:' . self::SQLITE_PATH);
                self::$sqliteDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                return self::$sqliteDb;
            } catch (Throwable $e) {
                error_log('[DeviceDetector] SQLite open failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    private static function formatDisplayName(string $brand, string $model, string $code, string $osName, string $type): string
    {
        if ($model !== '') {
            if ($brand !== '' && ! str_starts_with(strtolower($model), strtolower($brand))) {
                return "{$brand} {$model}";
            }
            return $model;
        }

        if ($osName !== '') {
            return "{$osName} {$type}";
        }

        return 'Personal Device';
    }
}
