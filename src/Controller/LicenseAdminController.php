<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Service\AuthService;

/**
 * Controller for IP/HWID Multi-Device License Key Generation & Real-time Sync API.
 */
final class LicenseAdminController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Send strict anti-cache HTTP headers to prevent stale reverse-proxy caching */
    private static function sendNoCacheHeaders(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /** Generate a unique license key formatted as FF-XXXX-XXXX-XXXX */
    public static function generateLicenseKey(): string
    {
        $bytes = random_bytes(6);
        $hex = strtoupper(bin2hex($bytes));
        return 'FF-' . substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8, 4);
    }

    /** GET /{$ap}/licenses — List all licenses and summary stats. */
    public function index(): void
    {
        $db = $this->app->db();

        // Auto-expire licenses where expires_at < NOW()
        $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` < NOW()");

        // Fill missing license_keys for legacy rows
        $missingKeys = $db->fetchAll("SELECT `id` FROM `server_licenses` WHERE `license_key` IS NULL OR `license_key` = ''");
        foreach ($missingKeys as $row) {
            $key = self::generateLicenseKey();
            $db->execute("UPDATE `server_licenses` SET `license_key` = :k WHERE `id` = :id", ['k' => $key, 'id' => (int) $row['id']]);
        }

        $stats = [
            'total'   => (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM `server_licenses`')['c'] ?? 0),
            'active'  => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'active'")['c'] ?? 0),
            'expired' => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'expired'")['c'] ?? 0),
            'blocked' => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'blocked'")['c'] ?? 0),
        ];

        $licenses = $db->fetchAll('SELECT * FROM `server_licenses` ORDER BY `id` DESC');

        $now = time();
        foreach ($licenses as &$lic) {
            // Count actual active devices from license_devices
            $activeCount = (int) ($db->fetchOne(
                "SELECT COUNT(*) AS c FROM `license_devices` WHERE `license_id` = :id AND `status` = 'active'",
                ['id' => (int) $lic['id']]
            )['c'] ?? 0);

            $lic['active_activations'] = $activeCount;
            $lic['max_devices']        = (int) ($lic['max_devices'] ?? 1);

            // Fetch attached devices for admin view modal
            $lic['devices'] = $db->fetchAll(
                'SELECT * FROM `license_devices` WHERE `license_id` = :id ORDER BY `id` DESC',
                ['id' => (int) $lic['id']]
            );

            if ($lic['plan_type'] === 'lifetime' || empty($lic['expires_at'])) {
                $lic['days_remaining'] = '∞';
            } else {
                $expTs = strtotime((string) $lic['expires_at']);
                $diff = (int) ceil(($expTs - $now) / 86400);
                $lic['days_remaining'] = max(0, $diff);
            }
        }
        unset($lic);

        // Fetch all remote devices for the second table
        $allDevices = $db->fetchAll(
            'SELECT d.*, l.customer_name, l.plan_type 
             FROM `license_devices` d 
             LEFT JOIN `server_licenses` l ON d.license_id = l.id 
             ORDER BY d.id DESC'
        );

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        $secPrefix = (string) ($_SESSION['admin_sec_prefix'] ?? 'cp_default');

        $this->app->view()->display('admin/licenses/list.twig', [
            'stats'            => $stats,
            'licenses'         => $licenses,
            'all_devices'      => $allDevices,
            'flash_success'    => $flashSuccess,
            'flash_error'      => $flashError,
            'admin_sec_prefix' => $secPrefix,
        ]);
    }

    /** POST /{$ap}/licenses/create — Store a new server license. */
    public function create(): void
    {
        $secPrefix = (string) ($_SESSION['admin_sec_prefix'] ?? 'cp_default');
        $redirectUrl = "/{$secPrefix}/licenses";

        $ip         = trim((string) ($_POST['ip_address'] ?? ''));
        $name       = trim((string) ($_POST['customer_name'] ?? ''));
        $contact    = trim((string) ($_POST['customer_contact'] ?? ''));
        $plan       = trim((string) ($_POST['plan_type'] ?? 'monthly'));
        $maxDevices = max(1, (int) ($_POST['max_devices'] ?? 1));
        $notes      = trim((string) ($_POST['notes'] ?? ''));

        if ($ip === '' || $name === '') {
            $_SESSION['flash_error'] = 'Server IP Address and Customer Name are required.';
            header("Location: {$redirectUrl}");
            return;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $_SESSION['flash_error'] = "Invalid IP address format: '{$ip}'.";
            header("Location: {$redirectUrl}");
            return;
        }

        $existing = $this->app->db()->fetchOne('SELECT `id` FROM `server_licenses` WHERE `ip_address` = :ip LIMIT 1', ['ip' => $ip]);
        if ($existing !== false) {
            $_SESSION['flash_error'] = "License for IP '{$ip}' already exists.";
            header("Location: {$redirectUrl}");
            return;
        }

        $durationDays = match ($plan) {
            'trial'    => 3,
            'monthly'  => 30,
            '3months'  => 90,
            '6months'  => 180,
            'annual'   => 365,
            'lifetime' => 36500, // 100 years
            default    => 30,
        };

        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        $licenseKey = self::generateLicenseKey();

        $this->app->db()->execute(
            'INSERT INTO `server_licenses` (`license_key`, `ip_address`, `customer_name`, `customer_contact`, `plan_type`, `duration_days`, `max_devices`, `active_activations`, `status`, `notes`, `expires_at`, `created_at`)
             VALUES (:key, :ip, :name, :contact, :plan, :days, :max_dev, 0, "active", :notes, :expires, NOW())',
            [
                'key'     => $licenseKey,
                'ip'      => $ip,
                'name'    => $name,
                'contact' => $contact,
                'plan'    => $plan,
                'days'    => $durationDays,
                'max_dev' => $maxDevices,
                'notes'   => $notes !== '' ? $notes : null,
                'expires' => $expiresAt,
            ]
        );

        $_SESSION['flash_success'] = "License created successfully! Key: {$licenseKey} bound to IP: {$ip} (Max Devices: {$maxDevices}).";
        header("Location: {$redirectUrl}");
    }

    /** POST /{$ap}/licenses/{id}/renew — Renew / Extend a license. */
    public function renew(string $id): void
    {
        $secPrefix = (string) ($_SESSION['admin_sec_prefix'] ?? 'cp_default');
        $redirectUrl = "/{$secPrefix}/licenses";
        $licenseId   = (int) $id;

        $lic = $this->app->db()->fetchOne('SELECT * FROM `server_licenses` WHERE `id` = :id LIMIT 1', ['id' => $licenseId]);
        if ($lic === false) {
            $_SESSION['flash_error'] = 'License not found.';
            header("Location: {$redirectUrl}");
            return;
        }

        $addDays = (int) ($_POST['add_days'] ?? ($lic['duration_days'] ?? 30));
        if ($addDays <= 0) $addDays = 30;

        $baseTime = (time() < strtotime((string) ($lic['expires_at'] ?? 'now')))
            ? strtotime((string) $lic['expires_at'])
            : time();

        $newExpires = date('Y-m-d H:i:s', $baseTime + ($addDays * 86400));

        $this->app->db()->execute(
            'UPDATE `server_licenses` SET `expires_at` = :exp, `status` = "active" WHERE `id` = :id',
            ['exp' => $newExpires, 'id' => $licenseId]
        );

        $_SESSION['flash_success'] = "License '{$lic['license_key']}' for '{$lic['ip_address']}' renewed for +{$addDays} days until {$newExpires}.";
        header("Location: {$redirectUrl}");
    }

    /** POST /{$ap}/licenses/{id}/block — Toggle block/active status. */
    public function toggleBlock(string $id): void
    {
        $secPrefix = (string) ($_SESSION['admin_sec_prefix'] ?? 'cp_default');
        $redirectUrl = "/{$secPrefix}/licenses";
        $licenseId   = (int) $id;

        $lic = $this->app->db()->fetchOne('SELECT * FROM `server_licenses` WHERE `id` = :id LIMIT 1', ['id' => $licenseId]);
        if ($lic === false) {
            $_SESSION['flash_error'] = 'License not found.';
            header("Location: {$redirectUrl}");
            return;
        }

        $newStatus = ($lic['status'] === 'blocked') ? 'active' : 'blocked';

        $this->app->db()->execute(
            'UPDATE `server_licenses` SET `status` = :s WHERE `id` = :id',
            ['s' => $newStatus, 'id' => $licenseId]
        );

        $msg = ($newStatus === 'blocked') ? "Server IP '{$lic['ip_address']}' has been BLOCKED." : "Server IP '{$lic['ip_address']}' is now ACTIVE.";
        $_SESSION['flash_success'] = $msg;
        header("Location: {$redirectUrl}");
    }

    /** POST /{$ap}/licenses/{id}/delete — Remove a license record. */
    public function delete(string $id): void
    {
        $secPrefix = (string) ($_SESSION['admin_sec_prefix'] ?? 'cp_default');
        $redirectUrl = "/{$secPrefix}/licenses";
        $licenseId   = (int) $id;

        $this->app->db()->execute('DELETE FROM `server_licenses` WHERE `id` = :id', ['id' => $licenseId]);
        $this->app->db()->execute('DELETE FROM `license_devices` WHERE `license_id` = :id', ['id' => $licenseId]);

        $_SESSION['flash_success'] = 'License record and attached devices deleted successfully.';
        header("Location: {$redirectUrl}");
    }

    /** POST /api/v1/admin/license/generate — Admin REST API to generate a new license */
    public function apiGenerate(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        // Check Admin PAT Auth
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = $m[1];
        }
        if ($token === '') {
            $token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
        }

        $authService = new AuthService($this->app->db());
        $identity = $authService->authenticateToken($token);

        if ($identity === null || ! in_array($identity['scope'] ?? '', ['write', 'repo:write', 'all', 'admin'], true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized or insufficient write scope.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $input        = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $ip           = trim((string) ($input['ip'] ?? ($input['ip_address'] ?? '')));
        $customer     = trim((string) ($input['customer'] ?? ($input['customer_name'] ?? '')));
        $plan         = trim((string) ($input['plan'] ?? ($input['plan_type'] ?? 'monthly')));
        $maxDevices   = max(1, (int) ($input['max_devices'] ?? 1));
        $daysOverride = isset($input['days']) ? (int) $input['days'] : 0;

        if ($ip === '' || $customer === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ip and customer parameters are required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $existing = $this->app->db()->fetchOne('SELECT `id` FROM `server_licenses` WHERE `ip_address` = :ip LIMIT 1', ['ip' => $ip]);
        if ($existing !== false) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "License for IP '{$ip}' already exists."], JSON_UNESCAPED_UNICODE);
            return;
        }

        $durationDays = $daysOverride > 0 ? $daysOverride : match ($plan) {
            'trial'    => 3,
            'monthly'  => 30,
            '3months'  => 90,
            '6months'  => 180,
            'annual'   => 365,
            'lifetime' => 36500,
            default    => 30,
        };

        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        $licenseKey = self::generateLicenseKey();

        $this->app->db()->execute(
            'INSERT INTO `server_licenses` (`license_key`, `ip_address`, `customer_name`, `plan_type`, `duration_days`, `max_devices`, `active_activations`, `status`, `expires_at`, `created_at`)
             VALUES (:key, :ip, :customer, :plan, :days, :max_dev, 0, "active", :expires, NOW())',
            [
                'key'      => $licenseKey,
                'ip'       => $ip,
                'customer' => $customer,
                'plan'     => $plan,
                'days'     => $durationDays,
                'max_dev'  => $maxDevices,
                'expires'  => $expiresAt,
            ]
        );

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'License generated successfully',
            'data'    => [
                'license_key'    => $licenseKey,
                'ip'             => $ip,
                'customer'       => $customer,
                'plan'           => $plan,
                'max_devices'    => $maxDevices,
                'expires_at'     => $expiresAt,
                'days_remaining' => $durationDays,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** POST /api/v1/license/activate — HWID & Multi-Device Key Activation */
    public function apiActivate(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $input      = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $licenseKey = trim((string) ($input['license_key'] ?? ($input['key'] ?? '')));
        $hwid       = trim((string) ($input['hwid'] ?? ($input['machine_id'] ?? '')));
        $hostname   = trim((string) ($input['hostname'] ?? ''));

        // Client IP
        $clientIp = trim((string) ($input['ip'] ?? ''));
        if ($clientIp === '') {
            $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '';

            if (str_contains($clientIp, ',')) {
                $clientIp = trim(explode(',', $clientIp)[0]);
            }
        }

        if ($licenseKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'license_key parameter is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db  = $this->app->db();
        $lic = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `license_key` = :key LIMIT 1', ['key' => $licenseKey]);

        if ($lic === false) {
            http_response_code(404);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_NOT_FOUND',
                'error'      => 'License record not found or has been deleted.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        if (in_array($lic['status'], ['blocked', 'revoked', 'suspended'], true)) {
            http_response_code(403);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_REVOKED',
                'error'      => 'This license has been revoked by the administrator.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        // Auto-check expiration
        $now = time();
        $expiresTs = ! empty($lic['expires_at']) ? strtotime((string) $lic['expires_at']) : null;
        if ($lic['plan_type'] !== 'lifetime' && $expiresTs !== null && $expiresTs < $now) {
            $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `id` = :id", ['id' => (int) $lic['id']]);
            http_response_code(403);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_EXPIRED',
                'error'      => 'License subscription has expired.',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        $maxDevices = max(1, (int) ($lic['max_devices'] ?? 1));
        $deviceHwid = $hwid !== '' ? $hwid : ('IP-' . $clientIp);

        // Check if (license_key, device_hwid) is already registered
        $device = $db->fetchOne(
            'SELECT * FROM `license_devices` WHERE `license_key` = :key AND `device_hwid` = :hwid LIMIT 1',
            ['key' => $licenseKey, 'hwid' => $deviceHwid]
        );

        if ($device !== false) {
            if ($device['status'] === 'revoked') {
                http_response_code(403);
                echo json_encode([
                    'success'    => false,
                    'licensed'   => false,
                    'valid'      => false,
                    'action'     => 'kill',
                    'error_code' => 'ERR_LIC_DEVICE_REVOKED',
                    'error'      => 'Device authorization has been revoked by license administrator.',
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }

            // Already active device -> Update last_seen timestamp
            $db->execute(
                'UPDATE `license_devices` SET `server_ip` = :ip, `hostname` = :host, `status` = "active", `last_seen` = NOW() WHERE `id` = :id',
                ['ip' => $clientIp, 'host' => $hostname, 'id' => (int) $device['id']]
            );

            $activeCount = (int) ($db->fetchOne(
                "SELECT COUNT(*) AS c FROM `license_devices` WHERE `license_key` = :key AND `status` = 'active'",
                ['key' => $licenseKey]
            )['c'] ?? 1);

            $db->execute('UPDATE `server_licenses` SET `active_activations` = :c, `last_seen_at` = NOW() WHERE `id` = :id', ['c' => $activeCount, 'id' => (int) $lic['id']]);

            http_response_code(200);
            echo json_encode([
                'success'     => true,
                'licensed'    => true,
                'valid'       => true,
                'action'      => 'allow',
                'message'     => 'Device license active and verified.',
                'slots_used'  => $activeCount,
                'slots_total' => $maxDevices,
                'data'        => [
                    'license_key' => $licenseKey,
                    'hwid'        => $deviceHwid,
                    'ip'          => $clientIp,
                    'customer'    => $lic['customer_name'],
                    'plan'        => $lic['plan_type'],
                    'expires_at'  => $lic['expires_at'],
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        // New Device Activation Attempt -> Count active devices
        $currentActivations = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM `license_devices` WHERE `license_key` = :key AND `status` = 'active'",
            ['key' => $licenseKey]
        )['c'] ?? 0);

        if ($currentActivations >= $maxDevices) {
            http_response_code(403);
            echo json_encode([
                'success'             => false,
                'licensed'            => false,
                'valid'               => false,
                'action'              => 'kill',
                'error_code'          => 'ERR_LIC_DEVICE_LIMIT_REACHED',
                'error'               => 'Activation limit reached. All available slots are occupied.',
                'max_devices'         => $maxDevices,
                'current_activations' => $currentActivations,
                'license_key'         => $licenseKey,
                'server_ip'           => $clientIp,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        // Available slot found -> Register new device
        $db->execute(
            'INSERT INTO `license_devices` (`license_id`, `license_key`, `device_hwid`, `server_ip`, `hostname`, `status`, `activated_at`, `last_seen`)
             VALUES (:lic_id, :key, :hwid, :ip, :host, "active", NOW(), NOW())',
            [
                'lic_id' => (int) $lic['id'],
                'key'    => $licenseKey,
                'hwid'   => $deviceHwid,
                'ip'     => $clientIp,
                'host'   => $hostname,
            ]
        );

        $newCount = $currentActivations + 1;
        $db->execute('UPDATE `server_licenses` SET `active_activations` = :c, `last_seen_at` = NOW() WHERE `id` = :id', ['c' => $newCount, 'id' => (int) $lic['id']]);

        http_response_code(200);
        echo json_encode([
            'success'     => true,
            'licensed'    => true,
            'valid'       => true,
            'action'      => 'allow',
            'message'     => 'New device activated successfully.',
            'slots_used'  => $newCount,
            'slots_total' => $maxDevices,
            'data'        => [
                'license_key' => $licenseKey,
                'hwid'        => $deviceHwid,
                'ip'          => $clientIp,
                'customer'    => $lic['customer_name'],
                'plan'        => $lic['plan_type'],
                'expires_at'  => $lic['expires_at'],
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** GET/POST /api/v1/license/heartbeat & /api/v1/license/sync */
    public function apiHeartbeat(): void
    {
        $this->apiCheck();
    }

    public function apiSync(): void
    {
        $this->apiCheck();
    }

    /** GET/POST /api/v1/license/check — Strict Instant Revocation & Heartbeat Check */
    public function apiCheck(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $input      = json_decode((string) file_get_contents('php://input'), true) ?? $_REQUEST;
        $licenseKey = trim((string) ($input['license_key'] ?? ($input['key'] ?? '')));
        $hwid       = trim((string) ($input['hwid'] ?? ($input['machine_id'] ?? '')));

        // Resolve client IP
        $clientIp = trim((string) ($input['ip'] ?? ''));
        if ($clientIp === '') {
            $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '';

            if (str_contains($clientIp, ',')) {
                $clientIp = trim(explode(',', $clientIp)[0]);
            }
        }

        $db = $this->app->db();

        // Strict real-time lookup
        if ($licenseKey === '') {
            $lic = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `ip_address` = :ip LIMIT 1', ['ip' => $clientIp]);
        } else {
            $lic = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `license_key` = :key LIMIT 1', ['key' => $licenseKey]);
        }

        // DELETED / NOT FOUND RECORD -> Return HTTP 404 & action: kill
        if ($lic === false) {
            http_response_code(404);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_NOT_FOUND',
                'error'      => 'License record not found or has been deleted.',
                'status'     => 'not_found',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        // REVOKED / BLOCKED / SUSPENDED RECORD -> Return HTTP 403 & action: kill
        if (in_array($lic['status'], ['blocked', 'revoked', 'suspended'], true)) {
            http_response_code(403);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_REVOKED',
                'error'      => 'This license has been revoked by the administrator.',
                'status'     => 'revoked',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        $now = time();
        $expiresTs = ! empty($lic['expires_at']) ? strtotime((string) $lic['expires_at']) : null;
        if ($lic['plan_type'] !== 'lifetime' && $expiresTs !== null && $expiresTs < $now) {
            $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `id` = :id", ['id' => (int) $lic['id']]);
            http_response_code(403);
            echo json_encode([
                'success'    => false,
                'licensed'   => false,
                'valid'      => false,
                'action'     => 'kill',
                'error_code' => 'ERR_LIC_EXPIRED',
                'error'      => 'License subscription has expired.',
                'status'     => 'expired',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        // Verify device HWID status if provided
        $deviceHwid = $hwid !== '' ? $hwid : ('IP-' . $clientIp);
        $device = $db->fetchOne(
            'SELECT * FROM `license_devices` WHERE `license_key` = :key AND `device_hwid` = :hwid LIMIT 1',
            ['key' => $lic['license_key'], 'hwid' => $deviceHwid]
        );

        if ($device !== false) {
            if ($device['status'] === 'revoked') {
                http_response_code(403);
                echo json_encode([
                    'success'    => false,
                    'licensed'   => false,
                    'valid'      => false,
                    'action'     => 'kill',
                    'error_code' => 'ERR_LIC_DEVICE_REVOKED',
                    'error'      => 'Device authorization has been revoked by license administrator.',
                    'status'     => 'revoked',
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return;
            }

            $db->execute('UPDATE `license_devices` SET `last_seen` = NOW() WHERE `id` = :id', ['id' => (int) $device['id']]);
        }

        $db->execute('UPDATE `server_licenses` SET `last_seen_at` = NOW() WHERE `id` = :id', ['id' => (int) $lic['id']]);

        $daysRemaining = ($lic['plan_type'] === 'lifetime' || $expiresTs === null)
            ? 'lifetime'
            : (int) ceil(($expiresTs - $now) / 86400);

        http_response_code(200);
        echo json_encode([
            'success'  => true,
            'licensed' => true,
            'valid'    => true,
            'action'   => 'allow',
            'data'     => [
                'license_key'         => $lic['license_key'],
                'ip'                  => $clientIp,
                'customer'            => $lic['customer_name'],
                'plan'                => $lic['plan_type'],
                'status'              => 'active',
                'max_devices'         => (int) ($lic['max_devices'] ?? 1),
                'current_activations' => (int) ($lic['active_activations'] ?? 0),
                'expires_at'          => $lic['expires_at'],
                'days_remaining'      => $daysRemaining,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** POST /api/v1/admin/delete — Permanently purge a license & bound devices */
    public function apiAdminDelete(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $input      = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $licenseKey = trim((string) ($input['license_key'] ?? ($input['key'] ?? '')));
        $ip         = trim((string) ($input['ip'] ?? ($input['ip_address'] ?? '')));

        if ($licenseKey === '' && $ip === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'license_key or ip parameter is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = $this->app->db();

        if ($licenseKey !== '') {
            $db->execute('DELETE FROM `server_licenses` WHERE `license_key` = :key', ['key' => $licenseKey]);
            $db->execute('DELETE FROM `license_devices` WHERE `license_key` = :key', ['key' => $licenseKey]);
        } else {
            $lic = $db->fetchOne('SELECT `license_key` FROM `server_licenses` WHERE `ip_address` = :ip LIMIT 1', ['ip' => $ip]);
            if ($lic !== false) {
                $db->execute('DELETE FROM `license_devices` WHERE `license_key` = :key', ['key' => $lic['license_key']]);
            }
            $db->execute('DELETE FROM `server_licenses` WHERE `ip_address` = :ip', ['ip' => $ip]);
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'License purged. Clients will be blocked immediately.',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** POST /api/v1/admin/reset-ip — Reset IP & HWID bindings to allow re-activation */
    public function apiAdminResetIp(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $input      = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $licenseKey = trim((string) ($input['license_key'] ?? ($input['key'] ?? '')));

        if ($licenseKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'license_key parameter is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db  = $this->app->db();
        $lic = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `license_key` = :key LIMIT 1', ['key' => $licenseKey]);

        if ($lic === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'License key not found.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Reset IP and active activations count, revoke attached devices
        $db->execute('UPDATE `server_licenses` SET `ip_address` = "0.0.0.0", `active_activations` = 0 WHERE `id` = :id', ['id' => (int) $lic['id']]);
        $db->execute('UPDATE `license_devices` SET `status` = "revoked" WHERE `license_key` = :key', ['key' => $licenseKey]);

        http_response_code(200);
        echo json_encode([
            'success'     => true,
            'message'     => 'License IP & HWID bindings reset successfully. Key can now be re-activated on a new server.',
            'license_key' => $licenseKey,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** GET /api/v1/admin/license/devices — List all devices bound to a license key */
    public function apiListDevices(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $licenseKey = trim((string) ($_GET['license_key'] ?? ($_GET['key'] ?? '')));
        if ($licenseKey === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'license_key parameter is required.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db      = $this->app->db();
        $devices = $db->fetchAll(
            'SELECT * FROM `license_devices` WHERE `license_key` = :key ORDER BY `id` DESC',
            ['key' => $licenseKey]
        );

        echo json_encode([
            'success'     => true,
            'license_key' => $licenseKey,
            'count'       => count($devices),
            'devices'     => $devices,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** POST /api/v1/admin/license/device/revoke — Revoke/Unlink a specific device HWID */
    public function apiRevokeDevice(): void
    {
        self::sendNoCacheHeaders();
        header('Content-Type: application/json; charset=utf-8');

        $input      = json_decode((string) file_get_contents('php://input'), true) ?? $_POST;
        $licenseKey = trim((string) ($input['license_key'] ?? ($input['key'] ?? '')));
        $hwid       = trim((string) ($input['hwid'] ?? ($input['device_hwid'] ?? '')));
        $deviceId   = (int) ($input['device_id'] ?? 0);

        $db = $this->app->db();

        if ($deviceId > 0) {
            $device = $db->fetchOne('SELECT * FROM `license_devices` WHERE `id` = :id LIMIT 1', ['id' => $deviceId]);
        } else {
            $device = $db->fetchOne(
                'SELECT * FROM `license_devices` WHERE `license_key` = :key AND `device_hwid` = :hwid LIMIT 1',
                ['key' => $licenseKey, 'hwid' => $hwid]
            );
        }

        if ($device === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Device record not found.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Update device status to revoked
        $db->execute('UPDATE `license_devices` SET `status` = "revoked" WHERE `id` = :id', ['id' => (int) $device['id']]);

        // Decrement active_activations on server_licenses
        $activeCount = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM `license_devices` WHERE `license_key` = :key AND `status` = 'active'",
            ['key' => $device['license_key']]
        )['c'] ?? 0);

        $db->execute('UPDATE `server_licenses` SET `active_activations` = :c WHERE `license_key` = :key', [
            'c'   => $activeCount,
            'key' => $device['license_key']
        ]);

        http_response_code(200);
        echo json_encode([
            'success'             => true,
            'message'             => 'Device revoked successfully. Activation slot freed.',
            'license_key'         => $device['license_key'],
            'device_hwid'         => $device['device_hwid'],
            'active_activations' => $activeCount,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
