<?php

declare(strict_types=1);

/**
 * Migration: release_assets — binary attachments for releases.
 *
 * Adds a dedicated table for release assets with server-computed sha256
 * checksums, MIME sniffing, and per-asset download counters. Storage
 * layout: storage/downloads/releases/{repo_id}/{release_id}/{asset_id}_{name}
 */

return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS `release_assets` (
        `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        `release_id`     BIGINT UNSIGNED NOT NULL,
        `repo_id`        INT UNSIGNED    NOT NULL,
        `uploader_id`    INT UNSIGNED    DEFAULT NULL,
        `name`           VARCHAR(255)    NOT NULL,
        `storage_path`   VARCHAR(512)    NOT NULL,
        `size_bytes`     BIGINT UNSIGNED NOT NULL,
        `sha256`         CHAR(64)        NOT NULL,
        `mime`           VARCHAR(120)    NOT NULL DEFAULT \'application/octet-stream\',
        `download_count` INT UNSIGNED    NOT NULL DEFAULT 0,
        `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_release_asset_name` (`release_id`, `name`),
        KEY `idx_release_assets_repo` (`repo_id`),
        CONSTRAINT `fk_release_assets_release`
            FOREIGN KEY (`release_id`)
            REFERENCES `repo_releases` (`id`)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
