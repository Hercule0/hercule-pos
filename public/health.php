<?php
require_once __DIR__ . '/../includes/ErrorHandler.php';
ErrorHandler::register();

require_once __DIR__ . '/../includes/Database.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$startedAt = microtime(true);
$databaseOk = false;
$databaseStatus = 'unavailable';
$databaseLatencyMs = null;

try {
    $dbStartedAt = microtime(true);
    Database::pdo()->query('SELECT 1')->fetchColumn();
    $databaseLatencyMs = round((microtime(true) - $dbStartedAt) * 1000, 1);
    $databaseOk = true;
    $databaseStatus = 'reachable';
} catch (Throwable $e) {
    ErrorHandler::report($e, 'health_database_unavailable');
}

$httpStatus = $databaseOk ? 200 : 503;
$responseLatencyMs = round((microtime(true) - $startedAt) * 1000, 1);
$payload = [
    'ok' => $databaseOk,
    'service' => 'hercule-license-server',
    'database' => $databaseStatus,
    'time' => gmdate('Y-m-d\TH:i:s\Z'),
    'request_id' => ErrorHandler::requestId(),
    'response_ms' => $responseLatencyMs,
];
if ($databaseLatencyMs !== null) {
    $payload['database_ms'] = $databaseLatencyMs;
}

$format = strtolower(trim((string)($_GET['format'] ?? '')));
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$browserHtml = $format !== 'json' && ($format === 'html' || str_contains($accept, 'text/html'));

http_response_code($httpStatus);

if (!$browserHtml) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$statusLabel = $databaseOk ? 'All Systems Operational' : 'Service Degraded';
$statusDetail = $databaseOk
    ? 'Hercule License Server is online and the production database is responding normally.'
    : 'The application is reachable, but the production database health check is currently failing.';
$statusClass = $databaseOk ? 'healthy' : 'degraded';
$jsonPretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$databaseDisplay = $databaseOk ? 'Operational' : 'Unavailable';
$databaseLatencyDisplay = $databaseLatencyMs !== null ? number_format($databaseLatencyMs, 1) . ' ms' : 'No response';
$responseLatencyDisplay = number_format($responseLatencyMs, 1) . ' ms';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#07111f">
    <meta name="robots" content="noindex,nofollow">
    <title>Hercule System Status</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #050a12;
            --surface: rgba(13, 24, 42, .84);
            --surface-strong: #0d182a;
            --surface-soft: rgba(21, 38, 64, .62);
            --line: rgba(151, 181, 228, .13);
            --line-strong: rgba(94, 190, 255, .23);
            --text: #f5f8ff;
            --muted: #91a5c3;
            --muted-2: #657a9a;
            --cyan: #45c8ff;
            --blue: #4d8dff;
            --green: #25dfa7;
            --green-soft: rgba(37, 223, 167, .12);
            --red: #ff667f;
            --red-soft: rgba(255, 102, 127, .12);
            --amber: #ffc857;
            --shadow: 0 30px 80px rgba(0, 0, 0, .38);
        }

        * { box-sizing: border-box; }
        html { min-height: 100%; background: var(--bg); }
        body {
            min-height: 100vh;
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 10% 0%, rgba(57, 158, 255, .18), transparent 28rem),
                radial-gradient(circle at 92% 14%, rgba(37, 223, 167, .08), transparent 24rem),
                linear-gradient(180deg, #07101d 0%, #050a12 55%, #04080f 100%);
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .26;
            background-image:
                linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, #000 0%, transparent 75%);
        }

        a { color: inherit; }
        button, a { -webkit-tap-highlight-color: transparent; }

        .shell {
            width: min(1180px, calc(100% - 32px));
            margin: 0 auto;
            padding: 28px 0 52px;
            position: relative;
            z-index: 1;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
            text-decoration: none;
        }

        .logo {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #03111e;
            font-weight: 900;
            font-size: 22px;
            background: linear-gradient(145deg, #70dcff 0%, #23a9f5 55%, #4889ff 100%);
            box-shadow: 0 14px 35px rgba(44, 169, 255, .28), inset 0 1px rgba(255,255,255,.34);
        }

        .brand-copy strong { display: block; font-size: 16px; letter-spacing: -.01em; }
        .brand-copy span { display: block; margin-top: 2px; color: #56c8ff; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }

        .environment {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(37,223,167,.18);
            border-radius: 999px;
            padding: 8px 12px;
            background: rgba(10, 26, 38, .66);
            color: #95f4d4;
            font-size: 12px;
            font-weight: 750;
            white-space: nowrap;
        }

        .environment::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 16px rgba(37,223,167,.9);
        }

        .hero {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line-strong);
            border-radius: 30px;
            padding: clamp(26px, 5vw, 54px);
            background:
                linear-gradient(120deg, rgba(12, 26, 48, .94), rgba(8, 18, 32, .86)),
                radial-gradient(circle at 82% 18%, rgba(69,200,255,.14), transparent 36%);
            box-shadow: var(--shadow);
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 360px;
            height: 360px;
            right: -130px;
            top: -180px;
            border-radius: 50%;
            border: 1px solid rgba(93, 195, 255, .13);
            box-shadow: 0 0 0 46px rgba(75, 160, 255, .025), 0 0 0 92px rgba(75,160,255,.018);
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 36px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 14px;
            color: #64cfff;
            font-size: 12px;
            font-weight: 850;
            letter-spacing: .15em;
            text-transform: uppercase;
        }

        .eyebrow::before { content: ""; width: 18px; height: 2px; background: linear-gradient(90deg, var(--cyan), transparent); }
        h1 { margin: 0; font-size: clamp(36px, 6vw, 66px); line-height: .98; letter-spacing: -.045em; max-width: 720px; }
        .hero-copy { margin: 18px 0 0; color: var(--muted); font-size: clamp(15px, 2vw, 18px); line-height: 1.7; max-width: 680px; }

        .status-orb {
            width: 170px;
            aspect-ratio: 1;
            border-radius: 50%;
            display: grid;
            place-items: center;
            position: relative;
            background: radial-gradient(circle, rgba(37,223,167,.13), rgba(37,223,167,.04) 52%, transparent 70%);
        }

        .status-orb::before,
        .status-orb::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(37,223,167,.24);
        }
        .status-orb::before { inset: 20px; }
        .status-orb::after { inset: 2px; border-color: rgba(37,223,167,.10); }
        .degraded .status-orb { background: radial-gradient(circle, rgba(255,102,127,.14), rgba(255,102,127,.04) 52%, transparent 70%); }
        .degraded .status-orb::before { border-color: rgba(255,102,127,.25); }

        .orb-core {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 30px;
            color: #04140f;
            background: linear-gradient(145deg, #67f7c9, #1ec58f);
            box-shadow: 0 0 48px rgba(37,223,167,.34);
            position: relative;
            z-index: 2;
        }
        .degraded .orb-core { color: #20070d; background: linear-gradient(145deg, #ff9aae, #ff5f78); box-shadow: 0 0 48px rgba(255,102,127,.28); }

        .status-line {
            margin-top: 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding-top: 22px;
            border-top: 1px solid var(--line);
            position: relative;
            z-index: 1;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            color: #b9f7df;
            background: var(--green-soft);
            border: 1px solid rgba(37,223,167,.20);
        }
        .degraded .status-pill { color: #ffd6de; background: var(--red-soft); border-color: rgba(255,102,127,.22); }
        .pulse { width: 9px; height: 9px; border-radius: 50%; background: var(--green); box-shadow: 0 0 0 5px rgba(37,223,167,.10), 0 0 18px rgba(37,223,167,.7); }
        .degraded .pulse { background: var(--red); box-shadow: 0 0 0 5px rgba(255,102,127,.10), 0 0 18px rgba(255,102,127,.65); }
        .last-check { color: var(--muted-2); font-size: 12px; }

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap: 16px;
            margin-top: 18px;
        }

        .metric {
            min-width: 0;
            padding: 21px;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(16,30,51,.82), rgba(10,20,35,.82));
            box-shadow: 0 14px 35px rgba(0,0,0,.15);
        }

        .metric-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .metric-label { color: #7e93b2; font-size: 11px; font-weight: 850; letter-spacing: .09em; text-transform: uppercase; }
        .metric-icon { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; background: rgba(76,144,255,.08); color: #71cfff; font-size: 15px; }
        .metric-value { margin: 18px 0 4px; font-size: clamp(24px, 4vw, 34px); font-weight: 850; letter-spacing: -.035em; overflow-wrap: anywhere; }
        .metric-sub { color: var(--muted-2); font-size: 12px; line-height: 1.45; }
        .metric-value.good { color: #62edbd; }
        .metric-value.bad { color: #ff8094; }

        .details {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, .8fr);
            gap: 16px;
            margin-top: 16px;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 22px;
            background: rgba(10,20,35,.78);
            box-shadow: 0 16px 44px rgba(0,0,0,.17);
            overflow: hidden;
        }

        .panel-head { padding: 20px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid var(--line); }
        .panel-head strong { font-size: 15px; }
        .panel-head span { color: var(--muted-2); font-size: 11px; }

        .json {
            margin: 0;
            padding: 22px;
            min-height: 252px;
            color: #cfe1fa;
            background: rgba(3,9,17,.56);
            font: 12.5px/1.75 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .action-row { display: flex; flex-wrap: wrap; gap: 10px; padding: 0 22px 22px; background: rgba(3,9,17,.56); }
        .btn {
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 12px;
            padding: 0 15px;
            border: 1px solid var(--line);
            background: rgba(20,35,58,.85);
            color: #d9e8fb;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            transition: transform .16s ease, border-color .16s ease, background .16s ease;
        }
        .btn:hover { transform: translateY(-1px); border-color: rgba(91,190,255,.28); background: rgba(26,46,75,.92); }
        .btn.primary { color: #04111c; border-color: transparent; background: linear-gradient(135deg, #62d5ff, #3c8eff); box-shadow: 0 10px 26px rgba(60,142,255,.2); }

        .timeline { padding: 6px 22px 18px; }
        .timeline-row { display: grid; grid-template-columns: 16px minmax(0,1fr); gap: 13px; padding: 15px 0; }
        .timeline-row + .timeline-row { border-top: 1px solid rgba(151,181,228,.08); }
        .timeline-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; background: #4b91ff; box-shadow: 0 0 0 4px rgba(75,145,255,.08); }
        .timeline-row:first-child .timeline-dot { background: var(--green); box-shadow: 0 0 0 4px rgba(37,223,167,.08); }
        .degraded .timeline-row:first-child .timeline-dot { background: var(--red); box-shadow: 0 0 0 4px rgba(255,102,127,.08); }
        .timeline-row strong { display: block; font-size: 13px; }
        .timeline-row p { margin: 5px 0 0; color: var(--muted-2); font-size: 12px; line-height: 1.55; }

        .footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 4px 0; color: #526985; font-size: 11px; }
        .footer code { font-family: ui-monospace, monospace; color: #8097b6; }

        @media (max-width: 900px) {
            .hero-grid { grid-template-columns: 1fr; }
            .status-orb { width: 130px; justify-self: start; }
            .metrics { grid-template-columns: repeat(2, minmax(0,1fr)); }
            .details { grid-template-columns: 1fr; }
        }

        @media (max-width: 560px) {
            .shell { width: min(100% - 20px, 1180px); padding-top: 18px; }
            .topbar { align-items: flex-start; }
            .environment { display: none; }
            .hero { border-radius: 22px; padding: 24px 20px; }
            h1 { font-size: 38px; }
            .hero-copy { font-size: 14px; }
            .status-orb { width: 108px; }
            .orb-core { width: 62px; height: 62px; font-size: 24px; }
            .metrics { grid-template-columns: 1fr; gap: 10px; }
            .metric { padding: 18px; }
            .panel { border-radius: 18px; }
            .panel-head, .json { padding-left: 18px; padding-right: 18px; }
            .action-row { padding-left: 18px; padding-right: 18px; }
            .footer { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body class="<?= htmlspecialchars($statusClass) ?>">
<main class="shell">
    <header class="topbar">
        <a class="brand" href="/public/health.php" aria-label="Hercule System Status">
            <span class="logo">H</span>
            <span class="brand-copy"><strong>Hercule</strong><span>System Status</span></span>
        </a>
        <span class="environment">Production · Azure Web App</span>
    </header>

    <section class="hero">
        <div class="hero-grid">
            <div>
                <p class="eyebrow">Live infrastructure health</p>
                <h1><?= htmlspecialchars($statusLabel) ?></h1>
                <p class="hero-copy"><?= htmlspecialchars($statusDetail) ?></p>
            </div>
            <div class="status-orb" aria-hidden="true"><div class="orb-core"><?= $databaseOk ? '✓' : '!' ?></div></div>
        </div>
        <div class="status-line">
            <span class="status-pill"><span class="pulse"></span><?= $databaseOk ? 'Operational' : 'Degraded' ?></span>
            <span class="last-check">Live check · <?= htmlspecialchars(gmdate('M j, Y H:i:s')) ?> UTC</span>
        </div>
    </section>

    <section class="metrics" aria-label="Service metrics">
        <article class="metric">
            <div class="metric-head"><span class="metric-label">Application</span><span class="metric-icon">H</span></div>
            <div class="metric-value good">Online</div>
            <div class="metric-sub">Public health endpoint is responding.</div>
        </article>
        <article class="metric">
            <div class="metric-head"><span class="metric-label">Database</span><span class="metric-icon">DB</span></div>
            <div class="metric-value <?= $databaseOk ? 'good' : 'bad' ?>"><?= htmlspecialchars($databaseDisplay) ?></div>
            <div class="metric-sub">Production database connectivity.</div>
        </article>
        <article class="metric">
            <div class="metric-head"><span class="metric-label">Database latency</span><span class="metric-icon">↯</span></div>
            <div class="metric-value"><?= htmlspecialchars($databaseLatencyDisplay) ?></div>
            <div class="metric-sub">Round-trip for the database health query.</div>
        </article>
        <article class="metric">
            <div class="metric-head"><span class="metric-label">Response time</span><span class="metric-icon">◷</span></div>
            <div class="metric-value"><?= htmlspecialchars($responseLatencyDisplay) ?></div>
            <div class="metric-sub">Time spent generating this health response.</div>
        </article>
    </section>

    <section class="details">
        <article class="panel">
            <div class="panel-head"><strong>Health response</strong><span>Machine-readable payload</span></div>
            <pre class="json" id="health-json"><?= htmlspecialchars((string)$jsonPretty) ?></pre>
            <div class="action-row">
                <button class="btn primary" type="button" id="copy-json">Copy JSON</button>
                <a class="btn" href="/public/health.php?format=json" target="_blank" rel="noopener">Open raw JSON</a>
                <a class="btn" href="/public/admin/index.php">Open Admin</a>
                <button class="btn" type="button" onclick="location.reload()">Run new check</button>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head"><strong>Operational overview</strong><span>Current request</span></div>
            <div class="timeline">
                <div class="timeline-row">
                    <span class="timeline-dot"></span>
                    <div><strong><?= $databaseOk ? 'Core services are healthy' : 'Database requires attention' ?></strong><p><?= $databaseOk ? 'The web process and database health query both completed successfully.' : 'The web process is available, but the database check did not complete successfully.' ?></p></div>
                </div>
                <div class="timeline-row">
                    <span class="timeline-dot"></span>
                    <div><strong>Automation stays JSON-safe</strong><p>Azure probes and clients that do not request HTML still receive the compact JSON response and the correct HTTP status.</p></div>
                </div>
                <div class="timeline-row">
                    <span class="timeline-dot"></span>
                    <div><strong>Traceable request</strong><p>Request ID: <code><?= htmlspecialchars(ErrorHandler::requestId()) ?></code></p></div>
                </div>
            </div>
        </article>
    </section>

    <footer class="footer">
        <span>Hercule License Server · Production health surface</span>
        <span>Service: <code>hercule-license-server</code></span>
    </footer>
</main>
<script>
(function () {
    var copyButton = document.getElementById('copy-json');
    var source = document.getElementById('health-json');
    if (!copyButton || !source) return;
    copyButton.addEventListener('click', function () {
        var text = source.textContent || '';
        var done = function () {
            copyButton.textContent = 'Copied';
            window.setTimeout(function () { copyButton.textContent = 'Copy JSON'; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {});
            return;
        }
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        area.remove();
    });
})();
</script>
</body>
</html>
