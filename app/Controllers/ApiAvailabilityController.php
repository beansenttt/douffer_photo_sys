<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Settings;
use App\Core\Validator;
use App\Services\AvailabilityService;

/**
 * Live JSON availability check for interactive frontends (A5 new booking, A13 reschedule).
 * Strictly read-only, CSP-compliant, returns structured JSON.
 */
final class ApiAvailabilityController
{
    /**
     * Compute date availability status payload.
     * Exposed as a public static helper so tests and CLI runners can inspect status without exit.
     *
     * @return array{ok: bool, date: string, available: bool, reason: ?string, message: string, confirmed: int, max: int}
     */
    public static function status(string $date, ?int $excludeId = null): array
    {
        $max = max(1, Settings::int('max_events_per_day'));

        if (!Validator::isDate($date)) {
            return [
                'ok'        => false,
                'date'      => $date,
                'available' => false,
                'reason'    => 'invalid',
                'message'   => 'Please provide a valid date in YYYY-MM-DD format.',
                'confirmed' => 0,
                'max'       => $max,
            ];
        }

        $reason = AvailabilityService::reason($date, $excludeId);
        $confirmed = AvailabilityService::confirmedCount($date, $excludeId);

        $message = match ($reason) {
            null      => 'This date is open for booking ✓',
            'full'    => AvailabilityService::message('full'),
            'blocked' => AvailabilityService::message('blocked'),
            'past'    => AvailabilityService::message('past'),
            default   => AvailabilityService::message($reason),
        };

        return [
            'ok'        => $reason === null,
            'date'      => $date,
            'available' => $reason === null,
            'reason'    => $reason,
            'message'   => $message,
            'confirmed' => $confirmed,
            'max'       => $max,
        ];
    }

    /**
     * GET /api/availability/check?date=YYYY-MM-DD[&exclude=ID]
     */
    public function check(): void
    {
        $date = trim((string) ($_GET['date'] ?? ''));
        $excludeRaw = $_GET['exclude'] ?? null;
        $excludeId = ($excludeRaw !== null && ctype_digit((string) $excludeRaw)) ? (int) $excludeRaw : null;

        $payload = self::status($date, $excludeId);
        json_response($payload, 200);
    }
}
