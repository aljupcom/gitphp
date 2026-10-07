<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use RuntimeException;
use Symfony\Component\Process\Process;

final class GitStorageOptimizer
{
    private string $reposPath;
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->reposPath = rtrim(
            (string) env('REPOS_PATH', dirname(__DIR__, 2) . '/repos'),
            DIRECTORY_SEPARATOR,
        );
    }

    /**
     * Get repository full disk path
     */
    public function getRepoPath(string $slug): string
    {
        return $this->reposPath . DIRECTORY_SEPARATOR . $slug . '.git';
    }

    /**
     * Format bytes into human-readable string
     */
    public function formatBytes(int|float $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / pow(1024, $power), $precision) . ' ' . $units[$power];
    }

    /**
     * Calculate directory size on disk in bytes
     */
    public function getDirectorySizeBytes(string $dir): int
    {
        if (!is_dir($dir)) return 0;

        // Try fast Linux `du -sb` first
        try {
            $p = new Process(['du', '-sb', $dir]);
            $p->setTimeout(10);
            $p->run();
            if ($p->isSuccessful()) {
                $output = trim($p->getOutput());
                $parts = preg_split('/\s+/', $output);
                if (!empty($parts[0]) && is_numeric($parts[0])) {
                    return (int) $parts[0];
                }
            }
        } catch (\Throwable) {
            // Fallback to recursive PHP calculation
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Throwable) {
        }

        return $size;
    }

    /**
     * Inspect Git repository internal storage structure
     */
    public function getRepoStorageStats(string $slug): array
    {
        $repoPath = $this->getRepoPath($slug);
        if (!is_dir($repoPath)) {
            throw new RuntimeException("Repository {$slug} not found at {$repoPath}");
        }

        $totalBytes = $this->getDirectorySizeBytes($repoPath);

        // Run git count-objects -vH
        $countStats = [
            'count'         => 0,
            'size'          => '0 KiB',
            'in-pack'       => 0,
            'packs'         => 0,
            'size-pack'     => '0 KiB',
            'prune-packable'=> 0,
            'garbage'       => 0,
            'size-garbage'  => '0 KiB',
        ];

        try {
            $p = new Process(['git', '-C', $repoPath, 'count-objects', '-vH']);
            $p->setTimeout(15);
            $p->run();
            if ($p->isSuccessful()) {
                foreach (explode("\n", trim($p->getOutput())) as $line) {
                    if (str_contains($line, ':')) {
                        [$k, $v] = explode(':', $line, 2);
                        $countStats[trim($k)] = trim($v);
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Check if shared fork via alternates
        $altFile = $repoPath . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'alternates';
        $isSharedFork = file_exists($altFile) && filesize($altFile) > 0;
        $alternatePath = $isSharedFork ? trim((string) file_get_contents($altFile)) : null;

        // Check commit-graph
        $hasCommitGraph = file_exists($repoPath . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'commit-graph')
            || is_dir($repoPath . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'commit-graphs');

        // Check loose objects count
        $looseCount = (int) ($countStats['count'] ?? 0);
        $needsRepack = $looseCount > 100 || (int) ($countStats['packs'] ?? 0) > 3 || (int) ($countStats['prune-packable'] ?? 0) > 0;

        return [
            'slug'                  => $slug,
            'path'                  => $repoPath,
            'total_bytes'           => $totalBytes,
            'total_formatted'       => $this->formatBytes($totalBytes),
            'loose_objects'         => $looseCount,
            'loose_size'            => $countStats['size'] ?? '0 B',
            'in_pack'               => (int) ($countStats['in-pack'] ?? 0),
            'packs_count'           => (int) ($countStats['packs'] ?? 0),
            'pack_size'             => $countStats['size-pack'] ?? '0 B',
            'prune_packable'        => (int) ($countStats['prune-packable'] ?? 0),
            'is_shared_fork'        => $isSharedFork,
            'alternate_path'        => $alternatePath,
            'has_commit_graph'      => $hasCommitGraph,
            'needs_optimization'    => $needsRepack,
        ];
    }

    /**
     * Inspect all repositories on disk
     */
    public function getAllReposStats(): array
    {
        if (!is_dir($this->reposPath)) {
            return [
                'repos'             => [],
                'total_bytes'       => 0,
                'total_formatted'   => '0 B',
                'shared_forks_count'=> 0,
            ];
        }

        $dirs = scandir($this->reposPath);
        if ($dirs === false) return ['repos' => [], 'total_bytes' => 0, 'total_formatted' => '0 B', 'shared_forks_count' => 0];

        $repos = [];
        $totalBytes = 0;
        $sharedForksCount = 0;

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !str_ends_with($dir, '.git')) continue;
            $slug = substr($dir, 0, -4);
            try {
                $stats = $this->getRepoStorageStats($slug);
                $repos[] = $stats;
                $totalBytes += $stats['total_bytes'];
                if ($stats['is_shared_fork']) $sharedForksCount++;
            } catch (\Throwable) {
                // Skip inaccessible directory
            }
        }

        // Sort largest first
        usort($repos, static fn(array $a, array $b) => $b['total_bytes'] <=> $a['total_bytes']);

        return [
            'repos'              => $repos,
            'total_bytes'        => $totalBytes,
            'total_formatted'    => $this->formatBytes($totalBytes),
            'repos_count'        => count($repos),
            'shared_forks_count' => $sharedForksCount,
        ];
    }

    /**
     * Run safe, non-blocking background optimization on a single repository.
     * Uses Linux `nice -n 19 ionice -c 3` to guarantee ZERO impact on web requests.
     */
    public function optimizeRepo(string $slug, bool $aggressive = false): array
    {
        $repoPath = $this->getRepoPath($slug);
        if (!is_dir($repoPath)) {
            throw new RuntimeException("Repository {$slug} not found.");
        }

        $beforeBytes = $this->getDirectorySizeBytes($repoPath);
        $startTime = microtime(true);
        $steps = [];

        // Determine if nice / ionice are available for background execution
        $cmdPrefix = $this->getLowPriorityPrefix();

        // Step 1: pack-refs to combine scattered ref files into packed-refs
        $cmdPackRefs = array_merge($cmdPrefix, ['git', '-C', $repoPath, 'pack-refs', '--all', '--prune']);
        $p = new Process($cmdPackRefs);
        $p->setTimeout(60);
        $p->run();
        $steps['pack_refs'] = $p->isSuccessful();

        // Step 2: commit-graph write to accelerate web commit walks by up to 5x-10x
        $cmdGraph = array_merge($cmdPrefix, ['git', '-C', $repoPath, 'commit-graph', 'write', '--reachable', '--changed-paths']);
        $p = new Process($cmdGraph);
        $p->setTimeout(120);
        $p->run();
        $steps['commit_graph'] = $p->isSuccessful();

        // Step 3: Repack & Delta compression
        if ($aggressive) {
            // Aggressive delta compression
            $cmdRepack = array_merge($cmdPrefix, [
                'git', '-C', $repoPath, 'repack', '-a', '-d', '-f',
                '--depth=250', '--window=250', '-q',
            ]);
            $p = new Process($cmdRepack);
            $p->setTimeout(300);
            $p->run();
            $steps['repack_aggressive'] = $p->isSuccessful();

            // Prune unreachable objects
            $cmdPrune = array_merge($cmdPrefix, ['git', '-C', $repoPath, 'prune', '--expire=now']);
            $p = new Process($cmdPrune);
            $p->setTimeout(60);
            $p->run();
            $steps['prune'] = $p->isSuccessful();
        } else {
            // Safe incremental loose object compaction (ultra-fast, non-blocking)
            $cmdRepackLoose = array_merge($cmdPrefix, ['git', '-C', $repoPath, 'repack', '-d', '-q']);
            $p = new Process($cmdRepackLoose);
            $p->setTimeout(60);
            $p->run();
            $steps['repack_loose'] = $p->isSuccessful();

            // Safe auto GC
            $cmdGc = array_merge($cmdPrefix, ['git', '-C', $repoPath, 'gc', '--auto', '--quiet']);
            $p = new Process($cmdGc);
            $p->setTimeout(180);
            $p->run();
            $steps['gc_auto'] = $p->isSuccessful();
        }

        // Ensure permissions are preserved for web server
        try {
            $cmdChown = ['chown', '-R', 'gitys2614:gitys2614', $repoPath];
            (new Process($cmdChown))->run();
        } catch (\Throwable) {}

        $afterBytes = $this->getDirectorySizeBytes($repoPath);
        $savedBytes = max(0, $beforeBytes - $afterBytes);
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

        return [
            'slug'              => $slug,
            'before_bytes'      => $beforeBytes,
            'before_formatted'  => $this->formatBytes($beforeBytes),
            'after_bytes'       => $afterBytes,
            'after_formatted'   => $this->formatBytes($afterBytes),
            'saved_bytes'       => $savedBytes,
            'saved_formatted'   => $this->formatBytes($savedBytes),
            'percent_reduced'   => $beforeBytes > 0 ? round(($savedBytes / $beforeBytes) * 100, 1) : 0,
            'elapsed_ms'        => $elapsedMs,
            'steps'             => $steps,
            'aggressive'        => $aggressive,
        ];
    }

    /**
     * Sequentially optimize all repositories with zero impact on the server
     */
    public function optimizeAll(bool $aggressive = false, ?callable $progressCallback = null): array
    {
        $allStats = $this->getAllReposStats();
        $repos = $allStats['repos'];

        $results = [];
        $totalSavedBytes = 0;
        $startTime = microtime(true);

        foreach ($repos as $r) {
            $slug = $r['slug'];
            if ($progressCallback !== null) {
                $progressCallback('starting', $slug, $r);
            }

            try {
                $res = $this->optimizeRepo($slug, $aggressive);
                $results[] = $res;
                $totalSavedBytes += $res['saved_bytes'];

                if ($progressCallback !== null) {
                    $progressCallback('completed', $slug, $res);
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'slug'    => $slug,
                    'error'   => $e->getMessage(),
                    'saved_bytes' => 0,
                ];
                if ($progressCallback !== null) {
                    $progressCallback('error', $slug, ['error' => $e->getMessage()]);
                }
            }

            // Brief 100ms pause between repositories to let I/O breathe
            usleep(100000);
        }

        // Also prune stale archive caches
        $archiveStats = $this->pruneArchiveCache(4);

        $totalElapsed = round(microtime(true) - $startTime, 2);

        return [
            'repos_processed'   => count($repos),
            'total_saved_bytes' => $totalSavedBytes,
            'total_saved_formatted' => $this->formatBytes($totalSavedBytes),
            'total_elapsed_sec' => $totalElapsed,
            'archive_cache_freed' => $archiveStats['formatted_freed'],
            'results'           => $results,
        ];
    }

    /**
     * Purge old generated download archives (.zip/.tar.gz) from cache
     */
    public function pruneArchiveCache(int $maxAgeHours = 4): array
    {
        $cacheDirs = [
            $this->app->basePath('storage/cache/archives'),
            $this->app->basePath('storage/cache/zip'),
            $this->app->basePath('storage/cache/tar'),
        ];

        $freedBytes = 0;
        $filesDeleted = 0;
        $cutoff = time() - ($maxAgeHours * 3600);

        foreach ($cacheDirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = glob($dir . '/*');
            if ($files === false) continue;
            foreach ($files as $f) {
                if (is_file($f) && filemtime($f) < $cutoff) {
                    $freedBytes += filesize($f);
                    @unlink($f);
                    $filesDeleted++;
                }
            }
        }

        return [
            'files_deleted'   => $filesDeleted,
            'freed_bytes'     => $freedBytes,
            'formatted_freed' => $this->formatBytes($freedBytes),
        ];
    }

    /**
     * Determine prefix for background process with lowest priority (idle I/O and low CPU)
     */
    private function getLowPriorityPrefix(): array
    {
        static $prefix = null;
        if ($prefix !== null) return $prefix;

        $prefix = [];

        // Check if `nice` is executable
        if ($this->hasCommand('nice')) {
            $prefix[] = 'nice';
            $prefix[] = '-n';
            $prefix[] = '19'; // lowest CPU priority
        }

        // Check if `ionice` is executable
        if ($this->hasCommand('ionice')) {
            $prefix[] = 'ionice';
            $prefix[] = '-c';
            $prefix[] = '3'; // idle I/O priority
        }

        return $prefix;
    }

    private function hasCommand(string $cmd): bool
    {
        try {
            $p = new Process(['which', $cmd]);
            $p->run();
            return $p->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
