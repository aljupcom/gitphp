<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use Symfony\Component\Process\Process;

final class DownloadBridge
{
    private App $app;
    private GitService $gitService;
    private GitReader $gitReader;
    private AuditLogger $auditLogger;

    public function __construct(App $app)
    {
        $this->app         = $app;
        $this->gitService = new GitService();
        $this->gitReader  = new GitReader();
        $this->auditLogger = new AuditLogger($app);
    }

    /** Create a unified short download link for a repository snapshot archive (ZIP) */
    public function createRepoArchiveLink(
        string $slug,
        string $ref = 'HEAD',
        ?string $password = null,
        ?int $expiryDays = null,
        ?int $maxDownloads = null,
        int $userId = 0
    ): array {
        $repoPath = $this->gitService->getRepoPath($slug);
        if (!is_dir($repoPath)) {
            throw new \RuntimeException("Repository '{$slug}' not found.");
        }

        $downloadsDir = $this->app->basePath('storage/downloads');
        if (!is_dir($downloadsDir)) {
            @mkdir($downloadsDir, 0755, true);
        }

        // A leading dash would be parsed by git as an option (e.g. --output=)
        if ($ref === '' || str_starts_with($ref, '-') || str_contains($ref, "\0")) {
            throw new \RuntimeException("Invalid ref '{$ref}'.");
        }

        $safeRef   = preg_replace('/[^a-zA-Z0-9._-]/', '_', $ref) ?: 'main';
        $shortCode = substr(bin2hex(random_bytes(4)), 0, 6);
        $zipName   = "{$slug}-{$safeRef}.zip";
        $zipPath   = $downloadsDir . DIRECTORY_SEPARATOR . "{$shortCode}_{$zipName}";

        // Generate bare git archive zip
        $proc = new Process(['git', 'archive', '--format=zip', '--prefix=' . $slug . '-' . $safeRef . '/', '-o', $zipPath, $ref], $repoPath);
        $proc->setTimeout(30);
        $proc->run();

        if (!$proc->isSuccessful() || !file_exists($zipPath)) {
            throw new \RuntimeException('Failed to generate repository archive zip: ' . $proc->getErrorOutput());
        }

        $fileSize  = (int) filesize($zipPath);
        $pwdHash   = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
        $expiresAt = $expiryDays ? date('Y-m-d H:i:s', time() + ($expiryDays * 86400)) : null;

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            INSERT INTO file_downloads (title, original_name, file_path, file_size, mime_type, short_code, password_hash, max_downloads, expires_at, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $title = "Repository Snapshot ({$slug} @ {$safeRef})";
        $stmt->execute([
            $title,
            $zipName,
            $zipPath,
            $fileSize,
            'application/zip',
            $shortCode,
            $pwdHash,
            $maxDownloads,
            $expiresAt,
            $userId,
        ]);

        $appUrl   = rtrim((string) $this->app->config('app.url', 'http://localhost:8080'), '/');
        $shortUrl = "{$appUrl}/d/{$shortCode}";

        $this->auditLogger->log('download.create', null, "Generated snapshot link for {$slug}@{$safeRef} (code: {$shortCode})", $userId);

        return [
            'short_code' => $shortCode,
            'short_url'  => $shortUrl,
            'title'      => $title,
            'filename'   => $zipName,
            'file_size'  => $fileSize,
            'is_locked'  => !empty($password),
        ];
    }

    /** Create a unified short download link for a single file blob */
    public function createBlobDownloadLink(
        string $slug,
        string $ref,
        string $path,
        ?string $password = null,
        ?int $expiryDays = null,
        ?int $maxDownloads = null,
        int $userId = 0
    ): array {
        $repoPath = $this->gitService->getRepoPath($slug);
        if (!is_dir($repoPath)) {
            throw new \RuntimeException("Repository '{$slug}' not found.");
        }

        $content = $this->gitReader->getBlob($repoPath, $ref, $path);
        if ($content === null) {
            throw new \RuntimeException("File '{$path}' not found at ref '{$ref}'.");
        }

        $downloadsDir = $this->app->basePath('storage/downloads');
        if (!is_dir($downloadsDir)) {
            @mkdir($downloadsDir, 0755, true);
        }

        $origName  = basename($path);
        $shortCode = substr(bin2hex(random_bytes(4)), 0, 6);
        $safeName  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        $filePath  = $downloadsDir . DIRECTORY_SEPARATOR . "{$shortCode}_{$safeName}";

        file_put_contents($filePath, $content);

        $fileSize  = strlen($content);
        $mimeType  = mime_content_type($filePath) ?: 'application/octet-stream';
        $pwdHash   = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;
        $expiresAt = $expiryDays ? date('Y-m-d H:i:s', time() + ($expiryDays * 86400)) : null;

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('
            INSERT INTO file_downloads (title, original_name, file_path, file_size, mime_type, short_code, password_hash, max_downloads, expires_at, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $title = "File Download ({$origName} from {$slug})";
        $stmt->execute([
            $title,
            $origName,
            $filePath,
            $fileSize,
            $mimeType,
            $shortCode,
            $pwdHash,
            $maxDownloads,
            $expiresAt,
            $userId,
        ]);

        $appUrl   = rtrim((string) $this->app->config('app.url', 'http://localhost:8080'), '/');
        $shortUrl = "{$appUrl}/d/{$shortCode}";

        $this->auditLogger->log('download.create', null, "Generated file download link for {$slug}/{$path} (code: {$shortCode})", $userId);

        return [
            'short_code' => $shortCode,
            'short_url'  => $shortUrl,
            'title'      => $title,
            'filename'   => $origName,
            'file_size'  => $fileSize,
            'is_locked'  => !empty($password),
        ];
    }
}
