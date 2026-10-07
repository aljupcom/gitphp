<?php

declare(strict_types=1);

/**
 * GitPHP — fix-push.php
 * سكريبت إصلاح شامل لمشكلة رفض الـ Push.
 * الاستخدام: php bin/fix-push.php
 */

$basePath = dirname(__DIR__);

// Load .env
$envFile = $basePath . '/.env';
if (!is_file($envFile)) {
    die("❌ لم يُعثر على ملف .env في: {$basePath}\n");
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value, " \t\"'");
}

$reposPath          = rtrim($env['REPOS_PATH'] ?? ($basePath . '/repos'), '/');
$authorizedKeysPath = $env['AUTHORIZED_KEYS_PATH'] ?? '/home/git/.ssh/authorized_keys';
$gitShellWrapper    = $basePath . '/bin/git-shell-wrapper.php';

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  GitPHP — إصلاح شامل لمشكلة Push\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  REPOS_PATH           : {$reposPath}\n";
echo "  AUTHORIZED_KEYS_PATH : {$authorizedKeysPath}\n";
echo "  BASE_PATH            : {$basePath}\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// DB Connection
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1',
        $env['DB_PORT'] ?? '3306',
        $env['DB_NAME'] ?? 'gitphp',
    );
    $pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "✅ [DB] الاتصال بقاعدة البيانات ناجح\n\n";
} catch (Throwable $e) {
    die("❌ [DB] فشل الاتصال: " . $e->getMessage() . "\n");
}

// ════════════════════════════════════════════════════════
// الإصلاح 1: أذونات authorized_keys
// ════════════════════════════════════════════════════════
echo "── [1/5] إصلاح أذونات authorized_keys ─────────────────────\n";

$authKeysDir = dirname($authorizedKeysPath);

if (!is_dir($authKeysDir)) {
    if (mkdir($authKeysDir, 0700, true)) {
        echo "  ✅ أُنشئ المجلد: {$authKeysDir}\n";
    } else {
        echo "  ❌ فشل إنشاء المجلد: {$authKeysDir}\n";
    }
}

if (!is_file($authorizedKeysPath)) {
    file_put_contents($authorizedKeysPath, '');
    echo "  ✅ أُنشئ الملف: {$authorizedKeysPath}\n";
}

$currentPerms = substr(sprintf('%o', fileperms($authorizedKeysPath)), -4);
echo "  ℹ️  الأذونات الحالية: {$currentPerms}\n";

if (chmod($authorizedKeysPath, 0660)) {
    echo "  ✅ تم تغيير أذونات authorized_keys إلى 660\n";
} else {
    echo "  ⚠️  فشل تغيير الأذونات — قد تحتاج تشغيل السكريبت كـ root\n";
}

// ════════════════════════════════════════════════════════
// الإصلاح 2: توليد authorized_keys من DB
// ════════════════════════════════════════════════════════
echo "\n── [2/5] توليد authorized_keys من قاعدة البيانات ──────────\n";

$keys = $pdo->query('SELECT `public_key`, `title` FROM `ssh_keys` ORDER BY `id` ASC')->fetchAll(PDO::FETCH_ASSOC);

if (count($keys) === 0) {
    echo "  ⚠️  لا توجد مفاتيح SSH في قاعدة البيانات\n";
    echo "     أضف مفتاحاً من: لوحة الإدارة → SSH Keys\n";
} else {
    $lines = [];
    foreach ($keys as $key) {
        $publicKey = trim((string) $key['public_key']);
        if ($publicKey === '' || str_contains($publicKey, "\n") || str_contains($publicKey, "\r")) {
            echo "  ⚠️  تخطّي مفتاح غير صالح: {$key['title']}\n";
            continue;
        }
        $command = sprintf(
            'command="/usr/bin/php %s",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty',
            $gitShellWrapper,
        );
        $lines[] = $command . ' ' . $publicKey;
        echo "  ✅ مفتاح: {$key['title']}\n";
    }

    $content = implode("\n", $lines);
    if ($content !== '') $content .= "\n";

    if (file_put_contents($authorizedKeysPath, $content, LOCK_EX) !== false) {
        echo "  ✅ تم كتابة " . count($lines) . " مفتاح/مفاتيح في authorized_keys\n";
        chmod($authorizedKeysPath, 0660);
    } else {
        echo "  ❌ فشل كتابة authorized_keys — تحقق من صلاحيات الملف\n";
        $webUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'WEB_USER') : 'WEB_USER';
        echo "  الحل اليدوي:\n";
        echo "    sudo usermod -aG git {$webUser}\n";
        echo "    sudo chmod 660 {$authorizedKeysPath}\n";
        echo "    sudo chown git:git {$authorizedKeysPath}\n";
        echo "    php bin/fix-push.php\n";
    }
}

// ════════════════════════════════════════════════════════
// الإصلاح 3: http.receivepack=true في جميع المستودعات
// ════════════════════════════════════════════════════════
echo "\n── [3/5] تفعيل http.receivepack في جميع المستودعات ────────\n";

if (!is_dir($reposPath)) {
    echo "  ❌ مجلد repos غير موجود: {$reposPath}\n";
} else {
    $repos = glob($reposPath . '/*.git', GLOB_ONLYDIR) ?: [];

    if (empty($repos)) {
        echo "  ⚠️  لا توجد مستودعات في: {$reposPath}\n";
    }

    foreach ($repos as $repoDir) {
        $slug    = basename($repoDir, '.git');
        $current = trim(shell_exec("git -C " . escapeshellarg($repoDir) . " config http.receivepack 2>/dev/null") ?? '');

        if ($current === 'true') {
            echo "  ✅ {$slug}: http.receivepack=true (كان مفعّلاً)\n";
            continue;
        }

        shell_exec("git -C " . escapeshellarg($repoDir) . " config http.receivepack true 2>&1");
        $check = trim(shell_exec("git -C " . escapeshellarg($repoDir) . " config http.receivepack 2>/dev/null") ?? '');

        if ($check === 'true') {
            echo "  ✅ {$slug}: http.receivepack=true (تم تفعيله)\n";
        } else {
            echo "  ❌ {$slug}: فشل تفعيل http.receivepack\n";
        }
    }
}

// ════════════════════════════════════════════════════════
// الإصلاح 4: إعادة تثبيت Hooks مع BASE_PATH الصحيح
// ════════════════════════════════════════════════════════
echo "\n── [4/5] إعادة تثبيت Hooks مع BASE_PATH الصحيح ───────────\n";

$sourceHooksDir = $basePath . '/hooks';

if (!is_dir($sourceHooksDir)) {
    echo "  ❌ مجلد hooks غير موجود: {$sourceHooksDir}\n";
} else {
    $repos = glob($reposPath . '/*.git', GLOB_ONLYDIR) ?: [];

    if (empty($repos)) {
        echo "  ⚠️  لا توجد مستودعات لتثبيت الـ hooks فيها\n";
    }

    foreach ($repos as $repoDir) {
        $slug     = basename($repoDir, '.git');
        $hooksDir = $repoDir . '/hooks';

        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $entries = scandir($sourceHooksDir);
        if ($entries === false) {
            echo "  ❌ {$slug}: لا يمكن قراءة مجلد hooks\n";
            continue;
        }

        $installed = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $source = $sourceHooksDir . '/' . $entry;
            $dest   = $hooksDir . '/' . $entry;
            if (!is_file($source)) continue;

            $hookContent = (string) file_get_contents($source);
            // استبدال BASE_PATH في جميع الـ hooks
            if (str_contains($hookContent, '{{BASE_PATH}}')) {
                $hookContent = str_replace('{{BASE_PATH}}', $basePath, $hookContent);
            }

            if (file_put_contents($dest, $hookContent) !== false) {
                chmod($dest, 0755);
                $installed++;
            } else {
                echo "  ❌ {$slug}: فشل كتابة hook: {$entry}\n";
            }
        }

        // التحقق من الاستبدال الصحيح في pre-receive
        $preReceivePath = $hooksDir . '/pre-receive';
        $preContent     = is_file($preReceivePath) ? (string) file_get_contents($preReceivePath) : '';

        if (str_contains($preContent, '{{BASE_PATH}}')) {
            echo "  ❌ {$slug}: pre-receive لا يزال يحتوي على {{BASE_PATH}} غير مستبدل!\n";
        } else {
            echo "  ✅ {$slug}: تم تثبيت {$installed} hook(s) — BASE_PATH مضبوط\n";
        }
    }
}

// ════════════════════════════════════════════════════════
// الإصلاح 5: إصلاح أذونات مجلدات repos
// ════════════════════════════════════════════════════════
echo "\n── [5/5] إصلاح أذونات مجلدات المستودعات ──────────────────\n";

$repos = glob($reposPath . '/*.git', GLOB_ONLYDIR) ?: [];

foreach ($repos as $repoDir) {
    $slug  = basename($repoDir, '.git');
    $perms = substr(sprintf('%o', fileperms($repoDir)), -4);

    chmod($repoDir, 0775);
    $newPerms = substr(sprintf('%o', fileperms($repoDir)), -4);

    if ($perms !== $newPerms) {
        echo "  ✅ {$slug}: {$perms} → {$newPerms}\n";
    } else {
        echo "  ✅ {$slug}: {$perms} (صحيحة)\n";
    }

    // objects/ needs group write for pushes
    $objectsDir = $repoDir . '/objects';
    if (is_dir($objectsDir)) {
        chmod($objectsDir, 0775);
        foreach (glob($objectsDir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            chmod($sub, 0775);
        }
    }
}

// ════════════════════════════════════════════════════════
// الملخص النهائي
// ════════════════════════════════════════════════════════
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  الملخص النهائي\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$akContent = @file_get_contents($authorizedKeysPath) ?: '';
$akLines   = trim($akContent) !== '' ? substr_count(trim($akContent), "\n") + 1 : 0;
$akPerms   = is_file($authorizedKeysPath) ? substr(sprintf('%o', fileperms($authorizedKeysPath)), -4) : '----';

echo "\n  authorized_keys : {$authorizedKeysPath}\n";
echo "  أذونات         : {$akPerms}\n";
echo "  مفاتيح         : {$akLines}\n";

if ($akLines === 0) {
    $webUser = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'WEB_USER') : 'WEB_USER';
    echo "\n  ⚠️  authorized_keys فارغ — الخطوات اليدوية المطلوبة:\n";
    echo "  sudo usermod -aG git {$webUser}\n";
    echo "  sudo chmod 660 {$authorizedKeysPath}\n";
    echo "  sudo chown git:git {$authorizedKeysPath}\n";
    echo "  php bin/fix-push.php\n\n";
} else {
    echo "\n  ✅ SSH Push جاهز!\n";
}

echo "\n  اختبار SSH:\n";
echo "    ssh -T git@git.ysnapp.com\n";
echo "    (متوقع: 'Interactive shell is not available.')\n\n";
echo "  اختبار HTTP Push:\n";
echo "    git remote set-url origin https://admin@git.ysnapp.com/admin/REPO.git\n";
echo "    git push origin main\n";
echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  اكتمل الإصلاح ✅\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
