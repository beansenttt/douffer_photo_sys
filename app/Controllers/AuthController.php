<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\RateLimiter;
use App\Core\Session;

/** A1 Login / logout for owner, staff and crew. */
final class AuthController
{
    // Throttling: 5 failures per username or 20 per IP in 15 minutes.
    private const WINDOW = 900;
    private const MAX_PER_USER = 5;
    private const MAX_PER_IP = 20;

    public function showLogin(): void
    {
        if ($user = Auth::user()) {
            redirect(Auth::homeFor($user));
        }
        view('admin/login', ['title' => 'Log in', 'screen' => 'A1'], 'auth');
    }

    public function login(): void
    {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $ipKey = 'login:ip:' . client_ip();
        $userKey = 'login:user:' . $username;

        if (RateLimiter::tooMany($ipKey, self::MAX_PER_IP, self::WINDOW)
            || RateLimiter::tooMany($userKey, self::MAX_PER_USER, self::WINDOW)) {
            Flash::withInput(['username' => $username]);
            Flash::set('error', 'Too many failed attempts. Please wait 15 minutes, or ask the owner to reset your password.');
            redirect('/admin/login');
        }

        if ($username === '' || $password === '') {
            Flash::withInput(['username' => $username], array_filter([
                'username' => $username === '' ? 'Please enter your username.' : null,
                'password' => $password === '' ? 'Please enter your password.' : null,
            ]));
            redirect('/admin/login');
        }

        $user = Auth::attempt($username, $password);
        if ($user === null) {
            RateLimiter::hit($ipKey, self::MAX_PER_IP, self::WINDOW);
            RateLimiter::hit($userKey, self::MAX_PER_USER, self::WINDOW);
            Audit::log('login_failed', 'user', null, ['username' => $username]);
            Flash::withInput(['username' => $username]);
            Flash::set('error', 'Wrong username or password. Please try again.');
            redirect('/admin/login');
        }

        RateLimiter::clear($userKey);
        Auth::login($user);
        Audit::log('login', 'user', (int) $user['id']);

        // Go back to the page the user originally asked for, if it fits their role.
        $intended = Session::get('_intended');
        Session::forget('_intended');
        $home = Auth::homeFor($user);
        if (is_string($intended) && str_starts_with($intended, $home)) {
            redirect($intended);
        }
        redirect($home);
    }

    public function logout(): void
    {
        Audit::log('logout', 'user', Auth::id());
        Auth::logout();
        Session::start(); // fresh, empty session just to carry the message
        Flash::set('success', 'You are logged out.');
        redirect('/admin/login');
    }
}
