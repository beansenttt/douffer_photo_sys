<?php
/**
 * Douffer PhotoSys: business-rule and core tests (no PHPUnit needed).
 *   php tests/run.php
 * Uses the database from config/config.php. Run "php database/install.php --fresh" first.
 * Each phase adds tests here for its new logic.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\RateLimiter;
use App\Core\Router;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\ProjectService;
use App\Services\MessageTemplateService;
use App\Services\DashboardService;
use App\Services\CalendarService;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $result = $fn();
        if ($result === false) {
            throw new RuntimeException('assertion returned false');
        }
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n      " . $e->getMessage() . "\n";
    }
}

function eq(mixed $expected, mixed $actual): bool
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    return true;
}

$_SESSION = [];

echo "Phase 1: foundation\n";

test('normalize_mobile accepts 09xx, +63 and spaced formats', function () {
    eq('09171234567', normalize_mobile('09171234567'));
    eq('09171234567', normalize_mobile('0917 123 4567'));
    eq('09171234567', normalize_mobile('+63 917 123 4567'));
    eq('09171234567', normalize_mobile('639171234567'));
    eq(null, normalize_mobile('12345'));
    return eq(null, normalize_mobile('0817 123 4567'));
});

test('format_mobile shows 0917 123 4567', fn () => eq('0917 123 4567', format_mobile('+639171234567')));

test('peso() formats with ₱ and two decimals', function () {
    eq('₱12,500.00', peso(12500));
    return eq('₱0.00', peso(null));
});

test('date_long / time12 match the prototype format', function () {
    eq('Sat, Dec 12, 2026', date_long('2026-12-12'));
    return eq('2:00 PM', time12('14:00:00'));
});

test('Validator: required, mobile, date, accepted', function () {
    $errors = Validator::make(
        ['full_name' => '  ', 'mobile' => '123', 'event_date' => '2026-02-30', 'consent' => ''],
        ['full_name' => 'required', 'mobile' => 'required|mobile', 'event_date' => 'required|date', 'consent' => 'accepted']
    );
    eq(['full_name', 'mobile', 'event_date', 'consent'], array_keys($errors));
    return eq('Please fill in this field.', $errors['full_name']);
});

test('Validator: valid input passes; optional empty fields skip other rules', function () {
    $errors = Validator::make(
        ['full_name' => 'Ana', 'mobile' => '0917 123 4567', 'messenger' => '', 'start' => '09:00', 'end' => '17:00'],
        ['full_name' => 'required|max:120', 'mobile' => 'required|mobile', 'messenger' => 'max:5', 'start' => 'time', 'end' => 'time|after_time:start']
    );
    return eq([], $errors);
});

test('Validator: end time must be after start time', function () {
    $errors = Validator::make(['start' => '17:00', 'end' => '09:00'], ['end' => 'after_time:start']);
    return eq(true, isset($errors['end']));
});

test('Validator: strong password rule', function () {
    eq(true, Validator::passwordProblem('short') !== null);
    eq(true, Validator::passwordProblem('onlyletters') !== null);
    return eq(null, Validator::passwordProblem('Studio2026x'));
});

test('Validator: GCash reference allows 13 digits, rejects symbols', function () {
    eq([], Validator::make(['r' => '1001234567801'], ['r' => 'gcash_ref']));
    return eq(true, isset(Validator::make(['r' => '12-34'], ['r' => 'gcash_ref'])['r']));
});

test('Validator: "future" uses the demo date as today', function () {
    Clock::setToday('2026-12-12');
    eq(true, isset(Validator::make(['d' => '2026-12-11'], ['d' => 'future'])['d']));
    eq([], Validator::make(['d' => '2026-12-12'], ['d' => 'future']));
    Clock::setToday(null);
    return true;
});

test('Clock honours demo_date', function () {
    Clock::setToday('2026-12-12');
    eq('2026-12-12', Clock::today());
    Clock::setToday(null);
    return true;
});

test('Router: {id} matches digits only, {ref} matches reference codes', function () {
    eq(1, preg_match(Router::compile('/admin/bookings/{id}'), '/admin/bookings/42'));
    eq(0, preg_match(Router::compile('/admin/bookings/{id}'), '/admin/bookings/new'));
    return eq(1, preg_match(Router::compile('/booking/{ref}'), '/booking/DPS-2026-0001'));
});

test('CSRF: correct token passes, wrong/missing fails', function () {
    $t = Csrf::token();
    eq(true, Csrf::check($t));
    eq(false, Csrf::check('nope'));
    return eq(false, Csrf::check(null));
});

test('Database: schema installed with sample data', function () {
    eq(4, (int) DB::value('SELECT COUNT(*) FROM users'));
    return eq(true, (int) DB::value('SELECT COUNT(*) FROM bookings') >= 7);
});

test('Settings: defaults from §3.3 are stored (30%, 2/day, 14 days)', function () {
    Settings::flush();
    eq('percent', Settings::get('down_payment_mode'));
    eq(30, Settings::int('down_payment_value'));
    eq(2, Settings::int('max_events_per_day'));
    return eq(14, Settings::int('default_delivery_days'));
});

test('Seed: Dec 15, 2026 has no confirmed event; Dec 12 has 1 confirmed + 1 pending', function () {
    eq(0, (int) DB::value("SELECT COUNT(*) FROM bookings WHERE event_date = '2026-12-15' AND status = 'confirmed'"));
    eq(1, (int) DB::value("SELECT COUNT(*) FROM bookings WHERE event_date = '2026-12-12' AND status = 'confirmed'"));
    return eq(true, (int) DB::value("SELECT COUNT(*) FROM bookings WHERE event_date = '2026-12-12' AND status = 'pending'") >= 1);
});

test('Auth: owner can everything, staff by flags, crew nothing', function () {
    Auth::forceUser(DB::one("SELECT * FROM users WHERE username = 'owner'"));
    eq(true, Auth::can('verify_payments'));
    Auth::forceUser(DB::one("SELECT * FROM users WHERE username = 'staff'"));
    eq(true, Auth::can('record_payments'));
    eq(false, Auth::can('verify_payments'));
    eq(false, Auth::can('archive_records'));
    Auth::forceUser(DB::one("SELECT * FROM users WHERE username = 'crew1'"));
    eq(false, Auth::can('view_bookings'));
    Auth::forceUser(null);
    return true;
});

test('Auth: seeded passwords verify and must be changed', function () {
    $u = DB::one("SELECT * FROM users WHERE username = 'owner'");
    eq(true, password_verify('Owner@2026', $u['password_hash']));
    return eq(1, (int) $u['must_change_password']);
});

test('RateLimiter: blocks after the limit within the window', function () {
    $key = 'test:' . bin2hex(random_bytes(4));
    for ($i = 1; $i <= 3; $i++) {
        eq(true, RateLimiter::hit($key, 3, 60));
    }
    eq(false, RateLimiter::hit($key, 3, 60));
    RateLimiter::clear($key);
    return eq(false, RateLimiter::tooMany($key, 3, 60));
});


echo "\nPhase 2: booking path\n";

use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\CrewOverlapConflictException;
use App\Services\CrewService;
use App\Services\DateUnavailableException;
use App\Services\UploadException;
use App\Services\UploadService;

test('Down payment: 30% rounded UP to the nearest ₱50', function () {
    eq(4800.0, BookingService::downPayment(16000.0, null, 'percent', 30.0));
    eq(2400.0, BookingService::downPayment(8000.0, null, 'percent', 30.0));
    eq(3000.0, BookingService::downPayment(9999.0, null, 'percent', 30.0));   // 2999.70 -> 3000
    return eq(3050.0, BookingService::downPayment(10100.0, null, 'percent', 30.0)); // 3030 -> 3050
});

test('Down payment: fixed mode, package override, never above total', function () {
    eq(1000.0, BookingService::downPayment(16000.0, null, 'fixed', 1000.0));
    eq(500.0, BookingService::downPayment(16000.0, 500.0, 'percent', 30.0));
    return eq(800.0, BookingService::downPayment(800.0, null, 'fixed', 5000.0));
});

test('Availability: Dec 15 open, Dec 25 blocked, past closed (demo date Dec 12)', function () {
    Clock::setToday('2026-12-12');
    eq(null, AvailabilityService::reason('2026-12-15'));
    eq('blocked', AvailabilityService::reason('2026-12-25'));
    eq('past', AvailabilityService::reason('2026-12-11'));
    eq('invalid', AvailabilityService::reason('2026-13-40'));
    eq(null, AvailabilityService::reason('2026-12-12'));   // today, 1 confirmed < max 2
    Clock::setToday(null);
    return true;
});

test('Availability: pending requests never count (BR-1)', function () {
    Clock::setToday('2026-12-12');
    eq(1, AvailabilityService::confirmedCount('2026-12-12'));
    eq(true, AvailabilityService::pendingCount('2026-12-12') >= 1);
    eq(null, AvailabilityService::reason('2026-12-12'));
    Clock::setToday(null);
    return true;
});

test('Availability: a date with max confirmed events is full; excluding a booking reopens it', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE reference_code = 'DPS-2026-0006'"); // Reyes -> 2nd event
        eq('full', AvailabilityService::reason('2026-12-12'));
        $santos = (int) DB::value("SELECT id FROM bookings WHERE reference_code = 'DPS-2026-0004'");
        eq(null, AvailabilityService::reason('2026-12-12', $santos));  // rescheduling Santos away from a full day is allowed
        $threw = false;
        try {
            AvailabilityService::lockAndAssertOpen('2026-12-12');
        } catch (DateUnavailableException $e) {
            $threw = true;
            eq('This date just became full. Reschedule or reject.', $e->getMessage());
        }
        eq(true, $threw);
        $map = AvailabilityService::monthMap(2026, 12);
        eq('full', $map['2026-12-12']);
        eq(null, $map['2026-12-15']);
        eq('blocked', $map['2026-12-25']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

test('Race safety (T8): a second connection must WAIT for the date lock', function () {
    Clock::setToday('2026-12-15');
    $a = DB::pdo();
    $a->beginTransaction();
    try {
        AvailabilityService::lockAndAssertOpen('2026-12-15');          // connection A holds the lock
        $c = Config::get('db');
        $b = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $b->exec('SET innodb_lock_wait_timeout = 1');
        $b->beginTransaction();
        $blocked = false;
        try {
            $b->query("SELECT `date` FROM date_locks WHERE `date` = '2026-12-15' FOR UPDATE")->fetchAll();
        } catch (PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
        }
        $b->rollBack();
        eq(true, $blocked);
    } finally {
        $a->rollBack();
        Clock::setToday(null);
    }
    return true;
});

test('Reference numbers are sequential per year and unique', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $a = BookingService::nextNumber('booking', 2099);
        $b = BookingService::nextNumber('booking', 2099);
        eq(1, $a);
        eq(2, $b);
        eq(1, BookingService::nextNumber('receipt', 2099));   // separate counter per name
    } finally {
        $pdo->rollBack();
    }
    return true;
});

test('Reference format DPS-YYYY-NNNN follows the demo year', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $ref = BookingService::nextReference();
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return eq(1, preg_match('/^DPS-2026-\d{4}$/', $ref));
});

test('Upload: rejects a PHP file disguised as .png and accepts a real PNG', function () {
    $fake = tempnam(sys_get_temp_dir(), 'dps');
    file_put_contents($fake, '<?php echo 1;');
    $rejected = false;
    try {
        UploadService::saveImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $fake, 'size' => 13, 'name' => 'x.png'], 'receipts', true);
    } catch (UploadException $e) {
        $rejected = true;
    }
    unlink($fake);
    eq(true, $rejected);

    $sample = storage_path('receipts/sample-reyes.png');
    [$path, $thumb] = UploadService::saveImage(['error' => UPLOAD_ERR_OK, 'tmp_name' => $sample, 'size' => filesize($sample), 'name' => 'a.png'], 'receipts', true);
    eq(true, is_file(storage_path($path)));
    eq(true, (bool) preg_match('#^receipts/[a-f0-9]{32}\.(jpg|png)$#', $path));   // random name, no user input
    UploadService::delete($path, $thumb);
    return eq(false, is_file(storage_path($path)));
});

test('Upload: no file / oversized are refused with friendly messages', function () {
    $msg = '';
    try {
        UploadService::saveImage(['error' => UPLOAD_ERR_NO_FILE], 'receipts', true);
    } catch (UploadException $e) {
        $msg = $e->getMessage();
    }
    eq('Please choose a screenshot to upload.', $msg);
    try {
        UploadService::saveImage(['error' => UPLOAD_ERR_INI_SIZE], 'receipts', true);
    } catch (UploadException $e) {
        $msg = $e->getMessage();
    }
    return eq(true, str_contains($msg, 'too large'));
});

echo "\nPhase 3: payments and confirmation\n";

use App\Models\Booking;
use App\Services\DocumentService;
use App\Services\DuplicateReferenceException;
use App\Services\PaymentException;
use App\Services\PaymentService;

test('T9 Duplicate GCash reference: payment #9 (Mendoza) flags payment #7 (Garcia) and blocks without ack', function () {
    $mendozaPayment = DB::one('SELECT * FROM payments WHERE id = 9');
    $dups = PaymentService::duplicatesOf($mendozaPayment);
    eq(1, count($dups));
    eq('DPS-2026-0005', $dups[0]['reference_code']);

    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $threw = false;
        try {
            PaymentService::verify(9, 1, false);
        } catch (DuplicateReferenceException $e) {
            $threw = true;
        }
        eq(true, $threw);

        // With acknowledgement = true, verification succeeds and records duplicate_ack_by
        $bookingId = PaymentService::verify(9, 1, true);
        eq(7, $bookingId);
        $updated = DB::one('SELECT * FROM payments WHERE id = 9');
        eq('verified', $updated['status']);
        eq(1, (int) $updated['duplicate_ack_by']);
        eq(1, preg_match('/^OR-2026-\d{4}$/', (string) $updated['receipt_no']));
    } finally {
        $pdo->rollBack();
    }
    return true;
});

test('T2 Verify payment: confirms Reyes booking (#6), creates project, issues OR number, posts system message', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $bookingId = PaymentService::verify(8, 1, false);
        eq(6, $bookingId);

        $b = Booking::findById(6);
        eq('confirmed', $b['status']);

        $p = DB::one('SELECT * FROM payments WHERE id = 8');
        eq('verified', $p['status']);
        eq('OR-2026-0008', $p['receipt_no']);

        $proj = Booking::project(6);
        eq('booked', $proj['stage']);
        eq('2026-12-26', $proj['due_date']); // Dec 12 + 14 days

        $lastMsg = DB::one('SELECT * FROM messages WHERE conversation_id = 2 ORDER BY id DESC LIMIT 1');
        eq('system', $lastMsg['sender_type']);
        eq(true, str_contains($lastMsg['body'], 'OR-2026-0008'));
    } finally {
        $pdo->rollBack();
    }
    return true;
});

test('T8 Race safety on verify: 3rd booking on Dec 12 throws DateUnavailableException when date is full', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Confirm Reyes (#6) so Dec 12 now has 2 confirmed events (Santos + Reyes = max 2)
        PaymentService::verify(8, 1, false);
        eq(2, AvailabilityService::confirmedCount('2026-12-12'));

        // Move Mendoza (#7) to Dec 12 while still pending, then try to verify payment #9
        $pdo->exec("UPDATE bookings SET event_date = '2026-12-12' WHERE id = 7");
        $threw = false;
        try {
            PaymentService::verify(9, 1, true);
        } catch (DateUnavailableException $e) {
            $threw = true;
        }
        eq(true, $threw);
    } finally {
        $pdo->rollBack();
    }
    return true;
});

test('Reject payment: marks payment rejected, keeps booking pending, posts system message with reason', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $bookingId = PaymentService::reject(8, 1, 'Screenshot is unclear or unreadable');
        eq(6, $bookingId);
        $p = DB::one('SELECT * FROM payments WHERE id = 8');
        eq('rejected', $p['status']);
        eq('Screenshot is unclear or unreadable', $p['rejection_reason']);
        $b = Booking::findById(6);
        eq('pending', $b['status']);
    } finally {
        $pdo->rollBack();
    }
    return true;
});

test('T6 Record balance payment: Santos (#4) balance ₱11,200 -> ₱0.00, issues OR number, blocks overpayment', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $santos = Booking::findById(4);
        eq(11200.0, Booking::balance($santos));

        // Overpayment (> 11,200) must be blocked
        $overThrew = false;
        try {
            PaymentService::record(4, 1, 'balance', 'cash', 12000.0, null, null);
        } catch (PaymentException $e) {
            $overThrew = true;
        }
        eq(true, $overThrew);

        // Exact balance payment succeeds
        $payId = PaymentService::record(4, 1, 'balance', 'cash', 11200.0, null, 'Paid in full');
        $santosAfter = Booking::findById(4);
        eq(0.0, Booking::balance($santosAfter));

        $doc = DocumentService::forBooking(4);
        $receipt = DocumentService::receipt($doc, $payId);
        eq(11200.0, (float) $receipt['amount']);
        eq(0.0, (float) $receipt['balance_after']);
        eq(1, preg_match('/^OR-2026-\d{4}$/', (string) $receipt['receipt_no']));
        eq(true, str_contains(DocumentService::messengerText($doc), 'DPS-2026-0004'));
    } finally {
        $pdo->rollBack();
    }
    return true;
});

echo "\nPhase 4 (Milestone 1): booking management and changes\n";

use App\Controllers\AdminBookingController;
use App\Controllers\ApiAvailabilityController;
use App\Services\ValidationException;

// 1. Direct booking with down payment
test('A5 Direct booking with down payment: confirms booking, creates project, issues OR, records verified payment', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $data = [
            'client_name'           => 'Juan Dela Cruz',
            'mobile'                => '0918 123 4567',
            'messenger_name'        => 'Juan DC',
            'event_type'            => 'Wedding',
            'event_date'            => '2026-12-15',
            'start_time'            => '09:00:00',
            'end_time'              => '17:00:00',
            'venue'                 => 'Batangas Country Club',
            'package_id'            => 1, // Photography Package (₱8,000)
            'addons'                => [1], // Extra hour (₱1,500) -> total ₱9,500
            'source_channel'        => 'walk_in',
            'down_payment_received' => true,
            'payment_amount'        => 2850.0, // 30% of 9,500 = 2,850
            'payment_method'        => 'cash',
            'payment_ref'           => null,
            'payment_notes'         => 'Paid in cash at studio front desk',
        ];

        $res = BookingService::createDirect($data, 1);

        // 1. Booking assertions
        eq(true, is_array($res['booking']));
        $bookingId = (int) $res['booking']['id'];
        eq('confirmed', $res['booking']['status']);
        eq('2026-12-15', $res['booking']['event_date']);
        eq('walk_in', $res['booking']['source_channel']);
        eq(9500.0, (float) $res['booking']['total_amount']);
        eq(1, preg_match('/^DPS-2026-\d{4}$/', (string) $res['booking']['reference_code']));
        eq(true, !empty($res['booking']['confirmed_at']));

        // 2. Verified payment assertions
        eq(true, is_array($res['payment']));
        $payment = $res['payment'];
        eq($bookingId, (int) $payment['booking_id']);
        eq('down', $payment['type']);
        eq('cash', $payment['method']);
        eq('verified', $payment['status']);
        eq(2850.0, (float) $payment['amount']);
        eq(1, preg_match('/^OR-2026-\d{4}$/', (string) $payment['receipt_no']));
        eq(1, (int) $payment['verified_by']);

        // 3. Project assertions (BR-7: due date = event_date + default delivery days)
        eq(true, is_array($res['project']));
        $project = $res['project'];
        eq($bookingId, (int) $project['booking_id']);
        eq('booked', $project['stage']);
        eq('2026-12-29', $project['due_date']); // 2026-12-15 + 14 days

        // 4. Date capacity assertion: Dec 15 now has 1 confirmed event
        eq(1, AvailabilityService::confirmedCount('2026-12-15'));

        // 5. Change timeline and audit log
        $change = DB::one('SELECT * FROM booking_changes WHERE booking_id = ? AND change_type = "created"', [$bookingId]);
        eq(true, $change !== null);
        eq('confirmed', $change['new_value']);

        // 6. In-app conversation & system message
        $conv = DB::one('SELECT * FROM conversations WHERE booking_id = ?', [$bookingId]);
        eq(true, $conv !== null);
        $msg = DB::one('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [$conv['id']]);
        eq(true, str_contains($msg['body'], (string) $payment['receipt_no']));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 2. Direct booking without down payment
test('A5 Direct booking without down payment: creates pending booking, no payment, no project, date remains open', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $data = [
            'client_name'           => 'Pedro Penduko',
            'mobile'                => '0918 765 4321',
            'messenger_name'        => 'Pedro P',
            'event_type'            => 'Birthday',
            'event_date'            => '2026-12-16',
            'start_time'            => '14:00:00',
            'end_time'              => '18:00:00',
            'venue'                 => 'Pedro Residence',
            'package_id'            => 1, // 8000
            'addons'                => [],
            'source_channel'        => 'call',
            'down_payment_received' => false,
        ];

        $res = BookingService::createDirect($data, 1);
        $bookingId = (int) $res['booking']['id'];

        eq('pending', $res['booking']['status']);
        eq(null, $res['payment']);
        eq(null, $res['project']);

        // No verified payment and no project created
        eq(0, (int) DB::value('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$bookingId]));
        eq(0, (int) DB::value('SELECT COUNT(*) FROM projects WHERE booking_id = ?', [$bookingId]));

        // Date capacity (BR-1: pending bookings never count against date capacity)
        eq(0, AvailabilityService::confirmedCount('2026-12-16'));
        eq(1, AvailabilityService::pendingCount('2026-12-16'));
        eq(null, AvailabilityService::reason('2026-12-16')); // date is open
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 3. T3 Reschedule to open date
test('T3 Reschedule: open date succeeds, updates date and project due date, logs change, sends chat message', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Santos (#4) is confirmed on 2026-12-12 with project due on 2026-12-26
        $santosBefore = Booking::findById(4);
        eq('2026-12-12', $santosBefore['event_date']);
        $projBefore = Booking::project(4);
        eq('2026-12-26', $projBefore['due_date']);

        // Reschedule to open date 2026-12-15
        BookingService::reschedule(4, '2026-12-15', '10:00:00', '18:00:00', 'New Seaside Venue, Nasugbu', 'Client requested date change due to family travel', 1);

        // 1. Booking record updated
        $santosAfter = Booking::findById(4);
        eq('2026-12-15', $santosAfter['event_date']);
        eq('10:00:00', $santosAfter['start_time']);
        eq('18:00:00', $santosAfter['end_time']);
        eq('New Seaside Venue, Nasugbu', $santosAfter['venue']);
        eq('confirmed', $santosAfter['status']);

        // 2. Project due date shifted (2026-12-15 + 14 days = 2026-12-29)
        $projAfter = Booking::project(4);
        eq('2026-12-29', $projAfter['due_date']);

        // 3. Booking changes log recorded
        $change = DB::one('SELECT * FROM booking_changes WHERE booking_id = 4 AND change_type = "reschedule" ORDER BY id DESC LIMIT 1');
        eq(true, $change !== null);
        eq('2026-12-12', $change['old_value']);
        eq('2026-12-15', $change['new_value']);
        eq('Client requested date change due to family travel', $change['reason']);
        eq(1, (int) $change['changed_by']);

        // 4. Chat system message posted
        $lastMsg = DB::one('SELECT * FROM messages WHERE conversation_id = (SELECT id FROM conversations WHERE booking_id = 4) ORDER BY id DESC LIMIT 1');
        eq('system', $lastMsg['sender_type']);
        eq(true, str_contains($lastMsg['body'], '2026-12-15') || str_contains($lastMsg['body'], 'rescheduled'));

        // 5. Date capacities shifted
        eq(0, AvailabilityService::confirmedCount('2026-12-12'));
        eq(1, AvailabilityService::confirmedCount('2026-12-15'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 4. T3 Reschedule to blocked date fails
test('T3 Reschedule: blocked date (Dec 25) fails with DateUnavailableException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $threw = false;
        $msg = '';
        try {
            BookingService::reschedule(4, '2026-12-25', null, null, null, 'Change to Christmas', 1);
        } catch (DateUnavailableException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        eq(true, $threw);
        eq('This date is blocked by the studio.', $msg);

        // Booking and project remain unchanged
        $santos = Booking::findById(4);
        eq('2026-12-12', $santos['event_date']);
        $proj = Booking::project(4);
        eq('2026-12-26', $proj['due_date']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 5. T3 Reschedule to full date fails
test('T3 Reschedule: full date (2 confirmed events) fails with DateUnavailableException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Garcia (#5) is confirmed on 2026-12-19. Confirm a second booking (#3) on 2026-12-19 to fill the date (max = 2)
        $pdo->exec("UPDATE bookings SET event_date = '2026-12-19', status = 'confirmed' WHERE id = 3");
        eq(2, AvailabilityService::confirmedCount('2026-12-19'));
        eq('full', AvailabilityService::reason('2026-12-19'));

        // Attempt to reschedule Santos (#4) to the full date
        $threw = false;
        $msg = '';
        try {
            BookingService::reschedule(4, '2026-12-19', null, null, null, 'Try moving to full date', 1);
        } catch (DateUnavailableException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        eq(true, $threw);
        eq('This date just became full. Reschedule or reject.', $msg);

        // Booking remains on original date
        $santos = Booking::findById(4);
        eq('2026-12-12', $santos['event_date']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 6. T3 Reschedule to past date fails
test('T3 Reschedule: past date fails with DateUnavailableException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $threw = false;
        $msg = '';
        try {
            BookingService::reschedule(4, '2026-12-11', null, null, null, 'Reschedule to yesterday', 1);
        } catch (DateUnavailableException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        eq(true, $threw);
        eq('This date is in the past.', $msg);

        // Booking remains on original date
        $santos = Booking::findById(4);
        eq('2026-12-12', $santos['event_date']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 7. T10 Cancellation & down payment forfeiture
test('T10 Cancellation: marks status cancelled, verified down payment forfeited (is_forfeited = 1), frees date', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Santos (#4) is confirmed on 2026-12-12 with verified down payment #6
        eq(1, AvailabilityService::confirmedCount('2026-12-12'));
        $payBefore = DB::one('SELECT * FROM payments WHERE id = 6');
        eq('verified', $payBefore['status']);
        eq(0, (int) $payBefore['is_forfeited']);

        // Cancel booking #4
        BookingService::cancel(4, 'Client decided to cancel wedding; understands no refund', 1);

        // 1. Booking marked cancelled
        $santosAfter = Booking::findById(4);
        eq('cancelled', $santosAfter['status']);
        eq(true, !empty($santosAfter['cancelled_at']));

        // 2. Verified down payment forfeited (is_forfeited = 1, BR-2)
        $payAfter = DB::one('SELECT * FROM payments WHERE id = 6');
        eq(1, (int) $payAfter['is_forfeited']);
        eq('verified', $payAfter['status']); // remains verified

        // 3. Date capacity freed: Dec 12 now has 0 confirmed events
        eq(0, AvailabilityService::confirmedCount('2026-12-12'));

        // 4. Change logged in booking_changes
        $change = DB::one('SELECT * FROM booking_changes WHERE booking_id = 4 AND change_type = "cancel" ORDER BY id DESC LIMIT 1');
        eq(true, $change !== null);
        eq('confirmed', $change['old_value']);
        eq('cancelled', $change['new_value']);
        eq('Client decided to cancel wedding; understands no refund', $change['reason']);
        eq(1, (int) $change['changed_by']);

        // 5. System message posted to chat
        $lastMsg = DB::one('SELECT * FROM messages WHERE conversation_id = (SELECT id FROM conversations WHERE booking_id = 4) ORDER BY id DESC LIMIT 1');
        eq('system', $lastMsg['sender_type']);
        eq(true, str_contains($lastMsg['body'], 'cancelled') || str_contains($lastMsg['body'], 'forfeited'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 8. Internal notes persistence & client isolation
test('A6 Internal notes: persists record in booking_notes and logs booking_changes, isolated from client', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // 1. Add note visible to crew
        $noteId1 = BookingService::addNote(4, 'Important: Bride requests extra coverage for grandmother arrival at 11:30 AM', true, 1);
        eq(true, $noteId1 > 0);

        $note1 = DB::one('SELECT * FROM booking_notes WHERE id = ?', [$noteId1]);
        eq(4, (int) $note1['booking_id']);
        eq('Important: Bride requests extra coverage for grandmother arrival at 11:30 AM', $note1['body']);
        eq(1, (int) $note1['visible_to_crew']);
        eq(1, (int) $note1['created_by']);

        // Verify booking_changes recorded
        $change1 = DB::one('SELECT * FROM booking_changes WHERE booking_id = 4 AND change_type = "note" ORDER BY id DESC LIMIT 1');
        eq(true, $change1 !== null);
        eq(1, (int) $change1['changed_by']);

        // 2. Add confidential staff-only note (visible_to_crew = false)
        $noteId2 = BookingService::addNote(4, 'Staff note: Client requested discount earlier; declined per policy', false, 2);
        eq(true, $noteId2 > 0);

        $note2 = DB::one('SELECT * FROM booking_notes WHERE id = ?', [$noteId2]);
        eq(0, (int) $note2['visible_to_crew']);
        eq(2, (int) $note2['created_by']);

        // 3. Client Isolation: Internal notes are NOT in messages table (client chat)
        $clientMessages = DB::all('SELECT * FROM messages WHERE conversation_id = (SELECT id FROM conversations WHERE booking_id = 4)');
        foreach ($clientMessages as $m) {
            eq(false, str_contains($m['body'], 'grandmother arrival'));
            eq(false, str_contains($m['body'], 'discount earlier'));
        }
    } finally {
        $pdo->rollBack();
    }
    return true;
});

// 9. API availability check JSON payload
test('API availability check: validates date parameter and returns JSON status and counts', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Test open date (2026-12-15)
        $openData = ApiAvailabilityController::status('2026-12-15');
        eq(true, $openData['available']);
        eq(null, $openData['reason']);
        eq(0, (int) $openData['confirmed']);
        eq(2, (int) $openData['max']);

        // Verify JSON round-trip
        $openJson = json_decode((string) json_encode($openData), true);
        eq(true, $openJson['available']);
        eq(null, $openJson['reason']);

        // Test blocked date (2026-12-25)
        $blockedData = ApiAvailabilityController::status('2026-12-25');
        eq(false, $blockedData['available']);
        eq('blocked', $blockedData['reason']);
        eq('This date is blocked by the studio.', $blockedData['message']);

        // Test past date (2026-12-11)
        $pastData = ApiAvailabilityController::status('2026-12-11');
        eq(false, $pastData['available']);
        eq('past', $pastData['reason']);
        eq('This date is in the past.', $pastData['message']);

        // Test full date (make 2026-12-12 full with 2 confirmed events)
        $pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE reference_code = 'DPS-2026-0006'");
        $fullData = ApiAvailabilityController::status('2026-12-12');
        eq(false, $fullData['available']);
        eq('full', $fullData['reason']);
        eq(2, (int) $fullData['confirmed']);
        eq('This date just became full. Reschedule or reject.', $fullData['message']);

        // Test exclusion parameter: rescheduling booking 4 away from full date
        $excludedData = ApiAvailabilityController::status('2026-12-12', 4);
        eq(true, $excludedData['available']); // Santos excluded -> only 1 confirmed remains, so available
        eq(null, $excludedData['reason']);
        eq(1, (int) $excludedData['confirmed']);

        // Test invalid date format
        $invalidData = ApiAvailabilityController::status('not-a-date');
        eq(false, $invalidData['available']);
        eq('invalid', $invalidData['reason']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 10. Completed booking cancellation invariant
test('T10 Cancellation: completed booking cannot be cancelled and throws RuntimeException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Mark booking #4 as completed
        $pdo->exec("UPDATE bookings SET status = 'completed', completed_at = '2026-12-12 18:00:00' WHERE id = 4");

        $threw = false;
        $msg = '';
        try {
            BookingService::cancel(4, 'Attempting to cancel completed booking', 1);
        } catch (RuntimeException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        eq(true, $threw);
        eq('Completed bookings cannot be cancelled.', $msg);

        // Verify status remained 'completed' and not changed to 'cancelled'
        $bAfter = Booking::findById(4);
        eq('completed', $bAfter['status']);
        eq(null, $bAfter['cancelled_at']);

        // Verify down payment was not marked as forfeited
        $pay = DB::one('SELECT * FROM payments WHERE booking_id = 4 AND type = "down"');
        eq(0, (int) $pay['is_forfeited']);

        // Verify no cancellation record exists in booking_changes
        $cancelChanges = (int) DB::value('SELECT COUNT(*) FROM booking_changes WHERE booking_id = 4 AND change_type = "cancel"');
        eq(0, $cancelChanges);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 11. Completed booking reschedule invariant
test('T3 Reschedule: completed booking cannot be rescheduled and throws RuntimeException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // 1. Test explicitly marked completed booking #4
        $pdo->exec("UPDATE bookings SET status = 'completed', completed_at = '2026-12-12 18:00:00' WHERE id = 4");

        $threw = false;
        $msg = '';
        try {
            BookingService::reschedule(4, '2026-12-15', null, null, null, 'Attempting to reschedule completed event', 1);
        } catch (RuntimeException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        eq(true, $threw);
        eq('Completed bookings cannot be rescheduled.', $msg);

        // Verify booking status and date remain unchanged
        $bAfter = Booking::findById(4);
        eq('completed', $bAfter['status']);
        eq('2026-12-12', $bAfter['event_date']);

        // 2. Also test on naturally completed seed booking #1 (DPS-2026-0001)
        $threwSeed = false;
        $msgSeed = '';
        try {
            BookingService::reschedule(1, '2026-12-15', null, null, null, 'Reschedule seed completed booking', 1);
        } catch (RuntimeException $e) {
            $threwSeed = true;
            $msgSeed = $e->getMessage();
        }
        eq(true, $threwSeed);
        eq('Completed bookings cannot be rescheduled.', $msgSeed);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 12. Screen A5 direct booking authorization check
test('Screen A5 Direct booking: down payment requires record_payments permission', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $postData = [
            'source_channel'        => 'walk_in',
            'full_name'             => 'Maria Clara',
            'mobile'                => '0917 111 2222',
            'messenger_name'        => 'Maria C',
            'event_type'            => 'Portrait',
            'package_id'            => 1,
            'event_date'            => '2026-12-18',
            'start_time'            => '10:00',
            'end_time'              => '12:00',
            'venue'                 => 'Studio Intramuros',
            'down_payment_received' => '1',
            'payment_amount'        => '2400.00',
            'payment_method'        => 'cash',
        ];

        // 1. Staff user WITHOUT record_payments permission is rejected
        $staffWithoutPay = DB::one("SELECT * FROM users WHERE username = 'staff'");
        $staffWithoutPay['perm_record_payments'] = 0;
        Auth::forceUser($staffWithoutPay);
        eq(false, Auth::can('record_payments'));

        $errorsUnauthorized = AdminBookingController::validateNewBooking($postData);
        eq(true, isset($errorsUnauthorized['payment_amount']) || isset($errorsUnauthorized['down_payment_received']));
        eq('You do not have permission to record payments.', $errorsUnauthorized['payment_amount'] ?? $errorsUnauthorized['down_payment_received']);

        // 2. Staff user WITH record_payments permission passes validation
        $staffWithPay = DB::one("SELECT * FROM users WHERE username = 'staff'");
        $staffWithPay['perm_record_payments'] = 1;
        Auth::forceUser($staffWithPay);
        eq(true, Auth::can('record_payments'));

        $errorsAuthorized = AdminBookingController::validateNewBooking($postData);
        eq(false, isset($errorsAuthorized['payment_amount']));
        eq(false, isset($errorsAuthorized['down_payment_received']));

        // 3. Owner user passes validation
        $owner = DB::one("SELECT * FROM users WHERE username = 'owner'");
        Auth::forceUser($owner);
        $errorsOwner = AdminBookingController::validateNewBooking($postData);
        eq(false, isset($errorsOwner['payment_amount']));
        eq(false, isset($errorsOwner['down_payment_received']));
    } finally {
        Auth::forceUser(null);
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 13. Screen A13 cancellation confirm_forfeit enforcement
test('Screen A13 Cancellation: requires confirm_forfeit checkbox confirmation', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $owner = DB::one("SELECT * FROM users WHERE username = 'owner'");
        Auth::forceUser($owner);

        // Santos (#4) is confirmed on 2026-12-12
        $santos = Booking::findById(4);
        eq('confirmed', $santos['status']);

        // 1. Submit cancel without confirm_forfeit
        $unconfirmedPost = [
            'action' => 'cancel',
            'reason' => 'Client requested cancellation due to personal emergency',
            // confirm_forfeit omitted / empty
        ];

        $errorsUnconfirmed = AdminBookingController::validateCancel($unconfirmedPost);
        eq(true, isset($errorsUnconfirmed['confirm_forfeit']));
        eq('Please confirm that the client understands the down payment is forfeited without refund.', $errorsUnconfirmed['confirm_forfeit']);

        // Verify booking #4 status remains 'confirmed'
        $santosAfter = Booking::findById(4);
        eq('confirmed', $santosAfter['status']);

        // 2. Submit cancel WITH confirm_forfeit = '1'
        $confirmedPost = [
            'action'          => 'cancel',
            'reason'          => 'Client requested cancellation due to personal emergency',
            'confirm_forfeit' => '1',
        ];

        $errorsConfirmed = AdminBookingController::validateCancel($confirmedPost);
        eq(false, isset($errorsConfirmed['confirm_forfeit']));
        eq([], $errorsConfirmed);

        // 3. Submit cancel with empty reason even if confirm_forfeit is ticked
        $emptyReasonPost = [
            'action'          => 'cancel',
            'reason'          => '   ',
            'confirm_forfeit' => '1',
        ];
        $errorsEmptyReason = AdminBookingController::validateCancel($emptyReasonPost);
        eq(true, isset($errorsEmptyReason['reason']));
        eq('Please provide a reason for cancellation.', $errorsEmptyReason['reason']);
    } finally {
        Auth::forceUser(null);
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

echo "\nPhase 4 (Milestone 2): crew and equipment assignment (T4)\n";

// 1. Dec 12 initial shortage check
test('T4 Crew: Dec 12 flags crew shortage on multi-event day', function () {
    Clock::setToday('2026-12-12');
    $schedule = CrewService::getScheduleForDate('2026-12-12');
    eq(2, count($schedule['events']));
    eq(true, $schedule['has_shortage']);
    eq(true, str_contains((string) $schedule['shortage_message'], 'Liza Reyes'));

    $reyesEvent = null;
    foreach ($schedule['events'] as $ev) {
        if ((int) $ev['id'] === 6) {
            $reyesEvent = $ev;
            break;
        }
    }
    eq(true, $reyesEvent !== null);
    eq(0, count($reyesEvent['crew']));
    Clock::setToday(null);
    return true;
});

// 2. Overlapping assignment is blocked with CrewOverlapConflictException
test('T4 Crew: assigning crew to overlapping event hours is blocked with CrewOverlapConflictException', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Attempt assigning crew member 1 (Owner) to Reyes (#6, 14:00-18:00)
        // Owner is already assigned to Santos (#4, 09:00-17:00) -> Overlap!
        $threwOwner = false;
        try {
            CrewService::assign(6, 1, 'lead', '13:00', 1);
        } catch (CrewOverlapConflictException $e) {
            $threwOwner = true;
            eq(true, str_contains($e->getMessage(), 'overlapping hours'));
        }
        eq(true, $threwOwner);

        // Attempt assigning crew member 3 (Videographer A) to Reyes (#6) -> Overlap!
        $threwVideo = false;
        try {
            CrewService::assign(6, 3, 'backup', null, 1);
        } catch (CrewOverlapConflictException $e) {
            $threwVideo = true;
            eq(true, str_contains($e->getMessage(), 'overlapping hours'));
        }
        eq(true, $threwVideo);

        // Verify zero assignments were inserted for Reyes
        $assignments = DB::all('SELECT * FROM crew_assignments WHERE booking_id = 6');
        eq(0, count($assignments));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 3. Assign on-call photographer to clear shortage
test('T4 Crew: assigning on-call photographer saves assignment, clears shortage warning, and writes logs', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Assign on-call Photographer A (crew member 2) to Reyes (#6)
        CrewService::assign(6, 2, 'lead', '13:00:00', 1);

        // Verify assignment persisted
        $assignRow = DB::one('SELECT * FROM crew_assignments WHERE booking_id = 6 AND crew_member_id = 2');
        eq(true, $assignRow !== null);
        eq('lead', $assignRow['role']);
        eq('13:00:00', $assignRow['call_time']);

        // Verify schedule re-evaluation clears shortage
        $schedule = CrewService::getScheduleForDate('2026-12-12');
        eq(false, $schedule['has_shortage']);
        eq(null, $schedule['shortage_message']);

        // Verify audit log entry
        $audit = DB::one("SELECT * FROM audit_logs WHERE action = 'crew_assigned' AND entity_id = 6 ORDER BY id DESC LIMIT 1");
        eq(true, $audit !== null);
        eq('booking', $audit['entity']);
        eq(6, (int) $audit['entity_id']);

        // Verify booking_changes note entry
        $change = DB::one("SELECT * FROM booking_changes WHERE booking_id = 6 AND change_type = 'note' ORDER BY id DESC LIMIT 1");
        eq(true, $change !== null);
        eq(true, str_contains((string) $change['new_value'], 'Photographer A'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 4. Same-day non-overlapping multi-event assignment allowed with advisory warning
test('T4 Crew: same-day non-overlapping events allow assignment with same-day alert tag', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Create two non-overlapping bookings on 2026-12-18
        $bid1 = DB::insert('bookings', [
            'reference_code'   => 'DPS-TEST-0001',
            'client_id'        => 1,
            'package_id'       => 1,
            'event_type'       => 'Morning Shoot',
            'event_date'       => '2026-12-18',
            'start_time'       => '08:00:00',
            'end_time'         => '11:00:00',
            'venue'            => 'Studio',
            'status'           => 'confirmed',
            'package_price'    => 8000,
            'total_amount'     => 8000,
            'down_payment_due' => 2400,
            'terms_version'    => '1',
            'created_at'       => Clock::now(),
        ]);
        $bid2 = DB::insert('bookings', [
            'reference_code'   => 'DPS-TEST-0002',
            'client_id'        => 2,
            'package_id'       => 1,
            'event_type'       => 'Afternoon Shoot',
            'event_date'       => '2026-12-18',
            'start_time'       => '14:00:00',
            'end_time'         => '17:00:00',
            'venue'            => 'Park',
            'status'           => 'confirmed',
            'package_price'    => 8000,
            'total_amount'     => 8000,
            'down_payment_due' => 2400,
            'terms_version'    => '1',
            'created_at'       => Clock::now(),
        ]);

        // Assign Crew #2 to both non-overlapping events
        CrewService::assign($bid1, 2, 'lead', '07:00:00', 1);
        CrewService::assign($bid2, 2, 'lead', '13:00:00', 1);

        $schedule = CrewService::getScheduleForDate('2026-12-18');
        eq(false, $schedule['has_shortage']);

        // Check that crew member is flagged with same-day multi-event tag
        $ev1Crew = $schedule['events'][0]['crew'][0];
        eq(true, !empty($ev1Crew['is_same_day_multievent']));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 5. Unassign crew removes assignment and re-triggers shortage
test('T4 Crew: unassigning crew removes record and restores shortage status', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Assign crew #2 to Reyes (#6)
        CrewService::assign(6, 2, 'lead', '13:00:00', 1);
        eq(false, CrewService::getScheduleForDate('2026-12-12')['has_shortage']);

        // Unassign crew #2 from Reyes (#6)
        CrewService::unassign(6, 2, 1);

        $row = DB::one('SELECT * FROM crew_assignments WHERE booking_id = 6 AND crew_member_id = 2');
        eq(null, $row);

        // Shortage is back
        eq(true, CrewService::getScheduleForDate('2026-12-12')['has_shortage']);

        // Verify audit log and note
        $audit = DB::one("SELECT * FROM audit_logs WHERE action = 'crew_unassigned' AND entity_id = 6 ORDER BY id DESC LIMIT 1");
        eq(true, $audit !== null);
        $change = DB::one("SELECT * FROM booking_changes WHERE booking_id = 6 AND change_type = 'note' ORDER BY id DESC LIMIT 1");
        eq(true, $change !== null);
        eq(true, str_contains((string) $change['new_value'], 'removed'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 6. Equipment checklist persistence and audit logging
test('T4 Equipment: saving checklist persists checked items in booking_equipment and writes logs', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Santos (#4) initially has items [1, 2] checked
        // Save items [2, 3, 4]
        CrewService::saveEquipment(4, [2, 3, 4], 1);

        $checked = DB::all('SELECT equipment_item_id FROM booking_equipment WHERE booking_id = 4 AND is_checked = 1 ORDER BY equipment_item_id');
        $checkedIds = array_column($checked, 'equipment_item_id');
        eq([2, 3, 4], array_map('intval', $checkedIds));

        // Unticked item 1 is stored with is_checked = 0
        $item1 = DB::one('SELECT is_checked FROM booking_equipment WHERE booking_id = 4 AND equipment_item_id = 1');
        eq(0, (int) $item1['is_checked']);

        // Audit log and booking change note
        $audit = DB::one("SELECT * FROM audit_logs WHERE action = 'equipment_saved' AND entity_id = 4 ORDER BY id DESC LIMIT 1");
        eq(true, $audit !== null);
        $change = DB::one("SELECT * FROM booking_changes WHERE booking_id = 4 AND change_type = 'note' ORDER BY id DESC LIMIT 1");
        eq(true, $change !== null);
        eq(true, str_contains((string) $change['new_value'], 'Equipment checklist updated'));
    } finally {
        $pdo->rollBack();
    }
    return true;
});

// 7. Route authorization check: crew routes require view_bookings permission
test('T4 Route authorization: /admin/crew requires view_bookings permission or owner', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $staff = DB::one("SELECT * FROM users WHERE username = 'staff'");
        $staff['perm_view_bookings'] = 0;
        Auth::forceUser($staff);

        eq(false, Auth::can('view_bookings'));

        // Owner can access
        $owner = DB::one("SELECT * FROM users WHERE username = 'owner'");
        Auth::forceUser($owner);
        eq(true, Auth::can('view_bookings'));
        eq(true, Auth::isOwner());
    } finally {
        Auth::forceUser(null);
        $pdo->rollBack();
    }
    return true;
});

// 8. Effective call time fallback: 1 hour before start time when omitted
test('A9 Call time: computes 1 hour before event start time when call_time is null', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Reyes starts at 14:00:00; assign with null call_time
        CrewService::assign(6, 2, 'lead', null, 1);
        $schedule = CrewService::getScheduleForDate('2026-12-12');

        $reyes = null;
        foreach ($schedule['events'] as $e) {
            if ((int) $e['id'] === 6) {
                $reyes = $e;
                break;
            }
        }
        eq(true, $reyes !== null);
        $assigned = $reyes['crew'][0];
        eq(null, $assigned['call_time']);
        eq('13:00:00', $assigned['computed_call_time']);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// ---------------------------------------------------------------- Phase 4 (Milestone 3)
echo "\nPhase 4 (Milestone 3): projects, handover and templates (T5)\n";

// 1. Overdue deliverable detection on Screen A10 (Scenario T5)
test('T5 Overdue deliverable detection: Carlo Cruz project is overdue by 2 days on demo date 2026-12-12', function () {
    Clock::setToday('2026-12-12');
    try {
        $project = ProjectService::getById(3);
        eq(true, $project !== null);
        eq('editing', $project['stage']);
        eq('2026-12-10', $project['due_date']);
        eq(true, $project['is_overdue']);
        eq(2, (int) $project['days_overdue']);
        eq(0, (int) $project['days_left']);

        $counts = ProjectService::getStageCounts();
        eq(true, (int) $counts['overdue'] >= 1);
        eq(true, (int) $counts['editing'] >= 2);

        $overdueProjects = ProjectService::getAll(['overdue' => true]);
        $found = false;
        foreach ($overdueProjects as $op) {
            if ((int) $op['id'] === 3) {
                $found = true;
                break;
            }
        }
        eq(true, $found);
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 2. Linear stage progression from editing to ready (Screen A10)
test('T5 Stage progression: linear transition from editing to ready records history and chat notification', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Move Cruz (project #3) to ready
        ProjectService::updateStage(3, 'ready', 1);

        $project = ProjectService::getById(3);
        eq('ready', $project['stage']);

        // Check project_history entry
        $history = DB::one('SELECT * FROM project_history WHERE project_id = 3 AND new_stage = "ready" ORDER BY id DESC LIMIT 1');
        eq(true, $history !== null);
        eq('editing', $history['old_stage']);
        eq('ready', $history['new_stage']);
        eq(1, (int) $history['changed_by']);

        // Check audit log entry
        $audit = DB::one('SELECT * FROM audit_logs WHERE action = "project_stage_changed" AND entity_id = 3 ORDER BY id DESC LIMIT 1');
        eq(true, $audit !== null);

        // Check system notification in client chat
        $chatMsg = DB::one('SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 3 AND m.sender_type = "system" ORDER BY m.id DESC LIMIT 1');
        eq(true, $chatMsg !== null);
        eq(true, str_contains($chatMsg['body'], 'ready'));

        // Invalid stage throws InvalidArgumentException
        $threw = false;
        try {
            ProjectService::updateStage(3, 'non_existent_stage', 1);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        eq(true, $threw);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 3. Editing checklist toggle (Screen A10)
test('A10 Editing checklist: toggling editing, rendering, and album layout persists and logs audit trail', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Set all 3 items to done
        ProjectService::updateChecklist(3, true, true, true, 1);
        $project = ProjectService::getById(3);
        eq(1, (int) $project['editing_done']);
        eq(1, (int) $project['rendering_done']);
        eq(1, (int) $project['album_done']);

        // Verify audit log
        $audit = DB::one('SELECT * FROM audit_logs WHERE action = "project_checklist_updated" AND entity_id = 3 ORDER BY id DESC LIMIT 1');
        eq(true, $audit !== null);

        // Uncheck rendering and album (using 4-parameter contract)
        ProjectService::updateChecklist(3, true, false, false);
        $project = ProjectService::getById(3);
        eq(1, (int) $project['editing_done']);
        eq(0, (int) $project['rendering_done']);
        eq(0, (int) $project['album_done']);
    } finally {
        $pdo->rollBack();
    }
    return true;
});

// 4. Release warnings and override requirement (Screen A11)
test('A11 Release warnings: missing cloud backup and unpaid balance flag warnings and require override confirmation', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Check Cruz warnings: cloud_copy_done = 0 and balance = ₱7,000.00
        $warnings = ProjectService::checkReleaseWarnings(3);
        eq(true, $warnings['has_missing_cloud_copy']);
        eq(true, $warnings['has_unpaid_balance']);
        eq(7000.0, (float) $warnings['balance_amount']);

        // Attempting release without override confirmation throws ValidationException
        $threw = false;
        try {
            ProjectService::saveHandover(3, [
                'raw_folder'               => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW',
                'edited_folder'            => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/EDITED',
                'released_how'             => 'pickup',
                'received_by'              => 'Carlo Cruz',
                'mark_released'            => true,
                'confirm_release_override' => false,
            ], 1);
        } catch (ValidationException $e) {
            $threw = true;
            eq(true, str_contains($e->getMessage(), 'override') || isset($e->getErrors()['confirm_release_override']));
        }
        eq(true, $threw);
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 5. Handover release execution for Scenario T5 (Cruz project)
test('T5 Handover release: Cruz project released with override clears overdue tag and retains confirmed booking status', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Release project #3 with override
        ProjectService::saveHandover(3, [
            'raw_folder'               => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW',
            'edited_folder'            => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/EDITED',
            'local_backup_done'        => 1,
            'cloud_copy_done'          => 1,
            'released_how'             => 'messenger_link',
            'released_note'            => 'Google Drive link sent via Messenger',
            'received_by'              => 'Carlo Cruz',
            'released_at'              => '2026-12-12',
            'mark_released'            => true,
            'confirm_release_override' => true,
        ], 1);

        $project = ProjectService::getById(3);
        eq('released', $project['stage']);
        eq(false, $project['is_overdue']); // Overdue tag clears immediately!
        eq('messenger_link', $project['released_how']);
        eq('Carlo Cruz', $project['received_by']);
        eq('2026-12-12', $project['released_at']);
        eq(1, (int) $project['local_backup_done']);
        eq(1, (int) $project['cloud_copy_done']);

        // Because Carlo Cruz has an unpaid balance of ₱7,000, booking status must remain confirmed (NOT completed)
        $booking = DB::one('SELECT * FROM bookings WHERE id = 3');
        eq('confirmed', $booking['status']);

        // Check project history has released stage
        $history = DB::one('SELECT * FROM project_history WHERE project_id = 3 AND new_stage = "released" ORDER BY id DESC LIMIT 1');
        eq(true, $history !== null);

        // System message informs release and balance due
        $chatMsg = DB::one('SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 3 AND m.sender_type = "system" ORDER BY m.id DESC LIMIT 1');
        eq(true, $chatMsg !== null);
        eq(true, str_contains($chatMsg['body'], 'released'));
        eq(true, str_contains($chatMsg['body'], '7,000'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 6. Booking auto-completion on release for ₱0 balance booking
test('A11 Booking auto-completion: release of fully-paid project completes booking and logs change', function () {
    Clock::setToday('2026-12-12');
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        // Create confirmed booking with 0 balance
        $now = Clock::now();
        $bid = (int) DB::insert('bookings', [
            'reference_code'    => 'DPS-2026-0099',
            'client_id'         => 1,
            'package_id'        => 1,
            'event_type'        => 'Test Debut',
            'event_date'        => '2026-12-01',
            'start_time'        => '10:00:00',
            'end_time'          => '14:00:00',
            'venue'             => 'Studio Test Venue',
            'status'            => 'confirmed',
            'total_amount'      => 5000.00,
            'down_payment_due'  => 1500.00,
            'is_archived'       => 0,
            'confirmed_at'      => $now,
            'created_at'        => $now,
        ]);

        // Insert full payment
        DB::insert('payments', [
            'booking_id'    => $bid,
            'type'          => 'balance',
            'amount'        => 5000.00,
            'method'        => 'cash',
            'status'        => 'verified',
            'receipt_no'    => 'OR-2026-0099',
            'verified_at'   => $now,
            'verified_by'   => 1,
            'created_at'    => $now,
        ]);

        // Insert project in ready stage
        $pid = (int) DB::insert('projects', [
            'booking_id'        => $bid,
            'stage'             => 'ready',
            'due_date'          => '2026-12-10',
            'raw_folder'        => 'D:/RAW',
            'edited_folder'     => 'D:/EDITED',
            'local_backup_done' => 1,
            'cloud_copy_done'   => 1,
            'created_at'        => $now,
        ]);

        // Handover release: balance is ₱0, cloud copy preserved from DB without needing override
        ProjectService::saveHandover($pid, [
            'mark_released' => true,
            'released_how'  => 'pickup',
            'received_by'   => 'Maria Dela Cruz',
        ], 1);

        // Booking status must be completed with completed_at set
        $bUpdated = DB::one('SELECT * FROM bookings WHERE id = ?', [$bid]);
        eq('completed', $bUpdated['status']);
        eq(true, !empty($bUpdated['completed_at']));

        // booking_changes has 'status' change
        $change = DB::one('SELECT * FROM booking_changes WHERE booking_id = ? AND change_type = "status" ORDER BY id DESC LIMIT 1', [$bid]);
        eq(true, $change !== null);
        eq('completed', $change['new_value']);
        eq(true, str_contains($change['reason'], 'fully paid'));

        // System message confirms completion
        $msg = DB::one('SELECT m.* FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = ? AND m.sender_type = "system" ORDER BY m.id DESC LIMIT 1', [$bid]);
        eq(true, $msg !== null);
        eq(true, str_contains($msg['body'], 'completed'));
    } finally {
        $pdo->rollBack();
        Clock::setToday(null);
    }
    return true;
});

// 7. Bilingual message template interpolation and alias resolution (Screen A12)
test('A12 Client message templates: bilingual placeholder substitution and alias normalization', function () {
    // English Output Ready template for Carlo Cruz (booking #3)
    $tpl = MessageTemplateService::getInterpolated('output_ready', 3, 'en');
    eq('output_ready', $tpl['template_key']);
    eq('en', $tpl['language']);
    eq(true, str_contains($tpl['body'], 'Carlo Cruz (Sample)'));
    eq(true, str_contains($tpl['body'], 'Birthday'));
    eq(true, str_contains($tpl['body'], 'Sat, Dec 5, 2026'));
    eq(true, str_contains($tpl['body'], 'Douffer Photography'));
    // No unresolved placeholder left
    eq(false, str_contains($tpl['body'], '[Client name]'));
    eq(false, str_contains($tpl['body'], '[Event]'));

    // Tagalog Balance Reminder template
    $tplTl = MessageTemplateService::getInterpolated('balance_reminder', 3, 'tl');
    eq('balance_reminder', $tplTl['template_key']);
    eq('tl', $tplTl['language']);
    eq(true, str_contains($tplTl['body'], 'Carlo Cruz (Sample)'));
    eq(true, str_contains($tplTl['body'], '₱7,000.00'));
    eq(true, str_contains($tplTl['body'], 'Douffer Photography'));

    // Alias normalization: booking_confirmation -> booking_confirmed
    $tplAlias = MessageTemplateService::getInterpolated('booking_confirmation', 3, 'en');
    eq('booking_confirmed', $tplAlias['template_key']);

    // Invalid template key throws InvalidArgumentException
    $threw = false;
    try {
        MessageTemplateService::getInterpolated('non_existent_key', 3, 'en');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    eq(true, $threw);

    return true;
});

// 8. Message dispatch logging: mark as sent vs send in chat (Screen A12)
test('A12 Client message templates: mark as sent logs to message_log; send in chat posts to messages thread', function () {
    $pdo = DB::pdo();
    $pdo->beginTransaction();
    try {
        $bodyCopy = 'Hi Carlo Cruz! Test message for Messenger copy.';
        $bodyChat = 'Hi Carlo Cruz! Test message for in-app chat thread.';

        // 1. Mark as sent (channel = copy)
        MessageTemplateService::logSent(3, 'output_ready', 'en', $bodyCopy, 'copy', 1);

        $logCopy = DB::one('SELECT * FROM message_log WHERE booking_id = 3 AND channel = "copy" ORDER BY id DESC LIMIT 1');
        eq(true, $logCopy !== null);
        eq('output_ready', $logCopy['template_key']);
        eq('en', $logCopy['language']);
        eq($bodyCopy, $logCopy['body']);

        // Channel 'copy' does NOT post to messages table
        $noChat = DB::one('SELECT * FROM messages WHERE body = ?', [$bodyCopy]);
        eq(null, $noChat);

        // 2. Send in chat (channel = in_app)
        MessageTemplateService::logSent(3, 'output_ready', 'en', $bodyChat, 'in_app', 1);

        $logChat = DB::one('SELECT * FROM message_log WHERE booking_id = 3 AND channel = "in_app" ORDER BY id DESC LIMIT 1');
        eq(true, $logChat !== null);
        eq('output_ready', $logChat['template_key']);

        // Channel 'in_app' posts to messages table with sender_type = 'admin'
        $chat = DB::one('SELECT * FROM messages WHERE body = ?', [$bodyChat]);
        eq(true, $chat !== null);
        eq('admin', $chat['sender_type']);
        eq(1, (int) $chat['sender_user_id']);

        // Dispatch history returns both
        $history = MessageTemplateService::getSentHistory(3);
        eq(true, count($history) >= 2);
    } finally {
        $pdo->rollBack();
    }
    return true;
});

// 9. Route registration and authorization for A10, A11, A12
test('Milestone 3 Route registration and authorization: A10, A11, A12 require proper permissions', function () {
    $router = new Router();
    $routesFn = require dirname(__DIR__) . '/app/routes.php';
    $routesFn($router);

    $ref = new \ReflectionClass($router);
    $prop = $ref->getProperty('routes');
    $prop->setAccessible(true);
    $routes = $prop->getValue($router);

    $findRoute = function (string $method, string $path) use ($routes) {
        foreach ($routes as $r) {
            if ($r['method'] === $method && preg_match($r['regex'], $path)) {
                return $r;
            }
        }
        return null;
    };

    // Verify GET routes match
    $matchA10 = $findRoute('GET', '/admin/projects');
    eq(true, $matchA10 !== null);
    eq('AdminProjectController', substr(strrchr($matchA10['handler'][0], '\\'), 1));
    eq('index', $matchA10['handler'][1]);

    $matchA11 = $findRoute('GET', '/admin/projects/3/files');
    eq(true, $matchA11 !== null);
    eq('AdminProjectController', substr(strrchr($matchA11['handler'][0], '\\'), 1));
    eq('showFiles', $matchA11['handler'][1]);

    $matchA12 = $findRoute('GET', '/admin/bookings/3/messages/templates');
    eq(true, $matchA12 !== null);
    eq('AdminMessageTemplateController', substr(strrchr($matchA12['handler'][0], '\\'), 1));
    eq('index', $matchA12['handler'][1]);

    // Verify POST routes match
    $matchStage = $findRoute('POST', '/admin/projects/3/stage');
    eq(true, $matchStage !== null);
    eq('updateStage', $matchStage['handler'][1]);

    $matchFiles = $findRoute('POST', '/admin/projects/3/files');
    eq(true, $matchFiles !== null);
    eq('saveFiles', $matchFiles['handler'][1]);

    $matchSend = $findRoute('POST', '/admin/bookings/3/messages/templates/send');
    eq(true, $matchSend !== null);
    eq('send', $matchSend['handler'][1]);

    // Verify authorization: staff without view_bookings cannot access
    $staff = DB::one("SELECT * FROM users WHERE username = 'staff'");
    $staff['perm_view_bookings'] = 0;
    Auth::forceUser($staff);
    try {
        eq(false, Auth::can('view_bookings'));
    } finally {
        Auth::forceUser(null);
    }

    return true;
});

// 10. Code Quality: zero BOM and strict CSP compliance
test('Milestone 3 Code Quality: zero BOM in PHP files and strict CSP compliance across admin views', function () {
    $baseDir = dirname(__DIR__);

    // Check BOM in Milestone 3 files
    $filesToCheck = [
        $baseDir . '/app/Services/ProjectService.php',
        $baseDir . '/app/Services/MessageTemplateService.php',
        $baseDir . '/app/Controllers/AdminProjectController.php',
        $baseDir . '/app/Controllers/AdminMessageTemplateController.php',
        $baseDir . '/app/Views/admin/projects.php',
        $baseDir . '/app/Views/admin/project_files.php',
        $baseDir . '/app/Views/admin/messages_templates.php',
        $baseDir . '/app/Views/admin/booking_show.php',
        $baseDir . '/app/routes.php',
        $baseDir . '/tests/run.php',
    ];

    foreach ($filesToCheck as $filePath) {
        eq(true, file_exists($filePath));
        $content = (string) file_get_contents($filePath);
        $hasBom = str_starts_with($content, "\xEF\xBB\xBF");
        eq(false, $hasBom);
    }

    // Check strict CSP on new Milestone 3 views
    $views = [
        $baseDir . '/app/Views/admin/projects.php',
        $baseDir . '/app/Views/admin/project_files.php',
        $baseDir . '/app/Views/admin/messages_templates.php',
    ];

    foreach ($views as $viewPath) {
        $html = (string) file_get_contents($viewPath);

        // No inline <script> tags
        eq(false, (bool) preg_match('/<script\b(?![^>]*type=["\']application\/json["\'])/i', $html));

        // No inline style attributes
        eq(false, (bool) preg_match('/\bstyle\s*=\s*["\']/i', $html));

        // No inline on* event handlers (e.g. onclick, onchange, onsubmit)
        eq(false, (bool) preg_match('/\bon[a-z]+\s*=\s*["\']/i', $html));
    }

    return true;
});

echo "\nPhase 4 (Milestone 4): dashboard and calendar (A2, A3)\n";

// 1. Dashboard Metrics Calculation
test('Milestone 4 Dashboard: getMetrics returns accurate live summary counts', function () {
    Clock::setToday('2026-12-12');
    try {
        $metrics = DashboardService::getMetrics();

        // Events today: 1 confirmed event on Dec 12 (Angela Santos #4)
        eq(1, $metrics['events_today']);

        // Payments to verify: 2 pending payments (Payment #8 for Reyes and Payment #9 for Mendoza)
        eq(2, $metrics['payments_to_verify']);

        // Balances due: 3 confirmed bookings with unpaid balances across all active events (Cruz, Santos, Garcia)
        eq(3, $metrics['balances_due']);

        // Outputs overdue: 1 project overdue on Dec 12 (Carlo Cruz project #3 due Dec 10)
        eq(1, $metrics['outputs_overdue']);

        // Test in transaction when an event is added or payment verified
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Confirm Reyes booking #6 on Dec 12 -> events_today becomes 2
            $pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE id = 6");
            $m2 = DashboardService::getMetrics();
            eq(2, $m2['events_today']);

            // Verify payment #8 -> payments_to_verify decreases from 2 to 1
            $pdo->exec("UPDATE payments SET status = 'verified' WHERE id = 8");
            $m3 = DashboardService::getMetrics();
            eq(1, $m3['payments_to_verify']);

            // Release Cruz project -> outputs_overdue decreases to 0
            $pdo->exec("UPDATE projects SET stage = 'released' WHERE id = 3");
            $m4 = DashboardService::getMetrics();
            eq(0, $m4['outputs_overdue']);
        } finally {
            $pdo->rollBack();
        }
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 2. All 6 "Needs attention" Alert Rules
test('Milestone 4 Dashboard: getAlerts evaluates all 6 studio alert rules and handles empty state', function () {
    Clock::setToday('2026-12-12');
    try {
        $alerts = DashboardService::getAlerts();
        eq(true, is_array($alerts));
        eq(true, count($alerts) >= 5);

        // Find alert types present in seed data
        $types = array_column($alerts, 'type');

        // Rule 1: Unverified payments
        eq(true, in_array('payment_pending', $types, true));
        $payAlert = current(array_filter($alerts, fn($a) => $a['type'] === 'payment_pending'));
        eq('/admin/payments', $payAlert['url']);
        eq('Payments', $payAlert['badge']);

        // Rule 2: Crew shortfall on upcoming dates
        eq(true, in_array('crew_shortfall', $types, true));
        $crewAlert = current(array_filter($alerts, fn($a) => $a['type'] === 'crew_shortfall'));
        eq('Conflict', $crewAlert['badge']);
        eq(true, str_contains($crewAlert['url'], '/admin/crew?date=2026-12-12'));

        // Rule 3: Deliverable outputs overdue
        eq(true, in_array('output_overdue', $types, true));
        $overdueAlert = current(array_filter($alerts, fn($a) => $a['type'] === 'output_overdue'));
        eq('Overdue', $overdueAlert['badge']);
        eq(true, str_contains($overdueAlert['message'], 'overdue'));

        // Rule 4: Upcoming event balances due
        eq(true, in_array('balance_due', $types, true));
        $balanceAlert = current(array_filter($alerts, fn($a) => $a['type'] === 'balance_due'));
        eq('Balance due', $balanceAlert['badge']);
        eq(true, str_contains($balanceAlert['message'], 'balance due'));

        // Rule 5: Stale pending bookings (>48h) or unread messages
        eq(true, in_array('stale_pending', $types, true) || in_array('unread_messages', $types, true));

        // Rule 6: Backup age warning (in seed data, Dec 10 backup is 2 days old, so healthy)
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Set all backup runs older than 8 days to test Rule 6 trigger
            $pdo->exec("UPDATE backup_runs SET created_at = '2026-12-01 10:00:00'");
            $staleAlerts = DashboardService::getAlerts();
            $staleTypes = array_column($staleAlerts, 'type');
            eq(true, in_array('backup_warning', $staleTypes, true));
            $bkAlert = current(array_filter($staleAlerts, fn($a) => $a['type'] === 'backup_warning'));
            eq('/admin/settings', $bkAlert['url']);
            eq('Backup', $bkAlert['badge']);

            // Now resolve all alert conditions to verify empty state returns []
            $pdo->exec("UPDATE payments SET status = 'verified' WHERE status = 'pending'");
            $pdo->exec("UPDATE messages SET read_at = NOW() WHERE read_at IS NULL");
            $pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE status = 'pending'");
            $pdo->exec("UPDATE projects SET stage = 'released' WHERE stage <> 'released'");
            foreach (DB::all("SELECT id, total_amount FROM bookings WHERE status = 'confirmed'") as $bRow) {
                $pdo->exec("INSERT INTO payments (booking_id, type, method, amount, status, created_at) VALUES ({$bRow['id']}, 'balance', 'cash', {$bRow['total_amount']}, 'verified', '2026-12-11 12:00:00')");
            }
            $pdo->exec("UPDATE backup_runs SET created_at = '2026-12-12 08:00:00'");

            $clearedAlerts = DashboardService::getAlerts();
            eq(0, count($clearedAlerts));
        } finally {
            $pdo->rollBack();
        }
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 3. Backup Status Evaluation
test('Milestone 4 Dashboard: getBackupStatus calculates staleness and formatting correctly', function () {
    Clock::setToday('2026-12-12');
    try {
        // Seed backup: Dec 10, 2026 against demo date Dec 12, 2026 = 2 days ago
        $status = DashboardService::getBackupStatus();
        eq('2026-12-10 21:00:00', $status['last_backup_at']);
        eq('2026-12-10', $status['last_backup_date']);
        eq(2, $status['days_ago']);
        eq(false, $status['is_stale']);
        eq(true, str_contains($status['status_line'], 'Dec 10, 2026 ✓'));

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Simulate stale backup (10 days ago: Dec 2, 2026)
            $pdo->exec("UPDATE backup_runs SET created_at = '2026-12-02 12:00:00'");
            $staleStatus = DashboardService::getBackupStatus();
            eq(10, $staleStatus['days_ago']);
            eq(true, $staleStatus['is_stale']);
            eq(true, str_contains($staleStatus['status_line'], '10 days ago'));
            eq(true, str_contains($staleStatus['status_line'], '⚠'));

            // Simulate empty backup_runs table
            $pdo->exec("DELETE FROM backup_runs");
            $emptyStatus = DashboardService::getBackupStatus();
            eq(null, $emptyStatus['last_backup_at']);
            eq(null, $emptyStatus['days_ago']);
            eq(true, $emptyStatus['is_stale']);
            eq('No backup on record ⚠', $emptyStatus['status_line']);
        } finally {
            $pdo->rollBack();
        }
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 4. Calendar Month Capacity Mapping
test('Milestone 4 Calendar: getMonthData accurately maps daily capacity and pending requests (BR-1)', function () {
    Clock::setToday('2026-12-12');
    try {
        $data = CalendarService::getMonthData(2026, 12);
        eq(31, count($data));

        // Dec 12: 1 confirmed (Santos), 1 pending (Reyes) -> not full, has_confirmed = true, has_pending_only = false
        $dec12 = $data['2026-12-12'];
        eq(12, $dec12['day']);
        eq(6, $dec12['day_of_week']); // Saturday
        eq(1, $dec12['confirmed_count']);
        eq(1, $dec12['pending_count']);
        eq(false, $dec12['is_full']);
        eq(false, $dec12['is_blocked']);
        eq(true, $dec12['has_confirmed']);
        eq(false, $dec12['has_pending_only']);
        eq(true, $dec12['is_today']);
        eq(true, $dec12['is_available']);

        // Dec 15: 0 events -> open
        $dec15 = $data['2026-12-15'];
        eq(0, $dec15['confirmed_count']);
        eq(0, $dec15['pending_count']);
        eq(false, $dec15['is_full']);
        eq(false, $dec15['is_blocked']);
        eq(true, $dec15['is_available']);

        // Capacity test in transaction
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            // Confirm Reyes booking #6 on Dec 12 -> now 2 confirmed events -> is_full = true
            $pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE id = 6");
            $dataFull = CalendarService::getMonthData(2026, 12);
            eq(2, $dataFull['2026-12-12']['confirmed_count']);
            eq(true, $dataFull['2026-12-12']['is_full']);
            eq(false, $dataFull['2026-12-12']['is_available']);

            // Add 3 pending bookings on Dec 15 -> confirms BR-1: pending requests NEVER fill capacity
            $pdo->exec("INSERT INTO bookings (reference_code, client_id, package_id, event_type, event_date, venue, status, package_price, total_amount, down_payment_due)
                        VALUES ('DPS-2026-9001', 1, 1, 'Birthday', '2026-12-15', 'Venue 1', 'pending', 8000, 8000, 2400),
                               ('DPS-2026-9002', 1, 1, 'Birthday', '2026-12-15', 'Venue 2', 'pending', 8000, 8000, 2400),
                               ('DPS-2026-9003', 1, 1, 'Birthday', '2026-12-15', 'Venue 3', 'pending', 8000, 8000, 2400)");
            $dataPending = CalendarService::getMonthData(2026, 12);
            eq(0, $dataPending['2026-12-15']['confirmed_count']);
            eq(3, $dataPending['2026-12-15']['pending_count']);
            eq(false, $dataPending['2026-12-15']['is_full']); // NEVER full from pending per BR-1
            eq(true, $dataPending['2026-12-15']['has_pending_only']);
            eq(false, $dataPending['2026-12-15']['has_confirmed']);
            eq(true, $dataPending['2026-12-15']['is_available']);
        } finally {
            $pdo->rollBack();
        }
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 5. Calendar Blocked Dates Mapping
test('Milestone 4 Calendar: getMonthData accurately maps blocked studio dates and reasons', function () {
    Clock::setToday('2026-12-12');
    try {
        $data = CalendarService::getMonthData(2026, 12);

        // Dec 25 is Christmas Day
        $dec25 = $data['2026-12-25'];
        eq(true, $dec25['is_blocked']);
        eq(false, $dec25['is_available']);
        eq(true, str_contains((string) $dec25['blocked_reason'], 'Christmas'));

        // Dec 31 is New Year's Eve
        $dec31 = $data['2026-12-31'];
        eq(true, $dec31['is_blocked']);
        eq(false, $dec31['is_available']);
        eq(true, str_contains((string) $dec31['blocked_reason'], "New Year"));
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 6. Day Schedule Inspection
test('Milestone 4 Calendar: getDaySchedule returns full event list, times, packages, crew, and actions', function () {
    Clock::setToday('2026-12-12');
    try {
        $sched = CalendarService::getDaySchedule('2026-12-12');
        eq('2026-12-12', $sched['date']);
        eq('Sat, Dec 12, 2026', $sched['date_formatted']);
        eq(true, $sched['is_today']);
        eq(false, $sched['is_blocked']);
        eq(false, $sched['is_full']);
        eq(true, $sched['can_add_booking']);
        eq('/admin/bookings/new?date=2026-12-12', $sched['add_booking_url']);
        eq(1, $sched['confirmed_count']);
        eq(1, $sched['pending_count']);
        eq(2, $sched['total_events']);
        eq(true, $sched['has_crew_shortage']);

        // Inspect event 1 (Santos #4, confirmed)
        $santos = $sched['events'][0];
        eq(4, $santos['id']);
        eq('DPS-2026-0004', $santos['reference_code']);
        eq('Wedding', $santos['event_type']);
        eq('Angela Santos (Sample)', $santos['client_name']);
        eq('Photo + Video Package', $santos['package_name']);
        eq('9:00 AM – 5:00 PM', $santos['time_formatted']);
        eq('confirmed', $santos['status']);
        eq(16000.0, (float) $santos['total_amount']);
        eq(4800.0, (float) $santos['paid_amount']);
        eq(11200.0, (float) $santos['balance']);
        eq(true, $santos['has_crew']);
        eq(2, count($santos['crew']));
        eq('lead', $santos['crew'][0]['role']);
        eq('backup', $santos['crew'][1]['role']);

        // Inspect event 2 (Reyes #6, pending)
        $reyes = $sched['events'][1];
        eq(6, $reyes['id']);
        eq('DPS-2026-0006', $reyes['reference_code']);
        eq('Birthday', $reyes['event_type']);
        eq('Liza Reyes (Sample)', $reyes['client_name']);
        eq('Photography Package', $reyes['package_name']);
        eq('2:00 PM – 6:00 PM', $reyes['time_formatted']);
        eq('pending', $reyes['status']);
        eq(8000.0, (float) $reyes['total_amount']);
        eq(0.0, (float) $reyes['paid_amount']);
        eq(8000.0, (float) $reyes['balance']);
        eq(false, $reyes['has_crew']);

        // Test inspection on open date (Dec 15)
        $sched15 = CalendarService::getDaySchedule('2026-12-15');
        eq(0, $sched15['total_events']);
        eq(0, count($sched15['events']));
        eq(true, $sched15['can_add_booking']);
        eq(false, $sched15['is_blocked']);

        // Test inspection on blocked date (Dec 25)
        $sched25 = CalendarService::getDaySchedule('2026-12-25');
        eq(true, $sched25['is_blocked']);
        eq(false, $sched25['can_add_booking']);
        eq(true, str_contains((string) $sched25['blocked_reason'], 'Christmas'));
    } finally {
        Clock::setToday(null);
    }
    return true;
});

// 7. Route Registration and Authorization
test('Milestone 4 Route registration and authorization: /admin and /admin/calendar RBAC checks', function () {
    $router = new Router();
    $routesFn = require dirname(__DIR__) . '/app/routes.php';
    $routesFn($router);

    $ref = new \ReflectionClass($router);
    $prop = $ref->getProperty('routes');
    $prop->setAccessible(true);
    $routes = $prop->getValue($router);

    $findRoute = function (string $method, string $path) use ($routes) {
        foreach ($routes as $r) {
            if ($r['method'] === $method && preg_match($r['regex'], $path)) {
                return $r;
            }
        }
        return null;
    };

    // Verify /admin matches AdminDashboardController
    $matchDash = $findRoute('GET', '/admin');
    eq(true, $matchDash !== null);
    eq('AdminDashboardController', substr(strrchr($matchDash['handler'][0], '\\'), 1));
    eq('index', $matchDash['handler'][1]);
    eq('admin', $matchDash['opts']['auth'] ?? null);

    // Verify /admin/calendar matches AdminCalendarController
    $matchCal = $findRoute('GET', '/admin/calendar');
    eq(true, $matchCal !== null);
    eq('AdminCalendarController', substr(strrchr($matchCal['handler'][0], '\\'), 1));
    eq('index', $matchCal['handler'][1]);
    eq('admin', $matchCal['opts']['auth'] ?? null);
    eq('view_bookings', $matchCal['opts']['perm'] ?? null);

    // Authorization checks
    $staff = DB::one("SELECT * FROM users WHERE username = 'staff'");
    $staff['perm_view_bookings'] = 0;
    Auth::forceUser($staff);
    try {
        eq(false, Auth::can('view_bookings'));
    } finally {
        Auth::forceUser(null);
    }

    $staff['perm_view_bookings'] = 1;
    Auth::forceUser($staff);
    try {
        eq(true, Auth::can('view_bookings'));
    } finally {
        Auth::forceUser(null);
    }

    return true;
});

// 8. Code Quality: zero BOM and strict CSP compliance across Milestone 4 files
test('Milestone 4 Code Quality: zero BOM in PHP files and strict CSP compliance across admin views', function () {
    $baseDir = dirname(__DIR__);

    $filesToCheck = [
        $baseDir . '/app/Services/DashboardService.php',
        $baseDir . '/app/Services/CalendarService.php',
        $baseDir . '/app/Controllers/AdminDashboardController.php',
        $baseDir . '/app/Controllers/AdminCalendarController.php',
        $baseDir . '/app/Views/admin/dashboard.php',
        $baseDir . '/app/Views/admin/calendar.php',
        $baseDir . '/app/routes.php',
        $baseDir . '/tests/run.php',
    ];

    foreach ($filesToCheck as $filePath) {
        eq(true, file_exists($filePath));
        $content = (string) file_get_contents($filePath);
        $hasBom = str_starts_with($content, "\xEF\xBB\xBF");
        eq(false, $hasBom);
    }

    // Strict CSP regex assertions on views
    $views = [
        $baseDir . '/app/Views/admin/dashboard.php',
        $baseDir . '/app/Views/admin/calendar.php',
    ];

    foreach ($views as $viewPath) {
        $html = (string) file_get_contents($viewPath);

        // No inline <script> tags
        eq(false, (bool) preg_match('/<script\b(?![^>]*type=["\']application\/json["\'])/i', $html));

        // No inline style attributes
        eq(false, (bool) preg_match('/\bstyle\s*=\s*["\']/i', $html));

        // No inline on* event handlers (e.g. onclick, onchange, onsubmit)
        eq(false, (bool) preg_match('/\bon[a-z]+\s*=\s*["\']/i', $html));
    }

    return true;
});

echo "\nPhase 5: in-app messaging (C6, A19, T11)\n";

// 1. Pre-booking inquiry chat creation & SHA-256 token hashing
test('T11 Pre-booking inquiry: startInquiry creates conversation, stores SHA-256 token hash, and logs audit', function () {
    $res = \App\Services\MessageService::startInquiry('Carla Gomez', '0917 555 8899', 'Hi! Is Dec 28 open for a debut?');
    $convId = (int) $res['conversation_id'];
    $token = (string) $res['token'];

    eq(true, $convId > 0);
    eq(64, strlen($token));

    $rawRow = DB::one('SELECT * FROM conversations WHERE id = ?', [$convId]);
    eq(hash('sha256', $token), $rawRow['access_token_hash']);
    eq('09175558899', $rawRow['guest_mobile']);
    eq('open', $rawRow['status']);

    // Lookup by raw token finds the conversation; wrong token returns null
    $found = \App\Models\Message::findByToken($token);
    eq($convId, (int) $found['id']);
    eq(null, \App\Models\Message::findByToken($token . 'x'));

    // Initial message was stored
    $msgs = \App\Models\Message::messagesFor($convId, 0);
    eq(1, count($msgs));
    eq('client', $msgs[0]['sender_type']);
    eq('Hi! Is Dec 28 open for a debut?', $msgs[0]['body']);

    // Cleanup test conversation
    DB::run('DELETE FROM messages WHERE conversation_id = ?', [$convId]);
    DB::run('DELETE FROM conversations WHERE id = ?', [$convId]);
    return true;
});

// 2. Two-way messaging, since_id polling, unread counts, and Sent/Seen transitions
test('T11 Client <-> Admin messaging: sendMessage, since_id polling, unread counts, and Sent/Seen (read_at)', function () {
    $owner = DB::one("SELECT * FROM users WHERE username = 'owner'");
    Auth::forceUser($owner);

    try {
        $res = \App\Services\MessageService::startInquiry('Marco Tan', '09181112233', 'Hello studio!');
        $convId = (int) $res['conversation_id'];
        $msgs = \App\Models\Message::messagesFor($convId, 0);
        $firstMsgId = (int) $msgs[0]['id'];

        // Admin sees at least 1 unread message
        $unreadBefore = \App\Models\Message::unreadForAdmin();
        eq(true, $unreadBefore >= 1);

        // Client's message is not yet seen (seenUpToId == 0)
        eq(0, \App\Models\Message::lastSeenIdForSender($convId, 'client'));

        // Admin replies -> marks client's message as read and creates admin message
        $adminReply = \App\Services\MessageService::sendMessage(
            $convId,
            'admin',
            'Hello Marco! Yes, how can we help you?',
            null,
            (int) $owner['id']
        );
        $replyId = (int) $adminReply['id'];
        eq('admin', $adminReply['sender_type']);

        // Client's first message is now Seen
        eq($firstMsgId, \App\Models\Message::lastSeenIdForSender($convId, 'client'));

        // Polling with since_id = $firstMsgId returns ONLY the new admin reply
        $delta = \App\Models\Message::messagesFor($convId, $firstMsgId);
        eq(1, count($delta));
        eq($replyId, (int) $delta[0]['id']);

        // Client marks thread read -> admin reply becomes Seen
        \App\Services\MessageService::markRead($convId, 'client');
        eq($replyId, \App\Models\Message::lastSeenIdForSender($convId, 'admin'));
        eq(0, \App\Models\Message::unreadForClientConversation($convId));

        // Cleanup
        DB::run('DELETE FROM messages WHERE conversation_id = ?', [$convId]);
        DB::run('DELETE FROM conversations WHERE id = ?', [$convId]);
        return true;
    } finally {
        Auth::forceUser(null);
    }
});

// 3. Ownership & IDOR protection
test('T11 Ownership & IDOR guard: clientOwnsConversation rejects unowned conversations and wrong tokens', function () {
    $_SESSION = [];
    $conv1 = \App\Models\Message::findConversation(1); // Booking #4 (Santos)
    $conv3 = \App\Models\Message::findConversation(3); // Inquiry with token 'demo-inquiry-token'

    // Without session or token, neither conversation is owned
    eq(false, \App\Services\MessageService::clientOwnsConversation($conv1));
    eq(false, \App\Services\MessageService::clientOwnsConversation($conv3));
    eq(false, \App\Services\MessageService::clientOwnsConversation($conv3, 'wrong-token-12345'));

    // Valid inquiry token unlocks conv 3, but NOT conv 1
    eq(true, \App\Services\MessageService::clientOwnsConversation($conv3, 'demo-inquiry-token'));
    eq(false, \App\Services\MessageService::clientOwnsConversation($conv1));

    // Unlocking booking #4 in session unlocks conv 1
    \App\Controllers\ClientBookController::grantAccessTo(4);
    eq(true, \App\Services\MessageService::clientOwnsConversation($conv1));

    $_SESSION = [];
    return true;
});

// 4. Close/Reopen & Link Inquiry to Booking
test('T11 Conversation lifecycle: close blocks client replies, admin reply reopens, linkConversation merges thread', function () {
    $owner = DB::one("SELECT * FROM users WHERE username = 'owner'");
    $ownerId = (int) $owner['id'];

    $res = \App\Services\MessageService::startInquiry('Elena Sy', '09192223344', 'Can I ask about wedding rates?');
    $inqId = (int) $res['conversation_id'];

    // Close conversation
    \App\Services\MessageService::setStatus($inqId, 'closed', $ownerId);
    $closedConv = \App\Models\Message::findConversation($inqId);
    eq('closed', $closedConv['status']);

    // Client cannot send into a closed conversation
    $blocked = false;
    try {
        \App\Services\MessageService::sendMessage($inqId, 'client', 'Still there?');
    } catch (\RuntimeException $e) {
        $blocked = true;
    }
    eq(true, $blocked);

    // Admin sending a message automatically reopens the conversation
    \App\Services\MessageService::sendMessage($inqId, 'admin', 'Reopening thread for you!', null, $ownerId);
    $reopened = \App\Models\Message::findConversation($inqId);
    eq('open', $reopened['status']);

    // Linking inquiry to a client record updates client_id and logs conversation_linked
    $targetId = \App\Services\MessageService::linkConversation($inqId, null, 4, $ownerId);
    eq($inqId, $targetId);
    $linked = \App\Models\Message::findConversation($inqId);
    eq(4, (int) $linked['client_id']);

    // Cleanup
    DB::run('DELETE FROM messages WHERE conversation_id = ?', [$inqId]);
    DB::run('DELETE FROM conversations WHERE id = ?', [$inqId]);
    return true;
});

// 5. Chat attachment 2 MB limit & MIME validation
test('T11 Chat attachments: UploadService::saveChatImage enforces 2 MB limit and rejects non-images', function () {
    // Reject non-image disguised as PNG
    $badTmp = tempnam(sys_get_temp_dir(), 'dps_chat_bad_');
    file_put_contents($badTmp, '<?php echo "not an image"; ?>');
    $rejectedMime = false;
    try {
        \App\Services\UploadService::saveChatImage([
            'name'     => 'evil.png',
            'type'     => 'image/png',
            'tmp_name' => $badTmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($badTmp),
        ], true);
    } catch (\App\Services\UploadException $e) {
        $rejectedMime = true;
    } finally {
        @unlink($badTmp);
    }
    eq(true, $rejectedMime);

    // Reject image exceeding 2 MB chat limit
    $bigTmp = tempnam(sys_get_temp_dir(), 'dps_chat_big_');
    file_put_contents($bigTmp, str_repeat('A', \App\Services\UploadService::CHAT_MAX_BYTES + 1024));
    $rejectedSize = false;
    try {
        \App\Services\UploadService::saveChatImage([
            'name'     => 'huge.png',
            'type'     => 'image/png',
            'tmp_name' => $bigTmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($bigTmp),
        ], true);
    } catch (\App\Services\UploadException $e) {
        $rejectedSize = str_contains($e->getMessage(), '2 MB');
    } finally {
        @unlink($bigTmp);
    }
    eq(true, $rejectedSize);

    // Accept valid 1x1 PNG under 2 MB
    $pngTmp = tempnam(sys_get_temp_dir(), 'dps_chat_ok_');
    file_put_contents(
        $pngTmp,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
    );
    try {
        [$relPath, $thumbPath] = \App\Services\UploadService::saveChatImage([
            'name'     => 'photo.png',
            'type'     => 'image/png',
            'tmp_name' => $pngTmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($pngTmp),
        ], true);
        eq(true, str_starts_with($relPath, 'chat/'));
        eq(true, is_file(storage_path($relPath)));
        \App\Services\UploadService::delete($relPath, $thumbPath);
    } finally {
        @unlink($pngTmp);
    }

    return true;
});

// 6. Phase 5 Code Quality: zero BOM and strict CSP compliance
test('Phase 5 Code Quality: zero BOM in PHP files and strict CSP compliance across chat views', function () {
    $baseDir = dirname(__DIR__);
    $phpFiles = [
        $baseDir . '/app/Models/Message.php',
        $baseDir . '/app/Services/MessageService.php',
        $baseDir . '/app/Controllers/ClientChatController.php',
        $baseDir . '/app/Controllers/AdminMessageController.php',
        $baseDir . '/app/Controllers/ApiMessageController.php',
        $baseDir . '/app/Controllers/FileController.php',
        $baseDir . '/app/Views/client/chat.php',
        $baseDir . '/app/Views/admin/messages_index.php',
        $baseDir . '/app/Views/admin/messages_show.php',
    ];
    foreach ($phpFiles as $f) {
        eq(true, file_exists($f));
        $raw = (string) file_get_contents($f);
        eq(false, str_starts_with($raw, "\xEF\xBB\xBF"));
    }

    $views = [
        $baseDir . '/app/Views/client/chat.php',
        $baseDir . '/app/Views/admin/messages_index.php',
        $baseDir . '/app/Views/admin/messages_show.php',
    ];
    foreach ($views as $v) {
        $html = (string) file_get_contents($v);
        eq(false, (bool) preg_match('/<script\b/i', $html));
        eq(false, (bool) preg_match('/\bstyle\s*=\s*["\']/i', $html));
        eq(false, (bool) preg_match('/\bon[a-z]+\s*=\s*["\']/i', $html));
    }
    return true;
});

echo "\nPhase 6: remaining admin, logs & crew (T7, A14–A18, A20, A21)\n";

test('Phase 6 (T7 / A18): CrewService::getEventsForUser returns assigned events with zero financial fields', function () {
    Clock::setToday('2026-12-12');

    // crew2 (user_id = 4, Videographer A, crew_member_id = 3) is assigned to Booking #4 (Dec 12) and Booking #1 (Nov 28)
    $sched2 = CrewService::getEventsForUser(4);
    eq('Videographer A', $sched2['crew_member']['name'] ?? '');
    eq(true, $sched2['next_event'] !== null);
    eq('DPS-2026-0004', $sched2['next_event']['reference_code']);
    eq('07:30:00', $sched2['next_event']['effective_call_time']);
    eq('backup', $sched2['next_event']['crew_role']);

    // Verify zero financial fields are present in the returned event array
    $next = $sched2['next_event'];
    eq(false, array_key_exists('total_amount', $next));
    eq(false, array_key_exists('down_payment_due', $next));
    eq(false, array_key_exists('package_price', $next));
    eq(false, array_key_exists('balance_due', $next));

    // Verify crew-visible notes are included
    eq(true, !empty($next['notes']));
    eq(true, str_contains($next['notes'][0]['body'], 'Bride prefers candid shots'));

    // Past events include Booking #1 (Dela Cruz Debut, Nov 28)
    eq(true, !empty($sched2['past_events']));
    eq('DPS-2026-0001', $sched2['past_events'][0]['reference_code']);

    return true;
});

test('Phase 6 (A15): Package catalog admin queries return all packages, other services, and add-ons', function () {
    $all = \App\Models\Package::allForAdmin();
    eq(true, count($all) >= 9);
    $pkg1 = \App\Models\Package::findById(1);
    eq('Photography Package', $pkg1['name'] ?? '');
    $addons = \App\Models\Package::allAddons();
    eq(true, count($addons) >= 3);
    $addon1 = \App\Models\Package::findAddonById(1);
    eq(true, $addon1 !== null);
    return true;
});

test('Phase 6 (A20): BackupService creates valid SQL dump, logs backup_runs and audit_logs, and marks cloud copy', function () {
    Auth::forceUser(DB::one("SELECT * FROM users WHERE username = 'owner'"));
    $run = \App\Services\BackupService::create('manual', 1);
    $runId = (int) $run['id'];
    eq(true, $runId > 0);
    eq('manual', $run['type']);
    eq(true, (int) $run['size_bytes'] > 1000);

    $path = \App\Services\BackupService::ensureFileForDownload($run);
    eq(true, $path !== null && is_file($path));
    $sqlContent = (string) file_get_contents($path);
    eq(true, str_contains($sqlContent, 'CREATE TABLE'));
    eq(true, str_contains($sqlContent, 'INSERT INTO `bookings`'));

    \App\Services\BackupService::markCloudCopy($runId, 1);
    $updated = DB::one('SELECT * FROM backup_runs WHERE id = ?', [$runId]);
    eq(true, !empty($updated['cloud_copy_marked_at']));

    // Verify audit logs recorded both actions
    $createdLogs = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup_created' AND entity_id = ?", [$runId]);
    $cloudLogs = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup_cloud_marked' AND entity_id = ?", [$runId]);
    eq(true, $createdLogs >= 1);
    eq(true, $cloudLogs >= 1);

    // Clean up test backup file and row
    if ($path && is_file($path)) {
        @unlink($path);
    }
    DB::run('DELETE FROM backup_runs WHERE id = ?', [$runId]);
    Auth::forceUser(null);
    return true;
});

test('Phase 6 Code Quality: zero BOM in PHP files and strict CSP compliance across Phase 6 views', function () {
    $baseDir = dirname(__DIR__);
    $phpFiles = [
        $baseDir . '/app/Controllers/AdminClientController.php',
        $baseDir . '/app/Controllers/AdminPackageController.php',
        $baseDir . '/app/Controllers/AdminReportController.php',
        $baseDir . '/app/Controllers/AdminUserController.php',
        $baseDir . '/app/Controllers/AdminSettingController.php',
        $baseDir . '/app/Controllers/AdminLogController.php',
        $baseDir . '/app/Controllers/CrewController.php',
        $baseDir . '/app/Services/BackupService.php',
        $baseDir . '/database/backup.php',
        $baseDir . '/app/Views/admin/clients.php',
        $baseDir . '/app/Views/admin/client_show.php',
        $baseDir . '/app/Views/admin/packages.php',
        $baseDir . '/app/Views/admin/reports.php',
        $baseDir . '/app/Views/admin/users.php',
        $baseDir . '/app/Views/admin/settings.php',
        $baseDir . '/app/Views/admin/logs.php',
        $baseDir . '/app/Views/crew/index.php',
    ];
    foreach ($phpFiles as $f) {
        eq(true, file_exists($f));
        $raw = (string) file_get_contents($f);
        eq(false, str_starts_with($raw, "\xEF\xBB\xBF"));
    }

    $views = [
        $baseDir . '/app/Views/admin/clients.php',
        $baseDir . '/app/Views/admin/client_show.php',
        $baseDir . '/app/Views/admin/packages.php',
        $baseDir . '/app/Views/admin/reports.php',
        $baseDir . '/app/Views/admin/users.php',
        $baseDir . '/app/Views/admin/settings.php',
        $baseDir . '/app/Views/admin/logs.php',
        $baseDir . '/app/Views/crew/index.php',
    ];
    foreach ($views as $v) {
        $html = (string) file_get_contents($v);
        eq(false, (bool) preg_match('/<script\b/i', $html));
        eq(false, (bool) preg_match('/\bstyle\s*=\s*["\']/i', $html));
        eq(false, (bool) preg_match('/\bon[a-z]+\s*=\s*["\']/i', $html));
    }
    return true;
});

echo "\nPhase 7: PWA-Lite, asset budgets, and Section 13 documentation\n";

test('Phase 7: manifest.json is valid JSON with required PWA fields', function () {
    $path = dirname(__DIR__) . '/public/manifest.json';
    eq(true, is_file($path));
    $data = json_decode((string) file_get_contents($path), true);
    eq(true, is_array($data));
    eq(true, str_starts_with($data['name'] ?? '', 'Douffer PhotoSys'));
    eq('Douffer', $data['short_name'] ?? '');
    eq('standalone', $data['display'] ?? '');
    eq(true, !empty($data['icons']));
    return true;
});

test('Phase 7: sw.js and offline.html exist and obey strict CSP', function () {
    $swPath = dirname(__DIR__) . '/public/sw.js';
    $offPath = dirname(__DIR__) . '/public/offline.html';
    eq(true, is_file($swPath));
    eq(true, is_file($offPath));
    $sw = (string) file_get_contents($swPath);
    $off = (string) file_get_contents($offPath);
    eq(true, str_contains($sw, 'offline.html'));
    eq(false, str_contains($off, '<script'));
    eq(false, str_contains($off, 'style='));
    return true;
});

test('Phase 7: CSS and JS asset budgets are within limits (CSS <= 30 KB, JS <= 60 KB)', function () {
    $cssSize = filesize(dirname(__DIR__) . '/public/assets/css/app.css');
    eq(true, $cssSize !== false && $cssSize <= 30 * 1024);
    $jsFiles = glob(dirname(__DIR__) . '/public/assets/js/*.js') ?: [];
    $totalJs = 0;
    foreach ($jsFiles as $f) {
        $totalJs += (int) filesize($f);
    }
    eq(true, $totalJs <= 60 * 1024);
    return true;
});

test('Phase 7: All 8 Section 13 documentation files exist and are non-empty', function () {
    $docs = [
        'README.md',
        'docs/DEVIATIONS.md',
        'docs/ERD.md',
        'docs/DFD.md',
        'docs/UseCases.md',
        'docs/TestCases.md',
        'docs/TRACEABILITY.md',
        'docs/CHANGELOG.md',
    ];
    foreach ($docs as $rel) {
        $p = dirname(__DIR__) . '/' . $rel;
        eq(true, is_file($p) && filesize($p) > 200);
    }
    return true;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
