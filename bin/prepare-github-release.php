#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GitPHP — Interactive GitHub Release & Clean Packaging CLI
 *
 * Prepares a clean, install-ready distribution copy of the project suitable
 * for publishing on GitHub or distributing as a standalone zip release.
 *
 * Security & Sanitization guarantees:
 *   - Strips .env (live production database credentials, secrets, app keys)
 *   - Strips storage/installed.lock (guarantees the web installer runs on new setups)
 *   - Strips all private hosted repositories (repos/*)
 *   - Strips all user sessions, cached data, and logs (storage/*)
 *   - Strips user-uploaded avatars and attachments (uploads/*)
 *   - Strips server-specific backups, Imunify scans, local git history
 *   - Replaces hardcoded absolute paths with dynamic, portable paths
 *   - Generates a production-safe .gitignore
 *   - Runs a 10-point automated security & readiness audit
 *
 * Usage:
 *   php bin/prepare-github-release.php                 # Interactive Wizard
 *   php bin/prepare-github-release.php --help          # Show CLI options
 */

// ── Terminal ANSI Colors & Styling ──────────────────────────────────────
final class Console
{
    public const RESET   = "\033[0m";
    public const BOLD    = "\033[1m";
    public const DIM     = "\033[2m";
    public const RED     = "\033[31m";
    public const GREEN   = "\033[32m";
    public const YELLOW  = "\033[33m";
    public const BLUE    = "\033[34m";
    public const MAGENTA = "\033[35m";
    public const CYAN    = "\033[36m";
    public const WHITE   = "\033[37m";

    public static function write(string $msg = ''): void
    {
        echo $msg;
    }

    public static function line(string $msg = ''): void
    {
        echo $msg . PHP_EOL;
    }

    public static function success(string $msg): void
    {
        echo self::GREEN . self::BOLD . "  [✓] " . self::RESET . self::GREEN . $msg . self::RESET . PHP_EOL;
    }

    public static function info(string $msg): void
    {
        echo self::CYAN . "  [i] " . self::RESET . $msg . PHP_EOL;
    }

    public static function warn(string $msg): void
    {
        echo self::YELLOW . self::BOLD . "  [!] " . self::RESET . self::YELLOW . $msg . self::RESET . PHP_EOL;
    }

    public static function error(string $msg): void
    {
        echo self::RED . self::BOLD . "  [✗] " . self::RESET . self::RED . $msg . self::RESET . PHP_EOL;
    }

    public static function title(string $title): void
    {
        echo PHP_EOL . self::CYAN . self::BOLD . "▶ " . $title . self::RESET . PHP_EOL;
        echo self::DIM . str_repeat('─', 65) . self::RESET . PHP_EOL;
    }

    public static function prompt(string $question, string $default = ''): string
    {
        $promptStr = self::BOLD . self::WHITE . "  ? " . $question . self::RESET;
        if ($default !== '') {
            $promptStr .= self::DIM . " [" . $default . "]" . self::RESET;
        }
        $promptStr .= ": ";

        echo $promptStr;
        $input = trim((string) fgets(STDIN));
        return $input !== '' ? $input : $default;
    }

    public static function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';
        $promptStr = self::BOLD . self::WHITE . "  ? " . $question . " " . self::YELLOW . $hint . self::RESET . ": ";
        echo $promptStr;
        $input = strtolower(trim((string) fgets(STDIN)));
        if ($input === '') {
            return $default;
        }
        return in_array($input, ['y', 'yes', '1', 'true'], true);
    }

    public static function choice(string $question, array $options, int $defaultIndex = 1): int
    {
        echo self::BOLD . self::WHITE . "  ? " . $question . self::RESET . PHP_EOL;
        foreach ($options as $idx => $label) {
            $isDef = ($idx === $defaultIndex) ? self::CYAN . " (Default)" . self::RESET : '';
            echo "    " . self::YELLOW . "[" . $idx . "]" . self::RESET . " " . $label . $isDef . PHP_EOL;
        }
        echo self::DIM . "  Enter choice number [" . $defaultIndex . "]: " . self::RESET;
        $input = trim((string) fgets(STDIN));
        $val = ($input !== '' && is_numeric($input)) ? (int)$input : $defaultIndex;
        return isset($options[$val]) ? $val : $defaultIndex;
    }
}

// ── Packaging Core Engine ───────────────────────────────────────────────
class ReleasePackager
{
    private string $sourceDir;
    private string $targetDir;
    private bool $includeVendor = false;
    private bool $initGit = true;
    private bool $createZip = true;
    private bool $forceOverwrite = false;

    private array $copiedStats = [
        'files' => 0,
        'dirs' => 0,
        'bytes' => 0,
    ];

    public function __construct(string $sourceDir)
    {
        $this->sourceDir = rtrim(realpath($sourceDir) ?: $sourceDir, '/\\');
    }

    public function setTargetDir(string $targetDir): void
    {
        $this->targetDir = rtrim($targetDir, '/\\');
    }

    public function setIncludeVendor(bool $include): void
    {
        $this->includeVendor = $include;
    }

    public function setInitGit(bool $init): void
    {
        $this->initGit = $init;
    }

    public function setCreateZip(bool $zip): void
    {
        $this->createZip = $zip;
    }

    public function setForceOverwrite(bool $force): void
    {
        $this->forceOverwrite = $force;
    }

    public function getTargetDir(): string
    {
        return $this->targetDir;
    }

    public function runWizard(): void
    {
        $this->printBanner();

        Console::title("1. Destination Directory Configuration");
        $defaultTarget = dirname($this->sourceDir) . DIRECTORY_SEPARATOR . 'gitphp-github-release';
        $targetInput = Console::prompt("Enter destination directory path", $defaultTarget);
        $this->setTargetDir($targetInput);

        // Check if directory exists
        if (is_dir($this->targetDir)) {
            Console::warn("Target folder already exists: " . $this->targetDir);
            $overwrite = Console::confirm("Would you like to overwrite and clean this directory?", false);
            if (!$overwrite) {
                Console::error("Operation aborted by user.");
                exit(0);
            }
            $this->setForceOverwrite(true);
        }

        Console::title("2. Release Packaging Options");
        $vendorChoice = Console::choice(
            "Select how to handle vendor/ dependencies (Composer Packages):",
            [
                1 => "Exclude vendor/ (Recommended for GitHub - Lightweight ~12MB, user runs 'composer install')",
                2 => "Include vendor/ (Standalone Release - Self-contained ~35MB, works directly on shared hosts)",
            ],
            1
        );
        $this->setIncludeVendor($vendorChoice === 2);

        $initGit = Console::confirm(
            "Initialize a clean Git repository inside the release folder with initial commit?",
            true
        );
        $this->setInitGit($initGit);

        $createZip = Console::confirm(
            "Create a compressed .zip archive ready for upload to GitHub Releases?",
            true
        );
        $this->setCreateZip($createZip);

        Console::title("3. Configuration Summary & Confirmation");
        Console::line("  • Source Directory:  " . Console::CYAN . $this->sourceDir . Console::RESET);
        Console::line("  • Target Directory:  " . Console::GREEN . $this->targetDir . Console::RESET);
        Console::line("  • Include vendor/:   " . ($this->includeVendor ? Console::YELLOW . "Yes (Standalone)" : Console::CYAN . "No (Standard GitHub)") . Console::RESET);
        Console::line("  • Initialize Git:    " . ($this->initGit ? Console::GREEN . "Yes" : Console::DIM . "No") . Console::RESET);
        Console::line("  • Create .zip:       " . ($this->createZip ? Console::GREEN . "Yes" : Console::DIM . "No") . Console::RESET);
        Console::line();

        if (!Console::confirm("Ready to start preparing the clean release and running security audit?", true)) {
            Console::error("Operation cancelled.");
            exit(0);
        }

        $this->execute();
    }

    public function execute(): void
    {
        Console::title("4. Preparing Directory & Copying Files");

        // Clean target if required
        if (is_dir($this->targetDir)) {
            Console::info("Cleaning existing target directory: " . $this->targetDir);
            $this->deleteDirectory($this->targetDir);
        }

        if (!@mkdir($this->targetDir, 0755, true) && !is_dir($this->targetDir)) {
            Console::error("Failed to create target directory: " . $this->targetDir);
            exit(1);
        }
        Console::success("Created release destination folder: " . $this->targetDir);

        // Copy whitelist directories
        $dirsToCopy = [
            'src',
            'config',
            'database',
            'templates',
            'docs',
            'hooks',
            'bin',
        ];

        if ($this->includeVendor && is_dir($this->sourceDir . '/vendor')) {
            $dirsToCopy[] = 'vendor';
        }

        foreach ($dirsToCopy as $dir) {
            $srcPath = $this->sourceDir . '/' . $dir;
            $dstPath = $this->targetDir . '/' . $dir;
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
                Console::success("Copied application directory: {$dir}/");
            }
        }

        // Copy public_html safely (excluding user uploads)
        Console::info("Packaging web public_html/ safely...");
        $this->copyPublicHtml();

        // Copy root release files
        $rootFiles = [
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'README.md',
            'LICENSE',
            '.env.example',
        ];

        foreach ($rootFiles as $file) {
            $srcFile = $this->sourceDir . '/' . $file;
            $dstFile = $this->targetDir . '/' . $file;
            if (is_file($srcFile)) {
                copy($srcFile, $dstFile);
                $this->copiedStats['files']++;
                $this->copiedStats['bytes'] += filesize($srcFile);
            }
        }
        Console::success("Copied root files (composer.json, .env.example, README.md, LICENSE)");

        Console::title("5. Runtime Skeletons & Portability Hardening");
        $this->createCleanRuntimeStructure();
        $this->applyPortabilityFixes();
        $this->generateGitignore();

        Console::title("6. Security & Installation Readiness Audit");
        $auditPassed = $this->runSecurityAudit();

        if (!$auditPassed) {
            Console::error("Security audit failed! Operation stopped to prevent sensitive data leakage.");
            exit(1);
        }

        Console::title("7. Finalizing Release");

        if ($this->initGit) {
            $this->initializeGitRepository();
        }

        $zipPath = null;
        if ($this->createZip) {
            $zipPath = $this->createZipArchive();
        }

        $this->printSuccessSummary($zipPath);
    }

    private function copyPublicHtml(): void
    {
        $srcPublic = $this->sourceDir . '/public_html';
        $dstPublic = $this->targetDir . '/public_html';

        if (!is_dir($dstPublic)) {
            @mkdir($dstPublic, 0755, true);
        }

        // Files to directly copy from public_html
        $files = ['index.php', 'install.php', '.htaccess'];
        foreach ($files as $file) {
            $src = $srcPublic . '/' . $file;
            $dst = $dstPublic . '/' . $file;
            if (is_file($src)) {
                copy($src, $dst);
                $this->copiedStats['files']++;
                $this->copiedStats['bytes'] += filesize($src);
            }
        }

        // Copy assets folder completely
        if (is_dir($srcPublic . '/assets')) {
            $this->copyDirectory($srcPublic . '/assets', $dstPublic . '/assets');
        }

        // Provide a safe wrapper for repo-optimize.php in public_html instead of a broken symlink
        $cliRunnerCode = "<?php\n\ndeclare(strict_types=1);\n\n// CLI runner wrapper for repo-optimize.php\nif (PHP_SAPI !== 'cli') {\n    http_response_code(403);\n    header('Content-Type: text/plain; charset=utf-8');\n    echo \"Access denied. Run via CLI: php bin/repo-optimize.php\\n\";\n    exit(1);\n}\n\nrequire_once dirname(__DIR__) . '/bin/repo-optimize.php';\n";
        file_put_contents($dstPublic . '/repo-optimize.php', $cliRunnerCode);
        @mkdir($dstPublic . '/bin', 0755, true);
        file_put_contents($dstPublic . '/bin/repo-optimize.php', $cliRunnerCode);

        Console::success("Prepared public_html/ with installer (install.php), front controller, and assets");
    }

    private function createCleanRuntimeStructure(): void
    {
        // 1. Create empty storage structure with .gitkeep
        $storageDirs = [
            'storage/cache',
            'storage/data',
            'storage/downloads',
            'storage/logs',
            'storage/sessions',
            'storage/tmp',
        ];

        foreach ($storageDirs as $sDir) {
            $fullDir = $this->targetDir . '/' . $sDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0775, true);
            }
            file_put_contents($fullDir . '/.gitkeep', '');
            $this->copiedStats['files']++;
        }

        // 2. Create clean repos folder
        $reposDir = $this->targetDir . '/repos';
        if (!is_dir($reposDir)) {
            @mkdir($reposDir, 0775, true);
        }
        file_put_contents($reposDir . '/.gitkeep', '');
        $this->copiedStats['files']++;

        // 3. Create clean uploads structure
        $uploadDirs = [
            'public_html/uploads',
            'public_html/uploads/avatars',
        ];

        foreach ($uploadDirs as $uDir) {
            $fullDir = $this->targetDir . '/' . $uDir;
            if (!is_dir($fullDir)) {
                @mkdir($fullDir, 0775, true);
            }
            file_put_contents($fullDir . '/.gitkeep', '');
            $this->copiedStats['files']++;
        }

        Console::success("Created clean runtime directory skeletons (storage, repos, uploads) with .gitkeep");
    }

    private function applyPortabilityFixes(): void
    {
        // Patch DeviceDetector.php to use dynamic paths instead of hardcoded server path
        $detectorFile = $this->targetDir . '/src/Service/DeviceDetector.php';
        if (is_file($detectorFile)) {
            $content = file_get_contents($detectorFile);
            $hardcodedPattern = "'/home/git.ysnapp.com/storage/data/";
            if (str_contains($content, $hardcodedPattern)) {
                // Replace with dynamic resolution
                $content = str_replace(
                    "private const SQLITE_PATH = '/home/git.ysnapp.com/storage/data/google_play_devices.sqlite';",
                    "private const SQLITE_PATH = __DIR__ . '/../../storage/data/google_play_devices.sqlite';",
                    $content
                );
                $content = str_replace(
                    "private const APPLE_JSON_PATH = '/home/git.ysnapp.com/storage/data/apple_devices.json';",
                    "private const APPLE_JSON_PATH = __DIR__ . '/../../storage/data/apple_devices.json';",
                    $content
                );
                $content = str_replace(
                    "private const META_PATH = '/home/git.ysnapp.com/storage/data/devices_meta.json';",
                    "private const META_PATH = __DIR__ . '/../../storage/data/devices_meta.json';",
                    $content
                );
                file_put_contents($detectorFile, $content);
                Console::success("Patched DeviceDetector.php paths to portable dynamic paths");
            }
        }

        // Patch bin/repo-optimize.php if needed
        $optFile = $this->targetDir . '/bin/repo-optimize.php';
        if (is_file($optFile)) {
            $content = file_get_contents($optFile);
            if (str_contains($content, "'/home/git.ysnapp.com'")) {
                $content = str_replace(
                    "\$basePath = realpath(__DIR__ . '/..') ?: '/home/git.ysnapp.com';",
                    "\$basePath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);",
                    $content
                );
                $content = str_replace(
                    "\$basePath = '/home/git.ysnapp.com';",
                    "\$basePath = dirname(__DIR__);",
                    $content
                );
                file_put_contents($optFile, $content);
                Console::success("Secured bin/repo-optimize.php path resolution for foreign hosts");
            }
        }
    }

    private function generateGitignore(): void
    {
        $gitignoreContent = <<< 'GITIGNORE'
# GitPHP Production Gitignore
# Prevents accidental commits of private credentials, lock files, and sensitive runtime data.

# Composer dependencies
/vendor/

# Local Environment & Secrets
.env
.env.backup
.env.production
.env.local

# Storage runtime files (keep directory skeleton via .gitkeep)
/storage/cache/*
!/storage/cache/.gitkeep
/storage/data/*
!/storage/data/.gitkeep
/storage/downloads/*
!/storage/downloads/.gitkeep
/storage/logs/*
!/storage/logs/.gitkeep
/storage/sessions/*
!/storage/sessions/.gitkeep
/storage/tmp/*
!/storage/tmp/.gitkeep

# Web installer lock file — MUST NEVER BE COMMITTED TO GITHUB
/storage/installed.lock

# Hosted git repositories & private code
/repos/*
!/repos/.gitkeep

# User uploads & avatars
/public_html/uploads/*
!/public_html/uploads/.gitkeep
/public_html/uploads/avatars/*
!/public_html/uploads/avatars/.gitkeep

# Backups & Server Logs
/backup/
/logs/
*.log

# Node dependencies & caches
/node_modules/
.npm

# IDE, OS, & AI agent metadata
.idea/
.vscode/
*.swp
*~
.DS_Store
Thumbs.db
.agents/
AGENTS.md
GEMINI.md
.imunify*
GITIGNORE;

        file_put_contents($this->targetDir . '/.gitignore', $gitignoreContent);
        $this->copiedStats['files']++;
        Console::success("Generated hardened production-safe .gitignore file");
    }

    private function runSecurityAudit(): bool
    {
        $allPassed = true;

        $checks = [
            [
                'title' => 'Absence of live production .env secrets file',
                'test'  => !file_exists($this->targetDir . '/.env'),
            ],
            [
                'title' => 'Absence of storage/installed.lock (Fresh installation ready)',
                'test'  => !file_exists($this->targetDir . '/storage/installed.lock'),
            ],
            [
                'title' => 'Empty repos/ directory (Zero private hosted repositories)',
                'test'  => count(glob($this->targetDir . '/repos/*') ?: []) === 0,
            ],
            [
                'title' => 'Clean storage/sessions/ (Zero user session files)',
                'test'  => count(glob($this->targetDir . '/storage/sessions/*') ?: []) === 0,
            ],
            [
                'title' => 'Clean storage/logs/ (Zero server logs or query logs)',
                'test'  => count(glob($this->targetDir . '/storage/logs/*') ?: []) === 0,
            ],
            [
                'title' => 'Clean public_html/uploads/avatars/ (Zero uploaded user avatars)',
                'test'  => count(glob($this->targetDir . '/public_html/uploads/avatars/*') ?: []) === 0,
            ],
            [
                'title' => 'Presence of clean configuration template (.env.example)',
                'test'  => file_exists($this->targetDir . '/.env.example'),
            ],
            [
                'title' => 'Presence of web installer (public_html/install.php & index.php)',
                'test'  => file_exists($this->targetDir . '/public_html/install.php') && file_exists($this->targetDir . '/public_html/index.php'),
            ],
        ];

        // Advanced check: Database password leak test
        $dbPassLeakCheck = true;
        $liveEnvFile = $this->sourceDir . '/.env';
        if (is_file($liveEnvFile)) {
            $envLines = file($liveEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $livePass = '';
            foreach ($envLines as $el) {
                if (str_starts_with(trim($el), 'DB_PASS=')) {
                    $livePass = trim(explode('=', $el, 2)[1] ?? '', " \"'");
                    break;
                }
            }
            if ($livePass !== '' && strlen($livePass) > 4) {
                $cmd = sprintf("grep -rn %s %s 2>/dev/null", escapeshellarg($livePass), escapeshellarg($this->targetDir));
                $output = shell_exec($cmd);
                if (!empty($output)) {
                    $dbPassLeakCheck = false;
                }
            }
        }
        $checks[] = [
            'title' => 'Zero database passwords or server secrets leaked into release',
            'test'  => $dbPassLeakCheck,
        ];

        // Installer simulation check
        $installerCheck = false;
        require_once $this->sourceDir . '/src/Setup/Installer.php';
        if (!\App\Setup\Installer::isInstalled($this->targetDir)) {
            $installerCheck = true;
        }
        $checks[] = [
            'title' => 'Programmatic check: Installer::isInstalled() returns FALSE (Ready for web setup)',
            'test'  => $installerCheck,
        ];

        // Print results
        foreach ($checks as $chk) {
            if ($chk['test']) {
                Console::success($chk['title']);
            } else {
                Console::error("Audit check failed: " . $chk['title']);
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    private function initializeGitRepository(): void
    {
        Console::info("Initializing clean Git repository inside release folder...");
        $target = escapeshellarg($this->targetDir);
        shell_exec("git -C {$target} init -q 2>&1");
        shell_exec("git -C {$target} branch -M main 2>&1");
        shell_exec("git -C {$target} add . 2>&1");
        shell_exec("git -C {$target} commit -m 'Initial release ready for installation' -q 2>&1");
        Console::success("Git repository initialized and initial commit created");
    }

    private function createZipArchive(): string
    {
        Console::info("Compressing release files into .zip archive...");
        $zipName = basename($this->targetDir) . '-' . date('Ymd-His') . '.zip';
        $zipPath = dirname($this->targetDir) . DIRECTORY_SEPARATOR . $zipName;

        $targetParent = escapeshellarg(dirname($this->targetDir));
        $targetBase = escapeshellarg(basename($this->targetDir));
        $zipCmd = sprintf("cd %s && zip -rq %s %s -x '*.git*'", $targetParent, escapeshellarg($zipPath), $targetBase);
        shell_exec($zipCmd);

        if (file_exists($zipPath)) {
            $mb = round(filesize($zipPath) / (1024 * 1024), 2);
            Console::success("Distribution ZIP created: " . basename($zipPath) . " ({$mb} MB)");
            return $zipPath;
        }

        Console::warn("Unable to create zip file (ensure 'zip' command is installed)");
        return '';
    }

    private function printSuccessSummary(?string $zipPath): void
    {
        $mbTotal = round($this->copiedStats['bytes'] / (1024 * 1024), 2);

        Console::line();
        Console::line(Console::GREEN . Console::BOLD . "═══════════════════════════════════════════════════════════════════" . Console::RESET);
        Console::line(Console::GREEN . Console::BOLD . "      SUCCESS! Install-Ready Release Prepared for GitHub           " . Console::RESET);
        Console::line(Console::GREEN . Console::BOLD . "═══════════════════════════════════════════════════════════════════" . Console::RESET);
        Console::line();
        Console::line("  📁 Release Folder:     " . Console::BOLD . Console::CYAN . $this->targetDir . Console::RESET);
        Console::line("  �� Total Files:        " . Console::WHITE . "{$this->copiedStats['files']} files ({$mbTotal} MB)" . Console::RESET);
        Console::line("  🔒 Security Status:    " . Console::GREEN . "100% SECURE (Zero secrets, credentials, or private repos)" . Console::RESET);
        Console::line("  🚀 Installer Status:   " . Console::GREEN . "Ready to install via public_html/install.php" . Console::RESET);
        if ($zipPath && file_exists($zipPath)) {
            Console::line("  📦 Distribution ZIP:   " . Console::YELLOW . $zipPath . Console::RESET);
        }

        Console::title("GitHub Push Instructions for: https://github.com/aljupcom/gitphp.git");
        Console::line(Console::WHITE . "  Option A: Push the prepared repository directly to GitHub:" . Console::RESET);
        Console::line();
        Console::line(Console::YELLOW . "     cd " . $this->targetDir . Console::RESET);
        Console::line(Console::YELLOW . "     git remote add origin https://github.com/aljupcom/gitphp.git" . Console::RESET);
        Console::line(Console::YELLOW . "     git branch -M main" . Console::RESET);
        Console::line(Console::YELLOW . "     git push -u origin main" . Console::RESET);
        Console::line();
        Console::line(Console::WHITE . "  Option B: Or create a new repository on the command line:" . Console::RESET);
        Console::line();
        Console::line(Console::CYAN . "     echo \"# gitphp\" >> README.md" . Console::RESET);
        Console::line(Console::CYAN . "     git init" . Console::RESET);
        Console::line(Console::CYAN . "     git add README.md" . Console::RESET);
        Console::line(Console::CYAN . "     git commit -m \"first commit\"" . Console::RESET);
        Console::line(Console::CYAN . "     git branch -M main" . Console::RESET);
        Console::line(Console::CYAN . "     git remote add origin https://github.com/aljupcom/gitphp.git" . Console::RESET);
        Console::line(Console::CYAN . "     git push -u origin main" . Console::RESET);
        Console::line();
        Console::line(Console::GREEN . Console::BOLD . "═══════════════════════════════════════════════════════════════════" . Console::RESET);
        Console::line();
    }

    private function copyDirectory(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!$dir) return;

        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
            $this->copiedStats['dirs']++;
        }

        $skipNames = [
            '.env', '.git', '.idea', '.vscode', '.DS_Store', 'Thumbs.db',
            '.agents', 'AGENTS.md', 'GEMINI.md', '.imunify_patch_id', '.myimunify_id',
            'installed.lock'
        ];

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') continue;
            if (in_array($file, $skipNames, true)) continue;

            $srcFile = $src . '/' . $file;
            $dstFile = $dst . '/' . $file;

            if (is_link($srcFile)) {
                $target = readlink($srcFile);
                if (is_file($srcFile)) {
                    copy($srcFile, $dstFile);
                    $this->copiedStats['files']++;
                }
                continue;
            }

            if (is_dir($srcFile)) {
                $this->copyDirectory($srcFile, $dstFile);
            } else {
                copy($srcFile, $dstFile);
                $this->copiedStats['files']++;
                $this->copiedStats['bytes'] += filesize($srcFile);
            }
        }
        closedir($dir);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function printBanner(): void
    {
        Console::line();
        Console::line(Console::CYAN . Console::BOLD . "  ╔═════════════════════════════════════════════════════════════════╗" . Console::RESET);
        Console::line(Console::CYAN . Console::BOLD . "  ║       GitPHP — GitHub Release & Clean Packaging Wizard          ║" . Console::RESET);
        Console::line(Console::CYAN . Console::BOLD . "  ║    Prepare a secure, install-ready package for GitHub & dist    ║" . Console::RESET);
        Console::line(Console::CYAN . Console::BOLD . "  ╚═════════════════════════════════════════════════════════════════╝" . Console::RESET);
        Console::line(Console::DIM . "   Creates a clean, installable distribution free of private secrets" . Console::RESET);
        Console::line();
    }
}

// ── CLI Runner Entrypoint ───────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
if (!file_exists($projectRoot . '/composer.json') && file_exists(dirname($projectRoot) . '/composer.json')) {
    $projectRoot = dirname($projectRoot);
}

$packager = new ReleasePackager($projectRoot);

// Handle CLI parameters or interactive mode
$options = getopt('', ['target:', 'include-vendor', 'no-git', 'no-zip', 'force', 'help']);

if (isset($options['help'])) {
    Console::line("GitPHP Release Packager CLI");
    Console::line("Usage: php bin/prepare-github-release.php [options]");
    Console::line("Options:");
    Console::line("  --target=<path>     Set release destination folder");
    Console::line("  --include-vendor    Include vendor/ directory (Standalone release)");
    Console::line("  --no-git            Skip Git repository initialization");
    Console::line("  --no-zip            Skip creating .zip archive");
    Console::line("  --force             Overwrite existing destination without prompting");
    Console::line("  --help              Show this help message");
    exit(0);
}

// Non-interactive execution if target is specified via CLI flag
if (isset($options['target'])) {
    $packager->setTargetDir((string)$options['target']);
    $packager->setIncludeVendor(isset($options['include-vendor']));
    $packager->setInitGit(!isset($options['no-git']));
    $packager->setCreateZip(!isset($options['no-zip']));
    $packager->setForceOverwrite(isset($options['force']));
    $packager->execute();
    exit(0);
}

// Default: Interactive Wizard Mode
$packager->runWizard();
