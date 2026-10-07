<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\GitService;
use App\Service\ImportPipeline;
use App\Service\RepoImporter;
use InvalidArgumentException;
use Throwable;

final class RepoImportController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private RepoImporter $importer;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitService = new GitService();
        $this->importer   = new RepoImporter();
    }

    /** Admin nav counters cached for 60s to avoid 4 COUNT(*) queries per page load. */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }


    /** GET /repos/import or /admin/repos/import — show the import form. */
    public function form(): void
    {
        $this->auth->requireAuth();

        $isOwner   = $this->auth->isOwner();
        $ownerName = $this->auth->displayName();

        $this->app->view()->display('admin/repos/import.twig', [
            'csrf_token'   => $this->auth->generateCsrf(),
            'old_url'      => $_SESSION['flash_old_url'] ?? '',
            'owner'        => $ownerName,
            'is_owner'     => $isOwner,
            'admin_prefix' => $this->auth->adminPrefix(),
            'nav_counts'   => $isOwner ? $this->getNavCounts() : [],
        ]);

        unset($_SESSION['flash_old_url']);
    }

    /** GET /repos/import/lookup or /admin/repos/import/lookup — JSON metadata lookup for auto-fill. */
    public function lookup(): void
    {
        $this->auth->requireAuth();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $json = static fn(array $payload): never => exit((string) json_encode($payload));

        $url   = trim((string) ($_GET['url'] ?? ''));
        $token = trim((string) ($_GET['token'] ?? ''));

        if ($url === '') {
            $json(['ok' => false, 'error' => 'Please provide a repository URL.']);
        }

        try {
            $info = $this->importer->parseRemoteUrl($url);
        } catch (Throwable $e) {
            $json(['ok' => false, 'error' => $e->getMessage()]);
        }

        try {
            $meta = $this->importer->fetchRemoteMetadata($url, $token);
            $json(['ok' => true, 'is_partial' => false, 'meta' => $meta]);
        } catch (Throwable $e) {
            $fallbackMeta = [
                'name'           => $info['name'],
                'slug'           => $info['slug'],
                'description'    => '',
                'default_branch' => 'main',
                'stars_count'    => 0,
                'homepage'       => '',
                'topics'         => '',
            ];

            $json([
                'ok'         => true,
                'is_partial' => true,
                'error'      => $e->getMessage(),
                'meta'       => $fallbackMeta,
            ]);
        }
    }

    /** POST /repos/import or /admin/repos/import — kick off an asynchronous mirror job. */
    public function import(): void
    {
        $this->auth->requireAuth();

        $isOwner   = $this->auth->isOwner();
        $ownerName = $this->auth->displayName();
        $ownerId   = $isOwner ? null : $this->auth->userId();

        $isXhr = (
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        );

        if (! $this->auth->validateCsrf()) {
            if ($isXhr) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => false, 'error' => 'Invalid security token.']));
            }
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . ($isOwner ? '/admin/repos/import' : '/repos/import'));
            exit;
        }

        $sourceUrl   = trim((string) ($_POST['source_url'] ?? ''));
        $accessToken = trim((string) ($_POST['access_token'] ?? ''));
        $name        = trim((string) ($_POST['name'] ?? ''));
        $slug        = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $description = trim((string) ($_POST['description'] ?? ''));
        $visibility  = in_array($_POST['visibility'] ?? 'public', ['public', 'private'], true)
            ? (string) $_POST['visibility']
            : 'public';

        $progressPrefix = '/repos/import';

        $fail = function (string $message) use ($sourceUrl, $isXhr, $progressPrefix): never {
            if ($isXhr) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['ok' => false, 'error' => $message]));
            }
            $_SESSION['flash_error']   = $message;
            $_SESSION['flash_old_url'] = $sourceUrl;
            header('Location: ' . $progressPrefix);
            exit;
        };

        // Enforce global limits & quotas for non-owner imports
        if (! $this->auth->isOwner()) {
            $userRole = (string) ($_SESSION['user']['role'] ?? 'user');
            if ($userRole === 'restricted' || $userRole === 'bot') {
                $fail('Your account role is not permitted to import repositories.');
            }

            $limitsRows = $this->app->db()->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('max_repos_per_user', 'max_private_repos_per_user', 'allow_public_repo_creation', 'allow_private_repo_creation')");
            $policy = [];
            foreach ($limitsRows as $lr) {
                $policy[$lr['key_name']] = (string) $lr['value'];
            }

            if ($visibility === 'public' && ($policy['allow_public_repo_creation'] ?? '1') === '0') {
                $fail('Public repository creation is currently disabled by system policy.');
            }
            if ($visibility === 'private' && ($policy['allow_private_repo_creation'] ?? '1') === '0') {
                $fail('Private repository creation is currently disabled by system policy.');
            }

            $currentUserId = (int) ($this->auth->userId() ?? 0);
            if ($currentUserId > 0) {
                $maxTotal = (int) ($policy['max_repos_per_user'] ?? 50);
                if ($maxTotal > 0) {
                    $cnt = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE owner_user_id = :uid', ['uid' => $currentUserId])['c'] ?? 0);
                    if ($cnt >= $maxTotal) {
                        $fail("Repository quota limit reached. Maximum allowed per user is {$maxTotal}.");
                    }
                }

                $maxPrivate = (int) ($policy['max_private_repos_per_user'] ?? 10);
                if ($visibility === 'private' && $maxPrivate > 0) {
                    $cntPriv = (int) ($this->app->db()->fetchOne('SELECT COUNT(*) AS c FROM repositories WHERE owner_user_id = :uid AND visibility = "private"', ['uid' => $currentUserId])['c'] ?? 0);
                    if ($cntPriv >= $maxPrivate) {
                        $fail("Private repository quota limit reached. Maximum allowed private repositories is {$maxPrivate}.");
                    }
                }
            }
        }

        // 1. Validate the URL (platform allow-list, shape, scheme)
        try {
            $info = $this->importer->parseRemoteUrl($sourceUrl);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }

        if ($accessToken === '' && ($info['embedded_token'] ?? '') !== '') {
            $accessToken = (string) $info['embedded_token'];
        }

        if ($name === '') $name = $info['name'];
        if ($slug === '') $slug = $info['slug'];

        // Same slug policy as manual creation
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*[a-z0-9]$/', $slug) && !preg_match('/^[a-z0-9]$/', $slug)) {
            $fail('Slug must be lowercase alphanumeric with hyphens only.');
        }

        // Uniqueness (DB record and on-disk bare repo)
        $existing = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($existing !== false || $this->gitService->repoExists($slug)) {
            $fail("A repository with the slug '{$slug}' already exists. Choose a different slug.");
        }

        // 2. Create the job (id + status + payload file with the token)
        $jobId   = bin2hex(random_bytes(8));
        $tmpDir  = $this->app->basePath('storage/tmp');
        if (! is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $jobFile = $tmpDir . '/import-' . $jobId . '.job';
        $statusF = $tmpDir . '/import-' . $jobId . '.json';

        $job = [
            'id'            => $jobId,
            'slug'          => $slug,
            'name'          => $name,
            'description'   => $description,
            'visibility'    => $visibility,
            'url'           => $info['url'],
            'platform'      => $info['platform'],
            'token'         => $accessToken,
            'owner'         => $ownerName,
            'owner_user_id' => $ownerId,
        ];

        if (@file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            $fail('Could not create the import job (storage/tmp not writable?).');
        }
        @chmod($jobFile, 0600);

        // Initial status so the first poll never 404s
        @file_put_contents($statusF, json_encode([
            'id' => $jobId, 'slug' => $slug, 'name' => $name,
            'phase' => 'queued', 'percent' => 0, 'message' => 'Queued',
            'detail' => '', 'log' => [], 'ok' => false, 'done' => false,
            'error' => null, 'repo_url' => null, 'summary' => null,
            'updated_at' => time(),
        ], JSON_UNESCAPED_SLASHES));

        // 3. Launch the fully detached CLI worker in the background
        $spawned = false;
        $phpBin = is_executable('/usr/bin/php') ? '/usr/bin/php' : (PHP_BINARY ?: 'php');
        $workerScript = $this->app->basePath('bin/import-worker.php');

        if (function_exists('proc_open')) {
            try {
                $descriptors = [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ];
                $cmd = 'nohup ' . escapeshellcmd($phpBin) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($jobFile) . ' > /dev/null 2>&1 &';
                $pipes = [];
                $proc = @proc_open($cmd, $descriptors, $pipes, $this->app->basePath(), ['GIT_TERMINAL_PROMPT' => '0']);
                if (is_resource($proc)) {
                    @proc_close($proc);
                    $spawned = true;
                }
            } catch (Throwable) {
                $spawned = false;
            }
        }

        if (! $spawned && function_exists('exec')) {
            try {
                $cmd = 'nohup ' . escapeshellcmd($phpBin) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($jobFile) . ' > /dev/null 2>&1 &';
                @exec($cmd);
                $spawned = true;
            } catch (Throwable) {}
        }

        // 4. Respond immediately — always redirect to user repos import progress
        if ($isXhr) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => true, 'job' => $jobId, 'slug' => $slug, 'spawned' => $spawned]));
        }

        header('Location: /repos/import/progress?id=' . urlencode($jobId));
        exit;
    }

    /**
     * GET /repos/import/run or /admin/repos/import/run — web self-runner.
     */
    public function run(): void
    {
        $this->auth->requireAuth();

        @ignore_user_abort(true);
        @set_time_limit(0);
        while (ob_get_level() > 0) { @ob_end_flush(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');

        $jobId   = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['id'] ?? ''));
        $tmpDir  = $this->app->basePath('storage/tmp');
        $jobFile = $tmpDir . '/import-' . $jobId . '.job';

        if ($jobId === '' || ! is_file($jobFile)) {
            exit(json_encode(['ok' => false, 'error' => 'Unknown import job.']));
        }

        $lockFile = $tmpDir . '/import-' . $jobId . '.lock';
        $lockFp   = fopen($lockFile, 'c');

        if ($lockFp === false || ! flock($lockFp, LOCK_EX | LOCK_NB)) {
            exit(json_encode(['ok' => true, 'note' => 'already running']));
        }

        // Release the session so progress polls are never blocked.
        @session_write_close();

        $job = json_decode((string) file_get_contents($jobFile), true);
        if (! is_array($job) || ! isset($job['id'])) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            exit(json_encode(['ok' => false, 'error' => 'Corrupt job payload.']));
        }

        $statusF = $tmpDir . '/import-' . $jobId . '.json';
        $final   = (new ImportPipeline($this->app))->execute($job);

        @unlink($jobFile);
        flock($lockFp, LOCK_UN);
        fclose($lockFp);

        exit(json_encode(['ok' => (bool) ($final['ok'] ?? false)]));
    }

    /**
     * GET /repos/import/progress or /admin/repos/import/progress — live import status.
     */
    public function progress(): void
    {
        $this->auth->requireAuth();

        $jobId   = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['id'] ?? ''));
        $statusF = $this->app->basePath('storage/tmp/import-' . $jobId . '.json');

        $isXhr = (
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        );

        $status = is_file($statusF)
            ? json_decode((string) file_get_contents($statusF), true)
            : null;

        if ($isXhr) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');

            if (! is_array($status)) {
                http_response_code(404);
                exit(json_encode(['ok' => false, 'done' => true, 'error' => 'Unknown or expired import job.']));
            }

            exit(json_encode(['ok' => true] + $status));
        }

        $this->app->view()->display('admin/repos/import-progress.twig', [
            'csrf_token' => $this->auth->generateCsrf(),
            'job_id'     => $jobId,
            'status'     => is_array($status) ? $status : null,
            'is_owner'   => $this->auth->isOwner(),
        ]);
    }
}
