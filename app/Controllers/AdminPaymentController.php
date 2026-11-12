<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Validator;
use App\Models\Booking;
use App\Services\DateUnavailableException;
use App\Services\DuplicateReferenceException;
use App\Services\PaymentException;
use App\Services\PaymentService;

/**
 * A7 Payments to Verify and A6 Record Balance Payment.
 */
final class AdminPaymentController
{
    /** A7: Queue of pending GCash screenshots waiting for manual verification. */
    public function index(): void
    {
        $queue = Booking::pendingPaymentsQueue();
        view('admin/payments', [
            'title' => 'Payments to verify (' . count($queue) . ')',
            'screen' => 'A7',
            'back' => '/admin',
            'queue' => $queue,
        ], 'admin');
    }

    /** POST /admin/payments/{id}/verify */
    public function verify(string $id): void
    {
        $paymentId = (int) $id;
        $ackDuplicate = !empty($_POST['ack_duplicate']);

        try {
            $bookingId = PaymentService::verify($paymentId, (int) Auth::id(), $ackDuplicate);
            Flash::set('success', 'Payment verified and booking confirmed ✓');
            redirect('/admin/bookings/' . $bookingId . '/confirmation');
        } catch (DuplicateReferenceException $e) {
            Flash::set('error', $e->getMessage());
            redirect('/admin/payments#payment-' . $paymentId);
        } catch (DateUnavailableException $e) {
            Flash::set('error', $e->getMessage());
            redirect('/admin/payments#payment-' . $paymentId);
        } catch (PaymentException $e) {
            Flash::set('error', $e->getMessage());
            redirect('/admin/payments');
        }
    }

    /** POST /admin/payments/{id}/reject */
    public function reject(string $id): void
    {
        $paymentId = (int) $id;
        $choice = trim((string) ($_POST['reason_choice'] ?? ''));
        $custom = trim((string) ($_POST['reason_custom'] ?? ''));

        $reason = match ($choice) {
            'unclear'  => 'Screenshot is unclear or unreadable',
            'mismatch' => 'Amount does not match the required down payment',
            'other'    => $custom !== '' ? $custom : 'Payment proof could not be verified',
            default    => $custom !== '' ? $custom : $choice,
        };

        if ($reason === '') {
            Flash::set('error', 'Please choose a reason for rejecting this payment.');
            redirect('/admin/payments#reject-' . $paymentId);
        }

        try {
            PaymentService::reject($paymentId, (int) Auth::id(), $reason);
            Flash::set('info', 'Payment marked as rejected. The client can re-upload from their booking page.');
        } catch (PaymentException $e) {
            Flash::set('error', $e->getMessage());
        }
        redirect('/admin/payments');
    }

    /** POST /admin/bookings/{id}/payments (A6 "Record balance payment"). */
    public function record(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $errors = Validator::make($_POST, [
            'amount' => 'required|money',
            'method' => 'required|in:cash,gcash,other',
            'type'   => 'in:balance,other',
        ]);
        $method = (string) ($_POST['method'] ?? 'cash');
        $gcashRef = preg_replace('/\s+/', '', (string) ($_POST['gcash_reference_no'] ?? ''));
        if ($method === 'gcash' && $gcashRef !== '') {
            $refErr = Validator::make(['gcash_reference_no' => $gcashRef], ['gcash_reference_no' => 'gcash_ref']);
            $errors = array_merge($errors, $refErr);
        }

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', reset($errors));
            redirect('/admin/bookings/' . $bookingId . '?tab=payments#record-payment');
        }

        $rawType = (string) ($_POST['type'] ?? 'balance');
        $type = in_array($rawType, ['balance', 'other'], true) ? $rawType : 'balance';
        $amount = (float) $_POST['amount'];
        $note = trim((string) ($_POST['notes'] ?? ''));

        try {
            $paymentId = PaymentService::record($bookingId, (int) Auth::id(), $type, $method, $amount, $gcashRef, $note);
            Flash::set('success', 'Payment recorded ✓ Official receipt issued.');
            redirect('/admin/bookings/' . $bookingId . '/confirmation?tab=receipt&payment_id=' . $paymentId);
        } catch (PaymentException $e) {
            Flash::withInput($_POST);
            Flash::set('error', $e->getMessage());
            redirect('/admin/bookings/' . $bookingId . '?tab=payments#record-payment');
        }
    }
}
