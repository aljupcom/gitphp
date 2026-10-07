<?php

// Entry-point guard — must stay parseable on legacy PHP (plain syntax only).
// Shared hosts commonly default vhosts to PHP 7.x; without this gate the
// modern syntax further down produces an opaque parse-error 500.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PHP too old · GitPHP</title></head>'
        . '<body style="margin:0;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:#0d1117;color:#e6edf3;min-height:100vh;display:flex;align-items:center;justify-content:center">'
        . '<div style="max-width:520px;text-align:center;padding:2rem">'
        . '<h1 style="font-size:1.4rem">PHP ' . PHP_VERSION . ' is too old</h1>'
        . '<p style="color:#8b949e;line-height:1.6">GitPHP requires <b style="color:#e6edf3">PHP 8.1+</b>. '
        . 'Select PHP 8.1/8.2/8.3 for this website in your hosting control panel (CyberPanel: Websites → Manage → PHP), then reload this page.</p>'
        . '</div></body></html>';
    exit;
}

use App\App;
use App\Middleware\SecurityHeaders;
use App\Service\ErrorHandler;
use FastRoute\Dispatcher;

// Composer autoload: vendor/ is NOT part of the git repository, so a
// fresh "git pull" deployment on a new server hits this constantly.
// Render an actionable page instead of PHP's raw fatal-error 500.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (! file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    renderVendorMissing();
    exit;
}
require_once $autoload;

$basePath = dirname(__DIR__);

// Register custom Error & Exception Handling & Logging system
ErrorHandler::register($basePath);

// Fresh deployment: no configuration exists yet — send the visitor to the
// web installer. Existing installs always have .env and are never affected.
if (! file_exists($basePath . '/.env')) {
    header('Location: install.php');
    exit;
}

// The session MUST start before App::boot(): boot() computes the Twig
// session globals (is_logged_in, is_owner, current_user, unread badge),
// and a session started after boot would always render guest state.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // Ensure the project-local session store exists and is used. Without a
    // writable save path no session file is written, so PHP rotates the
    // session id on every request and flash/error messages are lost.
    $sessionDir = $basePath . '/storage/sessions';
    if (! is_dir($sessionDir)) @mkdir($sessionDir, 0775, true);
    if (is_dir($sessionDir)) session_save_path($sessionDir);

    // Harden the session cookie before it is ever issued.
    $isHttps  = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

$app      = App::boot($basePath);

(new SecurityHeaders())->apply();

try {
    [$status, $handler, $vars] = $app->router()->dispatch();

    switch ($status) {
        case Dispatcher::NOT_FOUND:
            http_response_code(404);
            echo $app->view()->render('partials/error.html.twig', [
                'code'    => 404,
                'message' => 'Page not found.',
            ]);
            break;

        case Dispatcher::METHOD_NOT_ALLOWED:
            http_response_code(405);
            echo $app->view()->render('partials/error.html.twig', [
                'code'    => 405,
                'message' => 'Method not allowed.',
            ]);
            break;

        case Dispatcher::FOUND:
            // Parse "ControllerClass@method"
            [$controllerClass, $method] = explode('@', (string) $handler, 2);
            $fqcn = 'App\\Controller\\' . $controllerClass;

            if (! class_exists($fqcn)) {
                throw new RuntimeException("Controller not found: {$fqcn}");
            }

            $controller = new $fqcn($app);

            // FastRoute captures are ALWAYS strings, but controllers may type
            // parameters as int (e.g. "{number:\d+}"). Coerce per signature.
            // Match parameters by name from $vars to safely ignore system routing prefixes like admin_sec_prefix.
            try {
                $reflection = new ReflectionMethod($controller, $method);
                $parameters = $reflection->getParameters();

                $args = [];

                foreach ($parameters as $param) {
                    $paramName = $param->getName();
                    $rawValue = null;

                    if (array_key_exists($paramName, $vars)) {
                        $rawValue = $vars[$paramName];
                    } elseif ($param->isDefaultValueAvailable()) {
                        $rawValue = $param->getDefaultValue();
                    } elseif ($param->allowsNull()) {
                        $rawValue = null;
                    }

                    $paramType = $param->getType();
                    if (
                        $paramType instanceof ReflectionNamedType
                        && $paramType->isBuiltin()
                        && $paramType->getName() === 'int'
                        && is_string($rawValue)
                        && preg_match('/^-?\d+$/', $rawValue)
                    ) {
                        $args[] = (int) $rawValue;
                        continue;
                    }

                    $args[] = $rawValue;
                }
            } catch (ReflectionException) {
                $filteredVars = array_filter($vars, fn($k) => $k !== 'admin_sec_prefix', ARRAY_FILTER_USE_KEY);
                $args = array_values($filteredVars);
            }

            $controller->{$method}(...$args);
            break;
    }
} catch (Throwable $e) {
    // Database unreachable after install: boot survives, but the first
    // query anywhere throws. Render dedicated status page in production.
    if (
        $e instanceof RuntimeException
        && str_contains($e->getMessage(), 'Database connection failed')
        && ! ErrorHandler::isDebug()
    ) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: 15');
        renderDbOffline();
        exit;
    }

    // Render using our custom ErrorHandler (Interactive Debug Mode UI or clean Production Mode)
    ErrorHandler::render($e);
}

/**
 * Standalone page for a deployment whose vendor/ directory is missing
 * (composer dependencies are git-ignored). No framework is booted yet,
 * so everything here must be plain PHP + inline HTML.
 */
if (!function_exists('renderVendorMissing')) {
function renderVendorMissing(): void
{
    $base        = dirname(__DIR__);
    $hasComposer = file_exists($base . '/composer.json');
    $hasEnv      = file_exists($base . '/.env');
    $hasLock     = file_exists($base . '/storage/installed.lock');
    $expected    = $base . '/vendor/autoload.php';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Dependencies missing · GitPHP</title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--border:#30363d;--fg:#e6edf3;--muted:#8b949e;--link:#58a6ff;--bad:#f85149;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;padding:1.5rem}
.card{max-width:600px;width:100%;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:2rem;animation:rise .4s cubic-bezier(.2,.7,.3,1)}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.icon{width:52px;height:52px;margin:0 auto 1rem;border-radius:50%;background:rgba(248,81,73,.14);border:1px solid rgba(248,81,73,.4);display:flex;align-items:center;justify-content:center;font-size:1.5rem}
h1{font-size:1.3rem;text-align:center;margin-bottom:.4rem}
p.sub{color:var(--muted);font-size:.92rem;line-height:1.6;text-align:center}
.chips{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin:1.2rem 0}
.chip{font-family:var(--mono);font-size:.75rem;border:1px solid var(--border);border-radius:999px;padding:.3em .8em;background:#0d1117;color:var(--muted)}
.chip b{color:var(--fg)}
pre.cmd{background:#010409;border:1px solid var(--border);border-radius:8px;padding:.9rem 1rem;font-family:var(--mono);font-size:.82rem;color:#79c0ff;overflow-x:auto;line-height:1.7}
.actions{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;margin-top:1.3rem}
.btn{display:inline-flex;font:inherit;font-size:.88rem;font-weight:600;color:var(--fg);background:#21262d;border:1px solid var(--border);border-radius:6px;padding:.55em 1.05em;text-decoration:none;transition:filter .12s}
.btn:hover{filter:brightness(1.15)}
.btn.primary{background:#238636;border-color:rgba(240,246,252,.1);color:#fff}
ol{color:var(--muted);font-size:.86rem;margin:.9rem 0 0 1.2rem;line-height:1.7}
</style>
</head>
<body>
<div class="card">
    <div class="icon">📦</div>
    <h1>Composer dependencies missing</h1>
    <p class="sub">The application code is deployed, but <b style="color:var(--fg)">vendor/</b> was not uploaded.<br>It is intentionally excluded from the git repository and must be generated once per server.</p>
    <div class="chips">
        <span class="chip">expected · <b><?= htmlspecialchars($expected) ?></b></span>
        <?php if ($hasComposer): ?><span class="chip">composer.json · <b>found ✓</b></span><?php else: ?><span class="chip" style="color:var(--bad)">composer.json · MISSING</span><?php endif; ?>
    </div>
    <pre class="cmd"># via SSH on the server:
cd <?= htmlspecialchars($base) . "\n" ?>composer install --no-dev

# or without SSH:
# upload the local vendor/ folder into the project root</pre>
    <ol>
        <li>If your host has no SSH, run <span style="font-family:var(--mono)">composer install</span> locally and upload the produced <span style="font-family:var(--mono)">vendor/</span> folder.</li>
        <li>Keep <span style="font-family:var(--mono)">.env</span> permissions strict (640) after uploading.</li>
    </ol>
    <div class="actions">
        <a class="btn primary" href="preflight.php">Run diagnostics →</a>
        <?php if (! $hasEnv && ! $hasLock): ?><a class="btn" href="install.php">Open installer</a><?php endif; ?>
    </div>
</div>
</body>
</html><?php
}

/**
 * Standalone (no-Twig) status page shown when MySQL cannot be reached.
 * Deliberately reveals no credentials — only the configured host:port.
 */
}
if (!function_exists('renderDbOffline')) {
function renderDbOffline(): void
{
    $installed = file_exists(dirname(__DIR__) . '/storage/installed.lock');
    $dbHost    = (string) env('DB_HOST', '127.0.0.1');
    $dbPort    = (string) env('DB_PORT', '3306');
    $appName   = (string) env('APP_NAME', 'GitPHP');
    $debug     = env('APP_DEBUG', false);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Database unavailable · <?= htmlspecialchars($appName) ?></title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--border:#30363d;--fg:#e6edf3;--muted:#8b949e;--accent:#58a6ff;--ok:#3fb950;--bad:#f85149;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;padding:1.5rem}
.card{max-width:560px;width:100%;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:2rem;text-align:center;animation:rise .45s cubic-bezier(.2,.7,.3,1)}
@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.beacon{position:relative;width:54px;height:54px;margin:0 auto 1.1rem;border-radius:50%;background:rgba(248,81,73,.14);border:1px solid rgba(248,81,73,.4);display:flex;align-items:center;justify-content:center}
.beacon::after{content:"";position:absolute;inset:-7px;border-radius:50%;border:1px solid rgba(248,81,73,.35);animation:pulse 2s ease-out infinite}
@keyframes pulse{0%{transform:scale(.75);opacity:.9}100%{transform:scale(1.35);opacity:0}}
.beacon svg{color:var(--bad)}
h1{font-size:1.25rem;margin-bottom:.4rem}
p.sub{color:var(--muted);font-size:.92rem;line-height:1.6}
.chips{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin:1.25rem 0}
.chip{font-family:var(--mono);font-size:.78rem;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:.32em .85em;background:#0d1117;display:inline-flex;gap:.45em;align-items:center}
.chip b{color:var(--fg);font-weight:600}
.dot{width:7px;height:7px;border-radius:50%;background:var(--bad);box-shadow:0 0 8px var(--bad);animation:blink 1.6s ease-in-out infinite}
@keyframes blink{50%{opacity:.35}}
.actions{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;margin-top:.6rem}
.btn{display:inline-flex;align-items:center;gap:.45em;font:inherit;font-size:.9rem;font-weight:600;color:var(--fg);background:#21262d;border:1px solid var(--border);border-radius:6px;padding:.55em 1.1em;cursor:pointer;text-decoration:none;transition:filter .12s,border-color .12s}
.btn:hover{border-color:var(--muted);filter:brightness(1.12)}
.btn.primary{background:#238636;border-color:rgba(240,246,252,.1);color:#fff}
details{text-align:start;margin-top:1.4rem;font-size:.84rem;color:var(--muted)}
summary{cursor:pointer;color:var(--accent);margin-bottom:.5rem}
details li{margin:.35rem 0 0 1.1rem;line-height:1.55}
code{font-family:var(--mono);background:#0d1117;border:1px solid var(--border);border-radius:4px;padding:.08em .35em;font-size:.88em}
</style>
</head>
<body>
<div class="card">
    <div class="beacon"><svg width="24" height="24" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM3.28 4.34a6.5 6.5 0 0 0 8.38 8.38Zm9.44-.68a6.5 6.5 0 0 0-8.38-8.38Z" opacity="0"/><path d="M4.47.22A8 8 0 0 1 15.78 11.53a.75.75 0 0 1-1.42-.48 6.5 6.5 0 0 0-9.41-7.65.75.75 0 0 1-.77-1.29Zm.75 2.53a.75.75 0 0 1 1.03.27A4.5 4.5 0 0 1 12.98 8c0 .64-.13 1.26-.38 1.81a.75.75 0 1 1-1.36-.62c.17-.37.24-.77.24-1.19a3 3 0 0 0-4.48-2.61.75.75 0 0 1-1.03-.27c-.2-.36-.07-.82.29-1.02ZM8.75 10.5a.75.75 0 0 1-1.5 0v-3a.75.75 0 0 1 1.5 0ZM8 13.5A.75.75 0 1 1 8 12a.75.75 0 0 1 0 1.5Z"/></svg></div>
    <h1>Database unavailable</h1>
    <p class="sub"><?= htmlspecialchars($appName) ?> is up, but it cannot reach its MySQL server right now.<br>Everything else on this machine is fine — this usually recovers automatically.</p>
    <div class="chips">
        <span class="chip"><span class="dot"></span>mysql · <b><?= htmlspecialchars($dbHost) ?>:<?= htmlspecialchars($dbPort) ?></b></span>
        <span class="chip">status · <b>offline</b></span>
        <?php if ($installed): ?><span class="chip">install · <b>locked ✓</b></span><?php endif; ?>
    </div>
    <div class="actions">
        <button type="button" class="btn primary" onclick="location.reload()">↻ Retry now</button>
        <?php if (! $installed): ?><a class="btn" href="install.php">Open installer</a><?php endif; ?>
    </div>
    <details>
        <summary>What can I check?</summary>
        <ul>
            <li>Is MySQL / MariaDB running? (<code>systemctl status mysql</code> or your host's control panel)</li>
            <li>Do the credentials in <code>.env</code> still match? (<code>DB_HOST</code>, <code>DB_USER</code>, <code>DB_PASS</code>)</li>
            <li>Is the database server reachable from this host (firewall, remote access)?</li>
        </ul>
    </details>
</div>
</body>
</html><?php
}

}
