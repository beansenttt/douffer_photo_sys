<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\UploadException;
use App\Services\UploadService;

/**
 * JSON API for light polling and AJAX message sending (PROMPT §8.3).
 *  - GET  /api/unread
 *  - GET  /api/conversations/{id}/messages?since_id=N
 *  - POST /api/conversations/{id}/messages
 */
final class ApiMessageController
{
    /** Global unread count for the admin topbar badge (polled every ~25-30s). */
    public function unread(): void
    {
        if (Auth::role() === 'crew' || !Auth::isAdmin() || !Auth::can('manage_messages')) {
            throw new HttpException(403, 'You do not have permission to view messages.');
        }

        json_response([
            'ok'     => true,
            'unread' => Message::unreadForAdmin(),
        ]);
    }

    /** Poll for new messages after since_id in conversation {id}. */
    public function poll(string $id): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $asParam = isset($_GET['as']) ? (string) $_GET['as'] : '';
        $token = isset($_GET['token']) ? (string) $_GET['token'] : (string) ($_SERVER['HTTP_X_CHAT_TOKEN'] ?? '');

        if ($asParam !== 'client' && Auth::isAdmin() && Auth::can('manage_messages')) {
            $viewerSide = 'admin';
        } elseif (MessageService::clientOwnsConversation($conv, $token)) {
            $viewerSide = 'client';
        } else {
            throw new HttpException(403, 'You do not have access to this conversation.');
        }

        $sinceId = max(0, (int) ($_GET['since_id'] ?? 0));
        MessageService::markRead($convId, $viewerSide);

        $messages = Message::messagesFor($convId, $sinceId);
        $seenUpToId = Message::lastSeenIdForSender($convId, $viewerSide);
        $unreadCount = $viewerSide === 'admin'
            ? Message::unreadForAdmin()
            : Message::unreadForClientConversation($convId);

        json_response([
            'ok'            => true,
            'status'        => (string) $conv['status'],
            'messages'      => $messages,
            'seen_up_to_id' => $seenUpToId,
            'unread_count'  => $unreadCount,
        ]);
    }

    /** Send a message (and optional <=2 MB image attachment) into conversation {id} via AJAX. */
    public function send(string $id): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new HttpException(404, 'Conversation not found.');
        }

        $asParam = isset($_POST['as']) ? (string) $_POST['as'] : (isset($_GET['as']) ? (string) $_GET['as'] : '');
        $token = isset($_POST['token']) ? (string) $_POST['token'] : (isset($_GET['token']) ? (string) $_GET['token'] : (string) ($_SERVER['HTTP_X_CHAT_TOKEN'] ?? ''));

        if ($asParam !== 'client' && Auth::isAdmin() && Auth::can('manage_messages')) {
            $senderType = 'admin';
            $senderUserId = Auth::id();
        } elseif (MessageService::clientOwnsConversation($conv, $token)) {
            $senderType = 'client';
            $senderUserId = null;
        } else {
            throw new HttpException(403, 'You do not have access to this conversation.');
        }

        // Rate limit: 10 messages per minute per conversation and per IP (§8.2).
        if (!RateLimiter::hit('chat:conv:' . $convId, 10, 60) || !RateLimiter::hit('chat:ip:' . client_ip(), 10, 60)) {
            json_response([
                'ok'    => false,
                'error' => 'Too many messages sent. Please wait a minute before sending more.',
            ], 429);
        }

        $attachmentPath = null;
        $thumbPath = null;
        $file = $_FILES['attachment'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                [$attachmentPath, $thumbPath] = UploadService::saveChatImage($file);
            } catch (UploadException $e) {
                json_response(['ok' => false, 'error' => $e->getMessage()], 422);
            }
        }

        $body = trim((string) ($_POST['body'] ?? ''));

        try {
            $msg = MessageService::sendMessage($convId, $senderType, $body, $attachmentPath, $senderUserId);
            json_response([
                'ok'      => true,
                'message' => $msg,
            ], 201);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            throw $e;
        }
    }
}
