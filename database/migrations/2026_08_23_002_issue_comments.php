<?php
declare(strict_types=1);

/**
 * Issue enhancements:
 *  - bug_comments: threaded conversation on bug reports/issues
 *
 * Idempotent: safe to run multiple times.
 */

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bug_comments` (
        `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bug_id`      INT UNSIGNED    NOT NULL,
        `user_id`     INT UNSIGNED    DEFAULT 0,
        `author_name` VARCHAR(100)    DEFAULT 'anonymous',
        `body`        TEXT            NOT NULL,
        `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_bug_comments_bug` (`bug_id`, `created_at`),
        CONSTRAINT `fk_bug_comments_bug` FOREIGN KEY (`bug_id`)
            REFERENCES `bug_reports` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
