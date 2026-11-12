<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Screen A3: Booking Calendar Service.
 * Provides month grid capacity and status mapping (confirmed dot, 2+ full outline,
 * pending-only dashed outline, blocked dates) and day schedule inspection.
 */
final class CalendarService
{
    /**
     * Day-by-day month data for Screen A3:
     * Returns associative array keyed by 'YYYY-MM-DD'.
     *
     * @return array<string, array{
     *     date: string,
     *     day: int,
     *     day_of_week: int,
     *     confirmed_count: int,
     *     pending_count: int,
     *     is_full: bool,
     *     is_blocked: bool,
     *     blocked_reason: ?string,
     *     is_past: bool,
     *     is_today: bool,
     *     has_pending_only: bool,
     *     has_confirmed: bool,
     *     is_available: bool
     * }>
     */
    public static function getMonthData(int $year, int $month): array
    {
        $month = max(1, min(12, $month));
        $first = sprintf('%04d-%02d-01', $year, $month);
        $daysInMonth = (int) date('t', strtotime($first));
        $last = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // 1. Fetch blocked dates in month
        $blockedRows = DB::all(
            "SELECT `date`, `reason` FROM blocked_dates WHERE `date` BETWEEN ? AND ?",
            [$first, $last]
        );
        $blockedMap = [];
        foreach ($blockedRows as $b) {
            $blockedMap[(string) $b['date']] = isset($b['reason']) ? (string) $b['reason'] : 'Studio closed';
        }

        // 2. Fetch confirmed and pending counts grouped by date
        $counts = DB::all(
            "SELECT event_date,
                    SUM(CASE WHEN status IN ('confirmed', 'completed') THEN 1 ELSE 0 END) AS confirmed_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
             FROM bookings
             WHERE event_date BETWEEN ? AND ? AND is_archived = 0
             GROUP BY event_date",
            [$first, $last]
        );
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[(string) $c['event_date']] = [
                'confirmed' => (int) $c['confirmed_count'],
                'pending'   => (int) $c['pending_count'],
            ];
        }

        $maxEvents = max(1, Settings::int('max_events_per_day'));
        $today = Clock::today();
        $days = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $confirmed = $countMap[$date]['confirmed'] ?? 0;
            $pending = $countMap[$date]['pending'] ?? 0;
            $isBlocked = isset($blockedMap[$date]);
            $isPast = ($date < $today);
            $isFull = ($confirmed >= $maxEvents);

            $days[$date] = [
                'date'             => $date,
                'day'              => $d,
                'day_of_week'      => (int) date('w', strtotime($date)), // 0 = Sun .. 6 = Sat
                'confirmed_count'  => $confirmed,
                'pending_count'    => $pending,
                'is_full'          => $isFull,
                'is_blocked'       => $isBlocked,
                'blocked_reason'   => $blockedMap[$date] ?? null,
                'is_past'          => $isPast,
                'is_today'         => ($date === $today),
                'has_pending_only' => ($confirmed === 0 && $pending > 0),
                'has_confirmed'    => ($confirmed > 0),
                'is_available'     => (!$isPast && !$isBlocked && !$isFull),
            ];
        }

        return $days;
    }

    /**
     * Day inspection schedule for Screen A3:
     * Returns scheduled events on selected date with client, package, time, crew, and project status.
     *
     * @return array{
     *     date: string,
     *     date_formatted: string,
     *     is_today: bool,
     *     is_past: bool,
     *     is_blocked: bool,
     *     blocked_reason: ?string,
     *     confirmed_count: int,
     *     pending_count: int,
     *     total_events: int,
     *     is_full: bool,
     *     can_add_booking: bool,
     *     add_booking_url: string,
     *     has_crew_shortage: bool,
     *     crew_shortage_message: ?string,
     *     events: array<int, array>
     * }
     */
    public static function getDaySchedule(string $date): array
    {
        if (!Validator::isDate($date)) {
            $date = Clock::today();
        }

        $today = Clock::today();
        $isPast = ($date < $today);

        // Blocked check
        $blockedReason = DB::value("SELECT reason FROM blocked_dates WHERE `date` = ?", [$date]);
        $isBlocked = ($blockedReason !== null);

        // Fetch events on this date
        $rows = DB::all(
            "SELECT b.id, b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                    b.venue, b.status, b.total_amount, b.is_archived,
                    c.id AS client_id, c.full_name AS client_name, c.mobile AS client_mobile,
                    p.id AS package_id, p.name AS package_name,
                    pr.id AS project_id, pr.stage AS project_stage, pr.due_date AS project_due_date
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             LEFT JOIN projects pr ON pr.booking_id = b.id
             WHERE b.event_date = ?
               AND b.status IN ('confirmed', 'pending', 'completed')
               AND b.is_archived = 0
             ORDER BY b.start_time ASC, b.id ASC",
            [$date]
        );

        $events = [];
        $confirmedCount = 0;
        $pendingCount = 0;

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if ($status === 'confirmed' || $status === 'completed') {
                $confirmedCount++;
            } elseif ($status === 'pending') {
                $pendingCount++;
            }

            // Crew assignments
            $crew = DB::all(
                "SELECT ca.id AS assignment_id, ca.role, ca.call_time,
                        cm.id AS crew_member_id, cm.name AS crew_name, cm.type AS crew_type
                 FROM crew_assignments ca
                 JOIN crew_members cm ON cm.id = ca.crew_member_id
                 WHERE ca.booking_id = ?
                 ORDER BY ca.role = 'lead' DESC, cm.id ASC",
                [(int) $row['id']]
            );

            // Paid amount & balance
            $paid = (float) DB::value(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND status = 'verified'",
                [(int) $row['id']]
            );
            $total = (float) $row['total_amount'];
            $balance = max(0.0, round($total - $paid, 2));

            // Time formatted
            $startTime = !empty($row['start_time']) ? time12((string) $row['start_time']) : null;
            $endTime = !empty($row['end_time']) ? time12((string) $row['end_time']) : null;
            $timeFormatted = $startTime ? ($endTime ? "{$startTime} – {$endTime}" : $startTime) : 'Time TBD';

            // Overdue project check
            $isOverdue = false;
            if (!empty($row['project_due_date']) && $row['project_stage'] !== 'released') {
                $isOverdue = ($row['project_due_date'] < $today);
            }

            $crewSummary = !empty($crew)
                ? implode(', ', array_map(fn($c) => $c['crew_name'] . ' (' . $c['role'] . ')', $crew))
                : 'No crew assigned';

            $events[] = [
                'id'               => (int) $row['id'],
                'booking_id'       => (int) $row['id'],
                'reference_code'   => (string) $row['reference_code'],
                'event_type'       => (string) $row['event_type'],
                'event_date'       => (string) $row['event_date'],
                'start_time'       => $row['start_time'],
                'end_time'         => $row['end_time'],
                'time_formatted'   => $timeFormatted,
                'venue'            => (string) $row['venue'],
                'status'           => $status,
                'total_amount'     => $total,
                'paid_amount'      => $paid,
                'balance'          => $balance,
                'client_id'        => (int) $row['client_id'],
                'client_name'      => (string) $row['client_name'],
                'client_mobile'    => (string) $row['client_mobile'],
                'package_id'       => (int) $row['package_id'],
                'package_name'     => (string) $row['package_name'],
                'project_id'       => $row['project_id'] ? (int) $row['project_id'] : null,
                'project_stage'    => $row['project_stage'],
                'project_due_date' => $row['project_due_date'],
                'is_overdue'       => $isOverdue,
                'crew'             => $crew,
                'has_crew'         => !empty($crew),
                'crew_summary'     => $crewSummary,
            ];
        }

        $maxEvents = max(1, Settings::int('max_events_per_day'));
        $isFull = ($confirmedCount >= $maxEvents);
        $canAdd = (!$isPast && !$isBlocked && !$isFull);

        // Crew shortage check on date
        $crewSchedule = CrewService::getScheduleForDate($date);

        return [
            'date'                  => $date,
            'date_formatted'        => date_long($date),
            'is_today'              => ($date === $today),
            'is_past'               => $isPast,
            'is_blocked'            => $isBlocked,
            'blocked_reason'        => $blockedReason !== null ? (string) $blockedReason : null,
            'confirmed_count'       => $confirmedCount,
            'pending_count'         => $pendingCount,
            'total_events'          => count($events),
            'is_full'               => $isFull,
            'can_add_booking'       => $canAdd,
            'add_booking_url'       => '/admin/bookings/new?date=' . urlencode($date),
            'has_crew_shortage'     => (bool) ($crewSchedule['has_shortage'] ?? false),
            'crew_shortage_message' => $crewSchedule['shortage_message'] ?? null,
            'events'                => $events,
        ];
    }
}
