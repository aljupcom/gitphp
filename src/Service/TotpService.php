<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * TOTP (RFC 6238) two-factor authentication, pure PHP — no libraries.
 *
 * Base32 secrets, 30-second windows, ±1 step tolerance, SHA1 default.
 */
final class TotpService
{
    private const WINDOW = 30;
    private const LOOKAHEAD = 1;

    /** Generate a random base32 secret (20 bytes → 32 chars). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /** Verify a user-supplied code against a secret within ±1 window. */
    public static function verify(string $secret, string $code): bool
    {
        $code = trim($code);
        if ($code === '' || ! ctype_digit($code)) return false;

        $secret = self::normalizeBase32($secret);
        $step   = (int) floor(time() / self::WINDOW);

        for ($i = -self::LOOKAHEAD; $i <= self::LOOKAHEAD; $i++) {
            if (hash_equals(self::codeAt($secret, $step + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** otpauth:// provisioning URI (for authenticator apps / manual entry). */
    public static function provisioningUri(string $secret, string $account, string $issuer = 'GitPHP'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::WINDOW,
        );
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $bin = pack('N', $counter); // 32-bit big-endian
        $hash = hash_hmac('sha1', $bin, $secret, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24)
                | ((ord($hash[$offset + 1]) & 0xFF) << 16)
                | ((ord($hash[$offset + 2]) & 0xFF) << 8)
                | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Base32 encode (RFC 4648, upper-case, no padding). */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result   = '';
        $buffer   = 0;
        $bits     = 0;

        foreach (str_split($data) as $byte) {
            $buffer  = ($buffer << 8) | ord($byte);
            $bits   += 8;

            while ($bits >= 5) {
                $bits   -= 5;
                $result .= $alphabet[($buffer >> $bits) & 0x1F];
            }
        }

        if ($bits > 0) {
            $result .= $alphabet[($buffer << (5 - $bits)) & 0x1F];
        }

        return $result;
    }

    /** Base32 decode with padding tolerance. */
    private static function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32   = strtoupper(rtrim($base32, '='));
        $result   = '';
        $buffer   = 0;
        $bits     = 0;

        foreach (str_split($base32) as $char) {
            if ($char === "\r" || $char === "\n" || $char === ' ') continue;
            $char = strtoupper($char);
            $ind  = strpos($alphabet, $char);
            if ($ind === false) {
                // Tolerate an internal ? added by some importers; otherwise reject.
                throw new RuntimeException('Invalid base32 char in TOTP secret.');
            }

            $buffer  = ($buffer << 5) | (int) $ind;
            $bits   += 5;

            if ($bits >= 8) {
                $bits    -= 8;
                $result  .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $result;
    }

    private static function normalizeBase32(string $secret): string
    {
        return self::base32Decode(self::clean($secret));
    }

    private static function clean(string $value): string
    {
        // Strip whitespace and lowercase; keep A-Z2-7 and '='
        return preg_replace('/[^A-Za-z2-7=]/', '', $value) ?? '';
    }
}
