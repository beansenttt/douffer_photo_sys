<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Validator;
use App\Models\Package;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\UploadException;
use App\Services\UploadService;

/** C3 Booking Details (step 1 of 2) and C4 Summary & GCash Payment (step 2). */
final class ClientBookController
{
    private const UNAVAILABLE = 'Sorry, this date is not available. Please choose another date.';

    // ------------------------------------------------------------------ C3
    public function details(): void
    {
        $draft = (array) Session::get('booking_draft', []);
        $date = (string) ($_GET['date'] ?? ($draft['event_date'] ?? ''));

        // Only show the form for an open date (the posted date is shown again after an error).
        if (!Flash::hasErrors() && ($date === '' || AvailabilityService::reason($date) !== null)) {
            Flash::set('error', self::UNAVAILABLE);
            redirect('/availability');
        }

        view('client/book', [
            'title'      => 'Booking Details',
            'screen'     => 'C3',
            'back'       => '/availability' . (Validator::isDate($date) ? '?date=' . $date : ''),
            'navActive'  => 'availability',
            'hideFab'    => true,
            'draft'      => $draft,
            'date'       => $date,
            'packages'   => Package::bookable(),
            'eventTypes' => Settings::list('event_types'),
        ], 'client');
    }

    public function saveDetails(): void
    {
        $eventTypes = Settings::list('event_types');
        $errors = Validator::make($_POST, [
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
            'privacy_consent'  => 'accepted',
        ]);

        $package = isset($errors['package_id']) ? null : Package::bookableById((int) $_POST['package_id']);
        if (!isset($errors['package_id']) && !$package) {
            $errors['package_id'] = 'Please choose a package.';
        }
        $date = trim((string) ($_POST['event_date'] ?? ''));
        if (!isset($errors['event_date']) && AvailabilityService::reason($date) !== null) {
            $errors['event_date'] = self::UNAVAILABLE;
        }

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please check the highlighted fields.');
            redirect('/book' . (Validator::isDate($date) ? '?date=' . $date : ''));
        }

        Session::put('booking_draft', [
            'full_name'          => trim((string) $_POST['full_name']),
            'mobile'             => normalize_mobile((string) $_POST['mobile']),
            'messenger_name'     => trim((string) ($_POST['messenger_name'] ?? '')),
            'event_type'         => (string) $_POST['event_type'],
            'package_id'         => (int) $package['id'],
            'event_date'         => $date,
            'start_time'         => trim((string) ($_POST['start_time'] ?? '')),
            'end_time'           => trim((string) ($_POST['end_time'] ?? '')),
            'venue'              => trim((string) $_POST['venue']),
            'special_requests'   => trim((string) ($_POST['special_requests'] ?? '')),
            'privacy_consent_at' => Clock::now(),
        ]);
        redirect('/book/pay');
    }

    // ------------------------------------------------------------------ C4
    public function pay(): void
    {
        $draft = $this->requireDraft();
        $package = Package::bookableById((int) $draft['package_id']);
        if (!$package) {
            Flash::set('error', 'That package is no longer available. Please choose another.');
            redirect('/book?date=' . $draft['event_date']);
        }
        if (AvailabilityService::reason($draft['event_date']) !== null) {
            Flash::set('error', self::UNAVAILABLE);
            redirect('/availability');
        }

        $total = (float) $package['price'];
        $down = BookingService::downPaymentFor($package);
        $terms = \App\Core\DB::value('SELECT body FROM terms_versions WHERE version = ?', [Settings::get('current_terms_version', '1')]);

        view('client/pay', [
            'title'     => 'Summary and Payment',
            'screen'    => 'C4',
            'back'      => '/book',
            'navActive' => 'availability',
            'hideFab'   => true,
            'scripts'   => ['pay.js'],
            'draft'     => $draft,
            'package'   => $package,
            'total'     => $total,
            'down'      => $down,
            'balance'   => $total - $down,
            'terms'     => (string) $terms,
            'gcash'     => [
                'name'   => Settings::get('gcash_name'),
                'number' => Settings::get('gcash_number'),
                'qr'     => Settings::get('gcash_qr_path'),
            ],
        ], 'client');
    }

    public function submitPay(): void
    {
        $draft = Session::get('booking_draft');
        if (!is_array($draft)) {
            // Double tap / back button after a successful submit: go to the booking just made.
            $last = Session::get('last_booking_ref');
            redirect(is_string($last) ? '/booking/' . $last : '/availability');
        }

        // Spam brake: 10 booking submissions per hour per IP.
        if (!RateLimiter::hit('book:ip:' . client_ip(), 10, 3600)) {
            abort(429, 'Too many booking attempts. Please try again later or message the studio.');
        }

        $errors = Validator::make($_POST, [
            'gcash_reference_no' => 'required|gcash_ref',
            'terms_ack'          => 'accepted',
        ]);

        $package = Package::bookableById((int) $draft['package_id']);
        if (!$package) {
            Flash::set('error', 'That package is no longer available. Please choose another.');
            redirect('/book?date=' . $draft['event_date']);
        }
        if (AvailabilityService::reason($draft['event_date']) !== null) {
            Flash::set('error', self::UNAVAILABLE);
            redirect('/availability');
        }

        $path = $thumb = null;
        try {
            [$path, $thumb] = UploadService::saveImage($_FILES['receipt'] ?? [], 'receipts');
        } catch (UploadException $e) {
            $errors['receipt'] = $e->getMessage();
        }

        if ($errors) {
            UploadService::delete($path, $thumb);
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please check the highlighted fields.');
            redirect('/book/pay');
        }

        try {
            $booking = BookingService::createPending(
                $draft,
                preg_replace('/\s+/', '', (string) $_POST['gcash_reference_no']),
                $path,
                $thumb
            );
        } catch (\Throwable $e) {
            UploadService::delete($path, $thumb);
            throw $e;
        }

        // The visitor may now open this booking (and its files/chat) without an account.
        $this->grantAccess((int) $booking['id']);
        Session::forget('booking_draft');
        Session::put('last_booking_ref', $booking['reference_code']);
        Flash::set('success', 'Booking submitted ✓ Save your reference number.');
        redirect('/booking/' . $booking['reference_code']);
    }

    // ------------------------------------------------------------------ helpers
    private function requireDraft(): array
    {
        $draft = Session::get('booking_draft');
        if (!is_array($draft)) {
            Flash::set('info', 'Please choose a date and enter your details first.');
            redirect('/availability');
        }
        return $draft;
    }

    /** Remember in the session which bookings this visitor proved ownership of. */
    public static function grantAccessTo(int $bookingId): void
    {
        $ids = (array) Session::get('client_bookings', []);
        if (!in_array($bookingId, $ids, true)) {
            $ids[] = $bookingId;
        }
        Session::put('client_bookings', $ids);
    }

    private function grantAccess(int $bookingId): void
    {
        self::grantAccessTo($bookingId);
    }
}
