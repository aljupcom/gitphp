<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lightweight, robust application logger with PSR-3 inspired levels,
 * log rotation, sensitive data masking, and structured formatting.
 */
final class Logger
{
    public const EMERGENCY = 'EMERGENCY';
    public const ALERT     = 'ALERT';
    public const CRITICAL  = 'CRITICAL';
    public const ERROR     = 'ERROR';
    public const WARNING   = 'WARNING';
    public const NOTICE    = 'NOTICE';
    public const INFO      = 'INFO';
    public const DEBUG     = 'DEBUG';

    private static ?string $logPath = null;

    /**
     * Set custom log directory path.
     */
    public static function setLogPath(string $path): void
    {
        self::$logPath = rtrim($path, '/\\');
    }

    /**
     * Get or determine log directory path.
     */
    public static function getLogPath(): string
    {
        if (self::$logPath !== null) {
            return self::$logPath;
        }

        $base = dirname(__DIR__, 2);
        $candidate = $base . '/storage/logs';

        if (! is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }

        if (is_dir($candidate) && is_writable($candidate)) {
            self::$logPath = $candidate;
        } else {
            self::$logPath = sys_get_temp_dir();
        }

        return self::$logPath;
    }

    /**
     * Log an error message or exception.
     *
     * @param string|mixed $level
     * @param string $message
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            $level = strtoupper($level);
            $dir = self::getLogPath();
            $date = date('Y-m-d');
            $file = $dir . "/app-{$date}.log";

            $timestamp = date('Y-m-d H:i:s P');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
            $uri = $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? implode(' ', $_SERVER['argv'] ?? []) : '-');
            $refId = $context['ref_id'] ?? null;

            $header = sprintf("[%s] [%s]", $timestamp, $level);
            if ($refId) {
                $header .= " [Ref: {$refId}]";
            }
            $header .= " [{$method} {$uri}] [IP: {$ip}]";

            $logLine = "{$header} {$message}\n";

            // Clean message of absolute paths
            $message = self::sanitizePath($message);

            // If context contains exception or details, format cleanly
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $e = $context['exception'];
                $cleanMsg = self::sanitizePath($e->getMessage());
                $cleanFile = self::sanitizePath($e->getFile());
                $logLine .= sprintf("  Exception: %s: %s in %s:%d\n", get_class($e), $cleanMsg, $cleanFile, $e->getLine());
                $logLine .= "  Stack Trace:\n" . self::indentTrace(self::sanitizePath($e->getTraceAsString())) . "\n";
                unset($context['exception']);
            }

            if (! empty($context)) {
                $cleanedContext = self::maskSensitive($context);
                $logLine .= "  Context: " . json_encode($cleanedContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }

            $logLine .= str_repeat('-', 80) . "\n";

            @file_put_contents($file, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Failsafe error_log
            @error_log("[{$level}] {$message}");
        }
    }

    public static function emergency(string $message, array $context = []): void
    {
        self::log(self::EMERGENCY, $message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        self::log(self::ALERT, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::log(self::NOTICE, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Indent stack trace lines for clean log files.
     */
    private static function indentTrace(string $trace): string
    {
        $lines = explode("\n", $trace);
        return implode("\n", array_map(static fn($line) => '    ' . $line, $lines));
    }

    /**
     * Mask sensitive fields (passwords, tokens, keys) in data before logging.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizePath(string $str): string
    {
        $base = dirname(__DIR__, 2);
        $str = str_replace($base . '/', '', $str);
        $str = str_replace($base, '.', $str);
        $str = preg_replace('#/home/[^/]+/(?:public_html/)?#', '', $str);
        return $str;
    }

    public static function maskSensitive(array $data): array
    {
        $sensitiveKeys = [
            'password', 'pass', 'db_pass', 'db_password', 'secret', 'token',
            'api_token', 'api_key', 'auth', 'authorization', 'bearer',
            'totp', 'totp_secret', 'ssh_key', 'private_key', 'cookie',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitiveKeys as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $result[$key] = '********';
            } elseif (is_array($value)) {
                $result[$key] = self::maskSensitive($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
