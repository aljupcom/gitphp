<?php

declare(strict_types=1);

namespace App\Service;

use Throwable;
use ErrorException;

final class ErrorHandler
{
    private static ?string $basePath = null;
    private static bool $registered = false;
    private static ?bool $forceDebug = null;

    /**
     * Register global error, exception, and shutdown handlers.
     */
    public static function register(?string $basePath = null, ?bool $forceDebug = null): void
    {
        if (self::$registered) {
            return;
        }

        if ($basePath !== null) {
            self::$basePath = rtrim($basePath, '/\\');
            Logger::setLogPath(self::$basePath . '/storage/logs');
        }

        self::$forceDebug = $forceDebug;

        // Convert PHP errors into ErrorException when appropriate
        set_error_handler([self::class, 'handleError']);

        // Handle uncaught exceptions
        set_exception_handler([self::class, 'handleException']);

        // Catch fatal errors during shutdown
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    /**
     * Handle PHP errors (notices, warnings, deprecations, user errors).
     */
    public static function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        // Suppressed with @ operator
        if (! (error_reporting() & $level)) {
            return false;
        }

        // Fatal/error level conversions
        $fatalLevels = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

        if ($level & $fatalLevels) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }

        // Warnings / notices: log them and continue without crashing if in production
        $levelName = match ($level) {
            E_WARNING, E_USER_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE   => 'NOTICE',
            E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
            default                   => 'PHP_ERROR',
        };

        Logger::log($levelName, "{$message} in {$file}:{$line}");

        // In debug mode, throw warnings/errors for strict development visibility
        if (self::isDebug() && ($level & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR))) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }

        return true;
    }

    /**
     * Handle uncaught exceptions/throwables.
     */
    public static function handleException(Throwable $e): void
    {
        self::render($e);
        exit(1);
    }

    /**
     * Handle fatal errors during PHP shutdown.
     */
    public static function handleShutdown(): void
    {
        $lastError = error_get_last();
        if ($lastError === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (in_array($lastError['type'], $fatalTypes, true)) {
            // Clean active output buffers to prevent broken markup
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }

            $exception = new ErrorException(
                $lastError['message'],
                0,
                $lastError['type'],
                $lastError['file'],
                $lastError['line']
            );

            self::render($exception);
        }
    }

    /**
     * Check if application is in Debug / Development Mode.
     */
    public static function isDebug(): bool
    {
        if (self::$forceDebug !== null) {
            return self::$forceDebug;
        }

        if (function_exists('env')) {
            $debug = env('APP_DEBUG');
            if ($debug !== null) {
                return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $val = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Render the exception page (Debug rich dashboard or secure production page).
     */
    public static function render(Throwable $e, ?bool $debug = null): void
    {
        $isDebug = $debug !== null ? $debug : self::isDebug();
        $refId = 'ERR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // Client-input validation rejections (bad refs/paths, template junk in
        // URLs) are routine bot noise, not server faults: downgrade to a
        // WARNING with a 400 response instead of a full ERROR + 500 stack.
        if ($e instanceof \RuntimeException && preg_match(
            '/contains (?:disallowed characters|null byte|traversal sequence)/i',
            $e->getMessage()
        )) {
            Logger::warning(self::sanitizeMessage($e->getMessage()), [
                'ref_id' => $refId,
                'url'    => $_SERVER['REQUEST_URI'] ?? '-',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '-',
            ]);

            if (! headers_sent()) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Bad request: invalid ref or path.';
            return;
        }

        // Log the exception
        Logger::error(self::sanitizeMessage($e->getMessage()), [
            'exception' => $e,
            'ref_id'    => $refId,
            'url'       => $_SERVER['REQUEST_URI'] ?? '-',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? '-',
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '-',
            'user_id'   => $_SESSION['user']['id'] ?? ($_SESSION['is_owner'] ?? false ? 'owner' : 'guest'),
        ]);

        if (! headers_sent()) {
            http_response_code(500);
        }

        if (self::isJsonRequest()) {
            if (! headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            if ($isDebug) {
                echo json_encode([
                    'error'        => true,
                    'status'       => 500,
                    'exception'    => get_class($e),
                    'message'      => self::sanitizeMessage($e->getMessage()),
                    'file'         => self::shortenPath($e->getFile()),
                    'line'         => $e->getLine(),
                    'reference_id' => $refId,
                    'trace'        => explode("\n", $e->getTraceAsString()),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'error'        => true,
                    'status'       => 500,
                    'message'      => 'Internal Server Error',
                    'reference_id' => $refId,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        if (! headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        if ($isDebug) {
            self::renderDevelopmentDashboard($e, $refId);
        } else {
            self::renderProductionErrorPage($refId);
        }
    }

    /**
     * Determine if request is requesting JSON output.
     */
    private static function isJsonRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')) {
            return true;
        }

        return str_starts_with($uri, '/api/') || str_starts_with($uri, '/api?');
    }

    /**
     * Render sleek, modern, interactive developer debug error dashboard.
     */
    private static function renderDevelopmentDashboard(Throwable $e, string $refId): void
    {
        $className   = get_class($e);
        $shortClass  = basename(str_replace('\\', '/', $className));
        $message     = self::sanitizeMessage($e->getMessage()) ?: '(No message provided)';
        $file        = $e->getFile();
        $line        = $e->getLine();
        $codeSnippet = self::extractCodeSnippet($file, $line);
        $traceFrames = self::parseTrace($e);

        $appName     = function_exists('env') ? (string) env('APP_NAME', 'GitPHP') : 'GitPHP';
        $phpVersion  = PHP_VERSION;
        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB';
        $peakMemory  = round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB';
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri         = $_SERVER['REQUEST_URI'] ?? '/';

        $getParams   = $_GET;
        $postParams  = Logger::maskSensitive($_POST);
        $sessionData = isset($_SESSION) ? Logger::maskSensitive($_SESSION) : [];
        $serverData  = Logger::maskSensitive($_SERVER);

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($shortClass) ?>: <?= htmlspecialchars($message) ?> · <?= htmlspecialchars($appName) ?> Debug</title>
    <style>
        :root {
            --bg: #090d13;
            --panel: #111620;
            --panel-header: #171d2b;
            --border: #21293a;
            --border-highlight: #313c54;
            --text-primary: #e6edf3;
            --text-muted: #8b949e;
            --text-dim: #656d76;
            --danger: #f85149;
            --danger-bg: rgba(248, 81, 73, 0.12);
            --danger-border: rgba(248, 81, 73, 0.35);
            --accent: #58a6ff;
            --accent-bg: rgba(88, 166, 255, 0.12);
            --badge-bg: #1f293d;
            --badge-fg: #79c0ff;
            --line-active: rgba(248, 81, 73, 0.22);
            --line-active-num: #f85149;
            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif;
            --font-mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg);
            color: var(--text-primary);
            font-family: var(--font-sans);
            line-height: 1.5;
            font-size: 14px;
            padding: 0 0 4rem 0;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Bar */
        .topbar {
            background: var(--panel-header);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(8px);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .brand-badge {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
            padding: 0.15rem 0.6rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .topbar-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.82rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
        }
        .topbar-btn {
            background: var(--panel);
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 0.35rem 0.8rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.15s ease;
        }
        .topbar-btn:hover {
            border-color: var(--border-highlight);
            background: #1c2436;
            color: #fff;
        }

        /* Container */
        .container {
            max-width: 1300px;
            margin: 1.75rem auto 0;
            padding: 0 1.5rem;
        }

        /* Hero Exception Banner */
        .error-banner {
            background: var(--panel);
            border: 1px solid var(--border);
            border-left: 4px solid var(--danger);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
        }
        .error-type {
            font-family: var(--font-mono);
            font-size: 0.88rem;
            color: var(--danger);
            font-weight: 600;
            margin-bottom: 0.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .error-message {
            font-size: 1.35rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.35;
            word-break: break-word;
            margin-bottom: 0.75rem;
        }
        .error-location {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            color: var(--text-muted);
            background: #090d13;
            border: 1px solid var(--border);
            padding: 0.45rem 0.85rem;
            border-radius: 6px;
            display: inline-block;
            word-break: break-all;
        }
        .error-location b {
            color: var(--badge-fg);
        }

        /* Main Grid: Stack Trace & Code / Request */
        .grid-layout {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .grid-layout { grid-template-columns: 1fr; }
        }

        /* Stack Frames Column */
        .card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
        }
        .card-header {
            background: var(--panel-header);
            border-bottom: 1px solid var(--border);
            padding: 0.85rem 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header h3 {
            font-size: 0.95rem;
            color: var(--text-primary);
        }

        .trace-list {
            list-style: none;
            max-height: 680px;
            overflow-y: auto;
        }
        .trace-item {
            padding: 0.85rem 1.1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: background 0.12s ease;
        }
        .trace-item:last-child {
            border-bottom: none;
        }
        .trace-item:hover {
            background: #141b27;
        }
        .trace-item.active {
            background: #192233;
            border-left: 3px solid var(--accent);
        }
        .trace-item-title {
            font-family: var(--font-mono);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-primary);
            word-break: break-all;
            margin-bottom: 0.2rem;
        }
        .trace-item.active .trace-item-title {
            color: var(--accent);
        }
        .trace-item-file {
            font-family: var(--font-mono);
            font-size: 0.74rem;
            color: var(--text-muted);
            word-break: break-all;
        }

        /* Code Viewer & Tabs Column */
        .tabs-header {
            display: flex;
            background: var(--panel-header);
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 0.85rem 1.25rem;
            color: var(--text-muted);
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
            transition: all 0.15s ease;
        }
        .tab-btn:hover {
            color: var(--text-primary);
        }
        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
            background: rgba(88, 166, 255, 0.05);
        }

        .tab-pane {
            display: none;
            padding: 1.25rem;
        }
        .tab-pane.active {
            display: block;
        }

        /* Code Snippet Table */
        .code-window {
            background: #090d13;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            font-family: var(--font-mono);
            font-size: 0.82rem;
        }
        .code-bar {
            background: #141a26;
            padding: 0.5rem 1rem;
            font-size: 0.78rem;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .code-table {
            width: 100%;
            border-collapse: collapse;
        }
        .code-table tr td {
            padding: 0.2rem 0.5rem;
            vertical-align: top;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .code-num {
            width: 48px;
            text-align: right;
            color: var(--text-dim);
            user-select: none;
            border-right: 1px solid var(--border);
            padding-right: 0.75rem !important;
        }
        .code-content {
            padding-left: 0.85rem !important;
            color: #d1d7e0;
        }
        .code-row.active {
            background: var(--line-active);
        }
        .code-row.active .code-num {
            color: var(--line-active-num);
            font-weight: 700;
        }
        .code-row.active .code-content {
            color: #fff;
            font-weight: 600;
        }

        /* Environment / Context Key-Value Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-family: var(--font-mono);
            font-size: 0.8rem;
        }
        .data-table tr {
            border-bottom: 1px solid var(--border);
        }
        .data-table tr:last-child {
            border-bottom: none;
        }
        .data-table th {
            text-align: left;
            padding: 0.6rem 0.85rem;
            color: var(--badge-fg);
            width: 220px;
            vertical-align: top;
            background: rgba(255, 255, 255, 0.015);
        }
        .data-table td {
            padding: 0.6rem 0.85rem;
            color: var(--text-primary);
            word-break: break-all;
            vertical-align: top;
        }
        .empty-state {
            padding: 2rem;
            text-align: center;
            color: var(--text-dim);
            font-size: 0.88rem;
        }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: #238636;
            color: #fff;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
            display: none;
            animation: fadeIn 0.2s ease;
            z-index: 999;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <span style="font-size: 1.2rem;">⚡</span>
        <span><?= htmlspecialchars($appName) ?> Debug Mode</span>
        <span class="brand-badge">HTTP 500</span>
    </div>
    <div class="topbar-meta">
        <span>PHP <?= htmlspecialchars($phpVersion) ?></span>
        <span>Mem: <?= htmlspecialchars($memoryUsage) ?> (Peak <?= htmlspecialchars($peakMemory) ?>)</span>
        <span>Ref: <b style="color:var(--text-primary)"><?= htmlspecialchars($refId) ?></b></span>
        <button class="topbar-btn" onclick="copyTrace()">📋 Copy Trace</button>
        <button class="topbar-btn" onclick="location.reload()">↻ Reload</button>
    </div>
</div>

<div class="container">
    <div class="error-banner">
        <div class="error-type">
            <span>⚠️</span>
            <span><?= htmlspecialchars($className) ?></span>
        </div>
        <h1 class="error-message"><?= htmlspecialchars($message) ?></h1>
        <div class="error-location">
            <?= htmlspecialchars($file) ?>:<b><?= $line ?></b>
        </div>
    </div>

    <div class="grid-layout">
        <!-- Left: Stack Frames -->
        <div class="card">
            <div class="card-header">
                <h3>Call Stack (<?= count($traceFrames) ?> frames)</h3>
            </div>
            <ul class="trace-list" id="traceList">
                <?php foreach ($traceFrames as $idx => $frame): ?>
                    <li class="trace-item <?= $idx === 0 ? 'active' : '' ?>"
                        onclick="switchFrame(<?= $idx ?>)"
                        id="frame-item-<?= $idx ?>">
                        <div class="trace-item-title"><?= htmlspecialchars($frame['call']) ?></div>
                        <div class="trace-item-file"><?= htmlspecialchars($frame['short_file']) ?>:<?= $frame['line'] ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Right: Code Snippet & Request Tabs -->
        <div class="card">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="switchTab('code', this)">🔍 Source Code</button>
                <button class="tab-btn" onclick="switchTab('request', this)">🌐 Request</button>
                <button class="tab-btn" onclick="switchTab('params', this)">📦 Query &amp; Body</button>
                <button class="tab-btn" onclick="switchTab('session', this)">🔐 Session</button>
                <button class="tab-btn" onclick="switchTab('server', this)">🖥️ Server</button>
            </div>

            <!-- Tab: Source Code -->
            <div class="tab-pane active" id="tab-code">
                <?php foreach ($traceFrames as $idx => $frame): ?>
                    <div class="frame-code-view" id="frame-code-<?= $idx ?>" style="<?= $idx === 0 ? '' : 'display:none;' ?>">
                        <div class="code-window">
                            <div class="code-bar">
                                <span><?= htmlspecialchars($frame['file'] ?: 'Internal PHP / Unknown') ?></span>
                                <span>Line <b><?= $frame['line'] ?></b></span>
                            </div>
                            <?php if (! empty($frame['snippet'])): ?>
                                <table class="code-table">
                                    <?php foreach ($frame['snippet'] as $snippetLine => $snippetContent): ?>
                                        <tr class="code-row <?= $snippetLine === $frame['line'] ? 'active' : '' ?>">
                                            <td class="code-num"><?= $snippetLine ?></td>
                                            <td class="code-content"><?= htmlspecialchars($snippetContent) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">Source code unavailable for internal/compiled frame.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Tab: Request Details -->
            <div class="tab-pane" id="tab-request">
                <table class="data-table">
                    <tr><th>HTTP Method</th><td><?= htmlspecialchars($method) ?></td></tr>
                    <tr><th>Request URI</th><td><?= htmlspecialchars($uri) ?></td></tr>
                    <tr><th>Client IP</th><td><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></td></tr>
                    <tr><th>User Agent</th><td><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? '-') ?></td></tr>
                    <tr><th>Referer</th><td><?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '-') ?></td></tr>
                    <tr><th>Protocol</th><td><?= htmlspecialchars($_SERVER['SERVER_PROTOCOL'] ?? '-') ?></td></tr>
                    <tr><th>Host</th><td><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? '-') ?></td></tr>
                </table>
            </div>

            <!-- Tab: Query & Body Parameters -->
            <div class="tab-pane" id="tab-params">
                <h4 style="margin: 0.5rem 0 0.8rem; font-size: 0.9rem; color: var(--text-primary)">GET Query Parameters</h4>
                <?php if (! empty($getParams)): ?>
                    <table class="data-table" style="margin-bottom: 1.5rem">
                        <?php foreach ($getParams as $k => $v): ?>
                            <tr><th><?= htmlspecialchars((string) $k) ?></th><td><?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-state" style="padding: 0.75rem 0 1.5rem">No GET parameters.</div>
                <?php endif; ?>

                <h4 style="margin: 0.5rem 0 0.8rem; font-size: 0.9rem; color: var(--text-primary)">POST Body Parameters (Masked)</h4>
                <?php if (! empty($postParams)): ?>
                    <table class="data-table">
                        <?php foreach ($postParams as $k => $v): ?>
                            <tr><th><?= htmlspecialchars((string) $k) ?></th><td><?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-state" style="padding: 0.75rem 0">No POST parameters.</div>
                <?php endif; ?>
            </div>

            <!-- Tab: Session Data -->
            <div class="tab-pane" id="tab-session">
                <?php if (! empty($sessionData)): ?>
                    <table class="data-table">
                        <?php foreach ($sessionData as $k => $v): ?>
                            <tr><th><?= htmlspecialchars((string) $k) ?></th><td><?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></td></tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="empty-state">Session is empty or not started.</div>
                <?php endif; ?>
            </div>

            <!-- Tab: Server ($_SERVER) -->
            <div class="tab-pane" id="tab-server">
                <table class="data-table">
                    <?php foreach ($serverData as $k => $v): ?>
                        <tr><th><?= htmlspecialchars((string) $k) ?></th><td><?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast">Trace copied to clipboard!</div>

<script>
function switchTab(tabId, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
    if (el) el.classList.add('active');
}

function switchFrame(idx) {
    document.querySelectorAll('.trace-item').forEach(i => i.classList.remove('active'));
    document.querySelectorAll('.frame-code-view').forEach(v => v.style.display = 'none');

    const item = document.getElementById('frame-item-' + idx);
    const code = document.getElementById('frame-code-' + idx);
    if (item) item.classList.add('active');
    if (code) code.style.display = 'block';

    switchTab('code', document.querySelector('.tab-btn:first-child'));
}

function copyTrace() {
    const text = `Error: <?= addslashes($className) ?>\nMessage: <?= addslashes($message) ?>\nFile: <?= addslashes($file) ?>:<?= $line ?>\n\nStack Trace:\n<?= addslashes($e->getTraceAsString()) ?>`;
    navigator.clipboard.writeText(text).then(() => {
        const toast = document.getElementById('toast');
        toast.style.display = 'block';
        setTimeout(() => toast.style.display = 'none', 2500);
    });
}
</script>
</body>
</html>
<?php
    }

    /**
     * Render sleek, clean user-facing error page for production mode.
     */
    private static function renderProductionErrorPage(string $refId): void
    {
        $appName = function_exists('env') ? (string) env('APP_NAME', 'GitPHP') : 'GitPHP';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Server Error (500) · <?= htmlspecialchars($appName) ?></title>
    <style>
        :root {
            --bg: #0d1117;
            --panel: #161b22;
            --border: #30363d;
            --text-primary: #e6edf3;
            --text-muted: #8b949e;
            --danger: #f85149;
            --btn-bg: #21262d;
            --btn-border: #30363d;
            --btn-primary-bg: #238636;
            --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            padding: 1.5rem;
        }
        .card {
            max-width: 560px;
            width: 100%;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 16px 36px rgba(0,0,0,0.4);
            animation: rise 0.4s cubic-bezier(0.2, 0.7, 0.3, 1);
        }
        @keyframes rise {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.25rem;
            border-radius: 50%;
            background: rgba(248, 81, 73, 0.14);
            border: 1px solid rgba(248, 81, 73, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #fff;
        }
        p.sub {
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .ref-box {
            background: #0d1117;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-family: var(--mono);
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
            display: inline-block;
        }
        .ref-box b {
            color: var(--text-primary);
        }
        .actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font: inherit;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            background: var(--btn-bg);
            border: 1px solid var(--btn-border);
            border-radius: 6px;
            padding: 0.6rem 1.2rem;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn:hover {
            filter: brightness(1.15);
            border-color: var(--text-muted);
        }
        .btn.primary {
            background: var(--btn-primary-bg);
            border-color: rgba(240, 246, 252, 0.1);
            color: #fff;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="icon">⚠️</div>
    <h1>500 — Server Error</h1>
    <p class="sub">An unexpected error occurred while processing your request.<br>The technical team has been notified and logged the event.</p>
    <div class="ref-box">
        Incident ID: <b><?= htmlspecialchars($refId) ?></b>
    </div>
    <div class="actions">
        <a class="btn primary" href="/">Return to Home</a>
        <button class="btn" onclick="history.back()">Go Back</button>
    </div>
</div>
</body>
</html>
<?php
    }

    /**
     * Parse Throwable trace frames with code snippets.
     *
     * @return array<int, array{file: string, short_file: string, line: int, call: string, snippet: array<int, string>}>
     */
    private static function parseTrace(Throwable $e): array
    {
        $frames = [];

        // First frame: point of origin
        $frames[] = [
            'file'       => $e->getFile(),
            'short_file' => self::shortenPath($e->getFile()),
            'line'       => $e->getLine(),
            'call'       => get_class($e) . ' thrown',
            'snippet'    => self::extractCodeSnippet($e->getFile(), $e->getLine()),
        ];

        foreach ($e->getTrace() as $trace) {
            $file = $trace['file'] ?? '';
            $line = $trace['line'] ?? 0;
            $class = $trace['class'] ?? '';
            $type = $trace['type'] ?? '';
            $function = $trace['function'] ?? '';

            $call = $class ? "{$class}{$type}{$function}()" : "{$function}()";

            $frames[] = [
                'file'       => $file,
                'short_file' => $file ? self::shortenPath($file) : '[internal]',
                'line'       => $line,
                'call'       => $call,
                'snippet'    => $file ? self::extractCodeSnippet($file, $line) : [],
            ];
        }

        return $frames;
    }

    /**
     * Extract ~8 lines around target line from a source file.
     *
     * @return array<int, string>
     */
    private static function extractCodeSnippet(string $file, int $targetLine, int $radius = 7): array
    {
        if (! file_exists($file) || ! is_readable($file) || $targetLine <= 0) {
            return [];
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start = max(1, $targetLine - $radius);
        $end   = min(count($lines), $targetLine + $radius);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[$i] = $lines[$i - 1] ?? '';
        }

        return $snippet;
    }

    /**
     * Shorten full file path for cleaner display.
     */
    public static function shortenPath(string $path): string
    {
        if (empty($path)) {
            return '';
        }
        if (self::$basePath && str_starts_with($path, self::$basePath)) {
            $rel = ltrim(substr($path, strlen(self::$basePath)), '/\\');
            return $rel !== '' ? $rel : '.';
        }
        // General fallback for any absolute unix path inside document root or app
        if (preg_match('#/home/[^/]+(?:/public_html)?/(.*)#', $path, $m)) {
            return $m[1];
        }
        return $path;
    }

    public static function sanitizeMessage(string $msg): string
    {
        if (self::$basePath) {
            $msg = str_replace(self::$basePath . '/', '', $msg);
            $msg = str_replace(self::$basePath, '.', $msg);
        }
        $msg = preg_replace('#/home/[^/]+/(?:public_html/)?#', '', $msg);
        return $msg;
    }
}
