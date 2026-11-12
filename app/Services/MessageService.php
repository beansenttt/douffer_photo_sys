<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Session;
use App\Models\Message;

/**
 * In-app messaging domain service (Screen C6 & Screen A19, PROMPT §8).
 * Handles system notices, pre-booking inquiry threads, booking threads,
 * sending messages with optional attachments, read receipts, close/reopen,
 * and linking inquiries to bookings or clients.
 */
final class MessageService
{
    /**
     * Post an automatic "system" message into a booking's conversation
     * (payment verified, rescheduled, output ready, cancelled, completed).
     */
    public static function postSystem(int $bookingId, string $body): void
    {
        $now = Clock::now();
        $convId = DB::value('SELECT id FROM conversations WHERE booking_id = ?', [$bookingId]);
        if (!$convId) {
            $clientId = DB::value('SELECT client_id FROM bookings WHERE id = ?', [$bookingId]);
            $convId = DB::insert('conversations', [
                'booking_id'      => $bookingId,
                'client_id'       => $clientId,
                'status'          => 'open',
                'last_message_at' => $now,
                'created_at'      => $now,
            ]);
        }
        DB::insert('messages', [
            'conversation_id' => (int) $convId,
            'sender_type'     => 'system',
            'body'            => mb_substr($body, 0, 1000),
            'created_at'      => $now,
        ]);
        DB::run('UPDATE conversations SET last_message_at = ? WHERE id = ?', [$now, $convId]);
    }

    /** Ensure a conversation row exists for a booking and return the enriched conversation. */
    public static function ensureBookingConversation(int $bookingId): array
    {
        $existing = Message::findByBookingId($bookingId);
        if ($existing) {
            return $existing;
        }

        $booking = DB::one('SELECT id, client_id, privacy_consent_at, created_at FROM bookings WHERE id = ?', [$bookingId]);
        if (!$booking) {
            throw new \InvalidArgumentException('Booking not found.');
        }

        $now = Clock::now();
        $convId = DB::insert('conversations', [
            'booking_id'         => (int) $booking['id'],
            'client_id'          => (int) $booking['client_id'],
            'privacy_consent_at' => $booking['privacy_consent_at'] ?: $now,
            'status'             => 'open',
            'last_message_at'    => $now,
            'created_at'         => $now,
        ]);
        return (array) Message::findConversation($convId);
    }

    /**
     * Create a new pre-booking inquiry conversation with a random 64-char hex token.
     * Stores only the SHA-256 hash of the token in the database.
     *
     * @return array{conversation_id:int,token:string,conversation:array}
     */
    public static function startInquiry(string $guestName, string $guestMobile, string $initialBody = ''): array
    {
        $guestName = trim($guestName);
        $normMobile = normalize_mobile($guestMobile);
        if ($guestName === '' || $normMobile === null) {
            throw new \InvalidArgumentException('Please enter a valid name and Philippine mobile number.');
        }

        $initialBody = trim($initialBody);
        if (mb_strlen($initialBody) > 1000) {
            throw new \InvalidArgumentException('Message cannot exceed 1,000 characters.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $now = Clock::now();

        $convId = DB::transaction(static function () use ($guestName, $normMobile, $tokenHash, $initialBody, $now): int {
            $id = DB::insert('conversations', [
                'booking_id'         => null,
                'client_id'          => null,
                'guest_name'         => mb_substr($guestName, 0, 120),
                'guest_mobile'       => $normMobile,
                'access_token_hash'  => $tokenHash,
                'privacy_consent_at' => $now,
                'status'             => 'open',
                'last_message_at'    => $now,
                'created_at'         => $now,
            ]);

            if ($initialBody !== '') {
                DB::insert('messages', [
                    'conversation_id' => $id,
                    'sender_type'     => 'client',
                    'sender_user_id'  => null,
                    'body'            => $initialBody,
                    'attachment_path' => null,
                    'created_at'      => $now,
                ]);
            }

            Audit::log('inquiry_chat_started', 'conversation', $id, [
                'guest_name'   => $guestName,
                'guest_mobile' => $normMobile,
            ]);

            return $id;
        });

        return [
            'conversation_id' => $convId,
            'token'           => $rawToken,
            'conversation'    => (array) Message::findConversation($convId),
        ];
    }

    /**
     * Send a message in a conversation (client or admin).
     */
    public static function sendMessage(
        int $convId,
        string $senderType,
        string $body,
        ?string $attachmentPath = null,
        ?int $senderUserId = null
    ): array {
        if (!in_array($senderType, ['client', 'admin', 'system'], true)) {
            throw new \InvalidArgumentException('Invalid message sender type.');
        }

        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new \InvalidArgumentException('Conversation not found.');
        }

        if ($senderType === 'client' && (string) $conv['status'] === 'closed') {
            throw new \RuntimeException('This conversation is closed. Please start a new inquiry or contact the studio.');
        }

        $body = trim($body);
        if ($body === '' && ($attachmentPath === null || $attachmentPath === '')) {
            throw new \InvalidArgumentException('Please enter a message or attach a picture.');
        }
        if (mb_strlen($body) > 1000) {
            throw new \InvalidArgumentException('Message cannot exceed 1,000 characters.');
        }

        $now = Clock::now();
        $msgId = DB::transaction(static function () use ($convId, $conv, $senderType, $body, $attachmentPath, $senderUserId, $now): int {
            $id = DB::insert('messages', [
                'conversation_id' => $convId,
                'sender_type'     => $senderType,
                'sender_user_id'  => $senderUserId,
                'body'            => $body,
                'attachment_path' => $attachmentPath,
                'read_at'         => null,
                'created_at'      => $now,
            ]);

            // If an admin replies to a closed thread, reopen it automatically.
            if ($senderType === 'admin' && (string) $conv['status'] === 'closed') {
                DB::run('UPDATE conversations SET status = \'open\', last_message_at = ? WHERE id = ?', [$now, $convId]);
            } else {
                DB::run('UPDATE conversations SET last_message_at = ? WHERE id = ?', [$now, $convId]);
            }

            return $id;
        });

        // Replying also marks any unread messages from the other side as read.
        if ($senderType === 'admin') {
            self::markRead($convId, 'admin');
        } elseif ($senderType === 'client') {
            self::markRead($convId, 'client');
        }

        $row = DB::one(
            'SELECT m.*, u.name AS sender_user_name
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.id = ?',
            [$msgId]
        );
        return Message::formatMessage((array) $row);
    }

    /**
     * Mark unread messages from the other party as read.
     *
     * @param string $viewerRole 'admin' (marks client messages read) or 'client' (marks admin/system messages read)
     */
    public static function markRead(int $convId, string $viewerRole): int
    {
        $now = Clock::now();
        if ($viewerRole === 'admin') {
            $stmt = DB::run(
                "UPDATE messages SET read_at = ? WHERE conversation_id = ? AND sender_type = 'client' AND read_at IS NULL",
                [$now, $convId]
            );
            $count = $stmt->rowCount();
            if ($count > 0) {
                Audit::log('conversation_read', 'conversation', $convId, ['marked_read' => $count]);
            }
            return $count;
        }

        $stmt = DB::run(
            "UPDATE messages SET read_at = ? WHERE conversation_id = ? AND sender_type IN ('admin', 'system') AND read_at IS NULL",
            [$now, $convId]
        );
        return $stmt->rowCount();
    }

    /** Close or reopen a conversation from Screen A19. */
    public static function setStatus(int $convId, string $newStatus, int $actorUserId): void
    {
        if (!in_array($newStatus, ['open', 'closed'], true)) {
            throw new \InvalidArgumentException('Invalid conversation status.');
        }
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new \InvalidArgumentException('Conversation not found.');
        }

        DB::run('UPDATE conversations SET status = ? WHERE id = ?', [$newStatus, $convId]);
        $action = $newStatus === 'closed' ? 'conversation_closed' : 'conversation_reopened';
        Audit::log($action, 'conversation', $convId, [
            'old_status' => (string) $conv['status'],
            'new_status' => $newStatus,
        ], $actorUserId);
    }

    /**
     * Link a pre-booking inquiry conversation to an existing booking or client.
     * If the target booking already has its own conversation row, all messages
     * from this inquiry are merged into the booking conversation so history is unified.
     *
     * @return int The resulting conversation ID (either $convId or the merged target conversation ID)
     */
    public static function linkConversation(int $convId, ?int $bookingId, ?int $clientId, int $actorUserId): int
    {
        $conv = Message::findConversation($convId);
        if (!$conv) {
            throw new \InvalidArgumentException('Conversation not found.');
        }
        if ($bookingId === null && $clientId === null) {
            throw new \InvalidArgumentException('Please choose a booking or client to link.');
        }

        return DB::transaction(static function () use ($convId, $conv, $bookingId, $clientId, $actorUserId): int {
            if ($bookingId !== null && $bookingId > 0) {
                $booking = DB::one('SELECT id, client_id, reference_code FROM bookings WHERE id = ?', [$bookingId]);
                if (!$booking) {
                    throw new \InvalidArgumentException('Selected booking was not found.');
                }

                $existingConv = DB::one(
                    'SELECT * FROM conversations WHERE booking_id = ? AND id != ? FOR UPDATE',
                    [$bookingId, $convId]
                );

                if ($existingConv) {
                    $targetId = (int) $existingConv['id'];
                    DB::run('UPDATE messages SET conversation_id = ? WHERE conversation_id = ?', [$targetId, $convId]);

                    $latestAt = DB::value('SELECT MAX(created_at) FROM messages WHERE conversation_id = ?', [$targetId])
                        ?: Clock::now();

                    // Clear token on source before moving it to target (uq_conv_token unique key).
                    $tokenToKeep = $existingConv['access_token_hash'] ?: $conv['access_token_hash'];
                    if ($conv['access_token_hash']) {
                        DB::run('UPDATE conversations SET access_token_hash = NULL WHERE id = ?', [$convId]);
                    }
                    DB::run(
                        'UPDATE conversations
                         SET client_id = COALESCE(client_id, ?),
                             guest_name = COALESCE(guest_name, ?),
                             guest_mobile = COALESCE(guest_mobile, ?),
                             access_token_hash = ?,
                             last_message_at = ?
                         WHERE id = ?',
                        [
                            (int) $booking['client_id'],
                            $conv['guest_name'],
                            $conv['guest_mobile'],
                            $tokenToKeep,
                            $latestAt,
                            $targetId,
                        ]
                    );
                    DB::run('DELETE FROM conversations WHERE id = ?', [$convId]);

                    Audit::log('conversation_linked', 'conversation', $targetId, [
                        'merged_from_conversation_id' => $convId,
                        'booking_id'                  => $bookingId,
                        'reference_code'              => $booking['reference_code'],
                        'client_id'                   => (int) $booking['client_id'],
                    ], $actorUserId);

                    return $targetId;
                }

                DB::run(
                    'UPDATE conversations SET booking_id = ?, client_id = ? WHERE id = ?',
                    [$bookingId, (int) $booking['client_id'], $convId]
                );

                Audit::log('conversation_linked', 'conversation', $convId, [
                    'booking_id'     => $bookingId,
                    'reference_code' => $booking['reference_code'],
                    'client_id'      => (int) $booking['client_id'],
                ], $actorUserId);

                return $convId;
            }

            $client = DB::one('SELECT id, full_name FROM clients WHERE id = ?', [$clientId]);
            if (!$client) {
                throw new \InvalidArgumentException('Selected client was not found.');
            }

            DB::run('UPDATE conversations SET client_id = ? WHERE id = ?', [(int) $client['id'], $convId]);
            Audit::log('conversation_linked', 'conversation', $convId, [
                'client_id'   => (int) $client['id'],
                'client_name' => $client['full_name'],
            ], $actorUserId);

            return $convId;
        });
    }

    /** Remember in session that this client visitor owns conversation $convId. */
    public static function grantClientAccess(int $convId, ?string $rawToken = null): void
    {
        $ids = (array) Session::get('client_conversations', []);
        if (!in_array($convId, $ids, true)) {
            $ids[] = $convId;
        }
        Session::put('client_conversations', $ids);

        if ($rawToken !== null && $rawToken !== '') {
            Session::put('inquiry_token', $rawToken);
            $tokens = (array) Session::get('client_conv_tokens', []);
            $tokens[$convId] = $rawToken;
            Session::put('client_conv_tokens', $tokens);
        }
    }

    /**
     * Validate on EVERY request whether the current visitor owns this conversation.
     * Never trusts conversation ID in the URL alone.
     */
    public static function clientOwnsConversation(array $conv, ?string $rawToken = null): bool
    {
        $convId = (int) ($conv['id'] ?? 0);
        if ($convId <= 0) {
            return false;
        }

        // 1. Explicit raw token provided in URL/query/body/header matches stored SHA-256 hash
        if ($rawToken !== null && trim($rawToken) !== '' && !empty($conv['access_token_hash'])) {
            $hash = hash('sha256', trim($rawToken));
            if (hash_equals((string) $conv['access_token_hash'], $hash)) {
                self::grantClientAccess($convId, trim($rawToken));
                return true;
            }
        }

        // 2. Conversation ID was previously unlocked in this session
        $grantedConvs = (array) Session::get('client_conversations', []);
        if (in_array($convId, $grantedConvs, true)) {
            return true;
        }

        // 3. Conversation belongs to a booking that the visitor unlocked via C4/C5 lookup
        if (!empty($conv['booking_id'])) {
            $grantedBookings = (array) Session::get('client_bookings', []);
            if (in_array((int) $conv['booking_id'], $grantedBookings, true)) {
                self::grantClientAccess($convId);
                return true;
            }
        }

        return false;
    }
}
