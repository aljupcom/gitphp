<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Service\ApiTokenService;
use App\Service\GitService;

final class GitHttpController
{
    private App $app;
    private GitService $gitService;

    /** Authenticated pusher identity from the last requireBasicAuth() call. */
    private string $authenticatedUser = '';

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->gitService = new GitService();
    }

    /** GET /{user}/{repo}.git/info/refs?service=git-upload-pack|git-receive-pack */
    public function infoRefs(string $user, string $repo): void
    {
        $service = $_GET['service'] ?? '';

        if (! in_array($service, ['git-upload-pack', 'git-receive-pack'], true)) {
            http_response_code(400);
            echo 'Invalid service';
            return;
        }

        $slug = $this->extractSlug($repo);
        if ($slug === null) {
            http_response_code(404);
            echo 'Repository not found';
            return;
        }

        // For push (receive-pack): require authentication
        if ($service === 'git-receive-pack') {
            if (! $this->requireBasicAuth($slug, $service)) return;
        }

        // For clone/fetch (upload-pack): allow if repo is public
        if ($service === 'git-upload-pack') {
            if (! $this->isRepoPublic($slug)) {
                if (! $this->requireBasicAuth($slug, $service)) return;
            }
        }

        $repoPath = $this->gitService->getRepoPath($slug);

        // Disable output buffering for streaming
        while (ob_get_level()) ob_end_clean();

        ignore_user_abort(false);

        // Strip "git-" prefix: "git-upload-pack" -> "upload-pack"
        $gitCmd = substr($service, 4);

        header("Content-Type: application/x-{$service}-advertisement");
        header('Cache-Control: no-cache');

        // Send service announcement packet line
        echo $this->buildPacketLine("# service={$service}\n");
        echo '0000'; // flush packet

        $command = ['git', $gitCmd, '--stateless-rpc', '--advertise-refs', $repoPath];

        $this->streamGitProcess($repoPath, $command);
    }

    /** POST /{user}/{repo}.git/git-upload-pack */
    public function uploadPack(string $user, string $repo): void
    {
        $slug = $this->extractSlug($repo);
        if ($slug === null) {
            http_response_code(404);
            echo 'Repository not found';
            return;
        }

        // Allow public repos without auth; require auth for private repos
        if (! $this->isRepoPublic($slug)) {
            if (! $this->requireBasicAuth($slug, 'git-upload-pack')) return;
        }

        $repoPath = $this->gitService->getRepoPath($slug);

        while (ob_get_level()) ob_end_clean();

        ignore_user_abort(false);

        header('Content-Type: application/x-git-upload-pack-result');
        header('Cache-Control: no-cache');

        $input = file_get_contents('php://input');

        $this->streamGitProcess(
            $repoPath,
            ['git', 'upload-pack', '--stateless-rpc', $repoPath],
            $input !== false ? $input : null,
        );
    }

    /** POST /{user}/{repo}.git/git-receive-pack */
    public function receivePack(string $user, string $repo): void
    {
        $slug = $this->extractSlug($repo);
        if ($slug === null) {
            http_response_code(404);
            echo 'Repository not found';
            return;
        }

        if (! $this->requireBasicAuth($slug, 'git-receive-pack')) return;

        $repoPath = $this->gitService->getRepoPath($slug);

        // ── Single Repository Size Quota Check (repo_max_size_mb) ──
        $maxRepoMb = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'repo_max_size_mb'")['value'] ?? 2048);
        if ($maxRepoMb > 0) {
            $currRepoSize = $this->getDirSize($repoPath);
            if ($currRepoSize >= ($maxRepoMb * 1024 * 1024)) {
                $this->sendForbidden("Repository disk quota exceeded. Max allowed size is {$maxRepoMb} MB.");
                return;
            }
        }

        while (ob_get_level()) ob_end_clean();

        ignore_user_abort(false);

        header('Content-Type: application/x-git-receive-pack-result');
        header('Cache-Control: no-cache');

        $input = file_get_contents('php://input');

        // Expose the pusher identity to server-side hooks (pre-receive
        // uses it for admin-bypass on protected branches).
        $env = null;
        if ($this->authenticatedUser !== '') {
            $env = array_merge(getenv(), [
                'GITPHP_PUSH_USER' => $this->authenticatedUser,
            ]);
        }

        $this->streamGitProcess(
            $repoPath,
            ['git', 'receive-pack', '--stateless-rpc', $repoPath],
            $input !== false ? $input : null,
            $env,
        );
    }

    /** Extract the repository slug from the route parameter. */
    private function extractSlug(string $repo): ?string
    {
        // Strip .git suffix
        if (! str_ends_with($repo, '.git')) return null;

        $slug = substr($repo, 0, -4);

        if ($slug === '' || $slug === false) return null;

        // Verify the repo exists on disk
        // Verify the repo exists on disk
        try {
            if (! $this->gitService->repoExists($slug)) return null;
        } catch (\Throwable) {
            return null;
        }

        return $slug;
    }

    /**
     * Verify HTTP Basic Auth against the owner or a registered user, then
     * enforce per-repository permissions for the requested git service:
     *   - push (receive-pack): owner, or a collaborator with `write` role
     *   - private fetch (upload-pack): owner, or any collaborator
     */
    private function requireBasicAuth(string $slug, string $service): bool
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($username === null || $password === null) {
            $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
            $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? ($headers['Authorization'] ?? ($headers['authorization'] ?? ''));

            if ($authHeader === '' || ! str_starts_with($authHeader, 'Basic ')) {
                $this->sendUnauthorized();
                return false;
            }

            $decoded = base64_decode(substr($authHeader, 6), true);

            if ($decoded === false || ! str_contains($decoded, ':')) {
                $this->sendUnauthorized();
                return false;
            }

            [$username, $password] = explode(':', $decoded, 2);
        }

        $repoId = $this->getRepoId($slug);

        if ($repoId === null) {
            $this->sendUnauthorized();
            return false;
        }

                // ── Signed Capability Token authentication (gitsig_*) ────────
        $rawSig = str_starts_with((string) $password, 'gitsig_') ? (string) $password : (str_starts_with((string) $username, 'gitsig_') ? (string) $username : '');
        if ($rawSig !== '') {
            $sigService = new \App\Service\SignedTokenService($this->app);
            $action = ($service === 'git-receive-pack') ? 'push' : 'clone';
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
            $tokenUser = $sigService->verifyAndConsumeToken($rawSig, $action, $repoId, $clientIp);
            if ($tokenUser === null) {
                $this->sendUnauthorized();
                return false;
            }
            if ($service === 'git-receive-pack') {
                $this->authenticatedUser = (string) ($tokenUser['username'] ?? 'signed-token');
            }
            return true;
        }

        // ── API Token authentication (gtp_*) ─────────────────────────

        // The password field carries the raw token (gtp_<40hex>).
        // Username is irrelevant for token auth — any value is accepted.
        // Tokens have a `scopes` field: only 'write' tokens can push.
        if (str_starts_with((string) $password, 'gtp_')) {
            $tokenService = new ApiTokenService($this->app);
            try {
                $tokenData = $tokenService->authenticate((string) $password);
            } catch (\Throwable) {
                $this->sendUnauthorized();
                return false;
            }
            if ($tokenData === null) {
                $this->sendUnauthorized();
                return false;
            }
            // Push (receive-pack) requires a write-scoped token or owner identity.
            $isOwnerToken  = ((int) ($tokenData['user_id'] ?? -1) === 0);
            $scopeVal      = (string) ($tokenData['scopes'] ?? '');
            $hasWriteScope = $isOwnerToken
                           || str_contains($scopeVal, 'repo')
                           || str_contains($scopeVal, 'write')
                           || str_contains($scopeVal, 'all')
                           || str_contains($scopeVal, 'admin');

            if ($service === 'git-receive-pack' && ! $isOwnerToken && ! $hasWriteScope) {
                $this->sendUnauthorized();
                return false;
            }
            // user_id = 0 → owner token → full access to all repos.
            $ownerName = (string) $this->app->config('app.owner', 'admin');
            if ((int) $tokenData['user_id'] === 0) {
                $this->authenticatedUser = $ownerName;
                return true;
            }
            // Check user standing for token owner
            $tokenOwner = $this->app->db()->fetchOne(
                'SELECT `id`, `is_suspended`, `suspension_type`, `suspension_until` FROM `users` WHERE `id` = :id LIMIT 1',
                ['id' => (int) $tokenData['user_id']]
            );
            if ($tokenOwner !== false && ! empty($tokenOwner['is_suspended'])) {
                if (! empty($tokenOwner['suspension_until']) && strtotime((string) $tokenOwner['suspension_until']) <= time()) {
                    $this->app->db()->execute(
                        'UPDATE `users` SET `is_suspended` = 0, `suspension_type` = "none", `suspension_until` = NULL WHERE `id` = :id',
                        ['id' => (int) $tokenOwner['id']]
                    );
                } else {
                    if ($service === 'git-receive-pack' && ($tokenOwner['suspension_type'] ?? '') === 'read_only') {
                        $this->sendForbidden('Account is in Read-Only mode. Git push operations are blocked.');
                        return false;
                    }
                    $this->sendForbidden('Account associated with token is suspended.');
                    return false;
                }
            }

            // Regular user token: must be a collaborator with the right role.
            $role = $this->getCollaboratorRole($repoId, (int) $tokenData['user_id']);
            if ($role === null) {
                $this->sendUnauthorized();
                return false;
            }
            if ($service === 'git-receive-pack' && $role !== 'write') {
                $this->sendUnauthorized();
                return false;
            }
            if ($service === 'git-receive-pack') {
                $this->authenticatedUser = 'token:' . $tokenData['name'];
            }
            return true;
        }
        // ─────────────────────────────────────────────────────────────

        // Owner account: full access to every repository.
        $ownerUsername = (string) $this->app->config('app.owner', 'admin');

        if (hash_equals($ownerUsername, $username)) {
            $hash = $this->getSetting('owner_password_hash');

            if ($hash !== null && $hash !== '' && password_verify($password, $hash)) {
                $this->authenticatedUser = $username;
                return true;
            }

            $this->sendUnauthorized();
            return false;
        }

        // Registered user: must be a collaborator or owner of this repository.
        $user = $this->app->db()->fetchOne(
            'SELECT `id`, `username`, `password_hash`, `role`, `is_suspended`, `suspension_type`, `suspension_until` FROM `users` WHERE `username` = :name OR `email` = :email LIMIT 1',
            ['name' => $username, 'email' => strtolower($username)],
        );

        if ($user === false || ! password_verify($password, (string) $user['password_hash'])) {
            $this->sendUnauthorized();
            return false;
        }

        // Check account suspension and moderation standing
        if (! empty($user['is_suspended'])) {
            if (! empty($user['suspension_until']) && strtotime((string) $user['suspension_until']) <= time()) {
                // Auto-lift expired suspension
                $this->app->db()->execute(
                    'UPDATE `users` SET `is_suspended` = 0, `suspension_type` = "none", `suspension_until` = NULL WHERE `id` = :id',
                    ['id' => (int) $user['id']]
                );
            } else {
                $sType = (string) ($user['suspension_type'] ?? 'suspended');
                if ($service === 'git-receive-pack' && $sType === 'read_only') {
                    $this->sendForbidden('Account is restricted to Read-Only mode. Git push operations are blocked.');
                    return false;
                }
                $this->sendForbidden('Account is currently suspended or locked. Git operations rejected.');
                return false;
            }
        }

        // Git Push Specific Constraints
        if ($service === 'git-receive-pack') {
            // Push rate limiting
            $rateLimitPerMin = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'git_push_rate_limit_per_minute'")['value'] ?? 30);
            if ($rateLimitPerMin > 0) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $limiter = new \App\Middleware\RateLimit($this->app);
                $rateKey = 'git_push_' . md5($clientIp . '_' . ((string) $user['id']));
                if (! $limiter->check($rateKey, $rateLimitPerMin, 1)) {
                    $this->sendTooManyRequests('Git push rate limit exceeded. Please wait a minute before pushing again.');
                    return false;
                }
                $limiter->increment($rateKey);
            }

            // User total storage quota
            $userQuotaMb = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'user_storage_quota_mb'")['value'] ?? 5120);
            if ($userQuotaMb > 0) {
                $totalUserBytes = $this->getUserTotalRepoStorage((int) $user['id']);
                if ($totalUserBytes >= ($userQuotaMb * 1024 * 1024)) {
                    $this->sendForbidden("Account total storage quota exceeded ({$userQuotaMb} MB). Push rejected.");
                    return false;
                }
            }
        }

        $role = $this->getCollaboratorRole($repoId, (int) $user['id']);

        if ($role === null) {
            $this->sendUnauthorized();
            return false;
        }

        // Pushing requires write access; read collaborators can only fetch.
        if ($service === 'git-receive-pack' && $role !== 'write') {
            $this->sendUnauthorized();
            return false;
        }

        if ($service === 'git-receive-pack') {
            $this->authenticatedUser = (string) $user['username'];
        }

        return true;
    }

    /** Send a 401 Unauthorized response with Basic auth challenge. */
    private function sendUnauthorized(): void
    {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="GitPHP"');
        echo 'Authentication required';
    }

    /** Check whether a repository is publicly accessible. */
    private function isRepoPublic(string $slug): bool
    {
        $row = $this->app->db()->fetchOne(
            'SELECT `visibility` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($row === false) return false;

        return ($row['visibility'] ?? 'private') === 'public';
    }

    /** Return the database id for a repository slug, or null when absent. */
    private function getRepoId(string $slug): ?int
    {
        $row = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        return $row !== false ? (int) $row['id'] : null;
    }

    /** Return the collaborator role of a user on a repository, or null. */
    private function getCollaboratorRole(int $repoId, int $userId): ?string
    {
        // 1. Direct repository owner has write privileges
        $repo = $this->app->db()->fetchOne(
            'SELECT `owner_user_id` FROM `repositories` WHERE `id` = :repo LIMIT 1',
            ['repo' => $repoId]
        );
        if ($repo !== false && (int) ($repo['owner_user_id'] ?? 0) === $userId && $userId > 0) {
            return 'write';
        }

        // 2. Otherwise check repo_collaborators
        $row = $this->app->db()->fetchOne(
            'SELECT `role` FROM `repo_collaborators` WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
            ['repo' => $repoId, 'user' => $userId],
        );

        return $row !== false ? (string) $row['role'] : null;
    }

    /** Fetch a value from the settings table by key. */
    private function getSetting(string $key): ?string
    {
        $row = $this->app->db()->fetchOne(
            'SELECT `setting_value` FROM `settings` WHERE `setting_key` = :key LIMIT 1',
            ['key' => $key],
        );

        if ($row === false) return null;

        return $row['setting_value'] !== null ? (string) $row['setting_value'] : null;
    }

    /**
     * Execute a git process with streaming output.
     * @param string $repoPath Full filesystem path to the bare repo
     * @param array<int, string> $command The git command and arguments
     * @param string|null $input Raw request body to pipe to stdin
     * @param array<string, string>|null $env Extra environment (merged over the current one)
     */
    private function streamGitProcess(string $repoPath, array $command, ?string $input = null, ?array $env = null): void
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $repoPath, $env);

        if (! is_resource($process)) {
            http_response_code(500);
            echo 'Failed to start git process';
            return;
        }

        // Set a generous timeout for large repos
        set_time_limit(300);

        // Write input to stdin if provided
        if ($input !== null && $input !== '') fwrite($pipes[0], $input);
        fclose($pipes[0]);

        // Stream stdout in 8KB chunks.
        // Non-blocking mode is not supported on Windows pipes, so use blocking reads there.
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        if (! $isWindows) stream_set_blocking($pipes[1], false);
        stream_set_timeout($pipes[1], 300);

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);

            if ($chunk === false || $chunk === '') {
                if ($isWindows) {
                    // On Windows blocking reads return '' only at EOF, so break here
                    break;
                }
                // On Unix, check if the process is still alive
                $status = proc_get_status($process);
                if (! $status['running']) break;
                usleep(1000); // 1ms backoff before retry
                continue;
            }

            echo $chunk;

            if (function_exists('ob_flush')) @ob_flush();
            flush();

            if (connection_aborted()) break;
        }

        fclose($pipes[1]);

        // Read any stderr output (for logging, not sent to client)
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Log stderr if there was an error
        if ($exitCode !== 0 && $stderr !== '' && $stderr !== false) {
            error_log("Git process error (exit {$exitCode}): {$stderr}");
        }
    }

    /** Format a Git packet line. */
    private function buildPacketLine(string $data): string
    {
        $length = strlen($data) + 4;

        return sprintf('%04x%s', $length, $data);
    }

    /** Calculate directory size in bytes recursively */
    private function getDirSize(string $dir): int
    {
        $size = 0;
        if (! is_dir($dir)) return 0;
        try {
            $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, $flags));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable) {}
        return $size;
    }

    /** Calculate total storage used across all repositories owned by a user */
    private function getUserTotalRepoStorage(int $userId): int
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT slug FROM repositories WHERE owner_user_id = :uid',
            ['uid' => $userId]
        );
        $total = 0;
        foreach ($rows as $row) {
            $path = $this->gitService->getRepoPath((string) $row['slug']);
            $total += $this->getDirSize($path);
        }
        return $total;
    }

    /** Send a 403 Forbidden response with Git error message */
    private function sendForbidden(string $message): void
    {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "remote: error: {$message}
";
        echo "fatal: unable to access repository: The requested URL returned error: 403
";
    }

    /** Send a 429 Too Many Requests response with Git error message */
    private function sendTooManyRequests(string $message): void
    {
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        echo "remote: error: {$message}
";
        echo "fatal: unable to access repository: The requested URL returned error: 429
";
    }
}
