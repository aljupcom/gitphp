<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\DownloadBridge;

final class DownloadApiController
{
    private App $app;
    private Auth $auth;
    private DownloadBridge $bridge;

    public function __construct(App $app)
    {
        $this->app    = $app;
        $this->auth   = new Auth($app);
        $this->bridge = new DownloadBridge($app);
    }

    /** POST /api/downloads/generate — JSON endpoint to generate instant short link */
    public function generate(): void
    {
        header('Content-Type: application/json');

        if (!$this->auth->validateCsrf()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
            return;
        }

        $type         = trim((string) ($_POST['type'] ?? ''));
        $slug         = trim((string) ($_POST['slug'] ?? ''));
        $ref          = trim((string) ($_POST['ref'] ?? 'HEAD'));
        $path         = trim((string) ($_POST['path'] ?? ''));
        $password     = trim((string) ($_POST['password'] ?? ''));
        $expiryDays   = !empty($_POST['expiry_days']) ? max(1, (int) $_POST['expiry_days']) : null;
        $maxDownloads = !empty($_POST['max_downloads']) ? max(1, (int) $_POST['max_downloads']) : null;

        // Check Repo Access Permissions
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT id, visibility FROM repositories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $repo = $stmt->fetch();

        if (!$repo) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "Repository '{$slug}' not found."]);
            return;
        }

        if (!$this->auth->canViewRepo((int) $repo['id'], (string) $repo['visibility'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Permission denied to access this repository.']);
            return;
        }

        $userId = $this->auth->userId();

        try {
            if ($type === 'repo_archive') {
                $result = $this->bridge->createRepoArchiveLink($slug, $ref, $password ?: null, $expiryDays, $maxDownloads, $userId);
            } elseif ($type === 'blob') {
                $result = $this->bridge->createBlobDownloadLink($slug, $ref, $path, $password ?: null, $expiryDays, $maxDownloads, $userId);
            } else {
                throw new \InvalidArgumentException('Invalid download type specified.');
            }

            echo json_encode(['ok' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
