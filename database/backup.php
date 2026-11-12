<?php
/**
 * Douffer PhotoSys: CLI Database Backup Script (§9.6).
 *
 * Usage:
 *   php database/backup.php
 *
 * Can be scheduled with Windows Task Scheduler or cron to create daily
 * SQL dumps in storage/backups/ and log them in backup_runs.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

try {
    $run = BackupService::create('scheduled', null);
    echo sprintf(
        "Backup created: storage/backups/%s (%s bytes)\n",
        $run['file_name'],
        number_format((int) $run['size_bytes'])
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed: " . $e->getMessage() . "\n");
    exit(1);
}
