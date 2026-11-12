<?php
/**
 * Douffer PhotoSys: Phase 7 End-to-End HTTP Verification Suite.
 *
 * Covers:
 *   - Scenario T12: PWA-Lite (manifest.json, sw.js, offline.html), viewport meta,
 *     strict CSP compliance (zero inline style/on* attributes), and performance
 *     asset budgets (HTML < 50 KB/page, CSS <= 30 KB, JS <= 60 KB).
 *   - Scenario T13: Security Hardening Probes:
 *     - Session fixation protection (session_regenerate_id on login)
 *     - Security headers (Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Cache-Control)
 *     - SQL injection probes on login, booking lookup, and admin search filters
 *     - Reflected & stored XSS probes (HTML escaping on output)
 *     - CSRF protection (POST without _csrf token rejected with 403)
 *     - Private file streaming protection (/files/receipt/* and /files/chat/*)
 *     - Role-Based Access Control (Owner vs Staff vs Crew route enforcement)
 *     - All 6 Audit & Operational Log streams on Screen A21 (/admin/logs)
 *
 * Usage:
 *   php tests/e2e_phase7.php [http://localhost:8000]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function http_req(
    string $method,
    string $path,
    array $data = [],
    array &$cookies = [],
    array $files = []
): array {
    global $base;
    $ch = curl_init($base . $path);
    $headers = [];
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    if ($method === 'POST') {
        if ($files) {
            $payload = $data;
            foreach ($files as $field => $finfo) {
                $payload[$field] = new CURLFile($finfo['path'], $finfo['mime'], $finfo['name']);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }

    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]+)/mi', $rawHeaders, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $cookies[trim($match[1])] = trim($match[2]);
        }
    }

    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $lm)) {
        $location = trim($lm[1]);
    }

    return [
        'status'   => $status,
        'headers'  => $rawHeaders,
        'body'     => $body,
        'location' => $location,
    ];
}

function extract_csrf(string $html): string
{
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function login_as(string $username, string $password): array
{
    $cookies = [];
    $r1 = http_req('GET', '/admin/login', [], $cookies);
    $csrf = extract_csrf($r1['body']);
    http_req('POST', '/admin/login', [
        '_csrf'    => $csrf,
        'username' => $username,
        'password' => $password,
    ], $cookies);
    return $cookies;
}

echo "=== Phase 7 E2E Verification (T12 PWA/Budgets & T13 Security Hardening) ===\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/install.php') . ' --fresh', $out, $code);
check('0.0 Fresh database seed installed cleanly', $code === 0);

// ---------------------------------------------------------------------------
// 1. Scenario T12: PWA-Lite, Viewport Meta & Performance Asset Budgets
// ---------------------------------------------------------------------------
echo "\n--- 1. Scenario T12: PWA-Lite & Performance Budgets ---\n";

$c = [];
$rManifest = http_req('GET', '/manifest.json', [], $c);
check('1.1 GET /manifest.json returns 200', $rManifest['status'] === 200);
$manifestJson = json_decode($rManifest['body'], true);
check(
    '1.2 manifest.json contains standalone display and theme_color #0f766e',
    is_array($manifestJson)
        && ($manifestJson['display'] ?? '') === 'standalone'
        && ($manifestJson['theme_color'] ?? '') === '#0f766e'
);

$rSw = http_req('GET', '/sw.js', [], $c);
check(
    '1.3 GET /sw.js returns 200 and caches static shell only',
    $rSw['status'] === 200
        && str_contains($rSw['body'], '/offline.html')
        && str_contains($rSw['body'], '/admin')
);

$rOffline = http_req('GET', '/offline.html', [], $c);
check(
    '1.4 GET /offline.html returns 200 and contains offline notice without inline scripts',
    $rOffline['status'] === 200
        && str_contains($rOffline['body'], 'You are currently offline')
        && !str_contains($rOffline['body'], '<script')
);

$rHome = http_req('GET', '/', [], $c);
check(
    '1.5 C1 home links /manifest.json and declares viewport-fit=cover',
    $rHome['status'] === 200
        && str_contains($rHome['body'], 'rel="manifest"')
        && str_contains($rHome['body'], 'viewport-fit=cover')
);

$cssFile = $root . '/public/assets/css/app.css';
$cssBytes = (int) filesize($cssFile);
check('1.6 CSS bundle <= 30 KB (' . $cssBytes . ' B)', $cssBytes > 0 && $cssBytes <= 30 * 1024);

$jsBytes = 0;
foreach (glob($root . '/public/assets/js/*.js') ?: [] as $jf) {
    $jsBytes += (int) filesize($jf);
}
check('1.7 Total JS bundle <= 60 KB (' . $jsBytes . ' B)', $jsBytes > 0 && $jsBytes <= 60 * 1024);

// Check HTML size budget (< 50 KB per page) across public and authenticated routes
$ownerCookies = login_as('owner', 'Owner@2026');
$crewCookies = login_as('crew1', 'Crew1@2026');

$pagesToMeasure = [
    ['/', $c, 'C1 Home'],
    ['/availability', $c, 'C2 Availability'],
    ['/booking', $c, 'C5 Lookup'],
    ['/admin/login', $c, 'A1 Login'],
    ['/admin', $ownerCookies, 'A2 Dashboard'],
    ['/admin/bookings', $ownerCookies, 'A3 Bookings'],
    ['/admin/bookings/1', $ownerCookies, 'A4 Booking Detail'],
    ['/admin/payments', $ownerCookies, 'A6 Payments'],
    ['/admin/calendar', $ownerCookies, 'A9 Calendar'],
    ['/admin/projects', $ownerCookies, 'A10 Pipeline'],
    ['/admin/clients', $ownerCookies, 'A14 Clients'],
    ['/admin/packages', $ownerCookies, 'A15 Packages'],
    ['/admin/reports', $ownerCookies, 'A16 Reports'],
    ['/admin/users', $ownerCookies, 'A17 Users'],
    ['/admin/messages', $ownerCookies, 'A19 Messages'],
    ['/admin/settings', $ownerCookies, 'A20 Settings'],
    ['/admin/logs', $ownerCookies, 'A21 Logs'],
    ['/crew', $crewCookies, 'A18 Crew Schedule'],
];

$allUnder50Kb = true;
$noInlineViolations = true;
$failDetail = '';
foreach ($pagesToMeasure as [$url, $cookieJar, $label]) {
    $resp = http_req('GET', $url, [], $cookieJar);
    $len = strlen($resp['body']);
    if ($resp['status'] !== 200 || $len >= 50 * 1024) {
        $allUnder50Kb = false;
        $failDetail .= "{$label} ({$url}) status={$resp['status']} len={$len}; ";
    }
    if (preg_match('/\son[a-z]+\s*=/i', $resp['body']) || preg_match('/\sstyle\s*=/i', $resp['body'])) {
        $noInlineViolations = false;
    }
}
check('1.8 All 18 screens return HTTP 200 with HTML payload < 50 KB', $allUnder50Kb, $failDetail);
check('1.9 Zero inline style="" or on*="" attributes across all 18 screens', $noInlineViolations);

// ---------------------------------------------------------------------------
// 2. Scenario T13: Security Probes (SQLi, XSS, CSRF, Private Uploads, Fixation)
// ---------------------------------------------------------------------------
echo "\n--- 2. Scenario T13: Security Probes & Access Control ---\n";

// 2.1 Session fixation: session cookie must rotate on login
$fixCookies = [];
$rLoginGet = http_req('GET', '/admin/login', [], $fixCookies);
$preLoginSession = $fixCookies['dps_session'] ?? '';
$csrfLogin = extract_csrf($rLoginGet['body']);
$rLoginPost = http_req('POST', '/admin/login', [
    '_csrf'    => $csrfLogin,
    'username' => 'owner',
    'password' => 'Owner@2026',
], $fixCookies);
$postLoginSession = $fixCookies['dps_session'] ?? '';
check(
    '2.1 Session ID rotates on login (session_regenerate_id)',
    $preLoginSession !== '' && $postLoginSession !== '' && $preLoginSession !== $postLoginSession,
    "pre={$preLoginSession} post={$postLoginSession}"
);

// 2.2 Strict CSP and security headers present on responses
check(
    '2.2 Content-Security-Policy header enforces script-src self and style-src self',
    str_contains($rHome['headers'], "script-src 'self'")
        && str_contains($rHome['headers'], "style-src 'self'")
        && str_contains($rHome['headers'], 'X-Frame-Options: DENY')
        && str_contains($rHome['headers'], 'X-Content-Type-Options: nosniff')
        && str_contains($rHome['headers'], 'Cache-Control: no-store')
);

// 2.3 SQL injection probes on login, lookup, and search filters
$sqliCookies = [];
$rSqliGet = http_req('GET', '/admin/login', [], $sqliCookies);
$sqliCsrf = extract_csrf($rSqliGet['body']);
$rSqliLogin = http_req('POST', '/admin/login', [
    '_csrf'    => $sqliCsrf,
    'username' => "' OR '1'='1' -- ",
    'password' => "' OR '1'='1' -- ",
], $sqliCookies);
check(
    '2.3 SQLi payload on POST /admin/login does not bypass authentication',
    $rSqliLogin['location'] === '/admin/login' || $rSqliLogin['status'] === 200
);

$rLookupGet = http_req('GET', '/booking', [], $sqliCookies);
$lookupCsrf = extract_csrf($rLookupGet['body']);
$rSqliLookup = http_req('POST', '/booking/lookup', [
    '_csrf'   => $lookupCsrf,
    'code'    => "DPS-2026-0001' OR 1=1 --",
    'contact' => "' OR '1'='1",
], $sqliCookies);
check(
    '2.4 SQLi payload on POST /booking/lookup is safely rejected without 500 error',
    $rSqliLookup['status'] !== 500 && !str_contains($rSqliLookup['body'], 'PDOException')
);

$sqliQuery = urlencode("' UNION SELECT 1,2,3,4,5 -- ");
$rSqliBookings = http_req('GET', '/admin/bookings?q=' . $sqliQuery, [], $ownerCookies);
$rSqliClients = http_req('GET', '/admin/clients?q=' . $sqliQuery, [], $ownerCookies);
$rSqliLogs = http_req('GET', '/admin/logs?tab=audit&q=' . $sqliQuery, [], $ownerCookies);
check(
    '2.5 SQLi payload on GET /admin/bookings?q= returns 200 without SQL error',
    $rSqliBookings['status'] === 200 && !str_contains($rSqliBookings['body'], 'SQLSTATE')
);
check(
    '2.6 SQLi payload on GET /admin/clients?q= returns 200 without SQL error',
    $rSqliClients['status'] === 200 && !str_contains($rSqliClients['body'], 'SQLSTATE')
);
check(
    '2.7 SQLi payload on GET /admin/logs?q= returns 200 without SQL error',
    $rSqliLogs['status'] === 200 && !str_contains($rSqliLogs['body'], 'SQLSTATE')
);

// 2.4 XSS probes: reflected search queries and stored client notes are HTML-escaped
$xssPayload = '<script>alert("xss")</script>';
$rXssSearch = http_req('GET', '/admin/bookings?q=' . urlencode($xssPayload), [], $ownerCookies);
check(
    '2.8 Reflected XSS payload in /admin/bookings?q= is HTML-escaped',
    $rXssSearch['status'] === 200
        && !str_contains($rXssSearch['body'], $xssPayload)
        && str_contains($rXssSearch['body'], '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;')
);

$rClient1 = http_req('GET', '/admin/clients/1', [], $ownerCookies);
$clientCsrf = extract_csrf($rClient1['body']);
http_req('POST', '/admin/clients/1/inquiries', [
    '_csrf'     => $clientCsrf,
    'channel'   => 'messenger',
    'note'      => 'VIP <img src=x onerror=alert(1)> client',
    'return_to' => 'show',
], $ownerCookies);
$rClient1After = http_req('GET', '/admin/clients/1', [], $ownerCookies);
check(
    '2.9 Stored XSS payload in client inquiry notes is HTML-escaped on render',
    !str_contains($rClient1After['body'], '<img src=x onerror=alert(1)>')
        && str_contains($rClient1After['body'], '&lt;img src=x onerror=alert(1)&gt;')
);

// 2.5 CSRF probes: POST without _csrf token is rejected with 403 across endpoints
$csrfTargets = [
    '/admin/packages',
    '/admin/users',
    '/admin/settings',
    '/admin/backups',
    '/admin/clients/1/inquiries',
    '/admin/bookings/1/notes',
];
foreach ($csrfTargets as $idx => $target) {
    $rCsrf = http_req('POST', $target, ['dummy' => '1'], $ownerCookies);
    $num = 10 + $idx;
    check("2.{$num} POST {$target} without CSRF token returns 403", $rCsrf['status'] === 403);
}

// 2.6 Private upload protection: unauthenticated requests cannot stream receipts or chat images
$anonCookies = [];
$rAnonReceipt = http_req('GET', '/files/receipt/6', [], $anonCookies);
check(
    '2.16 Unauthenticated GET /files/receipt/6 is denied (403/404)',
    in_array($rAnonReceipt['status'], [403, 404], true)
);
$rAnonChat = http_req('GET', '/files/chat/1', [], $anonCookies);
check(
    '2.17 Unauthenticated GET /files/chat/1 is denied (403/404)',
    in_array($rAnonChat['status'], [403, 404], true)
);
$rCrewReceipt = http_req('GET', '/files/receipt/6', [], $crewCookies);
check(
    '2.18 Crew GET /files/receipt/6 is denied (403/404)',
    in_array($rCrewReceipt['status'], [403, 404], true)
);

// 2.7 Role-Based Access Control (RBAC): Staff and Crew restrictions
$staffCookies = login_as('staff', 'Staff@2026');
$staffOwnerOnlyRoutes = [
    '/admin/packages',
    '/admin/users',
    '/admin/settings',
    '/admin/logs',
    '/admin/logs/export.csv',
];
foreach ($staffOwnerOnlyRoutes as $idx => $route) {
    $rStaff = http_req('GET', $route, [], $staffCookies);
    $num = 19 + $idx;
    check("2.{$num} Staff GET {$route} returns 403", $rStaff['status'] === 403);
}

$crewAdminRoutes = [
    '/admin',
    '/admin/bookings',
    '/admin/payments',
    '/admin/projects',
    '/admin/messages',
];
foreach ($crewAdminRoutes as $idx => $route) {
    $rCrew = http_req('GET', $route, [], $crewCookies);
    $num = 24 + $idx;
    check("2.{$num} Crew GET {$route} returns 403", $rCrew['status'] === 403);
}

// 2.8 All 6 Audit & Operational Log Streams render on A21 (/admin/logs)
$logTabs = [
    'audit'    => 'Audit Logs',
    'bookings' => 'Booking History',
    'projects' => 'Project History',
    'messages' => 'Message Log',
    'backups'  => 'Backup Runs',
    'system'   => 'System',
];
$tabIdx = 0;
foreach ($logTabs as $tabKey => $tabLabel) {
    $rTab = http_req('GET', '/admin/logs?tab=' . $tabKey, [], $ownerCookies);
    $num = 29 + $tabIdx;
    check(
        "2.{$num} Owner GET /admin/logs?tab={$tabKey} returns 200",
        $rTab['status'] === 200 && str_contains($rTab['body'], 'Audit &amp; System Logs')
    );
    $tabIdx++;
}

echo "\n==================================================\n";
echo "Phase 7 E2E Summary: {$passed} passed, {$failed} failed\n";
echo "==================================================\n";
exit($failed > 0 ? 1 : 0);
