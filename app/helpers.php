<?php
/**
 * Global helper functions used by controllers and views.
 * Kept short and boring on purpose so the team can explain each one.
 */
declare(strict_types=1);

use App\Core\Clock;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;

// ---------------------------------------------------------------- output

/** Escape for HTML. Use on EVERY value printed in a view. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Render a partial view: <?= partial('partials/tag', ['status' => 'pending']) ?> */
function partial(string $view, array $data = []): string
{
    return View::capture($view, $data);
}

/** Send a full page: render the view inside a layout. */
function view(string $view, array $data = [], string $layout = 'client'): void
{
    echo View::render($view, $data, $layout);
}

// ---------------------------------------------------------------- URLs

/** URL path where the app lives ('' or '/douffer_photosys'). */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = Config::get('base_path');
    if (is_string($configured)) {
        return $base = rtrim($configured, '/');
    }
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        return $base = '';
    }
    // Auto-detect from the front controller location, e.g. /douffer_photosys/public/index.php
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = rtrim($dir, '/');
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    // When the root .htaccess hides "public/", the browser URL does not contain it.
    if (str_ends_with($dir, '/public') && !str_starts_with($uri, $dir . '/') && $uri !== $dir) {
        $dir = substr($dir, 0, -7);
    }
    return $base = $dir;
}

/** Path of the current request without base path and query string. */
function current_path(): string
{
    $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    if (str_starts_with($uri, '/index.php')) {
        $uri = substr($uri, 10);
    }
    $uri = '/' . ltrim(rawurldecode($uri), '/');
    return $uri === '' ? '/' : $uri;
}

function current_path_with_query(): string
{
    $q = $_SERVER['QUERY_STRING'] ?? '';
    return current_path() . ($q !== '' ? '?' . $q : '');
}

/** Build an app URL: url('/admin/bookings', ['status' => 'pending']). */
function url(string $path = '/', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u === '' ? '/' : $u;
}

/** Static file URL with a cache-busting version. */
function asset(string $path): string
{
    $file = BASE_DIR . '/public/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url($path) . '?v=' . $v;
}

/** Is the given nav prefix the current section? Used for aria-current. */
function nav_active(string $prefix, bool $exact = false): bool
{
    $p = current_path();
    return $exact ? $p === $prefix : ($p === $prefix || str_starts_with($p, rtrim($prefix, '/') . '/'));
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

// ---------------------------------------------------------------- responses

/** Redirect to an app path and stop. */
function redirect(string $path): void
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target, true, 303);
    exit;
}

/** Redirect back to a safe local path (used after a form error). */
function redirect_back(string $fallback = '/'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
        header('Location: ' . $ref, true, 303);
        exit;
    }
    redirect($fallback);
}

/** Send JSON and stop. */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function abort(int $status, string $message = ''): void
{
    throw new \App\Core\HttpException($status, $message);
}

// ---------------------------------------------------------------- forms

function csrf_field(): string
{
    return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . e(Csrf::token()) . '">';
}

function csrf_token(): string
{
    return Csrf::token();
}

/** Previously submitted value (after a validation error). */
function old(string $key, mixed $default = ''): string
{
    $v = Flash::old($key, $default);
    return is_scalar($v) ? (string) $v : '';
}

function field_error(string $key): ?string
{
    return Flash::error($key);
}

/** Error text under a field, connected with aria-describedby="err-<name>". */
function error_html(string $key): string
{
    $msg = Flash::error($key);
    return $msg ? '<p class="field-error" id="err-' . e($key) . '">' . e($msg) . '</p>' : '';
}

/** Attributes for an input with a possible error. */
function invalid_attr(string $key): string
{
    return Flash::error($key) ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"' : '';
}

// ---------------------------------------------------------------- formatting

/** ₱12,500.00 */
function peso(float|int|string|null $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** "Sat, Dec 12, 2026" */
function date_long(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('D, M j, Y', $ts) : '';
}

/** "Dec 12, 2026" */
function date_short(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : '';
}

/** "9:00 AM" */
function time12(?string $time): string
{
    if (!$time) {
        return '';
    }
    $ts = strtotime('2000-01-01 ' . $time);
    return $ts ? date('g:i A', $ts) : '';
}

/** "Dec 12, 2026, 3:15 PM" */
function datetime_short(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('M j, Y, g:i A', $ts) : '';
}

/**
 * Normalize a Philippine mobile number to 09XXXXXXXXX.
 * Accepts 09171234567, 0917 123 4567, +639171234567, 639171234567.
 * Returns null if it is not a valid PH mobile number.
 */
function normalize_mobile(string $input): ?string
{
    $digits = preg_replace('/\D+/', '', $input);
    if (preg_match('/^639(\d{9})$/', $digits, $m)) {
        return '09' . $m[1];
    }
    if (preg_match('/^09\d{9}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '0' . $digits;
    }
    return null;
}

/** "0917 123 4567" */
function format_mobile(?string $mobile): string
{
    $n = normalize_mobile((string) $mobile);
    if ($n === null) {
        return (string) $mobile;
    }
    return substr($n, 0, 4) . ' ' . substr($n, 4, 3) . ' ' . substr($n, 7);
}

// ---------------------------------------------------------------- files

/** Absolute path inside storage/ (outside the web root). */
function storage_path(string $rel = ''): string
{
    $base = rtrim((string) Config::get('storage_path', BASE_DIR . '/storage'), '/\\');
    return $base . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

// ---------------------------------------------------------------- icons

/** Inline SVG icon (no icon font download). */
function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'home'     => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'list'     => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'more'     => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'chat'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'back'     => '<path d="M15 18l-6-6 6-6"/>',
        'next'     => '<path d="M9 18l6-6-6-6"/>',
        'logout'   => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
        'check'    => '<path d="M5 12l5 5L20 7"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20a7 7 0 0 1 14 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20a7 7 0 0 0-4-6.3"/>',
        'box'      => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3.6 15H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9.7 4.6V4a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>',
        'camera'   => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'alert'    => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.5"/>',
        'print'    => '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 15h12v6H6z"/>',
        'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    ];
    $p = $paths[$name] ?? $paths['more'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}
