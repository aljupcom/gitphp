<?php

declare(strict_types=1);

/**
 * GitPHP — lightweight migration runner.
 *
 * Applies every PHP migration in database/migrations/ exactly once, tracking
 * applied files in the `schema_migrations` table. Migrations return a
 * callable(PDO): void and must be idempotent.
 *
 * Usage:
 *   php bin/migrate.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = \App\App::boot(dirname(__DIR__));
$pdo = $app->db()->connection();

$pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `migration`  VARCHAR(255) NOT NULL,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$migrationsDir = dirname(__DIR__) . '/database/migrations';
$files         = glob($migrationsDir . '/*.php') ?: [];
sort($files);

$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);

    $stmt = $pdo->prepare('SELECT 1 FROM `schema_migrations` WHERE `migration` = ?');
    $stmt->execute([$name]);

    if ($stmt->fetchColumn() !== false) {
        echo "skip     {$name} (already applied)\n";
        $skipped++;
        continue;
    }

    $migration = require $file;

    if (! is_callable($migration)) {
        echo "ERROR    {$name}: migration file must return a callable(PDO)\n";
        exit(1);
    }

    $migration($pdo);

    $insert = $pdo->prepare('INSERT INTO `schema_migrations` (`migration`) VALUES (?)');
    $insert->execute([$name]);

    echo "applied  {$name}\n";
    $applied++;
}

echo "\nDone: {$applied} applied, {$skipped} already up to date.\n";
