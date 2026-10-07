<?php

declare(strict_types=1);

/**
 * Migration: performance indexes + schema documentation parity.
 *
 * 1. Adds the indexes identified by the performance audit (EXPLAIN review
 *    of homepage / issues / pulls / OTP lookups).
 * 2. Creates (via schema documentation) the runtime tables that were
 *    previously created outside the migration system, so a fresh
 *    deployment converges to the same shape: server_licenses,
 *    license_devices, mobile_otps, user_follows.
 *
 * Idempotent: index creation is guarded, table creation uses IF NOT EXISTS.
 */

return static function (PDO $pdo): void {
    $indexExists = static function (string $table, string $index) use ($pdo): bool {
        return (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$index}'"
        )->fetchColumn();
    };

    // ── 1. Performance indexes ─────────────────────────────────────
    if (! $indexExists('repositories', 'idx_repositories_updated')) {
        $pdo->exec('ALTER TABLE `repositories` ADD INDEX `idx_repositories_updated` (`updated_at`)');
    }

    if (! $indexExists('bug_reports', 'idx_bug_reports_repo_status_created')) {
        $pdo->exec('ALTER TABLE `bug_reports` ADD INDEX `idx_bug_reports_repo_status_created` (`repo_id`, `status`, `created_at`)');
    }
    if (! $indexExists('bug_reports', 'idx_bug_reports_updated')) {
        $pdo->exec('ALTER TABLE `bug_reports` ADD INDEX `idx_bug_reports_updated` (`updated_at`)');
    }

    if (! $indexExists('pull_requests', 'idx_pull_requests_repo_status_updated')) {
        $pdo->exec('ALTER TABLE `pull_requests` ADD INDEX `idx_pull_requests_repo_status_updated` (`repo_id`, `status`, `updated_at`)');
    }

    if (! $indexExists('mobile_otps', 'idx_mobile_otps_otp')) {
        $pdo->exec('ALTER TABLE `mobile_otps` ADD INDEX `idx_mobile_otps_otp` (`otp_code`)');
    }

    // ── 2. Runtime tables folded into the migration system ─────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS `server_licenses` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `license_key`  VARCHAR(64)  NOT NULL,
        `label`        VARCHAR(120) DEFAULT NULL,
        `max_devices`  INT UNSIGNED NOT NULL DEFAULT 1,
        `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_server_licenses_key` (`license_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `license_devices` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `license_id`   INT UNSIGNED NOT NULL,
        `device_hash`  VARCHAR(128) NOT NULL,
        `device_name`  VARCHAR(190) DEFAULT NULL,
        `last_seen`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_license_device` (`license_id`, `device_hash`),
        CONSTRAINT `fk_license_devices_license`
            FOREIGN KEY (`license_id`) REFERENCES `server_licenses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `mobile_otps` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `otp_code`   VARCHAR(10)  NOT NULL,
        `user_id`    INT UNSIGNED DEFAULT NULL,
        `purpose`    VARCHAR(50) NOT NULL DEFAULT \'login\',
        `expires_at` DATETIME     NOT NULL,
        `is_used`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_mobile_otps_otp` (`otp_code`),
        KEY `idx_mobile_otps_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `user_follows` (
        `follower_id` INT UNSIGNED NOT NULL,
        `followed_id` INT UNSIGNED NOT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`follower_id`, `followed_id`),
        KEY `idx_user_follows_followed` (`followed_id`),
        CONSTRAINT `fk_user_follows_follower`
            FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_user_follows_followed`
            FOREIGN KEY (`followed_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
