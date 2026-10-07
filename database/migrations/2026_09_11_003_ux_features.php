<?php

declare(strict_types=1);

/**
 * Migration: UX phase — issue labels/milestones/assignees, PR multi-reviewers
 * with squash/rebase merge strategies, and wiki revision history.
 *
 * Idempotent: every DDL statement is guarded by an information_schema check.
 */

return static function (PDO $pdo): void {
    $schema = 'SELECT COUNT(*) FROM information_schema.%s
               WHERE TABLE_SCHEMA = DATABASE() AND %s';

    $tableExists = static function (string $table) use ($pdo): bool {
        return (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
        )->fetchColumn();
    };

    $columnExists = static function (string $table, string $column) use ($pdo): bool {
        return (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$column}'"
        )->fetchColumn();
    };

    // ── 1. Issue labels ────────────────────────────────────────────────
    if (! $tableExists('issue_labels')) {
        $pdo->exec('CREATE TABLE `issue_labels` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `repo_id`    INT UNSIGNED NOT NULL,
            `name`       VARCHAR(50)  NOT NULL,
            `color`      CHAR(7)      NOT NULL DEFAULT \'#8b949e\',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_issue_label_repo_name` (`repo_id`, `name`),
            KEY `idx_issue_labels_repo` (`repo_id`),
            CONSTRAINT `fk_issue_labels_repo`
                FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    if (! $tableExists('issue_label_map')) {
        $pdo->exec('CREATE TABLE `issue_label_map` (
            `issue_id` INT UNSIGNED NOT NULL,
            `label_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`issue_id`, `label_id`),
            KEY `idx_ilm_label` (`label_id`),
            CONSTRAINT `fk_ilm_issue`
                FOREIGN KEY (`issue_id`) REFERENCES `bug_reports` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ilm_label`
                FOREIGN KEY (`label_id`) REFERENCES `issue_labels` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    if (! $tableExists('issue_milestones')) {
        $pdo->exec('CREATE TABLE `issue_milestones` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `repo_id`     INT UNSIGNED NOT NULL,
            `title`       VARCHAR(120) NOT NULL,
            `description` TEXT         DEFAULT NULL,
            `due_date`    DATE         DEFAULT NULL,
            `is_closed`   TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_issue_milestones_repo` (`repo_id`),
            CONSTRAINT `fk_issue_milestones_repo`
                FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    // bug_reports: milestone + assignee columns
    if (! $columnExists('bug_reports', 'milestone_id')) {
        $pdo->exec('ALTER TABLE `bug_reports` ADD COLUMN `milestone_id` INT UNSIGNED DEFAULT NULL AFTER `user_id`');
        $pdo->exec('ALTER TABLE `bug_reports`
                    ADD CONSTRAINT `fk_bug_reports_milestone`
                    FOREIGN KEY (`milestone_id`) REFERENCES `issue_milestones` (`id`) ON DELETE SET NULL');
    }
    if (! $columnExists('bug_reports', 'assigned_to')) {
        $pdo->exec('ALTER TABLE `bug_reports` ADD COLUMN `assigned_to` INT UNSIGNED DEFAULT NULL AFTER `milestone_id`');
        $pdo->exec('ALTER TABLE `bug_reports`
                    ADD CONSTRAINT `fk_bug_reports_assignee`
                    FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL');
    }

    // ── 2. PR multi-reviewers + merge strategies ─────────────────────
    if (! $tableExists('pr_reviews')) {
        $pdo->exec('CREATE TABLE `pr_reviews` (
            `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `pr_id`        BIGINT UNSIGNED NOT NULL,
            `user_id`      INT UNSIGNED NOT NULL,
            `decision`     ENUM(\'approved\',\'changes_requested\') NOT NULL,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pr_reviewer` (`pr_id`, `user_id`),
            KEY `idx_pr_reviews_pr` (`pr_id`),
            CONSTRAINT `fk_pr_reviews_pr`
                FOREIGN KEY (`pr_id`) REFERENCES `pull_requests` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    if (! $columnExists('pull_requests', 'merge_strategy')) {
        $pdo->exec('ALTER TABLE `pull_requests` ADD COLUMN `merge_strategy` ENUM(\'merge\',\'squash\',\'rebase\',\'ff\') NOT NULL DEFAULT \'merge\'');
    }
    if (! $columnExists('pull_requests', 'is_draft')) {
        $pdo->exec('ALTER TABLE `pull_requests` ADD COLUMN `is_draft` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`');
    }

    // ── 3. Wiki revision history ─────────────────────────────────────
    if (! $tableExists('wiki_revisions')) {
        $pdo->exec('CREATE TABLE `wiki_revisions` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `page_id`    INT UNSIGNED NOT NULL,
            `content`    MEDIUMTEXT   NOT NULL,
            `edited_by`  INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_wiki_revisions_page` (`page_id`, `created_at`),
            CONSTRAINT `fk_wiki_revisions_page`
                FOREIGN KEY (`page_id`) REFERENCES `wiki_pages` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
