<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Session;
use App\Core\Validator;

/** Change own password (any logged-in user). Clears the "change default password" notice. */
final class AccountController
{
    public function showPassword(): void
    {
        view('account/password', [
            'title'  => 'Change password',
            'screen' => 'ACCOUNT',
            'back'   => Auth::homeFor(Auth::user()),
        ], Auth::isCrew() ? 'crew' : 'admin');
    }

    public function updatePassword(): void
    {
        $user = Auth::user();
        $errors = Validator::make($_POST, [
            'current_password' => 'required',
            'password'         => 'required|password',
            'password_confirm' => 'required|same:password',
        ]);
        if (!isset($errors['current_password']) && !password_verify((string) $_POST['current_password'], $user['password_hash'])) {
            $errors['current_password'] = 'Your current password is not correct.';
        }
        if (!$errors && hash_equals((string) $_POST['current_password'], (string) $_POST['password'])) {
            $errors['password'] = 'Please choose a password different from the current one.';
        }
        if (!$errors && strtolower((string) $_POST['password']) === strtolower($user['username'])) {
            $errors['password'] = 'Your password must not be the same as your username.';
        }

        if ($errors) {
            Flash::withInput([], $errors);
            redirect('/account/password');
        }

        DB::update('users', [
            'password_hash'        => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'password_changed_at'  => Clock::now(),
        ], 'id = ?', [$user['id']]);
        Session::regenerate();
        Audit::log('password_changed', 'user', (int) $user['id']);
        Flash::set('success', 'Password changed ✓');
        redirect(Auth::homeFor($user));
    }
}
