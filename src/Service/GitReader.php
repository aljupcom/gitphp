<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class GitReader
{
    private const MAX_OUTPUT_BYTES = 50 * 1024 * 1024; // 50 MB
    private const MAX_BLOB_BYTES   = 1 * 1024 * 1024;  // 1 MB

    /**
     * Return a list of branch names in the repository.
     * @return string[]
     */
    public function getBranches(string $repoPath): array
    {
        $result = $this->runGit($repoPath, ['for-each-ref', '--format=%(refname:short)', 'refs/heads/']);

        if ($result['exitCode'] !== 0) return [];

        $branches = array_filter(explode("\n", trim($result['output'])));
        sort($branches);

        return array_values($branches);
    }

    /**
     * Return a list of tag names.
     * @return string[]
     */
    public function getTags(string $repoPath): array
    {
        $result = $this->runGit($repoPath, ['for-each-ref', '--sort=-creatordate', '--format=%(refname:short)', 'refs/tags/']);

        if ($result['exitCode'] !== 0) return [];

        $tags = array_filter(explode("\n", trim($result['output'])));

        return array_values($tags);
    }

    /** Read HEAD and return the default branch name. */
    public function getDefaultBranch(string $repoPath): string
    {
        $result = $this->runGit($repoPath, ['symbolic-ref', '--short', 'HEAD']);

        if ($result['exitCode'] !== 0) return 'main';

        return trim($result['output']) ?: 'main';
    }

    /**
     * Return an array of commit entries from the log.
     * @return array<int, array{hash:string, short_hash:string, message:string, author_name:string, author_email:string, date:string}>
     */
    public function getLog(string $repoPath, string $ref, int $limit = 50, int $offset = 0): array
    {
        $this->assertSafeRef($ref);

        $sep = '%x00';
        $format = implode($sep, ['%H', '%h', '%s', '%an', '%ae', '%aI']);

        $result = $this->runGit($repoPath, [
            'log',
            "--format={$format}",
            "--skip={$offset}",
            "-n", (string) $limit,
            $ref,
        ]);

        if ($result['exitCode'] !== 0) return [];

        $lines = array_filter(explode("\n", trim($result['output'])));
        $commits = [];

        foreach ($lines as $line) {
            $parts = explode("\0", $line);

            if (count($parts) < 6) continue;

            $commits[] = [
                'hash'         => $parts[0],
                'short_hash'   => $parts[1],
                'message'      => $parts[2],
                'author_name'  => $parts[3],
                'author_email' => $parts[4],
                'date'         => $parts[5],
            ];
        }

        return $commits;
    }

    /** Decode Git C-style octal escaped path (e.g. \330\247) to clean UTF-8. */
    public function unquotePath(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '"') && str_ends_with($path, '"') && strlen($path) >= 2) {
            $path = substr($path, 1, -1);
            $path = preg_replace_callback('/\\([0-7]{1,3})/', static function (array $m): string {
                return chr((int) octdec($m[1]));
            }, $path) ?? $path;
            $bs = chr(92);
            $path = str_replace([$bs . $bs, $bs . '"', $bs . 'n', $bs . 't', $bs . 'r'], [$bs, '"', "\n", "\t", "\r"], $path);
        }
        return $path;
    }

    /**
     * Return tree entries for a given ref and path.
     * @return array<int, array{mode:string, type:string, hash:string, name:string, size:int}>
     */
    public function getTree(string $repoPath, string $ref, string $path = ''): array
    {
        $this->assertSafeRef($ref);

        $treeish = $path !== '' ? "{$ref}:{$path}" : $ref;
        $this->assertSafePath($treeish);

        $result = $this->runGit($repoPath, [
            'ls-tree', '-l', '--', $treeish,
        ]);

        if ($result['exitCode'] !== 0) return [];

        $lines = array_filter(explode("\n", trim($result['output'])));
        $trees = [];
        $blobs = [];

        foreach ($lines as $line) {
            // Format: <mode> SP <type> SP <hash> SP <size> TAB <name>
            if (!preg_match('/^(\d+)\s+(blob|tree|commit)\s+([a-f0-9]+)\s+(-|\d+)\t(.+)$/', $line, $m)) continue;

            $entry = [
                'mode' => $m[1],
                'type' => $m[2] === 'commit' ? 'tree' : $m[2],
                'hash' => $m[3],
                'name' => $this->unquotePath($m[5]),
                'size' => $m[4] === '-' ? 0 : (int) $m[4],
            ];

            if ($entry['type'] === 'tree') {
                $trees[] = $entry;
            } else {
                $blobs[] = $entry;
            }
        }

        usort($trees, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));
        usort($blobs, fn(array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return array_merge($trees, $blobs);
    }

    /** Return the content of a file (blob), limited to 1 MB. */
    public function getBlob(string $repoPath, string $ref, string $path): ?string
    {
        $this->assertSafeRef($ref);
        $this->assertSafePath($path);

        $result = $this->runGit($repoPath, [
            'cat-file', '-p', "{$ref}:{$path}",
        ], timeout: 30, maxBytes: self::MAX_BLOB_BYTES);

        if ($result['exitCode'] !== 0) return null;

        return $result['output'];
    }

    /** Return the size of a blob in bytes. */
    public function getBlobSize(string $repoPath, string $ref, string $path): int
    {
        $this->assertSafeRef($ref);
        $this->assertSafePath($path);

        $result = $this->runGit($repoPath, [
            'cat-file', '-s', "{$ref}:{$path}",
        ]);

        if ($result['exitCode'] !== 0) return 0;

        return (int) trim($result['output']);
    }

    /**
     * Return details of a single commit, or null if not found.
     * @return array{hash:string, message:string, author_name:string, author_email:string, date:string, parents:string[], diff:string}|null
     */
    public function getCommit(string $repoPath, string $hash): ?array
    {
        $this->assertSafeRef($hash);

        $sep = '%x00';
        $format = implode($sep, ['%H', '%B', '%an', '%ae', '%aI', '%P']);

        $result = $this->runGit($repoPath, [
            'log', '-1', "--format={$format}", $hash,
        ]);

        if ($result['exitCode'] !== 0 || trim($result['output']) === '') return null;

        $parts = explode("\0", trim($result['output']), 6);

        if (count($parts) < 6) return null;

        return [
            'hash'         => $parts[0],
            'message'      => trim($parts[1]),
            'author_name'  => $parts[2],
            'author_email' => $parts[3],
            'date'         => $parts[4],
            'parents'      => $parts[5] !== '' ? explode(' ', $parts[5]) : [],
            'diff'         => $this->getCommitDiff($repoPath, $parts[0]),
        ];
    }

    /** Return the diff output for a commit (empty string for clean merge commits). */
    public function getCommitDiff(string $repoPath, string $hash): string
    {
        $this->assertSafeRef($hash);

        // NOTE: no "--" before the hash — anything after "--" is a pathspec,
        // so passing the commit there silently yields an empty diff.
        // -r recurses into subdirectories; --root renders the initial commit
        // against the empty tree; -M detects renames; merge commits produce
        // no output (same behavior as GitHub's commit view).
        $result = $this->runGit($repoPath, [
            'diff-tree', '-r', '-p', '--root', '-M', '--no-commit-id', $hash,
        ]);

        return $result['exitCode'] === 0 ? $result['output'] : '';
    }

    /**
     * Return the last commit that modified a given path, or null.
     * @return array{hash:string, short_hash:string, message:string, author_name:string, author_email:string, date:string}|null
     */
    public function getLastCommitForPath(string $repoPath, string $ref, string $path): ?array
    {
        $this->assertSafeRef($ref);
        $this->assertSafePath($path);

        $sep    = '%x00';
        $format = implode($sep, ['%H', '%h', '%s', '%an', '%ae', '%aI']);

        $result = $this->runGit($repoPath, [
            'log', '-1', "--format={$format}", $ref, '--', $path,
        ]);

        if ($result['exitCode'] !== 0 || trim($result['output']) === '') return null;

        $parts = explode("\0", trim($result['output']));

        if (count($parts) < 6) return null;

        return [
            'hash'         => $parts[0],
            'short_hash'   => $parts[1],
            'message'      => $parts[2],
            'author_name'  => $parts[3],
            'author_email' => $parts[4],
            'date'         => $parts[5],
        ];
    }

    /**
     * Resolve the last commit touching each of many paths in ONE git process.
     *
     * The naive alternative spawns `git log -1 -- <path>` per entry, which on
     * a 50-file listing means 50 process launches (proc_open + temp files +
     * poll loop) and seconds of wall time on shared hosting. Walking the log
     * once and assigning commits to paths as they appear is O(1) processes.
     *
     * @param  string[] $paths   Full paths as shown in the listing
     * @param  int      $maxWalk Safety cap on history traversal
     * @return array<string, array{hash:string, short_hash:string, message:string, author_name:string, author_email:string, date:string}|null>
     */
    public function getLastCommitsForPaths(string $repoPath, string $ref, array $paths, int $maxWalk = 300): array
    {
        $result = array_fill_keys($paths, null);

        if ($paths === []) return $result;

        $this->assertSafeRef($ref);

        $sep    = '%x00';
        $format = implode($sep, ['%H', '%h', '%s', '%an', '%ae', '%aI']);

        $res = $this->runGit($repoPath, [
            'log', "--format={$format}", '--name-only', '--no-renames',
            '-n', (string) $maxWalk, $ref,
        ], timeout: 60);

        if ($res['exitCode'] !== 0) return $result;

        $remaining = array_fill_keys($paths, true);
        $current   = null;

        foreach (explode("\n", $res['output']) as $line) {
            $line = rtrim($line, "\r");

            // Blank lines only separate sections; the active header stays
            // valid until the next header line replaces it.
            if ($line === '') continue;

            // Header lines carry NUL separators; changed-path lines never do.
            if (str_contains($line, "\0")) {
                $parts = explode("\0", $line);

                $current = count($parts) >= 6 ? [
                    'hash'         => $parts[0],
                    'short_hash'   => $parts[1],
                    'message'      => $parts[2],
                    'author_name'  => $parts[3],
                    'author_email' => $parts[4],
                    'date'         => $parts[5],
                ] : null;
                continue;
            }

            if ($current === null) continue;

            // Changed paths match either exactly (files) or by directory
            // prefix (tree entries), mirroring `git log -- <path>` semantics
            // where a directory pathspec matches everything beneath it.
            $cleanLine = $this->unquotePath($line);
            foreach ($remaining as $target => $_) {
                if ($cleanLine === $target || str_starts_with($cleanLine, $target . '/')) {
                    $result[$target] = $current;
                    unset($remaining[$target]);

                    if ($remaining === []) break 2;
                }
            }
        }

        return $result;
    }

    /** Check whether the repository has zero commits. */
    public function isEmpty(string $repoPath): bool
    {
        $result = $this->runGit($repoPath, ['rev-parse', '--verify', 'HEAD']);

        return $result['exitCode'] !== 0;
    }

    /** Count the total number of commits reachable from a ref. */
    public function getCommitCount(string $repoPath, string $ref): int
    {
        $this->assertSafeRef($ref);

        $result = $this->runGit($repoPath, ['rev-list', '--count', $ref]);

        if ($result['exitCode'] !== 0) return 0;

        return (int) trim($result['output']);
    }

    /**
     * Return branches with tip-commit details, newest first.
     * @return array<int, array{name:string, hash:string, short_hash:string, subject:string, author:string, date:string}>
     */
    public function getBranchesDetailed(string $repoPath): array
    {
        $result = $this->runGit($repoPath, [
            'for-each-ref',
            '--sort=-committerdate',
            '--format=%(refname:short)%00%(objectname)%00%(objectname:short)%00%(subject)%00%(authorname)%00%(committerdate:iso)',
            'refs/heads/',
        ]);

        if ($result['exitCode'] !== 0) return [];

        return $this->parseRefRows($result['output']);
    }

    /**
     * Return tags with commit details, newest first (annotated tags resolved to their commit).
     * @return array<int, array{name:string, hash:string, short_hash:string, subject:string, author:string, date:string}>
     */
    public function getTagsDetailed(string $repoPath): array
    {
        $result = $this->runGit($repoPath, [
            'for-each-ref',
            '--sort=-creatordate',
            '--format=%(refname:short)%00%(if)%(*objectname)%(then)%(*objectname)%(else)%(objectname)%(end)%00%(if)%(*objectname)%(then)%(*objectname:short)%(else)%(objectname:short)%(end)%00%(subject)%00%(taggername)%00%(creatordate:iso)',
            'refs/tags/',
        ]);

        if ($result['exitCode'] !== 0) return [];

        return $this->parseRefRows($result['output']);
    }

    /**
     * Resolve a ref (branch, tag, or HEAD) to its full 40-character commit SHA.
     */
    public function resolveRef(string $repoPath, string $ref): string
    {
        $this->assertSafeRef($ref);
        $result = $this->runGit($repoPath, ['rev-parse', '--verify', "{$ref}^{commit}"]);
        if ($result['exitCode'] === 0 && !empty(trim($result['output']))) {
            return trim($result['output']);
        }
        $result2 = $this->runGit($repoPath, ['rev-parse', '--verify', $ref]);
        if ($result2['exitCode'] === 0 && !empty(trim($result2['output']))) {
            return trim($result2['output']);
        }
        return '';
    }

    /**
     * Return the current HEAD commit hash (short), or '' for empty repos.
     */
    public function getHeadHash(string $repoPath): string
    {
        $result = $this->runGit($repoPath, ['rev-parse', '--short', 'HEAD']);

        if ($result['exitCode'] !== 0) return '';

        return trim($result['output']);
    }

    /**
     * Blame a file and return one entry per line.
     * @return array<int, array{hash:string, short_hash:string, author:string, date:string, line:int, content:string}>
     */
    public function getBlame(string $repoPath, string $ref, string $path): array
    {
        $this->assertSafeRef($ref);
        $this->assertSafePath($path);

        $result = $this->runGit($repoPath, [
            'blame', '--line-porcelain', $ref, '--', $path,
        ], timeout: 60);

        if ($result['exitCode'] !== 0) return [];

        $lines = [];
        $hash  = '';
        $author = '';
        $date   = '';
        $lineNo = 0;

        foreach (explode("\n", $result['output']) as $raw) {
            // Commit header line: "<40-hex> <orig-line> <final-line> [<count>]"
            if (preg_match('/^([0-9a-f]{40}) (\d+) (\d+)(?: \d+)?$/', $raw, $m)) {
                $hash   = $m[1];
                $lineNo = (int) $m[3];
                continue;
            }

            if (str_starts_with($raw, 'author ')) {
                $author = substr($raw, 7);
            } elseif (str_starts_with($raw, 'author-time ')) {
                $date = date('Y-m-d', (int) substr($raw, 12));
            } elseif (str_starts_with($raw, "\t")) {
                $lines[] = [
                    'hash'       => $hash,
                    'short_hash' => substr($hash, 0, 7),
                    'author'     => $author,
                    'date'       => $date,
                    'line'       => $lineNo,
                    'content'    => substr($raw, 1),
                ];
            }
        }

        return $lines;
    }

    /**
     * Parse NUL-separated for-each-ref rows into structured arrays.
     * @return array<int, array{name:string, hash:string, short_hash:string, subject:string, author:string, date:string}>
     */
    private function parseRefRows(string $output): array
    {
        $rows = [];

        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $parts = explode("\0", $line);

            if (count($parts) < 6) continue;

            $rows[] = [
                'name'       => $parts[0],
                'hash'       => $parts[1],
                'short_hash' => $parts[2],
                'subject'    => $parts[3],
                'author'     => $parts[4],
                'date'       => $parts[5],
            ];
        }

        return $rows;
    }

    /**
     * Execute a git command safely using proc_open with array arguments.
     * @param  string $repoPath  Working directory (the bare repo)
     * @param  array  $args      Git sub-command and arguments (NO shell interpolation)
     * @param  int    $timeout   Max seconds
     * @param  int    $maxBytes  Max bytes to read from stdout
     * @return array{output: string, exitCode: int}
     */
    private function runGit(
        string $repoPath,
        array $args,
        int $timeout = 30,
        int $maxBytes = self::MAX_OUTPUT_BYTES,
    ): array {
        if ($repoPath === '' || !is_dir($repoPath)) {
            return ['output' => '', 'exitCode' => 1];
        }

        // Build the full command array
        static $gitBin = null;
        if ($gitBin === null) {
            $gitBin = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';
        }
        $command = array_merge([$gitBin, '-c', 'core.quotepath=false'], $args);

        // Redirect stdout/stderr to temp FILES instead of pipes.
        // Two earlier approaches both failed on Windows:
        //  - non-blocking pipes + stream_select crash thread-safe PHP builds
        //    used by Apache (mod_php) with an access violation;
        //  - sequential blocking reads (stderr then stdout) DEADLOCK when the
        //    command's stdout fills the pipe buffer: git blocks writing stdout
        //    while we block reading stderr, so the request hangs forever.
        // Temp files have no buffer limit, so git always runs to completion.
        $outFile = tempnam(sys_get_temp_dir(), 'git_out_');
        $errFile = tempnam(sys_get_temp_dir(), 'git_err_');

        $descriptorSpec = [
            0 => ['pipe', 'r'],              // stdin (closed right away)
            1 => ['file', $outFile, 'w'],    // stdout -> file
            2 => ['file', $errFile, 'w'],    // stderr -> file
        ];

        // Under load (process-table pressure, exhausted /tmp inodes) proc_open
        // can transiently fail via posix_spawn. A short retry absorbs the spike
        // instead of surfacing 500s (710 spawn failures were logged on one day).
        $process = false;
        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $process = @proc_open($command, $descriptorSpec, $pipes, $repoPath);
            if (is_resource($process)) break;

            $lastError = error_get_last()['message'] ?? 'proc_open failed';
            $pipes = [];
            // brief pause: 15ms, 45ms
            usleep($attempt === 0 ? 15000 : 45000);
        }

        if (!is_resource($process)) {
            error_log("GitReader: proc_open failed after 3 attempts: {$lastError}");
            if ($outFile && file_exists($outFile)) @unlink($outFile);
            if ($errFile && file_exists($errFile)) @unlink($errFile);
            return ['output' => '', 'exitCode' => 1];
        }

        // Close stdin immediately
        fclose($pipes[0]);

        // Poll for completion with a wall-clock timeout.
        $startTime = time();
        $exitCode  = 1;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                // Valid only on the FIRST read after the process exits
                $exitCode = $status['exitcode'];
                break;
            }

            if (time() - $startTime >= $timeout) {
                @proc_terminate($process, 9);
                @proc_close($process);
                @unlink($outFile);
                @unlink($errFile);
                return ['output' => '', 'exitCode' => 124];
            }

            usleep(5000); // 5 ms
        }

        proc_close($process);

        // Read results back (bounded) and clean up
        $stdout = (string) file_get_contents($outFile, false, null, 0, $maxBytes + 1);
        if (strlen($stdout) > $maxBytes) $stdout = substr($stdout, 0, $maxBytes);

        @unlink($outFile);
        @unlink($errFile);

        return [
            'output'   => $stdout,
            'exitCode' => $exitCode,
        ];
    }

    /** Reject ref strings that contain traversal, null bytes, or shell metacharacters. */
    private function assertSafeRef(string $ref): void
    {
        if ($ref === '') throw new RuntimeException('Ref cannot be empty.');

        if (str_contains($ref, "\0")) throw new RuntimeException('Ref contains null byte.');

        if (str_contains($ref, '..')) throw new RuntimeException('Ref contains path traversal sequence.');

        // A leading dash would be parsed by git as an option (e.g. --output=)
        if ($ref[0] === '-') throw new RuntimeException('Ref cannot start with a dash.');

        // Reject shell metacharacters and whitespace
        if (preg_match('/[;&|`$(){}!<>\'\"\s\\\\]/', $ref)) throw new RuntimeException('Ref contains disallowed characters.');
    }

    /** Reject path strings that contain traversal, null bytes, or shell metacharacters. */
    private function assertSafePath(string $path): void
    {
        if ($path === '') return; // empty path is valid (means root)

        if (str_contains($path, "\0")) throw new RuntimeException('Path contains null byte.');

        if (str_contains($path, '..')) throw new RuntimeException('Path contains traversal sequence.');

        // Reject shell metacharacters (allow / and . for paths)
        if (preg_match('/[;&|`$(){}!<>\'\"\s\\\\]/', $path)) throw new RuntimeException('Path contains disallowed characters.');
    }
}
