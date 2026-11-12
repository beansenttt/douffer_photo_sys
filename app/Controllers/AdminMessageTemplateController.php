<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Models\Booking;
use App\Services\MessageTemplateService;
use Throwable;

/**
 * Screen A12: Client Message Templates Controller.
 * Handles template preview, bilingual toggle, placeholder interpolation, and dispatch actions.
 */
final class AdminMessageTemplateController
{
    /**
     * Show template editor, preview, and dispatch history for a booking.
     */
    public function index(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $templateKey = trim((string) ($_GET['template'] ?? 'booking_confirmed'));
        $lang = trim((string) ($_GET['lang'] ?? 'tl'));

        $templates = MessageTemplateService::getAll();
        if (empty($templates)) {
            abort(500, 'No message templates configured.');
        }

        // Validate templateKey exists among active templates
        $validKeys = array_column($templates, 'template_key');
        if (!in_array($templateKey, $validKeys, true)) {
            $templateKey = $validKeys[0] ?? 'booking_confirmed';
        }

        $interpolated = MessageTemplateService::getInterpolated($templateKey, $bookingId, $lang);
        $sentHistory = MessageTemplateService::getSentHistory($bookingId);
        $balance = Booking::balance($booking);

        view('admin/messages_templates', [
            'title'        => 'Client Messages',
            'screen'       => 'A12',
            'back'         => '/admin/bookings/' . $bookingId,
            'booking'      => $booking,
            'balance'      => $balance,
            'templates'    => $templates,
            'activeKey'    => $interpolated['template_key'],
            'lang'         => $interpolated['language'],
            'interpolated' => $interpolated,
            'sentHistory'  => $sentHistory,
        ], 'admin');
    }

    /**
     * Handle dispatch action: Mark as sent (channel: copy) or Send in app chat (channel: in_app).
     */
    public function send(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::findById($bookingId);
        if (!$booking) {
            abort(404, 'Booking not found.');
        }

        $actionType = trim((string) ($_POST['action_type'] ?? 'mark_sent'));
        $templateKey = trim((string) ($_POST['template_key'] ?? 'booking_confirmed'));
        $lang = trim((string) ($_POST['language'] ?? 'tl'));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($body === '') {
            Flash::set('error', 'Message body cannot be empty.');
            redirect('/admin/bookings/' . $bookingId . '/messages/templates?template=' . urlencode($templateKey) . '&lang=' . urlencode($lang));
        }

        $channel = ($actionType === 'send_chat') ? 'in_app' : 'copy';

        try {
            MessageTemplateService::logSent($bookingId, $templateKey, $lang, $body, $channel, Auth::id() ?? 1);
            if ($channel === 'in_app') {
                Flash::set('success', 'Message sent in client chat ✓');
            } else {
                Flash::set('success', 'Message marked as sent (Messenger) ✓');
            }
        } catch (Throwable $e) {
            Flash::set('error', 'Failed to dispatch message: ' . $e->getMessage());
        }

        redirect('/admin/bookings/' . $bookingId . '/messages/templates?template=' . urlencode($templateKey) . '&lang=' . urlencode($lang));
    }
}
