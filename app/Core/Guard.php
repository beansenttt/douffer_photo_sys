<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Access rules attached to routes. Example route options:
 *   ['auth' => 'admin']                          owner or staff
 *   ['auth' => 'admin', 'perm' => 'verify_payments']
 *   ['auth' => 'owner']                          owner only
 *   ['auth' => 'crew']                           crew only
 *   ['auth' => 'any']                            any logged-in user
 */
final class Guard
{
    public static function check(array $opts, bool $isApi): void
    {
        $need = $opts['auth'] ?? null;
        if ($need === null) {
            return; // public route
        }

        if (!Auth::check()) {
            if ($isApi) {
                throw new HttpException(401, 'Please log in again.');
            }
            // Remember where the user wanted to go (GET only), then send to login.
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $_SESSION['_intended'] = current_path_with_query();
            }
            Flash::set('info', 'Please log in to continue.');
            redirect('/admin/login');
        }

        $role = Auth::role();
        $allowed = match ($need) {
            'any'   => true,
            'admin' => in_array($role, ['owner', 'staff'], true),
            'owner' => $role === 'owner',
            'crew'  => $role === 'crew',
            default => false,
        };
        if (!$allowed) {
            throw new HttpException(403);
        }

        $perms = (array) ($opts['perm'] ?? []);
        foreach ($perms as $perm) {
            if (!Auth::can($perm)) {
                throw new HttpException(403);
            }
        }
    }
}
