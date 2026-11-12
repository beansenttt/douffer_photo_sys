<?php
/**
 * Douffer PhotoSys: Phase 2 end-to-end test (acceptance test T1 + security checks).
 * Start the app first:  php -S localhost:8000 -t public public/index.php
 * Run:                  php tests/e2e_booking.php [base-url]
 * Creates a real pending booking in the database each time it runs.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);
$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : "  $extra") . PHP_EOL;
    $fail += $ok ? 0 : 1;
}

final class Browser
{
    private string $jar;
    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'dps');
    }
    public function req(string $path, string $method = 'GET', array $fields = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); // array => multipart (CURLFile ok)
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $head, $ct);
        return ['status' => $status, 'body' => substr($raw, $hs), 'location' => $m[1] ?? '', 'type' => $ct[1] ?? ''];
    }
    public function token(string $path): string
    {
        $r = $this->req($path);
        return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m) ? $m[1] : '';
    }
}

require $root . '/app/bootstrap.php';
function q(string $sql, array $p = []) { return \App\Core\DB::value($sql, $p); }
$png = $root . '/storage/receipts/sample-reyes.png';
if (!is_file($png)) {
    exit("Sample image missing. Run: php database/install.php --fresh\n");
}
\App\Core\DB::run('DELETE FROM rate_limits'); // earlier runs may have used up the limits
$mobile = '09' . random_int(100000000, 999999999);

// ---------------------------------------------------------------- C1 / C2
$c = new Browser($base);
$r = $c->req('/');
check('C1 lists the 3 packages', $r['status'] === 200 && str_contains($r['body'], 'Photo + Video Package') && str_contains($r['body'], 'Videography Package'));
check('C1 shows rate and inclusions sheet', str_contains($r['body'], '₱16,000.00') && str_contains($r['body'], 'SAMPLE – edit in Packages'));
check('C1 other services chips', str_contains($r['body'], 'Makeup artist') && str_contains($r['body'], 'Please message the studio to ask about this service.'));

$r = $c->req('/availability?month=2026-12');
check('C2 December 2026 renders', $r['status'] === 200 && str_contains($r['body'], 'December 2026'));
check('C2 Dec 25 (blocked) is unavailable', (bool) preg_match('/is-unavailable[^>]*aria-label="Fri, Dec 25, not available"|aria-label="Fri, Dec 25, not available"/', $r['body']));
check('C2 Dec 15 is available', str_contains($r['body'], 'aria-label="Tue, Dec 15, available"'));
check('C2 past date (Dec 5) not available', str_contains($r['body'], 'aria-label="Sat, Dec 5, not available"'));
$r = $c->req('/availability?month=2026-12&date=2026-12-25');
check('C2 tapping a blocked date shows the sorry message', str_contains($r['body'], 'Sorry, this date is not available. Please choose another date.') && !str_contains($r['body'], 'Continue to booking details'));
$r = $c->req('/availability?month=2026-12&date=2026-12-15');
check('C2 open date shows Available + Continue', str_contains($r['body'], 'Available') && str_contains($r['body'], '/book?date=2026-12-15'));

// ---------------------------------------------------------------- C3
$r = $c->req('/book?date=2026-12-25');
check('C3 blocked date is refused (back to C2)', $r['status'] === 303 && str_contains($r['location'], '/availability'));
$r = $c->req('/book?date=2026-12-15');
check('C3 form for Dec 15 shows prefilled date', $r['status'] === 200 && str_contains($r['body'], 'value="2026-12-15"'));
$t = $c->token('/book?date=2026-12-15');

$r = $c->req('/book', 'POST', ['_csrf' => $t, 'full_name' => '', 'mobile' => '123', 'event_date' => '2026-12-15']);
check('C3 empty/invalid form is bounced', $r['status'] === 303 && str_contains($r['location'], '/book'));
$r = $c->req($r['location'] ? preg_replace('#^https?://[^/]+#', '', $r['location']) : '/book');
check('C3 shows field errors under the fields', str_contains($r['body'], 'Please fill in this field.') && str_contains($r['body'], 'Philippine mobile number'));

$t = $c->token('/book?date=2026-12-15');
$r = $c->req('/book', 'POST', ['_csrf' => $t, 'full_name' => 'E2E Tester', 'mobile' => $mobile, 'event_type' => 'Wedding', 'package_id' => '3',
    'event_date' => '2026-12-25', 'venue' => 'Somewhere', 'privacy_consent' => '1']);
$r2 = $c->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('C3 server re-validates the date (blocked Dec 25 rejected)', str_contains($r2['body'], 'Sorry, this date is not available'));

$t = $c->token('/book?date=2026-12-15');
$r = $c->req('/book', 'POST', ['_csrf' => $t, 'full_name' => 'E2E Tester <b>x</b>', 'mobile' => $mobile, 'messenger_name' => 'e2e', 'event_type' => 'Wedding',
    'package_id' => '3', 'event_date' => '2026-12-15', 'start_time' => '09:00', 'end_time' => '17:00', 'venue' => 'Nasugbu Beach Hall',
    'special_requests' => '', 'privacy_consent' => '1']);
check('C3 valid details -> C4', $r['status'] === 303 && str_ends_with($r['location'], '/book/pay'), $r['location']);

// ---------------------------------------------------------------- C4
$r = $c->req('/book/pay');
check('C4 summary: total, 30% down payment (rounded to ₱50), balance', str_contains($r['body'], '₱16,000.00') && str_contains($r['body'], '₱4,800.00') && str_contains($r['body'], '₱11,200.00'));
check('C4 GCash box + required fields', str_contains($r['body'], 'GCash reference number') && str_contains($r['body'], 'accept="image/*"') && str_contains($r['body'], 'non-refundable'));
$t = $c->token('/book/pay');

$r = $c->req('/book/pay', 'POST', ['_csrf' => $t, 'gcash_reference_no' => '1009990001111', 'terms_ack' => '1']);
$r2 = $c->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('C4 missing screenshot is rejected with a message', str_contains($r2['body'], 'Please choose a screenshot'));

$tmpTxt = tempnam(sys_get_temp_dir(), 'dps') . '.png';
file_put_contents($tmpTxt, '<?php echo "not an image"; ?>');
$t = $c->token('/book/pay');
$r = $c->req('/book/pay', 'POST', ['_csrf' => $t, 'gcash_reference_no' => '1009990001111', 'terms_ack' => '1', 'receipt' => new CURLFile($tmpTxt, 'image/png', 'evil.png')]);
$r2 = $c->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('C4 fake image (PHP renamed .png) is rejected', str_contains($r2['body'], 'Please upload a JPG, PNG or WebP picture'));
unlink($tmpTxt);

$t = $c->token('/book/pay');
$r = $c->req('/book/pay', 'POST', ['_csrf' => $t, 'gcash_reference_no' => '1009990001111', 'receipt' => new CURLFile($png, 'image/png', 'shot.png')]);
$r2 = $c->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('C4 terms checkbox is required', str_contains($r2['body'], 'Please tick this box'));

$before = (int) q('SELECT COUNT(*) FROM bookings');
$t = $c->token('/book/pay');
$r = $c->req('/book/pay', 'POST', ['_csrf' => $t, 'gcash_reference_no' => '1009990001111', 'terms_ack' => '1', 'receipt' => new CURLFile($png, 'image/png', 'shot.png')]);
check('C4 submit -> C5 with reference', $r['status'] === 303 && preg_match('#/booking/(DPS-\d{4}-\d{4})$#', $r['location'], $m) === 1, $r['location']);
$ref = $m[1] ?? '';
$after = (int) q('SELECT COUNT(*) FROM bookings');
check('Exactly one booking created', $after === $before + 1);

// double submit (back button) must not create another booking
$t = $c->token('/booking'); // any page with a form
$r = $c->req('/book/pay', 'POST', ['_csrf' => $t, 'gcash_reference_no' => '1009990001111', 'terms_ack' => '1', 'receipt' => new CURLFile($png, 'image/png', 'shot.png')]);
$after2 = (int) q('SELECT COUNT(*) FROM bookings');
check('Double submit does not duplicate the booking', $after2 === $after && str_ends_with($r['location'], "/booking/$ref"), "count $after->$after2 status {$r['status']} loc {$r['location']}");

// ---------------------------------------------------------------- C5
$r = $c->req("/booking/$ref");
check('C5 shows reference, "Payment being checked", balance', $r['status'] === 200 && str_contains($r['body'], $ref) && str_contains($r['body'], 'Payment being checked') && str_contains($r['body'], 'Being checked'));
check('C5 confirmation button disabled until confirmed', str_contains($r['body'], 'aria-disabled="true"') && !str_contains($r['body'], "/booking/$ref/confirmation"));
check('C5 escapes HTML in client input', !str_contains($r['body'], '<b>x</b>'));
check('C5 has Message the studio', str_contains($r['body'], 'Message the studio'));

$row = \App\Core\DB::one(
    "SELECT b.status AS bs, p.status AS ps, p.amount, p.gcash_reference_no AS ref,
            (SELECT COUNT(*) FROM conversations WHERE booking_id = b.id) AS convs,
            (SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = b.id) AS msgs
     FROM bookings b JOIN payments p ON p.booking_id = b.id WHERE b.reference_code = ?",
    [$ref]
) ?? [];
check('DB: booking pending, payment pending 4800, conversation + system message',
    ($row['bs'] ?? '') === 'pending' && ($row['ps'] ?? '') === 'pending' && (float) ($row['amount'] ?? 0) === 4800.0
    && ($row['ref'] ?? '') === '1009990001111' && (int) ($row['convs'] ?? 0) === 1 && (int) ($row['msgs'] ?? 0) === 1, json_encode($row));

// receipt file access: owner of the booking yes; stranger no
$pid = (int) q('SELECT p.id FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.reference_code = ?', [$ref]);
$r = $c->req("/files/receipt/$pid/thumb");
check('Owner can load own screenshot thumbnail', $r['status'] === 200 && str_starts_with($r['type'], 'image/'), "{$r['status']} {$r['type']}");
$stranger = new Browser($base);
check('Stranger cannot load the screenshot (404)', $stranger->req("/files/receipt/$pid")['status'] === 404);
check('Stranger cannot open the booking by URL', $stranger->req("/booking/$ref")['status'] === 303);
check('Path traversal in files route is not routable', $stranger->req('/files/receipt/1/../../config/config.php')['status'] === 404);

// ---------------------------------------------------------------- lookup
$t = $stranger->token('/booking');
$r = $stranger->req('/booking', 'POST', ['_csrf' => $t, 'reference_no' => $ref, 'mobile' => '09170000099']);
$r2 = $stranger->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('Lookup with wrong mobile fails with a generic message', str_contains($r2['body'], 'We could not find a booking with those details'));
$r = $stranger->req('/booking', 'POST', ['_csrf' => $t, 'reference_no' => 'DPS-2099-9999', 'mobile' => $mobile]);
$r2 = $stranger->req(preg_replace('#^https?://[^/]+#', '', $r['location']));
check('Lookup with unknown reference gives the same message', str_contains($r2['body'], 'We could not find a booking with those details'));
$r = $stranger->req('/booking', 'POST', ['_csrf' => $t, 'reference_no' => strtolower($ref), 'mobile' => '+63' . substr($mobile, 1)]);
check('Lookup with correct ref + mobile (+63 format) opens C5', $r['status'] === 303 && str_ends_with($r['location'], "/booking/$ref"), $r['location']);
check('...and then the booking opens', $stranger->req("/booking/$ref")['status'] === 200);

// ---------------------------------------------------------------- T8-ish: pending does not lock the date
$r = (new Browser($base))->req('/availability?month=2026-12&date=2026-12-15');
check('Pending request does NOT lock Dec 15 (BR-1)', str_contains($r['body'], '/book?date=2026-12-15'));

echo PHP_EOL . ($fail === 0 ? 'All Phase 2 end-to-end checks passed.' : "$fail check(s) FAILED.") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
