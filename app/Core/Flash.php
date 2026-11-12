<?php
declare(strict_types=1);

namespace App\Core;

/**
 * One-time messages and form state kept in the session between a POST and the
 * redirected GET (Post/Redirect/Get pattern).
 */
final class Flash
{
    /** type: success | error | info | warning */
    public static function set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pull(): array
    {
        $items = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $items;
    }

    /** Keep submitted values so the form can be refilled after a validation error. */
    public static function withInput(array $input, array $errors = []): void
    {
        // Never keep passwords or tokens.
        unset($input['password'], $input['password_confirm'], $input['current_password'], $input[Csrf::FIELD]);
        $_SESSION['_old'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** Called once per request by App so old input lives for exactly one page view. */
    public static function ageFormState(): void
    {
        $GLOBALS['_dps_old'] = $_SESSION['_old'] ?? [];
        $GLOBALS['_dps_errors'] = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $GLOBALS['_dps_old'][$key] ?? $default;
    }

    public static function error(string $key): ?string
    {
        return $GLOBALS['_dps_errors'][$key] ?? null;
    }

    public static function hasErrors(): bool
    {
        return !empty($GLOBALS['_dps_errors']);
    }
}
