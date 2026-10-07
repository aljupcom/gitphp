<?php

declare(strict_types=1);

/**
 * Migration 001 — multi-user accounts, bug reports, wiki, and social features.
 *
 * Applied by `php bin/migrate.php` for pre-existing installations. Fresh
 * installs get the same structure from database/schema.sql directly.
 *
 * Every statement is idempotent so the migration is safe to re-run.
 */

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `username`           VARCHAR(50)     NOT NULL,
        `email`              VARCHAR(255)    NOT NULL,
        `password_hash`      VARCHAR(255)    NOT NULL,
        `role`               ENUM('user','admin') NOT NULL DEFAULT 'user',
        `email_verified_at`  DATETIME        DEFAULT NULL,
        `verification_token` VARCHAR(64)     DEFAULT NULL,
        `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_users_username` (`username`),
        UNIQUE KEY `idx_users_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `bug_reports` (
        `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `repo_id`             INT UNSIGNED    NOT NULL,
        `user_id`             INT UNSIGNED    DEFAULT NULL,
        `title`               VARCHAR(255)    NOT NULL,
        `description`         TEXT            NOT NULL,
        `environment`         TEXT            DEFAULT NULL,
        `steps_to_reproduce`  TEXT            DEFAULT NULL,
        `status`              ENUM('open','resolved','closed') NOT NULL DEFAULT 'open',
        `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bug_reports_repo` (`repo_id`),
        KEY `idx_bug_reports_status` (`status`),
        CONSTRAINT `fk_bug_reports_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_bug_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `wiki_pages` (
        `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `repo_id`     INT UNSIGNED    NOT NULL,
        `slug`        VARCHAR(255)    NOT NULL,
        `title`       VARCHAR(255)    NOT NULL,
        `content`     MEDIUMTEXT      DEFAULT NULL,
        `updated_by`  INT UNSIGNED    DEFAULT NULL,
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_wiki_pages_repo_slug` (`repo_id`, `slug`),
        KEY `idx_wiki_pages_updated` (`updated_at`),
        CONSTRAINT `fk_wiki_pages_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_wiki_pages_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `repo_likes` (
        `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `repo_id`    INT UNSIGNED     NOT NULL,
        `user_id`    INT UNSIGNED     NOT NULL,
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_repo_likes_pair` (`repo_id`, `user_id`),
        CONSTRAINT `fk_repo_likes_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_repo_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `repo_subscriptions` (
        `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `repo_id`    INT UNSIGNED     NOT NULL,
        `user_id`    INT UNSIGNED     NOT NULL,
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_repo_subscriptions_pair` (`repo_id`, `user_id`),
        CONSTRAINT `fk_repo_subscriptions_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_repo_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED     NOT NULL,
        `repo_id`    INT UNSIGNED     DEFAULT NULL,
        `type`       VARCHAR(30)      NOT NULL DEFAULT 'push',
        `message`    VARCHAR(500)     NOT NULL,
        `link`       VARCHAR(500)     DEFAULT NULL,
        `is_read`    TINYINT(1)       NOT NULL DEFAULT 0,
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_notifications_user` (`user_id`, `is_read`),
        KEY `idx_notifications_repo` (`repo_id`),
        CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_notifications_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `repo_collaborators` (
        `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `repo_id`    INT UNSIGNED     NOT NULL,
        `user_id`    INT UNSIGNED     NOT NULL,
        `role`       ENUM('read','write') NOT NULL DEFAULT 'read',
        `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_repo_collaborators_pair` (`repo_id`, `user_id`),
        CONSTRAINT `fk_repo_collaborators_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_repo_collaborators_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Extra social metadata columns on `repositories`. MySQL does not support
    // ADD COLUMN IF NOT EXISTS, so guard each with information_schema.
    $existing = $pdo->query('SHOW COLUMNS FROM `repositories`')->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_map('strtolower', $existing);

    $additions = [
        'stars_count' => "ALTER TABLE `repositories` ADD COLUMN `stars_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `source_url`",
        'homepage'    => "ALTER TABLE `repositories` ADD COLUMN `homepage` VARCHAR(500) DEFAULT NULL AFTER `stars_count`",
        'topics'      => "ALTER TABLE `repositories` ADD COLUMN `topics` VARCHAR(500) DEFAULT NULL AFTER `homepage`",
    ];

    foreach ($additions as $column => $ddl) {
        if (! in_array($column, $existing, true)) {
            $pdo->exec($ddl);
        }
    }
};
