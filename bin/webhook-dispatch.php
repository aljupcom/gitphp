<?php

declare(strict_types=1);

/**
 * GitPHP — webhook dispatcher (CLI, invoked by hooks/post-receive).
 *
 * Usage: php bin/webhook-dispatch.php <slug> <event> <payload-json-file>
 *
 * Boots the application just enough to reach the database and the
 * WebhookService, delivers the payload to every subscribed webhook,
 * then removes the temp payload file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$slug   = $argv[1] ?? '';
$event  = $argv[2] ?? '';
$file   = $argv[3] ?? '';

register_shutdown_function(static function () use ($file): void {
    if ($file !== '' && is_file($file) && str_contains(basename($file), 'gitphp_hook_')) {
        @unlink($file);
    }
});

if ($slug === '' || $event === '' || $file === '' || !is_file($file)) {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app      = \App\App::boot(dirname(__DIR__));
    $service  = new \App\Service\WebhookService($app);
    $contents = file_get_contents($file);
    $payload  = $contents !== false ? json_decode($contents, true) : null;
    $service->dispatch($slug, $event, is_array($payload) ? $payload : []);
} catch (Throwable $e) {
    error_log('[webhook-dispatch] ' . $e->getMessage());
    exit(0); // Never break a push.
}
