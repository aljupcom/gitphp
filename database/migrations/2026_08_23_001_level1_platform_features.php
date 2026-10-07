<?php

declare(strict_types=1);

/**
 * Level 1 platform features:
 *  - Pull requests + conversation comments + line-level review comments
 *  - Branch protections (pre-receive enforced)
 *  - Webhooks + deliveries
 *  - Personal API tokens
 *  - Multi-owner namespaces (repositories.owner_user_id)
 *  - Backfills DDL that shipped code depended on but schema.sql never
 *    created (repo_releases, file_downloads, audit_logs, system_settings,
 *    repositories.forked_from_id).
 *
 * Idempotent: safe to run multiple times.
 */

return static function (PDO $pdo): void {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // ── Tables ──────────────────────────────────────────────────────
    $tables = [
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `key_name` VARCHAR(100) NOT NULL,
            `value`    VARCHAR(500) DEFAULT NULL,
            PRIMARY KEY (`key_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    INT UNSIGNED    DEFAULT 0,
            `user_name`  VARCHAR(100)    DEFAULT 'owner',
            `action`     VARCHAR(50)     NOT NULL,
            `repo_id`    INT UNSIGNED    DEFAULT NULL,
            `details`    TEXT            DEFAULT NULL,
            `ip_address` VARCHAR(45)     DEFAULT NULL,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_audit_logs_created` (`created_at`),
            KEY `idx_audit_logs_action`  (`action`),
            KEY `idx_audit_logs_user`    (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `repo_releases` (
            `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `repo_id`          INT UNSIGNED    NOT NULL,
            `tag_name`         VARCHAR(255)    NOT NULL,
            `target_commitish` VARCHAR(255)    DEFAULT 'main',
            `name`             VARCHAR(255)    DEFAULT NULL,
            `body`             MEDIUMTEXT      DEFAULT NULL,
            `is_draft`         TINYINT(1)      NOT NULL DEFAULT 0,
            `is_prerelease`    TINYINT(1)      NOT NULL DEFAULT 0,
            `created_by`       INT UNSIGNED    DEFAULT 0,
            `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `published_at`     DATETIME        DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_repo_releases_tag` (`repo_id`, `tag_name`),
            CONSTRAINT `fk_repo_releases_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `file_downloads` (
            `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `title`          VARCHAR(255)    DEFAULT NULL,
            `original_name`  VARCHAR(500)    NOT NULL,
            `file_path`      VARCHAR(1000)   NOT NULL,
            `file_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `mime_type`      VARCHAR(150)    DEFAULT 'application/octet-stream',
            `short_code`     VARCHAR(32)     NOT NULL,
            `password_hash`  VARCHAR(255)    DEFAULT NULL,
            `max_downloads`  INT UNSIGNED    DEFAULT NULL,
            `download_count` INT UNSIGNED    NOT NULL DEFAULT 0,
            `expires_at`     DATETIME        DEFAULT NULL,
            `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
            `created_by`     INT UNSIGNED    DEFAULT 0,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_file_downloads_code` (`short_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `pull_requests` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `repo_id`        INT UNSIGNED    NOT NULL,
            `number`         INT UNSIGNED    NOT NULL,
            `title`          VARCHAR(255)    NOT NULL,
            `body`           MEDIUMTEXT      DEFAULT NULL,
            `source_branch`  VARCHAR(255)    NOT NULL,
            `target_branch`  VARCHAR(255)    NOT NULL,
            `status`         ENUM('open','merged','closed') NOT NULL DEFAULT 'open',
            `created_by`     INT UNSIGNED    DEFAULT 0,
            `author_name`    VARCHAR(100)    DEFAULT 'unknown',
            `merged_commit`  VARCHAR(40)     DEFAULT NULL,
            `merged_at`      DATETIME        DEFAULT NULL,
            `closed_at`      DATETIME        DEFAULT NULL,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_pull_requests_number` (`repo_id`, `number`),
            KEY `idx_pull_requests_status` (`repo_id`, `status`),
            CONSTRAINT `fk_pull_requests_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `pr_comments` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `pr_id`       BIGINT UNSIGNED NOT NULL,
            `user_id`     INT UNSIGNED    DEFAULT 0,
            `author_name` VARCHAR(100)    DEFAULT 'unknown',
            `body`        TEXT            NOT NULL,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pr_comments_pr` (`pr_id`, `created_at`),
            CONSTRAINT `fk_pr_comments_pr` FOREIGN KEY (`pr_id`) REFERENCES `pull_requests` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `pr_review_comments` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `pr_id`       BIGINT UNSIGNED NOT NULL,
            `user_id`     INT UNSIGNED    DEFAULT 0,
            `author_name` VARCHAR(100)    DEFAULT 'unknown',
            `file_path`   VARCHAR(500)    NOT NULL,
            `line_number` INT UNSIGNED    NOT NULL DEFAULT 0,
            `side`        ENUM('old','new') NOT NULL DEFAULT 'new',
            `body`        TEXT            NOT NULL,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_pr_review_comments_pr` (`pr_id`, `file_path`),
            CONSTRAINT `fk_pr_review_comments_pr` FOREIGN KEY (`pr_id`) REFERENCES `pull_requests` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `branch_protections` (
            `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `repo_id`            INT UNSIGNED    NOT NULL,
            `branch_name`        VARCHAR(255)    NOT NULL,
            `prevent_force_push` TINYINT(1)      NOT NULL DEFAULT 1,
            `prevent_delete`     TINYINT(1)      NOT NULL DEFAULT 1,
            `allow_admin`        TINYINT(1)      NOT NULL DEFAULT 1,
            `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_branch_protections_pair` (`repo_id`, `branch_name`),
            CONSTRAINT `fk_branch_protections_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `webhooks` (
            `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `repo_id`    INT UNSIGNED    NOT NULL,
            `url`        VARCHAR(500)    NOT NULL,
            `secret`     VARCHAR(255)    DEFAULT NULL,
            `events`     VARCHAR(255)    NOT NULL DEFAULT 'push',
            `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_webhooks_repo` (`repo_id`),
            CONSTRAINT `fk_webhooks_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `webhook_id`    INT UNSIGNED    NOT NULL,
            `event`         VARCHAR(50)     NOT NULL,
            `payload`       MEDIUMTEXT      NOT NULL,
            `response_code` SMALLINT UNSIGNED DEFAULT NULL,
            `success`       TINYINT(1)      DEFAULT NULL,
            `duration_ms`   INT UNSIGNED    DEFAULT NULL,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_webhook_deliveries_hook` (`webhook_id`, `created_at`),
            CONSTRAINT `fk_webhook_deliveries_hook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `api_tokens` (
            `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `user_id`      INT UNSIGNED    NOT NULL DEFAULT 0,
            `name`         VARCHAR(100)    NOT NULL,
            `token_hash`   CHAR(64)        NOT NULL,
            `token_prefix` VARCHAR(12)     NOT NULL DEFAULT '',
            `scopes`       VARCHAR(100)    NOT NULL DEFAULT 'read',
            `last_used_at` DATETIME        DEFAULT NULL,
            `revoked_at`   DATETIME        DEFAULT NULL,
            `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_api_tokens_hash` (`token_hash`),
            KEY `idx_api_tokens_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $ddl) {
        $pdo->exec($ddl);
    }

    // ── Columns on repositories (guarded — no ADD COLUMN IF NOT EXISTS) ─
    $existing = $pdo->query('SHOW COLUMNS FROM `repositories`')->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_map('strtolower', $existing);

    $additions = [
        'owner_user_id'  => "ALTER TABLE `repositories` ADD COLUMN `owner_user_id` INT UNSIGNED DEFAULT NULL AFTER `topics`",
        'forked_from_id' => "ALTER TABLE `repositories` ADD COLUMN `forked_from_id` INT UNSIGNED DEFAULT NULL AFTER `owner_user_id`",
    ];

    foreach ($additions as $column => $ddl) {
        if (! in_array($column, $existing, true)) {
            $pdo->exec($ddl);
        }
    }

    // Legacy branch_protections installs may predate the extended rule set.
    $protCols = array_map(
        'strtolower',
        $pdo->query('SHOW COLUMNS FROM `branch_protections`')->fetchAll(PDO::FETCH_COLUMN),
    );

    foreach ([
        'prevent_force_push' => "ALTER TABLE `branch_protections` ADD COLUMN `prevent_force_push` TINYINT(1) NOT NULL DEFAULT 1 AFTER `branch_name`",
        'prevent_delete'     => "ALTER TABLE `branch_protections` ADD COLUMN `prevent_delete` TINYINT(1) NOT NULL DEFAULT 1 AFTER `prevent_force_push`",
        'allow_admin'        => "ALTER TABLE `branch_protections` ADD COLUMN `allow_admin` TINYINT(1) NOT NULL DEFAULT 1 AFTER `prevent_delete`",
    ] as $column => $ddl) {
        if (! in_array($column, $protCols, true)) {
            $pdo->exec($ddl);
        }
    }

    // Indexes are best-effort: duplicates must not abort the migration.
    foreach ([
        "ALTER TABLE `repositories` ADD KEY `idx_repositories_owner_user` (`owner_user_id`)",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (PDOException) { /* already exists */ }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
};
