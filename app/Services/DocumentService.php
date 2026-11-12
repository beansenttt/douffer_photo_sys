<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;
use App\Models\Booking;

/** Data and plain text for A8: booking confirmation and official receipt. */
final class DocumentService
{
    /** @return array|null everything the confirmation sheet and receipt need */
    public static function forBooking(int $bookingId): ?array
    {
        $b = DB::one(
            'SELECT b.*, c.full_name, c.mobile, p.name AS package_name, p.inclusions
             FROM bookings b JOIN clients c ON c.id = b.client_id JOIN packages p ON p.id = b.package_id WHERE b.id = ?',
            [$bookingId]
        );
        if (!$b) {
            return null;
        }
        $payments = DB::all(
            "SELECT p.*, u.name AS verified_by_name FROM payments p LEFT JOIN users u ON u.id = p.verified_by
             WHERE p.booking_id = ? AND p.status = 'verified' ORDER BY p.id",
            [$bookingId]
        );
        $down = 0.0;
        $downRef = null;
        $downAt = null;
        foreach ($payments as $p) {
            if ($p['type'] === 'down') {
                $down += (float) $p['amount'];
                $downRef = $downRef ?? $p['gcash_reference_no'];
                $downAt = $downAt ?? $p['paid_at'];
            }
        }
        $terms = DB::value('SELECT body FROM terms_versions WHERE version = ?', [$b['terms_version'] ?? Settings::get('current_terms_version', '1')]);

        return [
            'booking'    => $b,
            'payments'   => $payments,
            'down'       => $down,
            'down_ref'   => $downRef,
            'down_at'    => $downAt,
            'balance'    => Booking::balance($b),
            'paid'       => Booking::paidTotal($bookingId),
            'terms'      => (string) $terms,
            'studio'     => [
                'name'    => Settings::get('studio_name'),
                'address' => Settings::get('studio_address'),
                'phone'   => Settings::get('studio_phone'),
            ],
            'inclusions' => \App\Models\Package::inclusions($b['inclusions']),
        ];
    }

    /** Receipt view of one verified payment, with the balance remaining right after it. */
    public static function receipt(array $doc, int $paymentId): ?array
    {
        foreach ($doc['payments'] as $p) {
            if ((int) $p['id'] === $paymentId) {
                $paidUpTo = 0.0;
                foreach ($doc['payments'] as $q) {
                    if ((int) $q['id'] <= $paymentId) {
                        $paidUpTo += (float) $q['amount'];
                    }
                }
                $p['balance_after'] = max(0.0, round((float) $doc['booking']['total_amount'] - $paidUpTo, 2));
                return $p;
            }
        }
        return null;
    }

    /** Plain-text version for pasting into Messenger ("Copy for Messenger"). */
    public static function messengerText(array $doc): string
    {
        $b = $doc['booking'];
        $when = date_long($b['event_date']);
        if ($b['start_time']) {
            $when .= ', ' . time12($b['start_time']) . ($b['end_time'] ? ' - ' . time12($b['end_time']) : '');
        }
        $lines = [
            '✅ BOOKING CONFIRMED',
            $doc['studio']['name'],
            '',
            'Reference no.: ' . $b['reference_code'],
            'Client: ' . $b['full_name'],
            'Event: ' . $b['event_type'],
            'Date/time: ' . $when,
            'Venue: ' . $b['venue'],
            'Package: ' . $b['package_name'],
            '',
            'Total: ' . peso($b['total_amount']),
            'Down payment received: ' . peso($doc['down']) . ($doc['down_ref'] ? ' (GCash ref. ' . $doc['down_ref'] . ')' : ''),
            'Balance: ' . peso($doc['balance']) . ' (due on or before the event date)',
            '',
            'Terms: ' . $doc['terms'],
            'Thank you!',
        ];
        return implode("\n", $lines);
    }
}
