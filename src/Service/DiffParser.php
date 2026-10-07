<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parse unified `git diff` output into structured, GitHub-style file entries.
 *
 * Extracted from the duplicated private implementations in
 * RepoController/BranchController so new features (pull requests,
 * code review) can share one canonical parser.
 */
final class DiffParser
{
    /**
     * @return array<int, array{
     *     path: string, old_path: ?string, status: string, binary: bool,
     *     additions: int, deletions: int, truncated: bool,
     *     lines: array<int, array<string, mixed>>
     * }>
     */
    public static function parse(string $diff): array
    {
        if (trim($diff) === '') return [];

        $files   = [];
        $current = null;
        $oldNo   = 0;
        $newNo   = 0;
        $lineCap = 2000;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                if ($current !== null) $files[] = $current;

                $path = '';
                if (preg_match('#^diff --git a/(.+) b/(.+)$#', $line, $m)) {
                    $path = $m[2];
                }

                $current = [
                    'path'      => $path,
                    'old_path'  => null,
                    'status'    => 'modified',
                    'binary'    => false,
                    'additions' => 0,
                    'deletions' => 0,
                    'truncated' => false,
                    'lines'     => [],
                ];
                continue;
            }

            if ($current === null) continue;

            if (str_starts_with($line, 'new file mode'))   { $current['status'] = 'added';   continue; }
            if (str_starts_with($line, 'deleted file mode')) { $current['status'] = 'deleted'; continue; }
            if (str_starts_with($line, 'rename from '))    { $current['old_path'] = substr($line, 12); $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'rename to '))      { $current['path'] = substr($line, 10);     $current['status'] = 'renamed'; continue; }
            if (str_starts_with($line, 'Binary files') || str_starts_with($line, 'GIT binary patch')) {
                $current['binary'] = true;
                continue;
            }
            if (
                str_starts_with($line, 'index ') ||
                str_starts_with($line, 'old mode') ||
                str_starts_with($line, 'new mode') ||
                str_starts_with($line, 'similarity index') ||
                str_starts_with($line, 'dissimilarity index') ||
                str_starts_with($line, 'copy from') ||
                str_starts_with($line, 'copy to') ||
                str_starts_with($line, '--- ') ||
                str_starts_with($line, '+++ ')
            ) {
                continue;
            }

            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m)) {
                $oldNo = (int) $m[1];
                $newNo = (int) $m[2];

                if (count($current['lines']) < $lineCap) {
                    $current['lines'][] = ['type' => 'hunk', 'content' => $line];
                }
                continue;
            }

            if (count($current['lines']) >= $lineCap) {
                $current['truncated'] = true;
            }

            if (str_starts_with($line, '+')) {
                $current['additions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'add', 'old' => null, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $newNo++;
                }
            } elseif (str_starts_with($line, '-')) {
                $current['deletions']++;
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'del', 'old' => $oldNo++, 'new' => null, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                }
            } elseif (str_starts_with($line, '\\')) {
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'meta', 'content' => substr($line, 2)];
                }
            } else {
                if (!$current['truncated']) {
                    $current['lines'][] = ['type' => 'ctx', 'old' => $oldNo++, 'new' => $newNo++, 'content' => substr($line, 1)];
                } else {
                    $oldNo++;
                    $newNo++;
                }
            }
        }

        if ($current !== null) $files[] = $current;

        return $files;
    }
}
