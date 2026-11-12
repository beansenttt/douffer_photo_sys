<?php
declare(strict_types=1);

namespace App\Core;

/** Read-only access to config/config.php using dot notation: Config::get('db.host'). */
final class Config
{
    private static array $items = [];

    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Used by tests to change a value temporarily. */
    public static function set(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }
}
