<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Validator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Screen A9: Crew and Equipment Assignment service (Milestone 2 / Scenario T4).
 * Manages schedule evaluation, conflict blocking, shortage detection, and equipment checklists.
 */
final class CrewService
{
    /**
     * Fetch complete schedule for a given date matching PROJECT.md contract:
     * Returns: ['events' => array, 'crew_roster' => array, 'has_shortage' => bool, 'shortage_message' => ?string]
     *
     * @return array{events: array, crew_roster: array, has_shortage: bool, shortage_message: ?string}
     */
    public static function getScheduleForDate(string $date): array
    {
        if (!Validator::isDate($date)) {
            $date = Clock::today();
        }

        // 1. Fetch active events on this date (confirmed + pending, non-archived)
        $events = DB::all(
            "SELECT b.id, b.reference_code, b.client_id, b.package_id, b.event_type,
                    b.event_date, b.start_time, b.end_time, b.venue, b.special_requests,
                    b.status, b.is_archived,
                    c.full_name, c.full_name AS client_name, c.mobile, c.messenger_name,
                    p.name AS package_name, p.category AS package_category
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             WHERE b.event_date = ?
               AND b.status IN ('confirmed', 'pending')
               AND b.is_archived = 0
             ORDER BY b.start_time ASC, b.id ASC",
            [$date]
        );

        // Pre-count crew assignments per crew member across active events on this date
        $assignmentsOnDate = DB::all(
            "SELECT ca.crew_member_id, ca.booking_id, b.start_time, b.end_time, b.reference_code,
                    c.full_name AS client_name
             FROM crew_assignments ca
             JOIN bookings b ON b.id = ca.booking_id
             JOIN clients c ON c.id = b.client_id
             WHERE b.event_date = ?
               AND b.status IN ('confirmed', 'pending')
               AND b.is_archived = 0",
            [$date]
        );

        $memberEventCount = [];
        $memberAssignments = [];
        foreach ($assignmentsOnDate as $a) {
            $mid = (int) $a['crew_member_id'];
            $memberEventCount[$mid] = ($memberEventCount[$mid] ?? 0) + 1;
            $memberAssignments[$mid][] = $a;
        }

        // 2. Fetch assigned crew and equipment for each event
        foreach ($events as &$ev) {
            $bid = (int) $ev['id'];

            $crew = DB::all(
                "SELECT ca.id AS assignment_id, ca.booking_id, ca.crew_member_id, ca.role, ca.call_time, ca.created_at,
                        cm.name, cm.type, cm.phone, cm.is_on_call
                 FROM crew_assignments ca
                 JOIN crew_members cm ON cm.id = ca.crew_member_id
                 WHERE ca.booking_id = ?
                 ORDER BY ca.role = 'lead' DESC, cm.id ASC",
                [$bid]
            );

            foreach ($crew as &$member) {
                $mid = (int) $member['crew_member_id'];
                $hasMulti = ($memberEventCount[$mid] ?? 0) > 1;
                $member['is_same_day_multievent'] = $hasMulti;
                $member['has_same_day_multievent'] = $hasMulti;

                // Fallback call time: 1 hour before event start time
                if (empty($member['call_time']) && !empty($ev['start_time'])) {
                    $member['computed_call_time'] = date('H:i:s', strtotime($ev['start_time'] . ' -1 hour'));
                } else {
                    $member['computed_call_time'] = $member['call_time'];
                }
                $member['effective_call_time'] = $member['computed_call_time'];
            }
            unset($member);

            $ev['crew'] = $crew;
            $ev['assigned_crew'] = $crew;

            // Equipment items with is_checked flag
            $equipment = self::getEquipmentForBooking($bid);
            $ev['equipment'] = $equipment;
            $ev['equipment_checked_ids'] = array_map(
                'intval',
                array_column(array_filter($equipment, static fn(array $item): bool => (int) $item['is_checked'] === 1), 'id')
            );
        }
        unset($ev);

        // 3. Build crew roster for this date with Free/Busy tags
        $allMembers = DB::all(
            "SELECT cm.id, cm.name, cm.type, cm.phone, cm.user_id, cm.is_on_call, cm.is_active
             FROM crew_members cm
             WHERE cm.is_active = 1
             ORDER BY cm.type = 'owner' DESC, cm.is_on_call DESC, cm.name ASC"
        );

        $crewRoster = [];
        foreach ($allMembers as $m) {
            $mid = (int) $m['id'];
            $count = $memberEventCount[$mid] ?? 0;
            $m['assigned_event_count'] = $count;
            $m['is_busy'] = ($count > 0);
            $m['status_on_date'] = ($count > 0) ? 'busy' : 'free';

            if ($count > 0 && !empty($memberAssignments[$mid])) {
                $firstAssign = $memberAssignments[$mid][0];
                $m['busy_event_ref'] = $firstAssign['reference_code'] . ' (' . time12($firstAssign['start_time'] ?? '') . ')';
            } else {
                $m['busy_event_ref'] = null;
            }

            $crewRoster[] = $m;
        }

        // 4. Compute shortage status (Scenario T4)
        // Red banner when >= 1 active event on date has 0 assigned crew; turns green when all have crew.
        $hasShortage = false;
        $shortageMessage = null;

        if (!empty($events)) {
            $unassigned = [];
            foreach ($events as $ev) {
                if (empty($ev['crew'])) {
                    $unassigned[] = $ev;
                }
            }
            if (!empty($unassigned)) {
                $hasShortage = true;
                $names = array_map(
                    static fn(array $e): string => ($e['client_name'] ?? $e['full_name']) . ' (' . $e['reference_code'] . ')',
                    $unassigned
                );
                $shortageMessage = count($unassigned) === 1
                    ? "Event " . $names[0] . " has no crew assigned."
                    : count($unassigned) . " events on this date have no crew assigned: " . implode(', ', $names) . ".";
            }
        }

        return [
            'events'           => $events,
            'crew_roster'      => $crewRoster,
            'has_shortage'     => $hasShortage,
            'shortage_message' => $shortageMessage,
        ];
    }

    /**
     * Assign a crew member to a booking (A9 / Scenario T4).
     *
     * In ONE transaction:
     * - Locks booking row.
     * - Asserts crew member is active.
     * - Asserts crew member not already assigned to this booking.
     * - Asserts no overlapping event assignment on the same date (s1 < e2 && s2 < e1).
     * - Inserts into crew_assignments.
     * - Records audit log.
     * - Records booking changes note.
     *
     * @throws CrewOverlapConflictException on overlapping event hours
     * @throws InvalidArgumentException on invalid input / duplicate assignment
     * @throws RuntimeException on missing/cancelled booking
     */
    public static function assign(
        int $bookingId,
        int $crewMemberId,
        string $role,
        ?string $callTime,
        int $assignedBy
    ): void {
        $role = in_array($role, ['lead', 'backup'], true) ? $role : 'lead';
        $callTime = ($callTime !== null && trim($callTime) !== '') ? trim($callTime) : null;

        DB::transaction(static function () use ($bookingId, $crewMemberId, $role, $callTime, $assignedBy): void {
            $booking = DB::one(
                "SELECT id, reference_code, event_date, start_time, end_time, status, is_archived
                 FROM bookings WHERE id = ? FOR UPDATE",
                [$bookingId]
            );
            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }
            if ($booking['status'] === 'cancelled') {
                throw new RuntimeException('Cannot assign crew to a cancelled booking.');
            }
            if (!empty($booking['is_archived'])) {
                throw new RuntimeException('Cannot assign crew to an archived booking.');
            }

            $crew = DB::one(
                "SELECT id, name, type, is_active FROM crew_members WHERE id = ?",
                [$crewMemberId]
            );
            if (!$crew || (int) $crew['is_active'] !== 1) {
                throw new InvalidArgumentException('Crew member not found or is inactive.');
            }

            // Check if already assigned to this booking
            $existing = DB::one(
                "SELECT id FROM crew_assignments WHERE booking_id = ? AND crew_member_id = ?",
                [$bookingId, $crewMemberId]
            );
            if ($existing) {
                throw new InvalidArgumentException($crew['name'] . ' is already assigned to this event.');
            }

            // Check for overlapping events on the same date
            $otherEvents = DB::all(
                "SELECT b.id, b.reference_code, b.event_date, b.start_time, b.end_time,
                        c.full_name AS client_name
                 FROM crew_assignments ca
                 JOIN bookings b ON b.id = ca.booking_id
                 JOIN clients c ON c.id = b.client_id
                 WHERE ca.crew_member_id = ?
                   AND b.event_date = ?
                   AND b.id <> ?
                   AND b.status IN ('confirmed', 'pending')
                   AND b.is_archived = 0",
                [$crewMemberId, $booking['event_date'], $bookingId]
            );

            $targetStart = $booking['start_time'] ?: '00:00:00';
            $targetEnd = $booking['end_time'] ?: '23:59:59';

            foreach ($otherEvents as $other) {
                $otherStart = $other['start_time'] ?: '00:00:00';
                $otherEnd = $other['end_time'] ?: '23:59:59';

                // Overlap formula: start1 < end2 AND start2 < end1
                if ($targetStart < $otherEnd && $otherStart < $targetEnd) {
                    throw new CrewOverlapConflictException(
                        sprintf(
                            '%s is already assigned to another event (%s – %s) with overlapping hours (%s – %s).',
                            $crew['name'],
                            $other['reference_code'],
                            $other['client_name'],
                            time12($otherStart),
                            time12($otherEnd)
                        )
                    );
                }
            }

            $now = Clock::now();
            DB::insert('crew_assignments', [
                'booking_id'     => $bookingId,
                'crew_member_id' => $crewMemberId,
                'role'           => $role,
                'call_time'      => $callTime,
                'assigned_by'    => $assignedBy > 0 ? $assignedBy : null,
                'created_at'     => $now,
            ]);

            Audit::log('crew_assigned', 'booking', $bookingId, [
                'crew_member_id'   => $crewMemberId,
                'crew_member_name' => $crew['name'],
                'role'             => $role,
                'call_time'        => $callTime,
            ]);

            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'note',
                'old_value'   => null,
                'new_value'   => "Crew assigned: {$crew['name']} ({$role})",
                'reason'      => 'Call time: ' . ($callTime ? time12($callTime) : 'Default (1h prior)'),
                'changed_by'  => $assignedBy > 0 ? $assignedBy : null,
                'changed_at'  => $now,
            ]);
        });
    }

    /**
     * Remove a crew assignment from a booking.
     */
    public static function unassign(int $bookingId, int $crewMemberId, ?int $userId = null): void
    {
        DB::transaction(static function () use ($bookingId, $crewMemberId, $userId): void {
            $assignment = DB::one(
                "SELECT ca.id, cm.name
                 FROM crew_assignments ca
                 JOIN crew_members cm ON cm.id = ca.crew_member_id
                 WHERE ca.booking_id = ? AND ca.crew_member_id = ?",
                [$bookingId, $crewMemberId]
            );
            if (!$assignment) {
                throw new RuntimeException('Assignment not found.');
            }

            DB::run(
                "DELETE FROM crew_assignments WHERE booking_id = ? AND crew_member_id = ?",
                [$bookingId, $crewMemberId]
            );

            Audit::log('crew_unassigned', 'booking', $bookingId, [
                'crew_member_id'   => $crewMemberId,
                'crew_member_name' => $assignment['name'],
            ]);

            $now = Clock::now();
            $changedBy = $userId ?? \App\Core\Auth::id();
            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'note',
                'old_value'   => null,
                'new_value'   => "Crew removed: {$assignment['name']}",
                'reason'      => 'Unassigned via Screen A9',
                'changed_by'  => ($changedBy && $changedBy > 0) ? $changedBy : null,
                'changed_at'  => $now,
            ]);
        });
    }

    /**
     * Save equipment checklist items for a booking.
     *
     * @param int[] $checkedItemIds
     */
    public static function saveEquipment(int $bookingId, array $checkedItemIds, ?int $userId = null): void
    {
        DB::transaction(static function () use ($bookingId, $checkedItemIds, $userId): void {
            $booking = DB::one("SELECT id FROM bookings WHERE id = ?", [$bookingId]);
            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }

            $allItems = DB::all("SELECT id, name FROM equipment_items WHERE is_active = 1");
            $checkedMap = array_flip(array_map('intval', $checkedItemIds));

            foreach ($allItems as $item) {
                $itemId = (int) $item['id'];
                $isChecked = isset($checkedMap[$itemId]) ? 1 : 0;

                DB::run(
                    "INSERT INTO booking_equipment (booking_id, equipment_item_id, is_checked)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE is_checked = VALUES(is_checked)",
                    [$bookingId, $itemId, $isChecked]
                );
            }

            Audit::log('equipment_saved', 'booking', $bookingId, [
                'checked_items' => array_keys($checkedMap),
            ]);

            $now = Clock::now();
            $changedBy = $userId ?? \App\Core\Auth::id();
            $count = count(array_intersect(array_keys($checkedMap), array_map(static fn(array $i): int => (int) $i['id'], $allItems)));
            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'note',
                'old_value'   => null,
                'new_value'   => "Equipment checklist updated ({$count} items checked)",
                'reason'      => 'Saved via Screen A9',
                'changed_by'  => ($changedBy && $changedBy > 0) ? $changedBy : null,
                'changed_at'  => $now,
            ]);
        });
    }

    /**
     * Retrieve equipment items and is_checked state for a specific booking.
     *
     * @return array<int, array{id: int, name: string, category: ?string, is_checked: int}>
     */
    public static function getEquipmentForBooking(int $bookingId): array
    {
        return DB::all(
            "SELECT ei.id, ei.name, ei.category,
                    COALESCE(be.is_checked, 0) AS is_checked
             FROM equipment_items ei
             LEFT JOIN booking_equipment be ON be.equipment_item_id = ei.id AND be.booking_id = ?
             WHERE ei.is_active = 1
             ORDER BY ei.id ASC",
            [$bookingId]
        );
    }

    /**
     * Check if a crew member has an overlapping conflict on the date of the given booking.
     *
     * @return ?array Conflicting booking information or null if free
     */
    public static function checkConflict(int $crewMemberId, int $bookingId): ?array
    {
        $booking = DB::one(
            "SELECT id, reference_code, event_date, start_time, end_time, status FROM bookings WHERE id = ?",
            [$bookingId]
        );
        if (!$booking) {
            return null;
        }

        $otherEvents = DB::all(
            "SELECT b.id, b.reference_code, b.event_date, b.start_time, b.end_time,
                    c.full_name AS client_name
             FROM crew_assignments ca
             JOIN bookings b ON b.id = ca.booking_id
             JOIN clients c ON c.id = b.client_id
             WHERE ca.crew_member_id = ?
               AND b.event_date = ?
               AND b.id <> ?
               AND b.status IN ('confirmed', 'pending')
               AND b.is_archived = 0",
            [$crewMemberId, $booking['event_date'], $bookingId]
        );

        $targetStart = $booking['start_time'] ?: '00:00:00';
        $targetEnd = $booking['end_time'] ?: '23:59:59';

        foreach ($otherEvents as $other) {
            $otherStart = $other['start_time'] ?: '00:00:00';
            $otherEnd = $other['end_time'] ?: '23:59:59';

            if ($targetStart < $otherEnd && $otherStart < $targetEnd) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Fetch assigned events for a crew login account (Screen A18 / Scenario T7).
     * Strictly excludes all financial/payment fields (no prices, no down payments, no balances).
     *
     * @return array{crew_member: ?array, next_event: ?array, later_events: array, past_events: array}
     */
    public static function getEventsForUser(int $userId): array
    {
        $crewMember = DB::one(
            "SELECT id, name, type, phone, is_on_call, is_active
             FROM crew_members
             WHERE user_id = ? AND is_active = 1",
            [$userId]
        );

        if (!$crewMember) {
            return [
                'crew_member'  => null,
                'next_event'   => null,
                'later_events' => [],
                'past_events'  => [],
            ];
        }

        $crewMemberId = (int) $crewMember['id'];
        $today = Clock::today();

        // Explicitly select only non-financial columns (T7: zero payment data on A18)
        $rows = DB::all(
            "SELECT b.id, b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                    b.venue, b.special_requests, b.status,
                    c.full_name AS client_name, c.mobile AS client_mobile, c.messenger_name,
                    p.name AS package_name, p.category AS package_category, p.inclusions AS package_inclusions,
                    ca.role AS crew_role, ca.call_time
             FROM crew_assignments ca
             JOIN bookings b ON b.id = ca.booking_id
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             WHERE ca.crew_member_id = ?
               AND b.status IN ('confirmed', 'pending', 'completed')
               AND b.is_archived = 0
             ORDER BY b.event_date ASC, b.start_time ASC, b.id ASC",
            [$crewMemberId]
        );

        $upcoming = [];
        $past = [];

        foreach ($rows as $ev) {
            $bid = (int) $ev['id'];

            // Effective call time: explicit call_time or 1 hour before start_time (Assumption #7)
            if (!empty($ev['call_time'])) {
                $ev['effective_call_time'] = (string) $ev['call_time'];
            } elseif (!empty($ev['start_time'])) {
                $ev['effective_call_time'] = date('H:i:s', strtotime($ev['start_time'] . ' -1 hour'));
            } else {
                $ev['effective_call_time'] = null;
            }

            // Package inclusions (lines) + add-on names (no prices)
            $ev['inclusions_list'] = \App\Models\Package::inclusions($ev['package_inclusions'] ?? '');
            $addons = DB::all(
                "SELECT a.name
                 FROM booking_addons ba
                 JOIN addons a ON a.id = ba.addon_id
                 WHERE ba.booking_id = ?
                 ORDER BY a.id ASC",
                [$bid]
            );
            $ev['addon_names'] = array_column($addons, 'name');

            // Co-assigned crew for this event
            $ev['team'] = DB::all(
                "SELECT cm.id, cm.name, cm.type, ca.role, ca.call_time
                 FROM crew_assignments ca
                 JOIN crew_members cm ON cm.id = ca.crew_member_id
                 WHERE ca.booking_id = ?
                 ORDER BY ca.role = 'lead' DESC, cm.id ASC",
                [$bid]
            );

            // Equipment checklist for this event
            $ev['equipment'] = self::getEquipmentForBooking($bid);

            // Owner / staff internal notes marked visible_to_crew = 1
            $ev['notes'] = DB::all(
                "SELECT bn.id, bn.body, bn.created_at, u.name AS author_name
                 FROM booking_notes bn
                 LEFT JOIN users u ON u.id = bn.created_by
                 WHERE bn.booking_id = ? AND bn.visible_to_crew = 1
                 ORDER BY bn.created_at DESC, bn.id DESC",
                [$bid]
            );

            if ($ev['event_date'] >= $today && in_array($ev['status'], ['confirmed', 'pending'], true)) {
                $upcoming[] = $ev;
            } else {
                $past[] = $ev;
            }
        }

        // Sort past events most recent first
        usort($past, static fn(array $a, array $b): int => strcmp((string) $b['event_date'], (string) $a['event_date']));

        $nextEvent = $upcoming[0] ?? null;
        $laterEvents = array_slice($upcoming, 1);

        return [
            'crew_member'  => $crewMember,
            'next_event'   => $nextEvent,
            'later_events' => $laterEvents,
            'past_events'  => $past,
        ];
    }
}
