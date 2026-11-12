<?php
/**
 * Douffer PhotoSys: configuration template.
 *
 * Copy this file to config/config.php (the installer does it for you) and edit
 * the values. config.php is git-ignored, so passwords never end up in the repo.
 */
return [
    'app_name'  => 'Douffer PhotoSys',

    // 'development' shows PHP errors on screen; 'production' hides them and only logs.
    'env'       => getenv('APP_ENV') ?: 'development',

    // URL path where the app lives. null = auto-detect
    // ('/douffer_photosys' under XAMPP, '' with `php -S`).
    'base_path' => getenv('BASE_PATH') !== false ? getenv('BASE_PATH') : null,

    'timezone'  => 'Asia/Manila',

    // Demo mode: when set (YYYY-MM-DD), "today" in the whole app equals this date
    // so the team can demo Sat, Dec 12, 2026 exactly like the paper prototype.
    // Set to null to use the real date.
    'demo_date' => getenv('DEMO_DATE') !== false ? getenv('DEMO_DATE') : '2026-12-12',

    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'douffer_photosys',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    'session_name'         => 'dps_session',
    'session_idle_minutes' => 480, // log out after 8 hours without activity

    // Private files (receipts, chat images, backups, logs). Must be OUTSIDE public/.
    'storage_path' => dirname(__DIR__) . '/storage',

    // Optional: full path to mysqldump. Leave null to use the built-in PDO dump.
    'mysqldump_path' => null,
];
