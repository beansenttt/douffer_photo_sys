<?php
/**
 * Douffer PhotoSys: HTTP smoke test (roles, redirects, 403s, CSRF).
 * Start the app first:  php -S localhost:8000 -t public public/index.php
 * Then run:             php tests/smoke_http.php [base-url]
 * Requires the sample data (php database/install.php --fresh).
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$fail = 0;

function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $name . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
}

/** Minimal HTTP client with a cookie jar (no curl needed). */
final class Client
{
    public array $cookies = [];

    public function __construct(private string $base)
    {
    }

    public function req(string $path, string $method = 'GET', array $form = []): array
    {
        $headers = ["User-Agent: dps-smoke"];
        if ($this->cookies) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        $opts = ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'header' => ''];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($form);
        }
        $opts['header'] = implode("\r\n", $headers);
        $body = @file_get_contents($this->base . $path, false, stream_context_create(['http' => $opts]));
        $status = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            } elseif (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) {
                if ($m[2] === '' || $m[2] === 'deleted') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        return ['status' => $status, 'body' => (string) $body, 'location' => $location];
    }

    public static function token(string $html): string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
    }

    public function login(string $user, string $pass): array
    {
        $page = $this->req('/admin/login');
        return $this->req('/admin/login', 'POST', ['_csrf' => self::token($page['body']), 'username' => $user, 'password' => $pass]);
    }
}

$anon = new Client($base);
$r = $anon->req('/');
check('C1 home 200', $r['status'] === 200 && str_contains($r['body'], 'Book your event coverage'));
$r = $anon->req('/privacy');
check('Privacy notice 200', $r['status'] === 200 && str_contains($r['body'], '10173'));
$r = $anon->req('/availability');
check('C2 placeholder 200', $r['status'] === 200);
$r = $anon->req('/does-not-exist');
check('Unknown page -> friendly 404', $r['status'] === 404 && str_contains($r['body'], 'Page not found'));
$r = $anon->req('/admin');
check('Admin without login -> redirect to A1', $r['status'] === 303 && str_ends_with($r['location'], '/admin/login'));
$r = $anon->req('/admin/login');
check('A1 login page 200', $r['status'] === 200 && str_contains($r['body'], 'Forgot password? Ask the owner.'));
$r = $anon->req('/admin/login', 'POST', ['username' => 'owner', 'password' => 'Owner@2026']);
check('Login POST without CSRF token -> rejected (403 Session expired)', $r['status'] === 403 && str_contains($r['body'], 'Session expired'));
$r = $anon->req('/assets/css/app.css');
check('Static CSS served', $r['status'] === 200);
$r = $anon->req('/admin/logout', 'GET');
check('GET on POST-only route -> 405', $r['status'] === 405);

$owner = new Client($base);
$r = $owner->login('owner', 'Owner@2026');
check('Owner login -> A2', $r['status'] === 303 && str_ends_with($r['location'], '/admin'));
$r = $owner->req('/admin');
check('A2 shows change-password notice', $r['status'] === 200 && str_contains($r['body'], 'Change the default password'));
check('Unread messages badge = 1', str_contains($r['body'], 'data-unread-badge>1<'));
check('Admin bottom nav has 5 items', substr_count($r['body'], 'class="bottomnav bottomnav-5"') === 1);
$r = $owner->req('/admin/more');
check('More menu (owner) lists Users and access', str_contains($r['body'], 'Users and access'));
$r = $owner->req('/admin/users');
check('Owner opens A17', $r['status'] === 200);
$r = $owner->req('/crew');
check('Owner on crew screen -> 403', $r['status'] === 403);

$bad = new Client($base);
$r = $bad->login('owner', 'wrong-password');
check('Wrong password -> back to A1', $r['status'] === 303 && str_ends_with($r['location'], '/admin/login'));

$staff = new Client($base);
$r = $staff->login('staff', 'Staff@2026');
check('Staff login -> A2', str_ends_with($r['location'], '/admin'));
check('Staff opens A3 (view_bookings)', $staff->req('/admin/calendar')['status'] === 200);
check('Staff A17 Users -> 403 (owner only)', $staff->req('/admin/users')['status'] === 403);
check('Staff A7 without verify_payments -> 403', $staff->req('/admin/payments')['status'] === 403);
check('Staff More menu hides Users', !str_contains($staff->req('/admin/more')['body'], 'Users and access'));

$crew = new Client($base);
$r = $crew->login('crew2', 'Crew2@2026');
check('Crew login -> A18', str_ends_with($r['location'], '/crew'));
$r = $crew->req('/crew');
check('A18 shows view-only note', $r['status'] === 200 && str_contains($r['body'], 'View-only. Payments and other records are hidden.'));
$r = $crew->req('/admin');
check('Crew on /admin -> 403 without admin nav', $r['status'] === 403 && !str_contains($r['body'], 'bottomnav-5'));
check('Crew on /admin/messages -> 403', $crew->req('/admin/messages')['status'] === 403);

// Change password flow (then restore so the test can be re-run)
$r = $crew->req('/account/password');
$t = Client::token($r['body']);
$r = $crew->req('/account/password', 'POST', ['_csrf' => $t, 'current_password' => 'Crew2@2026', 'password' => 'short', 'password_confirm' => 'short']);
check('Weak new password rejected', $r['status'] === 303 && str_ends_with($r['location'], '/account/password'));

// Logout (the logout button lives in the More menu)
$r = $owner->req('/admin/more');
$r = $owner->req('/admin/logout', 'POST', ['_csrf' => Client::token($r['body'])]);
check('Logout -> A1', $r['status'] === 303);
check('After logout, /admin needs login again', $owner->req('/admin')['status'] === 303);

// Security headers
$ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
@file_get_contents($base . '/', false, $ctx);
$hdrs = implode("\n", $http_response_header ?? []);
check('CSP header sent', str_contains($hdrs, 'Content-Security-Policy:'));
check('X-Frame-Options DENY', str_contains($hdrs, 'X-Frame-Options: DENY'));
check('Session cookie HttpOnly + SameSite', (bool) preg_match('/Set-Cookie: dps_session=[^\n]*HttpOnly[^\n]*SameSite=Lax/i', $hdrs));

echo PHP_EOL . ($fail === 0 ? 'All smoke tests passed.' : "$fail smoke test(s) FAILED.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
