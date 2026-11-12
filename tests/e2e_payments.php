<?php
/**
 * Douffer PhotoSys: Phase 3 end-to-end HTTP test (T2, T6, T8, T9, A4, A6, A7, A8, C5 reupload & confirmation).
 * Start the app first:  php -S localhost:8000 -t public public/index.php
 * Run:                  php tests/e2e_payments.php [base-url]
 * Runs against fresh seed data and restores fresh seed data at the end.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);
$fail = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : "  [$extra]") . PHP_EOL;
    $fail += $ok ? 0 : 1;
}

final class Browser
{
    private string $jar;
    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'dps3');
    }
    public function req(string $path, string $method = 'GET', array $fields = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 20,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $hasFile = false;
            foreach ($fields as $v) {
                if ($v instanceof CURLFile) {
                    $hasFile = true;
                    break;
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $fields : http_build_query($fields));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        return ['status' => $status, 'body' => substr($raw, $hs), 'location' => $m[1] ?? ''];
    }
    public function token(string $path): string
    {
        $r = $this->req($path);
        return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : '';
    }
    public function login(string $user, string $pass): void
    {
        $t = $this->token('/admin/login');
        $this->req('/admin/login', 'POST', ['_csrf' => $t, 'username' => $user, 'password' => $pass]);
    }
}

// Ensure clean seed data before starting
exec('C:\Xampp\php\php.exe ' . escapeshellarg($root . '/database/install.php') . ' --fresh');

$owner = new Browser($base);
$owner->login('owner', 'Owner@2026');

// ---------------------------------------------------------------- A4 Ledger
$r = $owner->req('/admin/bookings');
check('A4 Bookings ledger renders all active bookings', $r['status'] === 200 && str_contains($r['body'], 'Angela Santos') && str_contains($r['body'], 'Liza Reyes'));

$r = $owner->req('/admin/bookings?filter=balance_due');
check('A4 Balance due filter shows Santos and Cruz, hides Dela Cruz (completed)',
    str_contains($r['body'], 'Angela Santos') && str_contains($r['body'], 'Carlo Cruz') && !str_contains($r['body'], 'Maria Dela Cruz'));

$r = $owner->req('/admin/bookings?q=DPS-2026-0004');
check('A4 Search by reference code finds Santos',
    str_contains($r['body'], 'Angela Santos') && !str_contains($r['body'], 'Ramon Lopez'));

// ---------------------------------------------------------------- A7 Queue & T9 Duplicate warning
$r = $owner->req('/admin/payments');
check('A7 shows 2 waiting payments (Reyes #8 and Mendoza #9)',
    $r['status'] === 200 && str_contains($r['body'], 'Payments to verify (2)') && str_contains($r['body'], 'Liza Reyes') && str_contains($r['body'], 'Joy Mendoza'));
check('A7 flags duplicate GCash reference 1001234567807 on Mendoza (#9) referencing DPS-2026-0005',
    str_contains($r['body'], 'Duplicate GCash reference number detected!') && str_contains($r['body'], 'DPS-2026-0005'));

// Try verifying Mendoza (#9) WITHOUT ticking ack_duplicate -> bounced back with error
$t = $owner->token('/admin/payments');
$r = $owner->req('/admin/payments/9/verify', 'POST', ['_csrf' => $t]);
check('T9 Verifying duplicate ref without ack redirects back to A7',
    $r['status'] === 303 && str_contains($r['location'], '/admin/payments#payment-9'), $r['location']);
$r2 = $owner->req('/admin/payments');
check('T9 Error toast shown when duplicate ref is not acknowledged',
    str_contains($r2['body'], 'already used. Tick the box'));

// Verify Mendoza (#9) WITH ack_duplicate = 1 -> confirms booking #7 and redirects to A8
$t = $owner->token('/admin/payments');
$r = $owner->req('/admin/payments/9/verify', 'POST', ['_csrf' => $t, 'ack_duplicate' => '1']);
check('T9 Verifying duplicate ref WITH ack confirms Mendoza (#7) -> A8',
    $r['status'] === 303 && str_ends_with($r['location'], '/admin/bookings/7/confirmation'), $r['location']);

// ---------------------------------------------------------------- Reject + C5 Re-upload + T2 Verify on Reyes (#6 / payment #8)
$t = $owner->token('/admin/payments');
$r = $owner->req('/admin/payments/8/reject', 'POST', ['_csrf' => $t, 'reason_choice' => 'unclear', 'reason_custom' => '']);
check('Rejecting Reyes payment #8 redirects to A7', $r['status'] === 303 && str_ends_with($r['location'], '/admin/payments'));

// Client looks up DPS-2026-0006 (Reyes) and sees rejection box, then re-uploads
$client = new Browser($base);
$tc = $client->token('/booking');
$client->req('/booking', 'POST', ['_csrf' => $tc, 'reference_no' => 'DPS-2026-0006', 'mobile' => '0917 000 0006']);
$r = $client->req('/booking/DPS-2026-0006');
check('C5 Client sees rejection reason and re-upload form',
    $r['status'] === 200 && str_contains($r['body'], 'Screenshot is unclear or unreadable') && str_contains($r['body'], 'Send new screenshot'));

$png = $root . '/storage/receipts/sample-reyes.png';
$tc = $client->token('/booking/DPS-2026-0006');
$r = $client->req('/booking/DPS-2026-0006/reupload', 'POST', [
    '_csrf' => $tc,
    'gcash_reference_no' => '1001234567899',
    'receipt' => new CURLFile($png, 'image/png', 'clear-reyes.png'),
]);
check('C5 Client re-uploads new screenshot -> back to C5', $r['status'] === 303 && str_ends_with($r['location'], '/booking/DPS-2026-0006'));

// Owner now sees the new payment (#10) in A7 and verifies it (T2)
$r = $owner->req('/admin/payments');
check('A7 shows re-uploaded Reyes payment (#10) with ref 1001234567899',
    str_contains($r['body'], '1001234567899') && str_contains($r['body'], 'Payments to verify (1)'));

$t = $owner->token('/admin/payments');
$r = $owner->req('/admin/payments/10/verify', 'POST', ['_csrf' => $t]);
check('T2 Owner verifies Reyes payment #10 -> redirects to A8 confirmation',
    $r['status'] === 303 && str_ends_with($r['location'], '/admin/bookings/6/confirmation'), $r['location']);

$r = $owner->req('/admin/bookings/6/confirmation');
check('A8 Confirmation sheet shows DPS-2026-0006, Confirmed tag, Copy for Messenger, Print',
    $r['status'] === 200 && str_contains($r['body'], 'BOOKING CONFIRMATION') && str_contains($r['body'], 'DPS-2026-0006')
    && str_contains($r['body'], 'Copy for Messenger') && str_contains($r['body'], 'data-print'));

$r = $owner->req('/admin/bookings/6/confirmation?tab=receipt');
check('A8 Official receipt tab shows OR-2026-0009 and remaining balance ₱5,600.00',
    $r['status'] === 200 && str_contains($r['body'], 'OFFICIAL RECEIPT') && str_contains($r['body'], 'OR-2026-0009') && str_contains($r['body'], '₱5,600.00'));

// Client can now view C5 confirmed status and open A8 client confirmation
$r = $client->req('/booking/DPS-2026-0006');
check('C5 Client now sees Booking confirmed and active confirmation link',
    str_contains($r['body'], 'Booking confirmed') && str_contains($r['body'], '/booking/DPS-2026-0006/confirmation'));
$r = $client->req('/booking/DPS-2026-0006/confirmation');
check('A8 Client confirmation view renders confirmation sheet without admin receipt tabs',
    $r['status'] === 200 && str_contains($r['body'], 'BOOKING CONFIRMATION') && !str_contains($r['body'], 'Official receipt ('));

// ---------------------------------------------------------------- T6 Record balance payment on Santos (#4)
$r = $owner->req('/admin/bookings/4');
check('A6 Santos booking details hub shows ₱11,200.00 balance due and Record balance payment',
    $r['status'] === 200 && str_contains($r['body'], '₱11,200.00') && str_contains($r['body'], 'Record balance payment'));

$t = $owner->token('/admin/bookings/4');
$r = $owner->req('/admin/bookings/4/payments', 'POST', [
    '_csrf'  => $t,
    'type'   => 'balance',
    'method' => 'cash',
    'amount' => '11200.00',
    'notes'  => 'Paid cash before ceremony',
]);
check('T6 Recording Santos balance payment redirects to A8 Official Receipt',
    $r['status'] === 303 && str_contains($r['location'], '/admin/bookings/4/confirmation?tab=receipt&payment_id='), $r['location']);

$locPath = preg_replace('#^(https?://[^/]+)?(/douffer_photosys)?#', '', $r['location']);
$r = $owner->req($locPath);
check('T6 A8 Official Receipt for Santos shows ₱11,200.00 received and ₱0.00 remaining balance',
    $r['status'] === 200 && str_contains($r['body'], 'OFFICIAL RECEIPT') && str_contains($r['body'], '₱11,200.00') && str_contains($r['body'], '₱0.00'));

$r = $owner->req('/admin/bookings/4');
check('T6 A6 Santos balance due is now ₱0.00',
    $r['status'] === 200 && str_contains($r['body'], 'Balance due</span>') && str_contains($r['body'], '₱0.00'));

// Restore clean seed database so everything is left pristine
exec('C:\Xampp\php\php.exe ' . escapeshellarg($root . '/database/install.php') . ' --fresh');

echo PHP_EOL . ($fail === 0 ? 'All Phase 3 end-to-end checks passed.' : "$fail check(s) FAILED.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
