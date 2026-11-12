<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Services\AvailabilityService;
use App\Services\PaymentService;

/** Booking lookups shared by client and admin screens. */
final class Booking
{
    public static function findByRef(string $ref): ?array
    {
        return DB::one(
            'SELECT b.*, c.full_name, c.mobile, c.mobile_normalized, c.messenger_name, c.contact_channel,
                    p.name AS package_name, p.inclusions, p.category AS package_category
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             WHERE b.reference_code = ?',
            [strtoupper(trim($ref))]
        );
    }

    public static function findById(int $id): ?array
    {
        return DB::one(
            'SELECT b.*, c.full_name, c.mobile, c.mobile_normalized, c.messenger_name, c.contact_channel,
                    p.name AS package_name, p.inclusions, p.category AS package_category
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             WHERE b.id = ?',
            [$id]
        );
    }

    /** Sum of VERIFIED payments. Pending and rejected money does not count. */
    public static function paidTotal(int $bookingId): float
    {
        return (float) DB::value(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND status = 'verified'",
            [$bookingId]
        );
    }

    /** balance = total - verified payments (never below zero). */
    public static function balance(array $booking): float
    {
        return max(0.0, round((float) $booking['total_amount'] - self::paidTotal((int) $booking['id']), 2));
    }

    /** Most recent down-payment attempt (pending, verified or rejected). */
    public static function latestDownPayment(int $bookingId): ?array
    {
        return DB::one(
            "SELECT * FROM payments WHERE booking_id = ? AND type = 'down' ORDER BY id DESC LIMIT 1",
            [$bookingId]
        );
    }

    /** All payments for a booking (newest last). */
    public static function payments(int $bookingId): array
    {
        return DB::all(
            'SELECT p.*, u.name AS verified_by_name, r.name AS recorded_by_name
             FROM payments p
             LEFT JOIN users u ON u.id = p.verified_by
             LEFT JOIN users r ON r.id = p.recorded_by
             WHERE p.booking_id = ?
             ORDER BY p.id ASC',
            [$bookingId]
        );
    }

    /** Add-ons chosen on a booking. */
    public static function addons(int $bookingId): array
    {
        return DB::all(
            'SELECT ba.price_at_booking, a.name
             FROM booking_addons ba
             JOIN addons a ON a.id = ba.addon_id
             WHERE ba.booking_id = ?
             ORDER BY a.id',
            [$bookingId]
        );
    }

    /** History timeline for A6 (created, status, reschedule, cancel, notes). */
    public static function changes(int $bookingId): array
    {
        return DB::all(
            'SELECT bc.*, u.name AS user_name
             FROM booking_changes bc
             LEFT JOIN users u ON u.id = bc.changed_by
             WHERE bc.booking_id = ?
             ORDER BY bc.id DESC',
            [$bookingId]
        );
    }

    /** Assigned crew for a booking. */
    public static function crew(int $bookingId): array
    {
        return DB::all(
            'SELECT ca.*, cm.name, cm.type, cm.phone
             FROM crew_assignments ca
             JOIN crew_members cm ON cm.id = ca.crew_member_id
             WHERE ca.booking_id = ?
             ORDER BY ca.role = "lead" DESC, cm.id',
            [$bookingId]
        );
    }

    /** Internal notes on a booking (staff + optionally crew, never client). */
    public static function notes(int $bookingId): array
    {
        return DB::all(
            'SELECT bn.*, u.name AS author_name
             FROM booking_notes bn
             LEFT JOIN users u ON u.id = bn.created_by
             WHERE bn.booking_id = ?
             ORDER BY bn.id DESC',
            [$bookingId]
        );
    }

    /** Notes visible to crew members for A18. */
    public static function crewVisibleNotes(int $bookingId): array
    {
        return DB::all(
            'SELECT bn.*, u.name AS author_name
             FROM booking_notes bn
             LEFT JOIN users u ON u.id = bn.created_by
             WHERE bn.booking_id = ? AND bn.visible_to_crew = 1
             ORDER BY bn.id DESC',
            [$bookingId]
        );
    }

    public static function project(int $bookingId): ?array
    {
        return DB::one('SELECT * FROM projects WHERE booking_id = ?', [$bookingId]);
    }

    /**
     * Queue of pending payments for A7, enriched with duplicate-reference info,
     * date availability, and how many other requests are waiting for the same date.
     */
    public static function pendingPaymentsQueue(): array
    {
        $rows = DB::all(
            "SELECT p.*, b.reference_code, b.event_type, b.event_date, b.start_time, b.venue,
                    b.status AS booking_status, b.total_amount,
                    c.full_name, c.mobile, pk.name AS package_name
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN clients c ON c.id = b.client_id
             JOIN packages pk ON pk.id = b.package_id
             WHERE p.status = 'pending' AND b.is_archived = 0
             ORDER BY p.created_at ASC, p.id ASC"
        );
        foreach ($rows as &$row) {
            $row['duplicates'] = PaymentService::duplicatesOf($row);
            $otherPending = max(0, AvailabilityService::pendingCount($row['event_date']) - ($row['booking_status'] === 'pending' ? 1 : 0));
            $row['other_pending_on_date'] = $otherPending;
            $row['date_open'] = AvailabilityService::isDateAvailable($row['event_date'], (int) $row['booking_id']);
            $row['confirmed_on_date'] = AvailabilityService::confirmedCount($row['event_date'], (int) $row['booking_id']);
        }
        unset($row);
        return $rows;
    }

    /**
     * Search & filter ledger for A4.
     * Filters: all | pending | confirmed | balance_due | completed | archived
     */
    public static function ledger(string $filter = 'all', string $q = ''): array
    {
        $where = [];
        $params = [];

        if ($filter === 'archived') {
            $where[] = 'b.is_archived = 1';
        } else {
            $where[] = 'b.is_archived = 0';
            if ($filter === 'pending') {
                $where[] = "b.status = 'pending'";
            } elseif ($filter === 'confirmed') {
                $where[] = "b.status = 'confirmed'";
            } elseif ($filter === 'completed') {
                $where[] = "b.status = 'completed'";
            } elseif ($filter === 'balance_due') {
                $where[] = "b.status = 'confirmed' AND (b.total_amount - COALESCE(vp.paid_amount, 0)) > 0.009";
            }
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(c.full_name LIKE ? OR b.reference_code LIKE ? OR b.event_date LIKE ? OR b.event_type LIKE ? OR c.mobile LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sql = "SELECT b.*, c.full_name, c.mobile, p.name AS package_name,
                       COALESCE(vp.paid_amount, 0) AS paid_amount,
                       GREATEST(0, b.total_amount - COALESCE(vp.paid_amount, 0)) AS balance_amount
                FROM bookings b
                JOIN clients c ON c.id = b.client_id
                JOIN packages p ON p.id = b.package_id
                LEFT JOIN (
                    SELECT booking_id, SUM(amount) AS paid_amount
                    FROM payments WHERE status = 'verified' GROUP BY booking_id
                ) vp ON vp.booking_id = b.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY b.event_date DESC, b.id DESC
                LIMIT 100";

        return DB::all($sql, $params);
    }
}
