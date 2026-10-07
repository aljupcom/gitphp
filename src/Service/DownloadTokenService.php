<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Handles time-limited signed download tokens & permanent update secrets.
 */
class DownloadTokenService
{
    private App $app;
    private string $secretKey;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->secretKey = $this->resolveSecretKey();
    }

    /**
     * Generate a time-limited signed token for a download short code.
     * Format: <expiry_timestamp>_<hmac_hash>
     * Default TTL: 3600 seconds (1 hour).
     */
    public function generateToken(string $shortCode, int $ttlSeconds = 3600, ?string $clientIp = null): string
    {
        $expiresAt = time() + max(300, $ttlSeconds);
        $payload = "{$shortCode}|{$expiresAt}|" . ($clientIp ?? '');
        $sig = hash_hmac('sha256', $payload, $this->secretKey);
        // Take 32 characters for clean URLs
        $shortSig = substr($sig, 0, 32);

        return "{$expiresAt}_{$shortSig}";
    }

    /**
     * Validate a token against short code, expiration, and optional IP.
     */
    public function validateToken(string $shortCode, string $token, ?string $clientIp = null): bool
    {
        $parts = explode('_', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        $expiresAt = (int) $parts[0];
        $providedSig = $parts[1];

        // Check if expired
        if ($expiresAt < time()) {
            return false;
        }

        // Check with IP first, then fallback without IP (in case client is on mobile network/proxy)
        $payloadWithIp = "{$shortCode}|{$expiresAt}|" . ($clientIp ?? '');
        $expectedSigWithIp = substr(hash_hmac('sha256', $payloadWithIp, $this->secretKey), 0, 32);
        if (hash_equals($expectedSigWithIp, $providedSig)) {
            return true;
        }

        $payloadWithoutIp = "{$shortCode}|{$expiresAt}|";
        $expectedSigWithoutIp = substr(hash_hmac('sha256', $payloadWithoutIp, $this->secretKey), 0, 32);
        return hash_equals($expectedSigWithoutIp, $providedSig);
    }

    /**
     * Get or initialize the global permanent remote update secret key (System admin fallback).
     */
    public function getPermanentUpdateKey(): string
    {
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT `setting_value` FROM `settings` WHERE `setting_key` = "remote_update_secret" LIMIT 1');
        $stmt->execute();
        $key = $stmt->fetchColumn();

        if (empty($key)) {
            $key = 'upd_' . bin2hex(random_bytes(16));
            $ins = $db->prepare('INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ("remote_update_secret", ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
            $ins->execute([$key]);
        }

        return (string) $key;
    }

    /**
     * Set/regenerate a new global permanent remote update secret key.
     */
    public function regeneratePermanentUpdateKey(): string
    {
        $db = $this->app->db()->connection();
        $newKey = 'upd_' . bin2hex(random_bytes(16));
        $ins = $db->prepare('INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ("remote_update_secret", ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
        $ins->execute([$newKey]);

        return $newKey;
    }

    /**
     * Get or initialize a user-specific remote update token (upd_*).
     */
    public function getUserUpdateKey(int $userId): string
    {
        if ($userId <= 0) {
            return $this->getPermanentUpdateKey();
        }

        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT `setting_value` FROM `user_settings` WHERE `user_id` = ? AND `setting_key` = "remote_update_token" LIMIT 1');
        $stmt->execute([$userId]);
        $key = $stmt->fetchColumn();

        if (empty($key)) {
            $key = 'upd_' . bin2hex(random_bytes(16));
            $ins = $db->prepare('INSERT INTO `user_settings` (`user_id`, `setting_key`, `setting_value`) VALUES (?, "remote_update_token", ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
            $ins->execute([$userId, $key]);
        }

        return (string) $key;
    }

    /**
     * Regenerate a user-specific remote update token (upd_*).
     */
    public function regenerateUserUpdateKey(int $userId): string
    {
        if ($userId <= 0) {
            return $this->regeneratePermanentUpdateKey();
        }

        $db = $this->app->db()->connection();
        $newKey = 'upd_' . bin2hex(random_bytes(16));
        $ins = $db->prepare('INSERT INTO `user_settings` (`user_id`, `setting_key`, `setting_value`) VALUES (?, "remote_update_token", ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
        $ins->execute([$userId, $newKey]);

        return $newKey;
    }

    /**
     * Check if a given string matches:
     * 1) A user-specific key for a given user or resource owner, OR
     * 2) Any valid user's remote update key, OR
     * 3) The global system update key.
     */
    public function isPermanentUpdateKey(string $key, ?int $expectedUserId = null): bool
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return false;
        }

        // Global key check
        if (hash_equals($this->getPermanentUpdateKey(), $trimmed)) {
            return true;
        }

        $db = $this->app->db()->connection();

        // If a specific user is expected (e.g. repo owner)
        if ($expectedUserId !== null && $expectedUserId > 0) {
            $userKey = $this->getUserUpdateKey($expectedUserId);
            if (hash_equals($userKey, $trimmed)) {
                return true;
            }
        }

        // Fallback: check if the key matches any user's remote_update_token
        if (str_starts_with($trimmed, 'upd_')) {
            $stmt = $db->prepare('SELECT `user_id` FROM `user_settings` WHERE `setting_key` = "remote_update_token" AND `setting_value` = ? LIMIT 1');
            $stmt->execute([$trimmed]);
            $foundUserId = $stmt->fetchColumn();
            if ($foundUserId !== false && $foundUserId !== null) {
                return true;
            }
        }

        return false;
    }

    private function resolveSecretKey(): string
    {
        $db = $this->app->db()->connection();
        $stmt = $db->prepare('SELECT `setting_value` FROM `settings` WHERE `setting_key` = "download_signing_secret" LIMIT 1');
        $stmt->execute();
        $key = $stmt->fetchColumn();

        if (!empty($key)) {
            return (string) $key;
        }

        $newSecret = bin2hex(random_bytes(32));
        $ins = $db->prepare('INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ("download_signing_secret", ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
        $ins->execute([$newSecret]);

        return $newSecret;
    }
}
