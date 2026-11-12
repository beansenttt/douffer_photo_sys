<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\DB;

/**
 * Messages and Conversations repository (Screen C6 & Screen A19).
 */
final class Message
{
    /** Unread client messages across all conversations, for the admin badge. */
    public static function unreadForAdmin(): int
    {
        if (!Auth::can('manage_messages')) {
            return 0;
        }
        return (int) DB::value(
            "SELECT COUNT(*) FROM messages WHERE sender_type = 'client' AND read_at IS NULL"
        );
    }

    /** Unread studio messages in a specific conversation for the client. */
    public static function unreadForClientConversation(int $convId): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND sender_type = 'admin' AND read_at IS NULL",
            [$convId]
        );
    }

    /** Load a conversation with joined client and booking details. */
    public static function findConversation(int $id): ?array
    {
        $row = DB::one(
            'SELECT conv.*,
                    c.full_name AS client_name,
                    c.mobile AS client_mobile,
                    c.mobile_normalized AS client_mobile_normalized,
                    c.messenger_name AS client_messenger,
                    b.reference_code,
                    b.event_type,
                    b.event_date,
                    b.start_time,
                    b.end_time,
                    b.venue,
                    b.status AS booking_status,
                    b.total_amount,
                    b.down_payment_due,
                    pkg.name AS package_name
             FROM conversations conv
             LEFT JOIN bookings b ON b.id = conv.booking_id
             LEFT JOIN clients c ON c.id = COALESCE(conv.client_id, b.client_id)
             LEFT JOIN packages pkg ON pkg.id = b.package_id
             WHERE conv.id = ?',
            [$id]
        );
        if (!$row) {
            return null;
        }
        return self::enrichConversation($row);
    }

    /** Find a conversation by its raw inquiry token (only SHA-256 hash is stored in DB). */
    public static function findByToken(string $rawToken): ?array
    {
        $clean = trim($rawToken);
        if ($clean === '') {
            return null;
        }
        $hash = hash('sha256', $clean);
        $id = DB::value('SELECT id FROM conversations WHERE access_token_hash = ?', [$hash]);
        return $id ? self::findConversation((int) $id) : null;
    }

    /** Find a conversation by booking ID. */
    public static function findByBookingId(int $bookingId): ?array
    {
        $id = DB::value('SELECT id FROM conversations WHERE booking_id = ?', [$bookingId]);
        return $id ? self::findConversation((int) $id) : null;
    }

    /**
     * Fetch messages for a conversation (optionally only those after $sinceId for cheap polling).
     */
    public static function messagesFor(int $convId, int $sinceId = 0): array
    {
        $rows = DB::all(
            'SELECT m.*, u.name AS sender_user_name
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_user_id
             WHERE m.conversation_id = ? AND m.id > ?
             ORDER BY m.id ASC',
            [$convId, $sinceId]
        );
        return array_map([self::class, 'formatMessage'], $rows);
    }

    /** Format a single message row for views and JSON polling responses. */
    public static function formatMessage(array $m): array
    {
        $id = (int) $m['id'];
        $senderType = (string) $m['sender_type'];
        $senderLabel = match ($senderType) {
            'admin'  => !empty($m['sender_user_name']) ? (string) $m['sender_user_name'] . ' (Studio)' : 'Douffer Studio',
            'system' => 'System Notice',
            default  => 'Client',
        };
        return [
            'id'              => $id,
            'conversation_id' => (int) $m['conversation_id'],
            'sender_type'     => $senderType,
            'sender_user_id'  => $m['sender_user_id'] !== null ? (int) $m['sender_user_id'] : null,
            'sender_label'    => $senderLabel,
            'body'            => (string) $m['body'],
            'attachment_path' => $m['attachment_path'],
            'attachment_url'  => !empty($m['attachment_path']) ? url('/files/chat/' . $id) : null,
            'read_at'         => $m['read_at'],
            'read_label'      => !empty($m['read_at']) ? 'Seen · ' . datetime_short((string) $m['read_at']) : 'Sent',
            'created_at'      => (string) $m['created_at'],
            'created_label'   => datetime_short((string) $m['created_at']),
        ];
    }

    /** Highest message ID from $senderType that has been marked read (Seen). */
    public static function lastSeenIdForSender(int $convId, string $senderType): int
    {
        return (int) DB::value(
            'SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ? AND sender_type = ? AND read_at IS NOT NULL',
            [$convId, $senderType]
        );
    }

    /** Active quick replies for Screen A19. */
    public static function quickReplies(bool $activeOnly = true): array
    {
        if ($activeOnly) {
            return DB::all('SELECT * FROM quick_replies WHERE is_active = 1 ORDER BY id ASC');
        }
        return DB::all('SELECT * FROM quick_replies ORDER BY id ASC');
    }

    /** Counts for Screen A19 inbox filter chips. */
    public static function inboxCounts(): array
    {
        $all = (int) DB::value('SELECT COUNT(*) FROM conversations');
        $unread = (int) DB::value(
            "SELECT COUNT(DISTINCT conversation_id) FROM messages WHERE sender_type = 'client' AND read_at IS NULL"
        );
        $open = (int) DB::value("SELECT COUNT(*) FROM conversations WHERE status = 'open'");
        $closed = (int) DB::value("SELECT COUNT(*) FROM conversations WHERE status = 'closed'");
        $inquiries = (int) DB::value('SELECT COUNT(*) FROM conversations WHERE booking_id IS NULL');

        return [
            'all'       => $all,
            'unread'    => $unread,
            'open'      => $open,
            'closed'    => $closed,
            'inquiries' => $inquiries,
        ];
    }

    /**
     * Conversations list for Screen A19 (/admin/messages).
     * Supports filter: all | unread | open | closed | inquiries, and search query $q.
     */
    public static function inboxList(string $filter = 'all', string $q = ''): array
    {
        $where = ['1=1'];
        $params = [];

        if ($filter === 'unread') {
            $where[] = "EXISTS (SELECT 1 FROM messages um WHERE um.conversation_id = conv.id AND um.sender_type = 'client' AND um.read_at IS NULL)";
        } elseif ($filter === 'open') {
            $where[] = "conv.status = 'open'";
        } elseif ($filter === 'closed') {
            $where[] = "conv.status = 'closed'";
        } elseif ($filter === 'inquiries') {
            $where[] = 'conv.booking_id IS NULL';
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR conv.guest_name LIKE ? OR conv.guest_mobile LIKE ? OR b.reference_code LIKE ?
                         OR EXISTS (SELECT 1 FROM messages sm WHERE sm.conversation_id = conv.id AND sm.body LIKE ?))';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $sql = 'SELECT conv.*,
                       c.full_name AS client_name,
                       c.mobile AS client_mobile,
                       c.mobile_normalized AS client_mobile_normalized,
                       c.messenger_name AS client_messenger,
                       b.reference_code,
                       b.event_type,
                       b.event_date,
                       b.start_time,
                       b.end_time,
                       b.venue,
                       b.status AS booking_status,
                       b.total_amount,
                       b.down_payment_due,
                       pkg.name AS package_name,
                       (SELECT COUNT(*) FROM messages um
                        WHERE um.conversation_id = conv.id AND um.sender_type = \'client\' AND um.read_at IS NULL) AS unread_count,
                       lm.body AS last_message_body,
                       lm.sender_type AS last_message_sender,
                       lm.attachment_path AS last_message_attachment,
                       lm.created_at AS last_message_created_at
                FROM conversations conv
                LEFT JOIN bookings b ON b.id = conv.booking_id
                LEFT JOIN clients c ON c.id = COALESCE(conv.client_id, b.client_id)
                LEFT JOIN packages pkg ON pkg.id = b.package_id
                LEFT JOIN messages lm ON lm.id = (
                    SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = conv.id
                )
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY COALESCE(conv.last_message_at, conv.created_at) DESC, conv.id DESC';

        $rows = DB::all($sql, $params);
        return array_map([self::class, 'enrichConversation'], $rows);
    }

    private static function enrichConversation(array $row): array
    {
        $isInquiry = empty($row['booking_id']);
        $displayName = (string) ($row['client_name'] ?? $row['guest_name'] ?? 'Guest Inquiry');
        $displayMobile = (string) ($row['client_mobile'] ?? $row['guest_mobile'] ?? '');

        $row['is_inquiry'] = $isInquiry;
        $row['display_name'] = $displayName !== '' ? $displayName : 'Guest Inquiry';
        $row['display_mobile'] = $displayMobile;
        $row['unread_count'] = isset($row['unread_count']) ? (int) $row['unread_count'] : 0;
        return $row;
    }
}
