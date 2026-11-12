<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Package catalog queries (C1, C3, C4; edited in A15 in Phase 6). */
final class Package
{
    /** Photo / video packages a client can book. */
    public static function bookable(): array
    {
        return DB::all(
            "SELECT * FROM packages WHERE is_active = 1 AND category <> 'other' AND price IS NOT NULL
             ORDER BY sort_order, id"
        );
    }

    public static function bookableById(int $id): ?array
    {
        return DB::one(
            "SELECT * FROM packages WHERE id = ? AND is_active = 1 AND category <> 'other' AND price IS NOT NULL",
            [$id]
        );
    }

    /** "Other services" (inquire only). */
    public static function otherServices(): array
    {
        return DB::all("SELECT * FROM packages WHERE is_active = 1 AND category = 'other' ORDER BY sort_order, id");
    }

    /** All packages for A15 admin management (active and inactive). */
    public static function allForAdmin(): array
    {
        return DB::all("SELECT * FROM packages ORDER BY category = 'other' ASC, sort_order ASC, id ASC");
    }

    public static function findById(int $id): ?array
    {
        return DB::one("SELECT * FROM packages WHERE id = ?", [$id]);
    }

    /** Active add-on services (A5). */
    public static function activeAddons(): array
    {
        return DB::all("SELECT * FROM addons WHERE is_active = 1 ORDER BY id ASC");
    }

    /** All add-on services for A15 admin management. */
    public static function allAddons(): array
    {
        return DB::all("SELECT * FROM addons ORDER BY id ASC");
    }

    public static function findAddonById(int $id): ?array
    {
        return DB::one("SELECT * FROM addons WHERE id = ?", [$id]);
    }

    /** One inclusion per line -> array. */
    public static function inclusions(?string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        return array_values(array_filter(array_map('trim', $lines), 'strlen'));
    }
}
