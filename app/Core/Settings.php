<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Owner-editable settings (A20) stored as key/value rows.
 * Business defaults from PROMPT §3.3 live here, never hard-coded in logic.
 */
final class Settings
{
    /** Fallbacks if a row is missing. */
    public const DEFAULTS = [
        'studio_name'          => 'Douffer Photography',
        'studio_address'       => 'Victoria Ville Subd., Brgy. Bilaran, Nasugbu, Batangas',
        'studio_phone'         => '0917 000 0000',
        'studio_messenger_url' => '',
        'gcash_name'           => 'J. D. M. (SAMPLE)',
        'gcash_number'         => '0917 000 0000',
        'gcash_qr_path'        => '',
        'down_payment_mode'    => 'percent', // percent | fixed
        'down_payment_value'   => '30',
        'max_events_per_day'   => '2',
        'default_delivery_days'=> '14',
        'balance_overdue_days' => '0',
        'current_terms_version'=> '1',
        'event_types'          => "Wedding\nBirthday\nDebut\nChristening\nAnniversary\nCorporate\nOther",
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = DB::all('SELECT setting_key, setting_value FROM settings');
            self::$cache = self::DEFAULTS;
            foreach ($rows as $r) {
                self::$cache[$r['setting_key']] = (string) $r['setting_value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function int(string $key): int
    {
        return (int) self::get($key, '0');
    }

    public static function set(string $key, string $value): void
    {
        DB::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        self::$cache = null;
    }

    /** Newline-separated list setting -> array. */
    public static function list(string $key): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) self::get($key, ''));
        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
