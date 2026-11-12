<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Login, roles and permissions.
 *
 * Roles: owner (everything), staff (permission flags), crew (A18 only).
 * The user row is re-read from the database on every request, so a
 * deactivated account or a changed permission takes effect immediately.
 */
final class Auth
{
    /** Staff permission flags: key => [label, default]. Stored as users.perm_<key>. */
    public const PERMISSIONS = [
        'view_bookings'   => ['View and manage bookings, calendar, crew and projects', true],
        'record_payments' => ['Record cash/GCash payments', true],
        'verify_payments' => ['Verify or reject GCash payments', false],
        'archive_records' => ['Archive, restore and cancel bookings', false],
        'manage_messages' => ['Read and send client messages', true],
    ];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if (is_int($id)) {
                self::$user = DB::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$id]);
                if (self::$user === null) {
                    unset($_SESSION['uid']); // deactivated or removed
                }
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function isOwner(): bool
    {
        return self::role() === 'owner';
    }

    public static function isAdmin(): bool
    {
        return in_array(self::role(), ['owner', 'staff'], true);
    }

    public static function isCrew(): bool
    {
        return self::role() === 'crew';
    }

    /** Owner can do everything; staff only what their flags allow; crew nothing here. */
    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'owner') {
            return true;
        }
        if ($u['role'] === 'staff' && isset(self::PERMISSIONS[$permission])) {
            return (int) ($u['perm_' . $permission] ?? 0) === 1;
        }
        return false;
    }

    /**
     * Check a username/password. Returns the user row or null.
     * Uses password_verify() and also rehashes old hashes.
     */
    public static function attempt(string $username, string $password): ?array
    {
        $user = DB::one('SELECT * FROM users WHERE username = ? AND is_active = 1', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        return $user;
    }

    public static function login(array $user): void
    {
        Session::regenerate(); // new session id on login (prevents session fixation)
        $_SESSION['uid'] = (int) $user['id'];
        unset($_SESSION['_csrf_token']); // fresh CSRF token for the new session
        DB::update('users', ['last_login_at' => Clock::now()], 'id = ?', [$user['id']]);
        self::$loaded = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
        self::$loaded = true;
    }

    /** Where to send a user after login. */
    public static function homeFor(array $user): string
    {
        return $user['role'] === 'crew' ? '/crew' : '/admin';
    }

    /** Used by tests. */
    public static function forceUser(?array $user): void
    {
        self::$user = $user;
        self::$loaded = true;
    }
}
