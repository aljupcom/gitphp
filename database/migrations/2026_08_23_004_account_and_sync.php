<?php
declare(strict_types=1);

/**
 * Account settings, personal dashboard and sync bookkeeping:
 *  - users.bio / users.location / users.website : profile fields
 *  - repositories.last_synced_at               : upstream mirror sync time
 *  - sync_log                                  : per-repo sync attempt history
 *
 * Idempotent: safe to run multiple times.
 */

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sync_log` (
        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `repo_id`    INT UNSIGNED    NOT NULL,
        `status`     ENUM('started','success','failed') NOT NULL DEFAULT 'started',
        `message`    VARCHAR(500)    DEFAULT NULL,
        `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_sync_log_repo` (`repo_id`, `created_at`),
        CONSTRAINT `fk_sync_log_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $usersCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN));
    foreach ([
        'bio'      => "ALTER TABLE `users` ADD COLUMN `bio` VARCHAR(500) DEFAULT NULL",
        'location' => "ALTER TABLE `users` ADD COLUMN `location` VARCHAR(255) DEFAULT NULL",
        'website'  => "ALTER TABLE `users` ADD COLUMN `website` VARCHAR(500) DEFAULT NULL",
    ] as $col => $ddl) {
        if (! in_array($col, $usersCols, true)) $pdo->exec($ddl);
    }

    $repoCols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `repositories`')->fetchAll(PDO::FETCH_COLUMN));
    if (! in_array('last_synced_at', $repoCols, true)) {
        $pdo->exec("ALTER TABLE `repositories` ADD COLUMN `last_synced_at` DATETIME DEFAULT NULL AFTER `source_url`");
    }
};
