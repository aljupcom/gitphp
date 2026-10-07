<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

final class AuditLogger
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Log a security or administrative action.
     */
    public function log(string $action, ?int $repoId = null, ?string $details = null, int $userId = 0, string $userName = 'owner'): void
    {
        try {
            $ip = \App\Service\SecurityService::resolveClientIp();
            $db = $this->app->db()->connection();
            $stmt = $db->prepare('
                INSERT INTO audit_logs (user_id, user_name, action, repo_id, details, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$userId, $userName, $action, $repoId, $details, $ip]);
        } catch (\Throwable $e) {
            // Non-blocking logger
            error_log('[AuditLogger Error] ' . $e->getMessage());
        }
    }
}
