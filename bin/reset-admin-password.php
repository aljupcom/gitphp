<?php

declare(strict_types=1);

/**
 * GitPHP — reset or generate the owner (admin) password.
 *
 * The owner account (APP_OWNER, e.g. aljailane) authenticates against the
 * 'owner_password_hash' value stored in the `settings` table. This tool lets
 * you set it explicitly, or generate a fresh strong password.
 *
 * Usage:
 *   php bin/reset-admin-password.php                     # generate + print a new password
 *   php bin/reset-admin-password.php --password=S3cure!  # set a specific password
 *
 * The password is stored as a password_hash() (Argon2id when available),
 * matching how Auth::loginOwner verifies it.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

// ── Resolve flags ──────────────────────────────────────────────────
$givenPassword = '';
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--password=(.*)$/i', $arg, $m)) {
        $givenPassword = $m[1];
    }
}

echo "╔══════════════════════════════════════════════╗\n";
echo "║   GitPHP — Owner password reset / generate     ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

// ── Owner identity ─────────────────────────────────────────────────
$app = \App\App::boot($basePath);
$ownerName = (string) $app->config('app.owner', 'admin');
$db = $app->db()->connection();

echo "Owner account: {$ownerName}\n";

// ── Obtain the new password ────────────────────────────────────────
if ($givenPassword !== '') {
    $password = $givenPassword;
    echo "Password: (provided)\n";
} else {
    // Generate a strong random password (letters + digits + symbols).
    $password = generatePassword(16);
    echo "Generated password: {$password}\n";
    echo "\n⚠️  Copy it now — it will not be shown again.\n\n";
}

$password = (string) $password;

if (strlen($password) < 8) {
    fwrite(STDERR, "✗ Password must be at least 8 characters.\n");
    exit(1);
}

// ── Store it (Argon2id when supported, same params as the installer) ─
$hash = password_hash(
    $password,
    defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT,
    defined('PASSWORD_ARGON2ID') ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3] : [],
);

$stmt = $db->prepare(
    'REPLACE INTO `settings` (`setting_key`, `setting_value`) VALUES (:key, :value)',
);
$stmt->execute(['key' => 'owner_password_hash', 'value' => $hash]);

echo "✓ Password hash stored for owner '{$ownerName}'.\n\n";
echo "Next: sign in at " . rtrim((string) $app->config('app.url', '/login'), '/') . "/login\n";

/** Generate a cryptographically-strong password with letters, digits and symbols. */
function generatePassword(int $length = 16): string
{
    $sets = [
        'abcdefghijkmnopqrstuvwxyz',   // no l
        'ABCDEFGHJKLMNPQRSTUVWXYZ',    // no I/O
        '23456789',                    // no 0/1
        '!@#$%^&*_-+=?',
    ];

    $all     = implode('', $sets);
    $pattern = '';

    // Guarantee at least one of each class.
    foreach ($sets as $set) {
        $pattern .= $set[random_int(0, strlen($set) - 1)];
    }

    while (strlen($pattern) < $length) {
        $pattern .= $all[random_int(0, strlen($all) - 1)];
    }

    // Shuffle the guaranteed characters into the rest.
    $chars = str_split($pattern);
    shuffle($chars);

    return substr(implode('', $chars), 0, $length);
}
