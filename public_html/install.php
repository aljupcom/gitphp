<?php

// Entry-point guard — plain legacy-safe syntax on purpose: shared hosts
// often default new vhosts to PHP 7.x and this must render a clear message
// instead of an opaque parse-error 500.
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PHP too old · GitPHP</title></head>'
        . '<body style="margin:0;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;background:#0d1117;color:#e6edf3;min-height:100vh;display:flex;align-items:center;justify-content:center">'
        . '<div style="max-width:520px;text-align:center;padding:2rem">'
        . '<h1 style="font-size:1.4rem">PHP ' . PHP_VERSION . ' is too old</h1>'
        . '<p style="color:#8b949e;line-height:1.6">The GitPHP installer requires <b style="color:#e6edf3">PHP 8.1+</b>. '
        . 'Select PHP 8.1/8.2/8.3 for this website in your hosting control panel (CyberPanel: Websites → List → Manage → PHP), then reload.</p>'
        . '</div></body></html>';
    exit;
}

/**
 * GitPHP — Web installer.
 *
 * Standalone (no Composer dependencies) so it can run on any shared host
 * right after the files are uploaded. It is locked automatically once the
 * application is installed (storage/installed.lock) and refuses to run again.
 *
 * Interactive multi-step wizard (GitHub-style):
 *   1 · Environment & requirements
 *   2 · Database          (with live connection test)
 *   3 · Site              (identity, URL, timezone)
 *   4 · Owner account     (with strength meter)
 *   5 · Install           (animated setup log) → done screen
 */

$basePath = dirname(__DIR__);

require_once $basePath . '/src/Setup/Installer.php';

use App\Setup\Installer;

session_start();

$installer = new Installer($basePath);

// ── Lock guard ────────────────────────────────────────────────────────
if (Installer::isInstalled($basePath)) {
    http_response_code(403);
    render('already-installed', ['app_name' => 'GitPHP']);
    exit;
}

// ── CSRF token ────────────────────────────────────────────────────────
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['install_csrf'];

// ── Defaults ──────────────────────────────────────────────────────────
$scheme  = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$detUrl  = $scheme . '://' . $host;

$f = [
    'db_host'     => '127.0.0.1',
    'db_port'     => '3306',
    'db_name'     => 'gitphp',
    'db_user'     => '',
    'db_pass'     => '',
    'app_name'    => 'GitPHP',
    'app_url'     => $detUrl,
    'owner_name'  => 'admin',
    'timezone'    => 'UTC',
    'repos_path'  => $basePath . DIRECTORY_SEPARATOR . 'repos',
    'owner_pass'  => '',
    'owner_pass2' => '',
];

$errors = [];
$log    = [];
$done   = false;

$timezones = [
    'UTC', 'Asia/Riyadh', 'Asia/Dubai', 'Asia/Kuwait', 'Asia/Qatar', 'Asia/Baghdad',
    'Asia/Jordan', 'Asia/Beirut', 'Africa/Cairo', 'Africa/Tripoli', 'Europe/London',
    'Europe/Berlin', 'Europe/Istanbul', 'America/New_York', 'America/Chicago',
    'America/Los_Angeles',
];

// ── AJAX endpoints (wizard live helpers) ──────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = (string) $_GET['ajax'];

    if ($ajax === 'status') {
        $requirements = $installer->checkRequirements();
        echo json_encode([
            'ok'           => ! in_array(false, array_column($requirements, 'ok'), true),
            'requirements' => $requirements,
        ]);
        exit;
    }

    if ($ajax === 'testdb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (! is_array($in)) $in = $_POST;

        if (! hash_equals($csrf, (string) ($in['csrf_token'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid security token.']);
            exit;
        }

        $started = microtime(true);
        $error   = $installer->testConnection(
            trim((string) ($in['db_host'] ?? '127.0.0.1')),
            (int) ((string) ($in['db_port'] ?? '') ?: 3306),
            trim((string) ($in['db_name'] ?? '')),
            trim((string) ($in['db_user'] ?? '')),
            (string) ($in['db_pass'] ?? ''),
        );
        $latency = (int) round((microtime(true) - $started) * 1000);

        echo json_encode($error !== null
            ? ['ok' => false, 'error' => $error, 'latency' => $latency]
            : ['ok' => true, 'latency' => $latency]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── Handle submission ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! isset($_POST['step_probe'])) {
    if (! hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Invalid security token. Reload the page and try again.';
    } else {
        foreach (array_keys($f) as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                $f[$k] = trim($_POST[$k]);
            }
        }

        $authKeysPath = isset($_POST['authorized_keys_path']) && is_string($_POST['authorized_keys_path'])
            ? trim($_POST['authorized_keys_path'])
            : '';

        $errors = $installer->checkForm($f);

        if ($errors === []) {
            $dbError = $installer->testConnection(
                $f['db_host'],
                (int) ($f['db_port'] ?: 3306),
                $f['db_name'],
                $f['db_user'],
                $f['db_pass'],
            );

            if ($dbError !== null) {
                $errors[] = 'Database connection failed: ' . $dbError;
            } else {
                $result = $installer->install(array_merge($f, [
                    'authorized_keys_path' => $authKeysPath,
                ]));
                $log    = $result['log'];

                if (! $result['ok']) {
                    $errors[] = $result['error'];
                } else {
                    $done = true;
                    session_destroy();
                }
            }
        }
    }
}

$requirements = $installer->checkRequirements();
$reqOk        = ! in_array(false, array_column($requirements, 'ok'), true);

$environment = [
    ['PHP version', PHP_VERSION],
    ['Operating system', PHP_OS_FAMILY],
    ['Web server', (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown')],
    ['Max upload size', (string) ini_get('upload_max_filesize')],
    ['Free disk space', function_exists('disk_free_space') ? number_format((float) disk_free_space($basePath) / 1073741824, 1) . ' GB' : 'n/a'],
];

render('wizard', [
    'requirements' => $requirements,
    'reqOk'        => $reqOk,
    'f'            => $f,
    'errors'       => $errors,
    'log'          => $log,
    'done'         => $done,
    'csrf'         => $csrf,
    'timezones'    => $timezones,
    'environment'  => $environment,
]);

// ══════════════════════════════════════════════════════════════════════

/** @param array<string, mixed> $data */
function render(string $view, array $data = []): void
{
    extract($data);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>GitPHP &mdash; Installation</title>
<style>
:root{--bg:#0d1117;--bg-inset:#010409;--panel:#161b22;--border:#30363d;--fg:#e6edf3;--muted:#8b949e;--accent:#238636;--accent-h:#2ea043;--link:#58a6ff;--err:#f85149;--ok:#3fb950;--warn:#d29922;--mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,Consolas,monospace}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--fg);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;line-height:1.5;padding-bottom:4rem}
::selection{background:rgba(56,139,253,.4)}

.topbar{position:sticky;top:0;z-index:50;display:flex;align-items:center;gap:.7rem;padding:.85rem 1.25rem;background:rgba(13,17,23,.92);border-bottom:1px solid var(--border);backdrop-filter:blur(8px)}
.mark{width:26px;height:26px;border-radius:6px;background:linear-gradient(135deg,#238636,#58a6ff);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.95rem;flex:none}
.topbar h1{font-size:1rem;font-weight:600}
.topbar .tag{font-size:.72rem;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:.12em .7em;margin-inline-start:.35rem}

.stepper{max-width:780px;margin:2rem auto 0;display:flex;align-items:flex-start;padding:0 1.25rem}
.step{flex:1;display:flex;flex-direction:column;align-items:center;gap:.45rem;position:relative;text-align:center}
.step::before{content:"";position:absolute;top:14px;left:-50%;width:100%;height:2px;background:var(--border)}
.step:first-child::before{display:none}
.step.done::before,.step.active::before{background:linear-gradient(90deg,var(--accent),var(--link))}
.bullet{width:28px;height:28px;border-radius:50%;border:2px solid var(--border);background:var(--panel);color:var(--muted);display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;transition:all .25s ease;position:relative;z-index:1}
.step.active .bullet{border-color:var(--link);color:#fff;background:var(--link);box-shadow:0 0 0 4px rgba(88,166,255,.22)}
.step.done .bullet{border-color:var(--accent);background:var(--accent);color:#fff}
.step .lbl{font-size:.74rem;color:var(--muted);font-weight:600;letter-spacing:.02em}
.step.active .lbl{color:var(--fg)}
.step.done .lbl{color:var(--ok)}

.wrap{max-width:780px;margin:1.75rem auto 0;padding:0 1.25rem}

.panel{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:1.5rem;animation:rise .4s cubic-bezier(.2,.7,.3,1)}
@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
body.wizard .stage{display:none}
body.wizard .stage.on{display:block}

.stage-head{display:flex;align-items:baseline;gap:.65rem;margin-bottom:1.15rem;border-bottom:1px solid var(--border);padding-bottom:.8rem}
.stage-head h2{font-size:1.02rem;font-weight:600}
.stage-head small{color:var(--muted);font-size:.82rem}

.chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.1rem}
.chip{font-family:var(--mono);font-size:.76rem;color:var(--muted);border:1px solid var(--border);border-radius:999px;padding:.3em .8em;background:var(--bg)}
.chip b{color:var(--fg);font-weight:600}

.checks{list-style:none}
.checks li{display:flex;gap:.6rem;padding:.42rem 0;font-size:.92rem;border-bottom:1px dashed rgba(48,54,61,.5)}
.checks li:last-child{border:none}
.checks .st{width:20px;height:20px;flex:none;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;margin-top:.1em;background:var(--bg-inset);color:var(--muted);border:1px solid var(--border);position:relative;overflow:hidden}
.checks li.ok .st{background:rgba(63,185,80,.16);border-color:var(--ok);color:var(--ok)}
.checks li.bad .st{background:rgba(248,81,73,.16);border-color:var(--err);color:var(--err)}
.checks li.probe .st::after{content:"";width:10px;height:10px;border-radius:50%;border:2px solid var(--border);border-top-color:var(--link);animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.checks .hint{color:var(--muted);font-size:.78rem;margin-inline-start:auto;text-align:end;font-family:var(--mono)}

label{display:block;font-size:.83rem;font-weight:600;margin:.95rem 0 .35rem}
.hint{font-weight:400;color:var(--muted)}
input[type=text],input[type=password],input[type=number],select{width:100%;background:var(--bg);color:var(--fg);border:1px solid var(--border);border-radius:6px;padding:.55rem .75rem;font-size:.95rem;transition:border-color .15s,box-shadow .15s}
input:focus,select:focus{outline:none;border-color:var(--link);box-shadow:0 0 0 3px rgba(88,166,255,.18)}
input.inv{border-color:var(--err)!important;box-shadow:0 0 0 3px rgba(248,81,73,.16)!important}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}
@media(max-width:600px){.grid{grid-template-columns:1fr}}

.meter{height:6px;border-radius:999px;background:var(--bg-inset);border:1px solid var(--border);margin-top:.55rem;overflow:hidden}
.meter i{display:block;height:100%;width:0;border-radius:999px;background:var(--err);transition:width .3s ease,background .3s ease}
.meter-lbl{font-size:.74rem;color:var(--muted);margin-top:.3rem;min-height:1em}

.testrow{display:flex;gap:.6rem;align-items:center;margin-top:1.1rem;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5em;font:inherit;font-weight:600;font-size:.9rem;color:var(--fg);background:#21262d;border:1px solid var(--border);border-radius:6px;padding:.55em 1.05em;cursor:pointer;text-decoration:none;transition:filter .12s,border-color .12s,transform .12s}
.btn:hover:not(:disabled){border-color:var(--muted);filter:brightness(1.12)}
.btn:active:not(:disabled){transform:translateY(1px)}
.btn.primary{background:var(--accent);border-color:rgba(240,246,252,.1);color:#fff}
.btn.primary:hover:not(:disabled){background:var(--accent-h)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.bigbtn{width:100%;margin-top:1.4rem;padding:.68rem;font-size:1rem}

.result{display:none;margin-top:.9rem;border-radius:6px;padding:.7rem .9rem;font-size:.86rem;align-items:center;gap:.55em}
.result.show{display:flex;animation:rise .3s ease}
.result.ok{background:rgba(46,160,67,.12);border:1px solid var(--ok);color:var(--ok)}
.result.bad{background:rgba(248,81,73,.12);border:1px solid var(--err);color:var(--err)}

.alert{border-radius:6px;padding:.85rem 1rem;font-size:.9rem;margin-bottom:1.2rem;background:rgba(248,81,73,.12);border:1px solid var(--err);color:var(--err)}
.alert ul{margin:.35rem 0 0 1.1rem}

.nav{display:flex;justify-content:space-between;margin-top:1.4rem;gap:.6rem}
details{margin-top:1rem}
summary{cursor:pointer;color:var(--link);font-size:.88rem}
code{font-family:var(--mono);background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:.08em .38em;font-size:.85em}

.term{background:var(--bg-inset);border:1px solid var(--border);border-radius:10px;overflow:hidden;font-family:var(--mono);font-size:.84rem}
.term-bar{display:flex;align-items:center;gap:.45rem;padding:.55rem .85rem;border-bottom:1px solid var(--border);background:var(--panel)}
.dot3{width:11px;height:11px;border-radius:50%}
.dot3:nth-child(1){background:#ff5f56}.dot3:nth-child(2){background:#ffbd2e}.dot3:nth-child(3){background:#27c93f}
.term-title{margin-inline-start:auto;color:var(--muted);font-size:.73rem}
.term-body{padding:.95rem 1.05rem;min-height:11.5em}
.tl{white-space:pre-wrap;word-break:break-word;line-height:1.8;opacity:0;transform:translateY(4px)}
.tl.in{opacity:1;transform:none;transition:opacity .25s ease,transform .25s ease}
.tl .pf{color:var(--ok);font-weight:700}
.tl.err .pf{color:var(--err)}
.caret{display:inline-block;width:.55em;height:1.1em;vertical-align:text-bottom;background:var(--link);border-radius:1px;animation:blink 1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.progressbar{height:4px;background:var(--bg-inset);border-top:1px solid var(--border)}
.progressbar i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--link));transition:width .35s ease}

.done-hero{text-align:center;padding:1.2rem 0 .4rem}
.ring{width:84px;height:84px;margin:0 auto 1.1rem}
.ring circle{fill:none;stroke-width:2.4}
.ring .track{stroke:var(--border)}
.ring .arc{stroke:var(--ok);stroke-dasharray:213.6;stroke-dashoffset:213.6;stroke-linecap:round;transform:rotate(-90deg);transform-origin:center;animation:draw 1s .15s cubic-bezier(.3,.7,.3,1) forwards}
@keyframes draw{to{stroke-dashoffset:0}}
.ring path{stroke:var(--ok);stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:60;stroke-dashoffset:60;animation:draw .5s .85s cubic-bezier(.3,.7,.3,1) forwards}
.done-hero h2{font-size:1.3rem;margin-bottom:.35rem}
.done-hero p{color:var(--muted);font-size:.92rem}
ol.next{color:var(--muted);font-size:.9rem;margin:.9rem 0 0 1.25rem;text-align:start}
ol.next li{margin:.3rem 0}
.log-mini{list-style:none;text-align:start;font-size:.84rem;color:var(--muted);font-family:var(--mono);margin-top:1rem;border-top:1px dashed var(--border);padding-top:.8rem}
.log-mini li::before{content:"✓ ";color:var(--ok);font-weight:700}

.locked{text-align:center;padding:2.6rem 1rem}
.lock-badge{width:64px;height:64px;margin:0 auto 1rem;border-radius:50%;background:rgba(210,153,34,.14);border:1px solid rgba(210,153,34,.45);display:flex;align-items:center;justify-content:center;font-size:1.6rem}

footer{text-align:center;color:var(--muted);font-size:.76rem;margin-top:2.4rem}
@media(prefers-reduced-motion:reduce){*{animation-duration:.01ms!important;transition:none!important}}
</style>
</head>
<body>

<header class="topbar">
    <span class="mark">G</span>
    <h1>GitPHP Installer <span class="tag">v1 · web setup</span></h1>
</header>

<?php if ($view === 'already-installed'): ?>

<div class="wrap">
    <div class="panel locked">
        <div class="lock-badge">🔒</div>
        <h2 style="margin-bottom:.4rem">Already installed</h2>
        <p style="color:var(--muted)">The installer has been locked for your security after a successful installation.<br>If you really need to reinstall, delete <code>storage/installed.lock</code> on the server first.</p>
        <div style="display:flex;gap:.6rem;justify-content:center;margin-top:1.4rem;flex-wrap:wrap">
            <a class="btn primary" href="/">Go to <?= htmlspecialchars($app_name) ?></a>
            <a class="btn" href="/login">Log in</a>
        </div>
    </div>
</div>

<?php else: ?>

<nav class="stepper" id="stepper" aria-label="Setup progress">
    <?php if (! $done): ?>
    <div class="step" data-step="0"><span class="bullet">1</span><span class="lbl">Environment</span></div>
    <div class="step" data-step="1"><span class="bullet">2</span><span class="lbl">Database</span></div>
    <div class="step" data-step="2"><span class="bullet">3</span><span class="lbl">Site</span></div>
    <div class="step" data-step="3"><span class="bullet">4</span><span class="lbl">Admin</span></div>
    <div class="step" data-step="4"><span class="bullet">5</span><span class="lbl">Install</span></div>
    <?php endif; ?>
</nav>

<div class="wrap">

<?php if ($done): ?>

    <div class="panel" style="text-align:center">
        <div class="done-hero">
            <svg class="ring" viewBox="0 0 80 80" aria-hidden="true">
                <circle class="track" cx="40" cy="40" r="34"></circle>
                <circle class="arc" cx="40" cy="40" r="34"></circle>
                <path d="M26 41 l9 9 l19 -19"></path>
            </svg>
            <h2>Installation complete 🎉</h2>
            <p><?= htmlspecialchars($f['app_name']) ?> is ready. The installer is now locked.</p>
        </div>
        <ul class="log-mini">
            <?php foreach ($log as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
        </ul>
        <ol class="next">
            <li>Point your web server document root at <code>public_html/</code>.</li>
            <li>Make sure <code>git</code> is available to the PHP user.</li>
            <li>(Optional) Set up SSH keys — see <code>docs/ssh-setup.md</code>.</li>
        </ol>
        <div style="display:flex;gap:.6rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap">
            <a class="btn primary" href="<?= htmlspecialchars(rtrim($f['app_url'], '/') . '/login') ?>">Log in as owner &rarr;</a>
            <a class="btn" href="<?= htmlspecialchars(rtrim($f['app_url'], '/')) ?>">Open site</a>
        </div>
    </div>

<?php else: ?>

    <?php if ($errors): ?>
    <noscript><div class="alert"><strong>Please fix the following:</strong><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div></noscript>
    <div class="alert" id="server-alert" hidden><strong>Please fix the following:</strong><ul id="server-alert-list"></ul></div>
    <?php else: ?>
    <div class="alert" id="server-alert" hidden><strong>Please fix the following:</strong><ul id="server-alert-list"></ul></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" id="wiz-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

        <div class="stage on" data-stage="0">
            <div class="panel">
                <div class="stage-head"><h2>Environment &amp; requirements</h2><small>checked live</small></div>
                <div class="chips">
                    <?php foreach ($environment as [$k, $v]): ?>
                    <span class="chip"><?= htmlspecialchars($k) ?> · <b><?= htmlspecialchars($v) ?></b></span>
                    <?php endforeach; ?>
                </div>
                <ul class="checks" id="req-list">
                    <?php foreach ($requirements as $r): ?>
                    <li data-label="<?= htmlspecialchars($r['label']) ?>" data-ok="<?= $r['ok'] ? '1' : '0' ?>">
                        <span class="st"></span>
                        <span><?= htmlspecialchars($r['label']) ?></span>
                        <span class="hint"><?= htmlspecialchars($r['hint']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="nav"><span></span><button type="button" class="btn primary" data-next>&nbsp;&nbsp;&nbsp;Continue&nbsp;&nbsp;&nbsp;</button></div>
        </div>

        <div class="stage" data-stage="1">
            <div class="panel">
                <div class="stage-head"><h2>Database connection</h2><small>MySQL / MariaDB</small></div>
                <div class="grid">
                    <div>
                        <label for="db_host">Host</label>
                        <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($f['db_host']) ?>" required>
                    </div>
                    <div>
                        <label for="db_port">Port</label>
                        <input type="number" id="db_port" name="db_port" value="<?= htmlspecialchars($f['db_port']) ?>">
                    </div>
                    <div>
                        <label for="db_name">Database name</label>
                        <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($f['db_name']) ?>" required>
                    </div>
                    <div>
                        <label for="db_user">User <span class="hint">(with create rights)</span></label>
                        <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($f['db_user']) ?>" required>
                    </div>
                </div>
                <label for="db_pass">Password</label>
                <input type="password" id="db_pass" name="db_pass" value="">
                <p class="hint" style="margin-top:.5rem;font-size:.8rem">On shared hosting create the database + user in your control panel first (cPanel, CyberPanel, Plesk…). The installer creates the tables.</p>

                <div class="testrow">
                    <button type="button" class="btn" id="test-db-btn">⇄ Test connection</button>
                    <span class="hint" style="font-size:.8rem" id="test-db-note">optional but recommended</span>
                </div>
                <div class="result" id="test-db-result"></div>
            </div>
            <div class="nav">
                <button type="button" class="btn" data-back>← Back</button>
                <button type="button" class="btn primary" data-next>&nbsp;&nbsp;&nbsp;Continue&nbsp;&nbsp;&nbsp;</button>
            </div>
        </div>

        <div class="stage" data-stage="2">
            <div class="panel">
                <div class="stage-head"><h2>Site identity</h2><small>how your platform appears</small></div>
                <div class="grid">
                    <div>
                        <label for="app_name">Application name</label>
                        <input type="text" id="app_name" name="app_name" value="<?= htmlspecialchars($f['app_name']) ?>" required>
                    </div>
                    <div>
                        <label for="app_url">Application URL</label>
                        <input type="text" id="app_url" name="app_url" value="<?= htmlspecialchars($f['app_url']) ?>" required>
                    </div>
                    <div>
                        <label for="owner_name">Owner username <span class="hint">(admin identity)</span></label>
                        <input type="text" id="owner_name" name="owner_name" value="<?= htmlspecialchars($f['owner_name']) ?>" required>
                    </div>
                    <div>
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone">
                            <?php foreach ($timezones as $tz): ?>
                            <option value="<?= $tz ?>"<?= $tz === $f['timezone'] ? ' selected' : '' ?>><?= $tz ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <details>
                    <summary>Advanced options</summary>
                    <label for="repos_path">Repositories path <span class="hint">(bare repos live here)</span></label>
                    <input type="text" id="repos_path" name="repos_path" value="<?= htmlspecialchars($f['repos_path']) ?>">
                    <label for="authorized_keys_path">authorized_keys path <span class="hint">(optional, for SSH push)</span></label>
                    <input type="text" id="authorized_keys_path" name="authorized_keys_path" value="" placeholder="/home/git/.ssh/authorized_keys">
                </details>
            </div>
            <div class="nav">
                <button type="button" class="btn" data-back>← Back</button>
                <button type="button" class="btn primary" data-next>&nbsp;&nbsp;&nbsp;Continue&nbsp;&nbsp;&nbsp;</button>
            </div>
        </div>

        <div class="stage" data-stage="3">
            <div class="panel">
                <div class="stage-head"><h2>Owner password</h2><small>for “<?= htmlspecialchars($f['owner_name']) ?>”</small></div>
                <div class="grid">
                    <div>
                        <label for="owner_pass">Password</label>
                        <input type="password" id="owner_pass" name="owner_pass" minlength="8" required>
                        <div class="meter"><i id="pw-bar"></i></div>
                        <div class="meter-lbl" id="pw-lbl"></div>
                    </div>
                    <div>
                        <label for="owner_pass2">Confirm password</label>
                        <input type="password" id="owner_pass2" name="owner_pass2" minlength="8" required>
                    </div>
                </div>
            </div>
            <div class="nav">
                <button type="button" class="btn" data-back>← Back</button>
                <button type="submit" class="btn primary bigbtn" id="install-btn" <?= $reqOk ? '' : 'disabled' ?>>🚀 Install GitPHP</button>
            </div>
        </div>

        <div class="stage" data-stage="4">
            <div class="panel">
                <div class="stage-head"><h2>Installing…</h2><small id="install-sub">running setup tasks</small></div>
                <div class="term">
                    <div class="term-bar"><span class="dot3"></span><span class="dot3"></span><span class="dot3"></span><span class="term-title">gitphp-setup — bash</span></div>
                    <div class="term-body" id="term"></div>
                    <div class="progressbar"><i id="pbar"></i></div>
                </div>
            </div>
        </div>

    </form>

<?php endif; ?>
</div>

<footer>GitPHP · self-hosted Git hosting platform</footer>

<script>
(function () {
    "use strict";

    var DONE      = <?= $done ? 'true' : 'false' ?>;
    var CSRF      = <?= json_encode($csrf) ?>;
    var SERVER_ERRORS = <?= json_encode(array_values($errors)) ?>;
    var INSTALL_LOG   = <?= json_encode(array_values($log)) ?>;
    var REDUCED   = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ── Requirement checklist animation ─────────────────────────── */
    var reqItems = [].slice.call(document.querySelectorAll("#req-list li"));
    function paintReq(i) {
        if (i >= reqItems.length) {
            var bad = reqItems.filter(function (li) { return li.getAttribute("data-ok") === "0"; }).length;
            var btn = document.querySelector('[data-stage="0"] [data-next]');
            if (btn) {
                btn.disabled = bad > 0;
                btn.textContent = bad > 0
                    ? "Fix " + bad + " requirement" + (bad > 1 ? "s" : "") + " to continue"
                    : "Continue";
            }
            return;
        }
        var li = reqItems[i];
        var isOk = li.getAttribute("data-ok") === "1";
        li.classList.add("probe");
        setTimeout(function () {
            li.classList.remove("probe");
            li.classList.add(isOk ? "ok" : "bad");
            li.querySelector(".st").textContent = isOk ? "✓" : "✗";
            paintReq(i + 1);
        }, REDUCED ? 10 : 130 + Math.random() * 160);
    }

    fetch("?ajax=status", { headers: { "X-Requested-With": "fetch" } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var map = {};
            (data.requirements || []).forEach(function (r) { map[r.label] = r.ok; });
            reqItems.forEach(function (li) {
                var v = map[li.getAttribute("data-label")];
                li.setAttribute("data-ok", v === undefined ? li.getAttribute("data-ok") : (v ? "1" : "0"));
            });
            paintReq(0);
        })
        .catch(function () { paintReq(0); });

    /* ── Step machine ───────────────────────────────────────────── */
    var stages = [].slice.call(document.querySelectorAll(".stage"));
    var steps  = [].slice.call(document.querySelectorAll(".step"));
    var cur    = 0;

    function show(n) {
        cur = n;
        stages.forEach(function (s, i) { s.classList.toggle("on", i === n); });
        steps.forEach(function (s, i) {
            s.classList.toggle("active", i === n);
            s.classList.toggle("done", i < n);
            var b = s.querySelector(".bullet");
            if (b && !s.classList.contains("done")) b.textContent = String(i + 1);
        });
        var b = steps[n] && steps[n].querySelector(".bullet");
        if (b && n > 0) b.textContent = "✓";
        window.scrollTo({ top: 0, behavior: REDUCED ? "auto" : "smooth" });
    }

    function field(id) { return document.getElementById(id); }
    function mark(el, bad) { el.classList.toggle("inv", !!bad); return !bad; }

    var VALIDATORS = [
        null,
        function () {
            var ok = true;
            ok = mark(field("db_host"), field("db_host").value.trim() === "") && ok;
            ok = mark(field("db_user"), field("db_user").value.trim() === "") && ok;
            return ok;
        },
        function () {
            var ok = true;
            ["app_name", "app_url", "owner_name"].forEach(function (id) {
                ok = mark(field(id), field(id).value.trim() === "") && ok;
            });
            var url = field("app_url").value.trim();
            if (url !== "" && !/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(url)) {
                ok = mark(field("app_url"), true) && ok;
            }
            return ok;
        },
        function () {
            var ok = true;
            var p1 = field("owner_pass"), p2 = field("owner_pass2");
            ok = mark(p1, p1.value.length < 8) && ok;
            ok = mark(p2, p2.value !== p1.value) && ok;
            return ok;
        }
    ];

    document.querySelectorAll("[data-next]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (VALIDATORS[cur] && !VALIDATORS[cur]()) return;
            show(Math.min(cur + 1, stages.length - 1));
            if (cur === 3) field("owner_pass").focus();
        });
    });
    document.querySelectorAll("[data-back]").forEach(function (btn) {
        btn.addEventListener("click", function () { show(Math.max(cur - 1, 0)); });
    });

    /* ── Live DB test ───────────────────────────────────────────── */
    var testBtn = document.getElementById("test-db-btn");
    if (testBtn) testBtn.addEventListener("click", function () {
        var box    = document.getElementById("test-db-result");
        var note   = document.getElementById("test-db-note");
        var payload = {
            csrf_token: CSRF,
            db_host: field("db_host").value.trim() || "127.0.0.1",
            db_port: field("db_port").value.trim() || "3306",
            db_name: field("db_name").value.trim(),
            db_user: field("db_user").value.trim(),
            db_pass: field("db_pass").value
        };
        if (!payload.db_user || !payload.db_name) {
            box.className = "result show bad";
            box.textContent = "⚠ Fill in database name and user first.";
            return;
        }
        var t0 = performance.now();
        testBtn.disabled = true;
        testBtn.textContent = "◌ Testing…";
        note.textContent = "";

        fetch("?ajax=testdb", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var ms = Math.max(data.latency | 0, Math.round(performance.now() - t0));
            box.className = "result show " + (data.ok ? "ok" : "bad");
            box.textContent = data.ok
                ? "✓ Connected in ~" + ms + " ms"
                : "✗ " + (data.error || "Connection failed.");
        })
        .catch(function () {
            box.className = "result show bad";
            box.textContent = "✗ Request failed — is the server reachable?";
        })
        .finally(function () {
            testBtn.disabled = false;
            testBtn.textContent = "⇄ Test connection";
            note.textContent = "optional but recommended";
        });
    });

    /* ── Password strength ──────────────────────────────────────── */
    var pw = field("owner_pass"), bar = document.getElementById("pw-bar"), lbl = document.getElementById("pw-lbl");
    if (pw) pw.addEventListener("input", function () {
        var v = pw.value, score = 0;
        if (v.length >= 8) score++;
        if (v.length >= 12) score++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        var pct  = [8, 30, 52, 74, 90, 100][score];
        var col  = ["#f85149", "#f85149", "#d29922", "#d29922", "#3fb950", "#3fb950"][score];
        var text = ["too short", "weak", "fair", "good", "strong", "excellent"][score];
        bar.style.width = pct + "%";
        bar.style.background = col;
        lbl.textContent = v ? "Strength: " + text : "";
        lbl.style.color = col;
    });

    var pw2 = field("owner_pass2");
    if (pw2) pw2.addEventListener("input", function () {
        mark(pw2, pw2.value !== pw.value && pw2.value !== "");
    });

    /* ── Submit → animated terminal install ─────────────────────── */
    var form = document.getElementById("wiz-form");
    var installing = false;

    function runTerminal(lines, isError, finish) {
        var term = document.getElementById("term");
        var pbar = document.getElementById("pbar");
        if (!term) { finish(); return; }
        var caretLine = document.createElement("div");
        caretLine.className = "tl";
        caretLine.innerHTML = '<span class="caret"></span>';
        term.appendChild(caretLine);
        var i = 0;
        function nextLine() {
            if (i >= lines.length) {
                pbar.style.width = "100%";
                setTimeout(finish, REDUCED ? 50 : 450);
                return;
            }
            caretLine.remove();
            var isLastErr = isError && i === lines.length - 1;
            var d = document.createElement("div");
            d.className = "tl in" + (isLastErr ? " err" : "");
            var pf = document.createElement("span");
            pf.className = "pf";
            pf.style.color = isLastErr ? "#f85149" : "";
            pf.textContent = isLastErr ? "✗" : "✓";
            d.appendChild(pf);
            d.appendChild(document.createTextNode(" " + lines[i]));
            term.appendChild(d);
            i++;
            pbar.style.width = Math.round((i / Math.max(lines.length, 1)) * 96) + "%";
            caretLine.innerHTML = '<span class="caret"></span>';
            term.appendChild(caretLine);
            term.scrollTop = term.scrollHeight;
            setTimeout(nextLine, REDUCED ? 15 : 260 + Math.random() * 320);
        }
        nextLine();
    }

    if (form) form.addEventListener("submit", function (e) {
        if (installing) { e.preventDefault(); return; }
        for (var s = 0; s <= 3; s++) {
            if (VALIDATORS[s] && !VALIDATORS[s]()) {
                e.preventDefault();
                show(s);
                return;
            }
        }
        installing = true;
        show(4);
        runTerminal(["connecting to database…"], false, function () {});
    });

    if (DONE) {
        document.body.classList.add("done-mode");
        var stage4 = document.querySelector('[data-stage="4"]');
        if (stage4) stage4.classList.add("on");
        runTerminal(INSTALL_LOG.concat(["installation finished successfully 🎉"]), false, function () {
            window.location.href = <?= json_encode(rtrim($f['app_url'], '/') . '/login') ?>;
        });
    }

    if (SERVER_ERRORS.length && !INSTALL_LOG.length && !DONE) {
        var alertBox  = document.getElementById("server-alert");
        var alertList = document.getElementById("server-alert-list");
        if (alertBox && alertList) {
            SERVER_ERRORS.forEach(function (m) {
                var li = document.createElement("li");
                li.textContent = m;
                alertList.appendChild(li);
            });
            alertBox.hidden = false;
            var joined = SERVER_ERRORS.join(" ");
            show(joined.indexOf("Database") !== -1 ? 1 : (joined.indexOf("password") !== -1 || joined.indexOf("Owner") !== -1 ? 3 : 0));
        }
    }

    if (SERVER_ERRORS.length && INSTALL_LOG.length && !DONE) {
        show(4);
        runTerminal(INSTALL_LOG.concat(SERVER_ERRORS), true, function () {});
    }

    document.body.classList.add("wizard");
})();
</script>

<?php endif; ?>
</body>
</html><?php
}
