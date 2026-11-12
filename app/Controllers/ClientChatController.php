<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Booking;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\UploadException;
use App\Services\UploadService;

/**
 * Screen C6: Client Chat (in-app messaging with the studio).
 * Supports both booking conversations (/booking/{ref}/chat) and pre-booking
 * inquiry conversations (/chat, /chat/t/{token}) without requiring a client account.
 */
final class ClientChatController
{
    /** Entry point for /chat. */
    public function index(): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $bookingRef = isset($_GET['booking']) ? trim((string) $_GET['booking']) : '';
        if ($bookingRef !== '') {
            $this->bookingChat($bookingRef);
            return;
        }

        $convParam = isset($_GET['conv']) ? (int) $_GET['conv'] : 0;
        if ($convParam > 0) {
            $conv = Message::findConversation($convParam);
            $tokens = (array) Session::get('client_conv_tokens', []);
            $tok = isset($tokens[$convParam]) ? (string) $tokens[$convParam] : null;
            if (!$conv || !MessageService::clientOwnsConversation($conv, $tok)) {
                throw new HttpException(403, 'You do not have access to this conversation.');
            }
            $this->renderThread($conv, $tok);
            return;
        }

        $wantNew = !empty($_GET['new']) || isset($_GET['service']);
        $ownedList = $this->ownedConversations();

        if (!$wantNew && !empty($ownedList)) {
            $latest = $ownedList[0];
            $tokens = (array) Session::get('client_conv_tokens', []);
            $tok = isset($tokens[(int) $latest['id']]) ? (string) $tokens[(int) $latest['id']] : null;
            $this->renderThread($latest, $tok);
            return;
        }

        $serviceName = isset($_GET['service']) ? trim((string) $_GET['service']) : '';
        $prefillBody = $serviceName !== ''
            ? 'Hi! I would like to ask about ' . $serviceName . '.'
            : '';

        view('client/chat', [
            'title'       => 'Chat with the Studio',
            'heading'     => 'Chat with the Studio',
            'screen'      => 'C6',
            'back'        => '/',
            'hideFab'     => true,
            'mode'        => 'start',
            'ownedList'   => $ownedList,
            'prefillBody' => $prefillBody,
            'conv'        => null,
            'messages'    => [],
            'token'       => null,
            'seenUpToId'  => 0,
        ], 'client');
    }

    /** Start a pre-booking inquiry thread (POST /chat/start). */
    public function startInquiry(): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        // Hidden honeypot check (§8.1): bots filling "website" get silently ignored.
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            Flash::set('success', 'Thank you! Your message was received.');
            redirect('/chat?new=1');
        }

        if (!RateLimiter::hit('chat_start:ip:' . client_ip(), 10, 900)) {
            abort(429, 'Too many inquiry requests. Please wait a few minutes and try again.');
        }

        $errors = Validator::make($_POST, [
            'guest_name'      => 'required|max:120',
            'guest_mobile'    => 'required|mobile',
            'body'            => 'required|max:1000',
            'privacy_consent' => 'accepted',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please check the highlighted fields.');
            redirect('/chat?new=1');
        }

        $res = MessageService::startInquiry(
            (string) $_POST['guest_name'],
            (string) $_POST['guest_mobile'],
            (string) $_POST['body']
        );

        MessageService::grantClientAccess((int) $res['conversation_id'], (string) $res['token']);
        Flash::set('success', 'Conversation started ✓ Bookmark or copy your chat link to return anytime.');
        redirect('/chat/t/' . $res['token']);
    }

    /** Open an inquiry conversation via its secret random token (GET /chat/t/{token}). */
    public function showByToken(string $token): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $conv = Message::findByToken($token);
        if (!$conv) {
            throw new HttpException(404, 'Conversation link not found.');
        }

        MessageService::grantClientAccess((int) $conv['id'], $token);
        $this->renderThread($conv, $token);
    }

    /** Open a booking's conversation (GET /booking/{ref}/chat). Requires prior lookup ownership. */
    public function bookingChat(string $ref): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $booking = Booking::findByRef($ref);
        $grantedBookings = (array) Session::get('client_bookings', []);
        if (!$booking || !in_array((int) $booking['id'], $grantedBookings, true)) {
            Flash::set('info', 'Please enter your reference number and mobile number to open your booking chat.');
            redirect('/booking');
        }

        $conv = MessageService::ensureBookingConversation((int) $booking['id']);
        MessageService::grantClientAccess((int) $conv['id']);
        $this->renderThread($conv, null);
    }

    /** Standard HTML form POST for sending a message (POST /chat/{id}/messages). */
    public function sendMessage(string $id): void
    {
        if (Auth::role() === 'crew') {
            throw new HttpException(403, 'Crew accounts cannot access client chats.');
        }

        $convId = (int) $id;
        $conv = Message::findConversation($convId);
        $token = trim((string) ($_POST['token'] ?? ''));

        if (!$conv || !MessageService::clientOwnsConversation($conv, $token !== '' ? $token : null)) {
            throw new HttpException(403, 'You do not have access to this conversation.');
        }

        if (!RateLimiter::hit('chat:conv:' . $convId, 10, 60) || !RateLimiter::hit('chat:ip:' . client_ip(), 10, 60)) {
            abort(429, 'Too many messages sent. Please wait a minute before sending more.');
        }

        $returnUrl = $this->threadUrl($conv, $token !== '' ? $token : null);

        $attachmentPath = null;
        $thumbPath = null;
        $file = $_FILES['attachment'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                [$attachmentPath, $thumbPath] = UploadService::saveChatImage($file);
            } catch (UploadException $e) {
                Flash::withInput($_POST, ['attachment' => $e->getMessage()]);
                Flash::set('error', $e->getMessage());
                redirect($returnUrl);
            }
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        try {
            MessageService::sendMessage($convId, 'client', $body, $attachmentPath, null);
            Flash::set('success', 'Message sent ✓');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            Flash::withInput($_POST, ['body' => $e->getMessage()]);
            Flash::set('error', $e->getMessage());
        } catch (\Throwable $e) {
            UploadService::delete($attachmentPath, $thumbPath);
            throw $e;
        }

        redirect($returnUrl);
    }

    private function renderThread(array $conv, ?string $token): void
    {
        $convId = (int) $conv['id'];
        MessageService::markRead($convId, 'client');

        $messages = Message::messagesFor($convId, 0);
        $seenUpToId = Message::lastSeenIdForSender($convId, 'client');
        $ownedList = $this->ownedConversations();

        $backPath = !empty($conv['reference_code'])
            ? '/booking/' . $conv['reference_code']
            : '/';

        view('client/chat', [
            'title'       => !empty($conv['reference_code'])
                ? 'Chat · ' . $conv['reference_code']
                : 'Chat with the Studio',
            'heading'     => !empty($conv['reference_code'])
                ? 'Chat · ' . $conv['reference_code']
                : 'Chat with the Studio',
            'screen'      => 'C6',
            'back'        => $backPath,
            'navActive'   => !empty($conv['reference_code']) ? 'booking' : '',
            'hideFab'     => true,
            'scripts'     => ['pay.js', 'chat.js'],
            'mode'        => 'thread',
            'conv'        => $conv,
            'messages'    => $messages,
            'token'       => $token,
            'seenUpToId'  => $seenUpToId,
            'ownedList'   => $ownedList,
            'prefillBody' => '',
        ], 'client');
    }

    /** Return all conversations the current visitor has unlocked in this session. */
    private function ownedConversations(): array
    {
        $convIds = (array) Session::get('client_conversations', []);
        $bookingIds = (array) Session::get('client_bookings', []);

        foreach ($bookingIds as $bid) {
            $bConv = MessageService::ensureBookingConversation((int) $bid);
            $cid = (int) $bConv['id'];
            if (!in_array($cid, $convIds, true)) {
                $convIds[] = $cid;
            }
        }

        $tok = Session::get('inquiry_token');
        if (is_string($tok) && $tok !== '') {
            $tConv = Message::findByToken($tok);
            if ($tConv && !in_array((int) $tConv['id'], $convIds, true)) {
                $convIds[] = (int) $tConv['id'];
            }
        }

        $list = [];
        foreach ($convIds as $cid) {
            $c = Message::findConversation((int) $cid);
            if ($c) {
                $list[] = $c;
            }
        }

        usort($list, static function (array $a, array $b): int {
            $ta = (string) ($a['last_message_at'] ?? $a['created_at']);
            $tb = (string) ($b['last_message_at'] ?? $b['created_at']);
            return strcmp($tb, $ta);
        });

        return $list;
    }

    private function threadUrl(array $conv, ?string $token): string
    {
        if ($token !== null && $token !== '') {
            return '/chat/t/' . $token;
        }
        if (!empty($conv['reference_code'])) {
            return '/booking/' . $conv['reference_code'] . '/chat';
        }
        return '/chat?conv=' . (int) $conv['id'];
    }
}
