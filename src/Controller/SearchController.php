<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\GitReader;
use App\Service\GitService;
use Symfony\Component\Process\Process;

final class SearchController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private GitReader $gitReader;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->auth       = new Auth($app);
        $this->gitService = new GitService();
        $this->gitReader  = new GitReader();
    }

    /**
     * GET /api/search/suggest?q= — instant typeahead suggestions (DB only,
     * no subprocesses, so it is fast enough for keystroke-by-keystroke).
     * Returns grouped JSON: repositories, users, issues, pulls, type.
     */
    public function suggest(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        if ($q === '' || mb_strlen($q) < 2) {
            $this->jsonSuggest(['query' => $q, 'repositories' => [], 'users' => [], 'issues' => [], 'pulls' => []]);
            return;
        }

        $like = '%' . $q . '%';
        $db   = $this->app->db()->connection();

        // Repositories (name / slug / description), visibility-filtered.
        $repoRows = $db->prepare(
            'SELECT r.`id`, r.`slug`, r.`name`, r.`visibility`, u.`username` AS `owner_username`
             FROM `repositories` r
             LEFT JOIN `users` u ON u.id = r.owner_user_id
             WHERE r.`name` LIKE ? OR r.`slug` LIKE ? OR r.`description` LIKE ?
             ORDER BY r.`updated_at` DESC LIMIT 8'
        );
        $repoRows->execute([$like, $like, $like]);
        $ownerName = (string) $this->app->config('app.owner', 'admin');

        $repos = [];
        $visibleRepoIds = [];
        foreach ($repoRows->fetchAll() as $r) {
            if (! $this->auth->canViewRepo((int) $r['id'], (string) $r['visibility'])) continue;
            $visibleRepoIds[(int) $r['id']] = true;
            $repos[] = [
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'visibility' => $r['visibility'],
                'owner'      => ! empty($r['owner_username']) ? $r['owner_username'] : $ownerName,
            ];
        }

        // Users (public profiles).
        $userRows = $db->prepare('SELECT `username` FROM `users` WHERE `username` LIKE ? ORDER BY `created_at` DESC LIMIT 5');
        $userRows->execute([$like]);
        $users = array_map(static fn(array $u): array => ['username' => $u['username']], $userRows->fetchAll());

        // Issues in visible repos only.
        $issues = [];
        if ($visibleRepoIds !== []) {
            $in = implode(',', array_map('intval', array_keys($visibleRepoIds)));
            $issueRows = $db->prepare(
                "SELECT b.`id`, b.`title`, b.`status`, r.`slug` AS `repo_slug`, r.`name` AS `repo_name`,
                        COALESCE(u.`username`, ?) AS `repo_owner`
                 FROM `bug_reports` b
                 JOIN `repositories` r ON r.id = b.repo_id
                 LEFT JOIN `users` u ON u.id = r.owner_user_id
                 WHERE b.repo_id IN ({$in}) AND b.`title` LIKE ? ORDER BY b.`updated_at` DESC LIMIT 5"
            );
            $issueRows->execute([$ownerName, $like]);
            $issues = $issueRows->fetchAll();
        }

        // Pull requests in visible repos only.
        $pulls = [];
        if ($visibleRepoIds !== []) {
            $in = implode(',', array_map('intval', array_keys($visibleRepoIds)));
            $pullRows = $db->prepare(
                "SELECT pr.`number`, pr.`title`, pr.`status`, r.`slug` AS `repo_slug`, r.`name` AS `repo_name`,
                        COALESCE(u.`username`, ?) AS `repo_owner`
                 FROM `pull_requests` pr
                 JOIN `repositories` r ON r.id = pr.repo_id
                 LEFT JOIN `users` u ON u.id = r.owner_user_id
                 WHERE pr.repo_id IN ({$in}) AND pr.`title` LIKE ? ORDER BY pr.`updated_at` DESC LIMIT 5"
            );
            $pullRows->execute([$ownerName, $like]);
            $pulls = $pullRows->fetchAll();
        }

        $this->jsonSuggest([
            'query'        => $q,
            'repositories' => $repos,
            'users'        => $users,
            'issues'       => $issues,
            'pulls'        => $pulls,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function jsonSuggest(array $data): never
    {
        echo (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** GET /search?q={query}&type={repositories|code|commits} */
    public function search(): void
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $type  = (string) ($_GET['type'] ?? 'repositories');
        $type  = in_array($type, ['repositories', 'code', 'commits', 'users', 'issues', 'pulls'], true) ? $type : 'repositories';
        $owner = (string) $this->app->config('app.owner', 'admin');

        // Throttle the subprocess-heavy search surfaces.
        $rateLimiter = new \App\Middleware\RateLimit($this->app);
        if (! $rateLimiter->check('search_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), maxAttempts: 20, decayMinutes: 1)) {
            http_response_code(429);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 429, 'message' => 'Too many searches. Please try again shortly.']);
            return;
        }

        $repoResults   = [];
        $codeResults   = [];
        $commitResults = [];
        $userResults   = [];
        $issueResults  = [];
        $pullResults   = [];

        if ($query !== '') {
            $db  = $this->app->db()->connection();
            $cache = $this->app->cache();
            $like = "%{$query}%";

            // 1. Repositories
            if ($type === 'repositories' || $type === 'all') {
                $stmt = $db->prepare("
                    SELECT r.*, u.username AS owner_username,
                           (SELECT COUNT(*) FROM repo_likes WHERE repo_id = r.id) AS stars_count,
                           (SELECT COUNT(*) FROM repositories WHERE forked_from_id = r.id) AS forks_count
                    FROM repositories r
                    LEFT JOIN users u ON u.id = r.owner_user_id
                    WHERE (r.name LIKE ? OR r.description LIKE ? OR r.slug LIKE ?)
                    ORDER BY r.updated_at DESC LIMIT 30
                ");
                $stmt->execute([$like, $like, $like]);
                foreach ($stmt->fetchAll() as $r) {
                    if ($this->auth->canViewRepo((int) $r['id'], (string) $r['visibility'])) {
                        $r['display_owner'] = !empty($r['owner_username']) ? (string) $r['owner_username'] : $owner;
                        $repoResults[] = $r;
                    }
                }
            }

            // 1b. Users (public profiles) — runs for the users tab and "all".
            if ($type === 'users' || $type === 'all') {
                $userStmt = $db->prepare('SELECT `username` FROM `users` WHERE `username` LIKE ? ORDER BY `created_at` DESC LIMIT 20');
                $userStmt->execute([$like]);
                $userResults = $userStmt->fetchAll();
            }

            // 1c. Issues & pull requests across visible repos — runs for
            // their own tabs too, not only when the repositories tab is on.
            if ($type === 'issues' || $type === 'pulls' || $type === 'repositories' || $type === 'all') {
                $visibleIds = [];
                foreach ($repoResults as $r) $visibleIds[] = (int) $r['id'];
                if ($visibleIds === []) {
                    // Still pull ids from repos the viewer can see, even if none
                    // matched by name, by scanning recent visible repos.
                    $any = $db->query('SELECT id, visibility FROM repositories ORDER BY updated_at DESC LIMIT 40')->fetchAll();
                    foreach ($any as $r) {
                        if ($this->auth->canViewRepo((int) $r['id'], (string) $r['visibility'])) $visibleIds[] = (int) $r['id'];
                    }
                }
                if ($visibleIds !== []) {
                    $in = implode(',', array_map('intval', array_unique($visibleIds)));
                    if ($type === 'issues' || $type === 'all') {
                        $issueRows = $db->prepare(
                            "SELECT b.`id`, b.`title`, b.`status`, r.`slug` AS `repo_slug`, r.`name` AS `repo_name`,
                                    COALESCE(ru.`username`, :owner_issues) AS `repo_owner`
                             FROM `bug_reports` b
                             JOIN `repositories` r ON r.id = b.repo_id
                             LEFT JOIN `users` ru ON ru.id = r.owner_user_id
                             WHERE b.repo_id IN ({$in}) AND b.`title` LIKE ? ORDER BY b.`updated_at` DESC LIMIT 20"
                        );
                        $issueRows->execute(array_merge(['owner_issues' => $owner], [$like]));
                        $issueResults = $issueRows->fetchAll();
                    }

                    if ($type === 'pulls' || $type === 'all') {
                        $pullRows = $db->prepare(
                            "SELECT pr.`number`, pr.`title`, pr.`status`, r.`slug` AS `repo_slug`, r.`name` AS `repo_name`,
                                    COALESCE(ru.`username`, :owner_pulls) AS `repo_owner`
                             FROM `pull_requests` pr
                             JOIN `repositories` r ON r.id = pr.repo_id
                             LEFT JOIN `users` ru ON ru.id = r.owner_user_id
                             WHERE pr.repo_id IN ({$in}) AND pr.`title` LIKE ? ORDER BY pr.`updated_at` DESC LIMIT 20"
                        );
                        $pullRows->execute(array_merge(['owner_pulls' => $owner], [$like]));
                        $pullResults = $pullRows->fetchAll();
                    }
                }
            }

            // 2. Code across ALL visible repositories (memoized briefly).
            // The cache key MUST include the viewer's identity: results are
            // computed under per-user repo visibility, so sharing a key
            // between logged-in users would leak private-repo code.
            if ($type === 'code' || $type === 'all') {
                $codeCacheKey = 'search:code:' . md5($query . ':' . $owner . ':' . $this->searchCacheScope());
                $codeResults = (array) $cache->remember(
                    $codeCacheKey,
                    120,
                    function () use ($db, $query, $owner) {
                        return $this->runCodeSearch($db, $query, $owner);
                    },
                );
            }

            // 3. Commits (memoized briefly) across repos matching the query.
            if ($type === 'commits' || $type === 'all') {
                $commitCacheKey = 'search:commits:' . md5($query . ':' . $owner . ':' . $this->searchCacheScope());
                $commitResults = (array) $cache->remember(
                    $commitCacheKey,
                    120,
                    function () use ($repoResults, $query) {
                        return $this->runCommitSearch($repoResults, $query);
                    },
                );
            }
        }

        $this->app->view()->display('search.twig', [
            'owner'          => $owner,
            'query'          => $query,
            'type'           => $type,
            'repo_results'   => $repoResults,
            'code_results'   => $codeResults,
            'commit_results' => $commitResults,
            'user_results'   => $userResults,
            'issue_results'  => $issueResults,
            'pull_results'   => $pullResults,
        ]);
    }

    /**
     * Cache scope for per-user search results.
     *
     * Guests share a single scope ("guest") — they all see the same
     * public-only results. Every logged-in identity gets a private
     * scope so results computed under their repo visibility can never
     * be served to another account (or to a guest).
     */
    private function searchCacheScope(): string
    {
        if (! $this->auth->isLoggedIn()) return 'guest';
        if ($this->auth->isOwner()) return 'owner';

        return 'u' . $this->auth->userId();
    }

    /** @return array<int, array<string, mixed>> */
    private function runCodeSearch(\PDO $db, string $query, string $owner): array
    {
        $results = [];

        $scan = $db->query('SELECT `id`, `slug`, `name`, `visibility`, `owner_user_id` FROM `repositories` ORDER BY `updated_at` DESC LIMIT 60')->fetchAll();

        // Resolve owner usernames once (avoids a per-repo query inside the loop).
        $ownerNames = [];
        foreach ($scan as $r) {
            $ownerNames[(int) $r['owner_user_id']] = null;
        }
        if ($ownerNames !== []) {
            $in = implode(',', array_map('intval', array_keys($ownerNames)));
            foreach ($db->query("SELECT `id`, `username` FROM `users` WHERE `id` IN ({$in})")->fetchAll() as $u) {
                $ownerNames[(int) $u['id']] = (string) $u['username'];
            }
        }

        $scanned = 0;
        foreach ($scan as $repoRow) {
            if (! $this->auth->canViewRepo((int) $repoRow['id'], (string) $repoRow['visibility'])) continue;
            if (++$scanned > 25) break;

            $repoPath = $this->gitService->getRepoPath($repoRow['slug']);
            if (! is_dir($repoPath)) continue;

            // A repo that exceeds the timeout is skipped rather than failing
            // the whole search page with a 500 (Symfony throws on timeout).
            try {
                $proc = new Process(['git', 'grep', '-n', '-I', '-i', '-e', $query, 'HEAD'], $repoPath);
                $proc->setTimeout(3);
                $proc->run();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
                continue;
            }

            if (! $proc->isSuccessful()) continue;

            $repoRow['display_owner'] = $ownerNames[(int) $repoRow['owner_user_id']] ?? $owner;

            $lines = explode("\n", trim($proc->getOutput()));
            foreach ($lines as $line) {
                if ($line === '') continue;
                if (preg_match('/^HEAD:([^:]+):(\d+):(.*)$/', $line, $m)) {
                    $results[] = ['repo' => $repoRow, 'file' => $m[1], 'line_num' => (int) $m[2], 'content' => $m[3]];
                    if (count($results) >= 50) return $results;
                }
            }
        }

        return $results;
    }

    /** @return array<int, array<string, mixed>> */
    private function runCommitSearch(array $repoResults, string $query): array
    {
        $results = [];

        foreach ($repoResults as $r) {
            $repoPath = $this->gitService->getRepoPath($r['slug']);
            if (! is_dir($repoPath)) continue;

            // Same as code search: skip repositories that exceed the timeout.
            try {
                $proc = new Process([
                    'git', 'log', '--grep=' . $query, '-i', '--format=%H%x1f%an%x1f%ae%x1f%at%x1f%s', '-n', '10', 'HEAD',
                ], $repoPath);
                $proc->setTimeout(3);
                $proc->run();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
                continue;
            }

            if (! $proc->isSuccessful()) continue;

            $displayOwner = $r['display_owner'] ?? null;

            foreach (explode("\n", trim($proc->getOutput())) as $e) {
                if ($e === '') continue;
                $parts = explode("\x1f", $e);
                if (count($parts) >= 5) {
                    $repo = $r;
                    if ($displayOwner !== null) $repo['display_owner'] = $displayOwner;
                    $results[] = [
                        'repo' => $repo, 'hash' => $parts[0], 'author_name' => $parts[1],
                        'author_email' => $parts[2], 'date' => (int) $parts[3], 'subject' => $parts[4],
                    ];
                    if (count($results) >= 30) return $results;
                }
            }
        }

        return $results;
    }

    /** GET /{user}/{repo}/search — in-repo search (code, issues, PRs, wiki). */
    public function repoSearch(string $user, string $repo): void
    {
        $repoRow = $this->app->db()->fetchOne(
            'SELECT r.*, u.`username` AS `owner_username` FROM `repositories` r
             LEFT JOIN `users` u ON u.id = r.owner_user_id
             WHERE r.`slug` = :slug LIMIT 1',
            ['slug' => $repo],
        );

        if ($repoRow === false || ! $this->auth->canViewRepo((int) $repoRow['id'], (string) $repoRow['visibility'])) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', ['code' => 404, 'message' => 'Repository not found.']);
            return;
        }

        $displayOwner = (string) ($repoRow['owner_username'] ?? $user);
        $q = trim((string) ($_GET['q'] ?? ''));
        $codeResults = [];
        $issueResults = [];
        $pullResults = [];
        $wikiResults = [];
        $truncated = false;

        if ($q !== '' && mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';

            // Code (git grep on HEAD, bounded).
            $repoPath = $this->gitService->getRepoPath($repoRow['slug']);
            if (is_dir($repoPath)) {
                try {
                    $proc = new Process(['git', 'grep', '-n', '-I', '-i', '-e', $q, 'HEAD'], $repoPath);
                    $proc->setTimeout(5);
                    $proc->run();
                    if ($proc->isSuccessful()) {
                        foreach (explode("\n", trim($proc->getOutput())) as $line) {
                            if ($line === '') continue;
                            if (preg_match('/^HEAD:([^:]+):(\d+):(.*)$/', $line, $m)) {
                                $codeResults[] = ['file' => $m[1], 'line_num' => (int) $m[2], 'content' => $m[3]];
                                if (count($codeResults) >= 50) { $truncated = true; break; }
                            }
                        }
                    }
                } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException) {
                    $truncated = true; // huge repo — report but keep page usable
                }
            }

            // Issues
            $issueResults = $this->app->db()->fetchAll(
                'SELECT `id`, `title`, `status`, `created_at` FROM `bug_reports`
                 WHERE `repo_id` = :repo AND `title` LIKE :like ORDER BY `updated_at` DESC LIMIT 20',
                ['repo' => (int) $repoRow['id'], 'like' => $like],
            );

            // Pull requests
            $pullResults = $this->app->db()->fetchAll(
                'SELECT `number`, `title`, `status`, `created_at` FROM `pull_requests`
                 WHERE `repo_id` = :repo AND `title` LIKE :like ORDER BY `updated_at` DESC LIMIT 20',
                ['repo' => (int) $repoRow['id'], 'like' => $like],
            );

            // Wiki pages
            $wikiResults = $this->app->db()->fetchAll(
                'SELECT `slug`, `title` FROM `wiki_pages`
                 WHERE `repo_id` = :repo AND (`title` LIKE :like OR `content` LIKE :like) LIMIT 20',
                ['repo' => (int) $repoRow['id'], 'like' => $like],
            );
        }

        $this->app->view()->display('repo/search.twig', [
            'owner'        => $displayOwner,
            'repo'         => $repoRow,
            'query'        => $q,
            'code_results' => $codeResults,
            'issue_results'=> $issueResults,
            'pull_results' => $pullResults,
            'wiki_results' => $wikiResults,
            'truncated'    => $truncated,
        ]);
    }
}
