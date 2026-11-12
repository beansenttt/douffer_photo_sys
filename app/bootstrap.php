<?php
/**
 * Douffer PhotoSys: bootstrap.
 * Loads config, the class autoloader, helpers, error handling and the session.
 * Used by public/index.php, the CLI scripts in database/ and tests/run.php.
 */
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
define('APP_DIR', __DIR__);

// PSR-4 style autoloader: App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = APP_DIR . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_DIR . '/helpers.php';

use App\Core\Config;

$configFile = BASE_DIR . '/config/config.php';
if (!is_file($configFile)) {
    if (is_file(BASE_DIR . '/config/config.sample.php')) {
        $configFile = BASE_DIR . '/config/config.sample.php';
    } else {
        $msg = 'Missing config/config.php. Run "php database/install.php" or copy config/config.sample.php to config/config.php.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title><p>' . htmlspecialchars($msg) . '</p>';
        exit;
    }
}

Config::load(require $configFile);
date_default_timezone_set((string) Config::get('timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

// ---- Error handling ------------------------------------------------------
$isDev = Config::get('env') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', storage_path('logs/php-error.log'));

// Turn warnings/notices into exceptions so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
