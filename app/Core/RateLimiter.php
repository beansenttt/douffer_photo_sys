<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Fixed-window rate limiter stored in the rate_limits table.
 *
 *   if (!RateLimiter::hit('chat:ip:' . client_ip(), 10, 60)) { ...too many... }
 *
 * hit() counts the attempt and returns false once the limit is passed.
 */
final class RateLimiter
{
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $window = self::windowStart($windowSeconds);
        DB::run(
            'INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [self::key($key), $window]
        );
        self::cleanupSometimes();
        return self::attempts($key, $windowSeconds) <= $max;
    }

    /** Has the limit already been reached (without counting a new attempt)? */
    public static function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        return self::attempts($key, $windowSeconds) >= $max;
    }

    public static function attempts(string $key, int $windowSeconds): int
    {
        return (int) DB::value(
            'SELECT hits FROM rate_limits WHERE rl_key = ? AND window_start = ?',
            [self::key($key), self::windowStart($windowSeconds)]
        );
    }

    public static function clear(string $key): void
    {
        DB::run('DELETE FROM rate_limits WHERE rl_key = ?', [self::key($key)]);
    }

    private static function windowStart(int $windowSeconds): int
    {
        return intdiv(time(), $windowSeconds) * $windowSeconds;
    }

    private static function key(string $key): string
    {
        return strlen($key) > 190 ? substr($key, 0, 150) . sha1($key) : $key;
    }

    private static function cleanupSometimes(): void
    {
        if (random_int(1, 100) === 1) {
            DB::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
        }
    }
}
