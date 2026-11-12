<?php
declare(strict_types=1);

namespace App\Core;

/**
 * CSRF protection: one random token per session. Every POST form includes it
 * (csrf_field()) and fetch() calls send it in the X-CSRF-Token header.
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function check(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }

    /** Validate the current request or stop with 419. */
    public static function verifyRequest(): void
    {
        // A file bigger than post_max_size makes PHP drop the whole body (no token, no fields).
        if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            throw new HttpException(413, 'The picture you chose is too large. Please choose a smaller one and try again.');
        }
        $token = $_POST[self::FIELD] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!self::check(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session expired. Please go back, refresh the page and try again.');
        }
    }
}
