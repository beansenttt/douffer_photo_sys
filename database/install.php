<?php
/**
 * Douffer PhotoSys: CLI installer.
 *
 *   php database/install.php            create the database (if needed), tables and sample data
 *   php database/install.php --fresh    DROP the whole database first, then install
 *   php database/install.php --no-seed  tables only, plus one owner account (owner / Owner@2026)
 *
 * Also creates config/config.php from the sample and the private storage/ folders.
 * For phpMyAdmin: import database/schema.sql, then database/seed.sql.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$fresh = in_array('--fresh', $args, true);
$seed = !in_array('--no-seed', $args, true);

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

// 1. config.php ---------------------------------------------------------------
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    copy($root . '/config/config.sample.php', $configFile);
    out('✓ Created config/config.php from config.sample.php (edit it if your MySQL password is not empty).');
}
$config = require $configFile;
$db = $config['db'];

// 2. Connect to the server (no database yet) ------------------------------------
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    out('✗ Cannot connect to MySQL: ' . $e->getMessage());
    out('  Start MySQL in the XAMPP Control Panel and check config/config.php.');
    exit(1);
}

$name = $db['name'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    out('✗ Invalid database name in config.');
    exit(1);
}

if ($fresh) {
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    out("✓ Dropped database `$name`.");
}
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`");

$existing = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($name))->fetchColumn();
if ($existing > 0 && !$fresh) {
    out("✗ Database `$name` already has $existing tables. Use --fresh to delete everything and reinstall.");
    exit(1);
}

// 3. Run the SQL files ------------------------------------------------------------
/**
 * Split a .sql file into statements on ";" outside of quotes and comments.
 */
function split_sql(string $sql): array
{
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote === null) {
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') { // line comment
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
            } elseif ($c === ';') {
                if (trim($buf) !== '') {
                    $statements[] = trim($buf);
                }
                $buf = '';
                continue;
            }
        } else {
            if ($c === '\\' && $quote !== '`') { // backslash escape inside a string
                $buf .= $c . ($sql[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) { // doubled quote ''
                    $buf .= $c . $c;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $statements[] = trim($buf);
    }
    return $statements;
}

function run_file(PDO $pdo, string $file): int
{
    $count = 0;
    foreach (split_sql((string) file_get_contents($file)) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            out('✗ SQL error in ' . basename($file) . ': ' . $e->getMessage());
            out('  Statement: ' . substr($stmt, 0, 200) . '...');
            exit(1);
        }
        $count++;
    }
    return $count;
}

out('✓ Schema: ' . run_file($pdo, __DIR__ . '/schema.sql') . ' statements.');

if ($seed) {
    out('✓ Sample data: ' . run_file($pdo, __DIR__ . '/seed.sql') . ' statements.');
} else {
    $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, must_change_password) VALUES (?, 'owner', ?, 'owner', 1)");
    $stmt->execute(['Studio Owner', password_hash('Owner@2026', PASSWORD_DEFAULT)]);
    foreach ([
        'down_payment_mode' => 'percent', 'down_payment_value' => '30', 'max_events_per_day' => '2',
        'default_delivery_days' => '14', 'balance_overdue_days' => '0', 'current_terms_version' => '1',
    ] as $k => $v) {
        $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')->execute([$k, $v]);
    }
    $pdo->prepare("INSERT INTO terms_versions (version, body, effective_from) VALUES ('1', ?, CURDATE())")
        ->execute(['The down payment is non-refundable if you cancel. Rescheduling is free if the new date is open.']);
    out('✓ Created owner account only (owner / Owner@2026).');
}

// 4. Private storage folders + sample GCash screenshots ----------------------------
$storage = rtrim($config['storage_path'] ?? $root . '/storage', '/\\');
foreach (['receipts', 'chat', 'uploads', 'backups', 'logs'] as $dir) {
    if (!is_dir("$storage/$dir")) {
        mkdir("$storage/$dir", 0775, true);
    }
}
if (!is_file("$storage/.htaccess")) {
    file_put_contents("$storage/.htaccess", "Require all denied\n");
}

/**
 * Write a simple placeholder "GCash screenshot" PNG without the GD extension.
 * Blue header band, white body, grey text bars. Clearly fake.
 */
function write_sample_png(string $path, array $accent): void
{
    $w = 270;
    $h = 480;
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0"; // filter type: none
        for ($x = 0; $x < $w; $x++) {
            $rgb = [255, 255, 255];
            if ($y < 90) {
                $rgb = $accent;                                        // header band
            } elseif ($y >= 130 && $y < 150 && $x >= 30 && $x < 240) {
                $rgb = [200, 205, 212];                                // "Amount" bar
            } elseif ($y >= 170 && $y < 210 && $x >= 30 && $x < 180) {
                $rgb = [60, 70, 80];                                   // big amount
            } elseif (($y >= 250 && $y < 262) || ($y >= 290 && $y < 302) || ($y >= 330 && $y < 342)) {
                $rgb = ($x >= 30 && $x < 240) ? [220, 224, 230] : $rgb; // detail lines
            } elseif ($y >= 400 && $y < 440 && $x >= 30 && $x < 240) {
                $rgb = [230, 240, 255];                                // footer box
            }
            $raw .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
        }
    }
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
    file_put_contents($path, $png);
}

foreach (['santos' => [0, 112, 224], 'reyes' => [0, 112, 224], 'mendoza' => [0, 90, 200]] as $who => $color) {
    write_sample_png("$storage/receipts/sample-$who.png", $color);
}
out('✓ Storage folders ready (storage/ is outside the web root). Sample receipt images created.');

if (!extension_loaded('gd')) {
    out('! Note: the PHP GD extension is off. Uploads still work, but thumbnails and metadata stripping need GD.');
    out('  Enable it in C:\\xampp\\php\\php.ini by removing the ";" in front of "extension=gd", then restart Apache.');
}

out('');
out('Done. Open http://localhost/douffer_photosys/ (XAMPP) or run: php -S localhost:8000 -t public public/index.php');
out('Logins: owner / Owner@2026 · staff / Staff@2026 · crew1 / Crew1@2026 · crew2 / Crew2@2026 (change them after logging in).');
