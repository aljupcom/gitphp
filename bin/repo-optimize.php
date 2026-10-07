#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GitPHP Safe Repository Storage Optimizer & Housekeeping CLI
 *
 * Interactive Mode:
 *   php bin/repo-optimize.php
 *
 * Direct Batch Flags:
 *   php bin/repo-optimize.php --status       # Show repository storage breakdown & health
 *   php bin/repo-optimize.php --all          # Safely optimize all repositories (Low I/O, zero lag)
 *   php bin/repo-optimize.php --repo=slug    # Optimize a specific repository
 *   php bin/repo-optimize.php --aggressive   # Run deep delta repacking & prune (Off-peak hours)
 *   php bin/repo-optimize.php --prune-cache  # Prune old generated download archives
 */

$basePath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
if (!file_exists($basePath . '/vendor/autoload.php')) {
    $basePath = dirname(__DIR__);
}

$autoload = $basePath . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found at {$autoload}. Run composer install.\n");
    exit(1);
}

require_once $autoload;

// Boot application & storage optimizer
$app = \App\App::boot($basePath);
$optimizer = new \App\Service\GitStorageOptimizer($app);

function printBanner(): void
{
    echo "\n====================================================================\n";
    echo "   GitPHP Safe Storage & Housekeeping CLI                           \n";
    echo "====================================================================\n";
}

function renderStatusTable(\App\Service\GitStorageOptimizer $optimizer): array
{
    echo "Inspecting repositories on disk...\n\n";
    $stats = $optimizer->getAllReposStats();

    printf("%-4s %-26s %-12s %-8s %-8s %-13s %-10s\n", "#", "Repository", "Disk Size", "Packs", "Loose", "CommitGraph", "Status");
    echo str_repeat('-', 84) . "\n";

    $i = 1;
    foreach ($stats['repos'] as $r) {
        $status = $r['is_shared_fork'] ? "Fork (Shared)" : ($r['needs_optimization'] ? "Needs Opt." : "Optimized");
        $graph  = $r['has_commit_graph'] ? "Yes" : "No";

        printf("%-4s %-26s %-12s %-8s %-8s %-13s %-10s\n",
            "[{$i}]",
            substr($r['slug'], 0, 25),
            $r['total_formatted'],
            $r['packs_count'],
            $r['loose_objects'],
            $graph,
            $status
        );
        $i++;
    }

    echo str_repeat('-', 84) . "\n";
    echo " Repositories: {$stats['repos_count']} | Shared Forks: {$stats['shared_forks_count']} | Total Size: {$stats['total_formatted']}\n";

    return $stats['repos'];
}

function runOptimizeAll(\App\Service\GitStorageOptimizer $optimizer, bool $aggressive = false): void
{
    echo "\nStarting safe housekeeping on ALL repositories...\n";
    echo "Mode:     " . ($aggressive ? "Aggressive Repacking" : "Safe Auto-GC") . "\n";
    echo "Priority: Idle I/O & Low CPU (nice -n 19 ionice -c 3)\n";
    echo "Impact:   Zero server disruption, site remains fast.\n\n";

    $res = $optimizer->optimizeAll($aggressive, function(string $stage, string $slug, array $data) {
        if ($stage === 'starting') {
            echo " [..] Processing {$slug} ({$data['total_formatted']})...\n";
        } elseif ($stage === 'completed') {
            echo " [OK] {$slug}: Saved {$data['saved_formatted']} (now {$data['after_formatted']})\n";
        } elseif ($stage === 'error') {
            echo " [!!] {$slug}: ERROR - " . ($data['error'] ?? 'Unknown error') . "\n";
        }
    });

    echo "\n" . str_repeat('-', 70) . "\n";
    echo " Summary:\n";
    echo " - Repositories processed: {$res['repos_processed']}\n";
    echo " - Total disk space freed: {$res['total_saved_formatted']}\n";
    echo " - Archive cache purged:   {$res['archive_cache_freed']}\n";
    echo " - Total time elapsed:     {$res['total_elapsed_sec']} seconds\n";
    echo " Status: Complete.\n";
}

function runOptimizeSingle(\App\Service\GitStorageOptimizer $optimizer, string $targetRepo, bool $aggressive = false): void
{
    echo "\nOptimizing: {$targetRepo} " . ($aggressive ? "(Aggressive)" : "(Safe Auto)") . "\n";
    echo "Priority:   Idle I/O & Low CPU (nice -n 19 ionice -c 3)...\n\n";

    try {
        $res = $optimizer->optimizeRepo($targetRepo, $aggressive);
        echo " - Original size:  {$res['before_formatted']}\n";
        echo " - Size after opt: {$res['after_formatted']}\n";
        echo " - Space saved:    {$res['saved_formatted']} ({$res['percent_reduced']}%)\n";
        echo " - Execution time: {$res['elapsed_ms']} ms\n";
        echo " - Steps:          " . json_encode($res['steps']) . "\n";
        echo "\nSUCCESS: Repository {$targetRepo} optimized!\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");
    }
}

function runPruneCache(\App\Service\GitStorageOptimizer $optimizer): void
{
    echo "\nPruning expired download cache (> 24 hours)...\n\n";
    $res = $optimizer->pruneArchiveCache(24);
    echo " - Stale files deleted: {$res['files_deleted']}\n";
    echo " - Space freed:         {$res['formatted_freed']}\n";
    echo " Status: Cache pruned.\n";
}

// -----------------------------------------------------------------------------
// Check for non-interactive CLI flags (for cron, automation, or direct calls)
// -----------------------------------------------------------------------------
$cliMode = null;
$targetRepo = null;
$aggressive = false;

foreach ($argv as $arg) {
    if ($arg === '--status') {
        $cliMode = 'status';
    } elseif ($arg === '--all') {
        $cliMode = 'all';
    } elseif ($arg === '--aggressive') {
        $aggressive = true;
    } elseif ($arg === '--prune-cache') {
        $cliMode = 'prune-cache';
    } elseif (str_starts_with($arg, '--repo=')) {
        $cliMode = 'repo';
        $targetRepo = trim(substr($arg, 7));
    }
}

if ($cliMode !== null) {
    printBanner();
    if ($cliMode === 'status') {
        renderStatusTable($optimizer);
    } elseif ($cliMode === 'all') {
        runOptimizeAll($optimizer, $aggressive);
    } elseif ($cliMode === 'repo') {
        if (!$targetRepo) {
            fwrite(STDERR, "Error: Must specify repository slug via --repo=slug\n");
            exit(1);
        }
        runOptimizeSingle($optimizer, $targetRepo, $aggressive);
    } elseif ($cliMode === 'prune-cache') {
        runPruneCache($optimizer);
    }
    echo "====================================================================\n";
    exit(0);
}

// -----------------------------------------------------------------------------
// Interactive Menu Mode
// -----------------------------------------------------------------------------
while (true) {
    printBanner();
    echo "Select an option:\n\n";
    echo "  [1] Storage Status\n";
    echo "  [2] Optimize All Repos\n";
    echo "  [3] Optimize Single Repo\n";
    echo "  [4] Prune Cache\n";
    echo "  [5] Exit\n\n";

    echo "Choice [1-5]: ";
    $choice = trim((string) fgets(STDIN));

    if ($choice === '1') {
        echo "\n";
        renderStatusTable($optimizer);
    } elseif ($choice === '2') {
        runOptimizeAll($optimizer, false);
    } elseif ($choice === '3') {
        echo "\n";
        $repos = renderStatusTable($optimizer);
        echo "\nEnter repo # [1-" . count($repos) . "] or slug: ";
        $selectedInput = trim((string) fgets(STDIN));

        $slugToOptimize = null;
        if (ctype_digit($selectedInput)) {
            $idx = ((int) $selectedInput) - 1;
            if (isset($repos[$idx])) {
                $slugToOptimize = $repos[$idx]['slug'];
            }
        } else {
            $slugToOptimize = $selectedInput;
        }

        if (!$slugToOptimize) {
            echo "\nInvalid selection. Returning to menu.\n";
        } else {
            runOptimizeSingle($optimizer, $slugToOptimize, false);
        }
    } elseif ($choice === '4') {
        runPruneCache($optimizer);
    } elseif ($choice === '5' || strtolower($choice) === 'q' || strtolower($choice) === 'exit') {
        echo "\nGoodbye!\n\n";
        break;
    } else {
        echo "\nInvalid choice '{$choice}'. Enter 1-5.\n";
    }

    echo "\nPress [Enter] to continue (or 'q' to quit): ";
    $next = trim((string) fgets(STDIN));
    if (strtolower($next) === 'q') {
        echo "\nGoodbye!\n\n";
        break;
    }
}
