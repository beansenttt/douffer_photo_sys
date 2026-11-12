<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Services\DocumentService;

/**
 * A8 Confirmation & Official Receipt (Admin view).
 */
final class AdminDocumentController
{
    public function show(string $id): void
    {
        $bookingId = (int) $id;
        $doc = DocumentService::forBooking($bookingId);
        if (!$doc) {
            abort(404, 'Booking not found.');
        }

        $tab = ($_GET['tab'] ?? 'confirmation') === 'receipt' ? 'receipt' : 'confirmation';
        $paymentId = isset($_GET['payment_id']) ? (int) $_GET['payment_id'] : 0;

        $selectedPayment = null;
        if ($doc['payments']) {
            if ($paymentId > 0) {
                $selectedPayment = DocumentService::receipt($doc, $paymentId);
            }
            if (!$selectedPayment) {
                $last = end($doc['payments']);
                $selectedPayment = DocumentService::receipt($doc, (int) $last['id']);
            }
        }

        view('admin/confirmation', [
            'title'           => 'Confirmation / Receipt',
            'screen'          => 'A8',
            'back'            => '/admin/bookings/' . $bookingId,
            'doc'             => $doc,
            'tab'             => $tab,
            'selectedPayment' => $selectedPayment,
            'messengerText'   => DocumentService::messengerText($doc),
            'isAdmin'         => true,
        ], 'admin');
    }
}
