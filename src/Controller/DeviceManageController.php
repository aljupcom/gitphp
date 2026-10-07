<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Service\DeviceDetector;

final class DeviceManageController
{
    private App $app;
    private Auth $auth;

    public function __construct(App $app)
    {
        $this->app  = $app;
        $this->auth = new Auth($app);
    }

    /** GET /{$ap}/devices */
        /** Delegates to the shared NavCounts service (fixes the phantom `issues` table query). */
    private function getNavCounts(): array
    {
        return \App\Service\NavCounts::get($this->app);
    }

    public function index(): void
    {
        $this->auth->requireOwner();

        $meta = DeviceDetector::loadMeta();
        $updateCheck = DeviceDetector::checkUpdateAvailable();
        $appleCatalog = DeviceDetector::loadAppleCatalog();

        // Fetch real logged-in users and their resolved hardware sessions
        $ownerName = $this->auth->getOwnerUsername();
        $rows = $this->app->db()->fetchAll(
            'SELECT 
                s.id, s.user_id, s.token,
                COALESCE(u.username, :owner) AS username,
                COALESCE(u.display_name, u.username, :owner_title) AS display_name,
                u.email,
                s.ip_address, s.user_agent,
                s.device_brand, s.device_model, s.device_code, s.device_type,
                s.os_name, s.os_version, s.browser_name, s.browser_version,
                s.created_at, s.last_activity
             FROM user_sessions s
             LEFT JOIN users u ON s.user_id = u.id
             ORDER BY s.last_activity DESC
             LIMIT 100',
            ['owner' => $ownerName, 'owner_title' => 'Site Owner']
        );

        $userDevices = [];
        foreach ($rows as $row) {
            $brand = (string) ($row['device_brand'] ?? '');
            $model = (string) ($row['device_model'] ?? '');
            $code  = (string) ($row['device_code'] ?? '');
            $type  = (string) ($row['device_type'] ?? 'desktop');
            $os    = (string) ($row['os_name'] ?? '');
            $osVer = (string) ($row['os_version'] ?? '');
            $br    = (string) ($row['browser_name'] ?? '');
            $brVer = (string) ($row['browser_version'] ?? '');

            // Fallback detection if session row was created prior to hardware detector migration
            if ($model === '' && !empty($row['user_agent'])) {
                $detected = DeviceDetector::detect((string) $row['user_agent']);
                $brand = $detected['brand'];
                $model = $detected['model'];
                $code  = $detected['code'];
                $type  = $detected['type'];
                $os    = $detected['os_name'];
                $osVer = $detected['os_version'];
                $br    = $detected['browser_name'];
                $brVer = $detected['browser_version'];
            }

            $userDevices[] = [
                'id'              => (int) $row['id'],
                'user_id'         => (int) $row['user_id'],
                'username'        => (string) $row['username'],
                'display_name'    => (string) $row['display_name'],
                'email'           => (string) ($row['email'] ?? ''),
                'ip_address'      => (string) ($row['ip_address'] ?? '127.0.0.1'),
                'device_brand'    => $brand ?: 'Generic',
                'device_model'    => $model ?: 'Personal Device',
                'device_code'     => $code ?: 'N/A',
                'device_type'     => $type ?: 'desktop',
                'os_name'         => $os ?: 'Unknown OS',
                'os_version'      => $osVer,
                'browser_name'    => $br ?: 'Web Browser',
                'browser_version' => $brVer,
                'created_at'      => (string) $row['created_at'],
                'last_activity'   => (string) $row['last_activity'],
            ];
        }

        $this->app->view()->display('admin/devices.twig', [
            'csrf_token'    => $this->auth->generateCsrf(),
            'meta'          => $meta,
            'update_check'  => $updateCheck,
            'apple_count'   => count($appleCatalog),
            'user_devices'  => $userDevices,
            'nav_counts'    => $this->getNavCounts(),
            'flash_success' => $_SESSION['flash_success'] ?? null,
            'flash_error'   => $_SESSION['flash_error'] ?? null,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    /** POST /{$ap}/devices/update */
    public function update(): void
    {
        $this->auth->requireOwner();

        if (! $this->auth->validateCsrf()) {
            if ($this->isJson()) {
                $this->json(['success' => false, 'message' => 'Invalid security token.'], 403);
            }
            $_SESSION['flash_error'] = 'Invalid security token.';
            header('Location: ' . $this->adminPrefix() . '/devices');
            exit;
        }

        $res = DeviceDetector::syncGooglePlayCatalog();

        if ($this->isJson()) {
            $this->json($res, $res['success'] ? 200 : 500);
        }

        if ($res['success']) {
            $_SESSION['flash_success'] = $res['message'] . " (completed in {$res['duration_seconds']}s)";
        } else {
            $_SESSION['flash_error'] = $res['message'];
        }

        header('Location: ' . $this->adminPrefix() . '/devices');
        exit;
    }

    /** GET /{$ap}/devices/check */
    public function check(): void
    {
        $this->auth->requireOwner();
        $check = DeviceDetector::checkUpdateAvailable();
        $this->json($check);
    }

    /** POST /{$ap}/devices/lookup */
    public function lookup(): void
    {
        $this->auth->requireOwner();
        $query = trim((string) ($_POST['query'] ?? ''));

        if ($query === '') {
            $this->json(['success' => false, 'message' => 'Query is empty.']);
        }

        $result = DeviceDetector::detect(null, ['model' => $query]);

        $this->json([
            'success' => true,
            'query'   => $query,
            'result'  => $result,
        ]);
    }

    private function isJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xReq   = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xReq) === 'xmlhttprequest';
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    private function adminPrefix(): string
    {
        $hash = substr(hash('sha256', session_id() . 'gitphp_admin_sec_2026'), 0, 12);
        return "/cp_{$hash}";
    }
}
