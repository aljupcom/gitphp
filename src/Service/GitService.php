<?php

declare(strict_types=1);

namespace App\Service;

use App\Security\PathValidator;
use RuntimeException;
use Symfony\Component\Process\Process;

final class GitService
{
    private readonly string $reposPath;

    public function __construct()
    {
        $this->reposPath = rtrim(
            (string) env('REPOS_PATH', dirname(__DIR__, 2) . '/repos'),
            DIRECTORY_SEPARATOR,
        );
    }

    /** Initialize a new bare git repository. */
    public function initRepo(string $slug, string $defaultBranch = 'main'): bool
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        if (is_dir($repoPath)) {
            throw new RuntimeException("Repository already exists: {$slug}");
        }

        // Create the directory
        if (!mkdir($repoPath, 0755, true) && !is_dir($repoPath)) {
            throw new RuntimeException("Failed to create repository directory: {$repoPath}");
        }

        // git init --bare
        $process = new Process(['git', 'init', '--bare'], $repoPath);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->deleteDirectoryRecursive($repoPath);
            throw new RuntimeException('git init failed: ' . $process->getErrorOutput());
        }

        // Set HEAD to default branch
        $process = new Process(
            ['git', 'symbolic-ref', 'HEAD', "refs/heads/{$defaultBranch}"],
            $repoPath,
        );
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->deleteDirectoryRecursive($repoPath);
            throw new RuntimeException('Failed to set HEAD: ' . $process->getErrorOutput());
        }

        // Install hooks and enable HTTP push for this new repo.
        $this->installHooks($slug);
        $this->enableReceivePack($repoPath);

        return true;
    }

    /**
     * Import a remote repository as a full bare mirror clone.
     *
     * `--mirror` copies EVERYTHING git-level: the complete commit history of
     * every branch (with all directories and file contents at each version),
     * all branches, all tags (lightweight and annotated), notes, and sets up
     * a mirror fetch refspec (refs/*:refs/*) so future syncs stay complete.
     *
     * @param string $authHeader Optional HTTP auth header ("Authorization: ...")
     *                           passed via http.extraheader so private repos can
     *                           be cloned without embedding credentials in the URL.
     */
    /**
     * Mirror-clone a remote repository (all branches/tags/notes + submodules).
     *
     * When $onProgress is given it is invoked with real git progress parsed
     * from the clone's stderr: fn(string $phase, int $percent, string $detail).
     * Phases: 'receiving' (object download), 'resolving' (delta compression),
     * 'submodules' and 'done'. The percent spans 0-100 across all phases.
     *
     * @return string Detected default branch ('' if the remote is empty).
     */
    public function importRepo(string $slug, string $url, int $timeout = 900, string $authHeader = '', ?callable $onProgress = null): string
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        if (is_dir($repoPath)) {
            throw new RuntimeException("Repository already exists: {$slug}");
        }

        $args = ['git'];

        if ($authHeader !== '') {
            $args[] = '-c';
            $args[] = "http.extraheader={$authHeader}";
        }

        // --mirror: complete bare clone (all branches, tags, notes, full history)
        // --recurse-submodules: also clone every submodule repo so the
        // repository's subdirectories are fully populated after import.
        // --progress: forces the counters to stderr even without a TTY so the
        // caller can report a real percentage while the clone runs.
        array_push($args, 'clone', '--mirror', '--recurse-submodules', '--progress', $url, $repoPath);

        $process = new Process($args, null, [
            'GIT_TERMINAL_PROMPT' => '0', // never hang on credential prompts
        ]);
        $process->setTimeout($timeout);
        $process->start();

        $buffer = '';

        $process->wait(function (string $type, string $output) use ($onProgress, &$buffer): void {
            if ($onProgress === null) return;

            // Progress counters use \r without \n; split on both.
            $buffer .= $output;
            $lines   = preg_split('/\r\n|\n|\r/', $buffer) ?: [];
            $buffer  = $lines[count($lines) - 1] ?? ''; // keep the partial tail

            foreach (array_slice($lines, 0, -1) as $line) {
                $this->emitCloneProgress($onProgress, $line);
            }
        });

        // Flush any trailing partial line
        if ($onProgress !== null && $buffer !== '') {
            $this->emitCloneProgress($onProgress, $buffer);
        }

        if (!$process->isSuccessful()) {
            $this->deleteDirectoryRecursive($repoPath);
            throw new RuntimeException('git clone failed: ' . trim($process->getErrorOutput()));
        }

        if ($onProgress !== null) {
            $onProgress('done', 100, 'Clone completed');
        }

        $this->installHooks($slug);
        $this->enableReceivePack($repoPath);

        return $this->detectHead($repoPath);
    }

    /** Parse one stderr line from git clone and forward a normalized progress event. */
    private function emitCloneProgress(callable $onProgress, string $line): void
    {
        $line = trim($line);

        if ($line === '') return;

        // "Receiving objects:  45% (1234/2738), 45.20 MiB | 2.10 MiB/s"
        if (preg_match('/Receiving objects:\s+(\d+)%\s+\((\d+)\/(\d+)\)(.*)$/', $line, $m)) {
            $onProgress('receiving', (int) $m[1], trim($m[4], ", \t") ?: "{$m[2]}/{$m[3]} objects");
            return;
        }

        // "Resolving deltas:  100% (512/512), done."
        if (preg_match('/Resolving deltas:\s+(\d+)%\s+\((\d+)\/(\d+)\)/', $line, $m)) {
            $onProgress('resolving', (int) $m[1], "{$m[2]}/{$m[3]} deltas");
            return;
        }

        // "Enumerating objects: 2738, done." / "Counting objects: 100% ..."
        if (preg_match('/(Enumerating|Counting) objects:\s+(\d+)/', $line, $m)) {
            $onProgress('enumerating', 0, trim($line));
            return;
        }

        if (str_contains($line, 'Submodule')) {
            $onProgress('submodules', 0, $line);
        }
    }

    /**
     * Fetch all remote updates into a mirror repo and refresh submodules.
     *
     * The mirror refspec (refs/*:refs/*) refreshes every branch, tag and note
     * in one `git remote update`, and --prune removes refs deleted upstream.
     *
     * @param string $authHeader Optional HTTP auth header ("Authorization: ...")
     */
    public function syncRemote(string $slug, string $authHeader = '', int $timeout = 900): void
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        if (! is_dir($repoPath)) {
            throw new RuntimeException("Repository does not exist: {$slug}");
        }

        $args = ['git'];

        if ($authHeader !== '') {
            $args[] = '-c';
            $args[] = "http.extraheader={$authHeader}";
        }

        array_push($args, 'remote', 'update', '--prune');

        $process = new Process($args, $repoPath, [
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git remote update failed: ' . trim($process->getErrorOutput()));
        }

        // Submodule contents live in separate repos; refresh them too. This is
        // best-effort — a broken submodule remote must not fail the main sync.
        $sub = new Process(
            ['git', 'submodule', 'update', '--init', '--recursive'],
            $repoPath,
            ['GIT_TERMINAL_PROMPT' => '0'],
        );
        $sub->setTimeout($timeout);
        $sub->run();
    }

    /** Point HEAD at the given branch (sets the repository's default branch). */
    public function setHead(string $slug, string $branch): void
    {
        $repoPath = $this->getRepoPath($slug);

        $process = new Process(['git', 'symbolic-ref', 'HEAD', "refs/heads/{$branch}"], $repoPath);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to set HEAD: ' . $process->getErrorOutput());
        }
    }

    /** Rename a bare repository directory from old slug to new slug. */
    public function renameRepo(string $oldSlug, string $newSlug): bool
    {
        $this->assertValidSlug($oldSlug);
        $this->assertValidSlug($newSlug);

        $oldPath = $this->getRepoPath($oldSlug);
        $newPath = $this->getRepoPath($newSlug);

        if (!is_dir($oldPath)) {
            // If old directory is not on disk, initialize the new bare repository
            return $this->initRepo($newSlug);
        }

        if (file_exists($newPath)) {
            throw new \RuntimeException("Target repository directory already exists: {$newSlug}");
        }

        if (!@rename($oldPath, $newPath)) {
            throw new \RuntimeException("Failed to rename repository directory from {$oldSlug} to {$newSlug}");
        }

        return true;
    }

    /** Delete a bare repository. */
    public function deleteRepo(string $slug): bool
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) return false;

        // Safety safeguard: Decouple any forks that point to this parent's objects
        $this->decoupleDependentForks($slug);

        return $this->deleteDirectoryRecursive($repoPath);
    }

    /**
     * Decouple any child forks using objects/info/alternates from this repository
     * so that deleting the parent never damages any forks.
     */
    public function decoupleDependentForks(string $parentSlug): void
    {
        $parentObjectsPath = realpath($this->getRepoPath($parentSlug) . DIRECTORY_SEPARATOR . 'objects');
        if (!$parentObjectsPath) return;

        if (!is_dir($this->reposPath)) return;
        $dirs = scandir($this->reposPath);
        if ($dirs === false) return;

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !str_ends_with($dir, '.git')) continue;
            $childPath = $this->reposPath . DIRECTORY_SEPARATOR . $dir;
            $altFile = $childPath . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'alternates';

            if (file_exists($altFile)) {
                $content = file_get_contents($altFile);
                if ($content !== false && str_contains($content, $parentObjectsPath)) {
                    // Decouple this child fork into a standalone repository
                    try {
                        $p = new Process(['git', 'repack', '-a', '-d'], $childPath);
                        $p->setTimeout(300);
                        $p->run();
                        @unlink($altFile);
                    } catch (\Throwable $e) {
                        error_log("[GitService] Failed to decouple fork {$dir}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /** Check if a bare repo directory exists. */
    public function repoExists(string $slug): bool
    {
        $this->assertValidSlug($slug);

        $repoPath = $this->getRepoPath($slug);

        return is_dir($repoPath) && is_dir($repoPath . DIRECTORY_SEPARATOR . 'objects');
    }

    /** Return the full filesystem path for a repository. */
    public function getRepoPath(string $slug): string
    {
        $this->assertValidSlug($slug);

        $path = $this->reposPath . DIRECTORY_SEPARATOR . $slug . '.git';

        return PathValidator::validate($path, $this->reposPath);
    }

    /**
     * Enable git-receive-pack over Smart HTTP for a bare repository.
     * Called automatically by initRepo(), importRepo(), and forkRepo()
     * so every new repository accepts HTTP push without manual intervention.
     */
    private function enableReceivePack(string $repoPath): void
    {
        $cfg = new Process(['git', 'config', 'http.receivepack', 'true'], $repoPath);
        $cfg->setTimeout(10);
        $cfg->run();
        // Non-fatal: if git config fails (e.g. permissions), push will still
        // work for the owner via Basic auth fallback, but log a warning.
        if (! $cfg->isSuccessful()) {
            error_log("GitService: could not set http.receivepack for {$repoPath}: " . $cfg->getErrorOutput());
        }
    }

    /** Copy the post-receive hook into the repo's hooks directory. */
    public function installHooks(string $slug): void
    {
        $this->assertValidSlug($slug);

        $repoPath       = $this->getRepoPath($slug);
        $hooksDir       = $repoPath . DIRECTORY_SEPARATOR . 'hooks';
        $sourceHooksDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'hooks';

        if (!is_dir($hooksDir)) mkdir($hooksDir, 0755, true);

        // Copy every file from the project hooks/ directory into the repo hooks/
        if (!is_dir($sourceHooksDir)) return;

        $entries = scandir($sourceHooksDir);
        if ($entries === false) return;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $source = $sourceHooksDir . DIRECTORY_SEPARATOR . $entry;
            $dest   = $hooksDir . DIRECTORY_SEPARATOR . $entry;

            if (!is_file($source)) continue;

            // Bake the project root into every hook that references {{BASE_PATH}}
            // (both pre-receive and post-receive need it to locate .env).
            $hookContent = (string) file_get_contents($source);
            if (str_contains($hookContent, '{{BASE_PATH}}')) {
                $hookContent = str_replace('{{BASE_PATH}}', dirname(__DIR__, 2), $hookContent);
            }
            file_put_contents($dest, $hookContent);

            // Make executable on Unix
            if (DIRECTORY_SEPARATOR === '/') chmod($dest, 0755);
        }
    }

    /** Return the base repos directory path. */
    public function getReposPath(): string
    {
        return $this->reposPath;
    }

    /**
     * Create a zip or tar.gz archive of a ref's tree via `git archive`.
     * Returns the path of the created file.
     */
    public function createArchive(string $slug, string $ref, string $destFile, string $format = 'zip'): string
    {
        $this->assertValidSlug($slug);

        if (
            $ref === '' || str_starts_with($ref, '-') ||
            preg_match('/[;&|`$(){}\\\\\s]/', $ref)
        ) {
            throw new RuntimeException('Invalid ref for archive.');
        }

        $format = in_array($format, ['zip', 'tar.gz'], true) ? $format : 'zip';
        $repoPath = $this->getRepoPath($slug);
        $cleanRef = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ref);

        if ($format === 'tar.gz') {
            // Use native piped gzip -1 for multi-core fast streaming compression
            $cmd = sprintf(
                'git -C %s -c tar.umask=002 archive --format=tar --prefix=%s/ %s | gzip -1 > %s',
                escapeshellarg($repoPath),
                escapeshellarg("{$slug}-{$cleanRef}"),
                escapeshellarg($ref),
                escapeshellarg($destFile)
            );
            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($destFile) || filesize($destFile) === 0) {
                @unlink($destFile);
                throw new RuntimeException('git archive tar.gz failed: ' . trim($process->getErrorOutput()));
            }

            return $destFile;
        }

        // Fast zip compression level -1
        $process = new Process([
            'git', '-c', 'tar.umask=002',
            'archive', '--format=zip', '-1',
            "--prefix={$slug}-{$cleanRef}/",
            $ref, '-o', $destFile,
        ], $repoPath);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($destFile) || filesize($destFile) === 0) {
            @unlink($destFile);
            throw new RuntimeException('git archive zip failed: ' . trim($process->getErrorOutput()));
        }

        return $destFile;
    }

    /**
     * Create a new branch in the repository pointing to $fromRef (commit SHA or branch name).
     */
    public function createBranch(string $slug, string $branchName, string $fromRef = 'HEAD'): bool
    {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($branchName);
        if ($fromRef !== 'HEAD') {
            // Accept full branch refs ("refs/heads/x") and bare names, reject options
            $bareFromRef = str_starts_with($fromRef, 'refs/heads/') ? substr($fromRef, 11) : $fromRef;
            try {
                $this->assertValidBranchName($bareFromRef);
            } catch (RuntimeException) {
                $this->assertSafeRef($fromRef); // allow SHAs / tags that pass ref safety
            }
        }
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) {
            throw new RuntimeException("Repository not found: {$slug}");
        }

        // Check if branch already exists
        $check = new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branchName}"], $repoPath);
        $check->run();
        if ($check->isSuccessful()) {
            throw new RuntimeException("Branch '{$branchName}' already exists.");
        }

        $process = new Process(['git', 'branch', $branchName, $fromRef], $repoPath);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to create branch: ' . trim($process->getErrorOutput()));
        }

        return true;
    }

    /**
     * Delete an existing branch from the repository.
     */
    public function deleteBranch(string $slug, string $branchName, string $defaultBranch = 'main'): bool
    {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($branchName);
        $repoPath = $this->getRepoPath($slug);

        if ($branchName === $defaultBranch) {
            throw new RuntimeException("Cannot delete default branch '{$branchName}'.");
        }

        $process = new Process(['git', 'branch', '-D', $branchName], $repoPath);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to delete branch: ' . trim($process->getErrorOutput()));
        }

        return true;
    }

    /**
     * Change HEAD symbolic ref to another branch.
     */
    public function setDefaultBranch(string $slug, string $branchName): bool
    {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($branchName);
        $repoPath = $this->getRepoPath($slug);

        $process = new Process(['git', 'symbolic-ref', 'HEAD', "refs/heads/{$branchName}"], $repoPath);
        $process->setTimeout(10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to set default branch: ' . trim($process->getErrorOutput()));
        }

        return true;
    }

    /**
     * Compare two branches and return ahead/behind counts, commits, diff, and mergeability.
     * @return array<string, mixed>
     */
    public function compare(string $slug, string $base, string $head): array
    {
        $this->assertValidSlug($slug);
        $this->assertSafeRef($base);
        $this->assertSafeRef($head);
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) {
            throw new RuntimeException("Repository not found: {$slug}");
        }

        // Get commits in head that are not in base: base..head
        $logFormat = '%H%x1f%h%x1f%an%x1f%ae%x1f%at%x1f%s%x1e';
        $logProc = new Process(
            ['git', 'log', "{$base}..{$head}", "--format={$logFormat}", '--max-count=100'],
            $repoPath,
        );
        $logProc->setTimeout(15);
        $logProc->run();

        $commits = [];
        $rawLog = trim($logProc->getOutput());
        if ($rawLog !== '') {
            $records = explode("\x1e", $rawLog);
            foreach ($records as $record) {
                $record = trim($record);
                if ($record === '') continue;
                $parts = explode("\x1f", $record);
                if (count($parts) >= 6) {
                    $commits[] = [
                        'sha'            => $parts[0],
                        'short_sha'      => $parts[1],
                        'author_name'    => $parts[2],
                        'author_email'   => $parts[3],
                        'author_date'    => (int) $parts[4],
                        'subject'        => $parts[5],
                    ];
                }
            }
        }

        // Get count ahead and behind: rev-list --left-right --count base...head
        $countProc = new Process(['git', 'rev-list', '--left-right', '--count', "{$base}...{$head}"], $repoPath);
        $countProc->run();
        $countParts = preg_split('/\s+/', trim($countProc->getOutput()));
        $behindCount = isset($countParts[0]) ? (int) $countParts[0] : 0;
        $aheadCount  = isset($countParts[1]) ? (int) $countParts[1] : 0;

        // Get unified diff
        $diffProc = new Process(['git', 'diff', "{$base}...{$head}"], $repoPath);
        $diffProc->setTimeout(20);
        $diffProc->run();
        $diff = $diffProc->getOutput();

        // Check mergeability with merge-tree
        $mergeTreeProc = new Process(['git', 'merge-tree', '--write-tree', $base, $head], $repoPath);
        $mergeTreeProc->setTimeout(15);
        $mergeTreeProc->run();

        $canMerge = $mergeTreeProc->isSuccessful();
        $mergeError = $canMerge ? null : 'Automatic merge is not possible due to conflicts.';

        return [
            'base'         => $base,
            'head'         => $head,
            'ahead_count'  => $aheadCount,
            'behind_count' => $behindCount,
            'commits'      => $commits,
            'diff'         => $diff,
            'can_merge'    => $canMerge,
            'merge_error'  => $mergeError,
        ];
    }

    /**
     * Merge branch $head into branch $base programmatically.
     * @return array<string, mixed>
     */
    public function merge(
        string $slug,
        string $base,
        string $head,
        string $authorName,
        string $authorEmail,
        string $message = '',
        string $strategy = 'merge',
    ): array {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($base);
        $this->assertValidBranchName($head);
        $strategy = in_array($strategy, ['merge', 'squash', 'rebase', 'ff'], true) ? $strategy : 'merge';
        $repoPath = $this->getRepoPath($slug);

        if (!is_dir($repoPath)) {
            return ['ok' => false, 'error' => "Repository not found: {$slug}"];
        }

        $env = [
            'GIT_AUTHOR_NAME'     => $authorName ?: 'GitPHP',
            'GIT_AUTHOR_EMAIL'    => $authorEmail ?: 'gitphp@localhost',
            'GIT_COMMITTER_NAME'  => $authorName ?: 'GitPHP',
            'GIT_COMMITTER_EMAIL' => $authorEmail ?: 'gitphp@localhost',
        ];

        // ── fast-forward strategy: pure ref move, no new commit ──────
        $baseShaProc = new Process(['git', 'rev-parse', "refs/heads/{$base}"], $repoPath);
        $baseShaProc->run();
        $baseSha = trim($baseShaProc->getOutput());

        $headShaProc = new Process(['git', 'rev-parse', "refs/heads/{$head}"], $repoPath);
        $headShaProc->run();
        $headSha = trim($headShaProc->getOutput());

        if ($baseSha === '' || $headSha === '') {
            return ['ok' => false, 'error' => 'Could not resolve branch references.'];
        }

        if ($strategy === 'ff') {
            $isAncestor = new Process(['git', 'merge-base', '--is-ancestor', $baseSha, $headSha], $repoPath);
            $isAncestor->run();
            if (! $isAncestor->isSuccessful()) {
                return ['ok' => false, 'error' => 'Fast-forward is not possible: branches have diverged.'];
            }

            $update = new Process(['git', 'update-ref', "refs/heads/{$base}", $headSha], $repoPath, $env);
            $update->run();
            if (! $update->isSuccessful()) {
                return ['ok' => false, 'error' => 'Failed to fast-forward: ' . trim($update->getErrorOutput())];
            }
            return ['ok' => true, 'commit_sha' => $headSha];
        }

        // ── rebase strategy: replay each head commit onto base ────────
        // Implemented via cherry-picking the commit range onto a temp ref,
        // then moving base — keeps original commit authors intact.
        if ($strategy === 'rebase') {
            // Count commits to replay.
            $list = new Process(['git', 'rev-list', '--reverse', "{$baseSha}..{$headSha}"], $repoPath);
            $list->setTimeout(20);
            $list->run();
            if (! $list->isSuccessful()) {
                return ['ok' => false, 'error' => 'Failed to enumerate commits for rebase.'];
            }
            $commits = array_filter(array_map('trim', explode("\n", trim($list->getOutput()))));
            if ($commits === []) {
                // Already up to date → just move the ref (ff).
                $update = new Process(['git', 'update-ref', "refs/heads/{$base}", $headSha], $repoPath);
                $update->run();
                return ['ok' => true, 'commit_sha' => $headSha];
            }

            // Detach a temporary worktree at base to cherry-pick onto.
            // Bare repos have no worktree, so operate with a lightweight
            // temporary index/work tree via git -c directives.
            $tmpWork = sys_get_temp_dir() . '/gitphp-rebase-' . bin2hex(random_bytes(6));
            $checkout = new Process(['git', 'worktree', 'add', '--detach', $tmpWork, $baseSha], $repoPath);
            $checkout->setTimeout(30);
            $checkout->run();
            if (! $checkout->isSuccessful()) {
                return ['ok' => false, 'error' => 'Rebase setup failed: ' . trim($checkout->getErrorOutput())];
            }

            try {
                $last = $baseSha;
                foreach ($commits as $sha) {
                    $pick = new Process(['git', 'cherry-pick', $sha], $tmpWork, $env);
                    $pick->setTimeout(30);
                    $pick->run();
                    if (! $pick->isSuccessful()) {
                        $abort = new Process(['git', 'cherry-pick', '--abort'], $tmpWork);
                        $abort->run();
                        return ['ok' => false, 'error' => 'Rebase failed (conflicts) at commit ' . substr($sha, 0, 8) . '.'];
                    }
                    $rev = new Process(['git', 'rev-parse', 'HEAD'], $tmpWork);
                    $rev->run();
                    $last = trim($rev->getOutput());
                }

                $update = new Process(['git', 'update-ref', "refs/heads/{$base}", $last], $repoPath);
                $update->run();
                if (! $update->isSuccessful()) {
                    return ['ok' => false, 'error' => 'Failed to update branch after rebase.'];
                }

                return ['ok' => true, 'commit_sha' => $last];
            } finally {
                (new Process(['git', 'worktree', 'remove', '--force', $tmpWork], $repoPath))->run();
            }
        }

        // ── merge-tree check (merge + squash) ─────────────────────────
        $mergeTreeProc = new Process(['git', 'merge-tree', '--write-tree', $base, $head], $repoPath);
        $mergeTreeProc->setTimeout(20);
        $mergeTreeProc->run();

        if (!$mergeTreeProc->isSuccessful()) {
            return [
                'ok'    => false,
                'error' => 'Cannot merge automatically because of merge conflicts.',
            ];
        }

        $treeSha = trim($mergeTreeProc->getOutput());
        if ($treeSha === '') {
            return ['ok' => false, 'error' => 'Failed to generate merge tree.'];
        }

        // Default commit message
        if (trim($message) === '') {
            $message = $strategy === 'squash'
                ? "Squash merge branch '{$head}' into {$base}"
                : "Merge branch '{$head}' into {$base}";
        }

        // ── squash: single commit with ONE parent (base) ─────────────
        if ($strategy === 'squash') {
            $commitProc = new Process(
                ['git', 'commit-tree', $treeSha, '-p', $baseSha, '-m', $message],
                $repoPath,
                $env,
            );
            $commitProc->setTimeout(15);
            $commitProc->run();

            if (!$commitProc->isSuccessful()) {
                return ['ok' => false, 'error' => 'Failed to create squash commit: ' . trim($commitProc->getErrorOutput())];
            }

            $squashSha = trim($commitProc->getOutput());
            $updateRefProc = new Process(['git', 'update-ref', "refs/heads/{$base}", $squashSha], $repoPath);
            $updateRefProc->run();

            if (!$updateRefProc->isSuccessful()) {
                return ['ok' => false, 'error' => 'Failed to update branch ref: ' . trim($updateRefProc->getErrorOutput())];
            }

            return ['ok' => true, 'commit_sha' => $squashSha];
        }

        // ── merge commit (two parents) ────────────────────────────────
        $commitProc = new Process(
            ['git', 'commit-tree', $treeSha, '-p', $baseSha, '-p', $headSha, '-m', $message],
            $repoPath,
            $env,
        );
        $commitProc->setTimeout(15);
        $commitProc->run();

        if (!$commitProc->isSuccessful()) {
            return ['ok' => false, 'error' => 'Failed to create merge commit: ' . trim($commitProc->getErrorOutput())];
        }

        $mergeCommitSha = trim($commitProc->getOutput());

        // Update base ref
        $updateRefProc = new Process(['git', 'update-ref', "refs/heads/{$base}", $mergeCommitSha], $repoPath);
        $updateRefProc->run();

        if (!$updateRefProc->isSuccessful()) {
            return ['ok' => false, 'error' => 'Failed to update branch ref: ' . trim($updateRefProc->getErrorOutput())];
        }

        return [
            'ok'         => true,
            'commit_sha' => $mergeCommitSha,
        ];
    }

    /**
     * Create or edit a file directly in the bare repository and commit it.
     * @return array<string, mixed>
     */
    public function saveFile(
        string $slug,
        string $branch,
        string $filePath,
        string $content,
        string $commitMessage,
        string $authorName,
        string $authorEmail,
    ): array {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        $filePath = ltrim(str_replace('\\', '/', trim($filePath)), '/');
        if ($filePath === '' || str_contains($filePath, '..')) {
            return ['ok' => false, 'error' => 'Invalid file path.'];
        }

        $tempIndex = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gitphp_idx_' . uniqid('', true);
        $env = [
            'GIT_INDEX_FILE'      => $tempIndex,
            'GIT_AUTHOR_NAME'     => $authorName ?: 'GitPHP',
            'GIT_AUTHOR_EMAIL'    => $authorEmail ?: 'gitphp@localhost',
            'GIT_COMMITTER_NAME'  => $authorName ?: 'GitPHP',
            'GIT_COMMITTER_EMAIL' => $authorEmail ?: 'gitphp@localhost',
        ];

        try {
            // 1. Read existing branch tree if branch exists
            $branchRefProc = new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"], $repoPath);
            $branchRefProc->run();
            $branchExists = $branchRefProc->isSuccessful();

            if ($branchExists) {
                $readTree = new Process(['git', 'read-tree', "refs/heads/{$branch}"], $repoPath, $env);
                $readTree->run();
            }

            // 2. Hash and store content object
            $hashObj = new Process(['git', 'hash-object', '-w', '--stdin'], $repoPath, $env);
            $hashObj->setInput($content);
            $hashObj->run();

            if (!$hashObj->isSuccessful()) {
                throw new RuntimeException('Failed to write object: ' . trim($hashObj->getErrorOutput()));
            }
            $blobSha = trim($hashObj->getOutput());

            // 3. Update index
            $updateIdx = new Process(
                ['git', 'update-index', '--add', '--cacheinfo', '100644', $blobSha, $filePath],
                $repoPath,
                $env,
            );
            $updateIdx->run();
            if (!$updateIdx->isSuccessful()) {
                throw new RuntimeException('Failed to update index: ' . trim($updateIdx->getErrorOutput()));
            }

            // 4. Write tree
            $writeTree = new Process(['git', 'write-tree'], $repoPath, $env);
            $writeTree->run();
            if (!$writeTree->isSuccessful()) {
                throw new RuntimeException('Failed to write tree: ' . trim($writeTree->getErrorOutput()));
            }
            $treeSha = trim($writeTree->getOutput());

            // 5. Commit tree
            $args = ['git', 'commit-tree', $treeSha];
            if ($branchExists) {
                $parentShaProc = new Process(['git', 'rev-parse', "refs/heads/{$branch}"], $repoPath);
                $parentShaProc->run();
                $parentSha = trim($parentShaProc->getOutput());
                if ($parentSha !== '') {
                    $args[] = '-p';
                    $args[] = $parentSha;
                }
            }

            $msg = trim($commitMessage) !== '' ? $commitMessage : "Update {$filePath}";
            $args[] = '-m';
            $args[] = $msg;

            $commitProc = new Process($args, $repoPath, $env);
            $commitProc->run();
            if (!$commitProc->isSuccessful()) {
                throw new RuntimeException('Failed to commit: ' . trim($commitProc->getErrorOutput()));
            }
            $newCommitSha = trim($commitProc->getOutput());

            // 6. Update branch ref
            $updateRef = new Process(['git', 'update-ref', "refs/heads/{$branch}", $newCommitSha], $repoPath);
            $updateRef->run();
            if (!$updateRef->isSuccessful()) {
                throw new RuntimeException('Failed to update branch ref: ' . trim($updateRef->getErrorOutput()));
            }

            return ['ok' => true, 'commit_sha' => $newCommitSha];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (is_file($tempIndex)) {
                @unlink($tempIndex);
            }
        }
    }

    /**
     * Delete a file from a branch by creating a removal commit.
     * Nothing is destroyed: the blob stays reachable through the parent
     * commits, so the deletion is always revertible from history.
     * @return array<string, mixed>
     */
    public function deleteFile(
        string $slug,
        string $branch,
        string $filePath,
        string $commitMessage,
        string $authorName,
        string $authorEmail,
    ): array {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        $filePath = ltrim(str_replace('\\', '/', trim($filePath)), '/');
        if ($filePath === '' || str_contains($filePath, '..')) {
            return ['ok' => false, 'error' => 'Invalid file path.'];
        }

        $tempIndex = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gitphp_idx_' . uniqid('', true);
        $env = [
            'GIT_INDEX_FILE'      => $tempIndex,
            'GIT_AUTHOR_NAME'     => $authorName ?: 'GitPHP',
            'GIT_AUTHOR_EMAIL'    => $authorEmail ?: 'gitphp@localhost',
            'GIT_COMMITTER_NAME'  => $authorName ?: 'GitPHP',
            'GIT_COMMITTER_EMAIL' => $authorEmail ?: 'gitphp@localhost',
        ];

        try {
            $branchRefProc = new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"], $repoPath);
            $branchRefProc->run();
            if (! $branchRefProc->isSuccessful()) {
                throw new RuntimeException("Branch '{$branch}' does not exist.");
            }

            // Stage the branch tree into the temporary index
            $readTree = new Process(['git', 'read-tree', "refs/heads/{$branch}"], $repoPath, $env);
            $readTree->run();
            if (! $readTree->isSuccessful()) {
                throw new RuntimeException('Failed to read branch tree: ' . trim($readTree->getErrorOutput()));
            }

            // Remove the file from the index only — the object and all
            // parent commits keep it fully recoverable.
            $rmProc = new Process(['git', 'rm', '--cached', '--', $filePath], $repoPath, $env);
            $rmProc->run();
            if (! $rmProc->isSuccessful()) {
                throw new RuntimeException("File not found on branch '{$branch}': " . trim($rmProc->getErrorOutput()));
            }

            $writeTree = new Process(['git', 'write-tree'], $repoPath, $env);
            $writeTree->run();
            if (! $writeTree->isSuccessful()) {
                throw new RuntimeException('Failed to write tree: ' . trim($writeTree->getErrorOutput()));
            }
            $treeSha = trim($writeTree->getOutput());

            $parentShaProc = new Process(['git', 'rev-parse', "refs/heads/{$branch}"], $repoPath);
            $parentShaProc->run();
            $parentSha = trim($parentShaProc->getOutput());

            $msg = trim($commitMessage) !== '' ? $commitMessage : "Delete {$filePath}";
            $args = ['git', 'commit-tree', $treeSha];
            if ($parentSha !== '') {
                $args[] = '-p';
                $args[] = $parentSha;
            }
            $args[] = '-m';
            $args[] = $msg;

            $commitProc = new Process($args, $repoPath, $env);
            $commitProc->run();
            if (! $commitProc->isSuccessful()) {
                throw new RuntimeException('Failed to commit: ' . trim($commitProc->getErrorOutput()));
            }
            $newCommitSha = trim($commitProc->getOutput());

            $updateRef = new Process(['git', 'update-ref', "refs/heads/{$branch}", $newCommitSha, $parentSha], $repoPath);
            $updateRef->run();
            if (! $updateRef->isSuccessful()) {
                throw new RuntimeException('Failed to update branch ref: ' . trim($updateRef->getErrorOutput()));
            }

            return ['ok' => true, 'commit_sha' => $newCommitSha];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            if (is_file($tempIndex)) {
                @unlink($tempIndex);
            }
        }
    }

    /** Validate that a branch name contains only safe characters. */
    private function assertValidBranchName(string $name): void
    {
        if (
            $name === '' ||
            str_starts_with($name, '/') ||
            str_ends_with($name, '/') ||
            str_starts_with($name, '.') ||
            str_ends_with($name, '.') ||
            str_contains($name, '..') ||
            str_contains($name, '@{') ||
            str_contains($name, '\\') ||
            !preg_match('/^[a-zA-Z0-9._\-\/]+$/', $name)
        ) {
            throw new RuntimeException("Invalid branch name '{$name}'.");
        }
    }

    /**
     * Validate a user-supplied ref (branch/tag/SHA/range) before passing it
     * to any git command. A leading dash would otherwise be parsed by git
     * as an option (e.g. "--output=/path/to/file" writes arbitrary files).
     */
    private function assertSafeRef(string $ref): void
    {
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0")) {
            throw new RuntimeException("Invalid ref '{$ref}'.");
        }
    }

    /** Resolve HEAD to an existing branch name ('' if the repo has no branches). */
    private function detectHead(string $repoPath): string
    {
        // Current HEAD target (if it is a symbolic ref)
        $process = new Process(['git', 'symbolic-ref', '-q', '--short', 'HEAD'], $repoPath);
        $process->setTimeout(10);
        $process->run();

        $head = trim($process->getOutput());

        if ($head !== '' && $process->isSuccessful()) {
            $check = new Process(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$head}"], $repoPath);
            $check->setTimeout(10);
            $check->run();

            if ($check->isSuccessful()) return $head;
        }

        // HEAD is detached or dangling: fall back to the first available branch
        $fallback = new Process(
            ['git', 'for-each-ref', 'refs/heads', '--format=%(refname:short)', '--count=1'],
            $repoPath,
        );
        $fallback->setTimeout(10);
        $fallback->run();

        return trim($fallback->getOutput());
    }

    /** Validate that a slug contains only safe characters. */
    private function assertValidSlug(string $slug): void
    {
        if ($slug === '' || !preg_match('/^[a-zA-Z0-9._\-]+$/', $slug)) {
            throw new RuntimeException(
                'Invalid repository slug. Only alphanumeric characters, dots, hyphens, and underscores are allowed.',
            );
        }
    }

    /** Recursively delete a directory and its contents. */
    private function deleteDirectoryRecursive(string $path): bool
    {
        // Safety: make sure it's within repos path
        if (!PathValidator::isWithin($path, $this->reposPath)) throw new RuntimeException('Refusing to delete directory outside repos path.');

        if (DIRECTORY_SEPARATOR === '/') {
            // Unix: use rm -rf for performance
            $process = new Process(['rm', '-rf', $path]);
            $process->setTimeout(30);
            $process->run();
            return $process->isSuccessful();
        }

        // Windows fallback
        $realPath = realpath($path);
        if ($realPath === false || !is_dir($realPath)) return false;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        return rmdir($realPath);
    }

    /**
     * Fork a repository by performing a local bare clone.
     */
    public function forkRepo(string $sourceSlug, string $newSlug): string
    {
        $this->assertValidSlug($sourceSlug);
        $this->assertValidSlug($newSlug);

        $sourcePath = $this->getRepoPath($sourceSlug);
        if (!is_dir($sourcePath)) {
            throw new RuntimeException("Source repository '{$sourceSlug}' not found on disk.");
        }

        $targetPath = $this->reposPath . DIRECTORY_SEPARATOR . $newSlug . '.git';
        if (file_exists($targetPath)) {
            throw new RuntimeException("Repository destination '{$newSlug}' already exists on disk.");
        }

        // Use --shared to share objects with parent repo via alternates (GitHub-standard deduplication)
        $process = new Process(['git', 'clone', '--bare', '--shared', $sourcePath, $targetPath]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to fork repository: ' . trim($process->getErrorOutput()));
        }

        // Enable receivepack, set shared repo, and install hooks.
        $this->enableReceivePack($targetPath);
        $this->installHooks($newSlug);

        return $targetPath;
    }

    /**
     * Create an annotated Git tag for a release.
     */
    public function createReleaseTag(
        string $slug,
        string $tag,
        string $targetRef = 'HEAD',
        string $message = '',
        string $authorName = 'Admin',
        string $authorEmail = 'admin@example.com'
    ): void {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($tag);
        if ($targetRef !== 'HEAD') $this->assertSafeRef($targetRef);

        $repoPath = $this->getRepoPath($slug);
        $env = [
            'GIT_COMMITTER_NAME'  => $authorName,
            'GIT_COMMITTER_EMAIL' => $authorEmail,
        ];

        $args = ['git', 'tag', '-a', $tag, $targetRef, '-m', $message !== '' ? $message : "Release {$tag}"];
        $process = new Process($args, $repoPath, $env);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Failed to create tag: ' . trim($process->getErrorOutput()));
        }
    }

    /** Stream a zip or tar.gz archive directly via git archive. */
    public function archiveStream(string $slug, string $ref = 'HEAD', string $format = 'zip', string $prefix = ''): void
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        $cmd = ['git', 'archive', "--format={$format}"];
        if ($prefix !== '') {
            $cmd[] = "--prefix={$prefix}/";
        }
        $cmd[] = $ref;

        $proc = new Process($cmd, $repoPath);
        $proc->setTimeout(120);
        $proc->run(static function ($type, $buffer) {
            echo $buffer;
            if (ob_get_level() > 0) ob_flush();
            flush();
        });
    }

    /** Stream a full git bundle directly via git bundle create - --all. */
    public function bundleStream(string $slug): void
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        $proc = new Process(['git', 'bundle', 'create', '-', '--all'], $repoPath);
        $proc->setTimeout(300);
        $proc->run(static function ($type, $buffer) {
            echo $buffer;
            if (ob_get_level() > 0) ob_flush();
            flush();
        });
    }

    /** Restore bare repository from a uploaded bundle file. */
    public function restoreFromBundle(string $slug, string $filePath): bool
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        if (is_dir($repoPath)) {
            $this->deleteDirectoryRecursive($repoPath);
        }

        $proc = new Process(['git', 'clone', '--bare', $filePath, $repoPath]);
        $proc->setTimeout(300);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('Failed to restore repository from bundle: ' . trim($proc->getErrorOutput()));
        }

        $this->enableReceivePack($repoPath);
        return true;
    }

    /** Sync repository by fetching updates from a remote URL. */
    public function syncRemoteUrl(string $slug, string $remoteUrl, ?string $token = null): bool
    {
        $this->assertValidSlug($slug);
        $repoPath = $this->getRepoPath($slug);

        $finalUrl = $remoteUrl;
        if (! empty($token) && preg_match('#^https://#i', $remoteUrl)) {
            $finalUrl = preg_replace('#^https://#i', "https://x-access-token:{$token}@", $remoteUrl);
        }

        $proc = new Process(['git', 'fetch', '--all', '--prune', $finalUrl], $repoPath, ['GIT_TERMINAL_PROMPT' => '0']);
        $proc->setTimeout(300);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('Sync failed: ' . trim($proc->getErrorOutput()));
        }

        return true;
    }





    /** Delete a tag. */
    public function deleteTag(string $slug, string $tagName): bool
    {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($tagName);
        $repoPath = $this->getRepoPath($slug);

        $proc = new Process(['git', 'tag', '-d', $tagName], $repoPath);
        $proc->setTimeout(15);
        $proc->run();

        if (! $proc->isSuccessful()) {
            throw new \RuntimeException('Failed to delete tag: ' . trim($proc->getErrorOutput()));
        }

        return true;
    }

    /** Direct commit a file to a bare repository. */
    public function directCommitFile(string $slug, string $branch, string $filePath, string $content, string $message, string $authorName, string $authorEmail): bool
    {
        $this->assertValidSlug($slug);
        $this->assertValidBranchName($branch);
        $repoPath = $this->getRepoPath($slug);

        $tmpDir = sys_get_temp_dir() . '/git_commit_' . bin2hex(random_bytes(8));
        @mkdir($tmpDir, 0755, true);

        try {
            $p1 = new Process(['git', 'clone', '--branch', $branch, $repoPath, $tmpDir]);
            $p1->run();

            if (! $p1->isSuccessful()) {
                $p1 = new Process(['git', 'clone', $repoPath, $tmpDir]);
                $p1->run();
                $pCheckout = new Process(['git', 'checkout', '-b', $branch], $tmpDir);
                $pCheckout->run();
            }

            $fullTarget = $tmpDir . '/' . ltrim($filePath, '/');
            @mkdir(dirname($fullTarget), 0755, true);
            file_put_contents($fullTarget, $content);

            $env = [
                'GIT_AUTHOR_NAME'     => $authorName,
                'GIT_AUTHOR_EMAIL'    => $authorEmail,
                'GIT_COMMITTER_NAME'  => $authorName,
                'GIT_COMMITTER_EMAIL' => $authorEmail,
            ];

            $p2 = new Process(['git', 'add', '.'], $tmpDir, $env);
            $p2->run();

            $p3 = new Process(['git', 'commit', '-m', $message !== '' ? $message : "Update {$filePath}"], $tmpDir, $env);
            $p3->run();

            $p4 = new Process(['git', 'push', 'origin', $branch], $tmpDir, $env);
            $p4->run();

            @array_map('unlink', glob("{$tmpDir}/*") ?: []);
            @rmdir($tmpDir);
            return true;
        } catch (\Throwable $e) {
            @array_map('unlink', glob("{$tmpDir}/*") ?: []);
            @rmdir($tmpDir);
            throw new \RuntimeException('Direct commit failed: ' . $e->getMessage());
        }
    }
}
