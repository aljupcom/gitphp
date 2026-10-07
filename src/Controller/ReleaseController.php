<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\GitReader;
use App\Service\GitService;
use App\Service\MarkdownRenderer;
use App\Service\ReleaseAssetService;

final class ReleaseController
{
    private App $app;
    private Auth $auth;
    private GitService $gitService;
    private GitReader $gitReader;
    private MarkdownRenderer $markdown;
    private AuditLogger $auditLogger;
    private ReleaseAssetService $assets;

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->auth        = new Auth($app);
        $this->gitService  = new GitService();
        $this->gitReader   = new GitReader();
        $this->markdown    = new MarkdownRenderer();
        $this->auditLogger = new AuditLogger($app);
        $this->assets      = new ReleaseAssetService($app);
    }

    /** GET /{user}/{repo}/releases — list all releases */
    public function index(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);
        $tags     = method_exists($this->gitReader, 'getTagsDetailed')
            ? $this->gitReader->getTagsDetailed($repoPath)
            : $this->gitReader->getTags($repoPath);

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            SELECT * FROM repo_releases
            WHERE repo_id = ?
            ORDER BY created_at DESC
        ');
        $stmt->execute([$dbRepo['id']]);
        $rawReleases = $stmt->fetchAll();

        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        // Drafts are only visible to people who can publish them.
        if (! $canWrite) {
            $rawReleases = array_values(array_filter(
                $rawReleases,
                static fn(array $r): bool => empty($r['is_draft']),
            ));
        }

        // If no official DB releases exist, display Git tags as releases
        if (empty($rawReleases) && !empty($tags)) {
            foreach ($tags as $t) {
                $tName = is_array($t) ? ($t['name'] ?? '') : (string) $t;
                $tHash = is_array($t) ? ($t['short_hash'] ?? substr($t['hash'] ?? '', 0, 7)) : '';
                $tDate = is_array($t) ? ($t['date'] ?? '') : '';
                $tSubject = is_array($t) ? ($t['subject'] ?? '') : '';
                $rawReleases[] = [
                    'id'               => null,
                    'repo_id'          => (int) $dbRepo['id'],
                    'tag_name'         => $tName,
                    'target_commitish' => $tHash ?: $tName,
                    'name'             => $tName,
                    'body'             => $tSubject,
                    'is_draft'         => 0,
                    'is_prerelease'    => 0,
                    'is_tag_only'      => 1,
                    'created_at'       => $tDate ?: date('Y-m-d H:i:s'),
                    'published_at'     => $tDate ?: date('Y-m-d H:i:s'),
                ];
            }
        }

        // Determine latest release
        $latestAssigned = false;
        $stableSeen = false;
        foreach ($rawReleases as $r) {
            if (empty($r['is_draft']) && empty($r['is_prerelease'])) {
                $stableSeen = true;
                break;
            }
        }
        if ($stableSeen) {
            $latestAssigned = true;
        }

        $releases = [];
        $flaggedStable = false;
        foreach ($rawReleases as $r) {
            $bodyHtml = '';
            if (!empty($r['body'])) {
                $bodyHtml = $this->markdown->renderHtml($r['body']);
            }

            $isStable = empty($r['is_draft']) && empty($r['is_prerelease']);
            $isLatest = false;
            if ($stableSeen) {
                if ($isStable && ! $flaggedStable) {
                    $isLatest = true;
                    $flaggedStable = true;
                }
            } elseif (! $latestAssigned && empty($r['is_draft'])) {
                $isLatest = true;
                $latestAssigned = true;
            }

            $releaseId = isset($r['id']) && $r['id'] !== null ? (int) $r['id'] : null;
            $assets = $releaseId !== null ? $this->assets->assetsForRelease($releaseId) : [];

            $releases[] = array_merge($r, [
                'body_html'  => $bodyHtml,
                'is_latest'  => $isLatest,
                'assets'     => $assets,
            ]);
        }

        $owner = !empty($user) ? $user : (!empty($dbRepo['owner_username']) ? $dbRepo['owner_username'] : (string) $this->app->config('app.owner', 'admin'));

        $this->app->view()->display('repo/releases.twig', [
            'owner'       => $owner,
            'repo'        => $dbRepo,
            'releases'    => $releases,
            'branches'    => $branches,
            'tags'        => $tags,
            'current_ref' => $dbRepo['default_branch'] ?? 'main',
            'can_write'   => $canWrite,
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }



    /** GET /{user}/{repo}/releases/new — release creation form */
    public function newForm(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);
        $tags     = method_exists($this->gitReader, 'getTagsDetailed')
            ? $this->gitReader->getTagsDetailed($repoPath)
            : $this->gitReader->getTags($repoPath);

        $owner = !empty($user) ? $user : (!empty($dbRepo['owner_username']) ? $dbRepo['owner_username'] : (string) $this->app->config('app.owner', 'admin'));
        $unifiedDownloads = $this->getUnifiedDownloadsList();
        $maxBytes = $this->assets->maxAssetBytes();
        $maxUploadMb = (int) ($maxBytes / 1048576);

        $this->app->view()->display('repo/release-new.twig', [
            'owner'             => $owner,
            'repo'              => $dbRepo,
            'branches'          => $branches,
            'tags'              => $tags,
            'target_tag'        => (string) ($_GET['tag'] ?? ''),
            'unified_downloads' => $unifiedDownloads,
            'max_upload_mb'     => $maxUploadMb,
            'csrf_token'        => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/releases/new — handle release creation */
    public function store(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        }

        $tagName      = trim((string) ($_POST['tag_name'] ?? ''));
        $targetBranch = trim((string) ($_POST['target_commitish'] ?? $dbRepo['default_branch'] ?? 'main'));
        $title        = trim((string) ($_POST['name'] ?? ''));
        $body         = (string) ($_POST['body'] ?? '');
        $isPrerelease = !empty($_POST['is_prerelease']) ? 1 : 0;
        $isDraft      = !empty($_POST['is_draft']) ? 1 : 0;

        if ($tagName === '') {
            $_SESSION['flash_error'] = 'Tag name cannot be empty.';
            header("Location: /{$user}/{$repo}/releases/new");
            exit;
        }

        if ($title === '') {
            $title = $tagName;
        }

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $existingTags = $this->gitReader->getTags($repoPath);
        $tagCreatedHere = false;

        try {
            // If the tag doesn't exist yet in Git, create it as an annotated tag
            if (!in_array($tagName, $existingTags, true)) {
                $userRec = $this->auth->user();
                $authorName  = $this->auth->isOwner() ? 'Owner' : ($userRec['username'] ?? 'User');
                $authorEmail = $this->auth->isOwner() ? 'admin@example.com' : ($userRec['email'] ?? 'user@example.com');
                $this->gitService->createReleaseTag($dbRepo['slug'], $tagName, $targetBranch, $title, $authorName, $authorEmail);
                $tagCreatedHere = true;
            }

            $db = $this->app->db()->connection();
            $currUser = $this->auth->user();
            $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
            $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

            // Check if tag already exists in Git and get its true commit/creator date
            $releaseDate = date('Y-m-d H:i:s');
            if (in_array($tagName, $existingTags, true)) {
                $rawTagDate = trim((string) @shell_exec("git -C " . escapeshellarg($repoPath) . " log -1 --format=%aI " . escapeshellarg($tagName)));
                if (!empty($rawTagDate)) {
                    $ts = strtotime($rawTagDate);
                    if ($ts !== false) {
                        $releaseDate = date('Y-m-d H:i:s', $ts);
                    }
                }
            }

            $stmt = $db->prepare('
                INSERT INTO repo_releases (repo_id, tag_name, target_commitish, name, body, is_draft, is_prerelease, created_by, created_at, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), body = VALUES(body), is_draft = VALUES(is_draft), is_prerelease = VALUES(is_prerelease), published_at = VALUES(published_at)
            ');
            $stmt->execute([
                $dbRepo['id'],
                $tagName,
                $targetBranch,
                $title,
                $body,
                $isDraft,
                $isPrerelease,
                $userId,
                $releaseDate,
                $releaseDate,
            ]);

            // Tag caches must reflect the new ref immediately.
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");

            $this->auditLogger->log('release.create', (int) $dbRepo['id'], "Created release {$tagName} ({$title})", $userId, $userName);

            $releaseId = (int) $db->lastInsertId();
            if ($releaseId === 0) {
                $rStmt = $db->prepare('SELECT id FROM repo_releases WHERE repo_id = ? AND tag_name = ? LIMIT 1');
                $rStmt->execute([$dbRepo['id'], $tagName]);
                $releaseId = (int) $rStmt->fetchColumn();
            }

            // 1. Process directly uploaded binary files
            if (isset($_FILES['release_files'])) {
                $rFiles = $_FILES['release_files'];
                $fileList = [];
                if (is_array($rFiles['name'])) {
                    for ($i = 0; $i < count($rFiles['name']); $i++) {
                        if (!empty($rFiles['name'][$i]) && ($rFiles['error'][$i] ?? 0) === UPLOAD_ERR_OK) {
                            $fileList[] = [
                                'name'     => $rFiles['name'][$i],
                                'type'     => $rFiles['type'][$i] ?? 'application/octet-stream',
                                'tmp_name' => $rFiles['tmp_name'][$i],
                                'error'    => $rFiles['error'][$i],
                                'size'     => $rFiles['size'][$i],
                            ];
                        }
                    }
                } elseif (!empty($rFiles['name']) && ($rFiles['error'] ?? 0) === UPLOAD_ERR_OK) {
                    $fileList[] = $rFiles;
                }

                foreach ($fileList as $f) {
                    try {
                        $this->assets->directUpload((int)$dbRepo['id'], $releaseId, $userId, $user, (string)$dbRepo['slug'], $f, $tagName);
                    } catch (\Throwable $e) {
                        error_log('[ReleaseUpload] ' . $e->getMessage());
                    }
                }
            }

            // 3. Process attached repository binary files (scanned from git tree)
            $repoBinaryFiles = (array) ($_POST['repo_binary_files'] ?? []);
            foreach ($repoBinaryFiles as $gitFilePath) {
                $gitFilePath = trim((string) $gitFilePath);
                if ($gitFilePath !== '') {
                    try {
                        $this->assets->attachFromGitRepo(
                            (int) $dbRepo['id'],
                            $releaseId,
                            $userId,
                            $user,
                            (string) $dbRepo['slug'],
                            $repoPath,
                            $gitFilePath,
                            $targetBranch,
                            $tagName
                        );
                    } catch (\Throwable $e) {
                        error_log('[ReleaseAttachRepoBinary] ' . $e->getMessage());
                    }
                }
            }

            // 2. Process attached files selected from the Unified Download Center
            $selectedDownloads = (array) ($_POST['selected_downloads'] ?? []);
            foreach ($selectedDownloads as $dlId) {
                $dlIdInt = (int)$dlId;
                if ($dlIdInt > 0) {
                    try {
                        $this->assets->linkUnifiedDownload($dlIdInt, (int)$dbRepo['id'], $releaseId, $userId, $user, (string)$dbRepo['slug']);
                    } catch (\Throwable $e) {
                        error_log('[ReleaseLinkUnified] ' . $e->getMessage());
                    }
                }
            }

            // Fire the release webhook on first publish
            if (! $isDraft) {
                $this->fireReleaseEvent($dbRepo, $releaseId, 'published');
            }

            $_SESSION['flash_success'] = "Release '{$title}' published successfully.";
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        } catch (\Throwable $e) {
            if ($tagCreatedHere) {
                try {
                    $this->gitService->deleteTag($dbRepo['slug'], $tagName);
                    $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
                    $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");
                } catch (\Throwable) {
                }
            }

            $_SESSION['flash_error'] = 'Failed to publish release: ' . $e->getMessage();
            header("Location: /{$user}/{$repo}/releases/new");
            exit;
        }
    }

    /** POST /{user}/{repo}/releases/{id:\d+}/delete — delete release */
    public function delete(string $user, string $repo, int $id): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        }

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT * FROM repo_releases WHERE id = ? AND repo_id = ?');
        $stmt->execute([$id, $dbRepo['id']]);
        $release = $stmt->fetch();

        if ($release === false) {
            $_SESSION['flash_error'] = 'Release not found.';
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        }

        $deleteTag = ! empty($_POST['delete_tag']);
        if ($deleteTag) {
            try {
                $this->gitService->deleteTag($dbRepo['slug'], (string) $release['tag_name']);
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = 'Release row deleted, but the git tag could not be removed: ' . $e->getMessage();
                header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
                exit;
            }
        }

        $stmt = $db->prepare('DELETE FROM repo_releases WHERE id = ? AND repo_id = ?');
        $stmt->execute([$id, $dbRepo['id']]);

        if ($deleteTag) {
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
            $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");
        }

        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

        $this->auditLogger->log('release.delete', (int) $dbRepo['id'], "Deleted release ID {$id}" . ($deleteTag ? ' (tag removed)' : ''), $userId, $userName);

        $this->fireReleaseEvent($dbRepo, $id, 'deleted');

        $_SESSION['flash_success'] = 'Release deleted successfully.';
        header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
        exit;
    }

    // ── Release Assets (chunked upload / streaming download) ────────

    /** JSON response helper for the asset endpoints. */
    private function assetJson(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** POST /{user}/{repo}/releases/assets/init — start a chunked upload session. */
    public function assetUploadInit(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;

        $releaseId = (int) ($input['release_id'] ?? 0);
        $filename  = (string) ($input['filename'] ?? '');
        $size      = (int) ($input['size'] ?? 0);

        if ($releaseId < 1) {
            $this->assetJson(['success' => false, 'error' => 'release_id is required.'], 400);
        }

        $rel = $this->app->db()->fetchOne(
            'SELECT `id` FROM `repo_releases` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $releaseId, 'repo' => (int) $dbRepo['id']],
        );
        if ($rel === false) {
            $this->assetJson(['success' => false, 'error' => 'Release not found.'], 404);
        }

        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        try {
            $session = $this->assets->init((int) $dbRepo['id'], $releaseId, $uploaderId, $filename, $size);
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->assetJson(['success' => true] + $session);
    }

    /** POST /{user}/{repo}/releases/assets/chunk — receive one numbered part. */
    public function assetUploadChunk(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $sessionId = (string) ($_GET['session'] ?? $_POST['session'] ?? '');
        $index     = (int) ($_GET['index'] ?? $_POST['index'] ?? -1);
        $releaseId = (int) ($_GET['release_id'] ?? $_POST['release_id'] ?? 0);

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $raw = (string) ($_POST['chunk'] ?? '');
        }

        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        try {
            $result = $this->assets->chunk(
                $sessionId,
                (int) $dbRepo['id'],
                $releaseId,
                $uploaderId,
                $index,
                (string) $raw,
            );
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->assetJson(['success' => true] + $result);
    }

    /** POST /{user}/{repo}/releases/assets/complete — merge, hash, persist. */
    public function assetUploadComplete(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;

        $sessionId  = (string) ($input['session'] ?? '');
        $releaseId  = (int) ($input['release_id'] ?? 0);

        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        try {
            $asset = $this->assets->complete(
                $sessionId,
                (int) $dbRepo['id'],
                $releaseId,
                $uploaderId,
                $user,
                (string) $dbRepo['slug'],
            );
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->auditLogger->log(
            'release.asset_create',
            (int) $dbRepo['id'],
            "Uploaded release asset '{$asset['name']}' ({$asset['id']})",
            $uploaderId,
            $this->auth->isOwner() ? 'owner' : (string) ($this->auth->user()['username'] ?? 'user'),
        );

        $this->assetJson(['success' => true] + $asset);
    }

    /** POST /{user}/{repo}/releases/assets/{id}/delete — remove an asset. */
    public function assetDelete(string $user, string $repo, int $id): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        if (! $this->assets->deleteAsset($id, (int) $dbRepo['id'])) {
            $this->assetJson(['success' => false, 'error' => 'Asset not found.'], 404);
        }

        $this->auditLogger->log(
            'release.asset_delete',
            (int) $dbRepo['id'],
            "Deleted release asset ID {$id}",
            $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0),
            $this->auth->isOwner() ? 'owner' : (string) ($this->auth->user()['username'] ?? 'user'),
        );

        $this->assetJson(['success' => true]);
    }

    /**
     * GET /{user}/{repo}/releases/download/{id} — stream an asset with
     * ETag/304, Range (resume) support, and a post-completion counter.
     */
    public function assetDownload(string $user, string $repo, int $id): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $asset = $this->assets->getAsset($id);
        if ($asset === null || (int) $asset['repo_id'] !== (int) $dbRepo['id']) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Asset not found.',
            ]);
            return;
        }

        // Check if caller provides permanent update key via query or header for automated CLI/CI updaters
        $tokenService = new \App\Service\DownloadTokenService($this->app);
        $updateToken = (string) ($_GET['update_token'] ?? $_GET['token'] ?? ($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? ''));
        $isRemoteUpdater = $updateToken !== '' && $tokenService->isPermanentUpdateKey($updateToken);

        // Find or create short code for this release asset
        $db = $this->app->db()->connection();
        $q = $db->prepare('SELECT short_code FROM file_downloads WHERE file_path = ? OR original_name = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$asset['storage_path'], $asset['name']]);
        $shortCode = $q->fetchColumn();

        if (empty($shortCode)) {
            $shortCode = $this->assets->syncToDownloadCenter(
                (int) $asset['id'],
                (int) $dbRepo['id'],
                (int) $asset['release_id'],
                (string) $asset['name'],
                (string) $asset['storage_path'],
                (int) $asset['size_bytes'],
                (string) $asset['mime'],
                (int) ($asset['uploader_id'] ?? 0)
            );
        }

        if (!$isRemoteUpdater && !empty($shortCode)) {
            header("Location: /d/{$shortCode}", true, 302);
            exit;
        }

        $absPath = $this->app->basePath((string) $asset['storage_path']);
        if (! is_file($absPath)) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Asset file is missing on the server.',
            ]);
            return;
        }

        $size  = (int) $asset['size_bytes'];
        $etag  = '"' . $asset['sha256'] . '"';

        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), " 	");
        if ($ifNoneMatch !== '') {
            $candidates = array_map('trim', explode(',', $ifNoneMatch));
            if (in_array($etag, $candidates, true) || in_array('*', $candidates, true)) {
                http_response_code(304);
                exit;
            }
        }

        header('Content-Type: ' . ($asset['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $asset['name']) . '"');
        header('ETag: ' . $etag);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        $start = 0;
        $end   = $size - 1;

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/i', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $suffix = (int) $m[2];
                $start  = max(0, $size - $suffix);
            } elseif ($m[1] !== '' && $m[2] === '') {
                $start = (int) $m[1];
            } elseif ($m[1] !== '' && $m[2] !== '') {
                $start = (int) $m[1];
                $end   = min($size - 1, (int) $m[2]);
            }

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                exit;
            }

            http_response_code(206);
            $length = $end - $start + 1;
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header("Content-Length: {$length}");
        } else {
            header("Content-Length: {$size}");
        }

        $fp = fopen($absPath, 'rb');
        if ($fp === false) {
            http_response_code(500);
            exit;
        }

        if ($start > 0) {
            fseek($fp, $start);
        }

        $bytesToRead = $end - $start + 1;
        while (! feof($fp) && $bytesToRead > 0) {
            $chunkSize = min(65536, $bytesToRead);
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false) break;
            echo $buffer;
            flush();
            $bytesToRead -= strlen($buffer);
            if (connection_status() !== CONNECTION_NORMAL) break;
        }
        fclose($fp);

        $this->assets->recordDownload($id);
        exit;
    }

    /**
     * GET /{user}/{repo}/releases/download/{tag}/{filename} — GitHub/Codeberg standard release asset download
     */
    /**
     * POST /{user}/{repo}/releases/notes/generate — Auto-generate release notes based on git commit history
     */
    public function generateNotes(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $target = trim((string) ($_POST['target_commitish'] ?? $dbRepo['default_branch'] ?? 'main'));
        $tag = trim((string) ($_POST['tag_name'] ?? ''));

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        // Find the previous release tag in DB or git
        $db = $this->app->db()->connection();
        $stmt = $db->prepare("SELECT tag_name FROM repo_releases WHERE repo_id = ? AND is_draft = 0 ORDER BY created_at DESC LIMIT 2");
        $stmt->execute([$dbRepo['id']]);
        $dbTags = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $prevTag = null;
        foreach ($dbTags as $t) {
            if ($t !== $tag) {
                $prevTag = $t;
                break;
            }
        }

        // If not in DB, look up git tags
        if ($prevTag === null) {
            $gitTags = $this->gitReader->getTags($repoPath) ?: [];
            foreach ($gitTags as $t) {
                if ($t !== $tag) {
                    $prevTag = $t;
                    break;
                }
            }
        }

        $revRange = $prevTag ? escapeshellarg("{$prevTag}..{$target}") : escapeshellarg($target);
        $cmd = "git -C " . escapeshellarg($repoPath) . " log {$revRange} --no-merges --pretty=format:'* %s (@%an)' -n 50 2>/dev/null";
        $commitsOutput = trim((string) @shell_exec($cmd));

        $title = $tag ? "Release {$tag}" : "New Release";
        $notes = "## What's Changed\n\n";
        if (!empty($commitsOutput)) {
            $notes .= $commitsOutput . "\n\n";
        } else {
            $notes .= "* Initial release or no new commits found.\n\n";
        }

        if ($prevTag) {
            $notes .= "**Full Changelog**: " . "/{$user}/{$dbRepo['slug']}/compare/{$prevTag}...{$tag}\n";
        }

        $this->assetJson([
            'success' => true,
            'title'   => $title,
            'body'    => $notes,
            'prev_tag' => $prevTag,
        ]);
    }

    public function assetDownloadByTag(string $user, string $repo, string $tag, string $filename): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $cleanFilename = basename($filename);
        $asset = $this->assets->getAssetByTagAndFilename((int) $dbRepo['id'], $tag, $cleanFilename);
        if ($asset === null) {
            http_response_code(404);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Asset not found for the specified release.',
            ]);
            return;
        }

        $this->assetDownload($user, $repo, (int) $asset['id']);
    }

    /** GET /{user}/{repo}/releases/{id}/edit — release edit form */
    public function editForm(string $user, string $repo, int $id): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        $release = $this->loadRelease($dbRepo, $id);
        if ($release === null) {
            $this->notFound();
            return;
        }

        $owner = !empty($user) ? $user : (!empty($dbRepo['owner_username']) ? $dbRepo['owner_username'] : (string) $this->app->config('app.owner', 'admin'));
        $assets = $this->assets->assetsForRelease($id);
        $unifiedDownloads = $this->getUnifiedDownloadsList();
        $maxBytes = $this->assets->maxAssetBytes();
        $maxUploadMb = (int) ($maxBytes / 1048576);

        $this->app->view()->display('repo/release-edit.twig', [
            'owner'             => $owner,
            'repo'              => $dbRepo,
            'release'           => $release,
            'assets'            => $assets,
            'unified_downloads' => $unifiedDownloads,
            'max_upload_mb'     => $maxUploadMb,
            'csrf_token'        => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /{user}/{repo}/releases/{id}/edit — persist release edits */
    public function edit(string $user, string $repo, int $id): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /{$user}/{$repo}/releases/{$id}/edit");
            exit;
        }

        $release = $this->loadRelease($dbRepo, $id);
        if ($release === null) {
            $this->notFound();
            return;
        }

        $title        = trim((string) ($_POST['name'] ?? ''));
        $body         = (string) ($_POST['body'] ?? '');
        $isPrerelease = ! empty($_POST['is_prerelease']) ? 1 : 0;
        $isDraft      = ! empty($_POST['is_draft']) ? 1 : 0;

        if ($title === '') {
            $title = (string) $release['tag_name'];
        }

        $wasDraft = ! empty($release['is_draft']);
        $publishing = $wasDraft && ! $isDraft;

        $this->app->db()->execute(
            'UPDATE `repo_releases`
             SET `name` = :name, `body` = :body, `is_draft` = :draft, `is_prerelease` = :pre,
                 `published_at` = :published
             WHERE `id` = :id AND `repo_id` = :repo',
            [
                'name'      => $title,
                'body'      => $body,
                'draft'     => $isDraft,
                'pre'       => $isPrerelease,
                'published' => $publishing ? date('Y-m-d H:i:s') : $release['published_at'],
                'id'        => $id,
                'repo'      => (int) $dbRepo['id'],
            ],
        );

        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

        // 1. Process directly uploaded binary files
        if (isset($_FILES['release_files'])) {
            $rFiles = $_FILES['release_files'];
            $fileList = [];
            if (is_array($rFiles['name'])) {
                for ($i = 0; $i < count($rFiles['name']); $i++) {
                    if (!empty($rFiles['name'][$i]) && ($rFiles['error'][$i] ?? 0) === UPLOAD_ERR_OK) {
                        $fileList[] = [
                            'name'     => $rFiles['name'][$i],
                            'type'     => $rFiles['type'][$i] ?? 'application/octet-stream',
                            'tmp_name' => $rFiles['tmp_name'][$i],
                            'error'    => $rFiles['error'][$i],
                            'size'     => $rFiles['size'][$i],
                        ];
                    }
                }
            } elseif (!empty($rFiles['name']) && ($rFiles['error'] ?? 0) === UPLOAD_ERR_OK) {
                $fileList[] = $rFiles;
            }

            foreach ($fileList as $f) {
                try {
                    $this->assets->directUpload((int)$dbRepo['id'], $id, $userId, $user, (string)$dbRepo['slug'], $f, (string)$release['tag_name']);
                } catch (\Throwable $e) {
                    error_log('[ReleaseUpload] ' . $e->getMessage());
                }
            }
        }

        // 2. Process attached files selected from the Unified Download Center
        $selectedDownloads = (array) ($_POST['selected_downloads'] ?? []);
        foreach ($selectedDownloads as $dlId) {
            $dlIdInt = (int)$dlId;
            if ($dlIdInt > 0) {
                try {
                    $this->assets->linkUnifiedDownload($dlIdInt, (int)$dbRepo['id'], $id, $userId, $user, (string)$dbRepo['slug']);
                } catch (\Throwable $e) {
                    error_log('[ReleaseLinkUnified] ' . $e->getMessage());
                }
            }
        }

        $this->auditLogger->log('release.edit', (int) $dbRepo['id'], "Updated release ID {$id}", $userId, $userName);

        if ($publishing) {
            $this->fireReleaseEvent($dbRepo, $id, 'published');
        }

        $_SESSION['flash_success'] = 'Release updated.';
        header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
        exit;
    }
public function publish(string $user, string $repo, int $id): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        }

        $release = $this->loadRelease($dbRepo, $id);
        if ($release === null) {
            $this->notFound();
            return;
        }

        if (empty($release['is_draft'])) {
            $_SESSION['flash_error'] = 'This release is already published.';
            header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
            exit;
        }

        $this->app->db()->execute(
            'UPDATE `repo_releases` SET `is_draft` = 0, `published_at` = :published WHERE `id` = :id AND `repo_id` = :repo',
            ['published' => date('Y-m-d H:i:s'), 'id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

        $this->auditLogger->log('release.publish', (int) $dbRepo['id'], "Published draft release {$release['tag_name']} (ID {$id})", $userId, $userName);

        $this->fireReleaseEvent($dbRepo, $id, 'published');

        $_SESSION['flash_success'] = "Release '{$release['name']}' published.";
        header("Location: /" . urlencode($user) . "/" . urlencode($dbRepo['slug']) . "/releases");
        exit;
    }

    /** @return array<string, mixed>|null */
    private function loadRelease(array $dbRepo, int $id): ?array
    {
        $row = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `id` = :id AND `repo_id` = :repo LIMIT 1',
            ['id' => $id, 'repo' => (int) $dbRepo['id']],
        );

        return $row !== false ? $row : null;
    }

    /** Fire the (previously dead) `release` webhook event. */
    private function fireReleaseEvent(array $dbRepo, int $releaseId, string $action): void
    {
        try {
            $release = $this->app->db()->fetchOne(
                'SELECT `tag_name`, `name`, `is_prerelease`, `target_commitish` FROM `repo_releases` WHERE `id` = :id LIMIT 1',
                ['id' => $releaseId],
            );
            if ($release === false) return;

            (new \App\Service\WebhookService($this->app))->dispatch(
                (string) $dbRepo['slug'],
                'release',
                [
                    'action'          => $action,
                    'release_id'      => $releaseId,
                    'tag_name'        => (string) $release['tag_name'],
                    'name'            => (string) ($release['name'] ?? $release['tag_name']),
                    'prerelease'      => (bool) $release['is_prerelease'],
                    'target_commitish' => (string) ($release['target_commitish'] ?? ''),
                    'url'             => '/' . $this->app->config('app.owner', 'admin') . '/' . $dbRepo['slug'] . '/releases/tag/' . rawurlencode((string) $release['tag_name']),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[ReleaseWebhook] ' . $e->getMessage());
        }
    }

    /** GET /{user}/{repo}/releases/tag/{tag} — single-release permalink page */
    public function show(string $user, string $repo, string $tag): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $tag = rawurldecode($tag);

        $release = $this->app->db()->fetchOne(
            'SELECT * FROM `repo_releases` WHERE `repo_id` = :repo AND `tag_name` = :tag LIMIT 1',
            ['repo' => (int) $dbRepo['id'], 'tag' => $tag],
        );

        $canWrite = $this->auth->isOwner() || ($this->auth->isLoggedIn() && $this->auth->canWriteRepo((int) $dbRepo['id']));

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        $branches = $this->gitReader->getBranches($repoPath);
        $tags     = method_exists($this->gitReader, 'getTagsDetailed')
            ? $this->gitReader->getTagsDetailed($repoPath)
            : $this->gitReader->getTags($repoPath);

        if ($release === false || (! $canWrite && ! empty($release['is_draft']))) {
            // Check if tag exists in Git
            $matchedTag = null;
            foreach ($tags as $t) {
                $curName = is_array($t) ? ($t['name'] ?? '') : (string) $t;
                if ($curName === $tag) {
                    $matchedTag = is_array($t) ? $t : ['name' => $t];
                    break;
                }
            }

            if ($matchedTag === null) {
                http_response_code(404);
                echo $this->app->view()->render('partials/error.html.twig', [
                    'code'    => 404,
                    'message' => 'Release or tag not found.',
                ]);
                return;
            }

            $release = [
                'id'               => null,
                'repo_id'          => (int) $dbRepo['id'],
                'tag_name'         => $matchedTag['name'],
                'target_commitish' => $matchedTag['short_hash'] ?? substr($matchedTag['hash'] ?? '', 0, 7),
                'name'             => $matchedTag['name'],
                'body'             => $matchedTag['subject'] ?? '',
                'body_html'        => !empty($matchedTag['subject']) ? htmlspecialchars($matchedTag['subject']) : '',
                'is_draft'         => 0,
                'is_prerelease'    => 0,
                'is_tag_only'      => 1,
                'created_at'       => $matchedTag['date'] ?? date('Y-m-d H:i:s'),
                'published_at'     => $matchedTag['date'] ?? date('Y-m-d H:i:s'),
            ];
            $assets = [];
            $bodyHtml = $release['body_html'];
        } else {
            $bodyHtml = '';
            if (! empty($release['body'])) {
                $bodyHtml = $this->markdown->renderHtml((string) $release['body']);
            }
            $assets = $this->assets->assetsForRelease((int) $release['id']);

            // Check if this release is latest
            $latestStmt = $this->app->db()->fetchOne(
                "SELECT id FROM repo_releases WHERE repo_id = :repo AND is_draft = 0 AND is_prerelease = 0 ORDER BY created_at DESC LIMIT 1",
                ['repo' => (int) $dbRepo['id']]
            );
            if ($latestStmt && (int) $latestStmt['id'] === (int) $release['id']) {
                $release['is_latest'] = true;
            }
        }

        $owner = !empty($user) ? $user : (!empty($dbRepo['owner_username']) ? $dbRepo['owner_username'] : (string) $this->app->config('app.owner', 'admin'));

        $this->app->view()->display('repo/release-show.twig', [
            'owner'       => $owner,
            'repo'        => $dbRepo,
            'release'     => $release,
            'body_html'   => $bodyHtml,
            'assets'      => $assets,
            'branches'    => $branches,
            'tags'        => $tags,
            'can_write'   => $canWrite,
            'csrf_token'  => $this->auth->generateCsrf(),
        ]);
    }

    public function tagDelete(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
            header("Location: /{$user}/{$repo}/tags");
            exit;
        }

        $tag = trim((string) ($_POST['tag'] ?? ''));
        if ($tag === '') {
            $_SESSION['flash_error'] = 'Tag name is required.';
            header("Location: /{$user}/{$repo}/tags");
            exit;
        }

        try {
            $this->gitService->deleteTag($dbRepo['slug'], $tag);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not delete the tag: ' . $e->getMessage();
            header("Location: /{$user}/{$repo}/tags");
            exit;
        }

        // Remove any release row bound to the deleted tag (consistency).
        $this->app->db()->execute(
            'DELETE FROM `repo_releases` WHERE `repo_id` = :repo AND `tag_name` = :tag',
            ['repo' => (int) $dbRepo['id'], 'tag' => $tag],
        );

        $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags");
        $this->app->cache()->forget("repo:{$dbRepo['slug']}:tags_detailed");

        $currUser = $this->auth->user();
        $userId   = $this->auth->isOwner() ? 0 : (int) ($currUser['id'] ?? 0);
        $userName = $this->auth->isOwner() ? 'owner' : (string) ($currUser['username'] ?? 'user');

        $this->auditLogger->log('tag.delete', (int) $dbRepo['id'], "Deleted tag {$tag} from web UI", $userId, $userName);

        $_SESSION['flash_success'] = "Tag '{$tag}' deleted.";
        header("Location: /{$user}/{$repo}/tags");
        exit;
    }

    /** GET /{user}/{repo}/releases.rss (and /releases/rss) — releases RSS feed. */
    public function feed(string $user, string $repo): void
    {
        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        // Private repos: owner or collaborator only (same policy as the
        // commits feed).
        if (($dbRepo['visibility'] ?? 'public') === 'private') {
            $this->auth->requireAuth();
            if (! $this->auth->isOwner() && $this->auth->userId() > 0
                && (int) ($dbRepo['owner_user_id'] ?? 0) !== $this->auth->userId()) {
                $isCollab = $this->app->db()->fetchOne(
                    'SELECT `id` FROM `repo_collaborators` WHERE `repo_id` = :r AND `user_id` = :u LIMIT 1',
                    ['r' => (int) $dbRepo['id'], 'u' => $this->auth->userId()],
                );
                if (! $isCollab) {
                    $this->notFound();
                    return;
                }
            }
        }

        $releases = $this->app->db()->fetchAll(
            'SELECT * FROM `repo_releases`
             WHERE `repo_id` = :repo AND `is_draft` = 0
             ORDER BY `created_at` DESC
             LIMIT 25',
            ['repo' => (int) $dbRepo['id']],
        );

        $appUrl   = rtrim((string) $this->app->config('app.url', ''), '/');
        $repoUrl  = "{$appUrl}/{$user}/{$dbRepo['slug']}";
        $feedDate = ! empty($releases[0]['created_at'])
            ? date(DATE_RSS, strtotime((string) $releases[0]['created_at']))
            : date(DATE_RSS);

        header('Content-Type: application/rss+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>' . htmlspecialchars("{$user}/{$dbRepo['name']} Releases") . '</title>' . "\n";
        echo '    <link>' . htmlspecialchars("{$repoUrl}/releases") . '</link>' . "\n";
        echo '    <description>' . htmlspecialchars("Released versions of {$user}/{$dbRepo['name']}") . '</description>' . "\n";
        echo '    <language>en-us</language>' . "\n";
        echo '    <pubDate>' . $feedDate . '</pubDate>' . "\n";
        echo '    <atom:link href="' . htmlspecialchars("{$repoUrl}/releases.rss") . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($releases as $r) {
            $rUrl  = "{$repoUrl}/releases/tag/" . rawurlencode((string) $r['tag_name']);
            $title = (string) ($r['name'] !== '' && $r['name'] !== null ? $r['name'] : $r['tag_name']);
            $pre   = ! empty($r['is_prerelease']) ? ' [pre-release]' : '';
            $rDate = date(DATE_RSS, strtotime((string) $r['published_at'] ?: (string) $r['created_at']));

            echo '    <item>' . "\n";
            echo '      <title>' . htmlspecialchars($title . $pre) . '</title>' . "\n";
            echo '      <link>' . htmlspecialchars($rUrl) . '</link>' . "\n";
            echo '      <guid isPermaLink="true">' . htmlspecialchars($rUrl) . '</guid>' . "\n";
            echo '      <pubDate>' . $rDate . '</pubDate>' . "\n";
            echo '      <description><![CDATA[' . nl2br(htmlspecialchars((string) $r['body'])) . ']]></description>' . "\n";
            echo '    </item>' . "\n";
        }

        echo '  </channel>' . "\n";
        echo '</rss>' . "\n";
        exit;
    }

    private function requireWriteAccess(string $repo, ?array &$dbRepo): void
    {
        $this->auth->requireAuth();
        $dbRepo = $this->resolveRepo($repo);

        if ($dbRepo === null) {
            $this->notFound();
            return;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            http_response_code(403);
            echo $this->app->view()->render('partials/error.html.twig', [
                'code'    => 403,
                'message' => 'You do not have write permissions for this repository.',
            ]);
            $dbRepo = null;
        }
    }

    /** @return array<string, mixed>|null */
    private function resolveRepo(string $slug): ?array
    {
        // Tolerate clone-URL style paths (/{user}/{repo}.git)
        $slug = str_ends_with($slug, '.git') ? substr($slug, 0, -4) : $slug;
        if ($slug === '') return null;

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT * FROM repositories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $repo = $stmt->fetch();
        if ($repo === false || $repo === null) return null;

        // Private repos are visible to the owner and collaborators only.
        if (! $this->auth->canViewRepo((int) $repo['id'], (string) $repo['visibility'])) {
            if (! $this->auth->isLoggedIn()) {
                header('Location: /login');
                exit;
            }
            return null;
        }

        return $repo;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo $this->app->view()->render('partials/error.html.twig', [
            'code'    => 404,
            'message' => 'Repository not found.',
        ]);
    }

    /** POST /{user}/{repo}/releases/assets/upload — direct AJAX file upload */
    /**
     * GET /{user}/{repo}/releases/repo-binaries — scan git repo for binary distributions
     */
    public function scanRepoBinaries(string $user, string $repo): void
    {
        if (! $this->auth->isLoggedIn()) {
            $this->assetJson(['success' => false, 'error' => 'يجب تسجيل الدخول لاستعراض برامج المستودع'], 401);
            return;
        }

        $dbRepo = $this->resolveRepo($repo);
        if ($dbRepo === null) {
            $this->assetJson(['success' => false, 'error' => 'المستودع غير موجود'], 404);
            return;
        }

        $canWrite = $this->auth->isOwner() || $this->auth->canWriteRepo((int) $dbRepo['id']);
        if (! $canWrite) {
            $this->assetJson(['success' => false, 'error' => 'ليس لديك صلاحية تعديل هذا المستودع'], 403);
            return;
        }

        $ref = trim((string) ($_GET['ref'] ?? $dbRepo['default_branch'] ?? 'HEAD'));
        if ($ref === '') $ref = 'HEAD';

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);
        if (!is_dir($repoPath)) {
            // Fallback checking standard bare repository directory
            $altPath = dirname(__DIR__, 2) . '/repos/' . $dbRepo['slug'] . '.git';
            if (is_dir($altPath)) {
                $repoPath = $altPath;
            }
        }

        // Supported application and binary distribution extensions (excluding signatures/checksums by default)
        $extensions = [
            'deb', 'rpm', 'pkg', 'appimage', 'apk', 'dmg',
            'exe', 'msi', 'zip', 'tar.gz', 'tgz', 'tar.xz', 'txz', '7z'
        ];
        $signatureExtensions = ['asc', 'sig', 'sha256sums', 'sha256', 'sha512', 'md5'];
        $includeSignatures = !empty($_GET['include_signatures']) && $_GET['include_signatures'] !== '0';
        if ($includeSignatures) {
            $extensions = array_merge($extensions, $signatureExtensions);
        }

        // List files with size in tree
        $cmd = "git -C " . escapeshellarg($repoPath) . " ls-tree -r -l " . escapeshellarg($ref) . " 2>/dev/null";
        $output = trim((string) @shell_exec($cmd));

        $matches = [];
        if ($output !== '') {
            foreach (explode("\n", $output) as $line) {
                // Format: <mode> SP <type> SP <object> SP <size> TAB <path>
                $line = trim($line);
                if ($line === '') continue;

                $parts = preg_split("/\s+/", $line, 5);
                if (count($parts) < 5) continue;

                $type = $parts[1];
                if ($type !== 'blob') continue;

                $size = is_numeric($parts[3]) ? (int) $parts[3] : 0;
                $filePath = $parts[4];
                $filename = basename($filePath);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Check for double extension like tar.gz
                $lowerName = strtolower($filename);
                $isMatch = in_array($ext, $extensions, true);
                if (!$isMatch) {
                    foreach ($extensions as $extPattern) {
                        if (str_ends_with($lowerName, "." . $extPattern) || $lowerName === $extPattern) {
                            $isMatch = true;
                            break;
                        }
                    }
                }

                if ($isMatch) {
                    // Extract version pattern (e.g. 2.0.2, v2.0.2, 1.1.9)
                    $detectedVersion = '';
                    if (preg_match('/(?:v)?([0-9]+\.[0-9]+(?:\.[0-9]+)?(?:[a-zA-Z0-9\.\-\_]*))/i', $filename, $vMatches)) {
                        $detectedVersion = $vMatches[1];
                    }

                    $matches[] = [
                        'path'             => $filePath,
                        'name'             => $filename,
                        'filename'         => $filename,
                        'size_bytes'       => $size,
                        'size_formatted'   => $this->assets->formatBytes($size),
                        'is_dist'          => str_contains($filePath, 'dist'),
                        'detected_version' => $detectedVersion,
                        'ref'              => $ref,
                    ];
                }
            }
        }

        // Sort: dist/ folders first, then by name
        usort($matches, function ($a, $b) {
            $aDist = str_contains($a['path'], 'dist') ? 0 : 1;
            $bDist = str_contains($b['path'], 'dist') ? 0 : 1;
            if ($aDist !== $bDist) return $aDist <=> $bDist;
            return strnatcasecmp($a['path'], $b['path']);
        });

        $this->assetJson([
            'success'  => true,
            'ref'      => $ref,
            'files'    => $matches,
            'binaries' => $matches,
        ]);
    }

    /**
     * POST /{user}/{repo}/releases/attach-repo-binary — attach a file found in git repo to a release
     */
    public function attachRepoBinary(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;

        $filePath   = trim((string) ($input['path'] ?? ''));
        $releaseId  = (int) ($input['release_id'] ?? 0);
        $commitRef  = trim((string) ($input['ref'] ?? $dbRepo['default_branch'] ?? 'HEAD')) ?: 'HEAD';
        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        if ($filePath === '') {
            $this->assetJson(['success' => false, 'error' => 'File path is required.'], 400);
        }

        $release = $releaseId > 0 ? $this->loadRelease($dbRepo, $releaseId) : null;
        $tagName = $release ? (string) $release['tag_name'] : null;

        $repoPath = $this->gitService->getRepoPath($dbRepo['slug']);

        try {
            $asset = $this->assets->attachFromGitRepo(
                (int) $dbRepo['id'],
                $releaseId,
                $uploaderId,
                $user,
                (string) $dbRepo['slug'],
                $repoPath,
                $filePath,
                $commitRef,
                $tagName
            );

            $this->assetJson(['success' => true, 'asset' => $asset]);
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function assetDirectUpload(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $releaseId  = (int) ($_POST['release_id'] ?? 0);
        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            $this->assetJson(['success' => false, 'error' => 'No file uploaded or upload error.'], 400);
        }

        try {
            $asset = $this->assets->directUpload(
                (int)$dbRepo['id'],
                $releaseId,
                $uploaderId,
                $user,
                (string)$dbRepo['slug'],
                $file
            );
            $this->assetJson(['success' => true, 'asset' => $asset]);
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /{user}/{repo}/releases/assets/link-unified — link a file from Unified Download Center */
    public function assetLinkUnified(string $user, string $repo): void
    {
        $this->requireWriteAccess($repo, $dbRepo);
        if ($dbRepo === null) return;

        if (! $this->auth->validateCsrf()) {
            $this->assetJson(['success' => false, 'error' => 'Invalid security token.'], 403);
        }

        $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $downloadId = (int) ($input['download_id'] ?? 0);
        $releaseId  = (int) ($input['release_id'] ?? 0);
        $uploaderId = $this->auth->isOwner() ? 0 : (int) ($this->auth->user()['id'] ?? 0);

        if ($downloadId < 1) {
            $this->assetJson(['success' => false, 'error' => 'download_id is required.'], 400);
        }

        try {
            $asset = $this->assets->linkUnifiedDownload(
                $downloadId,
                (int)$dbRepo['id'],
                $releaseId,
                $uploaderId,
                $user,
                (string)$dbRepo['slug']
            );
            $this->assetJson(['success' => true, 'asset' => $asset]);
        } catch (\Throwable $e) {
            $this->assetJson(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** Helper to fetch files available in the Unified Download Center */
    private function getUnifiedDownloadsList(): array
    {
        try {
            $db = $this->app->db()->connection();
            $stmt = $db->query('
                SELECT fd.id, fd.title, fd.original_name, fd.file_size, fd.short_code, fd.mime_type, fd.created_at, df.name AS folder_name
                FROM file_downloads fd
                LEFT JOIN download_folders df ON fd.folder_id = df.id
                WHERE fd.is_active = 1
                ORDER BY fd.created_at DESC
                LIMIT 200
            ');
            $files = $stmt->fetchAll() ?: [];
            $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
            return array_map(function (array $f) use ($appUrl): array {
                $f['size_formatted'] = $this->assets->formatBytes((int)$f['file_size']);
                $f['short_url'] = "{$appUrl}/d/{$f['short_code']}";
                return $f;
            }, $files);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
