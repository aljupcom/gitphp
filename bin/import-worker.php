<?php

declare(strict_types=1);

/**
 * GitPHP — background import worker (CLI fast path).
 *
 * Usage: php bin/import-worker.php <job-file>
 *
 * Thin wrapper around App\Service\ImportPipeline. Hosts that cannot spawn
 * detached processes fall back to the web self-runner
 * (GET /admin/repos/import/run) which executes the exact same pipeline —
 * see RepoImportController::run().
 */

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Service\ImportPipeline;

$jobFile = $argv[1] ?? '';

if ($jobFile === '' || ! is_file($jobFile)) {
    fwrite(STDERR, "Job file missing.\n");
    exit(1);
}

$app = null;

try {
    $app = App::boot(dirname(__DIR__));
} catch (Throwable $bootError) {
    // Publish the failure so the polling UI never waits forever.
    $id   = preg_replace('/[^a-f0-9]/', '', basename($jobFile, '.job'));
    $path = dirname(__DIR__) . '/storage/tmp/import-' . $id . '.json';
    if ($id !== '' && is_dir(dirname($path))) {
        @file_put_contents($path, json_encode([
            'phase' => 'failed', 'percent' => 0, 'message' => 'Worker boot failed',
            'detail' => '', 'log' => [], 'ok' => false, 'done' => true,
            'error' => 'Worker could not boot: ' . $bootError->getMessage(),
            'repo_url' => null, 'summary' => null, 'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES));
    }
    fwrite(STDERR, 'Boot failed: ' . $bootError->getMessage() . "\n");
    exit(1);
}

$raw = (string) file_get_contents($jobFile);
$job = json_decode($raw, true);

// Tolerate a UTF-8 BOM (some Windows tooling writes one)
if (! is_array($job)) {
    $job = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw, true);
}

if (! is_array($job) || ! isset($job['id'], $job['slug'], $job['url'])) {
    fwrite(STDERR, "Invalid job payload.\n");
    exit(1);
}

$final = (new ImportPipeline($app))->execute($job);

// The job input carries the access token — it never outlives the run.
@unlink($jobFile);

exit(empty($final['ok']) ? 1 : 0);
