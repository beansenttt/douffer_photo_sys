<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;
use App\Models\Booking;
use InvalidArgumentException;
use RuntimeException;

/**
 * Screen A12 (Client Message Templates) service (Milestone 3).
 * Manages 4 bilingual templates, dynamic placeholder substitution, and dispatch logging.
 */
final class MessageTemplateService
{
    public const KEY_MAP = [
        'booking_confirmation' => 'booking_confirmed',
        'booking_confirmed'    => 'booking_confirmed',
        'balance_reminder'     => 'balance_reminder',
        'output_ready'         => 'output_ready',
        'reschedule'           => 'reschedule',
    ];

    /**
     * Get all active message templates.
     */
    public static function getAll(): array
    {
        return DB::all('SELECT * FROM message_templates WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
    }

    /**
     * Find template by key, resolving aliases.
     */
    public static function getByKey(string $key): ?array
    {
        $normalizedKey = self::KEY_MAP[$key] ?? $key;
        return DB::one('SELECT * FROM message_templates WHERE template_key = ? AND is_active = 1', [$normalizedKey]);
    }

    /**
     * Interpolate template placeholders for a given booking.
     *
     * @return array{
     *   template_key: string,
     *   title: string,
     *   language: string,
     *   raw_body: string,
     *   body: string,
     *   booking_id: int,
     *   reference: string,
     *   client_name: string,
     *   balance: float
     * }
     */
    public static function getInterpolated(string $key, int $bookingId, string $lang = 'tl'): array
    {
        $template = self::getByKey($key);
        if (!$template) {
            throw new InvalidArgumentException("Message template '{$key}' not found.");
        }

        $booking = Booking::findById($bookingId);
        if (!$booking) {
            throw new RuntimeException("Booking #{$bookingId} not found.");
        }

        $lang = in_array(strtolower($lang), ['tl', 'en'], true) ? strtolower($lang) : 'tl';
        $rawBody = ($lang === 'en') ? (string) $template['body_en'] : (string) $template['body_tl'];

        $project = DB::one('SELECT * FROM projects WHERE booking_id = ?', [$bookingId]);
        $balance = Booking::balance($booking);
        $studioName = (string) Settings::get('studio_name', 'Douffer Photography');

        $clientName = (string) ($booking['full_name'] ?? '');
        $eventType = (string) ($booking['event_type'] ?? '');
        $eventDateFormatted = !empty($booking['event_date']) ? date_long($booking['event_date']) : '';
        $refCode = (string) ($booking['reference_code'] ?? '');
        $balanceFormatted = peso($balance);
        $packageName = (string) ($booking['package_name'] ?? '');
        $venue = (string) ($booking['venue'] ?? '');
        $dueDateFormatted = !empty($project['due_date']) ? date_long($project['due_date']) : '';
        $driveLink = (string) ($project['edited_folder'] ?? '');

        // Normalized lowercase lookup map supporting all common placeholder variations
        $map = [
            'client name'       => $clientName,
            'client_name'       => $clientName,
            'client'            => $clientName,
            'event'             => $eventType,
            'event type'        => $eventType,
            'event_type'        => $eventType,
            'event date'        => $eventDateFormatted,
            'event_date'        => $eventDateFormatted,
            'reference no.'     => $refCode,
            'reference no'      => $refCode,
            'reference_no'      => $refCode,
            'reference code'    => $refCode,
            'reference_code'    => $refCode,
            'balance'           => $balanceFormatted,
            'remaining balance' => $balanceFormatted,
            'remaining_balance' => $balanceFormatted,
            'studio'            => $studioName,
            'studio name'       => $studioName,
            'studio_name'       => $studioName,
            'package'           => $packageName,
            'package name'      => $packageName,
            'package_name'      => $packageName,
            'venue'             => $venue,
            'due date'          => $dueDateFormatted,
            'due_date'          => $dueDateFormatted,
            'drive link'        => $driveLink,
            'drive_link'        => $driveLink,
            'edited folder'     => $driveLink,
            'edited_folder'     => $driveLink,
        ];

        $interpolated = preg_replace_callback('/\[([a-zA-Z0-9_\s\.\-]+)\]/', function ($m) use ($map) {
            $token = strtolower(trim($m[1]));
            return $map[$token] ?? $m[0];
        }, $rawBody);

        return [
            'template_key' => $template['template_key'],
            'title'        => $template['title'],
            'language'     => $lang,
            'raw_body'     => $rawBody,
            'body'         => $interpolated,
            'booking_id'   => $bookingId,
            'reference'    => $refCode,
            'client_name'  => $clientName,
            'balance'      => $balance,
        ];
    }

    /**
     * Record a sent template message in message_log and optionally post to in-app chat thread.
     */
    public static function logSent(int $bookingId, string $key, string $lang, string $body, string $channel, int $userId): void
    {
        $normalizedKey = self::KEY_MAP[$key] ?? $key;
        $lang = in_array(strtolower($lang), ['tl', 'en'], true) ? strtolower($lang) : 'tl';
        $channel = in_array(strtolower($channel), ['copy', 'in_app'], true) ? strtolower($channel) : 'copy';

        DB::transaction(function () use ($bookingId, $normalizedKey, $lang, $body, $channel, $userId) {
            $now = Clock::now();

            DB::insert('message_log', [
                'booking_id'     => $bookingId,
                'template_key'   => $normalizedKey,
                'language'       => $lang,
                'body'           => $body,
                'channel'        => $channel,
                'sent_marked_by' => $userId,
                'sent_at'        => $now,
            ]);

            if ($channel === 'in_app') {
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
                    'sender_type'     => 'admin',
                    'sender_user_id'  => $userId,
                    'body'            => mb_substr($body, 0, 1000),
                    'created_at'      => $now,
                ]);

                DB::run('UPDATE conversations SET last_message_at = ? WHERE id = ?', [$now, $convId]);
            }

            Audit::log('message_template_sent', 'booking', $bookingId, [
                'template_key' => $normalizedKey,
                'language'     => $lang,
                'channel'      => $channel,
            ]);
        });
    }

    /**
     * Fetch message dispatch history for Screen A12.
     */
    public static function getSentHistory(int $bookingId): array
    {
        return DB::all(
            "SELECT ml.*, u.name AS sent_by_name, mt.title AS template_title
             FROM message_log ml
             LEFT JOIN users u ON u.id = ml.sent_marked_by
             LEFT JOIN message_templates mt ON mt.template_key = ml.template_key
             WHERE ml.booking_id = ?
             ORDER BY ml.sent_at DESC, ml.id DESC",
            [$bookingId]
        );
    }
}
