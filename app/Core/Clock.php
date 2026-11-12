<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * The one place that knows what "today" is.
 * When config 'demo_date' is set, today = that date (time of day stays real),
 * so the demo always looks like Sat, Dec 12, 2026 from the paper prototype.
 */
final class Clock
{
    private static ?string $override = null;

    public static function today(): string
    {
        return self::now('Y-m-d');
    }

    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return self::dateTime()->format($format);
    }

    public static function dateTime(): DateTimeImmutable
    {
        $real = new DateTimeImmutable('now');
        $demo = self::$override ?? Config::get('demo_date');
        if (is_string($demo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $demo)) {
            return new DateTimeImmutable($demo . ' ' . $real->format('H:i:s'));
        }
        return $real;
    }

    public static function isDemo(): bool
    {
        $demo = self::$override ?? Config::get('demo_date');
        return is_string($demo) && $demo !== '';
    }

    /** Tests can pin "today". Pass null to reset. */
    public static function setToday(?string $date): void
    {
        self::$override = $date;
    }
}
