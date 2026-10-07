<?php

declare(strict_types=1);

return [
    'name'  => env('APP_NAME', 'GitPHP'),
    'url'   => rtrim(env('APP_URL', 'http://localhost'), '/'),
    'owner' => env('APP_OWNER', 'admin'),

    // UI theme: a subdirectory of /templates. "legacy" selects the bare
    // templates root (original terminal theme); "github" is the default.
    'theme' => env('APP_THEME', 'github'),

    'repos_path' => env('REPOS_PATH', __DIR__ . '/../repos'),

    'timezone' => env('APP_TIMEZONE', 'Asia/Riyadh'),

    'debug' => (bool) env('APP_DEBUG', false),

    // Smart Cache auto-cleanup thresholds
    'cache_max_size_mb' => (int) env('CACHE_MAX_SIZE_MB', 50),
    'cache_watermark'   => (int) env('CACHE_PRUNE_WATERMARK', 80),
];
