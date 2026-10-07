-- ═══════════════════════════════════════════════════════════════════
-- Migration: download_folders — Hierarchical folder system for
--            the Download Center (parent/child folders, system flag)
-- ═══════════════════════════════════════════════════════════════════

-- 1. Create the folders table
CREATE TABLE IF NOT EXISTS `download_folders` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)  NOT NULL,
    `slug`        VARCHAR(120)  NOT NULL,
    `parent_id`   INT UNSIGNED  DEFAULT NULL,
    `description` VARCHAR(255)  DEFAULT NULL,
    `is_system`   TINYINT(1)    NOT NULL DEFAULT 0,
    `sort_order`  SMALLINT      NOT NULL DEFAULT 0,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_folders_slug` (`slug`),
    KEY `idx_folders_parent` (`parent_id`),
    CONSTRAINT `fk_folders_parent`
        FOREIGN KEY (`parent_id`)
        REFERENCES `download_folders` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add folder_id to file_downloads if not exists
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'file_downloads'
      AND COLUMN_NAME  = 'folder_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `file_downloads` ADD COLUMN `folder_id` INT UNSIGNED DEFAULT NULL AFTER `title`',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add FK if not present
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'file_downloads'
      AND CONSTRAINT_NAME = 'fk_downloads_folder'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql2 = IF(@fk_exists = 0,
    'ALTER TABLE `file_downloads` ADD CONSTRAINT `fk_downloads_folder` FOREIGN KEY (`folder_id`) REFERENCES `download_folders` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- 4. Seed system folders
INSERT IGNORE INTO `download_folders` (`name`, `slug`, `is_system`, `sort_order`)
VALUES
    ('Profile Pictures', 'profiles', 1, 10),
    ('Releases',         'releases', 0, 20),
    ('Assets',           'assets',   0, 30);
