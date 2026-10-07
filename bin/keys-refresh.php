<?php

declare(strict_types=1);

/**
 * GitPHP — regenerate the SSH authorized_keys file from the database.
 *
 * Usage: php bin/keys-refresh.php
 *
 * Run this on the server after changing AUTHORIZED_KEYS_PATH in .env,
 * after restoring a database, or whenever the UI save failed due to
 * filesystem permissions. Idempotent — safe to run any time.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Service\SshKeyService;

$app = App::boot(dirname(__DIR__));

$path = (string) (getenv('AUTHORIZED_KEYS_PATH') ?: ($_ENV['AUTHORIZED_KEYS_PATH'] ?? '/home/git/.ssh/authorized_keys'));

echo "Authorized keys path: {$path}\n";

$service = new SshKeyService($app->db());

try {
    $service->regenerateAuthorizedKeys();
    $count = count($app->db()->fetchAll('SELECT id FROM ssh_keys'));
    echo "OK — {$count} key(s) written.\n";

    if (function_exists('posix_getpwuid')) {
        $owner = posix_getpwuid(fileowner($path))['name'] ?? '?';
        echo "File owner: {$owner} (must be readable by the 'git' user)\n";
    }
    echo "If the git user cannot read it, run on the server:\n";
    echo "  install -m 600 -o git -g git {$path} /home/git/.ssh/authorized_keys\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
