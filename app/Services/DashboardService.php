<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;

/**
 * Screen A2: Dashboard Service.
 * Provides live summary metrics, the 6 "Needs attention" studio alert rules,
 * and persistent backup status tracking.
 */
final class DashboardService
{
    /**
     * Four live summary tile metrics for Screen A2:
     * - events_today: Confirmed or completed bookings on Clock::today()
     * - payments_to_verify: Payments in 'pending' status for active bookings
     * - balances_due: Confirmed bookings with unpaid balance > 0
     * - outputs_overdue: Projects where due_date < Clock::today() and stage != 'released'
     *
     * @return array{events_today: int, payments_to_verify: int, balances_due: int, outputs_overdue: int}
     */
    public static function getMetrics(): array
    {
        $today = Clock::today();

        $eventsToday = (int) DB::value(
            "SELECT COUNT(*) FROM bookings
             WHERE event_date = ?
               AND status IN ('confirmed', 'completed')
               AND is_archived = 0",
            [$today]
        );

        $paymentsToVerify = (int) DB::value(
            "SELECT COUNT(*) FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             WHERE p.status = 'pending'
               AND b.is_archived = 0"
        );

        $balancesDue = (int) DB::value(
            "SELECT COUNT(*)
             FROM bookings b
             LEFT JOIN (
                 SELECT booking_id, SUM(amount) AS paid_amount
                 FROM payments WHERE status = 'verified' GROUP BY booking_id
             ) vp ON vp.booking_id = b.id
             WHERE b.status = 'confirmed'
               AND b.is_archived = 0
               AND (b.total_amount - COALESCE(vp.paid_amount, 0)) > 0.009"
        );

        $outputsOverdue = (int) DB::value(
            "SELECT COUNT(*) FROM projects p
             JOIN bookings b ON b.id = p.booking_id
             WHERE p.due_date < ?
               AND p.stage <> 'released'
               AND b.is_archived = 0",
            [$today]
        );

        return [
            'events_today'       => $eventsToday,
            'payments_to_verify' => $paymentsToVerify,
            'balances_due'       => $balancesDue,
            'outputs_overdue'    => $outputsOverdue,
        ];
    }

    /**
     * Six "Needs attention" alert rules per PROMPT §9.7 & DISPATCH:
     * 1. Unverified payments
     * 2. Crew shortfalls on upcoming multi-event dates (within 7 days)
     * 3. Overdue deliverable outputs
     * 4. Balances due on upcoming events (within 7 days)
     * 5. Stale inquiries / pending requests (>48h) or unread client messages (>1h)
     * 6. Backup age warning (last backup > 7 days ago or never)
     *
     * @return array<int, array{type: string, title: string, badge: string, badge_class: string, message: string, url: string, meta?: string}>
     */
    public static function getAlerts(): array
    {
        $alerts = [];
        $today = Clock::today();
        $now = Clock::now();

        // 1. Unverified payments
        $pendingPayments = DB::all(
            "SELECT p.id, p.amount, p.created_at, b.reference_code, c.full_name
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN clients c ON c.id = b.client_id
             WHERE p.status = 'pending' AND b.is_archived = 0
             ORDER BY p.created_at ASC"
        );
        if (!empty($pendingPayments)) {
            $count = count($pendingPayments);
            $oldest = $pendingPayments[0];
            $isOver24h = (strtotime($now) - strtotime((string) $oldest['created_at'])) > 86400;
            $msg = $count === 1
                ? "Payment of ₱" . number_format((float) $oldest['amount'], 2) . " for {$oldest['full_name']} ({$oldest['reference_code']}) is waiting verification" . ($isOver24h ? " (>24h)." : ".")
                : "{$count} payments waiting verification (oldest from " . date('M j', strtotime((string) $oldest['created_at'])) . ($isOver24h ? ", >24h" : "") . ").";

            $alerts[] = [
                'type'        => 'payment_pending',
                'title'       => 'Payments to verify',
                'badge'       => 'Payments',
                'badge_class' => 'pending',
                'message'     => $msg,
                'url'         => '/admin/payments',
                'meta'        => $isOver24h ? '>24h pending' : null,
            ];
        }

        // 2. Crew shortfalls: Scan active dates from today to today + 7 days
        $upcomingDates = DB::all(
            "SELECT DISTINCT event_date
             FROM bookings
             WHERE event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
               AND status IN ('confirmed', 'pending')
               AND is_archived = 0
             ORDER BY event_date ASC",
            [$today, $today]
        );
        foreach ($upcomingDates as $d) {
            $dateStr = (string) $d['event_date'];
            $schedule = CrewService::getScheduleForDate($dateStr);
            if ($schedule['has_shortage']) {
                $alerts[] = [
                    'type'        => 'crew_shortfall',
                    'title'       => 'Crew shortfall on ' . date('M j', strtotime($dateStr)),
                    'badge'       => 'Conflict',
                    'badge_class' => 'conflict',
                    'message'     => $schedule['shortage_message'] ?? 'One or more events have no crew assigned.',
                    'url'         => '/admin/crew?date=' . urlencode($dateStr),
                    'meta'        => date('D, M j', strtotime($dateStr)),
                ];
            }
        }

        // 3. Overdue deliverable outputs
        $overdueProjects = DB::all(
            "SELECT p.id, p.due_date, p.stage, b.reference_code, b.event_type, c.full_name
             FROM projects p
             JOIN bookings b ON b.id = p.booking_id
             JOIN clients c ON c.id = b.client_id
             WHERE p.due_date < ? AND p.stage <> 'released' AND b.is_archived = 0
             ORDER BY p.due_date ASC",
            [$today]
        );
        foreach ($overdueProjects as $op) {
            $days = max(1, (int) round((strtotime($today) - strtotime((string) $op['due_date'])) / 86400));
            $dayWord = $days === 1 ? 'day' : 'days';
            $alerts[] = [
                'type'        => 'output_overdue',
                'title'       => "Deliverable overdue: {$op['full_name']}",
                'badge'       => 'Overdue',
                'badge_class' => 'overdue',
                'message'     => "{$op['full_name']} ({$op['event_type']}) output was due on " . date('M j, Y', strtotime((string) $op['due_date'])) . " ({$days} {$dayWord} overdue).",
                'url'         => '/admin/projects/' . (int) $op['id'] . '/files',
                'meta'        => "{$days}d overdue",
            ];
        }

        // 4. Upcoming event balances due within 7 days
        $upcomingBalances = DB::all(
            "SELECT b.id, b.reference_code, b.event_type, b.event_date, b.total_amount,
                    c.full_name, GREATEST(0, b.total_amount - COALESCE(vp.paid_amount, 0)) AS balance_amount
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             LEFT JOIN (
                 SELECT booking_id, SUM(amount) AS paid_amount
                 FROM payments WHERE status = 'verified' GROUP BY booking_id
             ) vp ON vp.booking_id = b.id
             WHERE b.status = 'confirmed'
               AND b.is_archived = 0
               AND b.event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
               AND (b.total_amount - COALESCE(vp.paid_amount, 0)) > 0.009
             ORDER BY b.event_date ASC",
            [$today, $today]
        );
        foreach ($upcomingBalances as $ub) {
            $balFmt = '₱' . number_format((float) $ub['balance_amount'], 2);
            $dateLabel = ($ub['event_date'] === $today) ? 'today' : 'on ' . date('M j', strtotime((string) $ub['event_date']));
            $alerts[] = [
                'type'        => 'balance_due',
                'title'       => "Balance due: {$ub['full_name']}",
                'badge'       => 'Balance due',
                'badge_class' => 'balance',
                'message'     => "{$ub['full_name']} ({$ub['event_type']} {$dateLabel}) has {$balFmt} balance due.",
                'url'         => '/admin/bookings/' . (int) $ub['id'],
                'meta'        => $balFmt,
            ];
        }

        // 5. Stale pending bookings (>48h) & unread client messages (>1h)
        $staleBookings = DB::all(
            "SELECT b.id, b.reference_code, b.created_at, c.full_name
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             WHERE b.status = 'pending'
               AND b.is_archived = 0
               AND b.created_at <= DATE_SUB(?, INTERVAL 48 HOUR)
             ORDER BY b.created_at ASC",
            [$now]
        );
        foreach ($staleBookings as $sb) {
            $hours = (int) round((strtotime($now) - strtotime((string) $sb['created_at'])) / 3600);
            $alerts[] = [
                'type'        => 'stale_pending',
                'title'       => "Stale pending booking: {$sb['reference_code']}",
                'badge'       => 'Pending',
                'badge_class' => 'pending',
                'message'     => "Booking request for {$sb['full_name']} has been pending for {$hours} hours.",
                'url'         => '/admin/payments',
                'meta'        => "{$hours}h pending",
            ];
        }

        $unreadClientMessages = (int) DB::value(
            "SELECT COUNT(*) FROM messages
             WHERE sender_type = 'client'
               AND read_at IS NULL
               AND created_at <= DATE_SUB(?, INTERVAL 1 HOUR)",
            [$now]
        );
        if ($unreadClientMessages > 0) {
            $alerts[] = [
                'type'        => 'unread_messages',
                'title'       => 'Unread client messages',
                'badge'       => 'Unread',
                'badge_class' => 'info',
                'message'     => "{$unreadClientMessages} client message" . ($unreadClientMessages === 1 ? ' is' : 's are') . " unread for over 1 hour.",
                'url'         => '/admin/messages',
                'meta'        => "{$unreadClientMessages} unread",
            ];
        }

        // 6. Backup age warning (>7 days or never)
        $backupStatus = self::getBackupStatus();
        if ($backupStatus['is_stale']) {
            $alerts[] = [
                'type'        => 'backup_warning',
                'title'       => 'System backup overdue',
                'badge'       => 'Backup',
                'badge_class' => 'overdue',
                'message'     => $backupStatus['last_backup_at'] === null
                    ? 'No database backup has been recorded yet.'
                    : "Last backup was performed {$backupStatus['days_ago']} days ago (older than 7 days).",
                'url'         => '/admin/settings',
                'meta'        => $backupStatus['days_ago'] !== null ? "{$backupStatus['days_ago']}d ago" : 'No backup',
            ];
        }

        return $alerts;
    }

    /**
     * Backup status evaluation for Screen A2:
     * Queries backup_runs for the most recent entry and computes staleness (>7 days).
     *
     * @return array{
     *     last_backup_at: ?string,
     *     last_backup_date: ?string,
     *     days_ago: ?int,
     *     is_stale: bool,
     *     status_line: string,
     *     type: ?string,
     *     file_name: ?string,
     *     size_bytes: int
     * }
     */
    public static function getBackupStatus(): array
    {
        $today = Clock::today();
        $latest = DB::one("SELECT * FROM backup_runs ORDER BY created_at DESC, id DESC LIMIT 1");

        if (!$latest) {
            return [
                'last_backup_at'   => null,
                'last_backup_date' => null,
                'days_ago'         => null,
                'is_stale'         => true,
                'status_line'      => 'No backup on record ⚠',
                'type'             => null,
                'file_name'        => null,
                'size_bytes'       => 0,
            ];
        }

        $backupDate = substr((string) $latest['created_at'], 0, 10);
        $daysAgo = max(0, (int) round((strtotime($today) - strtotime($backupDate)) / 86400));
        $isStale = ($daysAgo > 7);

        $formattedDate = date('M j, Y', strtotime($backupDate));
        $statusLine = $isStale
            ? "Last backup: {$formattedDate} ({$daysAgo} days ago) ⚠"
            : "Last backup: {$formattedDate} ✓";

        return [
            'last_backup_at'   => (string) $latest['created_at'],
            'last_backup_date' => $backupDate,
            'days_ago'         => $daysAgo,
            'is_stale'         => $isStale,
            'status_line'      => $statusLine,
            'type'             => isset($latest['type']) ? (string) $latest['type'] : null,
            'file_name'        => isset($latest['file_name']) ? (string) $latest['file_name'] : null,
            'size_bytes'       => isset($latest['size_bytes']) ? (int) $latest['size_bytes'] : 0,
        ];
    }
}
