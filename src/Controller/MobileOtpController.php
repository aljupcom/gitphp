<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Service\ApiTokenService;

/**
 * Controller for Mobile App OTP Pairing & Authentication with Custom Duration Support up to 1 Year.
 */
final class MobileOtpController
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

    /** Parse JSON or POST Request Body */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (! empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
        return $_POST ?? [];
    }

    /**
     * POST /api/v1/auth/mobile-otp/generate
     * Generate a 6-digit pairing code valid for custom duration (1 min up to 365 days / 525,600 mins).
     */
    public function generate(): void
    {
        $input = $this->getJsonInput();
        $db = $this->app->db();

        // Custom duration in minutes (default 10 mins, min 1 min, max 525600 mins / 365 days)
        $durationMinutes = max(1, min(525600, (int) ($input['duration_minutes'] ?? ($input['minutes'] ?? ($input['duration'] ?? 10)))));
        $durationSeconds = $durationMinutes * 60;

        // Generate 6-digit numeric OTP code
        $otpCode = sprintf('%06d', random_int(100000, 999999));
        $adminUser = (string) ($_SESSION['username'] ?? ($_SESSION['admin_user'] ?? $this->app->config('app.owner', 'admin')));

        // Insert using DB time to eliminate timezone drift
        $db->execute(
            'INSERT INTO `mobile_otps` (`otp_code`, `admin_user`, `created_at`, `expires_at`, `used`) 
             VALUES (:otp, :user, NOW(), DATE_ADD(NOW(), INTERVAL :mins MINUTE), 0)',
            [
                'otp'  => $otpCode,
                'user' => $adminUser,
                'mins' => $durationMinutes,
            ]
        );

        $row = $db->fetchOne('SELECT `expires_at` FROM `mobile_otps` WHERE `otp_code` = :otp ORDER BY `id` DESC LIMIT 1', ['otp' => $otpCode]);

        $this->jsonResponse([
            'success'            => true,
            'otp_code'           => $otpCode,
            'duration_minutes'   => $durationMinutes,
            'expires_in_seconds' => $durationSeconds,
            'expires_at'         => $row['expires_at'] ?? date('Y-m-d H:i:s', time() + $durationSeconds),
            'admin_user'         => $adminUser,
            'message'            => "Temporary access code generated successfully.",
        ], 201);
    }

    /**
     * POST /api/v1/auth/mobile-otp/verify
     * Called by Android/iOS App with OTP code to receive a 90-day API token.
     */
    public function verify(): void
    {
        $input = $this->getJsonInput();
        $otp = trim((string) ($input['otp_code'] ?? ($input['otp'] ?? '')));
        $deviceName = trim((string) ($input['device_name'] ?? ($input['device'] ?? 'Android Device')));

        if (empty($otp)) {
            $this->jsonResponse(['success' => false, 'message' => 'OTP code is required'], 400);
            return;
        }

        $db = $this->app->db();

        // Query active, non-expired OTP using DB time
        $record = $db->fetchOne(
            'SELECT * FROM `mobile_otps` 
             WHERE `otp_code` = :otp AND `used` = 0 AND `expires_at` > NOW() 
             ORDER BY `id` DESC LIMIT 1',
            ['otp' => $otp]
        );

        if (! $record) {
            $this->jsonResponse(['success' => false, 'message' => 'Invalid or expired pairing code.'], 401);
            return;
        }

        // Mark OTP as used (single use enforcement)
        $db->execute('UPDATE `mobile_otps` SET `used` = 1 WHERE `id` = :id', ['id' => (int) $record['id']]);

        // Generate 90-day mobile API session token
        $adminUser = (string) ($record['admin_user'] ?? 'admin');
        $tokenService = new ApiTokenService($this->app);
        $tokenResult = $tokenService->create(0, 'Mobile Admin: ' . $deviceName, 'read,write,*');

        $expiresInSeconds = 86400 * 90; // 90 days mobile session

        $this->jsonResponse([
            'success'    => true,
            'token'      => $tokenResult['token'],
            'expires_in' => $expiresInSeconds,
            'admin_user' => $adminUser,
            'user'       => $adminUser,
            'role'       => 'mobile_admin',
            'device'     => $deviceName,
            'message'    => 'Device paired successfully.',
        ], 200);
    }
}
