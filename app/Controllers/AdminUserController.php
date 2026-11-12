<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Screen A17: Users & Access (/admin/users, Owner only).
 * Manages Owner, Staff, and Crew accounts, granular staff permission flags,
 * password resets, and account activation/deactivation.
 */
final class AdminUserController
{
    /**
     * GET /admin/users
     */
    public function index(): void
    {
        $users = DB::all(
            "SELECT u.*, cm.id AS crew_member_id, cm.type AS crew_type, cm.phone AS crew_phone, cm.is_on_call
             FROM users u
             LEFT JOIN crew_members cm ON cm.user_id = u.id
             ORDER BY FIELD(u.role, 'owner', 'staff', 'crew'), u.id ASC"
        );

        view('admin/users', [
            'title'       => 'Users & Access',
            'screen'      => 'A17',
            'back'        => '/admin/more',
            'users'       => $users,
            'permissions' => Auth::PERMISSIONS,
        ], 'admin');
    }

    /**
     * POST /admin/users — Create a new user account.
     */
    public function store(): void
    {
        $post = $_POST;
        $post['username'] = strtolower(trim((string) ($post['username'] ?? '')));

        $errors = Validator::make($post, [
            'name'     => 'required|max:100',
            'username' => 'required|username',
            'email'    => 'email|max:150',
            'role'     => 'required|in:owner,staff,crew',
            'password' => 'required|password',
        ]);

        if (!isset($errors['username'])) {
            $exists = DB::value('SELECT COUNT(*) FROM users WHERE username = ?', [$post['username']]);
            if ((int) $exists > 0) {
                $errors['username'] = 'That username is already taken. Please choose another.';
            }
        }

        if ($errors) {
            Flash::withInput($post, $errors);
            Flash::set('error', 'Please fix the highlighted user fields.');
            redirect('/admin/users');
        }

        $role = (string) $post['role'];
        $perms = $this->extractPermissions($post, $role, true);
        $now = Clock::now();

        $userId = DB::insert('users', array_merge([
            'name'                 => trim((string) $post['name']),
            'username'             => $post['username'],
            'email'                => trim((string) ($post['email'] ?? '')) !== '' ? trim((string) $post['email']) : null,
            'password_hash'        => password_hash((string) $post['password'], PASSWORD_DEFAULT),
            'role'                 => $role,
            'must_change_password' => 1,
            'is_active'            => 1,
            'created_at'           => $now,
        ], $perms));

        // If role is crew, link or create a crew_members entry so they work on A9 and A18
        if ($role === 'crew') {
            $crewType = in_array(($post['crew_type'] ?? ''), ['photographer', 'videographer', 'other'], true)
                ? (string) $post['crew_type']
                : 'photographer';
            $crewPhone = trim((string) ($post['crew_phone'] ?? ''));
            DB::insert('crew_members', [
                'name'       => trim((string) $post['name']),
                'type'       => $crewType,
                'phone'      => $crewPhone !== '' ? $crewPhone : null,
                'user_id'    => $userId,
                'is_on_call' => 1,
                'is_active'  => 1,
            ]);
        }

        Audit::log('user_saved', 'user', $userId, [
            'action'   => 'created',
            'username' => $post['username'],
            'role'     => $role,
        ]);

        if ($role === 'staff') {
            Audit::log('permissions_updated', 'user', $userId, $perms);
        }

        Flash::set('success', 'User account created ✓');
        redirect('/admin/users');
    }

    /**
     * POST /admin/users/{id} — Update user profile details (and permissions if submitted).
     */
    public function update(string $id): void
    {
        $userId = (int) $id;
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new HttpException(404, 'User not found.');
        }

        // If the form only submitted permissions, delegate to updatePermissions
        if (isset($_POST['_action']) && $_POST['_action'] === 'permissions') {
            $this->updatePermissions($id);
            return;
        }

        $post = $_POST;
        $post['username'] = isset($post['username'])
            ? strtolower(trim((string) $post['username']))
            : (string) $user['username'];
        $post['name'] = isset($post['name']) ? trim((string) $post['name']) : (string) $user['name'];
        $post['role'] = isset($post['role']) ? (string) $post['role'] : (string) $user['role'];

        $errors = Validator::make($post, [
            'name'     => 'required|max:100',
            'username' => 'required|username',
            'email'    => 'email|max:150',
            'role'     => 'required|in:owner,staff,crew',
        ]);

        if (!isset($errors['username'])) {
            $exists = DB::value('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$post['username'], $userId]);
            if ((int) $exists > 0) {
                $errors['username'] = 'That username is already taken.';
            }
        }

        // Prevent demoting the currently logged-in owner
        if ($userId === Auth::id() && $post['role'] !== 'owner') {
            $errors['role'] = 'You cannot change your own owner role.';
        }

        if ($errors) {
            Flash::withInput($post, $errors);
            Flash::set('error', 'Please check the user fields.');
            redirect('/admin/users');
        }

        $role = (string) $post['role'];
        $perms = $this->extractPermissions($post, $role, false);

        DB::update('users', array_merge([
            'name'     => $post['name'],
            'username' => $post['username'],
            'email'    => trim((string) ($post['email'] ?? '')) !== '' ? trim((string) $post['email']) : null,
            'role'     => $role,
        ], $perms), 'id = ?', [$userId]);

        Audit::log('user_saved', 'user', $userId, [
            'action'   => 'updated',
            'username' => $post['username'],
            'role'     => $role,
        ]);

        if ($role === 'staff') {
            Audit::log('permissions_updated', 'user', $userId, $perms);
        }

        Flash::set('success', 'User updated ✓');
        redirect('/admin/users');
    }

    /**
     * POST /admin/users/{id}/permissions — Update staff permission checkboxes.
     */
    public function updatePermissions(string $id): void
    {
        $userId = (int) $id;
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new HttpException(404, 'User not found.');
        }

        $role = (string) $user['role'];
        $perms = $this->extractPermissions($_POST, $role, false);

        DB::update('users', $perms, 'id = ?', [$userId]);

        Audit::log('permissions_updated', 'user', $userId, $perms);
        Audit::log('user_saved', 'user', $userId, array_merge(['action' => 'permissions_updated'], $perms));

        Flash::set('success', 'Permissions saved for ' . $user['name'] . ' ✓');
        redirect('/admin/users');
    }

    /**
     * POST /admin/users/{id}/reset-password — Owner resets a user's password.
     */
    public function resetPassword(string $id): void
    {
        $userId = (int) $id;
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new HttpException(404, 'User not found.');
        }

        $newPassword = (string) ($_POST['password'] ?? $_POST['new_password'] ?? '');
        $problem = Validator::passwordProblem($newPassword);
        if ($problem !== null) {
            Flash::set('error', 'Password reset failed: ' . $problem);
            redirect('/admin/users');
        }

        DB::update('users', [
            'password_hash'        => password_hash($newPassword, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'password_changed_at'  => Clock::now(),
        ], 'id = ?', [$userId]);

        Audit::log('user_saved', 'user', $userId, [
            'action'   => 'password_reset',
            'username' => $user['username'],
        ]);

        Flash::set('success', 'Password reset for ' . $user['username'] . ' ✓ (must change on next login)');
        redirect('/admin/users');
    }

    /**
     * POST /admin/users/{id}/toggle — Activate or deactivate a user account.
     */
    public function toggle(string $id): void
    {
        $userId = (int) $id;
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            throw new HttpException(404, 'User not found.');
        }

        if ($userId === Auth::id()) {
            Flash::set('error', 'You cannot deactivate your own account.');
            redirect('/admin/users');
        }

        $newActive = (int) $user['is_active'] === 1 ? 0 : 1;
        DB::update('users', ['is_active' => $newActive], 'id = ?', [$userId]);
        DB::run('UPDATE crew_members SET is_active = ? WHERE user_id = ?', [$newActive, $userId]);

        Audit::log('user_saved', 'user', $userId, [
            'action'    => $newActive === 1 ? 'activated' : 'deactivated',
            'username'  => $user['username'],
            'is_active' => $newActive,
        ]);

        Flash::set('success', $newActive === 1 ? 'User activated ✓' : 'User deactivated ✓');
        redirect('/admin/users');
    }

    /**
     * Normalize permission flags from POST data for owner, staff, or crew.
     * Supports both `perm_<key>=1`, `<key>=1`, and `permissions[]=<key>`.
     *
     * @return array<string, int>
     */
    private function extractPermissions(array $post, string $role, bool $useDefaultsIfMissing): array
    {
        $perms = [];
        $arrayPerms = isset($post['permissions']) && is_array($post['permissions']) ? $post['permissions'] : [];
        $hasAnyExplicit = isset($post['permissions']) || isset($post['_perms_submitted']);

        foreach (Auth::PERMISSIONS as $key => [$_label, $default]) {
            $col = 'perm_' . $key;
            if (array_key_exists($col, $post) || array_key_exists($key, $post)) {
                $hasAnyExplicit = true;
            }
        }

        foreach (Auth::PERMISSIONS as $key => [$_label, $default]) {
            $col = 'perm_' . $key;
            if ($role === 'owner') {
                $perms[$col] = 1;
            } elseif ($role === 'crew') {
                $perms[$col] = 0;
            } else {
                if (!$hasAnyExplicit && $useDefaultsIfMissing) {
                    $perms[$col] = $default ? 1 : 0;
                } else {
                    $checked = !empty($post[$col])
                        || !empty($post[$key])
                        || in_array($key, $arrayPerms, true);
                    $perms[$col] = $checked ? 1 : 0;
                }
            }
        }

        return $perms;
    }
}
