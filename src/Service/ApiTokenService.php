<?php

declare(strict_types=1);

namespace App\Service;

use App\App;
use RuntimeException;

/**
 * Personal API access tokens ("gtp_<40hex>").
 * Only a SHA-256 hash is stored; the raw token is shown exactly once.
 */
final class ApiTokenService
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Create a token for an identity. userId 0 represents the owner account.
     * @return array{token: string, id: int} The raw token — returned once.
     */
    public function create(int $userId, string $name, string $scopes = 'read'): array
    {
        $raw   = 'gtp_' . bin2hex(random_bytes(20));
        $hash  = hash('sha256', $raw);
        // "gtp_" + first 8 hex chars of the secret portion
        $prefix = substr($raw, 0, 12);

        $this->app->db()->execute(
            'INSERT INTO `api_tokens` (`user_id`, `name`, `token_hash`, `token_prefix`, `scopes`, `created_at`)
             VALUES (:user, :name, :hash, :prefix, :scopes, CURRENT_TIMESTAMP)',
            [
                'user'   => max(0, $userId),
                'name'   => mb_substr(trim($name), 0, 100) ?: 'token',
                'hash'   => $hash,
                'prefix' => $prefix,
                'scopes' => ! empty(trim($scopes)) ? mb_substr(trim($scopes), 0, 255) : 'repo',
            ],
        );

        return ['token' => $raw, 'id' => (int) $this->app->db()->lastInsertId()];
    }

    /** Revoke (soft-delete) a token owned by the given identity. */
    public function revoke(int $tokenId, int $userId): bool
    {
        $stmt = $this->app->db()->execute(
            'UPDATE `api_tokens` SET `revoked_at` = NOW() WHERE `id` = :id AND `user_id` = :user AND `revoked_at` IS NULL',
            ['id' => $tokenId, 'user' => $userId],
        );

        return $stmt->rowCount() > 0;
    }

    /** Permanently delete a token owned by the given identity. */
    public function delete(int $tokenId, int $userId): bool
    {
        $stmt = $this->app->db()->execute(
            'DELETE FROM `api_tokens` WHERE `id` = :id AND `user_id` = :user',
            ['id' => $tokenId, 'user' => $userId],
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Resolve a Bearer token to its identity. Updates last_used_at.
     * @return array{user_id: int, scopes: string, name: string}|null
     */
    public function authenticate(string $rawToken): ?array
    {
        if ($rawToken === '' || ! str_starts_with($rawToken, 'gtp_')) return null;

        $row = $this->app->db()->fetchOne(
            'SELECT `id`, `user_id`, `scopes`, `name`, `revoked_at`
             FROM `api_tokens`
             WHERE `token_hash` = :hash
             LIMIT 1',
            ['hash' => hash('sha256', $rawToken)],
        );

        if ($row === false || $row['revoked_at'] !== null) {
            throw new RuntimeException('Invalid or revoked token.');
        }

        try {
            $this->app->db()->execute(
                'UPDATE `api_tokens` SET `last_used_at` = NOW() WHERE `id` = :id',
                ['id' => (int) $row['id']],
            );
        } catch (\Throwable) {
            // best-effort
        }

        return [
            'user_id' => (int) $row['user_id'],
            'scopes'  => (string) $row['scopes'],
            'name'    => (string) $row['name'],
        ];
    }
}
