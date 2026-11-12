<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\Booking;
use App\Models\Package;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\DateUnavailableException;

/**
 * A4 Bookings Ledger and A6 Booking Details Hub.
 */
final class AdminBookingController
{
    /** A4: Searchable bookings ledger with filter chips and balances. */
    public function index(): void
    {
        $allowed = ['all', 'pending', 'confirmed', 'balance_due', 'completed', 'archived'];
        $rawFilter = (string) ($_GET['filter'] ?? 'all');
        $filter = in_array($rawFilter, $allowed, true) ? $rawFilter : 'all';
        $q = trim((string) ($_GET['q'] ?? ''));

        $bookings = Booking::ledger($filter, $q);

        view('admin/bookings', [
            'title'    => 'Bookings',
            'screen'   => 'A4',
            'actions'  => '<a class="topbar-btn" href="' . e(url('/admin/bookings/new')) . '" aria-label="New booking">' . icon('plus') . '</a>',
            'filter'   => $filter,
            'q'        => $q,
            'bookings' => $bookings,
        ], 'admin');
    }

    /** A6: Booking details hub (Overview, Payments, Crew, Project, Files, Notes). */
    public function show(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $allowedTabs = ['overview', 'payments', 'crew', 'project', 'files', 'notes'];
        $defaultTab = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)
            ? (string) $_GET['tab']
            : 'payments';

        $payments = Booking::payments($bookingId);
        $paid = Booking::paidTotal($bookingId);
        $balance = Booking::balance($booking);
        $downPayment = Booking::latestDownPayment($bookingId);

        view('admin/booking_show', [
            'title'       => 'Booking details',
            'screen'      => 'A6',
            'back'        => '/admin/bookings',
            'booking'     => $booking,
            'tab'         => $defaultTab,
            'payments'    => $payments,
            'paid'        => $paid,
            'balance'     => $balance,
            'downPayment' => $downPayment,
            'addons'      => Booking::addons($bookingId),
            'inclusions'  => Package::inclusions($booking['inclusions']),
            'changes'     => Booking::changes($bookingId),
            'crew'        => Booking::crew($bookingId),
            'project'     => Booking::project($bookingId),
            'notes'       => Booking::notes($bookingId),
        ], 'admin');
    }

    /** POST /admin/bookings/{id}/archive (BR-8: archive with restore instead of delete). */
    public function toggleArchive(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $archive = empty($booking['is_archived']) ? 1 : 0;
        $now = Clock::now();
        DB::update('bookings', [
            'is_archived' => $archive,
            'archived_at' => $archive ? $now : null,
            'archived_by' => $archive ? Auth::id() : null,
        ], 'id = ?', [$bookingId]);

        DB::insert('booking_changes', [
            'booking_id'  => $bookingId,
            'change_type' => 'note',
            'reason'      => $archive ? 'Booking archived' : 'Booking restored from archive',
            'changed_by'  => Auth::id(),
            'changed_at'  => $now,
        ]);

        Audit::log($archive ? 'booking_archived' : 'booking_restored', 'booking', $bookingId);
        Flash::set('success', $archive ? 'Booking archived. You can restore it anytime from the Archived filter.' : 'Booking restored ✓');
        redirect('/admin/bookings/' . $bookingId);
    }

    /** A5: New booking entry form. */
    public function newBookingForm(): void
    {
        $date = (string) ($_GET['date'] ?? '');
        $date = Validator::isDate($date) ? $date : '';

        $packages = Package::bookable();
        $addons = DB::all('SELECT * FROM addons WHERE is_active = 1 ORDER BY id ASC');
        $eventTypes = Settings::list('event_types');

        view('admin/booking_new', [
            'title'             => 'New Booking',
            'screen'            => 'A5',
            'back'              => '/admin/bookings',
            'packages'          => $packages,
            'addons'            => $addons,
            'eventTypes'        => $eventTypes,
            'date'              => $date,
            'canRecordPayments' => Auth::isOwner() || Auth::can('record_payments'),
            'scripts'           => ['admin_booking.js'],
        ], 'admin');
    }

    /**
     * Validate direct booking entry on Screen A5.
     * Exposed as a public static helper so tests and CLI runners can verify validation & authorization without exit.
     *
     * @return array<string, string> Validation errors (empty if valid)
     */
    public static function validateNewBooking(array $post): array
    {
        $eventTypes = Settings::list('event_types');
        $channels = ['messenger', 'call', 'walk_in', 'referral', 'website'];

        $errors = Validator::make($post, [
            'source_channel'   => 'required|in:' . implode(',', $channels),
            'full_name'        => 'required|max:120',
            'mobile'           => 'required|mobile',
            'messenger_name'   => 'max:150',
            'event_type'       => 'required|in:' . implode(',', $eventTypes),
            'package_id'       => 'required|integer',
            'event_date'       => 'required|date|future',
            'start_time'       => 'time',
            'end_time'         => 'time|after_time:start_time',
            'venue'            => 'required|max:255',
            'special_requests' => 'max:1000',
        ]);

        $package = isset($errors['package_id']) ? null : Package::bookableById((int) ($post['package_id'] ?? 0));
        if (!isset($errors['package_id']) && !$package) {
            $errors['package_id'] = 'Please choose a valid package.';
        }

        $date = trim((string) ($post['event_date'] ?? ''));
        $downPaymentReceived = !empty($post['down_payment_received']);

        if ($downPaymentReceived) {
            if (!Auth::isOwner() && !Auth::can('record_payments')) {
                $errors['payment_amount'] = 'You do not have permission to record payments.';
                $errors['down_payment_received'] = 'You do not have permission to record payments.';
            }

            $downAmount = (float) str_replace(',', '', (string) ($post['payment_amount'] ?? '0'));
            if (!isset($errors['payment_amount']) && $downAmount <= 0) {
                $errors['payment_amount'] = 'Please enter the down payment amount received.';
            }
            $method = (string) ($post['payment_method'] ?? 'cash');
            if (!in_array($method, ['cash', 'gcash', 'other'], true)) {
                $errors['payment_method'] = 'Invalid payment method.';
            }
            if ($method === 'gcash' && trim((string) ($post['payment_ref'] ?? '')) === '') {
                $errors['payment_ref'] = 'Please enter the GCash reference number.';
            }

            // Down payment received now confirms immediately; date MUST be open
            if (!isset($errors['event_date']) && AvailabilityService::reason($date) !== null) {
                $errors['event_date'] = AvailabilityService::message(AvailabilityService::reason($date));
            }
        }

        return $errors;
    }

    /** POST A5: Direct booking submission (walk-in / call / messenger). */
    public function createNewBooking(): void
    {
        $errors = self::validateNewBooking($_POST);
        $date = trim((string) ($_POST['event_date'] ?? ''));

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please check the highlighted fields.');
            redirect('/admin/bookings/new' . (Validator::isDate($date) ? '?date=' . $date : ''));
        }

        $packageId = (int) ($_POST['package_id'] ?? 0);
        $downPaymentReceived = !empty($_POST['down_payment_received']);
        $addons = isset($_POST['addons']) && is_array($_POST['addons'])
            ? array_map('intval', $_POST['addons'])
            : [];

        try {
            $result = BookingService::createDirect([
                'source_channel'        => (string) $_POST['source_channel'],
                'full_name'             => trim((string) $_POST['full_name']),
                'mobile'                => (string) $_POST['mobile'],
                'messenger_name'        => trim((string) ($_POST['messenger_name'] ?? '')),
                'event_type'            => (string) $_POST['event_type'],
                'package_id'            => $packageId,
                'addons'                => $addons,
                'event_date'            => $date,
                'start_time'            => trim((string) ($_POST['start_time'] ?? '')),
                'end_time'              => trim((string) ($_POST['end_time'] ?? '')),
                'venue'                 => trim((string) $_POST['venue']),
                'special_requests'      => trim((string) ($_POST['special_requests'] ?? '')),
                'down_payment_received' => $downPaymentReceived,
                'payment_amount'        => (float) ($_POST['payment_amount'] ?? 0),
                'payment_method'        => (string) ($_POST['payment_method'] ?? 'cash'),
                'payment_ref'           => trim((string) ($_POST['payment_ref'] ?? '')),
                'payment_notes'         => trim((string) ($_POST['payment_notes'] ?? '')),
            ], (int) Auth::id());

            $booking = $result['booking'];
            $msg = 'Booking ' . e($booking['reference_code']) . ' created ✓';
            if ($downPaymentReceived && !empty($result['payment']['receipt_no'])) {
                $msg .= ' Down payment verified (Official Receipt ' . $result['payment']['receipt_no'] . ').';
            }
            Flash::set('success', $msg);
            redirect('/admin/bookings/' . (int) $booking['id']);
        } catch (\App\Services\DateUnavailableException $e) {
            Flash::withInput($_POST, ['event_date' => $e->getMessage()]);
            Flash::set('error', $e->getMessage());
            redirect('/admin/bookings/new');
        } catch (\Throwable $e) {
            Flash::withInput($_POST);
            Flash::set('error', 'Unable to create booking: ' . $e->getMessage());
            redirect('/admin/bookings/new');
        }
    }

    /** POST /admin/bookings/{id}/notes: Add internal staff/crew note. */
    public function addNote(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            Flash::set('error', 'Note cannot be empty.');
            redirect('/admin/bookings/' . $bookingId . '?tab=notes');
        }
        if (mb_strlen($body) > 2000) {
            Flash::set('error', 'Note is too long (maximum 2,000 characters).');
            redirect('/admin/bookings/' . $bookingId . '?tab=notes');
        }

        $visibleToCrew = !empty($_POST['visible_to_crew']);
        BookingService::addNote($bookingId, $body, $visibleToCrew, (int) Auth::id());

        Flash::set('success', 'Internal note added ✓');
        redirect('/admin/bookings/' . $bookingId . '?tab=notes');
    }

    /** A13: Reschedule or Cancel form. */
    public function showChangeForm(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        if ($booking['status'] === 'cancelled') {
            Flash::set('info', 'This booking has already been cancelled.');
            redirect('/admin/bookings/' . $bookingId);
        }

        if ($booking['status'] === 'completed') {
            Flash::set('error', 'Completed bookings cannot be rescheduled or cancelled.');
            redirect('/admin/bookings/' . $bookingId);
        }

        $mode = (string) ($_GET['mode'] ?? 'reschedule');
        if (!in_array($mode, ['reschedule', 'cancel'], true)) {
            $mode = 'reschedule';
        }

        $paid = Booking::paidTotal($bookingId);
        $downPayment = Booking::latestDownPayment($bookingId);
        $verifiedDownAmount = 0.0;
        if ($downPayment && $downPayment['status'] === 'verified') {
            $verifiedDownAmount = (float) $downPayment['amount'];
        }

        view('admin/booking_change', [
            'title'              => 'Reschedule or Cancel',
            'screen'             => 'A13',
            'back'               => '/admin/bookings/' . $bookingId,
            'booking'            => $booking,
            'mode'               => $mode,
            'paid'               => $paid,
            'verifiedDownAmount' => $verifiedDownAmount,
            'canCancel'          => Auth::isOwner() || Auth::can('archive_records'),
            'scripts'            => ['admin_booking.js'],
        ], 'admin');
    }

    /**
     * Validate cancellation submission on Screen A13.
     * Exposed as a public static helper so tests and CLI runners can verify confirmation without exit.
     *
     * @param array<string, mixed> $post
     * @return array<string, string> Validation errors (empty if valid)
     */
    public static function validateCancel(array $post): array
    {
        $errors = [];
        if (empty($post['confirm_forfeit'])) {
            $errors['confirm_forfeit'] = 'Please confirm that the client understands the down payment is forfeited without refund.';
        }
        $reason = trim((string) ($post['reason'] ?? ''));
        if ($reason === '') {
            $errors['reason'] = 'Please provide a reason for cancellation.';
        }
        return $errors;
    }

    /** POST A13: Apply reschedule or cancellation. */
    public function applyChange(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        if ($booking['status'] === 'cancelled') {
            Flash::set('error', 'This booking is already cancelled.');
            redirect('/admin/bookings/' . $bookingId);
        }

        if ($booking['status'] === 'completed') {
            Flash::set('error', 'Completed bookings cannot be rescheduled or cancelled.');
            redirect('/admin/bookings/' . $bookingId);
        }

        $action = (string) ($_POST['action'] ?? 'reschedule');

        if ($action === 'cancel') {
            if (!Auth::isOwner() && !Auth::can('archive_records')) {
                abort(403, 'Permission denied: archive_records required to cancel bookings.');
            }

            $errors = self::validateCancel($_POST);
            if ($errors) {
                Flash::withInput($_POST, $errors);
                Flash::set('error', reset($errors));
                redirect('/admin/bookings/' . $bookingId . '/change?mode=cancel');
            }

            $reason = trim((string) $_POST['reason']);

            BookingService::cancel($bookingId, $reason, (int) Auth::id());
            Flash::set('success', 'Booking cancelled. Verified down payment has been marked as forfeited (no refund).');
            redirect('/admin/bookings/' . $bookingId);
        }

        // Action is reschedule
        $errors = Validator::make($_POST, [
            'new_date'   => 'required|date|future',
            'start_time' => 'time',
            'end_time'   => 'time|after_time:start_time',
            'reason'     => 'required|max:500',
        ]);

        $newDate = trim((string) ($_POST['new_date'] ?? ''));
        $startTime = trim((string) ($_POST['start_time'] ?? '')) ?: null;
        $endTime = trim((string) ($_POST['end_time'] ?? '')) ?: null;
        $venue = trim((string) ($_POST['venue'] ?? '')) ?: null;

        $normTime = static function (?string $t): ?string {
            if ($t === null) return null;
            $t = trim($t);
            return $t !== '' ? substr($t, 0, 5) : null;
        };

        $dateChanged = ($newDate !== $booking['event_date']);
        $startChanged = ($normTime($startTime) !== $normTime($booking['start_time']));
        $endChanged = ($normTime($endTime) !== $normTime($booking['end_time']));
        $venueChanged = ($venue !== null && $venue !== trim((string) ($booking['venue'] ?? '')));

        if (!isset($errors['new_date']) && !$dateChanged && !$startChanged && !$endChanged && !$venueChanged) {
            $errors['new_date'] = 'Please choose a different date, time, or venue.';
        }

        if (!isset($errors['new_date'])) {
            $reasonCode = AvailabilityService::reason($newDate, $bookingId);
            if ($reasonCode !== null) {
                $errors['new_date'] = AvailabilityService::message($reasonCode);
            }
        }

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please resolve the highlighted issues.');
            redirect('/admin/bookings/' . $bookingId . '/change?mode=reschedule');
        }

        $reason = trim((string) $_POST['reason']);

        try {
            BookingService::reschedule($bookingId, $newDate, $startTime, $endTime, $venue, $reason, (int) Auth::id());
            $successMsg = $dateChanged
                ? 'Booking rescheduled to ' . date_short($newDate) . ' ✓ Free reschedule applied.'
                : 'Event schedule and venue updated ✓';
            Flash::set('success', $successMsg);
            redirect('/admin/bookings/' . $bookingId);
        } catch (\App\Services\DateUnavailableException $e) {
            Flash::withInput($_POST, ['new_date' => $e->getMessage()]);
            Flash::set('error', $e->getMessage());
            redirect('/admin/bookings/' . $bookingId . '/change?mode=reschedule');
        }
    }
}
