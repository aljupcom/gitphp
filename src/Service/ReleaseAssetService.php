<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use RuntimeException;

/**
 * Chunked, resumable release-asset upload pipeline.
 *
 * Flow (all state on disk under storage/tmp/uploads/):
 *   1. init()     — validate limits/filename, create an upload session dir,
 *                   return the session id the client echoes on every chunk.
 *   2. chunk()    — append one numbered 5MB part; re-uploading the same
 *                   index is idempotent (overwrites), so retries are safe.
 *   3. complete() — merge parts in order, compute sha256 + size, sniff the
 *                   MIME type, move the result into
 *                   storage/downloads/releases/{repoId}/{releaseId}/ and
 *                   insert the release_assets row.
 *
 * Security notes:
 *   - Session dirs are addressed by a random 32-hex id (unguessable),
 *     bound to uploader id + repo id + release id at init; chunk calls
 *     re-verify the binding so a session cannot be retargeted.
 *   - Filenames are sanitized and reject traversal/execution extensions.
 *   - The enforced per-file cap is system_settings.max_release_asset_mb
 *     (previously a dead setting — now the effective limit).
 */
final class ReleaseAssetService
{
    private const CHUNK_BYTES = 5 * 1024 * 1024; // 5 MiB per part

    public function __construct(private App $app)
    {
    }

    private function uploadsTmpDir(): string
    {
        $dir = $this->app->basePath('storage/tmp/uploads');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private function sessionDir(string $sessionId): string
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $sessionId)) {
            throw new RuntimeException('Invalid upload session id.');
        }
        return $this->uploadsTmpDir() . DIRECTORY_SEPARATOR . $sessionId;
    }

    /** Reject dangerous extensions and traversal; return a clean filename. */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = str_replace("\0", '', $name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid file name.');
        }

        $lower = strtolower($name);
        foreach (['.php', '.phtml', '.php3', '.php4', '.php5', '.php7', '.phps', '.phar', '.cgi', '.pl', '.asp', '.aspx', '.jsp', '.htaccess', '.user.ini'] as $bad) {
            if (str_ends_with($lower, $bad)) {
                throw new RuntimeException("Executable file names ({$bad}) are not allowed as release assets.");
            }
        }

        $clean = preg_replace('/[^a-zA-Z0-9._()\-]/', '_', $name);
        if (mb_strlen((string) $clean) > 200) {
            $ext  = pathinfo($clean, PATHINFO_EXTENSION);
            $base = mb_substr(pathinfo($clean, PATHINFO_FILENAME), 0, 180);
            $clean = $base . ($ext !== '' ? '.' . $ext : '');
        }

        return (string) $clean;
    }

    public function maxAssetBytes(): int
    {
        try {
            $mb = (int) ($this->app->db()->fetchOne(
                "SELECT `value` FROM `system_settings` WHERE `key_name` = 'max_release_asset_mb'"
            )['value'] ?? 500);
        } catch (\Throwable) {
            $mb = 500;
        }

        return max(1, $mb) * 1024 * 1024;
    }

    /** @return array{session_id: string, chunk_bytes: int, max_bytes: int} */
    public function init(int $repoId, int $releaseId, int $uploaderId, string $filename, int $sizeBytes): array
    {
        $filename = $this->sanitizeFilename($filename);

        $maxBytes = $this->maxAssetBytes();
        if ($sizeBytes < 1) {
            throw new RuntimeException('File is empty.');
        }
        if ($sizeBytes > $maxBytes) {
            throw new RuntimeException(sprintf(
                'File exceeds the maximum release asset size of %d MB.',
                (int) ($maxBytes / 1048576)
            ));
        }

        // One active upload session per uploader, keep the count bounded.
        $this->reapStaleSessions();

        $sessionId = bin2hex(random_bytes(16));
        $dir = $this->sessionDir($sessionId);
        if (! @mkdir($dir, 0775, true)) {
            throw new RuntimeException('Unable to create upload session directory.');
        }

        // Binding manifest — verified on every chunk/complete call.
        file_put_contents($dir . '/manifest.json', json_encode([
            'repo_id'     => $repoId,
            'release_id'  => $releaseId,
            'uploader_id' => $uploaderId,
            'filename'    => $filename,
            'size_bytes'  => $sizeBytes,
            'created_at'  => time(),
        ], JSON_THROW_ON_ERROR));

        return [
            'session_id'  => $sessionId,
            'chunk_bytes' => self::CHUNK_BYTES,
            'max_bytes'   => $maxBytes,
        ];
    }

    /** @return array{received: int, bytes_so_far: int} */
    public function chunk(string $sessionId, int $repoId, int $releaseId, int $uploaderId, int $index, string $bytes): array
    {
        $dir = $this->sessionDir($sessionId);

        $manifest = $this->readManifest($dir);
        if (
            (int) $manifest['repo_id'] !== $repoId
            || (int) $manifest['release_id'] !== $releaseId
            || (int) $manifest['uploader_id'] !== $uploaderId
        ) {
            throw new RuntimeException('Upload session does not match the current context.');
        }

        if ($index < 0 || $index > 100000) {
            throw new RuntimeException('Invalid chunk index.');
        }

        $len = strlen($bytes);
        if ($len < 1 || $len > self::CHUNK_BYTES + 1048576) { // last part may exceed slightly for safety
            throw new RuntimeException('Invalid chunk size.');
        }

        $partPath = $dir . '/part_' . sprintf('%06d', $index);
        if (file_put_contents($partPath, $bytes) === false) {
            throw new RuntimeException('Failed to store chunk on disk.');
        }

        // Enforce the global cap as chunks arrive (never trust client size).
        $total = $this->sessionBytes($dir);
        if ($total > $this->maxAssetBytes()) {
            $this->destroySession($sessionId);
            throw new RuntimeException('Upload exceeded the maximum release asset size.');
        }

        return ['received' => $index, 'bytes_so_far' => $total];
    }

    /** @return array{id: int, name: string, size_bytes: int, sha256: string, download_url: string} */
    public function complete(string $sessionId, int $repoId, int $releaseId, int $uploaderId, string $owner, string $repoSlug): array
    {
        $dir = $this->sessionDir($sessionId);

        $manifest = $this->readManifest($dir);
        if (
            (int) $manifest['repo_id'] !== $repoId
            || (int) $manifest['release_id'] !== $releaseId
            || (int) $manifest['uploader_id'] !== $uploaderId
        ) {
            throw new RuntimeException('Upload session does not match the current context.');
        }

        $filename = (string) $manifest['filename'];

        // Destination first (needs asset id for the stored file name).
        $db = $this->app->db()->connection();
        $ins = $db->prepare('
            INSERT INTO release_assets (release_id, repo_id, uploader_id, name, storage_path, size_bytes, sha256, mime, created_at)
            VALUES (?, ?, ?, ?, ?, 0, \'\', \'application/octet-stream\', NOW())
        ');
        $ins->execute([$releaseId, $repoId, $uploaderId > 0 ? $uploaderId : null, $filename, 'pending']);
        $assetId = (int) $db->lastInsertId();

        $destDir = $this->app->basePath("storage/downloads/releases/{$repoId}/{$releaseId}");
        if (! is_dir($destDir)) @mkdir($destDir, 0775, true);

        // Merge parts in order, hashing while streaming.
        $finalPath = $destDir . DIRECTORY_SEPARATOR . $assetId . '_' . $filename;
        $out = @fopen($finalPath, 'wb');
        if ($out === false) {
            $this->destroySession($sessionId);
            $db->prepare('DELETE FROM release_assets WHERE id = ?')->execute([$assetId]);
            throw new RuntimeException('Unable to write the destination file.');
        }

        $ctx = hash_init('sha256');
        $size = 0;
        try {
            $parts = glob($dir . '/part_*') ?: [];
            usort($parts, static fn(string $a, string $b): int => strcmp($a, $b));

            foreach ($parts as $part) {
                $in = @fopen($part, 'rb');
                if ($in === false) continue;
                while (! feof($in)) {
                    $data = fread($in, 262144);
                    if ($data === false || $data === '') continue;
                    fwrite($out, $data);
                    hash_update($ctx, $data);
                    $size += strlen($data);
                }
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        if ($size < 1) {
            @unlink($finalPath);
            $this->destroySession($sessionId);
            $db->prepare('DELETE FROM release_assets WHERE id = ?')->execute([$assetId]);
            throw new RuntimeException('No chunks were uploaded for this asset.');
        }

        if ($size > $this->maxAssetBytes()) {
            @unlink($finalPath);
            $this->destroySession($sessionId);
            $db->prepare('DELETE FROM release_assets WHERE id = ?')->execute([$assetId]);
            throw new RuntimeException('Merged file exceeds the maximum release asset size.');
        }

        $sha256 = hash_final($ctx);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->file($finalPath) ?: 'application/octet-stream');
        if ($mime === '' || str_starts_with($mime, 'text/x-php')) {
            $mime = 'application/octet-stream';
        }

        $relPath = "storage/downloads/releases/{$repoId}/{$releaseId}/" . $assetId . '_' . $filename;

        $upd = $db->prepare('
            UPDATE release_assets
            SET storage_path = ?, size_bytes = ?, sha256 = ?, mime = ?
            WHERE id = ?
        ');
        $upd->execute([$relPath, $size, $sha256, $mime, $assetId]);

        $this->destroySession($sessionId);

        return [
            'id'            => $assetId,
            'name'          => $filename,
            'size_bytes'    => $size,
            'sha256'        => $sha256,
            'download_url'  => "/{$owner}/{$repoSlug}/releases/download/{$assetId}",
        ];
    }

    public function deleteAsset(int $assetId, int $repoId): bool
    {
        $db = $this->app->db()->connection();

        $row = $db->prepare('SELECT id, repo_id, storage_path FROM release_assets WHERE id = ?');
        $row->execute([$assetId]);
        $asset = $row->fetch();

        if ($asset === false || (int) $asset['repo_id'] !== $repoId) return false;

        $abs = $this->app->basePath((string) $asset['storage_path']);
        if (is_file($abs)) @unlink($abs);

        $del = $db->prepare('DELETE FROM release_assets WHERE id = ?');
        $del->execute([$assetId]);

        return true;
    }

    /** @return array{id:int, release_id:int, repo_id:int, name:string, storage_path:string, size_bytes:int, sha256:string, mime:string, download_count:int} */
    public function getAsset(int $assetId): ?array
    {
        $db = $this->app->db()->connection();
        $row = $db->prepare('SELECT * FROM release_assets WHERE id = ?');
        $row->execute([$assetId]);
        $asset = $row->fetch();

        return $asset === false ? null : $asset;
    }

    /** @return array{id:int, release_id:int, repo_id:int, name:string, storage_path:string, size_bytes:int, sha256:string, mime:string, download_count:int}|null */
    public function getAssetByTagAndFilename(int $repoId, string $tagName, string $filename): ?array
    {
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            SELECT ra.* 
            FROM release_assets ra
            INNER JOIN repo_releases rr ON rr.id = ra.release_id
            WHERE ra.repo_id = ? AND rr.tag_name = ? AND ra.name = ?
            LIMIT 1
        ');
        $stmt->execute([$repoId, $tagName, $filename]);
        $asset = $stmt->fetch();
        return $asset === false ? null : $asset;
    }

    public function recordDownload(int $assetId): void
    {
        $this->app->db()->execute(
            'UPDATE `release_assets` SET `download_count` = `download_count` + 1 WHERE `id` = :id',
            ['id' => $assetId],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function assetsForRelease(int $releaseId): array
    {
        $assets = $this->app->db()->fetchAll(
            'SELECT ra.*, fd.short_code, fd.download_count AS unified_downloads, fd.password_hash AS is_locked, fd.expires_at AS unified_expires_at
             FROM `release_assets` ra
             LEFT JOIN `file_downloads` fd ON (fd.file_path = ra.storage_path OR fd.file_path LIKE CONCAT("%", ra.storage_path) OR (fd.original_name = ra.name AND ra.created_at >= DATE_SUB(fd.created_at, INTERVAL 1 HOUR)))
             WHERE ra.`release_id` = :rid
             GROUP BY ra.id
             ORDER BY ra.`created_at` ASC',
            ['rid' => $releaseId],
        );

        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');

        return array_map(function (array $a) use ($appUrl): array {
            $a['short_url'] = !empty($a['short_code']) ? "{$appUrl}/d/{$a['short_code']}" : null;
            $a['formatted_size'] = $this->formatBytes((int) ($a['size_bytes'] ?? 0));
            return $a;
        }, $assets);
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 1) . ' ' . ($units[$i] ?? 'B');
    }

    public function syncToDownloadCenter(int $assetId, int $repoId, int $releaseId, string $filename, string $storagePath, int $sizeBytes, string $mime, int $uploaderId, ?string $tagName = null): string
    {
        $db = $this->app->db()->connection();

        // 1. Find or create 'Releases' folder in download_folders
        $fStmt = $db->prepare('SELECT id FROM download_folders WHERE slug = "releases" LIMIT 1');
        $fStmt->execute();
        $folder = $fStmt->fetch();
        if (!$folder) {
            $insF = $db->prepare('INSERT INTO download_folders (name, slug, description, created_by, created_at) VALUES ("Releases", "releases", "Official Repository Releases & Binaries", ?, NOW())');
            $insF->execute([$uploaderId > 0 ? $uploaderId : null]);
            $folderId = (int) $db->lastInsertId();
        } else {
            $folderId = (int) $folder['id'];
        }

        // 2. Generate unique 6-char short_code
        $shortCode = substr(bin2hex(random_bytes(4)), 0, 6);
        $title = $tagName ? "Release {$tagName} - {$filename}" : "Release Asset - {$filename}";

        $ins = $db->prepare('
            INSERT INTO file_downloads (title, folder_id, original_name, file_path, file_size, mime_type, short_code, is_active, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
        ');
        $ins->execute([
            $title,
            $folderId,
            $filename,
            $storagePath,
            $sizeBytes,
            $mime,
            $shortCode,
            $uploaderId > 0 ? $uploaderId : null,
        ]);

        return $shortCode;
    }

    /**
     * Attach a binary file stored in git repository to a release
     */
    public function attachFromGitRepo(int $repoId, int $releaseId, int $uploaderId, string $owner, string $repoSlug, string $repoPath, string $gitFilePath, string $commitRef = "HEAD", ?string $tagName = null): array
    {
        $filename = $this->sanitizeFilename(basename($gitFilePath));
        
        // Check file exists in git and get its size
        $checkCmd = "git -C " . escapeshellarg($repoPath) . " ls-tree -l " . escapeshellarg($commitRef) . " " . escapeshellarg($gitFilePath) . " 2>/dev/null";
        $checkOut = trim((string) @shell_exec($checkCmd));
        if ($checkOut === "") {
            throw new RuntimeException("File not found in repository at ref {$commitRef}: {$gitFilePath}");
        }

        // Parse size from ls-tree: mode type object size path
        $parts = preg_split("/\s+/", $checkOut, 5);
        $sizeBytes = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : 0;

        $db = $this->app->db()->connection();
        $ins = $db->prepare('
            INSERT INTO release_assets (release_id, repo_id, uploader_id, name, storage_path, size_bytes, sha256, mime, created_at)
            VALUES (?, ?, ?, ?, ?, 0, "", "application/octet-stream", NOW())
        ');
        $ins->execute([$releaseId, $repoId, $uploaderId > 0 ? $uploaderId : null, $filename, "pending"]);
        $assetId = (int) $db->lastInsertId();

        $destDir = $this->app->basePath("storage/downloads/releases/{$repoId}/{$releaseId}");
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $finalPath = $destDir . DIRECTORY_SEPARATOR . $assetId . "_" . $filename;

        // Extract blob directly from git into destination file
        $extractCmd = "git -C " . escapeshellarg($repoPath) . " show " . escapeshellarg("{$commitRef}:{$gitFilePath}") . " > " . escapeshellarg($finalPath) . " 2>/dev/null";
        @shell_exec($extractCmd);

        if (!is_file($finalPath) || filesize($finalPath) === 0) {
            $db->prepare("DELETE FROM release_assets WHERE id = ?")->execute([$assetId]);
            throw new RuntimeException("Failed to extract file from git repository.");
        }

        $realSize = (int) filesize($finalPath);
        $sha256 = hash_file("sha256", $finalPath) ?: "";
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($finalPath) ?: "application/octet-stream");
        if ($mime === "" || str_starts_with($mime, "text/x-php")) $mime = "application/octet-stream";

        $relPath = "storage/downloads/releases/{$repoId}/{$releaseId}/" . $assetId . "_" . $filename;
        $db->prepare("UPDATE release_assets SET storage_path = ?, size_bytes = ?, sha256 = ?, mime = ? WHERE id = ?")
           ->execute([$relPath, $realSize, $sha256, $mime, $assetId]);

        $shortCode = $this->syncToDownloadCenter($assetId, $repoId, $releaseId, $filename, $relPath, $realSize, $mime, $uploaderId, $tagName);
        $appUrl = rtrim((string) $this->app->config("app.url", "https://git.ysnapp.com"), "/");

        return [
            "id"             => $assetId,
            "name"           => $filename,
            "size_bytes"     => $realSize,
            "formatted_size" => $this->formatBytes($realSize),
            "sha256"         => $sha256,
            "short_code"     => $shortCode,
            "short_url"      => "{$appUrl}/d/{$shortCode}",
            "download_url"   => "/{$owner}/{$repoSlug}/releases/download/{$assetId}",
        ];
    }

    public function directUpload(int $repoId, int $releaseId, int $uploaderId, string $owner, string $repoSlug, array $file, ?string $tagName = null): array
    {
        $filename = $this->sanitizeFilename((string)($file['name'] ?? 'asset.bin'));
        $sizeBytes = (int) ($file['size'] ?? 0);
        $tmpPath = (string) ($file['tmp_name'] ?? '');

        if ($sizeBytes < 1 || !is_file($tmpPath)) {
            throw new RuntimeException('Uploaded file is empty or invalid.');
        }

        $maxBytes = $this->maxAssetBytes();
        if ($sizeBytes > $maxBytes) {
            throw new RuntimeException(sprintf('File exceeds the maximum release asset size of %d MB.', (int) ($maxBytes / 1048576)));
        }

        $db = $this->app->db()->connection();
        $ins = $db->prepare('
            INSERT INTO release_assets (release_id, repo_id, uploader_id, name, storage_path, size_bytes, sha256, mime, created_at)
            VALUES (?, ?, ?, ?, ?, 0, "", "application/octet-stream", NOW())
        ');
        $ins->execute([$releaseId, $repoId, $uploaderId > 0 ? $uploaderId : null, $filename, 'pending']);
        $assetId = (int) $db->lastInsertId();

        $destDir = $this->app->basePath("storage/downloads/releases/{$repoId}/{$releaseId}");
        if (!is_dir($destDir)) @mkdir($destDir, 0775, true);

        $finalPath = $destDir . DIRECTORY_SEPARATOR . $assetId . '_' . $filename;
        if (!move_uploaded_file($tmpPath, $finalPath) && !copy($tmpPath, $finalPath)) {
            $db->prepare('DELETE FROM release_assets WHERE id = ?')->execute([$assetId]);
            throw new RuntimeException('Failed to store uploaded asset on server.');
        }

        $sha256 = hash_file('sha256', $finalPath) ?: '';
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($finalPath) ?: 'application/octet-stream');
        if ($mime === '' || str_starts_with($mime, 'text/x-php')) $mime = 'application/octet-stream';

        $relPath = "storage/downloads/releases/{$repoId}/{$releaseId}/" . $assetId . '_' . $filename;
        $db->prepare('UPDATE release_assets SET storage_path = ?, size_bytes = ?, sha256 = ?, mime = ? WHERE id = ?')
           ->execute([$relPath, $sizeBytes, $sha256, $mime, $assetId]);

        $shortCode = $this->syncToDownloadCenter($assetId, $repoId, $releaseId, $filename, $relPath, $sizeBytes, $mime, $uploaderId, $tagName);
        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');

        return [
            'id'             => $assetId,
            'name'           => $filename,
            'size_bytes'     => $sizeBytes,
            'formatted_size' => $this->formatBytes($sizeBytes),
            'sha256'         => $sha256,
            'short_code'     => $shortCode,
            'short_url'      => "{$appUrl}/d/{$shortCode}",
            'download_url'   => "/{$owner}/{$repoSlug}/releases/download/{$assetId}",
        ];
    }

    public function linkUnifiedDownload(int $downloadId, int $repoId, int $releaseId, int $uploaderId, string $owner, string $repoSlug): array
    {
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT * FROM file_downloads WHERE id = ? LIMIT 1');
        $stmt->execute([$downloadId]);
        $dl = $stmt->fetch();
        if (!$dl) {
            throw new RuntimeException('File not found in Unified Download Center.');
        }

        $filename = $this->sanitizeFilename((string)$dl['original_name']);
        $sizeBytes = (int) $dl['file_size'];
        $mime = (string) ($dl['mime_type'] ?: 'application/octet-stream');
        $storagePath = (string) $dl['file_path'];
        $absPath = $this->app->basePath($storagePath);
        $sha256 = is_file($absPath) ? (hash_file('sha256', $absPath) ?: '') : '';

        $ins = $db->prepare('
            INSERT INTO release_assets (release_id, repo_id, uploader_id, name, storage_path, size_bytes, sha256, mime, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $ins->execute([
            $releaseId,
            $repoId,
            $uploaderId > 0 ? $uploaderId : null,
            $filename,
            $storagePath,
            $sizeBytes,
            $sha256,
            $mime,
        ]);
        $assetId = (int) $db->lastInsertId();

        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $shortCode = (string) $dl['short_code'];

        return [
            'id'             => $assetId,
            'name'           => $filename,
            'size_bytes'     => $sizeBytes,
            'formatted_size' => $this->formatBytes($sizeBytes),
            'sha256'         => $sha256,
            'short_code'     => $shortCode,
            'short_url'      => !empty($shortCode) ? "{$appUrl}/d/{$shortCode}" : null,
            'download_url'   => "/{$owner}/{$repoSlug}/releases/download/{$assetId}",
        ];
    }

    private function sessionBytes(string $dir): int
    {
        $total = 0;
        foreach (glob($dir . '/part_*') ?: [] as $p) {
            $total += (int) filesize($p);
        }
        return $total;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $dir): array
    {
        $manifestPath = $dir . '/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Upload session not found or expired.');
        }

        $data = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($data)) {
            throw new RuntimeException('Corrupt upload session.');
        }

        return $data;
    }

    /** Remove sessions older than 12h and keep at most ~200 alive. */
    private function reapStaleSessions(): void
    {
        $base = $this->uploadsTmpDir();
        $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];

        $cutoff = time() - 43200;
        $alive  = [];
        foreach ($dirs as $d) {
            if (basename($d) === 'releases') continue; // never touch final storage
            $mtime = (int) @filemtime($d);
            if ($mtime < $cutoff) {
                $this->rrmdir($d);
            } else {
                $alive[$d] = $mtime;
            }
        }

        if (count($alive) > 200) {
            asort($alive);
            foreach (array_slice($alive, 0, count($alive) - 200, true) as $d => $_) {
                $this->rrmdir($d);
            }
        }
    }

    public function destroySession(string $sessionId): void
    {
        $dir = $this->sessionDir($sessionId);
        if (is_dir($dir)) $this->rrmdir($dir);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) $this->rrmdir($f);
            else @unlink($f);
        }
        @rmdir($dir);
    }
}
