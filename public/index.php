<?php
/**
 * Douffer PhotoSys: front controller. Every page request comes through here.
 * Dev server: php -S localhost:8000 -t public public/index.php
 */
declare(strict_types=1);

// Built-in PHP server: let it serve real static files (CSS, JS, icons) itself.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\App::run();
