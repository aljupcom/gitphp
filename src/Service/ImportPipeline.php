<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use Throwable;

/**
 * The full repository-import pipeline, shared by both runners:
 *
 *  - bin/import-worker.php (CLI, spawned by the controller when the host
 *    allows detached processes), and
 *  - the web self-runner (RepoImportController::run) which executes the
 *    pipeline inside a long-lived HTTP request after ignore_user_abort —
 *    the only reliable option on shared hosting where proc_open/CLI are
 *    unavailable.
 *
 * Progress is published to storage/tmp/import-{id}.json (atomic writes)
 * and polled by the admin UI. Phases: verify → metadata → clone (REAL
 * percentage from git --progress) → audit → extras → done.
 */
final class ImportPipeline
{
    private App $app;
    private GitService $git;
    private RepoImporter $import;

    public function __construct(App $app)
    {
        $this->app    = $app;
        $this->git    = new GitService();
        $this->import = new RepoImporter();
    }

    /**
     * @param array<string, mixed> $job Job payload (id, slug, url, token, …)
     * @return array<string, mixed> Final status array
     */
    public function execute(array $job): array
    {
        $jobId   = (string) $job['id'];
        $statusF = $this->app->basePath('storage/tmp/import-' . $jobId . '.json');

        $log = [];

        $write = static function (array $status) use ($statusF): void {
            $status['updated_at'] = time();
            $tmp = $statusF . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($tmp, $statusF);
            }
        };

        $status = [
            'id'        => $jobId,
            'slug'      => (string) $job['slug'],
            'name'      => (string) ($job['name'] ?? $job['slug']),
            'phase'     => 'starting',
            'percent'   => 1,
            'message'   => 'Import started',
            'detail'    => '',
            'log'       => [],
            'ok'        => false,
            'done'      => false,
            'error'     => null,
            'repo_url'  => null,
            'summary'   => null,
        ];
        $status['log'] = &$log;

        $set = static function (string $phase, int $percent, string $message, string $detail = '') use (&$status, $write): void {
            $status['phase']   = $phase;
            $status['percent'] = max($status['percent'], min(100, $percent));
            $status['message'] = $message;
            if ($detail !== '') $status['detail'] = $detail;
            $write($status);
        };

        $say = static function (string $line) use (&$status, $write): void {
            $status['log'][] = $line;
            if (count($status['log']) > 40) {
                $status['log'] = array_slice($status['log'], -40);
            }
            $write($status);
        };

        $finishOk = static function (array $extra = []) use (&$status, $write): array {
            $status['done'] = true;
            foreach ($extra as $k => $v) $status[$k] = $v;
            $write($status);
            return $status;
        };

        try {
            $url        = (string) $job['url'];
            $slug       = (string) $job['slug'];
            $token      = (string) ($job['token'] ?? '');
            $authHeader = $this->import->authHeader($url, $token);

            // ── 1 · Verify remote ────────────────────────────────────
            $set('verify', 3, 'Verifying remote repository…');
            try {
                $remoteBranch = $this->import->verifyRemote($url, $token);
                $say("✓ remote verified — default branch: {$remoteBranch}");
            } catch (Throwable $e) {
                return $finishOk(['error' => $e->getMessage()]);
            }
            $set('verify', 6, 'Remote verified', "default branch: {$remoteBranch}");

            // ── 2 · Metadata ─────────────────────────────────────────
            $set('metadata', 8, 'Fetching repository metadata…');
            $metadata = null;
            try {
                $metadata = $this->import->fetchRemoteMetadata($url, $token);
                $say('✓ metadata fetched from ' . ($job['platform'] ?? 'platform') . ' API');
            } catch (Throwable) {
                $say('· metadata unavailable (non-blocking)');
            }
            $set('metadata', 10, 'Metadata ready');

            // ── 3 · Mirror clone with REAL progress ──────────────────
            $set('clone', 10, 'Starting mirror clone…', $url);

            $lastLogAt = 0;
            $onProgress = static function (string $phase, int $percent, string $detail) use (&$status, $set, $say, &$lastLogAt): void {
                if ($phase === 'receiving') {
                    $set('clone', 10 + (int) round($percent * 0.75), "Receiving objects — {$percent}%", $detail);
                    return;
                }
                if ($phase === 'resolving') {
                    $set('clone', 85 + (int) round($percent * 0.10), "Resolving deltas — {$percent}%", $detail);
                    return;
                }
                if ($phase === 'done') {
                    $set('clone', 95, 'Clone completed');
                    $say('✓ mirror clone finished');
                    return;
                }
                $now = time();
                if ($now - $lastLogAt >= 2) {
                    $lastLogAt = $now;
                    $say('· ' . $detail);
                }
            };

            $targetCloneUrl = $token !== '' ? $this->import->authenticatedUrl($url, $token) : $url;
            try {
                $detectedBranch = $this->git->importRepo($slug, $targetCloneUrl, 1800, $authHeader, $onProgress);
            } catch (Throwable $e) {
                if ($token !== '') {
                    try {
                        $detectedBranch = $this->git->importRepo($slug, $url, 1800, '', $onProgress);
                    } catch (Throwable) {
                        return $finishOk(['error' => 'Import failed: ' . $e->getMessage()]);
                    }
                } else {
                    return $finishOk(['error' => 'Import failed: ' . $e->getMessage()]);
                }
            }

            $set('clone', 95, 'Repository mirrored', 'all branches, tags and history');

            // ── 4 · Audit ────────────────────────────────────────────
            $set('audit', 96, 'Auditing imported refs…');
            $audit = null;
            try {
                $audit = $this->import->auditImport($this->git->getRepoPath($slug), $url, $token);
                $say("✓ audit: {$audit['branches']} branches, {$audit['tags']} tags, {$audit['commits']} commits"
                    . ($audit['missing'] !== [] ? ' — WARNING missing refs: ' . implode(', ', array_slice($audit['missing'], 0, 5)) : ''));
            } catch (Throwable) {
                $say('· audit skipped (non-blocking)');
            }

            // ── 5 · Persist the repository record ────────────────────
            $set('finalize', 97, 'Registering repository…');

            $name        = trim((string) ($job['name'] ?? '')) ?: $slug;
            $description = trim((string) ($job['description'] ?? ''));
            $visibility  = ($job['visibility'] ?? 'public') === 'private' ? 'private' : 'public';

            if ($description === '' && $metadata !== null) {
                $description = (string) $metadata['description'];
            }

            $defaultBranch = $detectedBranch !== '' ? $detectedBranch : $remoteBranch;

            $this->app->db()->execute(
                'INSERT INTO `repositories`
                    (`slug`, `name`, `description`, `visibility`, `default_branch`, `source_url`,
                     `stars_count`, `homepage`, `topics`, `owner_user_id`, `created_at`, `updated_at`)
                 VALUES (:slug, :name, :desc, :vis, :branch, :source, :stars, :homepage, :topics, :owner_id, NOW(), NOW())',
                [
                    'slug'     => $slug,
                    'name'     => $name,
                    'desc'     => $description !== '' ? $description : null,
                    'vis'      => $visibility,
                    'branch'   => $defaultBranch,
                    'source'   => $url,
                    'stars'    => $metadata['stars_count'] ?? 0,
                    'homepage' => ($metadata['homepage'] ?? '') !== '' ? $metadata['homepage'] : null,
                    'topics'   => ($metadata['topics'] ?? '') !== '' ? $metadata['topics'] : null,
                    'owner_id' => $job['owner_user_id'] ?? null,
                ],
            );

            $repoId = (int) $this->app->db()->lastInsertId();

            // ── 6 · Wiki pages (best-effort) ─────────────────────────
            $set('extras', 98, 'Importing wiki pages…');
            $wikiCount = 0;
            try {
                foreach ($this->import->fetchWikiPages($url, $token) as $page) {
                    $pageSlug = $this->import->slugify($page['title']);
                    if ($pageSlug === '') continue;
                    $this->app->db()->execute(
                        'INSERT IGNORE INTO `wiki_pages` (`repo_id`, `slug`, `title`, `content`)
                         VALUES (:repo, :slug, :title, :content)',
                        ['repo' => $repoId, 'slug' => $pageSlug, 'title' => $page['title'], 'content' => $page['content']],
                    );
                    $wikiCount++;
                }
                if ($wikiCount > 0) $say("✓ {$wikiCount} wiki pages imported");
            } catch (Throwable) {
                $say('· no wiki repository (skipped)');
            }

            // ── 7 · Issues (best-effort) ─────────────────────────────
            $set('extras', 99, 'Importing issues…');
            $issuesCount = 0;
            try {
                foreach ($this->import->fetchRemoteIssues($url, $token) as $issue) {
                    $this->app->db()->execute(
                        'INSERT INTO `bug_reports` (`repo_id`, `title`, `description`, `status`)
                         VALUES (:repo, :title, :desc, :status)',
                        [
                            'repo'   => $repoId,
                            'title'  => $issue['title'],
                            'desc'   => $issue['description'],
                            'status' => $issue['status'] === 'open' ? 'open' : 'closed',
                        ],
                    );
                    $issuesCount++;
                }
                if ($issuesCount > 0) $say("✓ {$issuesCount} issues imported");
            } catch (Throwable) {
                $say('· issues not available (skipped)');
            }

            // ── 8 · Activity log + done ──────────────────────────────
            $platform = (string) ($job['platform'] ?? 'remote');
            $details  = "Repository '{$name}' imported from {$platform} ({$url})";
            if ($audit !== null) {
                $details .= " — {$audit['branches']} branches, {$audit['tags']} tags, {$audit['commits']} commits";
                if ($audit['missing'] !== []) {
                    $details .= '; WARNING missing refs: ' . implode(', ', array_slice($audit['missing'], 0, 10));
                }
            }
            if ($wikiCount > 0)   $details .= "; {$wikiCount} wiki pages";
            if ($issuesCount > 0) $details .= "; {$issuesCount} issues";

            $this->app->db()->execute(
                'INSERT INTO `activity_log` (`repo_id`, `action`, `details`) VALUES (?, ?, ?)',
                [$repoId, 'imported', $details],
            );

            $summary = $audit !== null
                ? " ({$audit['branches']} branches, {$audit['tags']} tags, {$audit['commits']} commits)"
                : '';
            $extras = [];
            if ($wikiCount > 0)   $extras[] = "{$wikiCount} wiki pages";
            if ($issuesCount > 0) $extras[] = "{$issuesCount} issues";
            if ($extras !== [])   $summary .= ' with ' . implode(' and ', $extras);

            // Clear repo list and counter cache
            $this->app->cache()->forget('admin:nav_counts');
            $this->app->cache()->forget('dashboard:repos');
            $this->app->cache()->forgetPrefix('repos:');

            $set('done', 100, "Repository '{$name}' imported successfully");
            $say('✓ done');

            return $finishOk([
                'ok'       => true,
                'repo_url' => '/' . ($job['owner'] ?? 'admin') . '/' . $slug,
                'summary'  => "Imported from {$platform}{$summary}",
            ]);
        } catch (Throwable $e) {
            return $finishOk(['error' => 'Unexpected error: ' . $e->getMessage()]);
        }
    }
}
