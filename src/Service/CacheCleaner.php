<?php

declare(strict_types=1);

namespace App\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Smart Cache & Storage Cleanup Engine with Threshold-Based Auto-Pruning.
 *
 * Intelligently protects storage by monitoring cache directory sizes,
 * evicting expired items, pruning stale templates, and maintaining high/low
 * watermarks to avoid disk bloat and disk thrashing.
 */
final class CacheCleaner
{
    private string $basePath;
    private string $cachePath;
    private string $twigCachePath;
    private string $sessionsPath;
    private string $tmpPath;

    public function __construct(string $basePath)
    {
        $this->basePath      = rtrim($basePath, '/\\');
        $this->cachePath     = $this->basePath . '/storage/cache';
        $this->twigCachePath = $this->cachePath . '/twig';
        $this->sessionsPath  = $this->basePath . '/storage/sessions';
        $this->tmpPath       = $this->basePath . '/storage/tmp';
    }

    /**
     * Check if total cache storage exceeds a given megabyte threshold.
     */
    public function isOverThreshold(int $maxCacheSizeMb): bool
    {
        $currentBytes = $this->getDirectorySize($this->cachePath);
        return $currentBytes >= ($maxCacheSizeMb * 1024 * 1024);
    }

    /**
     * Perform smart non-destructive cleanup:
     * - Prune expired *.cache files (reads TTL header)
     * - Prune stale compiled Twig templates older than $maxTwigAgeHours
     * - Prune stale session files older than $maxSessionAgeHours
     * - Prune abandoned temporary files older than $maxTempAgeHours
     * - Enforce maximum total cache size with hysteresis watermark (evicts oldest items down to $watermarkPercent)
     */
    public function smartClean(
        int $maxTwigAgeHours = 48,
        int $maxSessionAgeHours = 72,
        int $maxTempAgeHours = 1,
        int $maxCacheSizeMb = 50,
        int $watermarkPercent = 80
    ): array {
        $stats = [
            'expired_cache_deleted' => 0,
            'twig_deleted'          => 0,
            'sessions_deleted'      => 0,
            'temp_deleted'          => 0,
            'evicted_by_size'       => 0,
            'bytes_freed'           => 0,
        ];

        // 1. Prune expired .cache files
        $cacheResult = $this->cleanExpiredCacheFiles();
        $stats['expired_cache_deleted'] += $cacheResult['count'];
        $stats['bytes_freed']           += $cacheResult['bytes'];

        // 2. Prune old Twig compiled templates
        $twigResult = $this->cleanOldFiles($this->twigCachePath, $maxTwigAgeHours * 3600);
        $stats['twig_deleted'] += $twigResult['count'];
        $stats['bytes_freed']  += $twigResult['bytes'];

        // 3. Prune old sessions
        $sessionResult = $this->cleanOldFiles($this->sessionsPath, $maxSessionAgeHours * 3600, 'sess_');
        $stats['sessions_deleted'] += $sessionResult['count'];
        $stats['bytes_freed']      += $sessionResult['bytes'];

        // 4. Prune temporary files (storage/tmp and /tmp/git_*)
        $tempResult = $this->cleanOldFiles($this->tmpPath, $maxTempAgeHours * 3600);
        $stats['temp_deleted'] += $tempResult['count'];
        $stats['bytes_freed']  += $tempResult['bytes'];

        $gitTempResult = $this->cleanGitTempFiles($maxTempAgeHours * 3600);
        $stats['temp_deleted'] += $gitTempResult['count'];
        $stats['bytes_freed']  += $gitTempResult['bytes'];

        // 5. Rotate application logs: delete entries older than 30 days and
        //    cap any single log file at 50 MB (oldest-first truncation).
        $logResult = $this->rotateLogs(30, 50);
        $stats['logs_deleted']     = $logResult['files_deleted'];
        $stats['logs_truncated']   = $logResult['files_truncated'];
        $stats['bytes_freed']     += $logResult['bytes'];

        // 6. Enforce maximum cache threshold with low-watermark hysteresis
        $maxBytes    = $maxCacheSizeMb * 1024 * 1024;
        $targetBytes = (int) ($maxBytes * (max(10, min(95, $watermarkPercent)) / 100));
        $enforceResult = $this->enforceMaxCacheSize($maxBytes, $targetBytes);

        $stats['evicted_by_size'] += $enforceResult['count'];
        $stats['bytes_freed']     += $enforceResult['bytes'];

        $stats['formatted_bytes_freed'] = $this->formatBytes($stats['bytes_freed']);
        $stats['current_cache_size']     = $this->formatBytes($this->getDirectorySize($this->cachePath));
        $stats['threshold_enforced']    = $enforceResult['triggered'];

        return $stats;
    }

    /**
     * Rotate application logs in storage/logs:
     *   - app-YYYY-MM-DD.log files older than $maxAgeDays are deleted;
     *   - any single log larger than $maxFileMb MB is truncated to the
     *     last half of the file (keep newest entries, drop oldest).
     *
     * @return array{files_deleted:int, files_truncated:int, bytes:int}
     */
    public function rotateLogs(int $maxAgeDays = 30, int $maxFileMb = 50): array
    {
        $stats = ['files_deleted' => 0, 'files_truncated' => 0, 'bytes' => 0];
        $logsDir = $this->basePath . '/storage/logs';

        if (! is_dir($logsDir) || ! is_writable($logsDir)) {
            return $stats;
        }

        $cutoff = time() - ($maxAgeDays * 86400);
        $maxBytes = max(1, $maxFileMb) * 1048576;

        // Date-named app logs: delete by age.
        foreach (glob($logsDir . '/app-*.log') ?: [] as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                if (strtotime($m[1]) === false) continue;
                if (strtotime($m[1]) < $cutoff) {
                    $size = (int) @filesize($file);
                    if (@unlink($file)) {
                        $stats['files_deleted']++;
                        $stats['bytes'] += $size;
                    }
                }
            }
        }

        // Oversized logs (any name): keep the newest half of the content.
        foreach (glob($logsDir . '/*.log') ?: [] as $file) {
            clearstatcache(true, $file);
            $size = (int) @filesize($file);
            if ($size <= $maxBytes) continue;

            // Only act on one oversized file per run to bound the cleanup cost.
            $fh = @fopen($file, 'rb');
            if ($fh === false) continue;

            fseek($fh, (int) ($size / 2));
            $keep = (string) stream_get_contents($fh);
            fclose($fh);

            $tmp = $file . '.rotating';
            if (@file_put_contents($tmp, $keep) !== false) {
                @unlink($file);
                @rename($tmp, $file);
                clearstatcache(true, $file);
                $stats['files_truncated']++;
                $stats['bytes'] += max(0, $size - (int) @filesize($file));
            }

            break;
        }

        return $stats;
    }

    /**
     * Complete wipe of all cache and compiled templates.
     */
    public function fullFlush(bool $includeSessions = false): array
    {
        $stats = [
            'cache_deleted'    => 0,
            'twig_deleted'     => 0,
            'sessions_deleted' => 0,
            'bytes_freed'      => 0,
        ];

        // Wipe .cache files
        if (is_dir($this->cachePath)) {
            foreach (glob($this->cachePath . '/*.cache') ?: [] as $f) {
                if (is_file($f)) {
                    $size = (int) @filesize($f);
                    if (@unlink($f)) {
                        $stats['cache_deleted']++;
                        $stats['bytes_freed'] += $size;
                    }
                }
            }
        }

        // Wipe Twig cache
        $twigResult = $this->deleteDirectoryRecursive($this->twigCachePath);
        $stats['twig_deleted'] += $twigResult['count'];
        $stats['bytes_freed']  += $twigResult['bytes'];

        if ($includeSessions && is_dir($this->sessionsPath)) {
            foreach (glob($this->sessionsPath . '/sess_*') ?: [] as $f) {
                if (is_file($f)) {
                    $size = (int) @filesize($f);
                    if (@unlink($f)) {
                        $stats['sessions_deleted']++;
                        $stats['bytes_freed'] += $size;
                    }
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $stats['formatted_bytes_freed'] = $this->formatBytes($stats['bytes_freed']);
        $stats['current_cache_size']     = $this->formatBytes($this->getDirectorySize($this->cachePath));

        return $stats;
    }

    /** Inspect expiry header of .cache files and delete expired ones. */
    private function cleanExpiredCacheFiles(): array
    {
        $count = 0;
        $bytes = 0;
        $now   = time();

        if (!is_dir($this->cachePath)) {
            return ['count' => 0, 'bytes' => 0];
        }

        foreach (glob($this->cachePath . '/*.cache') ?: [] as $file) {
            if (!is_file($file)) continue;

            $fp = @fopen($file, 'rb');
            if (!$fp) continue;

            $header = fgets($fp, 512);
            if ($header === false) {
                fclose($fp);
                continue;
            }

            $expiryBytes = fread($fp, 10);
            fclose($fp);

            if (strlen((string) $expiryBytes) === 10 && ctype_digit($expiryBytes)) {
                $expires = (int) $expiryBytes;
                if ($expires > 0 && $expires < $now) {
                    $size = (int) @filesize($file);
                    if (@unlink($file)) {
                        $count++;
                        $bytes += $size;
                    }
                }
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /** Clean files older than a specified duration in seconds. */
    private function cleanOldFiles(string $dir, int $maxAgeSeconds, string $prefix = ''): array
    {
        $count = 0;
        $bytes = 0;
        $cutoff = time() - $maxAgeSeconds;

        if (!is_dir($dir)) return ['count' => 0, 'bytes' => 0];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $filename = $item->getFilename();
                    if ($prefix !== '' && !str_starts_with($filename, $prefix)) {
                        continue;
                    }
                    if ($item->getMTime() < $cutoff) {
                        $size = $item->getSize();
                        if (@unlink($item->getRealPath())) {
                            $count++;
                            $bytes += $size;
                        }
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getRealPath());
                }
            }
        } catch (Throwable) {}

        return ['count' => $count, 'bytes' => $bytes];
    }

    /** Clean stranded git temporary files from sys_get_temp_dir() */
    private function cleanGitTempFiles(int $maxAgeSeconds): array
    {
        $count = 0;
        $bytes = 0;
        $cutoff = time() - $maxAgeSeconds;
        $tempDir = sys_get_temp_dir();

        foreach (['git_out_*', 'git_err_*'] as $pattern) {
            foreach (glob($tempDir . '/' . $pattern) ?: [] as $file) {
                if (is_file($file) && @filemtime($file) < $cutoff) {
                    $size = (int) @filesize($file);
                    if (@unlink($file)) {
                        $count++;
                        $bytes += $size;
                    }
                }
            }
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * Multi-stage threshold enforcement:
     * When total cache size exceeds $maxBytes (high watermark), prunes down
     * to $targetBytes (low watermark) by evicting:
     *   1) Stale Twig template files (older than 12h)
     *   2) Oldest application cache files (LRU / FIFO)
     *   3) Remaining older Twig template files if still needed.
     */
    public function enforceMaxCacheSize(int $maxBytes, ?int $targetBytes = null): array
    {
        $count = 0;
        $bytes = 0;
        $totalSize = $this->getDirectorySize($this->cachePath);

        if ($totalSize <= $maxBytes) {
            return ['count' => 0, 'bytes' => 0, 'triggered' => false];
        }

        $target = $targetBytes ?? (int) ($maxBytes * 0.80);

        // Stage 1: If Twig cache is using significant space, prune templates older than 12h
        $twigSize = $this->getDirectorySize($this->twigCachePath);
        if ($twigSize > ($target * 0.35)) {
            $twigClean = $this->cleanOldFiles($this->twigCachePath, 12 * 3600);
            $count += $twigClean['count'];
            $bytes += $twigClean['bytes'];
            $totalSize -= $twigClean['bytes'];
        }

        // Stage 2: Evict oldest application *.cache files (LRU)
        if ($totalSize > $target) {
            $cacheFiles = glob($this->cachePath . '/*.cache') ?: [];
            $fileTimes = [];

            foreach ($cacheFiles as $f) {
                if (is_file($f)) {
                    $fileTimes[$f] = @filemtime($f) ?: 0;
                }
            }

            asort($fileTimes);

            foreach ($fileTimes as $f => $mtime) {
                if ($totalSize <= $target) break;

                $size = (int) @filesize($f);
                if (@unlink($f)) {
                    $count++;
                    $bytes += $size;
                    $totalSize -= $size;
                }
            }
        }

        // Stage 3: If still over target, prune Twig templates older than 1h
        if ($totalSize > $target) {
            $twigClean = $this->cleanOldFiles($this->twigCachePath, 3600);
            $count += $twigClean['count'];
            $bytes += $twigClean['bytes'];
            $totalSize -= $twigClean['bytes'];
        }

        return ['count' => $count, 'bytes' => $bytes, 'triggered' => true];
    }

    private function deleteDirectoryRecursive(string $dir): array
    {
        $count = 0;
        $bytes = 0;

        if (!is_dir($dir)) return ['count' => 0, 'bytes' => 0];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $size = $item->getSize();
                    if (@unlink($item->getRealPath())) {
                        $count++;
                        $bytes += $size;
                    }
                } elseif ($item->isDir()) {
                    @rmdir($item->getRealPath());
                }
            }
            @rmdir($dir);
        } catch (Throwable) {}

        return ['count' => $count, 'bytes' => $bytes];
    }

    public function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) return 0;
        $size = 0;
        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        } catch (Throwable) {}
        return $size;
    }

    public function getStorageBreakdown(): array
    {
        $cacheSize    = $this->getDirectorySize($this->cachePath);
        $twigSize     = $this->getDirectorySize($this->twigCachePath);
        $sessionsSize = $this->getDirectorySize($this->sessionsPath);
        $tmpSize      = $this->getDirectorySize($this->tmpPath);

        return [
            'cache_bytes'         => $cacheSize,
            'cache_formatted'     => $this->formatBytes($cacheSize),
            'twig_bytes'          => $twigSize,
            'twig_formatted'      => $this->formatBytes($twigSize),
            'sessions_bytes'      => $sessionsSize,
            'sessions_formatted'  => $this->formatBytes($sessionsSize),
            'tmp_bytes'           => $tmpSize,
            'tmp_formatted'       => $this->formatBytes($tmpSize),
            'total_bytes'         => ($cacheSize + $sessionsSize + $tmpSize),
            'total_formatted'     => $this->formatBytes($cacheSize + $sessionsSize + $tmpSize),
        ];
    }

    public function formatBytes(float|int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int) floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }
}