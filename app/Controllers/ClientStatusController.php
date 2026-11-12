<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\UploadException;
use App\Services\UploadService;

/**
 * C5 Booking Status and the "My Booking" lookup.
 * A visitor proves ownership with reference no. + mobile no.; the booking id is then
 * remembered in the session. The id/ref in the URL is NEVER trusted on its own.
 */
final class ClientStatusController
{
    // ------------------------------------------------------------------ lookup (My Booking tab)
    public function lookupForm(): void
    {
        view('client/lookup', [
            'title'     => 'My Booking',
            'screen'    => 'C5',
            'back'      => '/',
            'navActive' => 'booking',
        ], 'client');
    }

    public function lookup(): void
    {
        $ip = 'lookup:ip:' . client_ip();
        if (RateLimiter::tooMany($ip, 10, 900)) {
            abort(429, 'Too many lookups. Please wait 15 minutes, or message the studio.');
        }

        $errors = Validator::make($_POST, [
            'reference_no' => 'required|max:30',
            'mobile'       => 'required|mobile',
        ]);
        if ($errors) {
            Flash::withInput($_POST, $errors);
            redirect('/booking');
        }

        $booking = Booking::findByRef((string) $_POST['reference_no']);
        // Same generic message for "no such reference" and "wrong mobile": no information leak.
        if (!$booking || $booking['mobile_normalized'] !== normalize_mobile((string) $_POST['mobile'])) {
            RateLimiter::hit($ip, 10, 900);
            Audit::log('booking_lookup_failed', 'booking', null, ['ref' => substr((string) $_POST['reference_no'], 0, 30)]);
            Flash::withInput($_POST);
            Flash::set('error', 'We could not find a booking with those details. Please check the reference number and mobile number.');
            redirect('/booking');
        }

        ClientBookController::grantAccessTo((int) $booking['id']);
        redirect('/booking/' . $booking['reference_code']);
    }

    // ------------------------------------------------------------------ C5
    public function show(string $ref): void
    {
        $booking = $this->ownedBooking($ref);
        $id = (int) $booking['id'];
        $payment = Booking::latestDownPayment($id);
        $status = (string) $booking['status'];

        // Progress: 1 request sent, 2 payment being checked, 3 booking confirmed.
        $step = in_array($status, ['confirmed', 'completed'], true) ? 3 : 2;

        view('client/status', [
            'title'     => 'Booking Status',
            'screen'    => 'C5',
            'back'      => '/booking',
            'navActive' => 'booking',
            'booking'   => $booking,
            'payment'   => $payment,
            'paid'      => Booking::paidTotal($id),
            'balance'   => Booking::balance($booking),
            'project'   => in_array($status, ['confirmed', 'completed'], true) ? Booking::project($id) : null,
            'step'      => $step,
            'rejected'  => $status === 'pending' && $payment && $payment['status'] === 'rejected',
        ], 'client');
    }

    /** Re-upload proof after a rejected payment. */
    public function reupload(string $ref): void
    {
        $booking = $this->ownedBooking($ref);
        $id = (int) $booking['id'];
        $last = Booking::latestDownPayment($id);

        if ($booking['status'] !== 'pending' || !$last || $last['status'] !== 'rejected') {
            Flash::set('info', 'There is nothing to re-upload for this booking.');
            redirect('/booking/' . $booking['reference_code']);
        }
        if (!RateLimiter::hit('reupload:ip:' . client_ip(), 10, 3600)) {
            abort(429, 'Too many attempts. Please try again later.');
        }

        $errors = Validator::make($_POST, ['gcash_reference_no' => 'required|gcash_ref']);
        $path = $thumb = null;
        try {
            [$path, $thumb] = UploadService::saveImage($_FILES['receipt'] ?? [], 'receipts');
        } catch (UploadException $e) {
            $errors['receipt'] = $e->getMessage();
        }
        if ($errors) {
            UploadService::delete($path, $thumb);
            Flash::withInput($_POST, $errors);
            redirect('/booking/' . $booking['reference_code'] . '#reupload');
        }

        try {
            BookingService::addPaymentProof($id, preg_replace('/\s+/', '', (string) $_POST['gcash_reference_no']), $path, $thumb);
        } catch (\Throwable $e) {
            UploadService::delete($path, $thumb);
            throw $e;
        }
        Flash::set('success', 'Thank you! We received your new screenshot and will check it soon.');
        redirect('/booking/' . $booking['reference_code']);
    }

    /** A8 as seen by the client (built in Phase 3). Ownership is already checked. */
    public function confirmation(string $ref): void
    {
        $booking = $this->ownedBooking($ref);
        if (!in_array((string) $booking['status'], ['confirmed', 'completed'], true)) {
            Flash::set('info', 'Your booking confirmation will be available once the studio verifies your GCash down payment.');
            redirect('/booking/' . $booking['reference_code']);
        }
        $doc = \App\Services\DocumentService::forBooking((int) $booking['id']);
        if (!$doc) {
            abort(404);
        }
        view('admin/confirmation', [
            'title'           => 'Booking Confirmation',
            'screen'          => 'A8',
            'back'            => '/booking/' . $booking['reference_code'],
            'navActive'       => 'booking',
            'doc'             => $doc,
            'tab'             => 'confirmation',
            'selectedPayment' => null,
            'messengerText'   => \App\Services\DocumentService::messengerText($doc),
            'isAdmin'         => false,
        ], 'client');
    }

    /** The booking, but only if this visitor proved ownership; otherwise back to the lookup form. */
    private function ownedBooking(string $ref): array
    {
        $booking = Booking::findByRef($ref);
        $granted = (array) Session::get('client_bookings', []);
        if (!$booking || !in_array((int) $booking['id'], $granted, true)) {
            Flash::set('info', 'Please enter your reference number and mobile number to view your booking.');
            redirect('/booking');
        }
        return $booking;
    }
}
