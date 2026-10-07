<?php

declare(strict_types=1);

/**
 * GitPHP — CLI installer (VPS / SSH deployments).
 *
 * Interactive mode:
 *   php bin/install.php
 *
 * Non-interactive mode (automation / provisioning scripts):
 *   php bin/install.php \
 *       --db-host=127.0.0.1 --db-port=3306 \
 *       --db-name=gitphp --db-user=gitphp --db-pass=secret \
 *       --app-name=GitPHP --app-url=https://git.example.com \
 *       --app-owner=admin --timezone=UTC \
 *       --owner-pass='S3cure-Pass!' \
 *       [--repos-path=/srv/gitphp/repos] \
 *       [--authorized-keys-path=/home/git/.ssh/authorized_keys]
 */

use App\Setup\Installer;

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
$installer = new Installer($basePath);

echo "╔══════════════════════════════════════════╗\n";
echo "║        GitPHP — Installation             ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

if (Installer::isInstalled($basePath)) {
    echo "GitPHP is already installed (storage/installed.lock exists).\n";
    echo "Delete storage/installed.lock and APP_INSTALLED from .env to reinstall.\n";
    exit(1);
}

// ── Requirements ──────────────────────────────────────────────────────
echo "[0/5] Checking server requirements...\n";

$failed = false;
foreach ($installer->checkRequirements() as $check) {
    printf("      %s %-38s %s\n", $check['ok'] ? '✓' : '✗', $check['label'], $check['ok'] ? '' : "({$check['hint']})");
    if (! $check['ok']) {
        $failed = true;
    }
}

if ($failed) {
    echo "\n      ✗ Fix the failed requirements and re-run.\n";
    exit(1);
}
echo "\n";

// ── Gather configuration: flags win, then existing .env, then prompts ─
$args = [];
foreach (array_slice($GLOBALS['argv'] ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)=(.*)$/i', $arg, $m)) {
        $args[str_replace('-', '_', strtolower($m[1]))] = $m[2];
    }
}

if (file_exists($basePath . '/.env')) {
    foreach (file($basePath . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $args['_env:' . trim($k)] = trim(trim($v), "\"'");
    }
}

/** Pull a config value: flag > previous .env > prompt/default. */
function cfg(array $args, string $flag, string $envKey, string $prompt, string $default, bool $secret = false): string
{
    if (isset($args[$flag]) && $args[$flag] !== '') return $args[$flag];
    if (isset($args['_env:' . $envKey]) && $args['_env:' . $envKey] !== '') return $args['_env:' . $envKey];

    if ($default !== '' || ! $secret) {
        echo $prompt . ($default !== '' ? " [{$default}]" : '') . ': ';
        $answer = trim((string) fgets(STDIN));
    } else {
        echo $prompt . ': ';
        hideInput();
        $answer = trim((string) fgets(STDIN));
        restoreInput();
        echo "\n";
    }

    return $answer !== '' ? $answer : $default;
}

/** Disable terminal echo for secret input (Unix only). */
function hideInput(): void
{
    if (DIRECTORY_SEPARATOR === '/' && function_exists('exec')) {
        @exec('stty -echo 2>/dev/null');
    }
}

function restoreInput(): void
{
    if (DIRECTORY_SEPARATOR === '/' && function_exists('exec')) {
        @exec('stty echo 2>/dev/null');
    }
}

$nonInteractive = isset($args['owner_pass']);

$config = [
    'db_host'     => cfg($args, 'db_host',     'DB_HOST',     'Database host', '127.0.0.1'),
    'db_port'     => cfg($args, 'db_port',     'DB_PORT',     'Database port', '3306'),
    'db_name'     => cfg($args, 'db_name',     'DB_NAME',     'Database name', 'gitphp'),
    'db_user'     => cfg($args, 'db_user',     'DB_USER',     'Database user', 'gitphp'),
    'db_pass'     => cfg($args, 'db_pass',     'DB_PASS',     'Database password', '', true),
    'app_name'    => cfg($args, 'app_name',    'APP_NAME',    'Application name', 'GitPHP'),
    'app_url'     => cfg($args, 'app_url',     'APP_URL',     'Application URL', 'http://localhost'),
    'owner_name'  => cfg($args, 'app_owner',   'APP_OWNER',   'Owner username', 'admin'),
    'timezone'    => cfg($args, 'timezone',    'APP_TIMEZONE','Timezone', 'UTC'),
    'owner_pass'  => cfg($args, 'owner_pass',  '',            'Owner password (min 8 chars)', '', true),
    'repos_path'  => cfg($args, 'repos_path',  'REPOS_PATH',  'Repositories path', $basePath . '/repos'),
];

$nonInteractive = isset($args['owner_pass']);

if ($nonInteractive && strlen($config['owner_pass']) < 8) {
    echo "      ✗ --owner-pass must be at least 8 characters.\n";
    exit(1);
}

if (! $nonInteractive && strlen($config['owner_pass']) < 8) {
    echo "      ✗ Owner password must be at least 8 characters.\n";
    exit(1);
}

$config['app_theme'] = $args['app_theme'] ?? ($args['_env:APP_THEME'] ?? 'github');

echo "\n[1/5] Connecting to MySQL at {$config['db_host']}:{$config['db_port']}...\n";

$dbError = $installer->testConnection(
    $config['db_host'],
    (int) $config['db_port'],
    $config['db_name'],
    $config['db_user'],
    $config['db_pass'],
);
if ($dbError !== null) {
    echo "      ✗ Connection failed: {$dbError}\n";
    exit(1);
}
echo "      ✓ Connected.\n";

echo "[2/5] Importing schema + migrations...\n";
$result = $installer->install($config);

if (! $result['ok']) {
    echo "      ✗ Installation failed: {$result['error']}\n";
    foreach ($result['log'] as $line) {
        echo "        · {$line}\n";
    }
    exit(1);
}

foreach ($result['log'] as $line) {
    echo "      ✓ {$line}\n";
}

echo "\n╔══════════════════════════════════════════╗\n";
echo "║        Installation Complete!            ║\n";
echo "╚══════════════════════════════════════════╝\n\n";

echo "App Name : {$config['app_name']}\n";
echo "App URL  : {$config['app_url']}\n";
echo "Database : {$config['db_name']}\n";
echo "Repos    : {$config['repos_path']}\n\n";

echo "Next steps:\n";
echo "  1. Point your web server document root to public_html/\n";
echo "  2. Visit {$config['app_url']}/login\n";
echo "  3. Log in with the owner username + password you set above\n";
