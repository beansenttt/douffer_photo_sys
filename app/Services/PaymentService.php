<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;

/**
 * Payment verification and recording (BR-1, BR-2, BR-5, §9.3, §9.4).
 * Nothing is ever verified automatically: a person verifies in A7, or records
 * money they received in A6/A5.
 */
final class PaymentService
{
    /** Other payments (any status) that used the same GCash reference number. */
    public static function duplicatesOf(array $payment): array
    {
        $ref = trim((string) ($payment['gcash_reference_no'] ?? ''));
        if ($ref === '') {
            return [];
        }
        return DB::all(
            'SELECT p.id, p.status, p.amount, b.reference_code
             FROM payments p JOIN bookings b ON b.id = p.booking_id
             WHERE p.gcash_reference_no = ? AND p.id <> ? ORDER BY p.id',
            [$ref, (int) $payment['id']]
        );
    }

    /**
     * Verify a pending payment (A7 "Verify").
     * In ONE transaction: lock the payment, require acknowledgement of a duplicate
     * reference, and (for a down payment on a pending booking) lock the date and
     * re-check availability. Then issue the official receipt number, confirm the
     * booking, create its project, log history and tell the client in the chat.
     *
     * @throws DuplicateReferenceException when the reference is a duplicate and $ackDuplicate is false
     * @throws DateUnavailableException    when the date filled up meanwhile (BR-4)
     * @throws PaymentException            when the payment cannot be verified any more
     * @return int booking id
     */
    public static function verify(int $paymentId, int $userId, bool $ackDuplicate): int
    {
        return DB::transaction(static function () use ($paymentId, $userId, $ackDuplicate): int {
            $now = Clock::now();

            $payment = DB::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || $payment['status'] !== 'pending') {
                throw new PaymentException('This payment was already verified or rejected.');
            }
            $booking = DB::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [(int) $payment['booking_id']]);
            if (!$booking || $booking['status'] === 'cancelled') {
                throw new PaymentException('This booking was cancelled. The payment cannot be verified.');
            }

            $duplicates = self::duplicatesOf($payment);
            if ($duplicates && !$ackDuplicate) {
                throw new DuplicateReferenceException('This GCash reference number was already used. Tick the box to confirm you checked it.');
            }

            $confirmsBooking = $payment['type'] === 'down' && $booking['status'] === 'pending';
            if ($confirmsBooking) {
                // Lock the date row, then re-run the availability check on fresh data.
                AvailabilityService::lockAndAssertOpen($booking['event_date'], (int) $booking['id']);
            }

            $receiptNo = self::issueReceiptNo();
            DB::update('payments', [
                'status'           => 'verified',
                'receipt_no'       => $receiptNo,
                'verified_by'      => $userId,
                'verified_at'      => $now,
                'duplicate_ack_by' => $duplicates ? $userId : null,
                'duplicate_ack_at' => $duplicates ? $now : null,
            ], 'id = ?', [$paymentId]);

            if ($confirmsBooking) {
                DB::update('bookings', ['status' => 'confirmed', 'confirmed_at' => $now], 'id = ?', [$booking['id']]);
                DB::insert('booking_changes', [
                    'booking_id' => $booking['id'], 'change_type' => 'status', 'old_value' => 'pending', 'new_value' => 'confirmed',
                    'reason' => 'Down payment verified (' . $receiptNo . ')', 'changed_by' => $userId, 'changed_at' => $now,
                ]);
                self::createProject($booking, $userId, $now);
                MessageService::postSystem((int) $booking['id'], "Payment verified ✔ Your booking is confirmed. Official receipt {$receiptNo}.");
            } else {
                DB::insert('booking_changes', [
                    'booking_id' => $booking['id'], 'change_type' => 'note', 'new_value' => peso($payment['amount']),
                    'reason' => 'Payment verified (' . $receiptNo . ')', 'changed_by' => $userId, 'changed_at' => $now,
                ]);
                MessageService::postSystem((int) $booking['id'], 'Payment verified ✔ Official receipt ' . $receiptNo . '.');
            }

            Audit::log('payment_verified', 'payment', $paymentId, ['receipt' => $receiptNo, 'duplicate_acknowledged' => (bool) $duplicates]);
            return (int) $booking['id'];
        });
    }

    /** Reject a pending payment with a reason (A7 "Reject"). The client can re-upload from C5. */
    public static function reject(int $paymentId, int $userId, string $reason): int
    {
        return DB::transaction(static function () use ($paymentId, $userId, $reason): int {
            $payment = DB::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || $payment['status'] !== 'pending') {
                throw new PaymentException('This payment was already verified or rejected.');
            }
            $booking = DB::one('SELECT * FROM bookings WHERE id = ?', [(int) $payment['booking_id']]);
            $reason = mb_substr(trim($reason), 0, 255);

            DB::update('payments', [
                'status' => 'rejected', 'rejection_reason' => $reason, 'verified_by' => $userId, 'verified_at' => Clock::now(),
            ], 'id = ?', [$paymentId]);
            DB::insert('booking_changes', [
                'booking_id' => $booking['id'], 'change_type' => 'note', 'reason' => 'Payment rejected: ' . $reason,
                'changed_by' => $userId, 'changed_at' => Clock::now(),
            ]);
            MessageService::postSystem(
                (int) $booking['id'],
                "Your payment could not be accepted: {$reason}. Please send a new screenshot from your booking page (reference no. {$booking['reference_code']})."
            );
            Audit::log('payment_rejected', 'payment', $paymentId, ['reason' => $reason]);
            return (int) $booking['id'];
        });
    }

    /**
     * Record money received in person or checked by staff (A6 "Record balance payment").
     * Staff are looking at the money, so it is saved as VERIFIED right away and gets
     * an official receipt number.
     *
     * @return int the new payment id
     */
    public static function record(int $bookingId, int $userId, string $type, string $method, float $amount, ?string $gcashRef, ?string $note): int
    {
        return DB::transaction(static function () use ($bookingId, $userId, $type, $method, $amount, $gcashRef, $note): int {
            $booking = DB::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$booking || in_array($booking['status'], ['cancelled'], true)) {
                throw new PaymentException('Payments cannot be recorded for this booking.');
            }
            if ($booking['status'] === 'pending') {
                throw new PaymentException('Verify the down payment first (Payments to verify).');
            }
            if ($amount <= 0) {
                throw new PaymentException('Please enter an amount greater than zero.');
            }
            $paid = (float) DB::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = ? AND status = 'verified'", [$bookingId]);
            $balance = round((float) $booking['total_amount'] - $paid, 2);
            if ($amount - $balance > 0.001) {
                throw new PaymentException('That is more than the balance (' . peso($balance) . ').');
            }

            $now = Clock::now();
            $receiptNo = self::issueReceiptNo();
            $id = DB::insert('payments', [
                'booking_id' => $bookingId, 'type' => $type, 'method' => $method, 'amount' => round($amount, 2),
                'gcash_reference_no' => $gcashRef !== null && $gcashRef !== '' ? $gcashRef : null,
                'status' => 'verified', 'receipt_no' => $receiptNo, 'submitted_via' => 'admin',
                'recorded_by' => $userId, 'verified_by' => $userId, 'verified_at' => $now, 'paid_at' => $now,
                'notes' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null, 'created_at' => $now,
            ]);
            DB::insert('booking_changes', [
                'booking_id' => $bookingId, 'change_type' => 'note', 'new_value' => peso($amount),
                'reason' => ucfirst($type) . ' payment recorded (' . $receiptNo . ')', 'changed_by' => $userId, 'changed_at' => $now,
            ]);
            $left = max(0.0, round($balance - $amount, 2));
            MessageService::postSystem($bookingId, 'Payment received ' . peso($amount) . '. Official receipt ' . $receiptNo . '. Remaining balance: ' . peso($left) . '.');
            Audit::log('payment_recorded', 'payment', $id, ['booking' => $bookingId, 'amount' => $amount, 'receipt' => $receiptNo]);
            return $id;
        });
    }

    /** OR-YYYY-NNNN, sequential per year. Needs an open transaction. */
    public static function issueReceiptNo(): string
    {
        $year = (int) Clock::dateTime()->format('Y');
        return sprintf('OR-%d-%04d', $year, BookingService::nextNumber('receipt', $year));
    }

    /** Project row created when a booking is confirmed (BR-7). Due date = event + default delivery days. */
    public static function createProject(array $booking, int $userId, string $now): void
    {
        if (DB::value('SELECT 1 FROM projects WHERE booking_id = ?', [$booking['id']])) {
            return;
        }
        $days = max(1, Settings::int('default_delivery_days'));
        $due = date('Y-m-d', strtotime($booking['event_date'] . " +$days days"));
        $projectId = DB::insert('projects', ['booking_id' => $booking['id'], 'stage' => 'booked', 'due_date' => $due, 'created_at' => $now]);
        DB::insert('project_history', ['project_id' => $projectId, 'old_stage' => null, 'new_stage' => 'booked', 'changed_by' => $userId, 'changed_at' => $now]);
    }
}
