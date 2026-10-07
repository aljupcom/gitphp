<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Manages time-limited, signed capability tokens for ultra-fast, secure
 * remote Git push and clone operations without permanent credentials.
 */
final class SignedTokenService
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Create a new signed capability token.
     *
     * @return array{id: int, raw_token: string, name: string, scope: string, expires_at: string, single_use: bool}
     */
    public function createToken(
        int $userId,
        string $name,
        string $scope = 'push',
        ?int $repoId = null,
        int $durationSeconds = 300,
        bool $singleUse = false,
        ?string $allowedIp = null,
    ): array {
        if (! in_array($scope, ['push', 'clone', 'all'], true)) {
            $scope = 'push';
        }

        // Clamp duration between 1 minute and 30 days
        $durationSeconds = max(60, min(86400 * 30, $durationSeconds));

        $name = trim($name) !== '' ? trim($name) : 'Quick-Sync Token';
        $entropy = bin2hex(random_bytes(24));
        $rawToken = "gitsig_{$entropy}";
        $tokenHash = hash('sha256', $rawToken);
        $tokenPrefix = substr($rawToken, 0, 14) . '…';

        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);

        $allowedIp = $allowedIp !== null && trim($allowedIp) !== '' ? trim($allowedIp) : null;

        $this->app->db()->execute(
            'INSERT INTO `signed_capabilities` 
             (`user_id`, `name`, `token_hash`, `token_prefix`, `scope`, `repo_id`, `allowed_ip`, `single_use`, `used_count`, `expires_at`, `created_at`)
             VALUES (:u, :n, :th, :tp, :sc, :r, :ip, :su, 0, :exp, :cr)',
            [
                'u'   => $userId,
                'n'   => $name,
                'th'  => $tokenHash,
                'tp'  => $tokenPrefix,
                'sc'  => $scope,
                'r'   => $repoId,
                'ip'  => $allowedIp,
                'su'  => $singleUse ? 1 : 0,
                'exp' => $expiresAt,
                'cr'  => $createdAt,
            ]
        );

        $id = (int) $this->app->db()->lastInsertId();

        return [
            'id'          => $id,
            'raw_token'   => $rawToken,
            'name'        => $name,
            'scope'       => $scope,
            'expires_at'  => $expiresAt,
            'single_use'  => $singleUse,
        ];
    }

    /**
     * Verify a raw token for a specific action (push/clone) and repository,
     * and increment its usage (or enforce single-use burn).
     *
     * @return array<string, mixed>|null User row if valid, null otherwise
     */
    public function verifyAndConsumeToken(
        string $rawToken,
        string $action,
        int $repoId,
        ?string $clientIp = null,
    ): ?array {
        if (! str_starts_with($rawToken, 'gitsig_')) {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        $token = $this->app->db()->fetchOne(
            'SELECT * FROM `signed_capabilities` WHERE `token_hash` = :th LIMIT 1',
            ['th' => $tokenHash]
        );

        if ($token === false) {
            return null;
        }

        // 1. Expiration check
        if (strtotime((string) $token['expires_at']) < time()) {
            return null;
        }

        // 2. Single-use check
        if ((int) $token['single_use'] === 1 && (int) $token['used_count'] > 0) {
            return null;
        }

        // 3. Repository isolation check
        if (! empty($token['repo_id']) && (int) $token['repo_id'] !== $repoId) {
            return null;
        }

        // 4. Scope permission check
        $scope = (string) $token['scope'];
        if ($action === 'push' && in_array($scope, ['clone', 'read', 'readonly'], true)) {
            return null;
        }

        // 5. IP binding check
        if (! empty($token['allowed_ip']) && $clientIp !== null) {
            if (trim($clientIp) !== trim((string) $token['allowed_ip'])) {
                return null;
            }
        }

        // Increment usage count
        $this->app->db()->execute(
            'UPDATE `signed_capabilities` SET `used_count` = `used_count` + 1 WHERE `id` = :id',
            ['id' => (int) $token['id']]
        );

        // Fetch corresponding user
        $tokenUid = (int) $token['user_id'];
        $ownerName = (string) $this->app->config('app.owner', 'admin');

        if ($tokenUid === 0) {
            return ['id' => 0, 'username' => $ownerName, 'role' => 'admin'];
        }

        $user = $this->app->db()->fetchOne(
            'SELECT * FROM `users` WHERE `id` = :uid LIMIT 1',
            ['uid' => $tokenUid]
        );

        if ($user === false) {
            return ['id' => $tokenUid, 'username' => $ownerName, 'role' => 'admin'];
        }

        return $user;
    }

    /**
     * List all active and recent signed tokens for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listUserTokens(int $userId): array
    {
        $rows = $this->app->db()->fetchAll(
            'SELECT sc.*, r.name AS repo_name, r.slug AS repo_slug 
             FROM `signed_capabilities` sc
             LEFT JOIN `repositories` r ON r.id = sc.repo_id
             WHERE sc.`user_id` = :uid
             ORDER BY sc.`id` DESC LIMIT 50',
            ['uid' => $userId]
        );

        $now = time();
        foreach ($rows as &$r) {
            $expTs = strtotime((string) $r['expires_at']);
            $r['is_expired'] = $expTs < $now || ((int) $r['single_use'] === 1 && (int) $r['used_count'] > 0);
            $r['remaining_seconds'] = max(0, $expTs - $now);
            $r['remaining_human'] = $this->formatRemaining($r['remaining_seconds']);
        }
        unset($r);

        return $rows;
    }

    /**
     * Revoke a token immediately.
     */
    public function revokeToken(int $tokenId, int $userId): bool
    {
        $this->app->db()->execute(
            'DELETE FROM `signed_capabilities` WHERE `id` = :id AND `user_id` = :uid',
            ['id' => $tokenId, 'uid' => $userId]
        );

        return true;
    }

    private function formatRemaining(int $seconds): string
    {
        if ($seconds <= 0) return 'Expired';
        if ($seconds < 60) return "{$seconds}s remaining";
        $mins = (int) round($seconds / 60);
        if ($mins < 60) return "{$mins} min remaining";
        $hours = (int) round($mins / 60);
        if ($hours < 24) return "{$hours} hr remaining";
        $days = (int) round($hours / 24);
        return "{$days} days remaining";
    }
}
