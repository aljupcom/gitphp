<?php

declare(strict_types=1);

/**
 * Migration: tag protection support.
 *
 * Extends branch_protections with a ref_pattern column so a single rule
 * can match either an exact branch name (existing behavior) or a wildcard
 * pattern against both refs/heads/* and refs/tags/* — enabling tag
 * protection (preventing tag deletion / re-pointing) in the pre-receive
 * hook. Existing rows are backfilled with their exact branch name so
 * current behavior is unchanged.
 */

return static function (PDO $pdo): void {
    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branch_protections' AND COLUMN_NAME = 'ref_pattern'"
    )->fetchColumn();

    if ((int) $colExists === 0) {
        $pdo->exec('ALTER TABLE `branch_protections`
                    ADD COLUMN `ref_pattern` VARCHAR(255) DEFAULT NULL AFTER `branch_name`');
    }

    // Backfill: exact-name pattern for every existing rule.
    $pdo->exec(
        "UPDATE `branch_protections` SET `ref_pattern` = `branch_name` WHERE `ref_pattern` IS NULL OR `ref_pattern` = ''"
    );
};
