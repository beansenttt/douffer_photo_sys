<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Date availability (BR-1, BR-3, BR-4).
 *
 * A date is open when it is not in the past, not blocked, and has fewer
 * CONFIRMED bookings than settings.max_events_per_day. Pending requests never
 * count (BR-1: only a verified down payment locks a date).
 */
final class AvailabilityService
{
    /** Why a date is closed: 'invalid' | 'past' | 'blocked' | 'full', or null when open. */
    public static function reason(string $date, ?int $excludeBookingId = null): ?string
    {
        if (!Validator::isDate($date)) {
            return 'invalid';
        }
        if ($date < Clock::today()) {
            return 'past';
        }
        if (DB::value('SELECT 1 FROM blocked_dates WHERE `date` = ?', [$date])) {
            return 'blocked';
        }
        if (self::confirmedCount($date, $excludeBookingId) >= max(1, Settings::int('max_events_per_day'))) {
            return 'full';
        }
        return null;
    }

    public static function isDateAvailable(string $date, ?int $excludeBookingId = null): bool
    {
        return self::reason($date, $excludeBookingId) === null;
    }

    /** Confirmed, non-archived bookings on a date (optionally ignoring one booking, e.g. when rescheduling it). */
    public static function confirmedCount(string $date, ?int $excludeBookingId = null): int
    {
        $sql = "SELECT COUNT(*) FROM bookings WHERE event_date = ? AND status = 'confirmed' AND is_archived = 0";
        $params = [$date];
        if ($excludeBookingId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeBookingId;
        }
        return (int) DB::value($sql, $params);
    }

    /** Pending (unverified) requests waiting for a date, for the A7 warning and the admin calendar. */
    public static function pendingCount(string $date): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM bookings WHERE event_date = ? AND status = 'pending' AND is_archived = 0",
            [$date]
        );
    }

    /**
     * Availability of every day in a month with 3 queries total (fast calendar).
     * Returns ['2026-12-01' => null|'past'|'blocked'|'full', ...]
     */
    public static function monthMap(int $year, int $month): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $days = (int) date('t', strtotime($first));
        $last = sprintf('%04d-%02d-%02d', $year, $month, $days);

        $blocked = array_flip(array_column(
            DB::all('SELECT `date` FROM blocked_dates WHERE `date` BETWEEN ? AND ?', [$first, $last]),
            'date'
        ));
        $counts = [];
        foreach (DB::all(
            "SELECT event_date, COUNT(*) AS n FROM bookings
             WHERE event_date BETWEEN ? AND ? AND status = 'confirmed' AND is_archived = 0
             GROUP BY event_date",
            [$first, $last]
        ) as $row) {
            $counts[$row['event_date']] = (int) $row['n'];
        }

        $max = max(1, Settings::int('max_events_per_day'));
        $today = Clock::today();
        $map = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            if ($date < $today) {
                $map[$date] = 'past';
            } elseif (isset($blocked[$date])) {
                $map[$date] = 'blocked';
            } elseif (($counts[$date] ?? 0) >= $max) {
                $map[$date] = 'full';
            } else {
                $map[$date] = null;
            }
        }
        return $map;
    }

    /**
     * RACE SAFETY (BR-4). Call INSIDE a DB::transaction() before a booking
     * becomes confirmed (A7 verify, A5 "down payment received", A13 reschedule).
     *
     * It locks one row per date (date_locks) with SELECT ... FOR UPDATE, so a
     * second request for the same date waits until the first commits. Then it
     * re-runs the availability check on fresh data. If the date filled up in
     * the meantime it throws, and the transaction rolls back.
     */
    public static function lockAndAssertOpen(string $date, ?int $excludeBookingId = null): void
    {
        DB::run('INSERT IGNORE INTO date_locks (`date`) VALUES (?)', [$date]);
        DB::run('SELECT `date` FROM date_locks WHERE `date` = ? FOR UPDATE', [$date])->fetchAll();

        $reason = self::reason($date, $excludeBookingId);
        if ($reason !== null) {
            throw new DateUnavailableException(self::message($reason));
        }
    }

    /** Plain-language explanation for a closed date. */
    public static function message(?string $reason): string
    {
        return match ($reason) {
            'full'    => 'This date just became full. Reschedule or reject.',
            'blocked' => 'This date is blocked by the studio.',
            'past'    => 'This date is in the past.',
            default   => 'This date is not available.',
        };
    }
}
