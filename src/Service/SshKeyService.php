<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use RuntimeException;

final class SshKeyService
{
    private Database $db;
    private string $authorizedKeysPath;
    private string $gitShellWrapperPath;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->authorizedKeysPath = (string) env(
            'AUTHORIZED_KEYS_PATH',
            '/home/git/.ssh/authorized_keys',
        );
        $this->gitShellWrapperPath = dirname(__DIR__, 2) . '/bin/git-shell-wrapper.php';
    }

    /**
     * Fetch all SSH keys from the database.
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?int $userId = null): array
    {
        if ($userId !== null) {
            return $this->db->fetchAll(
                'SELECT `id`, `user_id`, `title`, `public_key`, `fingerprint`, `created_at` FROM `ssh_keys` WHERE `user_id` = :uid ORDER BY `created_at` DESC',
                ['uid' => $userId]
            );
        }
        return $this->db->fetchAll(
            'SELECT `id`, `user_id`, `title`, `public_key`, `fingerprint`, `created_at` FROM `ssh_keys` ORDER BY `created_at` DESC'
        );
    }

    /**
     * Fetch a single SSH key by ID.
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT `id`, `title`, `public_key`, `fingerprint`, `created_at` FROM `ssh_keys` WHERE `id` = :id LIMIT 1',
            ['id' => $id],
        );

        return $row !== false ? $row : null;
    }

    /** Add a new SSH key. */
    public function add(string $title, string $publicKey, ?int $userId = null): bool
    {
        $title     = trim($title);
        $publicKey = trim($publicKey);

        if ($title === '') throw new RuntimeException('Key title is required.');

        if ($publicKey === '') throw new RuntimeException('Public key is required.');

        if (!$this->validateKeyFormat($publicKey)) {
            throw new RuntimeException(
                'Invalid public key format. Key must start with ssh-rsa, ssh-ed25519, or ecdsa-sha2-*.'
            );
        }

        $fingerprint = $this->getFingerprint($publicKey);

        if ($fingerprint === null) throw new RuntimeException('Could not compute key fingerprint.');

        // Check for duplicate fingerprint
        $existing = $this->db->fetchOne(
            'SELECT `id` FROM `ssh_keys` WHERE `fingerprint` = :fp LIMIT 1',
            ['fp' => $fingerprint],
        );

        if ($existing !== false) throw new RuntimeException('This SSH key has already been added.');

        $this->db->execute(
            'INSERT INTO `ssh_keys` (`user_id`, `title`, `public_key`, `fingerprint`) VALUES (:uid, :title, :key, :fp)',
            [
                'uid'   => $userId,
                'title' => $title,
                'key'   => $publicKey,
                'fp'    => $fingerprint,
            ],
        );

        $this->regenerateAuthorizedKeys();

        return true;
    }

    /** Delete an SSH key by ID. */
    public function delete(int $id): bool
    {
        $key = $this->getById($id);

        if ($key === null) return false;

        $this->db->execute('DELETE FROM `ssh_keys` WHERE `id` = :id', ['id' => $id]);

        $this->regenerateAuthorizedKeys();

        return true;
    }

    /** Validate that a public key string starts with a supported key type. */
    public function validateKeyFormat(string $key): bool
    {
        $key = trim($key);

        // A public key must be a single line: embedded newlines would let an
        // attacker inject extra authorized_keys entries bypassing restrictions.
        if (str_contains($key, "\n") || str_contains($key, "\r")) return false;

        // Supported key types
        $supportedTypes = [
            'ssh-rsa',
            'ssh-ed25519',
            'ssh-dss',
            'ecdsa-sha2-nistp256',
            'ecdsa-sha2-nistp384',
            'ecdsa-sha2-nistp521',
        ];

        foreach ($supportedTypes as $type) {
            if (str_starts_with($key, $type . ' ')) {
                // Must have at least two parts: type + base64 data (optional comment after)
                $parts = preg_split('/\s+/', $key);
                if ($parts !== false && count($parts) >= 2) {
                    // Validate base64-encoded key data
                    $decoded = base64_decode($parts[1], true);
                    return $decoded !== false && strlen($decoded) > 0;
                }
                return false;
            }
        }

        return false;
    }

    /** Compute the SHA256 fingerprint of an SSH public key. */
    public function getFingerprint(string $publicKey): ?string
    {
        $publicKey = trim($publicKey);

        // Try using ssh-keygen if available
        $fingerprint = $this->getFingerprintViaSshKeygen($publicKey);
        if ($fingerprint !== null) return $fingerprint;

        // Fallback: manual SHA256 fingerprint from base64 key data
        return $this->getFingerprintManual($publicKey);
    }

    /** Attempt fingerprint via ssh-keygen command. */
    private function getFingerprintViaSshKeygen(string $publicKey): ?string
    {
        // Write key to a temp file for ssh-keygen
        $tmpFile = tempnam(sys_get_temp_dir(), 'sshkey_');
        if ($tmpFile === false) return null;

        try {
            file_put_contents($tmpFile, $publicKey . "\n");

            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            $process = proc_open(
                ['ssh-keygen', '-l', '-E', 'sha256', '-f', $tmpFile],
                $descriptors,
                $pipes,
            );

            if (!is_resource($process)) return null;

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0 || $stdout === false) return null;

            // Output format: "256 SHA256:xxxxx comment (TYPE)"
            if (preg_match('/SHA256:[A-Za-z0-9+\/=]+/', $stdout, $matches)) return $matches[0];

            return null;
        } finally {
            if (file_exists($tmpFile)) unlink($tmpFile);
        }
    }

    /** Compute SHA256 fingerprint manually from the base64 key data. */
    private function getFingerprintManual(string $publicKey): ?string
    {
        $parts = preg_split('/\s+/', trim($publicKey));
        if ($parts === false || count($parts) < 2) return null;

        $keyData = base64_decode($parts[1], true);
        if ($keyData === false) return null;

        $hash = hash('sha256', $keyData, true);

        return 'SHA256:' . rtrim(base64_encode($hash), '=');
    }

    /** Regenerate the authorized_keys file from all keys in the database. */
    public function regenerateAuthorizedKeys(): void
    {
        $keys = $this->db->fetchAll(
            'SELECT `id`, `user_id`, `public_key` FROM `ssh_keys` ORDER BY `id` ASC'
        );

        $wrapperPath = $this->gitShellWrapperPath;
        $lines = [];

        foreach ($keys as $key) {
            $publicKey = trim((string) $key['public_key']);

            // Defense in depth: never write multi-line keys into authorized_keys.
            if ($publicKey === '' || str_contains($publicKey, "\n") || str_contains($publicKey, "\r")) continue;

            $keyId = (int) $key['id'];
            $uid = isset($key['user_id']) && $key['user_id'] !== null ? (int)$key['user_id'] : 0;
            $command   = sprintf(
                'command="/usr/bin/php %s --key-id=%d --user-id=%d",no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty',
                $wrapperPath,
                $keyId,
                $uid
            );
            $lines[] = $command . ' ' . $publicKey;
        }

        $content = implode("\n", $lines);
        if ($content !== '') $content .= "\n";

        $dir = dirname($this->authorizedKeysPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }

        $result = file_put_contents($this->authorizedKeysPath, $content, LOCK_EX);
        if ($result === false) throw new RuntimeException('Failed to write authorized_keys file.');

        // Set restrictive permissions
        chmod($this->authorizedKeysPath, 0600);
    }
}
