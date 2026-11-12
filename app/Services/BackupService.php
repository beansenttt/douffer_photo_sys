<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use PDO;
use RuntimeException;

/**
 * Screen A20 & CLI: Database Backup Service (§9.6, Risk 4).
 * Generates a complete PDO SQL dump into storage/backups/ and records it in backup_runs.
 */
final class BackupService
{
    /**
     * Create a new .sql dump in storage/backups/ and log it in backup_runs + audit_logs.
     *
     * @param string   $type   'manual' or 'scheduled'
     * @param int|null $userId user who triggered the backup (null for CLI scheduled runs)
     * @return array The created backup_runs row
     */
    public static function create(string $type = 'manual', ?int $userId = null): array
    {
        $type = in_array($type, ['manual', 'scheduled'], true) ? $type : 'manual';
        $actorId = $userId ?? Auth::id();
        $now = Clock::now();

        $dir = storage_path('backups');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage/backups directory.');
        }

        $stamp = date('Y-m-d_His', strtotime($now) ?: time());
        $fileName = "douffer_backup_{$stamp}.sql";
        if (is_file($dir . '/' . $fileName)) {
            $fileName = "douffer_backup_{$stamp}_" . substr(bin2hex(random_bytes(2)), 0, 4) . ".sql";
        }

        $fullPath = $dir . '/' . $fileName;
        $sql = self::buildSqlDump($now);

        if (file_put_contents($fullPath, $sql, LOCK_EX) === false) {
            throw new RuntimeException('Could not write SQL backup file.');
        }

        $sizeBytes = (int) filesize($fullPath);

        $runId = DB::insert('backup_runs', [
            'created_at'           => $now,
            'file_name'            => $fileName,
            'size_bytes'           => $sizeBytes,
            'type'                 => $type,
            'created_by'           => $actorId,
            'cloud_copy_marked_at' => null,
        ]);

        Audit::log('backup_created', 'backup_run', $runId, [
            'file_name'  => $fileName,
            'size_bytes' => $sizeBytes,
            'type'       => $type,
        ]);

        return (array) DB::one('SELECT * FROM backup_runs WHERE id = ?', [$runId]);
    }

    /**
     * Record that the owner manually copied a backup to cloud storage (§9.6).
     */
    public static function markCloudCopy(int $backupId, ?int $userId = null): void
    {
        $row = DB::one('SELECT * FROM backup_runs WHERE id = ?', [$backupId]);
        if (!$row) {
            throw new RuntimeException('Backup record not found.');
        }

        $now = Clock::now();
        DB::update('backup_runs', ['cloud_copy_marked_at' => $now], 'id = ?', [$backupId]);

        Audit::log('backup_cloud_marked', 'backup_run', $backupId, [
            'file_name'            => $row['file_name'],
            'cloud_copy_marked_at' => $now,
            'marked_by'            => $userId ?? Auth::id(),
        ]);
    }

    /**
     * List all backup runs (newest first).
     */
    public static function listAll(): array
    {
        return DB::all(
            "SELECT br.*, u.name AS created_by_name
             FROM backup_runs br
             LEFT JOIN users u ON u.id = br.created_by
             ORDER BY br.created_at DESC, br.id DESC"
        );
    }

    /**
     * Resolve the local file path for a backup run (creating a placeholder if it is the seeded sample row).
     */
    public static function ensureFileForDownload(array $backupRun): ?string
    {
        $fileName = basename((string) ($backupRun['file_name'] ?? ''));
        if ($fileName === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $fileName)) {
            return null;
        }

        $dir = storage_path('backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir . '/' . $fileName;
        if (!is_file($path) && str_contains($fileName, 'SAMPLE')) {
            $sampleSql = "-- Douffer PhotoSys Sample Seeded Backup (" . $fileName . ")\n"
                . "-- Generated at: " . ($backupRun['created_at'] ?? Clock::now()) . "\n"
                . "SET NAMES utf8mb4;\n";
            @file_put_contents($path, $sampleSql, LOCK_EX);
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Build a full SQL dump of all tables in the database using PDO.
     */
    private static function buildSqlDump(string $timestamp): string
    {
        $pdo = DB::pdo();
        $out = [];
        $out[] = '-- =====================================================================';
        $out[] = '-- Douffer PhotoSys Database Backup';
        $out[] = '-- Generated at: ' . $timestamp . ' (Asia/Manila)';
        $out[] = '-- =====================================================================';
        $out[] = '';
        $out[] = 'SET NAMES utf8mb4;';
        $out[] = "SET time_zone = '+08:00';";
        $out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $out[] = '';

        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        foreach ($tables as $table) {
            $tableName = (string) $table;
            $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';

            $createStmt = $pdo->query("SHOW CREATE TABLE {$quotedTable}");
            $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_NUM) : false;
            $createSql = is_array($createRow) && isset($createRow[1]) ? (string) $createRow[1] : '';

            $out[] = "-- ---------------------------------------------------------------- {$tableName}";
            $out[] = "DROP TABLE IF EXISTS {$quotedTable};";
            if ($createSql !== '') {
                $out[] = $createSql . ';';
            }

            $rowsStmt = $pdo->query("SELECT * FROM {$quotedTable}");
            $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            if (!empty($rows)) {
                $cols = array_map(
                    static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`',
                    array_keys($rows[0])
                );
                $colList = implode(', ', $cols);

                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($r as $val) {
                        if ($val === null) {
                            $vals[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $vals[] = (string) $val;
                        } else {
                            $vals[] = $pdo->quote((string) $val);
                        }
                    }
                    $out[] = "INSERT INTO {$quotedTable} ({$colList}) VALUES (" . implode(', ', $vals) . ");";
                }
            }
            $out[] = '';
        }

        $out[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $out[] = '';

        return implode("\n", $out);
    }
}
