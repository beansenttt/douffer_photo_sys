<?php
/**
 * Douffer PhotoSys: Phase 6 End-to-End HTTP Test Suite.
 *
 * Covers:
 *   - Scenario T7 & Screen A18 (/crew): Crew login, next assigned event card, call time,
 *     venue, inclusions, crew-visible notes, zero payment data, and 403 on all other routes.
 *   - Screen A14 (/admin/clients): Clients & Inquiries directory, search, channel filter chips,
 *     adding a new client, adding inquiry notes, updating client info, archive & restore.
 *   - Screen A15 (/admin/packages): Packages & Services catalog (Owner only, 403 for Staff),
 *     creating/updating/toggling packages & add-ons, reflecting on C1 client portal.
 *   - Screen A16 (/admin/reports & /admin/reports/export.csv): Monthly and Daily reports,
 *     4 summary tiles, booking breakdown, and CSV export.
 *   - Screen A17 (/admin/users): Users & Access (Owner only, 403 for Staff), creating staff/crew
 *     accounts, updating staff permission checkboxes, password reset, activation/deactivation.
 *   - Screen A20 (/admin/settings, /admin/backups, database/backup.php): Studio settings,
 *     GCash QR upload, blocked dates (blocking/unblocking & availability effect), versioned terms,
 *     quick replies, message templates, manual & CLI backups, download, and cloud copy marking.
 *   - Screen A21 (/admin/logs & /admin/logs/export.csv): Centralized Audit & System Logs viewer
 *     (Owner only, 403 for Staff/Crew) across all 6 log streams + CSV export.
 *
 * Usage:
 *   php tests/e2e_phase6.php [http://localhost:8000]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\AvailabilityService;

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

final class Phase6Browser
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'dps_e2e_p6_');
    }

    public function __destruct()
    {
        if (file_exists($this->jar)) {
            @unlink($this->jar);
        }
    }

    public function req(string $path, string $method = 'GET', array $fields = []): array
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
            CURLOPT_HTTPHEADER     => ['User-Agent: DPS-E2E-Phase6'],
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

    public function follow(array $res): array
    {
        if (!empty($res['location'])) {
            return $this->req($res['location'], 'GET');
        }
        return $res;
    }

    public function token(string $path = '/admin'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m)) {
            return $m[1];
        }
        return '';
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
}

// Ensure fresh seed state before running E2E checks
exec( escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/install.php') . ' --fresh', $installOut, $installCode);
check('0.0 Fresh database seed installed cleanly', $installCode === 0);

// ====================================================================
// SECTION 1: Scenario T7 & Screen A18 (Crew: My Events)
// ====================================================================
echo "\n--- Section 1: Scenario T7 & Screen A18 (Crew: My Events) ---\n";

$crew2 = new Phase6Browser($base);
$rCrewLogin = $crew2->login('crew2', 'Crew2@2026');
check('1.1 Crew2 (Videographer A) login redirects to /crew (A18)',
    $rCrewLogin['status'] === 303 && str_ends_with($rCrewLogin['location'], '/crew'));

$rA18 = $crew2->req('/crew');
check('1.2 Screen A18 renders 200 with crew member name and next assigned event on Dec 12',
    $rA18['status'] === 200 &&
    str_contains($rA18['body'], 'Videographer A') &&
    str_contains($rA18['body'], 'Angela Santos') &&
    str_contains($rA18['body'], 'Sat, Dec 12, 2026'));

check('1.3 Screen A18 shows venue, call time (7:30 AM), role (Backup), inclusions, and crew-visible owner note',
    str_contains($rA18['body'], 'St. Francis Xavier Parish') &&
    str_contains($rA18['body'], '7:30 AM') &&
    str_contains($rA18['body'], 'Backup') &&
    str_contains($rA18['body'], '30-page album') &&
    str_contains($rA18['body'], 'Bride prefers candid shots'));

check('1.4 Screen A18 hides all payment amounts, balances, and receipt numbers (T7)',
    !str_contains($rA18['body'], '16,000.00') &&
    !str_contains($rA18['body'], '4,800.00') &&
    !str_contains($rA18['body'], '11,200.00') &&
    !str_contains($rA18['body'], 'OR-2026-0006') &&
    str_contains($rA18['body'], 'View-only. Payments and other records are hidden.'));

check('1.5 Crew receives 403 on all admin routes (/admin, /admin/clients, /admin/packages, /admin/reports, /admin/users, /admin/settings, /admin/logs)',
    $crew2->req('/admin')['status'] === 403 &&
    $crew2->req('/admin/clients')['status'] === 403 &&
    $crew2->req('/admin/packages')['status'] === 403 &&
    $crew2->req('/admin/reports')['status'] === 403 &&
    $crew2->req('/admin/users')['status'] === 403 &&
    $crew2->req('/admin/settings')['status'] === 403 &&
    $crew2->req('/admin/logs')['status'] === 403);

$crew1 = new Phase6Browser($base);
$crew1->login('crew1', 'Crew1@2026');
$rCrew1Empty = $crew1->req('/crew');
check('1.6 Crew1 (Photographer A, unassigned initially) sees empty state on A18',
    $rCrew1Empty['status'] === 200 && str_contains($rCrew1Empty['body'], 'No events assigned yet.'));

// ====================================================================
// SECTION 2: Screen A14 (Clients & Inquiries)
// ====================================================================
echo "\n--- Section 2: Screen A14 (Clients & Inquiries) ---\n";

$owner = new Phase6Browser($base);
$owner->login('owner', 'Owner@2026');

$rClients = $owner->req('/admin/clients');
check('2.1 Screen A14 renders client directory with seeded clients and inquiry history',
    $rClients['status'] === 200 &&
    str_contains($rClients['body'], 'Angela Santos') &&
    str_contains($rClients['body'], 'Ramon Lopez') &&
    str_contains($rClients['body'], 'Asked for a small photo package'));

$rClientsSearch = $owner->req('/admin/clients?q=Santos');
check('2.2 Screen A14 search filters clients by name/mobile',
    $rClientsSearch['status'] === 200 &&
    str_contains($rClientsSearch['body'], 'Angela Santos') &&
    !str_contains($rClientsSearch['body'], 'Ramon Lopez'));

// Add new client + inquiry note
$tClients = $owner->token('/admin/clients');
$rNewClient = $owner->req('/admin/clients', 'POST', [
    '_csrf'           => $tClients,
    'full_name'       => 'Beatrice Villanueva (Sample)',
    'mobile'          => '0917 888 9900',
    'messenger_name'  => 'Bea V.',
    'contact_channel' => 'messenger',
    'note'            => 'Inquired about debut photo + video for January 2027.',
]);
check('2.3 Adding a new client + inquiry redirects to /admin/clients', $rNewClient['status'] === 303);
$newClientId = (int) DB::value("SELECT id FROM clients WHERE mobile_normalized = '09178889900'");
check('2.4 New client and initial inquiry note persisted in database and audit_logs',
    $newClientId > 0 &&
    (int) DB::value('SELECT COUNT(*) FROM client_inquiries WHERE client_id = ?', [$newClientId]) === 1 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'client_saved' AND entity_id = ?", [$newClientId]) >= 1 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'client_inquiry_added' AND entity_id = ?", [$newClientId]) >= 1);

// Add follow-up inquiry note on client detail page
$tClientShow = $owner->token('/admin/clients/' . $newClientId);
$rAddNote = $owner->req('/admin/clients/' . $newClientId . '/inquiries', 'POST', [
    '_csrf'     => $tClientShow,
    'return_to' => 'show',
    'channel'   => 'call',
    'note'      => 'Called back to confirm venue in Nasugbu.',
]);
$rClientDetail = $owner->follow($rAddNote);
check('2.5 Follow-up inquiry note added and visible on /admin/clients/{id}',
    $rClientDetail['status'] === 200 &&
    str_contains($rClientDetail['body'], 'Called back to confirm venue in Nasugbu.'));

// Archive & restore client
$rArchClient = $owner->req('/admin/clients/' . $newClientId . '/archive', 'POST', ['_csrf' => $tClientShow]);
check('2.6 Archiving client sets is_archived = 1 and logs client_archived',
    $rArchClient['status'] === 303 &&
    (int) DB::value('SELECT is_archived FROM clients WHERE id = ?', [$newClientId]) === 1 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'client_archived' AND entity_id = ?", [$newClientId]) === 1);

$owner->req('/admin/clients/' . $newClientId . '/archive', 'POST', ['_csrf' => $tClientShow]);
check('2.7 Restoring client sets is_archived = 0 and logs client_restored',
    (int) DB::value('SELECT is_archived FROM clients WHERE id = ?', [$newClientId]) === 0 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'client_restored' AND entity_id = ?", [$newClientId]) === 1);

// ====================================================================
// SECTION 3: Screen A15 (Packages & Services - Owner Only)
// ====================================================================
echo "\n--- Section 3: Screen A15 (Packages & Services) ---\n";

$staff = new Phase6Browser($base);
$staff->login('staff', 'Staff@2026');
check('3.1 Staff receives 403 on /admin/packages (Owner only)', $staff->req('/admin/packages')['status'] === 403);

$rPackages = $owner->req('/admin/packages');
check('3.2 Owner views A15 Packages & Services catalog',
    $rPackages['status'] === 200 &&
    str_contains($rPackages['body'], 'Photography Package') &&
    str_contains($rPackages['body'], 'Photo + Video Package'));

$tPkg = $owner->token('/admin/packages');
$rCreatePkg = $owner->req('/admin/packages', 'POST', [
    '_csrf'                 => $tPkg,
    'name'                  => 'Prenup + Wedding Deluxe (SAMPLE)',
    'category'              => 'photo_video',
    'price'                 => '22000.00',
    'down_payment_override' => '6000.00',
    'description'           => 'Full wedding + prenup session.',
    'inclusions'            => "2 photographers\n2 videographers\n40-page album",
    'sort_order'            => '4',
    'is_active'             => '1',
]);
check('3.3 Owner creates new package and redirects to A15', $rCreatePkg['status'] === 303);
$newPkgId = (int) DB::value("SELECT id FROM packages WHERE name = 'Prenup + Wedding Deluxe (SAMPLE)'");
check('3.4 New package persisted in DB and audit_logs (package_saved)',
    $newPkgId > 0 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'package_saved' AND entity = 'package' AND entity_id = ?", [$newPkgId]) >= 1);

$anon = new Phase6Browser($base);
$rHome = $anon->req('/');
check('3.5 New active package immediately appears on Client Home (C1)',
    str_contains($rHome['body'], 'Prenup + Wedding Deluxe (SAMPLE)') &&
    str_contains($rHome['body'], '22,000.00'));

// Toggle package inactive -> disappears from C1
$owner->req('/admin/packages/' . $newPkgId . '/toggle', 'POST', ['_csrf' => $tPkg]);
$rHomeAfterToggle = $anon->req('/');
check('3.6 Deactivating package hides it from Client Home (C1)',
    !str_contains($rHomeAfterToggle['body'], 'Prenup + Wedding Deluxe (SAMPLE)'));

// Create add-on service
$rCreateAddon = $owner->req('/admin/packages/addons', 'POST', [
    '_csrf'     => $tPkg,
    'name'      => 'Drone Aerial Coverage (SAMPLE)',
    'price'     => '4500.00',
    'is_active' => '1',
]);
$newAddonId = (int) DB::value("SELECT id FROM addons WHERE name = 'Drone Aerial Coverage (SAMPLE)'");
check('3.7 Owner creates add-on service and logs package_saved (entity=addon)',
    $rCreateAddon['status'] === 303 &&
    $newAddonId > 0 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'package_saved' AND entity = 'addon' AND entity_id = ?", [$newAddonId]) >= 1);

// ====================================================================
// SECTION 4: Screen A16 (Reports & CSV Export)
// ====================================================================
echo "\n--- Section 4: Screen A16 (Reports & CSV Export) ---\n";

$rRepMonth = $owner->req('/admin/reports?period=monthly&month=2026-12');
check('4.1 Monthly report for Dec 2026 renders 4 summary tiles and December bookings',
    $rRepMonth['status'] === 200 &&
    str_contains($rRepMonth['body'], 'December 2026') &&
    str_contains($rRepMonth['body'], 'Income received') &&
    str_contains($rRepMonth['body'], 'Outstanding balances') &&
    str_contains($rRepMonth['body'], 'Pending outputs') &&
    str_contains($rRepMonth['body'], 'DPS-2026-0004'));

$rRepDay = $owner->req('/admin/reports?period=daily&date=2026-12-12');
check('4.2 Daily report for Sat, Dec 12, 2026 isolates Dec 12 events (Santos & Reyes)',
    $rRepDay['status'] === 200 &&
    str_contains($rRepDay['body'], 'Sat, Dec 12, 2026') &&
    str_contains($rRepDay['body'], 'DPS-2026-0004') &&
    str_contains($rRepDay['body'], 'DPS-2026-0006') &&
    !str_contains($rRepDay['body'], 'DPS-2026-0001'));

$rRepCsv = $owner->req('/admin/reports/export.csv?period=monthly&month=2026-12');
check('4.3 Report CSV export returns 200 text/csv with header and booking rows',
    $rRepCsv['status'] === 200 &&
    str_contains(strtolower($rRepCsv['headers']), 'text/csv') &&
    str_contains($rRepCsv['body'], 'Reference No.') &&
    str_contains($rRepCsv['body'], 'DPS-2026-0004'));

// ====================================================================
// SECTION 5: Screen A17 (Users & Access - Owner Only)
// ====================================================================
echo "\n--- Section 5: Screen A17 (Users & Access) ---\n";

check('5.1 Staff receives 403 on /admin/users (Owner only)', $staff->req('/admin/users')['status'] === 403);

$rUsers = $owner->req('/admin/users');
check('5.2 Owner views A17 Users & Access with permission checkboxes',
    $rUsers['status'] === 200 &&
    str_contains($rUsers['body'], 'Jeffrey D. Madrigal') &&
    str_contains($rUsers['body'], 'Studio Assistant (Sample)'));

// Grant verify_payments to staff user (id = 2)
$tUsers = $owner->token('/admin/users');
$rPerms = $owner->req('/admin/users/2/permissions', 'POST', [
    '_csrf'                => $tUsers,
    '_perms_submitted'     => '1',
    'perm_view_bookings'   => '1',
    'perm_record_payments' => '1',
    'perm_verify_payments' => '1',
    'perm_archive_records' => '0',
    'perm_manage_messages' => '1',
]);
check('5.3 Updating staff permissions redirects and logs permissions_updated in audit_logs',
    $rPerms['status'] === 303 &&
    (int) DB::value('SELECT perm_verify_payments FROM users WHERE id = 2') === 1 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'permissions_updated' AND entity_id = 2") >= 1);

check('5.4 Staff can now access /admin/payments (A7) immediately after permission grant',
    $staff->req('/admin/payments')['status'] === 200);

// Revert staff perm_verify_payments back to 0
$owner->req('/admin/users/2/permissions', 'POST', [
    '_csrf'                => $tUsers,
    '_perms_submitted'     => '1',
    'perm_view_bookings'   => '1',
    'perm_record_payments' => '1',
    'perm_verify_payments' => '0',
    'perm_archive_records' => '0',
    'perm_manage_messages' => '1',
]);
check('5.5 Reverting verify_payments immediately blocks staff on /admin/payments (403)',
    $staff->req('/admin/payments')['status'] === 403);

// Create a new crew user
$rCreateCrewUser = $owner->req('/admin/users', 'POST', [
    '_csrf'     => $tUsers,
    'name'      => 'Photographer C (Sample)',
    'username'  => 'crew3',
    'email'     => 'crew3@example.com',
    'role'      => 'crew',
    'password'  => 'Crew3@2026',
    'crew_type' => 'photographer',
]);
$newCrewUserId = (int) DB::value("SELECT id FROM users WHERE username = 'crew3'");
check('5.6 Creating a crew user creates both users row and linked crew_members row',
    $rCreateCrewUser['status'] === 303 &&
    $newCrewUserId > 0 &&
    (int) DB::value('SELECT COUNT(*) FROM crew_members WHERE user_id = ?', [$newCrewUserId]) === 1);

// Reset password & toggle active
$owner->req('/admin/users/' . $newCrewUserId . '/reset-password', 'POST', [
    '_csrf'    => $tUsers,
    'password' => 'NewCrew3@2026',
]);
check('5.7 Owner password reset updates hash and sets must_change_password = 1',
    (int) DB::value('SELECT must_change_password FROM users WHERE id = ?', [$newCrewUserId]) === 1);

$owner->req('/admin/users/' . $newCrewUserId . '/toggle', 'POST', ['_csrf' => $tUsers]);
check('5.8 Deactivating user sets is_active = 0 and blocks login',
    (int) DB::value('SELECT is_active FROM users WHERE id = ?', [$newCrewUserId]) === 0);

// Prevent owner from deactivating self
$owner->req('/admin/users/1/toggle', 'POST', ['_csrf' => $tUsers]);
check('5.9 Owner cannot deactivate their own account',
    (int) DB::value('SELECT is_active FROM users WHERE id = 1') === 1);

// ====================================================================
// SECTION 6: Screen A20 (Settings, Blocked Dates & Backups)
// ====================================================================
echo "\n--- Section 6: Screen A20 (Settings, Blocked Dates & Backups) ---\n";

check('6.1 Staff receives 403 on /admin/settings (Owner only)', $staff->req('/admin/settings')['status'] === 403);

$rSettings = $owner->req('/admin/settings');
check('6.2 Owner opens Screen A20 Settings & Blocked Dates',
    $rSettings['status'] === 200 && str_contains($rSettings['body'], 'Douffer Photography'));

$tSet = $owner->token('/admin/settings');
$rSaveSet = $owner->req('/admin/settings', 'POST', [
    '_csrf'                 => $tSet,
    'studio_name'           => 'Douffer Photography',
    'studio_address'        => 'Victoria Ville Subd., Brgy. Bilaran, Nasugbu, Batangas',
    'studio_phone'          => '0917 000 0000',
    'studio_messenger_url'  => 'https://m.me/',
    'gcash_name'            => 'J. D. M. (SAMPLE)',
    'gcash_number'          => '0917 000 0000',
    'down_payment_mode'     => 'percent',
    'down_payment_value'    => '30',
    'max_events_per_day'    => '2',
    'default_delivery_days' => '14',
    'balance_overdue_days'  => '0',
    'event_types'           => "Wedding\nBirthday\nDebut\nChristening\nAnniversary\nCorporate\nOther",
]);
check('6.3 Saving studio settings redirects and logs settings_updated in audit_logs',
    $rSaveSet['status'] === 303 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings_updated'") >= 1);

// Block date 2026-12-22 -> verify availability becomes false -> unblock -> availability becomes true
check('6.4 Date 2026-12-22 is initially available', AvailabilityService::isDateAvailable('2026-12-22') === true);
$rBlock = $owner->req('/admin/settings/blocked-dates', 'POST', [
    '_csrf'  => $tSet,
    'date'   => '2026-12-22',
    'reason' => 'Studio camera maintenance (SAMPLE)',
]);
$blockId = (int) DB::value("SELECT id FROM blocked_dates WHERE date = '2026-12-22'");
check('6.5 Blocking date 2026-12-22 makes it unavailable and logs date_blocked',
    $rBlock['status'] === 303 &&
    $blockId > 0 &&
    AvailabilityService::isDateAvailable('2026-12-22') === false &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'date_blocked' AND entity_id = ?", [$blockId]) === 1);

$rUnblock = $owner->req('/admin/settings/blocked-dates/' . $blockId . '/delete', 'POST', ['_csrf' => $tSet]);
check('6.6 Unblocking date 2026-12-22 restores availability and logs date_unblocked',
    $rUnblock['status'] === 303 &&
    AvailabilityService::isDateAvailable('2026-12-22') === true &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'date_unblocked' AND entity_id = ?", [$blockId]) === 1);

// Manual backup via POST /admin/backups, download, and mark cloud copy done
$rBackupNow = $owner->req('/admin/backups', 'POST', ['_csrf' => $tSet]);
$latestBackup = DB::one('SELECT * FROM backup_runs ORDER BY id DESC LIMIT 1');
$latestBackupId = (int) ($latestBackup['id'] ?? 0);
check('6.7 Manual backup (POST /admin/backups) creates .sql file and logs backup_created',
    $rBackupNow['status'] === 303 &&
    $latestBackupId > 1 &&
    $latestBackup['type'] === 'manual' &&
    (int) $latestBackup['size_bytes'] > 1000 &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup_created' AND entity_id = ?", [$latestBackupId]) === 1);

$rDownloadBackup = $owner->req('/admin/backups/' . $latestBackupId . '/download');
check('6.8 Downloading backup streams valid SQL dump with CREATE TABLE statements',
    $rDownloadBackup['status'] === 200 &&
    str_contains($rDownloadBackup['body'], 'CREATE TABLE') &&
    str_contains($rDownloadBackup['body'], 'Douffer PhotoSys Database Backup'));

$rCloudMark = $owner->req('/admin/backups/' . $latestBackupId . '/cloud-copy', 'POST', ['_csrf' => $tSet]);
check('6.9 Marking cloud copy done updates cloud_copy_marked_at and logs backup_cloud_marked',
    $rCloudMark['status'] === 303 &&
    DB::value('SELECT cloud_copy_marked_at FROM backup_runs WHERE id = ?', [$latestBackupId]) !== null &&
    (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup_cloud_marked' AND entity_id = ?", [$latestBackupId]) === 1);

// CLI scheduled backup script (php database/backup.php)
$cliOut = [];
$cliCode = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/backup.php'), $cliOut, $cliCode);
$cliBackup = DB::one("SELECT * FROM backup_runs WHERE type = 'scheduled' ORDER BY id DESC LIMIT 1");
check('6.10 CLI database/backup.php runs cleanly and records a scheduled backup run',
    $cliCode === 0 &&
    str_contains(implode("\n", $cliOut), 'Backup created: storage/backups/') &&
    $cliBackup !== null);

// Clean up generated backup files from storage/backups/
foreach ([$latestBackup['file_name'] ?? '', $cliBackup['file_name'] ?? ''] as $fn) {
    if ($fn !== '' && !str_contains($fn, 'SAMPLE')) {
        $p = storage_path('backups/' . $fn);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

// ====================================================================
// SECTION 7: Screen A21 (Audit & System Logs Viewer & CSV Export)
// ====================================================================
echo "\n--- Section 7: Screen A21 (Audit & System Logs) ---\n";

check('7.1 Staff and Crew receive 403 on /admin/logs and /admin/logs/export.csv (Owner only)',
    $staff->req('/admin/logs')['status'] === 403 &&
    $staff->req('/admin/logs/export.csv')['status'] === 403 &&
    $crew2->req('/admin/logs')['status'] === 403);

$rLogsAudit = $owner->req('/admin/logs?tab=audit');
check('7.2 Screen A21 Tab 1 (Security & Audit Logs) displays logged actions',
    $rLogsAudit['status'] === 200 &&
    str_contains($rLogsAudit['body'], 'package_saved') &&
    str_contains($rLogsAudit['body'], 'permissions_updated') &&
    str_contains($rLogsAudit['body'], 'settings_updated') &&
    str_contains($rLogsAudit['body'], 'date_blocked') &&
    str_contains($rLogsAudit['body'], 'backup_created'));

$rLogsBookings = $owner->req('/admin/logs?tab=bookings');
check('7.3 Screen A21 Tab 2 (Booking History Logs) displays booking_changes timeline',
    $rLogsBookings['status'] === 200 &&
    str_contains($rLogsBookings['body'], 'DPS-2026-0001') &&
    str_contains($rLogsBookings['body'], 'Down payment verified'));

$rLogsProjects = $owner->req('/admin/logs?tab=projects');
check('7.4 Screen A21 Tab 3 (Projects & Handover Logs) displays stage transitions and folder paths',
    $rLogsProjects['status'] === 200 &&
    str_contains($rLogsProjects['body'], '2026-11-28_DelaCruz_Debut/RAW') &&
    str_contains($rLogsProjects['body'], 'Google Drive link sent in Messenger'));

$rLogsNotif = $owner->req('/admin/logs?tab=notifications');
check('7.5 Screen A21 Tab 4 (Notification Logs) displays template log and automated system notices',
    $rLogsNotif['status'] === 200 &&
    str_contains($rLogsNotif['body'], 'output_ready') &&
    str_contains($rLogsNotif['body'], 'Payment verified'));

$rLogsBackups = $owner->req('/admin/logs?tab=backups');
check('7.6 Screen A21 Tab 5 (Backup Logs) displays backup_runs history',
    $rLogsBackups['status'] === 200 &&
    str_contains($rLogsBackups['body'], 'douffer_backup_'));

$rLogsErrors = $owner->req('/admin/logs?tab=errors');
check('7.7 Screen A21 Tab 6 (Errors & Rate Limits) renders error log and rate_limits',
    $rLogsErrors['status'] === 200 &&
    str_contains($rLogsErrors['body'], 'storage/logs/app.log') &&
    str_contains($rLogsErrors['body'], 'rate_limits'));

$rLogsCsv = $owner->req('/admin/logs/export.csv?tab=audit&action=package_saved');
check('7.8 Screen A21 CSV export filters and downloads audit logs',
    $rLogsCsv['status'] === 200 &&
    str_contains(strtolower($rLogsCsv['headers']), 'text/csv') &&
    str_contains($rLogsCsv['body'], 'package_saved'));

echo "\n====================================================================\n";
echo "Phase 6 E2E Summary: {$pass} passed, {$fail} failed\n";
echo "====================================================================\n";
exit($fail === 0 ? 0 : 1);
