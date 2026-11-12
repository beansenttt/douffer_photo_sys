<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\DB;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Models\Booking;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\UploadException;
use App\Services\UploadService;

/**
 * Screen A19: Admin Messages Inbox & Thread (/admin/messages, /admin/messages/{id}).
 * Requires 'manage_messages' permission (crew and unauthorized staff get 403).
 */
final class AdminMessageController
{
    private const ALLOWED_FILTERS = ['all', 'unread', 'open', 'closed', 'inquiries'];

    /** Screen A19 Inbox (/admin/messages). */
    public function index(): void
    {
        $rawFilter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'all';
        $filter = in_array($rawFilter, self::ALLOWED_FILTERS, true) ? $rawFilter : 'all';
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $counts = Message::inboxCounts();
        $conversations = Message::inboxList($filter, $q);

        view('admin/messages_index', [
            'title'         => 'Messages',
            'screen'        => 'A19',
            'back'          => '/admin/more',
            'filter'        => $filter,
            'q'             => $q,
            'counts'        => $counts,
            'conversations' => $conversations,
        ], 'admin');
    }

    /** Screen A19 Thread view (/admin/messages/{id}). */
    public function show(string $id): void
    {
        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        MessageService::markRead($convId, 'admin');

        $messages = Message::messagesFor($convId, 0);
        $seenUpToId = Message::lastSeenIdForSender($convId, 'admin');
        $quickReplies = Message::quickReplies(true);

        $booking = null;
        $paid = 0.0;
        $balance = 0.0;
        $linkableBookings = [];
        $linkableClients = [];

        if (!empty($conv['booking_id'])) {
            $booking = Booking::find((int) $conv['booking_id']);
            if ($booking) {
                $paid = Booking::paidTotal((int) $booking['id']);
                $balance = Booking::balance($booking);
            }
        } else {
            $linkableBookings = DB::all(
                'SELECT b.id, b.reference_code, b.event_type, b.event_date, c.full_name
                 FROM bookings b
                 JOIN clients c ON c.id = b.client_id
                 WHERE b.is_archived = 0
                 ORDER BY b.id DESC
                 LIMIT 50'
            );
            $linkableClients = DB::all(
                'SELECT id, full_name, mobile FROM clients ORDER BY id DESC LIMIT 50'
            );
        }

        view('admin/messages_show', [
            'title'            => 'Chat · ' . $conv['display_name'],
            'screen'           => 'A19',
            'back'             => '/admin/messages',
            'scripts'          => ['pay.js', 'chat.js'],
            'conv'             => $conv,
            'messages'         => $messages,
            'seenUpToId'       => $seenUpToId,
            'quickReplies'     => $quickReplies,
            'booking'          => $booking,
            'paid'             => $paid,
            'balance'          => $balance,
            'linkableBookings' => $linkableBookings,
            'linkableClients'  => $linkableClients,
        ], 'admin');
    }

    /** Ensure booking conversation exists and redirect to its A19 thread (/admin/bookings/{id}/chat). */
    public function openForBooking(string $id): void
    {
        $bookingId = (int) $id;
        $booking = Booking::find($bookingId);
        if (!$booking) {
            throw new HttpException(404, 'Booking not found.');
        }
        $conv = MessageService::ensureBookingConversation($bookingId);
        redirect('/admin/messages/' . (int) $conv['id']);
    }

    /** Standard HTML form POST for sending an admin reply (/admin/messages/{id}/messages). */
    public function sendMessage(string $id): void
    {
        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        if (!RateLimiter::hit('chat:conv:' . $convId, 20, 60)) {
            abort(429, 'Too many messages sent. Please wait a moment.');
        }

        $attachmentPath = null;
        $thumbPath = null;
        $file = $_FILES['attachment'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                [$attachmentPath, $thumbPath] = UploadService::saveChatImage($file);
            } catch (UploadException $e) {
                Flash::withInput($_POST, ['attachment' => $e->getMessage()]);
                Flash::set('error', $e->getMessage());
                redirect('/admin/messages/' . $convId);
            }
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        try {
            MessageService::sendMessage($convId, 'admin', $body, $attachmentPath, Auth::id());
            Flash::set('success', 'Reply sent ✓');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            Flash::withInput($_POST, ['body' => $e->getMessage()]);
            Flash::set('error', $e->getMessage());
        } catch (\Throwable $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            throw $e;
        }

        redirect('/admin/messages/' . $convId);
    }

    /** Close or reopen a conversation (/admin/messages/{id}/status). */
    public function updateStatus(string $id): void
    {
        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $newStatus = isset($_POST['status']) && (string) $_POST['status'] === 'closed' ? 'closed' : 'open';
        MessageService::setStatus($convId, $newStatus, (int) Auth::id());

        Flash::set('success', $newStatus === 'closed' ? 'Conversation closed ✓' : 'Conversation reopened ✓');
        redirect('/admin/messages/' . $convId);
    }

    /** Link a pre-booking inquiry to a booking or client (/admin/messages/{id}/link). */
    public function link(string $id): void
    {
        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $bookingId = !empty($_POST['booking_id']) ? (int) $_POST['booking_id'] : null;
        $clientId = !empty($_POST['client_id']) ? (int) $_POST['client_id'] : null;

        try {
            $targetConvId = MessageService::linkConversation($convId, $bookingId, $clientId, (int) Auth::id());
            Flash::set('success', 'Conversation linked ✓');
            redirect('/admin/messages/' . $targetConvId);
        } catch (\InvalidArgumentException $e) {
            Flash::set('error', $e->getMessage());
            redirect('/admin/messages/' . $convId);
        }
    }

    /** Export conversation transcript as plain text (/admin/messages/{id}/export). */
    public function export(string $id): void
    {
        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $messages = Message::messagesFor($convId, 0);
        Audit::log('conversation_exported', 'conversation', $convId, [
            'message_count' => count($messages),
        ], Auth::id());

        $lines = [];
        $lines[] = 'DOUFFER PHOTOGRAPHY — CONVERSATION TRANSCRIPT';
        $lines[] = 'Conversation ID: #' . $convId;
        $lines[] = 'Client / Guest : ' . $conv['display_name'] . ($conv['display_mobile'] ? ' (' . format_mobile($conv['display_mobile']) . ')' : '');
        if (!empty($conv['reference_code'])) {
            $lines[] = 'Booking Ref    : ' . $conv['reference_code'] . ' (' . $conv['event_type'] . ' on ' . $conv['event_date'] . ')';
        } else {
            $lines[] = 'Type           : Pre-booking Inquiry';
        }
        $lines[] = 'Status         : ' . strtoupper((string) $conv['status']);
        $lines[] = str_repeat('-', 60);

        foreach ($messages as $m) {
            $head = '[' . $m['created_at'] . '] ' . strtoupper((string) $m['sender_type']) . ' (' . $m['sender_label'] . ')';
            if (!empty($m['read_at'])) {
                $head .= ' [Seen ' . $m['read_at'] . ']';
            }
            $lines[] = $head . ':';
            if ($m['body'] !== '') {
                $lines[] = '  ' . str_replace("\n", "\n  ", $m['body']);
            }
            if (!empty($m['attachment_path'])) {
                $lines[] = '  [Attachment: ' . $m['attachment_path'] . ']';
            }
            $lines[] = '';
        }

        $output = implode("\r\n", $lines);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="conversation-' . $convId . '.txt"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $output;
        exit;
    }
}
