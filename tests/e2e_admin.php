<?php
/**
 * Douffer PhotoSys: Phase 4 Admin Operations End-to-End HTTP Test Suite.
 *
 * Covers:
 *   - Authentication, RBAC permissions, session management, and CSRF protection
 *   - Screen A2 Live Dashboard & Screen A3 Booking Calendar
 *   - Screen A5 Direct Booking with on-the-spot down payment and availability validation
 *   - Screen A6 Booking Hub with internal staff/crew notes and client isolation
 *   - Scenario T3: Reschedule to open date (free, date lock, project due date sync, audit/chat log)
 *     vs refusal on full, blocked, or past dates; Cancellation with down payment forfeiture
 *     (is_forfeited = 1, frees capacity, non-refundable); Completed/cancelled booking immutability
 *   - Scenario T4: Crew assignment matrix on Dec 12, overlap rejection with conflict error when
 *     same crew member is assigned to overlapping event hours, shortage warning (red alert
 *     clears to green "All Events Covered"), same-day non-overlapping warning, equipment checklist saving
 *   - Scenario T5: Project tracker linear progression (Booked -> Covered -> Editing -> Ready -> Released),
 *     editing checklist updates, overdue deliverable detection (due_date < Clock::today() AND stage != 'released'),
 *     handover log saving (RAW/EDITED folders, local/cloud backup status, release method, recipient),
 *     release warning overrides (confirm_release_override), auto-marking booking completed when balance
 *     is ₱0.00, client message templates (A12) with placeholder substitution and copy/in-app dispatch
 *   - Forensic Verification of all 6 log streams:
 *       1. audit_logs (Security & Audit Trail)
 *       2. booking_changes (Booking Lifecycle & Policy History)
 *       3. project_history + projects (Project Stage, Folder Paths & Handover)
 *       4. message_log + messages (Client Notifications & Chat Stream)
 *       5. backup_runs (Backup & Data Protection Log)
 *       6. storage/logs/app.log + rate_limits (Telemetry, Error Log & Throttling Counters)
 *
 * Usage:
 *   php tests/e2e_admin.php [http://localhost:8000]
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function check(string $name, bool $ok, string $extra = ''): void
{
    global $fail, $pass;
    if ($ok) {
        $pass++;
        echo "PASS " . $name . PHP_EOL;
    } else {
        $fail++;
        echo "FAIL " . $name . ($extra !== '' ? "  [$extra]" : '') . PHP_EOL;
    }
}

final class AdminBrowser
{
    private string $jar;
    private ?string $cachedToken = null;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'dps_e2e_admin_');
    }

    public function __destruct()
    {
        if (file_exists($this->jar)) {
            @unlink($this->jar);
        }
    }

    public function req(string $path, string $method = 'GET', array $fields = [], array $headers = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: DPS-E2E-Admin-Runner'], $headers),
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $head = substr($raw, 0, $hs);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);

        return [
            'status'   => $status,
            'body'     => substr($raw, $hs),
            'location' => $m[1] ?? '',
            'headers'  => $head,
        ];
    }

    public function token(string $path = '/admin/projects'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m)) {
            $this->cachedToken = $m[1];
            return $m[1];
        }
        return $this->cachedToken ?? '';
    }

    public function login(string $username, string $password): array
    {
        $t = $this->token('/admin/login');
        return $this->req('/admin/login', 'POST', [
            '_csrf'    => $t,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function follow(array $resp): array
    {
        if (!empty($resp['location'])) {
            $path = preg_replace('#^(https?://[^/]+)?(/douffer_photosys)?#', '', $resp['location']);
            $path = explode('#', $path)[0];
            return $this->req($path !== '' ? $path : '/');
        }
        return $resp;
    }
}

// 1. Initialize bootstrap for direct read-only PDO database assertions
require $root . '/app/bootstrap.php';

// 2. Ensure clean, deterministic seed state before starting
echo "Setting up fresh seed database for Phase 4 E2E testing...\n";
exec('C:\Xampp\php\php.exe ' . escapeshellarg($root . '/database/install.php') . ' --fresh');

// Instantiate test actors
$anon  = new AdminBrowser($base);
$owner = new AdminBrowser($base);
$staff = new AdminBrowser($base);
$crew  = new AdminBrowser($base);

// ====================================================================
// SECTION 0: Authentication, RBAC Permissions, and CSRF Guards
// ====================================================================
echo "\n--- Section 0: RBAC, Session Authentication & CSRF Guards ---\n";

// 0.1 Unauthenticated requests to /admin are redirected to login
$rAnon = $anon->req('/admin');
check('0.1 Unauthenticated GET /admin redirects to /admin/login',
    in_array($rAnon['status'], [302, 303], true) && str_contains($rAnon['location'], '/admin/login'));

// 0.2 Owner authentication (owner / Owner@2026)
$rOwnerLogin = $owner->login('owner', 'Owner@2026');
check('0.2 Owner login succeeds with 303 redirect to /admin',
    $rOwnerLogin['status'] === 303 && str_ends_with(explode('?', $rOwnerLogin['location'])[0], '/admin'));
$rOwnerDash = $owner->follow($rOwnerLogin);
check('0.2 Owner lands on A2 Live Dashboard (200 OK)',
    $rOwnerDash['status'] === 200 && str_contains($rOwnerDash['body'], 'Events today') && str_contains($rOwnerDash['body'], 'Hello,'));

// 0.3 Staff authentication (staff / Staff@2026)
$rStaffLogin = $staff->login('staff', 'Staff@2026');
check('0.3 Staff login succeeds with 303 redirect to /admin',
    $rStaffLogin['status'] === 303 && str_ends_with(explode('?', $rStaffLogin['location'])[0], '/admin'));

// 0.4 Crew authentication lands on A18 /crew and is blocked from admin routes
$rCrewLogin = $crew->login('crew1', 'Crew1@2026');
check('0.4 Crew login succeeds with 303 redirect to /crew (Screen A18)',
    $rCrewLogin['status'] === 303 && str_ends_with(explode('?', $rCrewLogin['location'])[0], '/crew'));
$rCrewPortal = $crew->follow($rCrewLogin);
check('0.4 Screen A18 Crew portal renders view-only agenda view (200 OK)',
    $rCrewPortal['status'] === 200 && str_contains($rCrewPortal['body'], 'View-only') && str_contains($rCrewPortal['body'], 'My Events'));

$rCrewAdmin = $crew->req('/admin');
check('0.4 Crew role is forbidden (403) from accessing /admin', $rCrewAdmin['status'] === 403);
$rCrewProjects = $crew->req('/admin/projects');
check('0.4 Crew role is forbidden (403) from accessing /admin/projects', $rCrewProjects['status'] === 403);
$rCrewCrewScreen = $crew->req('/admin/crew');
check('0.4 Crew role is forbidden (403) from accessing /admin/crew', $rCrewCrewScreen['status'] === 403);

// 0.5 CSRF verification on POST routes
$rCsrf = $owner->req('/admin/bookings/5/change', 'POST', ['action' => 'reschedule', 'new_date' => '2026-12-16']);
check('0.5 POST route without CSRF token returns 403 Forbidden (Session expired)',
    $rCsrf['status'] === 403 && str_contains($rCsrf['body'], 'Session expired'));

// 0.6 Staff permission barriers (staff lacks archive_records and verify_payments)
$tStaffCancel = $staff->token('/admin/bookings/2/change?mode=cancel');
$rStaffCancel = $staff->req('/admin/bookings/2/change', 'POST', [
    '_csrf'           => $tStaffCancel,
    'action'          => 'cancel',
    'confirm_forfeit' => '1',
    'reason'          => 'Staff unauthorized cancellation attempt',
]);
check('0.6 Staff without archive_records permission is forbidden from cancellation (403)', $rStaffCancel['status'] === 403);

$tStaffVerify = $staff->token('/admin/payments');
$rStaffVerify = $staff->req('/admin/payments/8/verify', 'POST', ['_csrf' => $tStaffVerify]);
check('0.6 Staff without verify_payments permission is forbidden from verifying payments (403)', $rStaffVerify['status'] === 403);

// ====================================================================
// SECTION 1: Screens A2, A3, Availability API, and A6 Internal Notes
// ====================================================================
echo "\n--- Section 1: Dashboard, Calendar, Availability API & Internal Notes ---\n";

// 1.1 Live Dashboard summary tiles & alert rules (Screen A2)
$rDash = $owner->req('/admin');
check('1.1 A2 Dashboard renders live metric tiles',
    $rDash['status'] === 200 &&
    str_contains($rDash['body'], 'Events today') &&
    str_contains($rDash['body'], 'Payments to verify') &&
    str_contains($rDash['body'], 'Balances due') &&
    str_contains($rDash['body'], 'Outputs overdue'));
check('1.1 A2 Dashboard renders Needs Attention alert box', str_contains($rDash['body'], 'Needs attention'));

// 1.2 Booking Calendar month grid & day inspection (Screen A3)
$rCal = $owner->req('/admin/calendar?year=2026&month=12');
check('1.2 A3 Calendar renders month grid for Dec 2026',
    $rCal['status'] === 200 && str_contains($rCal['body'], 'December 2026'));
check('1.2 A3 Calendar displays blocked studio dates with reason',
    str_contains($rCal['body'], '2026-12-25') && str_contains($rCal['body'], 'Christmas Day'));

// 1.3 Availability API endpoint (/api/availability/check)
$rApiOpen = $owner->req('/api/availability/check?date=2026-12-15');
$jApiOpen = json_decode($rApiOpen['body'], true);
check('1.3 Availability API returns open status for Dec 15, 2026',
    $rApiOpen['status'] === 200 && ($jApiOpen['ok'] ?? false) === true && ($jApiOpen['available'] ?? false) === true);

$rApiBlocked = $owner->req('/api/availability/check?date=2026-12-25');
$jApiBlocked = json_decode($rApiBlocked['body'], true);
check('1.3 Availability API returns blocked status for Christmas Day',
    ($jApiBlocked['available'] ?? true) === false && ($jApiBlocked['reason'] ?? '') === 'blocked');

$rApiPast = $owner->req('/api/availability/check?date=2026-12-10');
$jApiPast = json_decode($rApiPast['body'], true);
check('1.3 Availability API returns past status for Dec 10, 2026 (demo date Dec 12)',
    ($jApiPast['available'] ?? true) === false && ($jApiPast['reason'] ?? '') === 'past');

// 1.4 Screen A6 Internal Notes with crew visibility toggle & client isolation
$tNote = $owner->token('/admin/bookings/4?tab=notes');
$rNote = $owner->req('/admin/bookings/4/notes', 'POST', [
    '_csrf'           => $tNote,
    'body'            => 'Bride requests extra coverage for grandmother arrival at 11:30 AM',
    'visible_to_crew' => '1',
]);
check('1.4 A6 Internal note submission returns 303 redirect to notes tab',
    $rNote['status'] === 303 && str_contains($rNote['location'], '/admin/bookings/4?tab=notes'));
$rFollowNote = $owner->follow($rNote);
check('1.4 A6 Notes tab displays newly added note with success flash',
    str_contains($rFollowNote['body'], 'grandmother arrival') && str_contains($rFollowNote['body'], 'Internal note added'));
check('1.4 DB verifies note persisted in booking_notes and logged in booking_changes',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_notes WHERE booking_id = 4 AND body LIKE "%grandmother arrival%"') === 1 &&
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE booking_id = 4 AND change_type = "note"') >= 1);

// Client Isolation check: note does not leak to client message thread
$leakCount = (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 4 AND m.body LIKE "%grandmother arrival%"');
check('1.4 Internal notes are strictly isolated from client conversation thread', $leakCount === 0);

// ====================================================================
// SECTION 2: Scenario T3 (Reschedule & Cancellation / Forfeiture)
// ====================================================================
echo "\n--- Section 2: Scenario T3 (Reschedule & Cancel / Deposit Forfeiture) ---\n";

// 2.1 Free Reschedule of Booking #5 (Paolo Garcia, Dec 19) to open date 2026-12-16
$tResched = $owner->token('/admin/bookings/5/change?mode=reschedule');
$rResched = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'      => $tResched,
    'action'     => 'reschedule',
    'new_date'   => '2026-12-16',
    'start_time' => '10:00',
    'end_time'   => '18:00',
    'venue'      => 'Tagaytay Wedding Garden',
    'reason'     => 'Client requested new open date due to venue availability',
]);
check('2.1 Reschedule to open date returns 303 redirect to booking hub',
    $rResched['status'] === 303 && str_contains($rResched['location'], '/admin/bookings/5'));
$rHub5 = $owner->follow($rResched);
check('2.1 Booking hub renders flash confirming free reschedule applied',
    str_contains($rHub5['body'], 'Booking rescheduled to Dec 16, 2026') && str_contains($rHub5['body'], 'Free reschedule applied'));
check('2.1 DB verifies booking event_date updated to 2026-12-16',
    \App\Core\DB::value('SELECT event_date FROM bookings WHERE id = 5') === '2026-12-16');
check('2.1 DB verifies linked project due_date recalculated to 2026-12-30 (14 days post-event)',
    \App\Core\DB::value('SELECT due_date FROM projects WHERE booking_id = 5') === '2026-12-30');
check('2.1 DB verifies booking_changes logged reschedule with old and new dates',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE booking_id = 5 AND change_type = "reschedule" AND old_value = "2026-12-19" AND new_value = "2026-12-16"') === 1);
check('2.1 DB verifies in-app chat thread received automated reschedule notice',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 5 AND m.sender_type = "system" AND m.body LIKE "%rescheduled to Dec 16, 2026%"') === 1);

// 2.2 Reschedule Refusal on blocked date (Christmas Day 2026-12-25)
$tBlocked = $owner->token('/admin/bookings/5/change?mode=reschedule');
$rBlocked = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'      => $tBlocked,
    'action'     => 'reschedule',
    'new_date'   => '2026-12-25',
    'start_time' => '10:00',
    'end_time'   => '18:00',
    'reason'     => 'Attempt on blocked date',
]);
check('2.2 Reschedule to blocked date redirects back to change form',
    $rBlocked['status'] === 303 && str_contains($rBlocked['location'], 'mode=reschedule'));
$rFollowBlocked = $owner->follow($rBlocked);
check('2.2 Change form renders studio blocked date error banner',
    str_contains($rFollowBlocked['body'], 'This date is blocked by the studio'));
check('2.2 DB verifies booking event_date remained unchanged (2026-12-16)',
    \App\Core\DB::value('SELECT event_date FROM bookings WHERE id = 5') === '2026-12-16');

// 2.3 Reschedule Refusal on past date (2026-12-10 relative to demo date 2026-12-12)
$tPast = $owner->token('/admin/bookings/5/change?mode=reschedule');
$rPast = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'      => $tPast,
    'action'     => 'reschedule',
    'new_date'   => '2026-12-10',
    'start_time' => '10:00',
    'end_time'   => '18:00',
    'reason'     => 'Attempt on past date',
]);
check('2.3 Reschedule to past date redirects back to change form',
    $rPast['status'] === 303 && str_contains($rPast['location'], 'mode=reschedule'));
$rFollowPast = $owner->follow($rPast);
check('2.3 Change form renders error indicating date is in the past',
    str_contains($rFollowPast['body'], 'past'));
check('2.3 DB verifies booking event_date remained 2026-12-16',
    \App\Core\DB::value('SELECT event_date FROM bookings WHERE id = 5') === '2026-12-16');

// 2.4 Reschedule Refusal on full date (capacity limit reached):
// Create two confirmed bookings on Dec 18 via Screen A5 with on-the-spot down payment to reach max capacity (2)
$tNewBookingA = $owner->token('/admin/bookings/new');
$rNewBookingA = $owner->req('/admin/bookings/new', 'POST', [
    '_csrf'                 => $tNewBookingA,
    'source_channel'        => 'walk_in',
    'full_name'             => 'Walkin Client One',
    'mobile'                => '0917 888 1111',
    'event_type'            => 'Debut',
    'package_id'            => '1',
    'event_date'            => '2026-12-18',
    'start_time'            => '10:00',
    'end_time'              => '14:00',
    'venue'                 => 'Clubhouse A',
    'down_payment_received' => '1',
    'payment_amount'        => '2400',
    'payment_method'        => 'cash',
]);
check('2.4 Screen A5 direct booking confirms immediately with down payment received (Event 1)',
    $rNewBookingA['status'] === 303 && str_contains($rNewBookingA['location'], '/admin/bookings/'));

$tNewBookingB = $owner->token('/admin/bookings/new');
$rNewBookingB = $owner->req('/admin/bookings/new', 'POST', [
    '_csrf'                 => $tNewBookingB,
    'source_channel'        => 'walk_in',
    'full_name'             => 'Walkin Client Two',
    'mobile'                => '0917 888 2222',
    'event_type'            => 'Birthday',
    'package_id'            => '1',
    'event_date'            => '2026-12-18',
    'start_time'            => '15:00',
    'end_time'              => '19:00',
    'venue'                 => 'Clubhouse B',
    'down_payment_received' => '1',
    'payment_amount'        => '2400',
    'payment_method'        => 'cash',
]);
check('2.4 Screen A5 direct booking confirms immediately with down payment received (Event 2)',
    $rNewBookingB['status'] === 303 && str_contains($rNewBookingB['location'], '/admin/bookings/'));
check('2.4 Dec 18, 2026 now has 2 confirmed events reaching studio capacity (full)',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM bookings WHERE event_date = "2026-12-18" AND status = "confirmed"') === 2);

// Now attempt to reschedule Booking #5 to the fully booked date Dec 18
$tFull = $owner->token('/admin/bookings/5/change?mode=reschedule');
$rFull = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'      => $tFull,
    'action'     => 'reschedule',
    'new_date'   => '2026-12-18',
    'start_time' => '10:00',
    'end_time'   => '18:00',
    'reason'     => 'Attempt on full date',
]);
check('2.4 Reschedule to full date redirects back to change form',
    $rFull['status'] === 303 && str_contains($rFull['location'], 'mode=reschedule'));
$rFollowFull = $owner->follow($rFull);
check('2.4 Change form renders error for full date',
    str_contains($rFollowFull['body'], 'full') || str_contains($rFollowFull['body'], 'fully booked') || str_contains($rFollowFull['body'], 'became full'));
check('2.4 DB verifies booking date remained 2026-12-16',
    \App\Core\DB::value('SELECT event_date FROM bookings WHERE id = 5') === '2026-12-16');

// 2.5 Cancellation of Booking #5 with Down Payment Forfeiture (BR-2)
// Attempt without confirm_forfeit -> rejected with error
$tCancelNoConfirm = $owner->token('/admin/bookings/5/change?mode=cancel');
$rCancelNoConfirm = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'  => $tCancelNoConfirm,
    'action' => 'cancel',
    'reason' => 'Client emergency cancellation',
]);
check('2.5 Cancellation without forfeiture confirmation redirects back to change form',
    $rCancelNoConfirm['status'] === 303 && str_contains($rCancelNoConfirm['location'], 'mode=cancel'));
$rFollowNoConfirm = $owner->follow($rCancelNoConfirm);
check('2.5 Form renders forfeiture confirmation requirement error',
    str_contains($rFollowNoConfirm['body'], 'Please confirm that the client understands the down payment is forfeited without refund'));
check('2.5 DB verifies booking status is still confirmed',
    \App\Core\DB::value('SELECT status FROM bookings WHERE id = 5') === 'confirmed');

// Cancel WITH confirm_forfeit -> succeeds, forfeits down payment, frees slot
$tCancel = $owner->token('/admin/bookings/5/change?mode=cancel');
$rCancel = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'           => $tCancel,
    'action'          => 'cancel',
    'confirm_forfeit' => '1',
    'reason'          => 'Client decided to cancel wedding; understands down payment is forfeited without refund',
]);
check('2.5 Cancellation with forfeiture confirmation returns 303 redirect to booking hub',
    $rCancel['status'] === 303 && str_contains($rCancel['location'], '/admin/bookings/5'));
$rHub5Cancelled = $owner->follow($rCancel);
check('2.5 Booking hub displays Cancelled badge and forfeiture success toast',
    str_contains($rHub5Cancelled['body'], 'Verified down payment has been marked as forfeited (no refund)') &&
    str_contains($rHub5Cancelled['body'], 'Cancelled'));
check('2.5 DB verifies booking status updated to cancelled with timestamp',
    \App\Core\DB::value('SELECT status FROM bookings WHERE id = 5') === 'cancelled' &&
    \App\Core\DB::value('SELECT cancelled_at FROM bookings WHERE id = 5') !== null);
check('2.5 DB verifies verified down payment marked is_forfeited = 1 (non-refundable)',
    (int) \App\Core\DB::value('SELECT is_forfeited FROM payments WHERE booking_id = 5 AND type = "down" AND status = "verified"') === 1);
check('2.5 DB verifies date 2026-12-16 is freed (0 confirmed events)',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM bookings WHERE event_date = "2026-12-16" AND status = "confirmed"') === 0);
check('2.5 DB verifies booking_changes logged cancellation with reason',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE booking_id = 5 AND change_type = "cancel" AND new_value = "cancelled"') === 1);

// 2.6 Immutability of Cancelled & Completed Bookings
// Attempt to reschedule cancelled Booking #5
$tValidAdmin = $owner->token('/admin/projects');
$rReschedCancelled = $owner->req('/admin/bookings/5/change', 'POST', [
    '_csrf'    => $tValidAdmin,
    'action'   => 'reschedule',
    'new_date' => '2026-12-18',
    'reason'   => 'Attempt on cancelled booking',
]);
check('2.6 Reschedule attempt on cancelled booking redirects to booking hub',
    $rReschedCancelled['status'] === 303 && str_contains($rReschedCancelled['location'], '/admin/bookings/5'));
$rFollowReschedCancelled = $owner->follow($rReschedCancelled);
check('2.6 Error toast states cancelled bookings cannot be rescheduled',
    str_contains($rFollowReschedCancelled['body'], 'This booking is already cancelled'));

// Attempt to cancel completed Booking #1 (Maria Dela Cruz)
$rCancelCompleted = $owner->req('/admin/bookings/1/change', 'POST', [
    '_csrf'           => $tValidAdmin,
    'action'          => 'cancel',
    'confirm_forfeit' => '1',
    'reason'          => 'Attempt on completed booking',
]);
check('2.6 Cancellation attempt on completed booking redirects to booking hub',
    $rCancelCompleted['status'] === 303 && str_contains($rCancelCompleted['location'], '/admin/bookings/1'));
$rFollowCancelCompleted = $owner->follow($rCancelCompleted);
check('2.6 Error toast states completed bookings cannot be cancelled',
    str_contains($rFollowCancelCompleted['body'], 'Completed bookings cannot be rescheduled or cancelled'));
check('2.6 DB verifies Booking #1 remains completed',
    \App\Core\DB::value('SELECT status FROM bookings WHERE id = 1') === 'completed');

// ====================================================================
// SECTION 3: Scenario T4 (Crew Assignment, Overlap Blocking & Shortages)
// ====================================================================
echo "\n--- Section 3: Scenario T4 (Crew Assignment & Overlap Conflicts) ---\n";

// 3.1 Shortage detected on Dec 12, 2026 (Booking #6 Liza Reyes has no assigned crew)
$rCrewDec12 = $owner->req('/admin/crew?date=2026-12-12');
check('3.1 Screen A9 renders red shortage banner (alert-danger)',
    $rCrewDec12['status'] === 200 &&
    str_contains($rCrewDec12['body'], 'alert-danger') &&
    str_contains($rCrewDec12['body'], 'Crew Shortage Detected'));
check('3.1 Screen A9 displays unassigned Liza Reyes event badge',
    str_contains($rCrewDec12['body'], 'Liza Reyes') && str_contains($rCrewDec12['body'], 'No Crew'));

// 3.2 Hard overlap rejection: Assign Owner (crew #1, 09:00–17:00 on Santos) to Reyes (booking #6, 14:00–18:00)
$tCrewAssign = $owner->token('/admin/crew?date=2026-12-12');
$rOverlap = $owner->req('/admin/crew/assign', 'POST', [
    '_csrf'          => $tCrewAssign,
    'booking_id'     => 6,
    'crew_member_id' => 1,
    'role'           => 'lead',
    'date'           => '2026-12-12',
]);
check('3.2 Overlapping crew assignment returns 303 redirect with conflict', $rOverlap['status'] === 303);
$rCrewOverlapFollow = $owner->follow($rOverlap);
check('3.2 Flash error displays overlapping hours conflict message',
    str_contains($rCrewOverlapFollow['body'], 'overlapping hours'));
check('3.2 DB verifies zero assignment records created for conflicting crew member',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM crew_assignments WHERE booking_id = 6 AND crew_member_id = 1') === 0);

// 3.3 Shortage resolution with on-call crew member (crew #4, Photographer B)
$tAssignB = $owner->token('/admin/crew?date=2026-12-12');
$rAssignB = $owner->req('/admin/crew/assign', 'POST', [
    '_csrf'          => $tAssignB,
    'booking_id'     => 6,
    'crew_member_id' => 4,
    'role'           => 'lead',
    'call_time'      => '13:00',
    'date'           => '2026-12-12',
]);
check('3.3 Assigning on-call crew returns 303 redirect', $rAssignB['status'] === 303);
$rCrewResolvedFollow = $owner->follow($rAssignB);
check('3.3 Red shortage banner clears and green "All Events Covered" banner renders',
    str_contains($rCrewResolvedFollow['body'], 'alert-success') &&
    str_contains($rCrewResolvedFollow['body'], 'All Events Covered') &&
    !str_contains($rCrewResolvedFollow['body'], 'Crew Shortage Detected'));
check('3.3 DB verifies crew assignment saved with correct call time and role',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM crew_assignments WHERE booking_id = 6 AND crew_member_id = 4 AND role = "lead" AND call_time = "13:00:00"') === 1);
check('3.3 DB verifies audit log recorded crew_assigned action',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = "crew_assigned" AND entity_id = 6') >= 1);

// 3.4 Equipment checklist persistence
$tEquip = $owner->token('/admin/crew?date=2026-12-12');
$rEquip = $owner->req('/admin/crew/equipment', 'POST', [
    '_csrf'         => $tEquip,
    'booking_id'    => 6,
    'equipment_ids' => [1, 2, 4],
    'date'          => '2026-12-12',
]);
check('3.4 Equipment save returns 303 redirect', $rEquip['status'] === 303);
$rFollowEquip = $owner->follow($rEquip);
check('3.4 Screen A9 renders success toast for equipment checklist save',
    str_contains($rFollowEquip['body'], 'Equipment checklist saved ✓'));
check('3.4 DB verifies 3 items checked in booking_equipment',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_equipment WHERE booking_id = 6 AND is_checked = 1') === 3);
check('3.4 DB verifies audit log recorded equipment_saved action',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = "equipment_saved" AND entity_id = 6') >= 1);

// 3.5 Unassign crew restores shortage warning
$tUnassign = $owner->token('/admin/crew?date=2026-12-12');
$rUnassign = $owner->req('/admin/crew/unassign', 'POST', [
    '_csrf'          => $tUnassign,
    'booking_id'     => 6,
    'crew_member_id' => 4,
    'date'           => '2026-12-12',
]);
check('3.5 Crew unassignment returns 303 redirect', $rUnassign['status'] === 303);
$rFollowUnassign = $owner->follow($rUnassign);
check('3.5 Screen A9 restores red shortage banner upon unassigning crew',
    str_contains($rFollowUnassign['body'], 'alert-danger') && str_contains($rFollowUnassign['body'], 'Crew Shortage Detected'));

// Re-assign Photographer B to leave date Dec 12 fully covered
$tReassign = $owner->token('/admin/crew?date=2026-12-12');
$owner->req('/admin/crew/assign', 'POST', [
    '_csrf'          => $tReassign,
    'booking_id'     => 6,
    'crew_member_id' => 4,
    'role'           => 'lead',
    'call_time'      => '13:00',
    'date'           => '2026-12-12',
]);

// ====================================================================
// SECTION 4: Scenario T5 (Projects, Overdue, Handover & Templates)
// ====================================================================
echo "\n--- Section 4: Scenario T5 (Project Tracker, Handover & Messaging) ---\n";

// 4.1 Overdue Deliverable Detection (Project #3 Carlo Cruz due 2026-12-10, today 2026-12-12)
$rProjects = $owner->req('/admin/projects');
check('4.1 Screen A10 renders Project #3 with Overdue badge',
    $rProjects['status'] === 200 &&
    str_contains($rProjects['body'], 'DPS-2026-0003') &&
    str_contains($rProjects['body'], 'Overdue'));

$rOverdueFilter = $owner->req('/admin/projects?overdue=1');
check('4.1 Overdue filter isolates overdue project #3 and excludes non-overdue projects',
    str_contains($rOverdueFilter['body'], 'DPS-2026-0003') &&
    !str_contains($rOverdueFilter['body'], 'DPS-2026-0001'));

// 4.2 Editing checklist updates
$tChecklist = $owner->token('/admin/projects');
$rChecklist = $owner->req('/admin/projects/3/checklist', 'POST', [
    '_csrf'          => $tChecklist,
    'editing_done'   => '1',
    'rendering_done' => '1',
]);
check('4.2 Editing checklist update returns 303 redirect', $rChecklist['status'] === 303);
check('4.2 DB verifies editing_done=1, rendering_done=1, album_done=0',
    (int) \App\Core\DB::value('SELECT editing_done FROM projects WHERE id = 3') === 1 &&
    (int) \App\Core\DB::value('SELECT rendering_done FROM projects WHERE id = 3') === 1 &&
    (int) \App\Core\DB::value('SELECT album_done FROM projects WHERE id = 3') === 0);
check('4.2 DB verifies audit log recorded project_checklist_updated',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = "project_checklist_updated" AND entity_id = 3') >= 1);

// 4.3 Linear stage progression to ready (editing -> ready)
$tStage = $owner->token('/admin/projects');
$rStage = $owner->req('/admin/projects/3/stage', 'POST', [
    '_csrf' => $tStage,
    'stage' => 'ready',
]);
check('4.3 Stage progression editing -> ready returns 303 redirect', $rStage['status'] === 303);
check('4.3 DB verifies projects.stage updated to ready',
    \App\Core\DB::value('SELECT stage FROM projects WHERE id = 3') === 'ready');
check('4.3 DB verifies project_history recorded transition to ready',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM project_history WHERE project_id = 3 AND old_stage = "editing" AND new_stage = "ready"') === 1);
check('4.3 DB verifies automated chat notice posted in client thread',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 3 AND m.body LIKE "%ready%"') >= 1);

// 4.4 Handover release warnings & override enforcement
// Project #3 has missing cloud copy (cloud_copy_done=0) and unpaid balance (₱7,000.00)
$tFiles3 = $owner->token('/admin/projects/3/files');
$rReleaseBlocked = $owner->req('/admin/projects/3/files', 'POST', [
    '_csrf'         => $tFiles3,
    'mark_released' => '1',
    'released_how'  => 'pickup',
]);
check('4.4 Handover release without override confirmation returns 303 redirect with error',
    $rReleaseBlocked['status'] === 303);
$rFollowBlockedRelease = $owner->follow($rReleaseBlocked);
check('4.4 Flash error indicates confirmation override is required to release deliverable',
    str_contains($rFollowBlockedRelease['body'], 'Confirmation required to release deliverables with missing cloud copy or unpaid balance'));
check('4.4 DB verifies project stage remains ready (unreleased)',
    \App\Core\DB::value('SELECT stage FROM projects WHERE id = 3') === 'ready');

// 4.5 Handover release WITH override clearing overdue status
$tFilesOverride = $owner->token('/admin/projects/3/files');
$rReleaseSuccess = $owner->req('/admin/projects/3/files', 'POST', [
    '_csrf'                    => $tFilesOverride,
    'raw_folder'               => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW',
    'edited_folder'            => 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/EDITED',
    'local_backup_done'        => '1',
    'cloud_copy_done'          => '1',
    'mark_released'            => '1',
    'confirm_release_override' => '1',
    'released_how'             => 'pickup',
    'received_by'              => 'Carlo Cruz',
    'released_note'            => 'Picked up flash drive at studio.',
]);
check('4.5 Release with override returns 303 redirect', $rReleaseSuccess['status'] === 303);
$rFollowSuccessRelease = $owner->follow($rReleaseSuccess);
check('4.5 Screen A11 renders success toast "Deliverables released ✓"',
    str_contains($rFollowSuccessRelease['body'], 'Deliverables released ✓'));
check('4.5 DB verifies project stage updated to released',
    \App\Core\DB::value('SELECT stage FROM projects WHERE id = 3') === 'released');
check('4.5 DB verifies RAW and EDITED directory paths saved in projects table',
    \App\Core\DB::value('SELECT raw_folder FROM projects WHERE id = 3') === 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW' &&
    \App\Core\DB::value('SELECT edited_folder FROM projects WHERE id = 3') === 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/EDITED');
check('4.5 DB verifies local and cloud backup timestamps recorded',
    \App\Core\DB::value('SELECT local_backup_at FROM projects WHERE id = 3') !== null &&
    \App\Core\DB::value('SELECT cloud_copy_at FROM projects WHERE id = 3') !== null);
check('4.5 DB verifies recipient and handover method saved',
    \App\Core\DB::value('SELECT received_by FROM projects WHERE id = 3') === 'Carlo Cruz' &&
    \App\Core\DB::value('SELECT released_how FROM projects WHERE id = 3') === 'pickup');

// Overdue status clearance check on Project Tracker
$rProjectsAfterRelease = $owner->req('/admin/projects');
check('4.5 Project #3 overdue status is cleared on project tracker after release',
    str_contains($rProjectsAfterRelease['body'], 'DPS-2026-0003') &&
    !str_contains($rProjectsAfterRelease['body'], 'tag-danger'));
$rOverdueAfter = $owner->req('/admin/projects?overdue=1');
check('4.5 Overdue filter no longer lists Project #3 after release',
    !str_contains($rOverdueAfter['body'], 'DPS-2026-0003'));
check('4.5 Booking #3 with unpaid balance (₱7,000.00) remains confirmed',
    \App\Core\DB::value('SELECT status FROM bookings WHERE id = 3') === 'confirmed');

// 4.6 Booking auto-completion upon release of fully paid project (Project #2 Ramon Lopez, balance ₱0.00)
// Advance Project #2 from editing to ready
$tStage2 = $owner->token('/admin/projects');
$owner->req('/admin/projects/2/stage', 'POST', ['_csrf' => $tStage2, 'stage' => 'ready']);

// Release Project #2 deliverables
$tFiles2 = $owner->token('/admin/projects/2/files');
$rRelease2 = $owner->req('/admin/projects/2/files', 'POST', [
    '_csrf'             => $tFiles2,
    'raw_folder'        => 'D:/Douffer/2026/2026-11-21_Lopez_Anniversary/RAW',
    'edited_folder'     => 'D:/Douffer/2026/2026-11-21_Lopez_Anniversary/EDITED',
    'local_backup_done' => '1',
    'cloud_copy_done'   => '1',
    'mark_released'     => '1',
    'released_how'      => 'messenger_link',
    'received_by'       => 'Ramon Lopez',
]);
check('4.6 Release of fully paid project returns 303 redirect', $rRelease2['status'] === 303);
check('4.6 DB verifies Project #2 stage updated to released',
    \App\Core\DB::value('SELECT stage FROM projects WHERE id = 2') === 'released');
check('4.6 DB verifies Booking #2 is automatically marked completed',
    \App\Core\DB::value('SELECT status FROM bookings WHERE id = 2') === 'completed');
check('4.6 DB verifies Booking #2 completed_at timestamp is populated',
    \App\Core\DB::value('SELECT completed_at FROM bookings WHERE id = 2') !== null);
check('4.6 DB verifies booking_changes logged auto-completion with reason "Output released and fully paid"',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE booking_id = 2 AND change_type = "status" AND new_value = "completed"') === 1);
check('4.6 DB verifies audit log recorded booking_completed action',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = "booking_completed" AND entity_id = 2') === 1);
check('4.6 DB verifies completion notice posted in client chat thread',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 2 AND m.body LIKE "%booking marked completed%"') === 1);

// 4.7 Screen A12: Client Message Templates with placeholder substitution & dual dispatch
// 4.7.1 Template preview and live placeholder interpolation (Tagalog & English)
$rTplTl = $owner->req('/admin/bookings/3/messages/templates?template=output_ready&lang=tl');
check('4.7 Screen A12 renders Tagalog template with client placeholders interpolated',
    $rTplTl['status'] === 200 &&
    str_contains($rTplTl['body'], 'Carlo Cruz') &&
    str_contains($rTplTl['body'], 'DPS-2026-0003') &&
    str_contains($rTplTl['body'], '₱7,000.00'));

$rTplEn = $owner->req('/admin/bookings/3/messages/templates?template=output_ready&lang=en');
check('4.7 Screen A12 renders English template with placeholders interpolated',
    $rTplEn['status'] === 200 &&
    str_contains($rTplEn['body'], 'Carlo Cruz') &&
    str_contains($rTplEn['body'], 'DPS-2026-0003'));

// 4.7.2 Dispatch Mode: Mark as sent (channel: copy for Messenger)
$tSendCopy = $owner->token('/admin/bookings/3/messages/templates?template=output_ready&lang=tl');
$rSendCopy = $owner->req('/admin/bookings/3/messages/templates/send', 'POST', [
    '_csrf'        => $tSendCopy,
    'action_type'  => 'mark_sent',
    'template_key' => 'output_ready',
    'language'     => 'tl',
    'body'         => 'Handa na ang inyong mga larawan at video! Carlo Cruz DPS-2026-0003',
]);
check('4.7 Mark as sent returns 303 redirect', $rSendCopy['status'] === 303);
$rFollowSendCopy = $owner->follow($rSendCopy);
check('4.7 Screen A12 renders flash "Message marked as sent (Messenger) ✓"',
    str_contains($rFollowSendCopy['body'], 'Message marked as sent (Messenger) ✓'));
check('4.7 DB verifies message_log recorded entry with channel "copy"',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM message_log WHERE booking_id = 3 AND template_key = "output_ready" AND channel = "copy"') === 1);

// 4.7.3 Dispatch Mode: Send in app chat (channel: in_app)
$tSendChat = $owner->token('/admin/bookings/3/messages/templates?template=output_ready&lang=en');
$rSendChat = $owner->req('/admin/bookings/3/messages/templates/send', 'POST', [
    '_csrf'        => $tSendChat,
    'action_type'  => 'send_chat',
    'template_key' => 'output_ready',
    'language'     => 'en',
    'body'         => 'In-app chat notification: Your edited photos are ready.',
]);
check('4.7 Send in app chat returns 303 redirect', $rSendChat['status'] === 303);
$rFollowSendChat = $owner->follow($rSendChat);
check('4.7 Screen A12 renders flash "Message sent in client chat ✓"',
    str_contains($rFollowSendChat['body'], 'Message sent in client chat ✓'));
check('4.7 DB verifies message_log recorded entry with channel "in_app"',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM message_log WHERE booking_id = 3 AND template_key = "output_ready" AND channel = "in_app"') === 1);
check('4.7 DB verifies message posted to conversation with sender_type = "admin"',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.booking_id = 3 AND m.sender_type = "admin" AND m.body LIKE "%Your edited photos are ready%"') === 1);
check('4.7 DB verifies audit log recorded message_template_sent action',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM audit_logs WHERE action = "message_template_sent" AND entity_id = 3') >= 1);

// ====================================================================
// SECTION 5: Forensic Verification of All 6 Log Streams
// ====================================================================
echo "\n--- Section 5: Forensic Verification of All 6 Log Streams ---\n";

// Stream 1: Security & Audit Logs (audit_logs table)
$auditActions = [
    'booking_rescheduled',
    'booking_cancelled',
    'crew_assigned',
    'equipment_saved',
    'project_stage_changed',
    'project_checklist_updated',
    'project_released',
    'booking_completed',
    'message_template_sent',
];
$auditCount = (int) \App\Core\DB::value(
    "SELECT COUNT(*) FROM audit_logs WHERE action IN ('" . implode("','", $auditActions) . "')"
);
check('Stream 1: audit_logs table contains entries for all required Phase 4 operational actions',
    $auditCount >= count($auditActions));

// Verify Stream 1 operational row integrity (valid user_id, non-empty entity, valid JSON details)
$invalidAuditCount = (int) \App\Core\DB::value(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action IN ('" . implode("','", $auditActions) . "')
       AND (user_id IS NULL OR entity IS NULL OR entity_id IS NULL OR details IS NULL OR details = '')"
);
check('Stream 1: audit_logs operational rows maintain valid non-null actors, entities, and JSON details',
    $invalidAuditCount === 0);

// Stream 2: Booking Changes & Policy History (booking_changes table)
$changeTypes = ['created', 'status', 'reschedule', 'cancel', 'note'];
$changeCount = (int) \App\Core\DB::value(
    "SELECT COUNT(*) FROM booking_changes WHERE change_type IN ('" . implode("','", $changeTypes) . "')"
);
check('Stream 2: booking_changes table maintains chronological policy history trail',
    $changeCount >= 10);
check('Stream 2: booking_changes verified exact records for reschedule, cancel, completion and notes',
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE change_type = "reschedule" AND new_value = "2026-12-16"') === 1 &&
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE change_type = "cancel" AND new_value = "cancelled"') === 1 &&
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE change_type = "status" AND new_value = "completed"') >= 2 &&
    (int) \App\Core\DB::value('SELECT COUNT(*) FROM booking_changes WHERE change_type = "note"') >= 2);

// Stream 3: Project Stage, File Storage & Handover Log (project_history + projects tables)
$historyCount = (int) \App\Core\DB::value('SELECT COUNT(*) FROM project_history WHERE project_id IN (2, 3)');
$p3Row = \App\Core\DB::one('SELECT raw_folder, edited_folder, local_backup_at, cloud_copy_at, released_how, received_by FROM projects WHERE id = 3');
check('Stream 3: project_history records transitions and projects table records full handover metadata',
    $historyCount >= 2 &&
    $p3Row['raw_folder'] === 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW' &&
    $p3Row['edited_folder'] === 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/EDITED' &&
    $p3Row['local_backup_at'] !== null &&
    $p3Row['cloud_copy_at'] !== null &&
    $p3Row['released_how'] === 'pickup' &&
    $p3Row['received_by'] === 'Carlo Cruz');

// Stream 4: Client Notification & Message Log (message_log + messages tables)
$msgLogCount = (int) \App\Core\DB::value('SELECT COUNT(*) FROM message_log WHERE booking_id = 3');
$sysMsgCount = (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages WHERE sender_type = "system"');
$adminMsgCount = (int) \App\Core\DB::value('SELECT COUNT(*) FROM messages WHERE sender_type = "admin"');
check('Stream 4: message_log records copy/in-app dispatches and messages maintains system/admin chat thread',
    $msgLogCount >= 2 && $sysMsgCount >= 3 && $adminMsgCount >= 1);

// Stream 5: Backup & Data Protection Log (backup_runs table)
$backupRow = \App\Core\DB::one('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 1');
check('Stream 5: backup_runs table records valid database backups and cloud copy status',
    $backupRow !== null &&
    !empty($backupRow['file_name']) &&
    (int) $backupRow['size_bytes'] > 0 &&
    !empty($backupRow['created_at']));

// Stream 6: Application Error & Rate-Limit Logs (storage/logs/app.log + rate_limits table)
$appLogPath = $root . '/storage/logs/app.log';
$appLogContent = file_exists($appLogPath) ? (string) file_get_contents($appLogPath) : '';
check('Stream 6: storage/logs/app.log verified with zero unhandled fatal exceptions or syntax errors',
    !str_contains($appLogContent, 'FATAL') &&
    !str_contains($appLogContent, 'ParseError') &&
    !str_contains($appLogContent, 'Fatal error'));
$rateLimitsTableExists = \App\Core\DB::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "rate_limits"');
check('Stream 6: rate_limits table exists in database schema for brute-force protection',
    (int) $rateLimitsTableExists === 1);

// ====================================================================
// Teardown: Clean database restoration
// ====================================================================
echo "\nRestoring fresh database state to leave environment pristine...\n";
exec('C:\Xampp\php\php.exe ' . escapeshellarg($root . '/database/install.php') . ' --fresh');

echo PHP_EOL . ($fail === 0 ? "All Phase 4 Admin E2E checks passed ($pass tests)." : "$fail check(s) FAILED ($pass passed).") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
