#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GitPHP Smart Cache & Storage Cleaner CLI
 *
 * Usage:
 *   php bin/clean-cache.php                     # Smart cleanup using configured threshold
 *   php bin/clean-cache.php --status            # Show storage breakdown without deleting
 *   php bin/clean-cache.php --threshold=30      # Enforce custom threshold (e.g. 30MB)
 *   php bin/clean-cache.php --watermark=75      # Enforce custom low watermark percentage
 *   php bin/clean-cache.php --all               # Full cache wipe
 */

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run composer install.\n");
    exit(1);
}

require_once $autoload;

// Load environment variables if not loaded
$envFile = $basePath . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\n\r\0\x0B\"'"));
    }
}

$defaultMaxMb = (int) (getenv('CACHE_MAX_SIZE_MB') ?: 50);
$defaultWatermark = (int) (getenv('CACHE_PRUNE_WATERMARK') ?: 80);

$mode = 'smart';
$maxMb = $defaultMaxMb;
$watermark = $defaultWatermark;

foreach ($argv as $arg) {
    if ($arg === '--all') {
        $mode = 'all';
    } elseif ($arg === '--status') {
        $mode = 'status';
    } elseif (str_starts_with($arg, '--threshold=')) {
        $maxMb = max(1, (int) substr($arg, 12));
    } elseif (str_starts_with($arg, '--watermark=')) {
        $watermark = max(10, min(95, (int) substr($arg, 12)));
    }
}

$cleaner = new \App\Service\CacheCleaner($basePath);

echo "=====================================================\n";
echo "   GitPHP Smart Cache & Storage Cleanup System       \n";
echo "=====================================================\n";

if ($mode === 'status') {
    $breakdown = $cleaner->getStorageBreakdown();
    echo "Storage Breakdown:\n";
    echo " - File Cache:              {$breakdown['cache_formatted']}\n";
    echo " - Compiled Twig Templates: {$breakdown['twig_formatted']}\n";
    echo " - Session Files:           {$breakdown['sessions_formatted']}\n";
    echo " - Temporary Files:         {$breakdown['tmp_formatted']}\n";
    echo "-----------------------------------------------------\n";
    echo " Total Managed Storage:     {$breakdown['total_formatted']}\n";
    echo " Configured Threshold:      {$maxMb} MB (Watermark: {$watermark}%)\n";
    echo " Over Threshold Status:     " . ($cleaner->isOverThreshold($maxMb) ? "YES (Cleanup Needed)" : "NO (Healthy)") . "\n";
} elseif ($mode === 'all') {
    echo "Mode: FULL FLUSH (Purging all application & template cache)\n\n";
    $result = $cleaner->fullFlush(includeSessions: false);
    echo " - Cache files removed:     {$result['cache_deleted']}\n";
    echo " - Twig templates removed:  {$result['twig_deleted']}\n";
    echo " - Sessions removed:        {$result['sessions_deleted']}\n";
    echo "-----------------------------------------------------\n";
    echo " Total space freed:         {$result['formatted_bytes_freed']}\n";
    echo " Current cache folder size: {$result['current_cache_size']}\n";
    echo " Status: Full flush completed successfully.\n";
} else {
    echo "Mode: SMART CLEANUP (Threshold: {$maxMb} MB | Watermark: {$watermark}%)\n\n";
    $result = $cleaner->smartClean(
        maxTwigAgeHours: 48,
        maxSessionAgeHours: 72,
        maxTempAgeHours: 1,
        maxCacheSizeMb: $maxMb,
        watermarkPercent: $watermark
    );
    echo " - Expired cache entries removed: {$result['expired_cache_deleted']}\n";
    echo " - Stale Twig templates removed:  {$result['twig_deleted']}\n";
    echo " - Inactive sessions purged:      {$result['sessions_deleted']}\n";
    echo " - Temporary files removed:       {$result['temp_deleted']}\n";
    echo " - Evicted by size threshold:     {$result['evicted_by_size']}\n";
    echo "-----------------------------------------------------\n";
    echo " Total space freed:               {$result['formatted_bytes_freed']}\n";
    echo " Current cache folder size:       {$result['current_cache_size']}\n";
    echo " Threshold Enforced:              " . ($result['threshold_enforced'] ? "YES" : "NO (Already within limits)") . "\n";
    echo " Status: Smart cleanup completed successfully.\n";
}

echo "=====================================================\n";