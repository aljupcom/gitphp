<?php

declare(strict_types=1);

namespace App\Service;

use App\App;

/**
 * Unified mail service.
 *
 * Resolves configuration from the `email_settings` table (admin-editable,
 * secrets encrypted at rest) with a fall back to environment variables.
 * Chooses a transport (smtp | mail | log), sends the message and records
 * every attempt in `email_log`. It never throws: a mail failure must never
 * break the primary application flow.
 */
final class MailService
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** True when a real transport (smtp or PHP mail) is configured. */
    public function enabled(): bool
    {
        return $this->transport() !== 'log';
    }

    /** Resolved transport: 'smtp', 'mail' or 'log'. */
    public function transport(): string
    {
        $explicit = strtolower((string) $this->setting('provider', ''));
        if (in_array($explicit, ['smtp', 'mail', 'log', 'api'], true)) {
            // 'api' is reserved; falls back to smtp/mail resolution for now.
            if ($explicit === 'smtp' && $this->setting('smtp_host', '') !== '') return 'smtp';
            if ($explicit === 'mail') return 'mail';
            if ($explicit === 'log') return 'log';
        }
        if ($this->setting('smtp_host', '') !== '') return 'smtp';
        if ($this->fromAddress() !== '') return 'mail';
        return 'log';
    }

    public function fromAddress(): string
    {
        return (string) $this->setting('from_email', (string) (env('MAIL_FROM', '')));
    }

    public function fromName(): string
    {
        return (string) $this->setting('from_name', (string) $this->app->config('app.name', 'GitPHP'));
    }

    /**
     * Send an email. Returns true on success (or when logged in dev mode).
     */
    public function send(string $to, string $subject, string $bodyHtml, string $bodyText = '', array $meta = []): bool
    {
        $transport = $this->transport();
        $status    = 'logged';
        $error     = null;
        $ok        = true;

        try {
            // Skip addresses on the suppression list (bounces / unsubscribes).
            if ($this->isSuppressed($to)) {
                $this->logEmail($to, $subject, 'failed', 'suppressed address', $transport, $meta);
                return false;
            }

            if ($transport === 'smtp') {
                $ok    = $this->sendSmtp($to, $subject, $bodyHtml, $bodyText, $error);
                $status = $ok ? 'sent' : 'failed';
            } elseif ($transport === 'mail') {
                $ok    = $this->sendPhpMail($to, $subject, $bodyHtml);
                $status = $ok ? 'sent' : 'failed';
            } else {
                // Dev / unconfigured: log only, treat as success.
                error_log('[MAIL:log] To: ' . $to . ' | Subject: ' . $subject);
                $status = 'logged';
                $ok     = true;
            }
        } catch (\Throwable $e) {
            $ok     = false;
            $status = 'failed';
            $error  = $e->getMessage();
        }

        $this->logEmail($to, $subject, $status, $error, $transport, $meta);
        return $ok;
    }

    // ── Transports ───────────────────────────────────────────────────

    private function sendPhpMail(string $to, string $subject, string $bodyHtml): bool
    {
        $from    = $this->fromAddress();
        $name    = $this->fromName();
        $fromHdr = $name !== '' ? sprintf('%s <%s>', $name, $from) : $from;

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromHdr,
            'X-Mailer: GitPHP',
        ]);

        return @mail($to, $subject, $bodyHtml, $headers);
    }

    /** Minimal, hang-proof SMTP client (AUTH LOGIN, STARTTLS or TLS). */
    private function sendSmtp(string $to, string $subject, string $bodyHtml, string $bodyText, ?string &$error): bool
    {
        $host = (string) $this->setting('smtp_host', '');
        $port = (int) $this->setting('smtp_port', '587');
        $enc  = strtolower((string) $this->setting('smtp_encryption', 'tls')); // tls|ssl|none
        $user = (string) $this->setting('smtp_user', '');
        $pass = (string) $this->setting('smtp_pass', '');
        $from = $this->fromAddress();

        if ($host === '' || $from === '') { $error = 'SMTP host or from address missing'; return false; }

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $ctx    = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp     = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) { $error = "connect failed: {$errstr}"; return false; }
        stream_set_timeout($fp, 10);

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };

        try {
            $read(); // greeting
            $ehloHost = (string) (parse_url((string) $this->app->config('app.url', ''), PHP_URL_HOST) ?: 'localhost');
            $cmd('EHLO ' . $ehloHost);

            if ($enc === 'tls') {
                $r = $cmd('STARTTLS');
                if (strncmp($r, '220', 3) !== 0) { $error = 'STARTTLS refused'; fclose($fp); return false; }
                if (! @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    $error = 'TLS negotiation failed'; fclose($fp); return false;
                }
                $cmd('EHLO ' . $ehloHost);
            }

            if ($user !== '') {
                $cmd('AUTH LOGIN');
                $r = $cmd(base64_encode($user));
                $r = $cmd(base64_encode($pass));
                if (strncmp($r, '235', 3) !== 0) { $error = 'authentication failed'; fclose($fp); return false; }
            }

            $r = $cmd('MAIL FROM:<' . $from . '>');
            if (strncmp($r, '250', 3) !== 0) { $error = 'MAIL FROM rejected'; fclose($fp); return false; }
            $r = $cmd('RCPT TO:<' . $to . '>');
            if (strncmp($r, '25', 2) !== 0) { $error = 'RCPT TO rejected'; fclose($fp); return false; }

            $r = $cmd('DATA');
            if (strncmp($r, '354', 3) !== 0) { $error = 'DATA refused'; fclose($fp); return false; }

            $fromHdr = $this->fromName() !== '' ? sprintf('%s <%s>', $this->fromName(), $from) : $from;
            $boundary = 'b' . bin2hex(random_bytes(8));
            $text = $bodyText !== '' ? $bodyText : trim(strip_tags($bodyHtml));

            $body = implode("\r\n", [
                'From: ' . $fromHdr,
                'To: ' . $to,
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Date: ' . date('r'),
                'X-Mailer: GitPHP',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                '',
                '--' . $boundary,
                'Content-Type: text/plain; charset=UTF-8',
                '',
                $this->dotStuff($text),
                '--' . $boundary,
                'Content-Type: text/html; charset=UTF-8',
                '',
                $this->dotStuff($bodyHtml),
                '--' . $boundary . '--',
                '.',
            ]);
            $r = $cmd($body);
            $cmd('QUIT');
            fclose($fp);

            if (strncmp($r, '250', 3) !== 0) { $error = 'message not accepted'; return false; }
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (is_resource($fp)) fclose($fp);
            return false;
        }
    }

    private function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private function dotStuff(string $s): string
    {
        // RFC 5321: lines starting with '.' must be escaped.
        return preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $s));
    }

    // ── Settings (with encrypted secrets) ────────────────────────────

    /** @var array<string,?string> */
    private array $cache = [];

    public function setting(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->cache)) return $this->cache[$key] ?? $default;

        try {
            $row = $this->app->db()->fetchOne(
                'SELECT `value`, `value_enc`, `is_secret` FROM `email_settings` WHERE `key_name` = :k LIMIT 1',
                ['k' => $key],
            );
        } catch (\Throwable) {
            $row = false;
        }

        if ($row === false) {
            return $this->cache[$key] = $default;
        }

        if (! empty($row['is_secret']) && $row['value_enc'] !== null) {
            $dec = $this->decrypt((string) $row['value_enc']);
            return $this->cache[$key] = ($dec ?? $default);
        }

        $val = $row['value'];
        return $this->cache[$key] = ($val !== null && $val !== '' ? (string) $val : $default);
    }

    public function saveSetting(string $key, string $value, bool $secret = false): void
    {
        unset($this->cache[$key]);
        if ($secret) {
            $enc = $this->encrypt($value);
            $this->app->db()->execute(
                'INSERT INTO `email_settings` (`key_name`, `value`, `value_enc`, `is_secret`) VALUES (:k, NULL, :e, 1)
                 ON DUPLICATE KEY UPDATE `value` = NULL, `value_enc` = :e2, `is_secret` = 1',
                ['k' => $key, 'e' => $enc, 'e2' => $enc],
            );
        } else {
            $this->app->db()->execute(
                'INSERT INTO `email_settings` (`key_name`, `value`, `value_enc`, `is_secret`) VALUES (:k, :v, NULL, 0)
                 ON DUPLICATE KEY UPDATE `value` = :v2, `value_enc` = NULL, `is_secret` = 0',
                ['k' => $key, 'v' => $value, 'v2' => $value],
            );
        }
    }

    private function secretKey(): string
    {
        try {
            $row = $this->app->db()->fetchOne("SELECT `setting_value` FROM `settings` WHERE `setting_key` = 'email_secret_key' LIMIT 1");
            if ($row !== false && ! empty($row['setting_value'])) {
                return base64_decode((string) $row['setting_value']);
            }
        } catch (\Throwable) {}

        $key = random_bytes(defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32);
        try {
            $this->app->db()->execute(
                "INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES ('email_secret_key', :v)
                 ON DUPLICATE KEY UPDATE `setting_value` = :v2",
                ['v' => base64_encode($key), 'v2' => base64_encode($key)],
            );
        } catch (\Throwable) {}
        return $key;
    }

    private function encrypt(string $plain): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->secretKey());
        }
        return "b64:" . base64_encode($plain);
    }

    private function decrypt(string $blob): ?string
    {
        if (str_starts_with($blob, 'b64:')) {
            return base64_decode(substr($blob, 4)) ?: null;
        }
        if (function_exists('sodium_crypto_secretbox_open')) {
            $nb = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($blob) <= $nb) return null;
            $nonce  = substr($blob, 0, $nb);
            $cipher = substr($blob, $nb);
            $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->secretKey());
            return $plain === false ? null : $plain;
        }
        return null;
    }

    // ── Log / suppression ────────────────────────────────────────────

    private function logEmail(string $to, string $subject, string $status, ?string $error, string $provider, array $meta): void
    {
        try {
            $this->app->db()->execute(
                'INSERT INTO `email_log` (`to_email`, `subject`, `template_key`, `event_key`, `provider`, `status`, `error`)
                 VALUES (:to, :subj, :tpl, :evt, :prov, :status, :err)',
                [
                    'to'     => mb_substr($to, 0, 255),
                    'subj'   => mb_substr($subject, 0, 255),
                    'tpl'    => $meta['template_key'] ?? null,
                    'evt'    => $meta['event_key'] ?? null,
                    'prov'   => $provider,
                    'status' => $status,
                    'err'    => $error !== null ? mb_substr($error, 0, 500) : null,
                ],
            );
        } catch (\Throwable) {
            // Logging must never break sending.
        }
    }

    private function isSuppressed(string $email): bool
    {
        try {
            $row = $this->app->db()->fetchOne('SELECT `email` FROM `email_suppressions` WHERE `email` = :e LIMIT 1', ['e' => strtolower($email)]);
            return $row !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
