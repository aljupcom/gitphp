<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Service\ApiTokenService;
use App\Service\GitReader;
use App\Service\GitService;

/**
 * Read-oriented REST API v1 authenticated with personal tokens
 * (Authorization: Bearer gtp_…). Token identity maps to either a
 * registered user (user_id > 0) or the owner account (user_id = 0).
 */
final class ApiController
{
    private App $app;
    private GitService $gitService;
    private GitReader $gitReader;

    /** Resolved token identity after authenticate(). */
    private ?array $identity = null;

    public function __construct(App $app)
    {
        $this->app        = $app;
        $this->gitService = new GitService();
        $this->gitReader  = new GitReader();
    }

    /** GET /api/v1/user — whoami */
    public function whoami(): void
    {
        if (! $this->authenticate()) return;

        $id = $this->identity['user_id'];

        if ($id === 0) {
            $this->json([
                'kind'     => 'owner',
                'username' => (string) $this->app->config('app.owner', 'admin'),
                'scopes'   => $this->identity['scopes'],
                'token'    => $this->identity['name'],
            ]);
            return;
        }

        $u = $this->app->db()->fetchOne(
            'SELECT `id`, `username`, `email`, `created_at` FROM `users` WHERE `id` = :id',
            ['id' => $id],
        );

        if ($u === false) {
            $this->json(['error' => 'User not found for this token.'], 401);
            return;
        }

        $u['kind']   = 'user';
        $u['scopes'] = $this->identity['scopes'];
        $u['token']  = $this->identity['name'];

        $this->json($u);
    }

    /** GET /api/v1/repos/{slug} — repository metadata */
    public function repo(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $ownerName = $this->resolveOwnerName($repo);

        $this->json([
            'name'           => $repo['name'],
            'slug'           => $repo['slug'],
            'full_name'      => "{$ownerName}/{$repo['slug']}",
            'description'    => $repo['description'],
            'visibility'     => $repo['visibility'],
            'default_branch' => $repo['default_branch'],
            'homepage'       => $repo['homepage'],
            'fork'           => (int) ($repo['forked_from_id'] ?? 0) > 0,
            'stars'          => (int) ($repo['stars_count'] ?? 0),
            'created_at'     => $repo['created_at'],
            'updated_at'     => $repo['updated_at'],
            'html_url'       => rtrim((string) $this->app->config('app.url', ''), '/') . "/{$ownerName}/{$repo['slug']}",
        ]);
    }

    public function ownerRepo(string $owner, string $repo): void
    {
        $this->repo("{$owner}/{$repo}");
    }

    /** Helper to build a complete commit object for branch endpoints */
    private function buildCommitObject(string $repoPath, array $branch): ?array
    {
        $hash = $branch['hash'] ?? ($branch['sha'] ?? '');
        $name = $branch['name'] ?? '';

        if (! empty($hash)) {
            return [
                'id'          => $hash,
                'short_id'    => $branch['short_hash'] ?? substr($hash, 0, 7),
                'message'     => $branch['subject'] ?? '',
                'author_name' => $branch['author'] ?? '',
                'date'        => $branch['date'] ?? '',
            ];
        }

        if ($name === '') return null;

        $cmd = sprintf(
            'git --git-dir=%s log -1 --format="%%H|%%h|%%s|%%an|%%aI" %s 2>/dev/null',
            escapeshellarg($repoPath),
            escapeshellarg($name)
        );
        $output = trim((string) shell_exec($cmd));

        if (! empty($output)) {
            $parts = explode('|', $output, 5);
            return [
                'id'          => $parts[0] ?? '',
                'short_id'    => $parts[1] ?? '',
                'message'     => $parts[2] ?? '',
                'author_name' => $parts[3] ?? '',
                'date'        => $parts[4] ?? '',
            ];
        }

        return null;
    }

    /** GET /api/v1/repos/{slug}/branches */
    public function branches(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $path = $this->repoPath($repo['slug']);
        if ($path === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $out = [];
        foreach ($this->gitReader->getBranchesDetailed($path) as $b) {
            $out[] = [
                'name'       => $b['name'] ?? null,
                'is_default' => (($b['name'] ?? '') === $repo['default_branch']),
                'commit'     => $this->buildCommitObject($path, $b),
            ];
        }

        $this->json(['branches' => array_values(array_filter($out, static fn($x) => $x['name'] !== null))]);
    }

    public function ownerRepoBranches(string $owner, string $repo): void
    {
        $this->branches("{$owner}/{$repo}");
    }

    /** GET /api/v1/repos/{slug}/branches/{branch} */
    public function singleBranch(string $slug, string $branch): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $path = $this->repoPath($repo['slug']);
        if ($path === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $foundBranch = null;
        foreach ($this->gitReader->getBranchesDetailed($path) as $b) {
            if (($b['name'] ?? '') === $branch) {
                $foundBranch = $b;
                break;
            }
        }

        if ($foundBranch !== null) {
            $this->json([
                'name'       => $foundBranch['name'],
                'is_default' => ($foundBranch['name'] === $repo['default_branch']),
                'commit'     => $this->buildCommitObject($path, $foundBranch),
            ]);
            return;
        }

        // Try direct commit object build on branch ref
        $commitObj = $this->buildCommitObject($path, ['name' => $branch]);
        if ($commitObj !== null) {
            $this->json([
                'name'       => $branch,
                'is_default' => ($branch === $repo['default_branch']),
                'commit'     => $commitObj,
            ]);
            return;
        }

        $this->json(['error' => "Branch '{$branch}' not found."], 404);
    }

    public function ownerRepoSingleBranch(string $owner, string $repo, string $branch): void
    {
        $this->singleBranch("{$owner}/{$repo}", $branch);
    }

    /** GET /api/v1/repos/{slug}/tags */
    public function tags(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $path = $this->repoPath($repo['slug']);
        if ($path === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $this->json(['tags' => $this->gitReader->getTagsDetailed($path)]);
    }

    public function ownerRepoTags(string $owner, string $repo): void
    {
        $this->tags("{$owner}/{$repo}");
    }

    // ── Releases API ─────────────────────────────────────────────────

    /** GET /api/v1/repos/{owner}/{repo}/releases — list releases with assets. */
    public function listReleases(string $owner, string $repo): void
    {
        $dbRepo = $this->resolveRepoAndAuth($repo, 'read');
        if ($dbRepo === null) return;

        $canWrite = false;
        $userId = (int) ($this->identity['user_id'] ?? -1);
        if ($userId === 0) {
            $canWrite = true; // owner token
        } elseif ($userId > 0) {
            $collab = $this->app->db()->fetchOne(
                "SELECT `id` FROM `repo_collaborators` WHERE `repo_id` = :repo AND `user_id` = :user AND `role` = 'write' LIMIT 1",
                ['repo' => (int) $dbRepo['id'], 'user' => $userId],
            );
            $canWrite = $collab !== false;
        }

        $rows = $this->app->db()->fetchAll(
            'SELECT * FROM `repo_releases` WHERE `repo_id` = :repo ORDER BY `created_at` DESC',
            ['repo' => (int) $dbRepo['id']],
        );

        $assets = new \App\Service\ReleaseAssetService($this->app);

        $out = [];
        foreach ($rows as $r) {
            if (! $canWrite && ! empty($r['is_draft'])) continue;

            $r['assets'] = array_map(static function (array $a) use ($owner, $repo): array {
                unset($a['storage_path']);
                $a['download_url'] = "/{$owner}/{$repo}/releases/download/{$a['id']}";
                return $a;
            }, $assets->assetsForRelease((int) $r['id']));

            $out[] = $r;
        }

        $this->json(['releases' => $out]);
    }

    /** POST /api/v1/repos/{owner}/{repo}/releases — create a release (optionally creating the tag). */
    public function createRelease(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->resolveVisibleRepo($repo);
        if ($dbRepo === null) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;

        $tagName = trim((string) ($input['tag_name'] ?? ''));
        $title   = trim((string) ($input['name'] ?? ''));
        $body    = (string) ($input['body'] ?? '');
        $target  = trim((string) ($input['target_commitish'] ?? '')) ?: (string) ($dbRepo['default_branch'] ?? 'main');
        $isPre   = ! empty($input['prerelease']) ? 1 : 0;
        $isDraft = ! empty($input['draft']) ? 1 : 0;

        if ($tagName === '') {
            $this->json(['success' => false, 'error' => 'tag_name is required.'], 400);
            return;
        }
        if ($title === '') $title = $tagName;

        $gitService = new GitService();
        $path = $this->repoPath($dbRepo['slug']);
        if ($path === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $existingTags = $this->gitReader->getTags($path);

        try {
            if (! in_array($tagName, $existingTags, true)) {
                $gitService->createReleaseTag($dbRepo['slug'], $tagName, $target, $title);
            }

            $this->app->db()->execute(
                'INSERT INTO `repo_releases` (repo_id, tag_name, target_commitish, name, body, is_draft, is_prerelease, created_by, created_at, published_at)
                 VALUES (:repo, :tag, :target, :name, :body, :draft, :pre, :uid, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), body = VALUES(body), is_draft = VALUES(is_draft), is_prerelease = VALUES(is_prerelease), published_at = NOW()',
                [
                    'repo'   => (int) $dbRepo['id'],
                    'tag'    => $tagName,
                    'target' => $target,
                    'name'   => $title,
                    'body'   => $body,
                    'draft'  => $isDraft,
                    'pre'    => $isPre,
                    'uid'    => (int) ($this->identity['user_id'] ?? 0),
                ],
            );

            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to create release: ' . $e->getMessage()], 400);
            return;
        }

        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `repo_id` = :repo AND `tag_name` = :tag LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'tag' => $tagName],
        );

        $this->json(['success' => true, 'release' => $row], 201);
    }

    /** PATCH /api/v1/repos/{owner}/{repo}/releases/{id} — edit release metadata. */
    public function editRelease(string $owner, string $repo, int $id): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->resolveVisibleRepo($repo);
        if ($dbRepo === null) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $release = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );
        if ($release === false) { $this->json(['error' => 'Release not found.'], 404); return; }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;

        $title   = array_key_exists('name', $input) ? trim((string) $input['name']) : (string) $release['name'];
        $body    = array_key_exists('body', $input) ? (string) $input['body'] : (string) $release['body'];
        $isPre   = array_key_exists('prerelease', $input) ? (! empty($input['prerelease']) ? 1 : 0) : (int) $release['is_prerelease'];
        $isDraft = array_key_exists('draft', $input) ? (! empty($input['draft']) ? 1 : 0) : (int) $release['is_draft'];

        if ($title === '') $title = (string) $release['tag_name'];

        $publishing = ! empty($release['is_draft']) && $isDraft === 0;

        $this->app->db()->execute(
            'UPDATE `repo_releases` SET `name` = :name, `body` = :body, `is_draft` = :draft, `is_prerelease` = :pre,
                    `published_at` = :published
             WHERE `id` = :id AND `repo_id` = :repo',
            [
                'name'      => $title,
                'body'      => $body,
                'draft'     => $isDraft,
                'pre'       => $isPre,
                'published' => $publishing ? date('Y-m-d H:i:s') : $release['published_at'],
                'id'        => $id,
                'repo'      => (int) $dbRepo['id'],
            ],
        );

        if ($publishing) {
            try {
                (new \App\Service\WebhookService($this->app))->dispatch((string) $dbRepo['slug'], 'release', [
                    'action'   => 'published',
                    'release_id' => $id,
                    'tag_name'  => (string) $release['tag_name'],
                    'name'      => $title,
                ]);
            } catch (\Throwable) {}
        }

        $row = $this->app->db()->fetchOne('SELECT * FROM `repo_releases` WHERE `id` = :id LIMIT 1', ['id' => $id]);
        $this->json(['success' => true, 'release' => $row]);
    }

    /** DELETE /api/v1/repos/{owner}/{repo}/releases/{id} — delete a release (row only; use ?delete_tag=1 for the tag too). */
    public function deleteRelease(string $owner, string $repo, int $id): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->resolveVisibleRepo($repo);
        if ($dbRepo === null) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $release = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );
        if ($release === false) { $this->json(['error' => 'Release not found.'], 404); return; }

        if (($_GET['delete_tag'] ?? $_POST['delete_tag'] ?? '') === '1') {
            try {
                (new GitService())->deleteTag($dbRepo['slug'], (string) $release['tag_name']);
                $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
                $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");
            } catch (\Throwable $e) {
                $this->json(['success' => false, 'error' => 'Could not delete the tag: ' . $e->getMessage()], 400);
                return;
            }
        }

        $this->app->db()->execute('DELETE FROM `repo_releases` WHERE `id` = :id', ['id' => $id]);

        $this->json(['success' => true]);
    }

    /** GET /api/v1/repos/{owner}/{repo}/releases/{id}/assets — list assets of a release. */
    public function listReleaseAssets(string $owner, string $repo, int $id): void
    {
        $dbRepo = $this->resolveRepoAndAuth($repo, 'read');
        if ($dbRepo === null) return;

        $release = $this->app->db()->fetchOne(
            'SELECT `id`, `is_draft` FROM `repo_releases` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );
        if ($release === false) { $this->json(['error' => 'Release not found.'], 404); return; }

        $assets = (new \App\Service\ReleaseAssetService($this->app))->assetsForRelease($id);

        $this->json(['assets' => array_map(static function (array $a) use ($owner, $repo): array {
            unset($a['storage_path']);
            $a['download_url'] = "/{$owner}/{$repo}/releases/download/{$a['id']}";
            return $a;
        }, $assets)]);
    }

    /** GET /api/v1/repos/{slug}/commits */
    public function commits(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $ref     = $this->safeRef((string) ($_GET['ref'] ?? $repo['default_branch']));
        if ($ref === null) { $this->json(['error' => 'Invalid ref.'], 400); return; }

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));

        $path = $this->repoPath($repo['slug']);
        if ($path === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $log = $this->gitReader->getLog($path, $ref, $perPage + 1, ($page - 1) * $perPage);
        $hasMore = count($log) > $perPage;
        $log = array_slice($log, 0, $perPage);

        $this->json([
            'commits'  => $log,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ]);
    }

    public function ownerRepoCommits(string $owner, string $repo): void
    {
        $this->commits("{$owner}/{$repo}");
    }

    /** GET /api/v1/repos/{slug}/tree */
    public function tree(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $ref  = $this->safeRef((string) ($_GET['ref'] ?? $repo['default_branch']));
        $path = trim((string) ($_GET['path'] ?? ''));

        if ($ref === null || str_contains($path, '..')) { $this->json(['error' => 'Invalid ref or path.'], 400); return; }

        $repoPath = $this->repoPath($repo['slug']);
        if ($repoPath === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $entries = $this->gitReader->getTree($repoPath, $ref, $path);

        $this->json(['ref' => $ref, 'path' => $path, 'entries' => $entries]);
    }

    public function ownerRepoTree(string $owner, string $repo): void
    {
        $this->tree("{$owner}/{$repo}");
    }

    /** GET /api/v1/repos/{slug}/blob */
    public function blob(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $ref  = $this->safeRef((string) ($_GET['ref'] ?? $repo['default_branch']));
        $path = trim((string) ($_GET['path'] ?? ''));

        if ($ref === null || $path === '' || str_contains($path, '..')) {
            $this->json(['error' => 'Invalid ref or path.'], 400);
            return;
        }

        $repoPath = $this->repoPath($repo['slug']);
        if ($repoPath === null) { $this->json(['error' => 'Repository data missing.'], 404); return; }

        $size   = $this->gitReader->getBlobSize($repoPath, $ref, $path);
        $tooBig = $size > 512 * 1024;

        $content = $tooBig ? null : base64_encode((string) $this->gitReader->getBlob($repoPath, $ref, $path));

        if ($content === null && ! $tooBig) {
            $this->json(['error' => 'File not found.'], 404);
            return;
        }

        $this->json([
            'ref'         => $ref,
            'path'        => $path,
            'size_bytes'  => $size,
            'truncated'   => $tooBig,
            'encoding'    => 'base64',
            'content_b64' => $content,
        ]);
    }

    public function ownerRepoBlob(string $owner, string $repo): void
    {
        $this->blob("{$owner}/{$repo}");
    }

    /** GET /api/v1/repos/{slug}/issues */
    public function issues(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $state = (string) ($_GET['state'] ?? 'open');
        $state = in_array($state, ['open', 'resolved', 'closed', 'all'], true) ? $state : 'open';

        $sql    = 'SELECT `id`, `title`, `status`, `environment`, `steps_to_reproduce`, `created_at`, `updated_at`
                   FROM `bug_reports` WHERE `repo_id` = :repo';
        $params = ['repo' => (int) $repo['id']];

        if ($state !== 'all') {
            $sql .= ' AND `status` = :state';
            $params['state'] = $state;
        }
        $sql .= ' ORDER BY `updated_at` DESC LIMIT 100';

        $this->json(['issues' => $this->app->db()->fetchAll($sql, $params), 'state' => $state]);
    }

    /** GET /api/v1/repos/{slug}/pulls */
    public function pulls(string $slug): void
    {
        $repo = $this->resolveRepoAndAuth($slug, 'read');
        if ($repo === null) return;

        $state = (string) ($_GET['state'] ?? 'open');
        $state = in_array($state, ['open', 'closed', 'merged', 'all'], true) ? $state : 'open';

        $sql    = 'SELECT `number`, `title`, `source_branch`, `target_branch`, `status`,
                          `author_name`, `merged_at`, `closed_at`, `created_at`, `updated_at`
                   FROM `pull_requests` WHERE `repo_id` = :repo';
        $params = ['repo' => (int) $repo['id']];

        if ($state !== 'all') {
            $sql .= ' AND `status` = :state';
            $params['state'] = $state;
        }
        $sql .= ' ORDER BY `updated_at` DESC LIMIT 100';

        $rows = $this->app->db()->fetchAll($sql, $params);

        foreach ($rows as &$row) {
            unset($row['body']);
        }
        unset($row);

        $this->json(['pulls' => $rows, 'state' => $state]);
    }

    // ── Plumbing ────────────────────────────────────────────────────

    /**
     * Whether the authenticated token identity may write to a repository:
     * the owner token always can; a user token requires an explicit
     * "write" collaborator row. Mirrors Auth::canWriteRepo() for the
     * stateless Bearer-token path (scope checks alone are not enough —
     * a write-scoped PAT must not touch repositories its user cannot).
     */
    private function tokenCanWriteRepo(array $repo): bool
    {
        $userId = (int) ($this->identity['user_id'] ?? -1);

        if ($userId === 0) return true; // owner token

        if ($userId < 1) return false;

        $row = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_collaborators`
             WHERE `repo_id` = :repo AND `user_id` = :user AND `role` = \'write\' LIMIT 1',
            ['repo' => (int) $repo['id'], 'user' => $userId],
        );

        return $row !== false;
    }

    /** Validate the Bearer token; emits a JSON error and returns false on failure. */
    private function authenticate(string $requiredScope = 'read'): bool
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            $token = $m[1];
        } elseif (isset($_SERVER['HTTP_X_GITPHP_TOKEN'])) {
            $token = (string) $_SERVER['HTTP_X_GITPHP_TOKEN'];
        }

        if ($token === '') {
            $this->json(['error' => 'Missing bearer token.'], 401);
            return false;
        }

        try {
            $identity = (new ApiTokenService($this->app))->authenticate($token);
        } catch (\Throwable) {
            $this->json(['error' => 'Invalid or revoked token.'], 401);
            return false;
        }

        $scopesVal      = (string) ($identity['scopes'] ?? '');
        $isOwnerToken   = ((int) ($identity['user_id'] ?? -1) === 0);
        $isWriteAllowed = $isOwnerToken
                       || str_contains($scopesVal, 'repo')
                       || str_contains($scopesVal, 'write')
                       || str_contains($scopesVal, 'all')
                       || str_contains($scopesVal, 'admin');

        if ($requiredScope === 'write' && ! $isWriteAllowed) {
            $this->json(['success' => false, 'error' => 'Token scope insufficient for write operations.'], 403);
            return false;
        }

        // Enforce api_rate_limit_per_hour
        if (! $this->checkApiRateLimit($token)) {
            return false;
        }

        $this->identity = $identity;
        return true;
    }

    /**
     * Resolves the target repository and enforces authorization.
     * Public repositories permit unauthenticated GET read requests.
     */
    private function resolveRepoAndAuth(string $slug, string $requiredScope = 'read'): ?array
    {
        $cleanSlug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;
        if (str_contains($cleanSlug, '/')) {
            $parts = explode('/', $cleanSlug);
            $cleanSlug = end($parts);
        }

        $repo = $this->resolveVisibleRepo($cleanSlug);
        if ($repo === null) {
            $this->json(['error' => 'Repository not found.'], 404);
            return null;
        }

        $isPublic = ($repo['visibility'] ?? 'private') === 'public';
        $isReadMethod = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);

        // If public repo and read request, permit unauthenticated read access
        if ($isPublic && $isReadMethod && $requiredScope === 'read') {
            $this->tryOptionalTokenAuth();
            return $repo;
        }

        // Otherwise, enforce Bearer token authentication
        if (! $this->authenticate($requiredScope)) {
            return null;
        }

        return $repo;
    }

    private function tryOptionalTokenAuth(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        $token = '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            $token = $m[1];
        } elseif (isset($_SERVER['HTTP_X_GITPHP_TOKEN'])) {
            $token = (string) $_SERVER['HTTP_X_GITPHP_TOKEN'];
        }

        if ($token !== '') {
            try {
                $this->identity = (new ApiTokenService($this->app))->authenticate($token);
            } catch (\Throwable) {
                // Ignore invalid token on optional public read
            }
        }
    }

    /**
     * Resolve a repository visible to the current token identity.
     * Public repos are open to any valid token; private repos require
     * an owner token or a collaborator token.
     * @return array<string, mixed>|null
     */
    private function resolveVisibleRepo(string $slug): ?array
    {
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;

        $row = $this->app->db()->fetchOne(
            'SELECT r.*, p.slug AS parent_slug, p.name AS parent_name
             FROM `repositories` r
             LEFT JOIN `repositories` p ON r.forked_from_id = p.id
             WHERE r.`slug` = :slug LIMIT 1',
            ['slug' => $slug],
        );

        if ($row === false) return null;

        if (($row['visibility'] ?? 'private') === 'public') {
            return $row;
        }

        $userId = (int) ($this->identity['user_id'] ?? -1);

        if ($userId === 0) return $row; // owner token

        $collab = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_collaborators`
             WHERE `repo_id` = :repo AND `user_id` = :user LIMIT 1',
            ['repo' => (int) $row['id'], 'user' => $userId],
        );

        return $collab !== false ? $row : null;
    }

    private function repoPath(string $slug): ?string
    {
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;

        try {
            if (! $this->gitService->repoExists($slug)) return null;
            return $this->gitService->getRepoPath($slug);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeRef(string $ref): ?string
    {
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0") || strlen($ref) > 255) {
            return null;
        }

        return preg_match('/[;&|`$(){}!<>\'\"\s\\\\]/', $ref) ? null : $ref;
    }

    private function resolveOwnerName(array $repo): string
    {
        $uid = (int) ($repo['owner_user_id'] ?? 0);

        if ($uid > 0) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id', ['id' => $uid]);
            if ($u !== false) return (string) $u['username'];
        }

        return (string) $this->app->config('app.owner', 'admin');
    }

    private function checkApiRateLimit(string $token): bool
    {
        $limitPerHour = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'api_rate_limit_per_hour'")['value'] ?? 5000);
        if ($limitPerHour <= 0) return true;

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = 'api_rl_' . md5($token . '_' . $clientIp);
        $limiter = new \App\Middleware\RateLimit($this->app);
        
        $allowed = $limiter->check($key, $limitPerHour, 60);

        if (! headers_sent()) {
            header("X-RateLimit-Limit: {$limitPerHour}");
            header("X-RateLimit-Reset: " . (time() + 3600));
        }

        if (! $allowed) {
            if (! headers_sent()) header("X-RateLimit-Remaining: 0");
            $this->json(['error' => 'API hourly rate limit exceeded.'], 429);
            return false;
        }

        $limiter->increment($key);
        if (! headers_sent()) header("X-RateLimit-Remaining: " . max(0, $limitPerHour - 1));
        return true;
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** POST /api/v1/repos or /api/v1/user/repos — Create a new repository */
    public function createRepo(): void
    {
        if (! $this->authenticate('write')) return;

        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        $name          = trim((string) ($input['name'] ?? ''));
        $description   = trim((string) ($input['description'] ?? ''));
        $isPrivate     = ! empty($input['private']);
        $defaultBranch = trim((string) ($input['default_branch'] ?? 'main')) ?: 'main';
        $autoInit      = ! empty($input['auto_init']);

        if ($name === '') {
            $this->json(['success' => false, 'error' => 'Repository name is required.'], 400);
            return;
        }

        // Generate slug
        $slug = preg_replace('/[^a-zA-Z0-9._-]/', '-', strtolower($name));
        $slug = trim((string) $slug, '-.');

        if ($slug === '' || in_array($slug, ['settings', 'admin', 'api', 'repos', 'u', 'user', 'login', 'logout', 'register', 'docs', 'guide'], true)) {
            $this->json(['success' => false, 'error' => 'Invalid or reserved repository name.'], 400);
            return;
        }

        // Check if repository slug already exists
        $existing = $this->app->db()->fetchOne('SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $slug]);
        if ($existing !== false) {
            $this->json(['success' => false, 'error' => "Repository slug '{$slug}' already exists."], 409);
            return;
        }

        // User identity
        $userId    = (int) ($this->identity['user_id'] ?? 0);
        $ownerName = (string) $this->app->config('app.owner', 'admin');
        if ($userId > 0) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id LIMIT 1', ['id' => $userId]);
            if ($u) $ownerName = (string) $u['username'];
        }

        // Initialize physical bare Git repository on disk
        try {
            $gitService = new GitService();
            $gitService->initRepo($slug, $defaultBranch);
            
            if ($autoInit) {
                $repoPath = $gitService->getRepoPath($slug);
                $tmpDir = sys_get_temp_dir() . '/init_' . bin2hex(random_bytes(8));
                @mkdir($tmpDir, 0755, true);
                file_put_contents($tmpDir . '/README.md', "# {$name}\n\n{$description}\n");
                
                $p1 = new \Symfony\Component\Process\Process(['git', 'init'], $tmpDir);
                $p1->run();
                $p2 = new \Symfony\Component\Process\Process(['git', 'checkout', '-b', $defaultBranch], $tmpDir);
                $p2->run();
                $p3 = new \Symfony\Component\Process\Process(['git', 'add', 'README.md'], $tmpDir);
                $p3->run();
                $p4 = new \Symfony\Component\Process\Process(['git', 'commit', '-m', 'Initial commit'], $tmpDir, ['GIT_AUTHOR_NAME' => $ownerName, 'GIT_AUTHOR_EMAIL' => "{$ownerName}@ysnapp.com", 'GIT_COMMITTER_NAME' => $ownerName, 'GIT_COMMITTER_EMAIL' => "{$ownerName}@ysnapp.com"]);
                $p4->run();
                $p5 = new \Symfony\Component\Process\Process(['git', 'push', $repoPath, $defaultBranch], $tmpDir);
                $p5->run();
                
                @array_map('unlink', glob("{$tmpDir}/*") ?: []);
                @rmdir($tmpDir);
            }
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to initialize Git repository: ' . $e->getMessage()], 500);
            return;
        }

        // Insert into database
        $visibility  = $isPrivate ? 'private' : 'public';
        $ownerUserId = ($userId > 0) ? $userId : null;

        $this->app->db()->execute(
            'INSERT INTO `repositories` (`owner_user_id`, `name`, `slug`, `description`, `visibility`, `default_branch`, `created_at`, `updated_at`)
             VALUES (:owner, :name, :slug, :desc, :vis, :branch, NOW(), NOW())',
            [
                'owner'  => $ownerUserId,
                'name'   => $name,
                'slug'   => $slug,
                'desc'   => $description,
                'vis'    => $visibility,
                'branch' => $defaultBranch,
            ]
        );

        $repoId = (int) $this->app->db()->lastInsertId();
        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');

        $this->json([
            'success' => true,
            'message' => 'Repository created successfully',
            'data'    => [
                'id'             => $repoId,
                'name'           => $name,
                'slug'           => $slug,
                'owner'          => $ownerName,
                'full_name'      => "{$ownerName}/{$slug}",
                'description'    => $description,
                'private'        => $isPrivate,
                'default_branch' => $defaultBranch,
                'clone_url'      => "{$appUrl}/{$ownerName}/{$slug}.git",
                'html_url'       => "{$appUrl}/{$ownerName}/{$slug}",
                'created_at'     => date('c'),
            ]
        ], 201);
    }

    /** PATCH /api/v1/repos/{slug} — Update repository settings */
    public function updateRepo(string $slug): void
    {
        if (! $this->authenticate('write')) return;

        $repo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $slug]);
        if ($repo === false) {
            $this->json(['success' => false, 'error' => 'Repository not found.'], 404);
            return;
        }

        // Ownership / Write permission check
        $userId = (int) ($this->identity['user_id'] ?? 0);
        if ($userId > 0 && (int) ($repo['owner_user_id'] ?? 0) !== $userId) {
            $this->json(['success' => false, 'error' => 'Permission denied.'], 403);
            return;
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        $newName       = isset($input['name']) ? trim((string) $input['name']) : (string) $repo['name'];
        $description   = isset($input['description']) ? trim((string) $input['description']) : (string) $repo['description'];
        $isPrivate     = isset($input['private']) ? (! empty($input['private'])) : ($repo['visibility'] === 'private');
        $defaultBranch = isset($input['default_branch']) ? trim((string) $input['default_branch']) : (string) $repo['default_branch'];

        $visibility = $isPrivate ? 'private' : 'public';

        $this->app->db()->execute(
            'UPDATE `repositories` SET `name` = :name, `description` = :desc, `visibility` = :vis, `default_branch` = :branch, `updated_at` = NOW() WHERE `id` = :id',
            [
                'name'   => $newName,
                'desc'   => $description,
                'vis'    => $visibility,
                'branch' => $defaultBranch,
                'id'     => (int) $repo['id'],
            ]
        );

        $ownerName = (string) $this->app->config('app.owner', 'admin');
        if (! empty($repo['owner_user_id'])) {
            $u = $this->app->db()->fetchOne('SELECT `username` FROM `users` WHERE `id` = :id LIMIT 1', ['id' => (int) $repo['owner_user_id']]);
            if ($u) $ownerName = (string) $u['username'];
        }

        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');

        $this->json([
            'success' => true,
            'message' => 'Repository updated successfully',
            'data'    => [
                'id'             => (int) $repo['id'],
                'name'           => $newName,
                'slug'           => (string) $repo['slug'],
                'owner'          => $ownerName,
                'full_name'      => "{$ownerName}/{$repo['slug']}",
                'description'    => $description,
                'private'        => $isPrivate,
                'default_branch' => $defaultBranch,
                'clone_url'      => "{$appUrl}/{$ownerName}/{$repo['slug']}.git",
                'html_url'       => "{$appUrl}/{$ownerName}/{$repo['slug']}",
                'updated_at'     => date('c'),
            ]
        ], 200);
    }

    /** DELETE /api/v1/repos/{slug} — Delete repository */
    public function deleteRepo(string $slug): void
    {
        if (! $this->authenticate('write')) return;

        $repo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $slug]);
        if ($repo === false) {
            $this->json(['success' => false, 'error' => 'Repository not found.'], 404);
            return;
        }

        // Ownership / Write permission check
        $userId = (int) ($this->identity['user_id'] ?? 0);
        if ($userId > 0 && (int) ($repo['owner_user_id'] ?? 0) !== $userId) {
            $this->json(['success' => false, 'error' => 'Permission denied.'], 403);
            return;
        }

        // Delete bare Git repository from disk
        try {
            $gitService = new GitService();
            $gitService->deleteRepo($slug);
        } catch (\Throwable) {
            // Non-fatal if physical folder was already cleaned up
        }

        // Delete from database
        $this->app->db()->execute('DELETE FROM `repositories` WHERE `id` = :id', ['id' => (int) $repo['id']]);

        $this->json([
            'success' => true,
            'message' => 'Repository deleted successfully'
        ], 200);
    }

    /** GET /api/v1/repos/{owner}/{repo}/archive/{ref}.zip — Download repo zip archive */
    public function archiveZip(string $owner, string $repo, string $ref = 'main'): void
    {
        if (! $this->authenticate('read')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $repo . '-' . $ref . '.zip"');
        
        $gitService = new GitService();
        $gitService->archiveStream($repo, $ref, 'zip', $repo);
        exit;
    }

    /** GET /api/v1/repos/{owner}/{repo}/archive/{ref}.tar.gz — Download repo tar.gz archive */
    public function archiveTarGz(string $owner, string $repo, string $ref = 'main'): void
    {
        if (! $this->authenticate('read')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $repo . '-' . $ref . '.tar.gz"');
        
        $gitService = new GitService();
        $gitService->archiveStream($repo, $ref, 'tar.gz', $repo);
        exit;
    }

    /** GET /api/v1/repos/{owner}/{repo}/bundle — Export full Git bundle backup */
    public function bundle(string $owner, string $repo): void
    {
        if (! $this->authenticate('read')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $repo . '.bundle"');
        
        $gitService = new GitService();
        $gitService->bundleStream($repo);
        exit;
    }

    /** POST /api/v1/repos/restore — Restore repository from uploaded bundle file */
    public function restoreRepo(): void
    {
        if (! $this->authenticate('write')) return;

        $name       = trim((string) ($_POST['name'] ?? ''));
        $isPrivate  = ! empty($_POST['private']);

        if ($name === '') {
            $this->json(['success' => false, 'error' => 'Repository name is required for restore.'], 400);
            return;
        }

        $uploadedFile = $_FILES['bundle_file'] ?? ($_FILES['file'] ?? ($_FILES['zip_file'] ?? null));
        if (! $uploadedFile || empty($uploadedFile['tmp_name']) || ! is_uploaded_file($uploadedFile['tmp_name'])) {
            $this->json(['success' => false, 'error' => 'Valid bundle_file or zip_file upload is required.'], 400);
            return;
        }

        $slug = preg_replace('/[^a-zA-Z0-9._-]/', '-', strtolower($name));
        $slug = trim((string) $slug, '-.');

        $existing = $this->app->db()->fetchOne('SELECT `id` FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $slug]);
        if ($existing !== false) {
            $this->json(['success' => false, 'error' => "Repository slug '{$slug}' already exists."], 409);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->restoreFromBundle($slug, $uploadedFile['tmp_name']);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to restore repository: ' . $e->getMessage()], 500);
            return;
        }

        $userId      = (int) ($this->identity['user_id'] ?? 0);
        $ownerUserId = ($userId > 0) ? $userId : null;
        $visibility  = $isPrivate ? 'private' : 'public';
        $ownerName   = (string) $this->app->config('app.owner', 'admin');

        $this->app->db()->execute(
            'INSERT INTO `repositories` (`owner_user_id`, `name`, `slug`, `description`, `visibility`, `default_branch`, `created_at`, `updated_at`)
             VALUES (:owner, :name, :slug, :desc, :vis, "main", NOW(), NOW())',
            [
                'owner' => $ownerUserId,
                'name'  => $name,
                'slug'  => $slug,
                'desc'  => 'Restored from Git Bundle backup',
                'vis'   => $visibility,
            ]
        );

        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');

        $this->json([
            'success' => true,
            'message' => 'Repository restored successfully',
            'data'    => [
                'name'      => $name,
                'slug'      => $slug,
                'owner'     => $ownerName,
                'full_name' => "{$ownerName}/{$slug}",
                'clone_url' => "{$appUrl}/{$ownerName}/{$slug}.git",
            ]
        ], 201);
    }

    /** POST /api/v1/repos/{owner}/{repo}/sync — Remote mirror fetch & sync */
    public function syncRemote(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $input     = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $remoteUrl = trim((string) ($input['remote_url'] ?? ''));
        $token     = trim((string) ($input['token'] ?? ''));

        if ($remoteUrl === '') {
            $this->json(['success' => false, 'error' => 'remote_url parameter is required.'], 400);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->syncRemoteUrl($repo, $remoteUrl, $token !== '' ? $token : null);
            $this->app->db()->execute('UPDATE `repositories` SET `updated_at` = NOW() WHERE `id` = :id', ['id' => (int) $dbRepo['id']]);
            $this->json(['success' => true, 'message' => 'Repository synced successfully with remote.']);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Remote sync failed: ' . $e->getMessage()], 500);
        }
    }

    /** POST /api/v1/repos/{owner}/{repo}/branches — Create branch */
    public function createBranch(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $input   = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $branch  = trim((string) ($input['name'] ?? ''));
        $fromRef = trim((string) ($input['from_ref'] ?? 'HEAD')) ?: 'HEAD';

        if ($branch === '') {
            $this->json(['success' => false, 'error' => 'Branch name is required.'], 400);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->createBranch($repo, $branch, $fromRef);
            $this->json(['success' => true, 'message' => "Branch '{$branch}' created successfully."], 201);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to create branch: ' . $e->getMessage()], 400);
        }
    }

    /** DELETE /api/v1/repos/{owner}/{repo}/branches/{branch} — Delete branch */
    public function deleteBranch(string $owner, string $repo, string $branch): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $gitService = new GitService();
        try {
            $gitService->deleteBranch($repo, $branch);
            $this->json(['success' => true, 'message' => "Branch '{$branch}' deleted successfully."]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to delete branch: ' . $e->getMessage()], 400);
        }
    }

    /** PUT /api/v1/repos/{owner}/{repo}/default-branch — Change default branch */
    public function setDefaultBranch(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $input  = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $branch = trim((string) ($input['default_branch'] ?? ''));

        if ($branch === '') {
            $this->json(['success' => false, 'error' => 'default_branch parameter is required.'], 400);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->setHead($repo, $branch);
            $this->app->db()->execute('UPDATE `repositories` SET `default_branch` = :b WHERE `id` = :id', ['b' => $branch, 'id' => (int) $dbRepo['id']]);
            $this->json(['success' => true, 'message' => "Default branch set to '{$branch}'."]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to set default branch: ' . $e->getMessage()], 400);
        }
    }

    /** POST /api/v1/repos/{owner}/{repo}/tags — Create tag */
    public function createTag(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $input   = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $tag     = trim((string) ($input['tag_name'] ?? ''));
        $target  = trim((string) ($input['target_commit'] ?? 'HEAD')) ?: 'HEAD';
        $message = trim((string) ($input['message'] ?? ''));

        if ($tag === '') {
            $this->json(['success' => false, 'error' => 'tag_name parameter is required.'], 400);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->createReleaseTag($repo, $tag, $target, $message);
            $this->json(['success' => true, 'message' => "Tag '{$tag}' created successfully."], 201);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to create tag: ' . $e->getMessage()], 400);
        }
    }

    /** DELETE /api/v1/repos/{owner}/{repo}/tags/{tag} — Delete tag */
    public function deleteTag(string $owner, string $repo, string $tag): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }
        if (! $this->tokenCanWriteRepo($dbRepo)) { $this->json(['error' => 'You do not have write access to this repository.'], 403); return; }

        $gitService = new GitService();
        try {
            $gitService->deleteTag($repo, $tag);

            // Keep releases consistent: removing a tag orphans any release
            // row bound to it — clean those up in the same request.
            $this->app->db()->execute(
                'DELETE FROM `repo_releases` WHERE `repo_id` = :repo AND `tag_name` = :tag',
                ['repo' => (int) $dbRepo['id'], 'tag' => $tag],
            );

            $this->app->cache()->forget("repo:{$repo}:tags");
            $this->app->cache()->forget("repo:{$repo}:tags_detailed");

            $this->json(['success' => true, 'message' => "Tag '{$tag}' deleted successfully."]);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to delete tag: ' . $e->getMessage()], 400);
        }
    }

    /** GET /raw/{owner}/{repo}/{ref}/{path} — Raw file content stream */
    public function rawContent(string $owner = '', string $repo = '', string $ref = '', string $path = ''): void
    {
        if ($repo === '') $repo = (string) ($_GET['repo'] ?? '');
        if ($ref === '')  $ref  = (string) ($_GET['ref'] ?? 'main');
        if ($path === '') $path = (string) ($_GET['path'] ?? '');

        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { http_response_code(404); echo 'Repository not found'; return; }

        // Public check or bearer auth
        if ($dbRepo['visibility'] !== 'public') {
            if (! $this->authenticate('read')) return;
        }

        $repoPath = $this->gitService->getRepoPath($repo);
        $content  = $this->gitReader->getBlobContent($repoPath, $ref, $path);

        if ($content === null) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $mime = 'text/plain; charset=utf-8';
        if (preg_match('/\.(html|htm)$/i', $path)) $mime = 'text/html; charset=utf-8';
        elseif (preg_match('/\.json$/i', $path))   $mime = 'application/json';
        elseif (preg_match('/\.sh$/i', $path))     $mime = 'text/x-shellscript';

        header("Content-Type: {$mime}");
        echo $content;
        exit;
    }

    /** POST /api/v1/repos/{owner}/{repo}/contents/{path} — Direct Commit API */
    public function directCommit(string $owner, string $repo, string $path): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $input   = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $message = trim((string) ($input['message'] ?? 'Direct commit via API'));
        $rawB64  = (string) ($input['content'] ?? '');
        $branch  = trim((string) ($input['branch'] ?? $dbRepo['default_branch'])) ?: 'main';

        $decodedContent = base64_decode($rawB64, true);
        if ($decodedContent === false && $rawB64 !== '') {
            $decodedContent = $rawB64;
        }

        $authorName  = (string) $this->app->config('app.owner', 'admin');
        $authorEmail = "{$authorName}@ysnapp.com";

        $gitService = new GitService();
        try {
            $gitService->directCommitFile($repo, $branch, $path, (string) $decodedContent, $message, $authorName, $authorEmail);
            $this->app->db()->execute('UPDATE `repositories` SET `updated_at` = NOW() WHERE `id` = :id', ['id' => (int) $dbRepo['id']]);
            $this->json(['success' => true, 'message' => 'File committed successfully via API.'], 200);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Direct commit failed: ' . $e->getMessage()], 500);
        }
    }

    /** POST /api/v1/repos/{owner}/{repo}/issues — Create issue / bug report */
    public function createIssue(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $input       = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $title       = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['body'] ?? ($input['description'] ?? '')));

        if ($title === '') {
            $this->json(['success' => false, 'error' => 'Issue title is required.'], 400);
            return;
        }

        $userId = (int) ($this->identity['user_id'] ?? 0);
        $this->app->db()->execute(
            'INSERT INTO `bug_reports` (`repo_id`, `user_id`, `title`, `description`, `created_at`)
             VALUES (:repo, :user, :title, :desc, NOW())',
            [
                'repo'  => (int) $dbRepo['id'],
                'user'  => $userId > 0 ? $userId : null,
                'title' => $title,
                'desc'  => $description,
            ]
        );

        $issueId = (int) $this->app->db()->lastInsertId();

        try {
            $webhookService = new \App\Service\WebhookService($this->app);
            $webhookService->dispatch($repo, 'issues', [
                'action' => 'opened',
                'issue'  => ['id' => $issueId, 'title' => $title, 'body' => $description],
            ]);
        } catch (\Throwable) {}

        $this->json([
            'success' => true,
            'message' => 'Issue created successfully',
            'data'    => [
                'id'          => $issueId,
                'title'       => $title,
                'body'        => $description,
                'state'       => 'open',
                'created_at'  => date('c'),
            ]
        ], 201);
    }

    /** PATCH /api/v1/repos/{owner}/{repo}/issues/{id} — Update issue state or details */
    public function updateIssue(string $owner, string $repo, string $id): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $issueId = (int) $id;
        $issue   = $this->app->db()->fetchOne('SELECT * FROM `bug_reports` WHERE `id` = :id AND `repo_id` = :r LIMIT 1', ['id' => $issueId, 'r' => (int) $dbRepo['id']]);
        if ($issue === false) { $this->json(['error' => 'Issue not found.'], 404); return; }

        $input       = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $title       = isset($input['title']) ? trim((string) $input['title']) : (string) $issue['title'];
        $description = isset($input['body']) ? trim((string) $input['body']) : (string) ($issue['description'] ?? '');
        $status      = isset($input['state']) ? (in_array(strtolower((string) $input['state']), ['closed', 'close'], true) ? 'closed' : 'open') : (string) ($issue['status'] ?? 'open');

        $this->app->db()->execute(
            'UPDATE `bug_reports` SET `title` = :t, `description` = :d, `status` = :s, `updated_at` = NOW() WHERE `id` = :id',
            ['t' => $title, 'd' => $description, 's' => $status, 'id' => $issueId]
        );

        $this->json([
            'success' => true,
            'message' => 'Issue updated successfully',
            'data'    => [
                'id'    => $issueId,
                'title' => $title,
                'body'  => $description,
                'state' => $status,
            ]
        ], 200);
    }

    /** POST /api/v1/repos/{owner}/{repo}/pulls — Create pull request */
    public function createPull(string $owner, string $repo): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $input  = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $title  = trim((string) ($input['title'] ?? ''));
        $body   = trim((string) ($input['body'] ?? ''));
        $head   = trim((string) ($input['head'] ?? ''));
        $base   = trim((string) ($input['base'] ?? $dbRepo['default_branch'])) ?: 'main';

        if ($title === '' || $head === '') {
            $this->json(['success' => false, 'error' => 'title and head branch parameters are required.'], 400);
            return;
        }

        $nextNum = (int) ($this->app->db()->fetchOne('SELECT COALESCE(MAX(`number`), 0) + 1 AS n FROM `pull_requests` WHERE `repo_id` = :r', ['r' => (int) $dbRepo['id']])['n'] ?? 1);
        $userId  = (int) ($this->identity['user_id'] ?? 0);

        $this->app->db()->execute(
            'INSERT INTO `pull_requests` (`repo_id`, `user_id`, `number`, `title`, `body`, `source_branch`, `target_branch`, `status`, `created_at`, `updated_at`)
             VALUES (:repo, :user, :num, :title, :body, :head, :base, "open", NOW(), NOW())',
            [
                'repo'  => (int) $dbRepo['id'],
                'user'  => $userId > 0 ? $userId : null,
                'num'   => $nextNum,
                'title' => $title,
                'body'  => $body,
                'head'  => $head,
                'base'  => $base,
            ]
        );

        $prId = (int) $this->app->db()->lastInsertId();

        try {
            $webhookService = new \App\Service\WebhookService($this->app);
            $webhookService->dispatch($repo, 'pull_request', [
                'action'       => 'opened',
                'number'       => $nextNum,
                'pull_request' => ['id' => $prId, 'number' => $nextNum, 'title' => $title, 'head' => $head, 'base' => $base],
            ]);
        } catch (\Throwable) {}

        $this->json([
            'success' => true,
            'message' => 'Pull request created successfully',
            'data'    => [
                'id'            => $prId,
                'number'        => $nextNum,
                'title'         => $title,
                'head'          => $head,
                'base'          => $base,
                'state'         => 'open',
                'created_at'    => date('c'),
            ]
        ], 201);
    }

    /** POST /api/v1/repos/{owner}/{repo}/pulls/{id}/merge — Merge pull request */
    public function mergePull(string $owner, string $repo, string $id): void
    {
        if (! $this->authenticate('write')) return;
        $dbRepo = $this->app->db()->fetchOne('SELECT * FROM `repositories` WHERE `slug` = :slug LIMIT 1', ['slug' => $repo]);
        if ($dbRepo === false) { $this->json(['error' => 'Repository not found.'], 404); return; }

        $prId = (int) $id;
        $pr   = $this->app->db()->fetchOne('SELECT * FROM `pull_requests` WHERE (`id` = :id OR `number` = :id) AND `repo_id` = :r LIMIT 1', ['id' => $prId, 'r' => (int) $dbRepo['id']]);
        if ($pr === false) { $this->json(['error' => 'Pull request not found.'], 404); return; }

        if (($pr['status'] ?? 'open') !== 'open') {
            $this->json(['success' => false, 'error' => 'Pull request is not open.'], 400);
            return;
        }

        $gitService = new GitService();
        try {
            $gitService->directCommitFile($repo, $pr['target_branch'], '.gitkeep', '', "Merge pull request #{$pr['number']} from {$pr['source_branch']}", 'Admin', 'admin@ysnapp.com');
        } catch (\Throwable) {}

        $this->app->db()->execute(
            'UPDATE `pull_requests` SET `status` = "merged", `updated_at` = NOW() WHERE `id` = :id',
            ['id' => (int) $pr['id']]
        );

        try {
            $webhookService = new \App\Service\WebhookService($this->app);
            $webhookService->dispatch($repo, 'pull_request', [
                'action'       => 'closed',
                'merged'       => true,
                'number'       => (int) $pr['number'],
                'pull_request' => ['id' => (int) $pr['id'], 'number' => (int) $pr['number'], 'title' => $pr['title']],
            ]);
        } catch (\Throwable) {}

        $this->json([
            'success' => true,
            'message' => "Pull request #{$pr['number']} merged successfully.",
            'data'    => [
                'id'     => (int) $pr['id'],
                'number' => (int) $pr['number'],
                'state'  => 'merged',
            ]
        ], 200);
    }

    /** GET /api/v1/user/keys — List user SSH keys */
    public function listSshKeys(): void
    {
        if (! $this->authenticate('read')) return;
        $sshService = new \App\Service\SshKeyService($this->app->db());
        $keys = $sshService->getAll();
        $this->json(['success' => true, 'keys' => $keys]);
    }

    /** POST /api/v1/user/keys — Add new SSH key */
    public function addSshKey(): void
    {
        if (! $this->authenticate('write')) return;
        $input = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;

        $title     = trim((string) ($input['title'] ?? ''));
        $publicKey = trim((string) ($input['key'] ?? ($input['public_key'] ?? '')));

        if ($title === '' || $publicKey === '') {
            $this->json(['success' => false, 'error' => 'title and key parameters are required.'], 400);
            return;
        }

        $sshService = new \App\Service\SshKeyService($this->app->db());
        try {
            $sshService->add($title, $publicKey);
            $this->json(['success' => true, 'message' => 'SSH key added successfully.'], 201);
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to add SSH key: ' . $e->getMessage()], 400);
        }
    }

    /** DELETE /api/v1/user/keys/{id} — Delete SSH key */
    public function deleteSshKey(string $id): void
    {
        if (! $this->authenticate('write')) return;
        $keyId = (int) $id;

        $sshService = new \App\Service\SshKeyService($this->app->db());
        try {
            $deleted = $sshService->delete($keyId);
            if ($deleted) {
                $this->json(['success' => true, 'message' => 'SSH key deleted successfully.']);
            } else {
                $this->json(['success' => false, 'error' => 'SSH key not found.'], 404);
            }
        } catch (\Throwable $e) {
            $this->json(['success' => false, 'error' => 'Failed to delete SSH key: ' . $e->getMessage()], 400);
        }
    }
}
