<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\AuditLogger;
use App\Service\Locale;

final class DownloadCenterController
{
    private App $app;
    private Auth $auth;
    private AuditLogger $auditLogger;
    private \App\Service\DownloadTokenService $tokenService;

    /** Admin nav counters cached for 60s to avoid 4 COUNT(*) queries per page load. */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    public function __construct(App $app)
    {
        $this->app          = $app;
        $this->auth         = new Auth($app);
        $this->auditLogger  = new AuditLogger($app);
        $this->tokenService = new \App\Service\DownloadTokenService($app);
    }

    /** GET /admin/downloads — file distribution and short links dashboard */
    public function adminIndex(): void
    {
        $this->auth->requireOwner();

        $db     = $this->app->db()->connection();
        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $ap     = $this->auth->adminPrefix();

        // All files (Cached 30s)
        $enriched = $this->app->cache()->remember('admin:downloads:list_v2', 30, function () use ($db, $appUrl): array {
            $stmt  = $db->query('SELECT fd.*, df.name AS folder_name, df.slug AS folder_slug FROM file_downloads fd LEFT JOIN download_folders df ON fd.folder_id = df.id ORDER BY fd.created_at DESC');
            $files = $stmt->fetchAll();

            return array_map(static function (array $f) use ($appUrl): array {
                $isExpired      = !empty($f['expires_at'])    && strtotime($f['expires_at']) < time();
                $isLimitReached = !empty($f['max_downloads']) && (int) $f['download_count'] >= (int) $f['max_downloads'];
                return array_merge($f, [
                    'short_url'        => "{$appUrl}/d/{$f['short_code']}",
                    'is_expired'       => $isExpired,
                    'is_limit_reached' => $isLimitReached,
                    'is_locked'        => !empty($f['password_hash']),
                ]);
            }, $files);
        });

        // Folder tree (parents first, then children)
        $folders = $this->app->cache()->remember('admin:downloads:folders_v2', 30, function () use ($db): array {
            return $this->getFolderTree($db);
        });

        $this->app->view()->display('admin/downloads.twig', [
            'files'          => $enriched,
            'folders'        => $folders,
            'current_folder' => null,
            'app_url'        => $appUrl,
            'admin_prefix'   => $ap,
            'nav_counts'     => $this->getNavCounts(),
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /admin/downloads/folder/{slug} — browse a specific folder */
    public function adminFolder(string $slug): void
    {
        $this->auth->requireOwner();

        $db     = $this->app->db()->connection();
        $appUrl = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $ap     = $this->auth->adminPrefix();

        $fStmt  = $db->prepare('SELECT * FROM download_folders WHERE slug = ? LIMIT 1');
        $fStmt->execute([$slug]);
        $folder = $fStmt->fetch();

        if (! $folder) {
            $_SESSION['flash_error'] = 'Folder not found.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $stmt = $db->prepare(
            'SELECT fd.*, df.name AS folder_name, df.slug AS folder_slug
             FROM file_downloads fd
             LEFT JOIN download_folders df ON fd.folder_id = df.id
             WHERE fd.folder_id = ?
             ORDER BY fd.created_at DESC'
        );
        $stmt->execute([$folder['id']]);
        $files = $stmt->fetchAll();

        $enriched = array_map(static function (array $f) use ($appUrl): array {
            $isExpired      = !empty($f['expires_at'])    && strtotime($f['expires_at']) < time();
            $isLimitReached = !empty($f['max_downloads']) && (int) $f['download_count'] >= (int) $f['max_downloads'];
            return array_merge($f, [
                'short_url'        => "{$appUrl}/d/{$f['short_code']}",
                'is_expired'       => $isExpired,
                'is_limit_reached' => $isLimitReached,
                'is_locked'        => !empty($f['password_hash']),
            ]);
        }, $files);

        $folders = $this->getFolderTree($db);

        $this->app->view()->display('admin/downloads.twig', [
            'files'          => $enriched,
            'folders'        => $folders,
            'current_folder' => $folder,
            'app_url'        => $appUrl,
            'admin_prefix'   => $ap,
            'nav_counts'     => $this->getNavCounts(),
            'csrf_token'     => $this->auth->generateCsrf(),
        ]);
    }

    /** POST /admin/downloads/upload — upload file and generate secure short link */
    public function adminUpload(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        if (! $this->auth->validateCsrf()) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid or expired security token.']);
                exit;
            }
            $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
            header("Location: {$ap}/downloads");
            exit;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = match($_FILES['file']['error'] ?? -1) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds maximum allowed upload size.',
                UPLOAD_ERR_NO_FILE => 'No file was selected for upload.',
                default => 'Failed to upload file. Please try again.',
            };
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $err]);
                exit;
            }
            $_SESSION['flash_error'] = $err;
            header("Location: {$ap}/downloads");
            exit;
        }

        $file         = $_FILES['file'];

        $maxUploadMb = (int) ($this->app->db()->fetchOne("SELECT value FROM system_settings WHERE key_name = 'max_upload_mb'")['value'] ?? 100);
        if ($file['size'] > ($maxUploadMb * 1024 * 1024)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => "File size exceeds the platform upload limit of {$maxUploadMb} MB."]);
                exit;
            }
            $_SESSION['flash_error'] = "File size exceeds the platform upload limit of {$maxUploadMb} MB.";
            header("Location: {$ap}/downloads");
            exit;
        }

        $title        = trim((string) ($_POST['title'] ?? ''));
        $password     = trim((string) ($_POST['password'] ?? ''));
        $maxDownloads = !empty($_POST['max_downloads']) ? max(1, (int) $_POST['max_downloads']) : null;
        $expiryDays   = !empty($_POST['expiry_days']) ? max(1, (int) $_POST['expiry_days']) : null;
        $folderId     = !empty($_POST['folder_id']) ? (int) $_POST['folder_id'] : null;

        $origName = basename($file['name']);
        if ($title === '') $title = $origName;

        // Resolve folder slug for storage sub-directory
        $db          = $this->app->db()->connection();
        $folderSlug  = 'general';
        if ($folderId !== null) {
            $fStmt = $db->prepare('SELECT slug FROM download_folders WHERE id = ? LIMIT 1');
            $fStmt->execute([$folderId]);
            $folderRow  = $fStmt->fetch();
            if ($folderRow) {
                $folderSlug = $folderRow['slug'];
            } else {
                $folderId = null; // folder doesn't exist
            }
        }

        // Generate clean 6-character short code (e.g. "a9f2bc")
        $shortCode = substr(bin2hex(random_bytes(4)), 0, 6);

        // Target storage path — files live in storage/downloads/{folder-slug}/
        $downloadsDir = $this->app->basePath('storage/downloads/' . $folderSlug);
        if (!is_dir($downloadsDir)) {
            @mkdir($downloadsDir, 0777, true);
        }
        @chmod($downloadsDir, 0777);

        $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
        $storagePath  = $downloadsDir . DIRECTORY_SEPARATOR . $shortCode . '_' . $safeFileName;

        if (!move_uploaded_file($file['tmp_name'], $storagePath)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Failed to store uploaded file on disk. Check storage folder permissions.']);
                exit;
            }
            $_SESSION['flash_error'] = 'Failed to store uploaded file on disk. Check storage folder permissions.';
            header("Location: {$ap}/downloads");
            exit;
        }
        @chmod($storagePath, 0666);

        $fileSize  = (int) filesize($storagePath);
        $mimeType  = mime_content_type($storagePath) ?: 'application/octet-stream';
        $pwdHash   = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $expiresAt = $expiryDays !== null ? date('Y-m-d H:i:s', time() + ($expiryDays * 86400)) : null;

        $stmt = $db->prepare('
            INSERT INTO file_downloads (folder_id, title, original_name, file_path, file_size, mime_type, short_code, password_hash, max_downloads, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $folderId,
            $title,
            $origName,
            $storagePath,
            $fileSize,
            $mimeType,
            $shortCode,
            $pwdHash,
            $maxDownloads,
            $expiresAt,
        ]);

        $this->auditLogger->log('download.create', null, "Created short download link for '{$origName}' (code: {$shortCode}, folder: {$folderSlug})");
        $this->app->cache()->forget('admin:nav_counts');

        // AJAX uploaders (quick-upload widget) get a JSON payload instead of a redirect.
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success'   => true,
                'short_code' => $shortCode,
                'url'       => '/d/' . $shortCode,
                'size'      => $fileSize,
                'title'     => $title,
            ]);
            exit;
        }

        $redirect = $folderId ? "{$ap}/downloads/folder/{$folderSlug}" : "{$ap}/downloads";
        $_SESSION['flash_success'] = "File uploaded successfully. Short link: /d/{$shortCode}";
        header("Location: {$redirect}");
        exit;
    }

    /** POST /admin/downloads/{id:\d+}/delete — delete download link and file */
    public function adminDelete(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid or expired security token. Please try again.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT * FROM file_downloads WHERE id = ?');
        $stmt->execute([$id]);
        $file = $stmt->fetch();

        if ($file) {
            if (file_exists($file['file_path'])) {
                @unlink($file['file_path']);
            }
            $del = $db->prepare('DELETE FROM file_downloads WHERE id = ?');
            $del->execute([$id]);

            $this->auditLogger->log('download.delete', null, "Deleted download link '{$file['title']}' (code: {$file['short_code']})");
            $this->app->cache()->forget('admin:nav_counts');
            $_SESSION['flash_success'] = 'Download link deleted successfully.';
        }

        header("Location: {$ap}/downloads");
        exit;
    }

    /** GET /d/{code} — public download portal landing page */
    public function publicDownloadPage(string $code): void
    {
        $file = $this->resolveFile($code);
        if ($file === null) {
            $this->renderDownloadError('File Not Found', 'The requested download link does not exist or has been removed.', 404);
            return;
        }

        if (!$file['is_active']) {
            $this->renderDownloadError('Link Inactive', 'This download link has been disabled.', 403);
            return;
        }

        if (!empty($file['expires_at']) && strtotime($file['expires_at']) < time()) {
            $this->renderDownloadError('Link Expired', 'This download link expired on ' . date('M j, Y H:i', strtotime($file['expires_at'])), 410);
            return;
        }

        if (!empty($file['max_downloads']) && (int) $file['download_count'] >= (int) $file['max_downloads']) {
            $this->renderDownloadError('Download Limit Reached', 'This link has reached its maximum download allowance.', 410);
            return;
        }

        $isUnlocked = empty($file['password_hash']) || !empty($_SESSION["dl_unlocked_{$code}"]);
        $clientIp   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $downloadToken = $this->tokenService->generateToken($code, 3600, $clientIp);

        // Calculate file checksum if file exists
        $fileSha256 = null;
        if (is_file($file['file_path'])) {
            // For files under 150MB calculate real sha256, else fallback to null or quick hash
            if ((int)$file['file_size'] < 150 * 1024 * 1024) {
                $fileSha256 = hash_file('sha256', $file['file_path']);
            }
        }

        // Fetch folder name if any
        $folderName = 'General';
        if (!empty($file['folder_id'])) {
            $fRow = $this->app->db()->fetchOne("SELECT name FROM download_folders WHERE id = ? LIMIT 1", [$file['folder_id']]);
            if ($fRow && !empty($fRow['name'])) {
                $folderName = $fRow['name'];
            }
        }

        $this->app->view()->display('downloads/gateway.twig', [
            'file'            => $file,
            'is_unlocked'     => $isUnlocked,
            'file_available'  => is_file($file['file_path']),
            'file_sha256'     => $fileSha256,
            'folder_name'     => $folderName,
            'download_token'  => $downloadToken,
            'download_url'    => "/d/{$code}/get/{$downloadToken}",
            'error'           => (string) ($_SESSION['dl_error'] ?? ''),
            'success'         => (string) ($_SESSION['dl_success'] ?? ''),
            'csrf_token'      => $this->auth->generateCsrf(),
        ]);
        unset($_SESSION['dl_error'], $_SESSION['dl_success']);
    }

    /** POST /d/{code}/report — submit report for broken download link */
    public function publicReportBrokenLink(string $code): void
    {
        $file = $this->resolveFile($code);
        if ($file === null) {
            header('Location: /');
            exit;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['dl_error'] = Locale::t('ui.gateway.invalid_token_short');
            header("Location: /d/{$code}");
            exit;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        $details = trim((string) ($_POST['details'] ?? ''));
        
        // Resolve real client IP considering reverse proxy headers
        $clientIp = (string) (
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 
            $_SERVER['HTTP_X_REAL_IP'] ?? 
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        $userId = $this->auth->isLoggedIn() ? (int) $this->auth->userId() : null;

        if ($reason === '') {
            $_SESSION['dl_error'] = Locale::t('ui.gateway.report_err_reason');
            header("Location: /d/{$code}");
            exit;
        }

        try {
            $stmt = $this->app->db()->connection()->prepare(
                "INSERT INTO download_reports (file_id, user_id, short_code, reason, details, reporter_ip, status) VALUES (?, ?, ?, ?, ?, ?, 'open')"
            );
            $stmt->execute([
                $file['id'],
                $userId,
                $code,
                mb_substr($reason, 0, 50),
                mb_substr($details, 0, 1000),
                $clientIp
            ]);
            $_SESSION['dl_success'] = Locale::t('ui.gateway.report_success');
        } catch (\Throwable $e) {
            error_log("[DownloadCenter] Report submission error: " . $e->getMessage());
            $_SESSION['dl_error'] = 'An error occurred while saving your report. Please try again.';
        }

        header("Location: /d/{$code}");
        exit;
    }

    /** POST /d/{code}/unlock — unlock password protected file */
    public function publicUnlock(string $code): void
    {
        $file = $this->resolveFile($code);
        if ($file === null) {
            header('Location: /');
            exit;
        }

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['dl_error'] = Locale::t('ui.gateway.invalid_token_short');
            header("Location: /d/{$code}");
            exit;
        }

        $password = (string) ($_POST['password'] ?? '');

        if (!empty($file['password_hash'])) {
            if (password_verify($password, $file['password_hash'])) {
                $_SESSION["dl_unlocked_{$code}"] = true;
                header("Location: /d/{$code}");
                exit;
            } else {
                $_SESSION['dl_error'] = Locale::t('ui.gateway.incorrect_password');
                header("Location: /d/{$code}");
                exit;
            }
        }

        header("Location: /d/{$code}");
        exit;
    }

    /** GET /d/{code}/get and /d/{code}/get/{token} — stream file with token validation or permanent update exception */
    public function publicServeFile(string $code, string $token = ''): void
    {
        $file = $this->resolveFile($code);
        if ($file === null || !file_exists($file['file_path'])) {
            $this->renderDownloadError('File Unavailable', 'This file is no longer stored on the server. Ask the uploader to re-share it.', 410);
            return;
        }

        // Validate Security Token or Remote Update Key
        $headerUpdateToken = (string) ($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '');
        $queryToken        = (string) ($_GET['token'] ?? '');
        $effectiveToken    = $token !== '' ? $token : ($queryToken !== '' ? $queryToken : $headerUpdateToken);

        $isAuthorized = false;

        // 1. Check if token matches permanent remote update key (e.g. CLI updater, curl, script)
        if ($effectiveToken !== '' && $this->tokenService->isPermanentUpdateKey($effectiveToken)) {
            $isAuthorized = true;
        }

        // 2. Check if token is a valid time-limited signed token
        if (!$isAuthorized && $effectiveToken !== '') {
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if ($this->tokenService->validateToken($code, $effectiveToken, $clientIp)) {
                $isAuthorized = true;
            }
        }

        // 3. If token is invalid or missing, redirect user to the gateway page to obtain a fresh token
        if (!$isAuthorized) {
            $_SESSION['dl_error'] = Locale::t('ui.gateway.expired_or_invalid_token');
            header("Location: /d/{$code}");
            exit;
        }

        if (empty($file['is_active']) || (int) $file['is_active'] !== 1) {
            $this->renderDownloadError('Link Disabled', 'This download link has been deactivated.', 403);
            return;
        }

        if (!empty($file['password_hash']) && empty($_SESSION["dl_unlocked_{$code}"])) {
            header("Location: /d/{$code}");
            exit;
        }

        if (!empty($file['max_downloads']) && (int) $file['download_count'] >= (int) $file['max_downloads']) {
            $this->renderDownloadError('Limit Exceeded', 'Maximum download limit reached.', 410);
            return;
        }

        if (!empty($file['expires_at']) && strtotime($file['expires_at']) < time()) {
            $this->renderDownloadError('Expired', 'Link has expired.', 410);
            return;
        }

        $absPath  = (string) $file['file_path'];
        $fileSize = (int) filesize($absPath);

        if ($fileSize < 1) {
            $this->renderDownloadError('File Unavailable', 'The stored file is empty or unreadable.', 410);
            return;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');

        // Range support (single range) so interrupted downloads can resume.
        $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        $start = 0;
        $end   = $fileSize - 1;
        $isPartial = false;

        if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $fileSize - (int) $m[2]);
                $end   = $fileSize - 1;
            } elseif ($m[1] !== '') {
                $start = (int) $m[1];
                $end   = ($m[2] !== '') ? min((int) $m[2], $fileSize - 1) : $fileSize - 1;
            }

            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                return;
            }

            $isPartial = true;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
        }

        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        while (ob_get_level()) {
            ob_end_clean();
        }

        // Stream in bounded chunks (memory-safe for large files) and count the
        // download ONLY on a completed full transfer — an aborted or partial
        // Range request no longer burns a max_downloads slot.
        $in = @fopen($absPath, 'rb');
        if ($in === false) {
            http_response_code(500);
            return;
        }

        if ($start > 0) fseek($in, $start);

        $remaining = $length;
        $sent = 0;
        while ($remaining > 0 && ! feof($in) && ! connection_aborted()) {
            $buffer = fread($in, (int) min(262144, $remaining));
            if ($buffer === false || $buffer === '') break;
            echo $buffer;
            $remaining -= strlen($buffer);
            $sent += strlen($buffer);
        }
        fclose($in);

        $countable = (! $isPartial && $sent >= $length)
            || ($isPartial && $start === 0 && $end === $fileSize - 1 && $sent >= $length);

        if ($countable) {
            $db = $this->app->db()->connection();
            $inc = $db->prepare('UPDATE file_downloads SET download_count = download_count + 1 WHERE id = ?');
            $inc->execute([$file['id']]);
        }

        exit;
    }


    /** POST /admin/downloads/folders/create — create a new folder (root or child) */
    public function folderCreate(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $name     = trim((string) ($_POST['name'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
        $desc     = trim((string) ($_POST['description'] ?? ''));

        if ($name === '' || strlen($name) > 100) {
            $_SESSION['flash_error'] = 'Folder name is required (max 100 characters).';
            header("Location: {$ap}/downloads");
            exit;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');

        $db    = $this->app->db()->connection();
        $check = $db->prepare('SELECT COUNT(*) FROM download_folders WHERE slug = ?');
        $check->execute([$slug]);
        if ((int) $check->fetchColumn() > 0) {
            $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        if ($parentId !== null) {
            $pStmt = $db->prepare('SELECT id FROM download_folders WHERE id = ? LIMIT 1');
            $pStmt->execute([$parentId]);
            if (!$pStmt->fetch()) {
                $parentId = null;
            }
        }

        $ins = $db->prepare(
            'INSERT INTO download_folders (name, slug, parent_id, description) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$name, $slug, $parentId, $desc !== '' ? $desc : null]);

        $this->auditLogger->log('folder.create', null, "Created download folder '{$name}' (slug: {$slug})");
        $_SESSION['flash_success'] = "Folder \"{$name}\" created successfully.";
        header("Location: {$ap}/downloads/folder/{$slug}");
        exit;
    }

    /** POST /admin/downloads/folders/{id}/rename — rename an existing folder */
    public function folderRename(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $db    = $this->app->db()->connection();
        $fStmt = $db->prepare('SELECT * FROM download_folders WHERE id = ? LIMIT 1');
        $fStmt->execute([$id]);
        $folder = $fStmt->fetch();

        if (! $folder) {
            $_SESSION['flash_error'] = 'Folder not found.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));

        if ($name === '' || strlen($name) > 100) {
            $_SESSION['flash_error'] = 'Folder name is required (max 100 characters).';
            header("Location: {$ap}/downloads/folder/{$folder['slug']}");
            exit;
        }

        $upd = $db->prepare('UPDATE download_folders SET name = ?, description = ? WHERE id = ?');
        $upd->execute([$name, $desc !== '' ? $desc : null, $id]);

        $this->auditLogger->log('folder.rename', null, "Renamed folder id={$id} to '{$name}'");
        $_SESSION['flash_success'] = "Folder renamed to \"{$name}\".";
        header("Location: {$ap}/downloads/folder/{$folder['slug']}");
        exit;
    }

    /** POST /admin/downloads/folders/{id}/delete — delete folder, files become uncategorised */
    public function folderDelete(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $db    = $this->app->db()->connection();
        $fStmt = $db->prepare('SELECT * FROM download_folders WHERE id = ? LIMIT 1');
        $fStmt->execute([$id]);
        $folder = $fStmt->fetch();

        if (! $folder) {
            $_SESSION['flash_error'] = 'Folder not found.';
            header("Location: {$ap}/downloads");
            exit;
        }

        if ((int) $folder['is_system'] === 1) {
            $_SESSION['flash_error'] = 'System folders cannot be deleted.';
            header("Location: {$ap}/downloads/folder/{$folder['slug']}");
            exit;
        }

        $db->prepare('UPDATE download_folders SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
        $db->prepare('UPDATE file_downloads SET folder_id = NULL WHERE folder_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM download_folders WHERE id = ?')->execute([$id]);

        $this->auditLogger->log('folder.delete', null, "Deleted folder '{$folder['name']}' (id={$id})");
        $_SESSION['flash_success'] = "Folder \"{$folder['name']}\" deleted. Its files moved to Uncategorised.";
        header("Location: {$ap}/downloads");
        exit;
    }

    /** POST /admin/downloads/{id}/move — move a file to another folder */
    public function fileMove(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $db    = $this->app->db()->connection();
        $fStmt = $db->prepare('SELECT * FROM file_downloads WHERE id = ? LIMIT 1');
        $fStmt->execute([$id]);
        $file = $fStmt->fetch();

        if (! $file) {
            $_SESSION['flash_error'] = 'File not found.';
            header("Location: {$ap}/downloads");
            exit;
        }

        $targetFolderId = !empty($_POST['folder_id']) ? (int) $_POST['folder_id'] : null;
        $redirectSlug   = null;

        if ($targetFolderId !== null) {
            $tStmt = $db->prepare('SELECT slug FROM download_folders WHERE id = ? LIMIT 1');
            $tStmt->execute([$targetFolderId]);
            $tFolder = $tStmt->fetch();
            if (! $tFolder) {
                $targetFolderId = null;
            } else {
                $redirectSlug = $tFolder['slug'];
            }
        }

        $db->prepare('UPDATE file_downloads SET folder_id = ? WHERE id = ?')->execute([$targetFolderId, $id]);

        $this->auditLogger->log('file.move', null, "Moved file id={$id} to folder_id=" . ($targetFolderId ?? 'NULL'));
        $_SESSION['flash_success'] = 'File moved successfully.';
        header($redirectSlug ? "Location: {$ap}/downloads/folder/{$redirectSlug}" : "Location: {$ap}/downloads");
        exit;
    }

    /** Build a nested folder tree for sidebar rendering */
    private function getFolderTree(\PDO $db): array
    {
        $stmt = $db->query(
            'SELECT df.*, COUNT(fd.id) AS file_count
             FROM download_folders df
             LEFT JOIN file_downloads fd ON fd.folder_id = df.id
             GROUP BY df.id
             ORDER BY df.sort_order ASC, df.name ASC'
        );
        $all = $stmt->fetchAll();

        $roots    = [];
        $children = [];
        foreach ($all as $f) {
            if ($f['parent_id'] === null) {
                $roots[] = $f;
            } else {
                $children[(int) $f['parent_id']][] = $f;
            }
        }

        foreach ($roots as &$root) {
            $root['children'] = $children[(int) $root['id']] ?? [];
        }
        unset($root);

        return $roots;
    }

    private function resolveFile(string $code): ?array
    {
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT * FROM file_downloads WHERE short_code = ? LIMIT 1');
        $stmt->execute([$code]);
        $file = $stmt->fetch();
        if (!$file) return null;

        $rawPath = (string) $file['file_path'];
        if (!str_starts_with($rawPath, '/')) {
            $abs = $this->app->basePath($rawPath);
            if (file_exists($abs)) {
                $file['file_path'] = $abs;
            }
        }
        return $file;
    }

    private function renderDownloadError(string $title, string $message, int $code = 404): void
    {
        http_response_code($code);
        $this->app->view()->display('partials/error.html.twig', [
            'code'    => $code,
            'message' => "{$title}: {$message}",
        ]);
    }

    /** GET /downloads/guide and /admin/downloads/guide — remote update documentation and API guide */
    public function adminGuide(): void
    {
        if (! $this->auth->isLoggedIn()) {
            $_SESSION['flash_error'] = 'يرجى تسجيل الدخول للوصول إلى دليل التوثيق الأمني ومفاتيح التحديث.';
            header('Location: /login');
            exit;
        }

        $appUrl    = rtrim((string) $this->app->config('app.url', 'https://git.ysnapp.com'), '/');
        $ap        = $this->auth->adminPrefix();
        $isOwner   = $this->auth->isOwner();
        $updateKey = $this->tokenService->getUserUpdateKey($this->identityId());

        $this->app->view()->display('admin/downloads-guide.twig', [
            'app_url'      => $appUrl,
            'admin_prefix' => $ap,
            'is_owner'     => $isOwner,
            'update_key'   => $updateKey,
            'nav_counts'   => $isOwner ? $this->getNavCounts() : [],
            'csrf_token'   => $this->auth->generateCsrf(),
        ]);
    }

    /** GET /admin/downloads/reports — broken link reports management */
    public function adminReports(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();
        $appUrl = $this->app->config('app.url', '');

        $status = strtolower(trim((string) ($_GET['status'] ?? 'open')));
        if (!in_array($status, ['open', 'investigating', 'resolved', 'dismissed', 'all'], true)) {
            $status = 'open';
        }

        $db = $this->app->db()->connection();

        // Get status counts
        $counts = [
            'open'          => (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS c FROM download_reports WHERE status = 'open'")['c'] ?? 0),
            'investigating' => (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS c FROM download_reports WHERE status = 'investigating'")['c'] ?? 0),
            'resolved'      => (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS c FROM download_reports WHERE status = 'resolved'")['c'] ?? 0),
            'dismissed'     => (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS c FROM download_reports WHERE status = 'dismissed'")['c'] ?? 0),
            'all'           => (int) ($this->app->db()->fetchOne("SELECT COUNT(*) AS c FROM download_reports")['c'] ?? 0),
        ];

        $whereClause = ($status === 'all') ? '' : 'WHERE r.status = ?';
        $params = ($status === 'all') ? [] : [$status];

        $sql = "
            SELECT r.*, 
                   f.title AS file_title, f.original_name, f.file_path, f.is_active AS file_active,
                   u.username, u.email AS user_email, u.display_name
            FROM download_reports r
            LEFT JOIN file_downloads f ON r.file_id = f.id
            LEFT JOIN users u ON r.user_id = u.id
            {$whereClause}
            ORDER BY r.id DESC
            LIMIT 200
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reports = $stmt->fetchAll();

        $this->app->view()->display('admin/download-reports.twig', [
            'reports'      => $reports,
            'status'       => $status,
            'counts'       => $counts,
            'app_url'      => $appUrl,
            'admin_prefix' => $ap,
            'nav_counts'   => $this->getNavCounts(),
            'csrf_token'   => $this->auth->generateCsrf(),
            'flash_success'=> (string) ($_SESSION['flash_success'] ?? ''),
            'flash_error'  => (string) ($_SESSION['flash_error'] ?? ''),
        ]);
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /admin/downloads/reports/{id}/status — update report status */
    public function adminReportStatus(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = Locale::t('ui.gateway.invalid_token_short');
            header("Location: {$ap}/downloads/reports");
            exit;
        }

        $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));
        if (!in_array($newStatus, ['open', 'investigating', 'resolved', 'dismissed'], true)) {
            $_SESSION['flash_error'] = 'Invalid status specified.';
            header("Location: {$ap}/downloads/reports");
            exit;
        }

        try {
            $stmt = $this->app->db()->connection()->prepare("UPDATE download_reports SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $_SESSION['flash_success'] = Locale::t('adm.download_reports.status_updated');
        } catch (\Throwable $e) {
            error_log("[DownloadCenter] Update report status error: " . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to update status.';
        }

        $redirStatus = (string) ($_POST['return_status'] ?? 'open');
        header("Location: {$ap}/downloads/reports?status={$redirStatus}");
        exit;
    }

    /** POST /admin/downloads/reports/{id}/delete — delete report entry */
    public function adminReportDelete(int $id): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf((string) ($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = Locale::t('ui.gateway.invalid_token_short');
            header("Location: {$ap}/downloads/reports");
            exit;
        }

        try {
            $stmt = $this->app->db()->connection()->prepare("DELETE FROM download_reports WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_success'] = Locale::t('adm.download_reports.report_deleted');
        } catch (\Throwable $e) {
            error_log("[DownloadCenter] Delete report error: " . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to delete report.';
        }

        $redirStatus = (string) ($_POST['return_status'] ?? 'open');
        header("Location: {$ap}/downloads/reports?status={$redirStatus}");
        exit;
    }

    /** POST /admin/downloads/guide/regenerate-token — regenerate permanent update key */
    public function adminRegenerateUpdateKey(): void
    {
        $this->auth->requireOwner();
        $ap = $this->auth->adminPrefix();

        if (! $this->auth->validateCsrf()) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            header("Location: {$ap}/downloads/guide");
            exit;
        }

        $newKey = $this->tokenService->regeneratePermanentUpdateKey();
        $this->auditLogger->log('download.regenerate_key', null, "Regenerated permanent remote update key");

        $_SESSION['flash_success'] = 'تم تجديد مفتاح التحديث عن بعد بنجاح: ' . $newKey;
        header("Location: {$ap}/downloads/guide");
        exit;
    }
}