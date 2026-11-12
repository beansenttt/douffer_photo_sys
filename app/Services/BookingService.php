<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Validator;
use InvalidArgumentException;
use RuntimeException;

/** Creating bookings and the money rules (PROMPT §3.3 and §9.3). */
final class BookingService
{
    /**
     * Down payment for a package total.
     *  - package override (fixed amount) wins;
     *  - mode 'fixed'  -> down_payment_value pesos;
     *  - mode 'percent' -> value% of the total, rounded UP to the nearest ₱50.
     * Never more than the total.
     */
    public static function downPayment(float $total, ?float $packageOverride = null, ?string $mode = null, ?float $value = null): float
    {
        $mode ??= Settings::get('down_payment_mode', 'percent');
        $value ??= (float) Settings::get('down_payment_value', '30');

        if ($packageOverride !== null && $packageOverride > 0) {
            $down = $packageOverride;
        } elseif ($mode === 'fixed') {
            $down = $value;
        } else {
            $down = ceil(($total * $value / 100) / 50) * 50;
        }
        return round(min($down, $total), 2);
    }

    /** Down payment for a packages row. */
    public static function downPaymentFor(array $package): float
    {
        $override = $package['down_payment_override'] !== null ? (float) $package['down_payment_override'] : null;
        return self::downPayment((float) $package['price'], $override);
    }

    /**
     * Next sequential number per name+year (booking -> DPS-2026-0001, receipt -> OR-2026-0001).
     * Must run inside a transaction: the FOR UPDATE row lock makes it unique even with parallel requests.
     */
    public static function nextNumber(string $name, int $year): int
    {
        DB::run('INSERT IGNORE INTO counters (name, year, last_value) VALUES (?, ?, 0)', [$name, $year]);
        $current = (int) DB::value('SELECT last_value FROM counters WHERE name = ? AND year = ? FOR UPDATE', [$name, $year]);
        $next = $current + 1;
        DB::run('UPDATE counters SET last_value = ? WHERE name = ? AND year = ?', [$next, $name, $year]);
        return $next;
    }

    public static function nextReference(): string
    {
        $year = (int) Clock::dateTime()->format('Y');
        return sprintf('DPS-%d-%04d', $year, self::nextNumber('booking', $year));
    }

    /**
     * Client submitted the C3 details and the C4 GCash proof.
     * Creates, in ONE transaction: client (reused if name + mobile match),
     * a PENDING booking, its history entry, the booking conversation with a
     * system message, and a PENDING payment for the verification queue (A7).
     * The date is NOT held (BR-1); the admin re-checks it when verifying.
     *
     * @param array $d draft from C3: full_name, mobile (normalized 09...), messenger_name, event_type,
     *                 package_id, event_date, start_time, end_time, venue, special_requests, privacy_consent_at
     * @return array the new booking row (id, reference_code, ...)
     */
    public static function createPending(array $d, string $gcashRef, string $receiptPath, ?string $thumbPath): array
    {
        return DB::transaction(static function () use ($d, $gcashRef, $receiptPath, $thumbPath): array {
            $now = Clock::now();
            $package = DB::one(
                "SELECT * FROM packages WHERE id = ? AND is_active = 1 AND price IS NOT NULL AND category <> 'other'",
                [(int) $d['package_id']]
            );
            if (!$package) {
                throw new RuntimeException('That package is no longer available.');
            }

            $clientId = self::findOrCreateClient($d, $now);
            $total = (float) $package['price'];
            $down = self::downPaymentFor($package);
            $ref = self::nextReference();
            $termsVersion = Settings::get('current_terms_version', '1');

            $bookingId = DB::insert('bookings', [
                'reference_code'     => $ref,
                'client_id'          => $clientId,
                'package_id'         => (int) $package['id'],
                'event_type'         => $d['event_type'],
                'event_date'         => $d['event_date'],
                'start_time'         => ($d['start_time'] ?? '') !== '' ? $d['start_time'] : null,
                'end_time'           => ($d['end_time'] ?? '') !== '' ? $d['end_time'] : null,
                'venue'              => $d['venue'],
                'special_requests'   => ($d['special_requests'] ?? '') !== '' ? $d['special_requests'] : null,
                'source_channel'     => 'website',
                'status'             => 'pending',
                'package_price'      => $total,
                'total_amount'       => $total,
                'down_payment_due'   => $down,
                'terms_version'      => $termsVersion,
                'terms_accepted_at'  => $now,
                'privacy_consent_at' => $d['privacy_consent_at'] ?? $now,
                'created_at'         => $now,
            ]);

            DB::insert('booking_changes', [
                'booking_id' => $bookingId, 'change_type' => 'created', 'new_value' => 'pending',
                'reason' => 'Booked online', 'changed_by' => null, 'changed_at' => $now,
            ]);

            DB::insert('payments', [
                'booking_id'         => $bookingId,
                'type'               => 'down',
                'method'             => 'gcash',
                'amount'             => $down,
                'gcash_reference_no' => $gcashRef,
                'receipt_path'       => $receiptPath,
                'receipt_thumb_path' => $thumbPath,
                'status'             => 'pending',
                'submitted_via'      => 'portal',
                'paid_at'            => $now,
                'created_at'         => $now,
            ]);

            // Booking conversation (one per booking) with the first system message.
            $convId = DB::insert('conversations', [
                'booking_id' => $bookingId, 'client_id' => $clientId, 'privacy_consent_at' => $d['privacy_consent_at'] ?? $now,
                'status' => 'open', 'last_message_at' => $now, 'created_at' => $now,
            ]);
            DB::insert('messages', [
                'conversation_id' => $convId, 'sender_type' => 'system',
                'body' => "Booking request received. Reference no. $ref. Your payment is being checked.",
                'created_at' => $now,
            ]);

            Audit::log('booking_created_online', 'booking', $bookingId, ['reference' => $ref]);

            return DB::one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        });
    }

    /** Client re-uploads proof after a rejected payment: adds a new pending down payment. */
    public static function addPaymentProof(int $bookingId, string $gcashRef, string $receiptPath, ?string $thumbPath): void
    {
        DB::transaction(static function () use ($bookingId, $gcashRef, $receiptPath, $thumbPath): void {
            $booking = DB::one("SELECT * FROM bookings WHERE id = ? AND status = 'pending' FOR UPDATE", [$bookingId]);
            if (!$booking) {
                throw new RuntimeException('This booking cannot accept a new payment proof.');
            }
            $now = Clock::now();
            DB::insert('payments', [
                'booking_id' => $bookingId, 'type' => 'down', 'method' => 'gcash',
                'amount' => $booking['down_payment_due'], 'gcash_reference_no' => $gcashRef,
                'receipt_path' => $receiptPath, 'receipt_thumb_path' => $thumbPath,
                'status' => 'pending', 'submitted_via' => 'portal', 'paid_at' => $now, 'created_at' => $now,
            ]);
            DB::insert('booking_changes', [
                'booking_id' => $bookingId, 'change_type' => 'note', 'reason' => 'Client sent a new payment proof',
                'changed_at' => $now,
            ]);
            Audit::log('payment_proof_resubmitted', 'booking', $bookingId);
        });
    }

    private static function findOrCreateClient(array $d, string $now): int
    {
        $mobile = (string) $d['mobile']; // already normalized to 09XXXXXXXXX
        $existing = DB::value(
            'SELECT id FROM clients WHERE mobile_normalized = ? AND LOWER(full_name) = LOWER(?) LIMIT 1',
            [$mobile, $d['full_name']]
        );
        if ($existing) {
            if (($d['messenger_name'] ?? '') !== '') {
                DB::run('UPDATE clients SET messenger_name = COALESCE(messenger_name, ?) WHERE id = ?', [$d['messenger_name'], $existing]);
            }
            return (int) $existing;
        }
        return DB::insert('clients', [
            'full_name'          => $d['full_name'],
            'mobile'             => format_mobile($mobile),
            'mobile_normalized'  => $mobile,
            'messenger_name'     => ($d['messenger_name'] ?? '') !== '' ? $d['messenger_name'] : null,
            'contact_channel'    => 'website',
            'privacy_consent_at' => $d['privacy_consent_at'] ?? $now,
            'created_at'         => $now,
        ]);
    }

    /**
     * Create a direct booking from admin walk-in, call, or message inquiry (A5).
     *
     * In ONE transaction:
     * - Reuses existing client if mobile number matches, or creates new client.
     * - Calculates package price, active add-ons, and down payment due.
     * - If $downPaymentReceived is true:
     *     * Locks date row and asserts date is open (throws DateUnavailableException if closed).
     *     * Marks booking 'confirmed'.
     *     * Generates official receipt number (OR-YYYY-NNNN) and verified payment.
     *     * Creates delivery project (BR-7) with due_date = event_date + delivery_days.
     *     * Posts confirmed chat system message.
     * - If $downPaymentReceived is false:
     *     * Date is NOT held (BR-1). Marks booking 'pending'.
     *     * Posts pending inquiry chat system message.
     *
     * @param array $data ['client_name', 'mobile', 'messenger_name', 'event_type', 'event_date',
     *                     'start_time', 'end_time', 'venue', 'special_requests', 'package_id',
     *                     'addons', 'source_channel', 'down_payment_received', 'payment_amount',
     *                     'payment_method', 'payment_ref', 'payment_notes']
     * @param int $staffUserId ID of staff/owner recording the booking
     * @return array ['booking' => array, 'payment' => ?array, 'project' => ?array]
     *
     * @throws ValidationException
     * @throws DateUnavailableException
     */
    public static function createDirect(array $data, int $staffUserId): array
    {
        $mobile = (string) ($data['mobile'] ?? '');
        $mobileNorm = normalize_mobile($mobile);
        if ($mobileNorm === null) {
            throw new ValidationException('Please enter a valid Philippine mobile number.');
        }

        $clientName = trim((string) ($data['client_name'] ?? $data['full_name'] ?? ''));
        if ($clientName === '') {
            throw new ValidationException('Client name is required.');
        }

        $eventType = trim((string) ($data['event_type'] ?? ''));
        if ($eventType === '') {
            throw new ValidationException('Event type is required.');
        }

        $eventDate = trim((string) ($data['event_date'] ?? ''));
        if (!Validator::isDate($eventDate)) {
            throw new ValidationException('Please enter a valid event date.');
        }

        $venue = trim((string) ($data['venue'] ?? ''));
        if ($venue === '') {
            throw new ValidationException('Venue is required.');
        }

        $packageId = (int) ($data['package_id'] ?? 0);
        $package = DB::one(
            "SELECT * FROM packages WHERE id = ? AND is_active = 1 AND price IS NOT NULL AND category <> 'other'",
            [$packageId]
        );
        if (!$package) {
            throw new ValidationException('Please select a valid active package.');
        }

        $allowedChannels = ['messenger', 'call', 'walk_in', 'referral', 'website'];
        $rawChannel = (string) ($data['source_channel'] ?? 'walk_in');
        $sourceChannel = in_array($rawChannel, $allowedChannels, true) ? $rawChannel : 'walk_in';

        $downPaymentReceived = !empty($data['down_payment_received']);

        return DB::transaction(static function () use (
            $data, $staffUserId, $mobileNorm, $clientName, $eventType,
            $eventDate, $venue, $package, $sourceChannel, $downPaymentReceived
        ): array {
            $now = Clock::now();

            if ($eventDate < Clock::today()) {
                throw new DateUnavailableException(AvailabilityService::message('past'));
            }

            if ($downPaymentReceived) {
                // BR-4: Lock date row and assert date is open before confirming
                AvailabilityService::lockAndAssertOpen($eventDate);
            }

            // Client reuse by normalized mobile (PROMPT §7.2 A5)
            $existingClient = DB::one('SELECT * FROM clients WHERE mobile_normalized = ? LIMIT 1', [$mobileNorm]);
            if ($existingClient) {
                $clientId = (int) $existingClient['id'];
                $messenger = trim((string) ($data['messenger_name'] ?? ''));
                if ($messenger !== '' && empty($existingClient['messenger_name'])) {
                    DB::run('UPDATE clients SET messenger_name = ? WHERE id = ?', [$messenger, $clientId]);
                }
            } else {
                $clientId = DB::insert('clients', [
                    'full_name'          => $clientName,
                    'mobile'             => format_mobile($mobileNorm),
                    'mobile_normalized'  => $mobileNorm,
                    'messenger_name'     => ($data['messenger_name'] ?? '') !== '' ? trim((string) $data['messenger_name']) : null,
                    'contact_channel'    => $sourceChannel,
                    'privacy_consent_at' => $now,
                    'created_at'         => $now,
                ]);
            }

            // Package and Add-ons pricing
            $packagePrice = (float) $package['price'];
            $selectedAddonIds = array_filter(array_map('intval', (array) ($data['addons'] ?? [])));
            $addonsTotal = 0.0;
            $addonsToAttach = [];

            if (!empty($selectedAddonIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedAddonIds), '?'));
                $addons = DB::all("SELECT * FROM addons WHERE id IN ($placeholders) AND is_active = 1", array_values($selectedAddonIds));
                foreach ($addons as $addon) {
                    $price = (float) $addon['price'];
                    $addonsTotal += $price;
                    $addonsToAttach[] = [
                        'addon_id' => (int) $addon['id'],
                        'price'    => $price,
                    ];
                }
            }

            $totalAmount = round($packagePrice + $addonsTotal, 2);
            $downOverride = $package['down_payment_override'] !== null ? (float) $package['down_payment_override'] : null;
            $downPaymentDue = self::downPayment($totalAmount, $downOverride);

            $status = $downPaymentReceived ? 'confirmed' : 'pending';
            $ref = self::nextReference();
            $termsVersion = Settings::get('current_terms_version', '1');

            $bookingId = DB::insert('bookings', [
                'reference_code'     => $ref,
                'client_id'          => $clientId,
                'package_id'         => (int) $package['id'],
                'event_type'         => $eventType,
                'event_date'         => $eventDate,
                'start_time'         => ($data['start_time'] ?? '') !== '' ? $data['start_time'] : null,
                'end_time'           => ($data['end_time'] ?? '') !== '' ? $data['end_time'] : null,
                'venue'              => $venue,
                'special_requests'   => ($data['special_requests'] ?? '') !== '' ? trim((string) $data['special_requests']) : null,
                'source_channel'     => $sourceChannel,
                'status'             => $status,
                'package_price'      => $packagePrice,
                'total_amount'       => $totalAmount,
                'down_payment_due'   => $downPaymentDue,
                'terms_version'      => $termsVersion,
                'terms_accepted_at'  => $now,
                'privacy_consent_at' => $now,
                'confirmed_at'       => $downPaymentReceived ? $now : null,
                'created_by'         => $staffUserId,
                'created_at'         => $now,
            ]);

            foreach ($addonsToAttach as $att) {
                DB::insert('booking_addons', [
                    'booking_id'       => $bookingId,
                    'addon_id'         => $att['addon_id'],
                    'price_at_booking' => $att['price'],
                ]);
            }

            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'created',
                'new_value'   => $status,
                'reason'      => 'Direct booking (' . $sourceChannel . ')',
                'changed_by'  => $staffUserId,
                'changed_at'  => $now,
            ]);

            $convId = DB::insert('conversations', [
                'booking_id'         => $bookingId,
                'client_id'          => $clientId,
                'privacy_consent_at' => $now,
                'status'             => 'open',
                'last_message_at'    => $now,
                'created_at'         => $now,
            ]);

            $payment = null;
            $project = null;

            if ($downPaymentReceived) {
                $payAmount = isset($data['payment_amount']) && (float) $data['payment_amount'] > 0
                    ? (float) $data['payment_amount']
                    : $downPaymentDue;
                $payMethod = in_array($data['payment_method'] ?? 'cash', ['cash', 'gcash', 'other'], true)
                    ? (string) $data['payment_method']
                    : 'cash';
                $gcashRef = $payMethod === 'gcash' && !empty($data['payment_ref']) ? trim((string) $data['payment_ref']) : null;
                $notes = !empty($data['payment_notes']) ? mb_substr(trim((string) $data['payment_notes']), 0, 255) : null;

                $receiptNo = PaymentService::issueReceiptNo();

                $paymentId = DB::insert('payments', [
                    'booking_id'         => $bookingId,
                    'type'               => 'down',
                    'method'             => $payMethod,
                    'amount'             => round($payAmount, 2),
                    'gcash_reference_no' => $gcashRef,
                    'receipt_path'       => null,
                    'receipt_thumb_path' => null,
                    'status'             => 'verified',
                    'receipt_no'         => $receiptNo,
                    'submitted_via'      => 'admin',
                    'recorded_by'        => $staffUserId,
                    'verified_by'        => $staffUserId,
                    'verified_at'        => $now,
                    'paid_at'            => $now,
                    'notes'              => $notes,
                    'created_at'         => $now,
                ]);

                DB::insert('booking_changes', [
                    'booking_id'  => $bookingId,
                    'change_type' => 'note',
                    'new_value'   => peso($payAmount),
                    'reason'      => 'Down payment received (' . $receiptNo . ')',
                    'changed_by'  => $staffUserId,
                    'changed_at'  => $now,
                ]);

                $bookingRow = DB::one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
                PaymentService::createProject($bookingRow, $staffUserId, $now);

                DB::insert('messages', [
                    'conversation_id' => $convId,
                    'sender_type'     => 'system',
                    'body'            => "Direct booking confirmed. Reference no. {$ref}. Down payment received (" . peso($payAmount) . "). Official receipt {$receiptNo}.",
                    'created_at'      => $now,
                ]);

                Audit::log('booking_created_direct', 'booking', $bookingId, [
                    'reference' => $ref,
                    'status'    => 'confirmed',
                    'receipt'   => $receiptNo,
                    'amount'    => $payAmount,
                ]);

                $payment = DB::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
                $project = DB::one('SELECT * FROM projects WHERE booking_id = ?', [$bookingId]);
            } else {
                DB::insert('messages', [
                    'conversation_id' => $convId,
                    'sender_type'     => 'system',
                    'body'            => "Booking inquiry recorded. Reference no. {$ref}. Pending down payment.",
                    'created_at'      => $now,
                ]);

                Audit::log('booking_created_direct', 'booking', $bookingId, [
                    'reference' => $ref,
                    'status'    => 'pending',
                ]);
            }

            $booking = DB::one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);

            return [
                'booking' => $booking,
                'payment' => $payment,
                'project' => $project,
            ];
        });
    }

    /**
     * Reschedule a booking to an open date (A13 / T3).
     *
     * In ONE transaction:
     * - Locks booking row.
     * - Locks new date row and asserts date is open (throws DateUnavailableException on full/blocked/past).
     * - Updates event_date, start_time, end_time, venue.
     * - Shifts linked project due date (due_date = newDate + default_delivery_days).
     * - Logs change in booking_changes.
     * - Posts system chat notification.
     *
     * @throws DateUnavailableException
     * @throws RuntimeException
     */
    public static function reschedule(
        int $bookingId,
        string $newDate,
        ?string $startTime,
        ?string $endTime,
        ?string $venue,
        string $reason,
        int $userId
    ): void {
        if (!Validator::isDate($newDate)) {
            throw new DateUnavailableException('Please choose a valid date.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Please provide a reason for rescheduling.');
        }

        DB::transaction(static function () use ($bookingId, $newDate, $startTime, $endTime, $venue, $reason, $userId): void {
            $booking = DB::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }
            if ($booking['status'] === 'cancelled') {
                throw new RuntimeException('Cancelled bookings cannot be rescheduled.');
            }
            if ($booking['status'] === 'completed') {
                throw new RuntimeException('Completed bookings cannot be rescheduled.');
            }

            // Lock the new date and re-run availability check on fresh data (BR-3, BR-4)
            AvailabilityService::lockAndAssertOpen($newDate, $bookingId);

            $now = Clock::now();
            $oldDate = $booking['event_date'];
            $isSameDate = ($newDate === $oldDate);

            $updates = ['event_date' => $newDate];
            if ($startTime !== null) {
                $updates['start_time'] = $startTime !== '' ? $startTime : null;
            }
            if ($endTime !== null) {
                $updates['end_time'] = $endTime !== '' ? $endTime : null;
            }
            if ($venue !== null && trim($venue) !== '') {
                $updates['venue'] = trim($venue);
            }

            DB::update('bookings', $updates, 'id = ?', [$bookingId]);

            // Synchronize project deliverables deadline (due_date) with the new event date
            $project = DB::one('SELECT * FROM projects WHERE booking_id = ? FOR UPDATE', [$bookingId]);
            if ($project && $project['stage'] !== 'released') {
                $deliveryDays = max(1, Settings::int('default_delivery_days'));
                $newDueDate = date('Y-m-d', strtotime($newDate . " +{$deliveryDays} days"));
                DB::update('projects', [
                    'due_date'   => $newDueDate,
                    'updated_at' => $now,
                ], 'id = ?', [(int) $project['id']]);
            }

            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'reschedule',
                'old_value'   => $oldDate,
                'new_value'   => $newDate,
                'reason'      => $reason,
                'changed_by'  => $userId,
                'changed_at'  => $now,
            ]);

            $systemMsg = $isSameDate
                ? "Your event schedule or venue for " . date_short($newDate) . " (" . date_long($newDate) . ") was updated. Reason: {$reason}."
                : "Your event was rescheduled to " . date_short($newDate) . " (" . date_long($newDate) . "). Rescheduling is free. Reason: {$reason}.";

            MessageService::postSystem(
                $bookingId,
                $systemMsg
            );

            Audit::log($isSameDate ? 'booking_schedule_updated' : 'booking_rescheduled', 'booking', $bookingId, [
                'old_date' => $oldDate,
                'new_date' => $newDate,
                'reason'   => $reason,
            ]);
        });
    }

    /**
     * Cancel a booking (A13 / T10).
     *
     * In ONE transaction:
     * - Locks booking row.
     * - Sets status = 'cancelled', cancelled_at = now.
     * - Marks verified down payments as forfeited (is_forfeited = 1, BR-2).
     * - Date capacity is freed automatically as confirmedCount only counts 'confirmed'.
     * - Logs change in booking_changes.
     * - Posts system chat notification.
     *
     * @throws RuntimeException
     */
    public static function cancel(int $bookingId, string $reason, int $userId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Please provide a reason for cancellation.');
        }

        DB::transaction(static function () use ($bookingId, $reason, $userId): void {
            $booking = DB::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }
            if ($booking['status'] === 'cancelled') {
                throw new RuntimeException('This booking is already cancelled.');
            }
            if ($booking['status'] === 'completed') {
                throw new RuntimeException('Completed bookings cannot be cancelled.');
            }

            $now = Clock::now();
            $oldStatus = $booking['status'];

            DB::update('bookings', [
                'status'       => 'cancelled',
                'cancelled_at' => $now,
            ], 'id = ?', [$bookingId]);

            // Verified down payments are non-refundable and kept as forfeited (BR-2)
            DB::run(
                "UPDATE payments SET is_forfeited = 1 WHERE booking_id = ? AND type = 'down' AND status = 'verified'",
                [$bookingId]
            );

            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'cancel',
                'old_value'   => $oldStatus,
                'new_value'   => 'cancelled',
                'reason'      => $reason,
                'changed_by'  => $userId,
                'changed_at'  => $now,
            ]);

            MessageService::postSystem(
                $bookingId,
                "This booking was cancelled. Down payment is forfeited per studio policy (non-refundable). Reason: {$reason}."
            );

            Audit::log('booking_cancelled', 'booking', $bookingId, [
                'old_status' => $oldStatus,
                'reason'     => $reason,
            ]);
        });
    }

    /**
     * Add an internal staff/crew note to a booking (A6).
     *
     * @return int the inserted note id
     */
    public static function addNote(int $bookingId, string $body, bool $visibleToCrew, int $userId): int
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Note text cannot be empty.');
        }

        return DB::transaction(static function () use ($bookingId, $trimmed, $visibleToCrew, $userId): int {
            $booking = DB::one('SELECT id FROM bookings WHERE id = ?', [$bookingId]);
            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }

            $now = Clock::now();
            $noteId = DB::insert('booking_notes', [
                'booking_id'      => $bookingId,
                'body'            => $trimmed,
                'visible_to_crew' => $visibleToCrew ? 1 : 0,
                'created_by'      => $userId,
                'created_at'      => $now,
            ]);

            $visibility = $visibleToCrew ? ' (crew visible)' : ' (staff only)';
            DB::insert('booking_changes', [
                'booking_id'  => $bookingId,
                'change_type' => 'note',
                'new_value'   => null,
                'reason'      => 'Internal note added' . $visibility,
                'changed_by'  => $userId,
                'changed_at'  => $now,
            ]);

            Audit::log('booking_note_added', 'booking', $bookingId, [
                'note_id'         => $noteId,
                'visible_to_crew' => $visibleToCrew,
            ]);

            return $noteId;
        });
    }
}
