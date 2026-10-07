<?php
declare(strict_types=1);

/**
 * Security & collaboration upgrades:
 *  - password_reset_tokens : email-based forgot/reset password flow
 *  - remember_tokens       : "remember me" persistent login cookies
 *  - user_sessions         : session registry (list / revoke)
 *  - users.totp_*          : TOTP two-factor authentication
 *  - pull_requests.approval: code review approvals / requested changes
 *  - branch_protections.require_approval
 *
 * Idempotent: safe to run multiple times.
 */

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
        `token_hash` CHAR(64)        NOT NULL,
        `expires_at` DATETIME        NOT NULL,
        `used_at`    DATETIME        DEFAULT NULL,
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_reset_token_hash` (`token_hash`),
        KEY `idx_reset_user` (`user_id`, `used_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `remember_tokens` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
        `token_hash` CHAR(64)        NOT NULL,
        `expires_at` DATETIME        NOT NULL,
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_remember_token_hash` (`token_hash`),
        KEY `idx_remember_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_sessions` (
        `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `user_id`       INT UNSIGNED    NOT NULL DEFAULT 0,
        `token`         VARCHAR(64)     NOT NULL,
        `ip_address`    VARCHAR(45)     DEFAULT NULL,
        `user_agent`    VARCHAR(255)    DEFAULT NULL,
        `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_activity` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sessions_user` (`user_id`),
        KEY `idx_sessions_token` (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── Columns ──────────────────────────────────────────────────────
    $usersCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN));
    foreach ([
        'totp_secret'  => "ALTER TABLE `users` ADD COLUMN `totp_secret` VARCHAR(64) DEFAULT NULL",
        'totp_enabled' => "ALTER TABLE `users` ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $ddl) {
        if (! in_array($col, $usersCols, true)) $pdo->exec($ddl);
    }

    $prCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `pull_requests`')->fetchAll(PDO::FETCH_COLUMN));
    foreach ([
        'approval'      => "ALTER TABLE `pull_requests` ADD COLUMN `approval` ENUM('pending','approved','changes_requested') NOT NULL DEFAULT 'pending'",
        'approved_by'   => "ALTER TABLE `pull_requests` ADD COLUMN `approved_by` VARCHAR(100) DEFAULT NULL",
        'approved_at'   => "ALTER TABLE `pull_requests` ADD COLUMN `approved_at` DATETIME DEFAULT NULL",
    ] as $col => $ddl) {
        if (! in_array($col, $prCols, true)) $pdo->exec($ddl);
    }

    $bpCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `branch_protections`')->fetchAll(PDO::FETCH_COLUMN));
    if (! in_array('require_approval', $bpCols, true)) {
        $pdo->exec("ALTER TABLE `branch_protections` ADD COLUMN `require_approval` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_admin`");
    }
};
