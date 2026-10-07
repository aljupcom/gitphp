<?php

declare(strict_types=1);

namespace App\Setup;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Shared installation engine used by both the web installer
 * (public_html/install.php) and the CLI installer (bin/install.php).
 *
 * It performs: requirement checks, database creation + schema import,
 * idempotent post-schema migrations, owner password provisioning,
 * .env generation and install locking.
 */
final class Installer
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /** Whether the application has already been installed. */
    public static function isInstalled(string $basePath): bool
    {
        $basePath = rtrim($basePath, '/\\');

        if (file_exists($basePath . '/storage/installed.lock')) {
            return true;
        }

        // Installs created before the lock file existed are still locked
        // once their .env carries the APP_INSTALLED marker.
        $envFile = $basePath . '/.env';
        if (is_file($envFile)) {
            $contents = (string) file_get_contents($envFile);
            if (preg_match('/^APP_INSTALLED\s*=\s*1\s*$/m', $contents)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Static/server requirement checks.
     * @return array<int, array{label:string, ok:bool, hint:string}>
     */
    public function checkRequirements(): array
    {
        $checks   = [];
        $checks[] = [
            'label' => 'PHP 8.1 or newer',
            'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
            'hint'  => 'Current version: ' . PHP_VERSION,
        ];

        foreach ([
            'pdo'       => 'PDO extension',
            'pdo_mysql' => 'PDO MySQL driver',
            'mbstring'  => 'Multibyte string extension',
            'fileinfo'  => 'Fileinfo extension',
            'openssl'   => 'OpenSSL extension',
            'curl'      => 'cURL extension (repository import)',
        ] as $ext => $label) {
            $checks[] = [
                'label' => $label,
                'ok'    => extension_loaded($ext),
                'hint'  => 'php-' . $ext,
            ];
        }

        $checks[] = [
            'label' => 'Composer dependencies (vendor/)',
            'ok'    => file_exists($this->basePath . '/vendor/autoload.php'),
            'hint'  => 'Run: composer install',
        ];

        $gitPath = $this->findGit();
        $checks[] = [
            'label' => 'Git binary on PATH',
            'ok'    => $gitPath !== null,
            'hint'  => $gitPath ?? 'Install git and ensure it is on the PATH',
        ];

        $storageWritable = is_dir($this->basePath . '/storage')
            || mkdir($this->basePath . '/storage', 0755, true);
        $checks[] = [
            'label' => 'storage/ directory writable',
            'ok'    => (bool) $storageWritable && is_writable($this->basePath . '/storage'),
            'hint'  => 'chmod 755 storage/ (or 775 depending on server user)',
        ];

        $checks[] = [
            'label' => 'Project root writable (for .env)',
            'ok'    => is_writable($this->basePath),
            'hint'  => 'The installer must be able to create .env in the project root',
        ];

        return $checks;
    }

    /** @return array<int, array{label:string, ok:bool, hint:string}> */
    public function checkForm(array $in): array
    {
        $errors = [];

        foreach (['db_host', 'db_name', 'db_user', 'app_name', 'app_url', 'owner_name', 'owner_pass'] as $k) {
            if (trim((string) ($in[$k] ?? '')) === '') {
                $errors[] = 'The field "' . $k . '" is required.';
            }
        }

        if ((string) ($in['owner_pass'] ?? '') !== (string) ($in['owner_pass2'] ?? '')) {
            $errors[] = 'Owner password and confirmation do not match.';
        } elseif (strlen((string) ($in['owner_pass'] ?? '')) < 8) {
            $errors[] = 'Owner password must be at least 8 characters.';
        }

        $owner = trim((string) ($in['owner_name'] ?? ''));
        if ($owner !== '' && ! preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $owner)) {
            $errors[] = 'Owner username may only contain letters, digits, dots, hyphens and underscores.';
        }

        $url = trim((string) ($in['app_url'] ?? ''));
        if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Application URL must be a valid absolute URL.';
        }

        return $errors;
    }

    /** Attempt a database connection; returns an error message or null on success. */
    public function testConnection(string $host, int $port, string $name, string $user, string $pass): ?string
    {
        try {
            $this->connect($host, $port, $name, $user, $pass);
            return null;
        } catch (PDOException $e) {
            // 1049 = unknown database: server is reachable and the installer
            // creates the database itself, so this is not fatal here.
            if ((string) ($e->errorInfo[1] ?? '') === '1049' || str_contains($e->getMessage(), '1049')) {
                return null;
            }
            return $e->getMessage();
        }
    }

    /**
     * Run the full installation.
     *
     * @param array<string, mixed> $in Normalized form/config input.
     * @return array{ok: bool, error?: string, log: list<string>}
     */
    public function install(array $in): array
    {
        $log = [];

        try {
            $host = (string) $in['db_host'];
            $port = (int) ($in['db_port'] ?? 3306);
            $name = (string) $in['db_name'];
            $user = (string) $in['db_user'];
            $pass = (string) ($in['db_pass'] ?? '');

            // 1. Connect (create database when the account has privileges)
            try {
                $pdo = $this->connect($host, $port, $name, $user, $pass);
                $log[] = "Connected to MySQL at {$host}:{$port}, database `{$name}` selected.";
            } catch (PDOException) {
                $server = $this->connect($host, $port, '', $user, $pass);
                $server->exec(
                    "CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $name) . "`"
                    . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                );
                $log[] = "Database `{$name}` created.";
                $pdo = $this->connect($host, $port, $name, $user, $pass);
            }

            // 2. Schema
            $schemaFile = $this->basePath . '/database/schema.sql';
            if (! is_file($schemaFile)) {
                throw new RuntimeException('database/schema.sql was not found.');
            }
            $pdo->exec((string) file_get_contents($schemaFile));
            $log[] = 'Schema imported (all tables created).';

            // 3. Idempotent migrations for pre-existing databases
            if ($pdo->query("SHOW COLUMNS FROM `repositories` LIKE 'source_url'")->fetch() === false) {
                $pdo->exec("ALTER TABLE `repositories` ADD COLUMN `source_url` VARCHAR(500) DEFAULT NULL AFTER `default_branch`");
                $log[] = 'Migrated: repositories.source_url added.';
            }

            // 4. Owner password (Argon2id when available)
            $hash = password_hash(
                (string) $in['owner_pass'],
                defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
                defined('PASSWORD_ARGON2ID') ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3] : [],
            );
            $stmt = $pdo->prepare('REPLACE INTO `settings` (`setting_key`, `setting_value`) VALUES (:key, :value)');
            $stmt->execute(['key' => 'owner_password_hash', 'value' => $hash]);
            $log[] = 'Owner password hashed and stored.';

            // 5. Directory layout
            $reposPath = rtrim((string) ($in['repos_path'] ?? $this->defaultReposPath()), '/\\');
            if (! is_dir($reposPath) && ! mkdir($reposPath, 0755, true) && ! is_dir($reposPath)) {
                throw new RuntimeException("Unable to create repositories directory: {$reposPath}");
            }
            foreach (['cache', 'cache/twig', 'downloads', 'tmp', 'sessions'] as $dir) {
                $path = $this->basePath . '/storage/' . $dir;
                if (! is_dir($path)) mkdir($path, 0755, true);
            }
            $log[] = "Repositories directory ready at {$reposPath}.";

            // 6. Write .env
            $env = [
                'APP_NAME'             => (string) $in['app_name'],
                'APP_URL'              => rtrim((string) $in['app_url'], '/'),
                'APP_OWNER'            => (string) $in['owner_name'],
                'APP_THEME'            => (string) ($in['app_theme'] ?? 'github') ?: 'github',
                'APP_TIMEZONE'         => (string) ($in['timezone'] ?? 'UTC') ?: 'UTC',
                'APP_DEBUG'            => '0',
                'APP_INSTALLED'        => '1',
                'DB_HOST'              => $host,
                'DB_PORT'              => (string) $port,
                'DB_NAME'              => $name,
                'DB_USER'              => $user,
                'DB_PASS'              => $pass,
                'REPOS_PATH'           => $reposPath,
            ];
            if (! empty($in['authorized_keys_path'])) {
                $env['AUTHORIZED_KEYS_PATH'] = (string) $in['authorized_keys_path'];
            }
            $this->writeEnv($env);
            $log[] = '.env written with APP_INSTALLED=1.';

            // 7. Lock the installer
            $lock = $this->basePath . '/storage/installed.lock';
            file_put_contents(
                $lock,
                'installed_at=' . gmdate('c') . "\n" . 'token=' . bin2hex(random_bytes(16)) . "\n",
            );
            $log[] = 'Installer locked (storage/installed.lock created).';

            return ['ok' => true, 'log' => $log];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'log' => $log];
        }
    }

    /** Locate the git binary, or null when unavailable. */
    private function findGit(): ?string
    {
        if (function_exists('exec')) {
            @$out = [];
            $code = 1;
            @exec('git --version 2>/dev/null', $out, $code);
            if ($code === 0) return 'git';
        }
        foreach (['/usr/bin/git', '/usr/local/bin/git', 'C:\\Program Files\\Git\\cmd\\git.exe'] as $p) {
            if (is_executable($p) || (str_starts_with($p, 'C:') && is_file($p))) return $p;
        }
        return null;
    }

    /** Create a PDO connection; empty $name connects to the server only. */
    private function connect(string $host, int $port, string $name, string $user, string $pass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($name !== '') {
            $dsn .= ";dbname={$name}";
        }

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    private function defaultReposPath(): string
    {
        return getenv('REPOS_PATH') ?: ($this->basePath . DIRECTORY_SEPARATOR . 'repos');
    }

    /** @param array<string, string> $values */
    private function writeEnv(array $values): void
    {
        $lines = ['# Generated by GitPHP installer on ' . gmdate('c')];

        foreach ($values as $key => $value) {
            $value = str_replace(["\r", "\n"], ' ', (string) $value);
            if ($value !== '' && preg_match('/[\s#"\']/', $value)) {
                $value = '"' . addslashes($value) . '"';
            }
            $lines[] = "{$key}={$value}";
        }

        $path = $this->basePath . '/.env';
        if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Could not write {$path} — check directory permissions.");
        }
        @chmod($path, 0640);
    }
}
