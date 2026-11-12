<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/** Request lifecycle: session -> headers -> route -> error pages. */
final class App
{
    public static function run(): void
    {
        $GLOBALS['_dps_is_api'] = false;
        try {
            Session::start();
            Flash::ageFormState();
            self::securityHeaders();

            $router = new Router();
            (require APP_DIR . '/routes.php')($router);

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $router->dispatch($method, current_path());
        } catch (HttpException $e) {
            self::renderError($e->status, $e->getMessage());
        } catch (Throwable $e) {
            self::logException($e);
            if (Config::get('env') === 'development') {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Development error (hidden in production):\n\n" . $e;
                return;
            }
            self::renderError(500);
        }
    }

    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), payment=()');
        // No inline scripts or inline style attributes anywhere in the app.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; script-src 'self'; "
            . "style-src 'self'; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; manifest-src 'self'; worker-src 'self'");
        // Pages contain private data: never let the browser or a proxy cache them.
        header('Cache-Control: no-store, max-age=0');
    }

    public static function renderError(int $status, string $message = ''): void
    {
        if (!headers_sent()) {
            // 419 "session expired" is not a standard code; Apache turns it into 500. Send 403 instead.
            http_response_code($status === 419 ? 403 : $status);
        }
        if (!empty($GLOBALS['_dps_is_api']) || str_starts_with(current_path(), '/api/')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => $message !== '' ? $message : self::title($status)]);
            return;
        }
        $layout = str_starts_with(current_path(), '/admin') || str_starts_with(current_path(), '/crew')
            || str_starts_with(current_path(), '/account') ? 'admin' : 'client';
        if ($layout === 'admin' && !Auth::check()) {
            $layout = 'auth';
        } elseif ($layout === 'admin' && Auth::isCrew()) {
            $layout = 'crew'; // crew never see the admin navigation
        }
        try {
            echo View::render('errors/error', [
                'title'   => self::title($status),
                'status'  => $status,
                'message' => $message,
                'screen'  => (string) $status,
            ], $layout);
        } catch (Throwable $e) {
            self::logException($e);
            echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>Something went wrong. Please try again.</p>';
        }
    }

    private static function title(int $status): string
    {
        return match ($status) {
            403     => 'You do not have access to this page',
            404     => 'Page not found',
            405     => 'Action not allowed',
            419     => 'Session expired',
            429     => 'Too many attempts',
            default => 'Something went wrong',
        };
    }

    public static function logException(Throwable $e): void
    {
        $line = sprintf("[%s] %s: %s in %s:%d\n%s\n\n", date('Y-m-d H:i:s'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        @file_put_contents(storage_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
    }
}
