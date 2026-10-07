<?php

declare(strict_types=1);

/**
 * GitPHP — refresh git hooks on every repository.
 *
 * Copies the current templates from hooks/ (post-receive, pre-receive)
 * into each bare repo under REPOS_PATH. Run after upgrading GitPHP so
 * existing repositories pick up new hook behaviour:
 *
 *   php bin/hooks-refresh.php
 */

use App\Service\GitService;

require_once __DIR__ . '/../vendor/autoload.php';

$app  = \App\App::boot(dirname(__DIR__));
$git  = new GitService();
$path = $git->getReposPath();

if (! is_dir($path)) {
    echo "Repos path not found: {$path}\n";
    exit(1);
}

$count = 0;
foreach (scandir($path) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (! str_ends_with($entry, '.git')) continue;

    $slug = substr($entry, 0, -4);

    try {
        $git->installHooks($slug);
        echo "refreshed {$slug}\n";
        $count++;
    } catch (Throwable $e) {
        echo "FAILED   {$slug}: {$e->getMessage()}\n";
    }
}

echo "\nDone: {$count} repositories updated.\n";
