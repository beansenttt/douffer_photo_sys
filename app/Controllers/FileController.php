<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Session;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\UploadService;

/**
 * Serves private uploads from storage/ (outside the web root) after an access check.
 * Allowed: owner/staff who may see bookings/payments/messages, or the visitor who proved
 * ownership of that booking or chat conversation. Everyone else gets a 403/404.
 */
final class FileController
{
    public function receipt(string $id): void
    {
        $this->serveReceipt((int) $id, false);
    }

    public function receiptThumb(string $id): void
    {
        $this->serveReceipt((int) $id, true);
    }

    /** Serve a chat image attachment (messages.id). */
    public function chatAttachment(string $id): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chat files.');
        }

        $msg = DB::one('SELECT id, conversation_id, attachment_path FROM messages WHERE id = ?', [(int) $id]);
        if (!$msg || empty($msg['attachment_path'])) {
            throw new HttpException(404);
        }

        if (!$this->mayViewChat((int) $msg['conversation_id'])) {
            throw new HttpException(404);
        }

        $this->send((string) $msg['attachment_path'], 'chat-attachment');
    }

    /** Serve the studio GCash QR image uploaded in Settings A20. */
    public function gcashQr(): void
    {
        $rel = \App\Core\Settings::get('gcash_qr_path', '');
        $this->send($rel, 'gcash-qr');
    }

    private function serveReceipt(int $paymentId, bool $thumb): void
    {
        $p = DB::one('SELECT booking_id, receipt_path, receipt_thumb_path FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || !$this->mayView((int) $p['booking_id'])) {
            throw new HttpException(404);
        }
        $rel = $thumb ? ($p['receipt_thumb_path'] ?: $p['receipt_path']) : $p['receipt_path'];
        $this->send($rel, 'receipt');
    }

    private function mayView(int $bookingId): bool
    {
        if (Auth::isAdmin() && (Auth::can('view_bookings') || Auth::can('verify_payments') || Auth::can('record_payments'))) {
            return true;
        }
        return in_array($bookingId, (array) Session::get('client_bookings', []), true);
    }

    private function mayViewChat(int $convId): bool
    {
        if (Auth::isAdmin() && Auth::can('manage_messages')) {
            return true;
        }
        $conv = Message::findConversation($convId);
        if (!$conv) {
            return false;
        }
        $token = isset($_GET['token']) ? (string) $_GET['token'] : null;
        return MessageService::clientOwnsConversation($conv, $token);
    }

    private function send(?string $rel, string $prefix = 'file'): void
    {
        // Only allow our own "folder/file.ext" layout: no "..", no absolute paths.
        if (!$rel || !preg_match('#^[a-z]+/[A-Za-z0-9._-]+$#', $rel) || !is_file(storage_path($rel))) {
            throw new HttpException(404);
        }
        $file = storage_path($rel);
        header('Content-Type: ' . UploadService::mimeForPath($rel));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: inline; filename="' . $prefix . '.' . pathinfo($rel, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
