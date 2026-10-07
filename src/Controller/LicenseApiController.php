<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Service\ApiTokenService;

/**
 * RESTful API Suite for License Management, HWID Binding, Quotas, and Analytics.
 */
final class LicenseApiController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Send anti-cache headers and JSON response */
    private function jsonResponse(array $payload, int $statusCode = 200): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /** Standard Success Response */
    private function success(mixed $data = null, string $message = 'Operation executed successfully.', int $statusCode = 200): void
    {
        $response = ['success' => true];
        if ($data !== null) {
            $response['data'] = $data;
        }
        $response['message'] = $message;
        $this->jsonResponse($response, $statusCode);
    }

    /** Standard Error Response */
    private function error(string $errorCode, string $message, int $statusCode = 400): void
    {
        $this->jsonResponse([
            'success'    => false,
            'error'      => $errorCode,
            'message'    => $message,
            'status'     => $statusCode,
        ], $statusCode);
    }

    /** Authenticate Admin Bearer Token */
    private function authenticate(): bool
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
            $this->error('ERR_UNAUTHORIZED', 'Missing Bearer token authorization header.', 401);
            return false;
        }

        try {
            $tokenService = new ApiTokenService($this->app);
            $identity = $tokenService->authenticate($token);
            if ($identity !== null) {
                return true;
            }
        } catch (\Throwable) {
            // Token auth failed
        }

        $this->error('ERR_UNAUTHORIZED', 'Invalid or expired API token.', 401);
        return false;
    }

    /** Parse JSON Request Body */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Generate FF-XXXX-XXXX-XXXX key */
    private function generateLicenseKey(): string
    {
        $bytes = random_bytes(6);
        $hex = strtoupper(bin2hex($bytes));
        return 'FF-' . substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8, 4);
    }

    /** Find license record by ID or Key */
    private function findLicenseRecord(string $id_or_key): ?array
    {
        $db = $this->app->db();
        if (is_numeric($id_or_key)) {
            $row = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `id` = :id LIMIT 1', ['id' => (int) $id_or_key]);
        } else {
            $row = $db->fetchOne('SELECT * FROM `server_licenses` WHERE `license_key` = :k LIMIT 1', ['k' => $id_or_key]);
        }

        if (! $row) return null;

        // Auto-expire check
        if (($row['status'] ?? '') === 'active' && ! empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
            $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `id` = :id", ['id' => (int)$row['id']]);
            $row['status'] = 'expired';
        }

        return $row;
    }

    /** Helper to format license object */
    private function formatLicenseObject(array $lic): array
    {
        $expiresAt = $lic['expires_at'] ?? null;
        $daysRemaining = '∞';
        if (! empty($expiresAt)) {
            $remSeconds = strtotime((string) $expiresAt) - time();
            $daysRemaining = max(0, (int) ceil($remSeconds / 86400));
        }

        return [
            'id'                 => (int) $lic['id'],
            'license_key'        => (string) $lic['license_key'],
            'customer_name'      => (string) ($lic['customer_name'] ?? ''),
            'customer_contact'   => (string) ($lic['customer_contact'] ?? ''),
            'plan_type'          => (string) ($lic['plan_type'] ?? 'monthly'),
            'duration_days'      => (int) ($lic['duration_days'] ?? 30),
            'max_devices'        => (int) ($lic['max_devices'] ?? 1),
            'active_activations' => (int) ($lic['active_activations'] ?? 0),
            'bound_ip'           => (string) ($lic['ip_address'] ?? '0.0.0.0'),
            'bound_domain'       => (string) ($lic['bound_domain'] ?? ''),
            'status'             => (string) ($lic['status'] ?? 'active'),
            'days_remaining'     => $daysRemaining,
            'expires_at'         => $lic['expires_at'] ?? null,
            'created_at'         => $lic['created_at'] ?? null,
            'last_seen_at'       => $lic['last_seen_at'] ?? null,
            'notes'              => (string) ($lic['notes'] ?? ''),
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // API ENDPOINTS
    // ────────────────────────────────────────────────────────────────────────

    /** POST /api/v1/licenses — Create License */
    public function create(): void
    {
        if (! $this->authenticate()) return;

        $input = $this->getJsonInput();

        $customerName   = trim((string) ($input['customer_name'] ?? ''));
        $planType       = strtolower(trim((string) ($input['plan_type'] ?? 'monthly')));
        $durationDays   = max(1, (int) ($input['duration_days'] ?? 30));
        $maxDevices     = max(1, (int) ($input['max_devices'] ?? 1));
        $boundIp        = trim((string) ($input['bound_ip'] ?? '0.0.0.0'));
        $boundDomain    = trim((string) ($input['bound_domain'] ?? ''));
        $customKey      = trim((string) ($input['custom_key'] ?? ''));
        $notes          = trim((string) ($input['notes'] ?? ''));
        $customerContact= trim((string) ($input['customer_contact'] ?? ''));

        if ($customerName === '') {
            $this->error('ERR_INVALID_INPUT', 'customer_name field is required.', 400);
            return;
        }

        $validPlans = ['trial', 'monthly', 'quarterly', 'yearly', 'annual', 'lifetime'];
        if (! in_array($planType, $validPlans, true)) {
            $this->error('ERR_INVALID_PLAN', 'Invalid plan_type. Must be one of: ' . implode(', ', $validPlans), 400);
            return;
        }

        $db = $this->app->db();

        $licenseKey = $customKey !== '' ? $customKey : $this->generateLicenseKey();

        // Ensure key uniqueness
        $exists = $db->fetchOne('SELECT `id` FROM `server_licenses` WHERE `license_key` = :k LIMIT 1', ['k' => $licenseKey]);
        if ($exists) {
            $this->error('ERR_LIC_EXISTS', 'License key already exists.', 400);
            return;
        }

        // Calculate expires_at
        $expiresAt = null;
        if ($planType === 'lifetime') {
            $durationDays = 0;
            $expiresAt = null;
        } else {
            if ($planType === 'trial') $durationDays = 3;
            elseif ($planType === 'monthly') $durationDays = 30;
            elseif ($planType === 'quarterly') $durationDays = 90;
            elseif (in_array($planType, ['yearly', 'annual'], true)) $durationDays = 365;

            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
        }

        $db->execute(
            'INSERT INTO `server_licenses` 
             (`license_key`, `ip_address`, `bound_domain`, `customer_name`, `customer_contact`, `plan_type`, `duration_days`, `max_devices`, `active_activations`, `status`, `notes`, `expires_at`, `created_at`) 
             VALUES (:key, :ip, :domain, :customer, :contact, :plan, :days, :max_dev, 0, "active", :notes, :exp, NOW())',
            [
                'key'      => $licenseKey,
                'ip'       => $boundIp !== '' ? $boundIp : '0.0.0.0',
                'domain'   => $boundDomain,
                'customer' => $customerName,
                'contact'  => $customerContact,
                'plan'     => $planType,
                'days'     => $durationDays,
                'max_dev'  => $maxDevices,
                'notes'    => $notes,
                'exp'      => $expiresAt,
            ]
        );

        $newId = (int) $db->lastInsertId();
        $licRecord = $this->findLicenseRecord((string) $newId);

        $this->success($this->formatLicenseObject($licRecord), 'License key created successfully.', 201);
    }

    /** GET /api/v1/licenses — List & Search Licenses */
    public function list(): void
    {
        if (! $this->authenticate()) return;

        $db = $this->app->db();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $search = trim((string) ($_GET['search'] ?? ''));
        $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
        $plan   = strtolower(trim((string) ($_GET['plan'] ?? 'all')));

        $whereClause = [];
        $params = [];

        if ($search !== '') {
            $whereClause[] = '(`customer_name` LIKE :s OR `license_key` LIKE :s OR `ip_address` LIKE :s OR `bound_domain` LIKE :s)';
            $params['s'] = "%{$search}%";
        }

        if ($status !== '' && $status !== 'all') {
            $whereClause[] = '`status` = :status';
            $params['status'] = $status;
        }

        if ($plan !== '' && $plan !== 'all') {
            $whereClause[] = '`plan_type` = :plan';
            $params['plan'] = $plan;
        }

        $sqlWhere = count($whereClause) > 0 ? 'WHERE ' . implode(' AND ', $whereClause) : '';

        // Auto-expire check
        $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` < NOW()");

        $totalRow = $db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` {$sqlWhere}", $params);
        $totalRecords = (int) ($totalRow['c'] ?? 0);

        $sql = "SELECT * FROM `server_licenses` {$sqlWhere} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}";
        $rows = $db->fetchAll($sql, $params);

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->formatLicenseObject($r);
        }

        // Summary counts
        $summary = [
            'total'     => (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM `server_licenses`')['c'] ?? 0),
            'active'    => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'active'")['c'] ?? 0),
            'expired'   => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'expired'")['c'] ?? 0),
            'suspended' => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` IN ('suspended', 'blocked')")['c'] ?? 0),
            'revoked'   => (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'revoked'")['c'] ?? 0),
        ];

        $this->success([
            'licenses'      => $items,
            'pagination'    => [
                'total_records' => $totalRecords,
                'current_page'  => $page,
                'per_page'      => $perPage,
                'total_pages'   => max(1, (int) ceil($totalRecords / $perPage)),
            ],
            'summary'       => $summary,
        ], 'Licenses retrieved successfully.');
    }

    /** GET /api/v1/licenses/stats — System-wide License Analytics */
    public function stats(): void
    {
        if (! $this->authenticate()) return;

        $db = $this->app->db();

        // Auto-expire check
        $db->execute("UPDATE `server_licenses` SET `status` = 'expired' WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` < NOW()");

        $totalLicenses   = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM `server_licenses`')['c'] ?? 0);
        $activeLicenses  = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'active'")['c'] ?? 0);
        $expiredLicenses = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` = 'expired'")['c'] ?? 0);
        $revokedLicenses = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `server_licenses` WHERE `status` IN ('revoked', 'blocked', 'suspended')")['c'] ?? 0);

        // Expiring in next 7 days
        $expiring7d = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM `server_licenses` 
             WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"
        )['c'] ?? 0);

        $totalDevices = (int) ($db->fetchOne("SELECT COUNT(*) AS c FROM `license_devices` WHERE `status` = 'active'")['c'] ?? 0);

        // Plan distribution
        $plansRaw = $db->fetchAll('SELECT `plan_type`, COUNT(*) AS cnt FROM `server_licenses` GROUP BY `plan_type`');
        $planDist = [
            'trial'     => 0,
            'monthly'   => 0,
            'quarterly' => 0,
            'yearly'    => 0,
            'lifetime'  => 0,
        ];

        foreach ($plansRaw as $pr) {
            $p = (string) $pr['plan_type'];
            if ($p === 'annual') $p = 'yearly';
            $planDist[$p] = (int) $pr['cnt'];
        }

        $this->success([
            'stats' => [
                'total_licenses'      => $totalLicenses,
                'active_licenses'     => $activeLicenses,
                'expired_licenses'    => $expiredLicenses,
                'revoked_licenses'    => $revokedLicenses,
                'expiring_soon_7d'    => $expiring7d,
                'total_bound_devices' => $totalDevices,
                'plan_distribution'   => $planDist,
            ]
        ], 'System-wide license statistics retrieved.');
    }

    /** GET /api/v1/licenses/{id_or_key} — Get Single License Details */
    public function show(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $db = $this->app->db();
        $devices = $db->fetchAll('SELECT * FROM `license_devices` WHERE `license_id` = :id ORDER BY `id` DESC', ['id' => (int) $lic['id']]);

        $deviceList = [];
        foreach ($devices as $d) {
            $deviceList[] = [
                'id'         => (int) $d['id'],
                'machine_id' => (string) $d['device_hwid'],
                'last_ip'    => (string) $d['server_ip'],
                'hostname'   => (string) ($d['hostname'] ?? ''),
                'first_seen' => $d['activated_at'] ?? null,
                'last_seen'  => $d['last_seen'] ?? null,
                'status'     => (string) ($d['status'] === 'active' ? 'authorized' : $d['status']),
            ];
        }

        $data = $this->formatLicenseObject($lic);
        $data['devices'] = $deviceList;

        $this->success($data, 'License details retrieved successfully.');
    }

    /** PUT /api/v1/licenses/{id_or_key} — Update / Extend License */
    public function update(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $input = $this->getJsonInput();
        $db = $this->app->db();

        $customerName   = isset($input['customer_name']) ? trim((string) $input['customer_name']) : (string) $lic['customer_name'];
        $customerContact= isset($input['customer_contact']) ? trim((string) $input['customer_contact']) : (string) $lic['customer_contact'];
        $planType       = isset($input['plan_type']) ? strtolower(trim((string) $input['plan_type'])) : (string) $lic['plan_type'];
        $maxDevices     = isset($input['max_devices']) ? max(1, (int) $input['max_devices']) : (int) $lic['max_devices'];
        $boundIp        = isset($input['bound_ip']) ? trim((string) $input['bound_ip']) : (string) $lic['ip_address'];
        $boundDomain    = isset($input['bound_domain']) ? trim((string) $input['bound_domain']) : (string) ($lic['bound_domain'] ?? '');
        $notes          = isset($input['notes']) ? trim((string) $input['notes']) : (string) $lic['notes'];
        $addDays        = max(0, (int) ($input['add_days'] ?? 0));

        // Expiration extension logic
        $expiresAt = $lic['expires_at'];
        if ($planType === 'lifetime') {
            $expiresAt = null;
        } elseif ($addDays > 0) {
            $baseTime = (! empty($expiresAt) && strtotime((string) $expiresAt) > time())
                ? strtotime((string) $expiresAt)
                : time();
            $expiresAt = date('Y-m-d H:i:s', $baseTime + ($addDays * 86400));
        }

        $db->execute(
            'UPDATE `server_licenses` 
             SET `customer_name` = :customer,
                 `customer_contact` = :contact,
                 `plan_type` = :plan,
                 `max_devices` = :max_dev,
                 `ip_address` = :ip,
                 `bound_domain` = :domain,
                 `notes` = :notes,
                 `expires_at` = :exp
             WHERE `id` = :id',
            [
                'customer' => $customerName,
                'contact'  => $customerContact,
                'plan'     => $planType,
                'max_dev'  => $maxDevices,
                'ip'       => $boundIp,
                'domain'   => $boundDomain,
                'notes'    => $notes,
                'exp'      => $expiresAt,
                'id'       => (int) $lic['id'],
            ]
        );

        $updatedRecord = $this->findLicenseRecord((string) $lic['id']);
        $this->success($this->formatLicenseObject($updatedRecord), 'License updated successfully.');
    }

    /** POST /api/v1/licenses/{id_or_key}/status — Change License Status / Ban */
    public function updateStatus(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $input = $this->getJsonInput();
        $newStatus = strtolower(trim((string) ($input['status'] ?? '')));

        $validStatuses = ['active', 'suspended', 'revoked', 'blocked', 'expired'];
        if (! in_array($newStatus, $validStatuses, true)) {
            $this->error('ERR_INVALID_STATUS', 'Invalid status. Allowed values: active, suspended, revoked', 400);
            return;
        }

        $db = $this->app->db();
        $db->execute('UPDATE `server_licenses` SET `status` = :s WHERE `id` = :id', [
            's'  => $newStatus,
            'id' => (int) $lic['id'],
        ]);

        $updatedRecord = $this->findLicenseRecord((string) $lic['id']);
        $this->success($this->formatLicenseObject($updatedRecord), "License status changed to '{$newStatus}'.");
    }

    /** DELETE /api/v1/licenses/{id_or_key} — Delete License */
    public function delete(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $db = $this->app->db();
        $db->execute('DELETE FROM `license_devices` WHERE `license_id` = :id OR `license_key` = :k', [
            'id' => (int) $lic['id'],
            'k'  => (string) $lic['license_key'],
        ]);
        $db->execute('DELETE FROM `server_licenses` WHERE `id` = :id', ['id' => (int) $lic['id']]);

        $this->success(['license_key' => $lic['license_key']], 'License record and associated device bindings deleted successfully.');
    }

    /** GET /api/v1/licenses/{id_or_key}/devices — List Bound Devices */
    public function listDevices(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $db = $this->app->db();
        $devices = $db->fetchAll('SELECT * FROM `license_devices` WHERE `license_id` = :id ORDER BY `id` DESC', ['id' => (int) $lic['id']]);

        $deviceList = [];
        foreach ($devices as $d) {
            $deviceList[] = [
                'id'         => (int) $d['id'],
                'machine_id' => (string) $d['device_hwid'],
                'last_ip'    => (string) $d['server_ip'],
                'hostname'   => (string) ($d['hostname'] ?? ''),
                'first_seen' => $d['activated_at'] ?? null,
                'last_seen'  => $d['last_seen'] ?? null,
                'status'     => (string) ($d['status'] === 'active' ? 'authorized' : $d['status']),
            ];
        }

        $this->jsonResponse([
            'success'     => true,
            'total_bound' => count($deviceList),
            'max_allowed' => (int) $lic['max_devices'],
            'devices'     => $deviceList,
        ]);
    }

    /** DELETE /api/v1/licenses/{id_or_key}/devices/{machine_id} — Unbind Specific Device */
    public function unbindDevice(string $id_or_key, string $machineId): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $db = $this->app->db();
        $targetDev = $db->fetchOne(
            'SELECT * FROM `license_devices` WHERE (`license_id` = :id OR `license_key` = :k) AND `device_hwid` = :hwid LIMIT 1',
            [
                'id'   => (int) $lic['id'],
                'k'    => (string) $lic['license_key'],
                'hwid' => $machineId,
            ]
        );

        if (! $targetDev) {
            $this->error('ERR_DEVICE_NOT_FOUND', 'Bound device hardware fingerprint not found.', 404);
            return;
        }

        $db->execute('DELETE FROM `license_devices` WHERE `id` = :id', ['id' => (int) $targetDev['id']]);

        // Recalculate active_activations count
        $cntRow = $db->fetchOne('SELECT COUNT(*) AS c FROM `license_devices` WHERE `license_id` = :id AND `status` = "active"', ['id' => (int) $lic['id']]);
        $newCount = (int) ($cntRow['c'] ?? 0);
        $db->execute('UPDATE `server_licenses` SET `active_activations` = :c WHERE `id` = :id', ['c' => $newCount, 'id' => (int) $lic['id']]);

        $this->success([
            'machine_id'         => $machineId,
            'active_activations' => $newCount,
            'max_devices'        => (int) $lic['max_devices'],
        ], 'Device HWID revoked and activation slot freed successfully.');
    }

    /** POST /api/v1/licenses/{id_or_key}/reset-bindings — Reset IP & Device Bindings */
    public function resetBindings(string $id_or_key): void
    {
        if (! $this->authenticate()) return;

        $lic = $this->findLicenseRecord($id_or_key);
        if (! $lic) {
            $this->error('ERR_LIC_NOT_FOUND', 'License not found.', 404);
            return;
        }

        $db = $this->app->db();
        $db->execute('DELETE FROM `license_devices` WHERE `license_id` = :id OR `license_key` = :k', [
            'id' => (int) $lic['id'],
            'k'  => (string) $lic['license_key'],
        ]);

        $db->execute('UPDATE `server_licenses` SET `ip_address` = "0.0.0.0", `bound_domain` = "", `active_activations` = 0 WHERE `id` = :id', [
            'id' => (int) $lic['id'],
        ]);

        $updatedRecord = $this->findLicenseRecord((string) $lic['id']);

        $this->success($this->formatLicenseObject($updatedRecord), 'All device HWID bindings and bound IP reset to allow server migration.');
    }
}
