<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\DB;
use App\Core\Validator;

/**
 * Screen A21: Centralized Audit & System Logs Viewer (/admin/logs & /admin/logs/export.csv, Owner only).
 * Surfaces all 6 append-only log streams required by PROMPT §7.2 A21 & §9.8:
 *   1. audit         -> Security & Audit Logs (audit_logs)
 *   2. bookings      -> Booking Change & Policy History Logs (booking_changes)
 *   3. projects      -> Project Stage Transitions & File Storage/Handover Logs (project_history + projects)
 *   4. notifications -> Client Notification & System Message Logs (message_log + system messages)
 *   5. backups       -> Backup & Data Protection Logs (backup_runs)
 *   6. errors        -> Application Error & Rate-Limit Logs (storage/logs/app.log + rate_limits)
 */
final class AdminLogController
{
    private const TABS = [
        'audit'         => 'Security & Audit',
        'bookings'      => 'Booking History',
        'projects'      => 'Projects & Handover',
        'notifications' => 'Notifications',
        'backups'       => 'Backups',
        'errors'        => 'Errors & Rate Limits',
    ];

    /**
     * GET /admin/logs
     */
    public function index(): void
    {
        $data = $this->queryLogs($_GET, 200);

        $users = DB::all('SELECT id, name, username, role FROM users ORDER BY id ASC');
        $distinctActions = DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');
        $distinctEntities = DB::all('SELECT DISTINCT entity FROM audit_logs WHERE entity IS NOT NULL ORDER BY entity ASC');

        $streamCounts = [
            'audit'         => (int) DB::value('SELECT COUNT(*) FROM audit_logs'),
            'bookings'      => (int) DB::value('SELECT COUNT(*) FROM booking_changes'),
            'projects'      => (int) DB::value('SELECT COUNT(*) FROM project_history'),
            'notifications' => (int) DB::value('SELECT COUNT(*) FROM message_log')
                             + (int) DB::value("SELECT COUNT(*) FROM messages WHERE sender_type = 'system'"),
            'backups'       => (int) DB::value('SELECT COUNT(*) FROM backup_runs'),
            'errors'        => (int) DB::value('SELECT COUNT(*) FROM rate_limits'),
        ];

        view('admin/logs', [
            'title'            => 'Audit & System Logs',
            'screen'           => 'A21',
            'back'             => '/admin/more',
            'tabs'             => self::TABS,
            'tab'              => $data['tab'],
            'filters'          => $data['filters'],
            'streamCounts'     => $streamCounts,
            'users'            => $users,
            'distinctActions'  => array_column($distinctActions, 'action'),
            'distinctEntities' => array_column($distinctEntities, 'entity'),
            'auditRows'        => $data['auditRows'],
            'bookingRows'      => $data['bookingRows'],
            'projectHistRows'  => $data['projectHistRows'],
            'projectFileRows'  => $data['projectFileRows'],
            'messageLogRows'   => $data['messageLogRows'],
            'systemMsgRows'    => $data['systemMsgRows'],
            'backupRows'       => $data['backupRows'],
            'rateLimitRows'    => $data['rateLimitRows'],
            'errorLogLines'    => $data['errorLogLines'],
        ], 'admin');
    }

    /**
     * GET /admin/logs/export.csv
     */
    public function exportCsv(): void
    {
        $data = $this->queryLogs($_GET, 1000);
        $tab = $data['tab'];
        $stamp = date('Y-m-d_His', strtotime(Clock::now()) ?: time());
        $filename = "douffer_logs_{$tab}_{$stamp}.csv";

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        fputcsv($out, ['Douffer PhotoSys - Audit & System Logs Export', self::TABS[$tab] ?? $tab, 'Generated at ' . Clock::now()]);
        fputcsv($out, []);

        if ($tab === 'audit') {
            fputcsv($out, ['ID', 'Timestamp', 'Actor', 'Role', 'Action', 'Entity', 'Entity ID', 'Details', 'IP Address']);
            foreach ($data['auditRows'] as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['created_at'],
                    $r['actor_name'] ?: 'Public / Client',
                    $r['actor_role'] ?: 'public',
                    $r['action'],
                    $r['entity'] ?? '',
                    $r['entity_id'] ?? '',
                    $r['details'] ?? '',
                    $r['ip'] ?? '',
                ]);
            }
        } elseif ($tab === 'bookings') {
            fputcsv($out, ['ID', 'Timestamp', 'Booking Ref', 'Client Name', 'Change Type', 'Old Value', 'New Value', 'Reason', 'Changed By']);
            foreach ($data['bookingRows'] as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['changed_at'],
                    $r['reference_code'],
                    $r['client_name'],
                    $r['change_type'],
                    $r['old_value'] ?? '',
                    $r['new_value'] ?? '',
                    $r['reason'] ?? '',
                    $r['actor_name'] ?: 'Client / System',
                ]);
            }
        } elseif ($tab === 'projects') {
            fputcsv($out, ['--- Project Stage History ---']);
            fputcsv($out, ['ID', 'Timestamp', 'Booking Ref', 'Client Name', 'Old Stage', 'New Stage', 'Changed By']);
            foreach ($data['projectHistRows'] as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['changed_at'],
                    $r['reference_code'],
                    $r['client_name'],
                    $r['old_stage'] ?? 'initial',
                    $r['new_stage'],
                    $r['actor_name'] ?: 'System',
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['--- File Storage, Backup & Handover Log ---']);
            fputcsv($out, ['Project ID', 'Booking Ref', 'Client Name', 'Stage', 'Due Date', 'RAW Folder', 'EDITED Folder', 'Local Backup At', 'Cloud Copy At', 'Released How', 'Released At', 'Received By', 'Released By']);
            foreach ($data['projectFileRows'] as $p) {
                fputcsv($out, [
                    $p['id'],
                    $p['reference_code'],
                    $p['client_name'],
                    $p['stage'],
                    $p['due_date'],
                    $p['raw_folder'] ?? '',
                    $p['edited_folder'] ?? '',
                    $p['local_backup_at'] ?? '',
                    $p['cloud_copy_at'] ?? '',
                    $p['released_how'] ?? '',
                    $p['released_at'] ?? '',
                    $p['received_by'] ?? '',
                    $p['released_by_name'] ?? '',
                ]);
            }
        } elseif ($tab === 'notifications') {
            fputcsv($out, ['--- Template Notification Log (A12) ---']);
            fputcsv($out, ['ID', 'Sent At', 'Booking Ref', 'Client Name', 'Template Key', 'Language', 'Channel', 'Sent By', 'Message Body']);
            foreach ($data['messageLogRows'] as $m) {
                fputcsv($out, [
                    $m['id'],
                    $m['sent_at'],
                    $m['reference_code'],
                    $m['client_name'],
                    $m['template_key'] ?? '',
                    $m['language'],
                    $m['channel'],
                    $m['actor_name'] ?: 'Studio',
                    $m['body'],
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['--- Automated System Notices in Chat ---']);
            fputcsv($out, ['Message ID', 'Created At', 'Conversation ID', 'Booking Ref', 'Read At', 'Body']);
            foreach ($data['systemMsgRows'] as $sm) {
                fputcsv($out, [
                    $sm['id'],
                    $sm['created_at'],
                    $sm['conversation_id'],
                    $sm['reference_code'] ?? '',
                    $sm['read_at'] ?? '',
                    $sm['body'],
                ]);
            }
        } elseif ($tab === 'backups') {
            fputcsv($out, ['ID', 'Created At', 'File Name', 'Size (Bytes)', 'Type', 'Created By', 'Cloud Copy Marked At']);
            foreach ($data['backupRows'] as $b) {
                fputcsv($out, [
                    $b['id'],
                    $b['created_at'],
                    $b['file_name'],
                    $b['size_bytes'],
                    $b['type'],
                    $b['created_by_name'] ?: 'CLI / Scheduled',
                    $b['cloud_copy_marked_at'] ?? '',
                ]);
            }
        } else {
            fputcsv($out, ['--- Rate Limits ---']);
            fputcsv($out, ['Key', 'Window Start (Unix)', 'Hits']);
            foreach ($data['rateLimitRows'] as $rl) {
                fputcsv($out, [$rl['rl_key'], $rl['window_start'], $rl['hits']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['--- Application Error Log (storage/logs/app.log) ---']);
            foreach ($data['errorLogLines'] as $line) {
                fputcsv($out, [$line]);
            }
        }

        fclose($out);
        exit;
    }

    /**
     * Query the requested log stream with filters applied.
     */
    private function queryLogs(array $query, int $limit = 200): array
    {
        $rawTab = strtolower(trim((string) ($query['tab'] ?? $query['type'] ?? 'audit')));
        $tab = array_key_exists($rawTab, self::TABS) ? $rawTab : 'audit';

        $q = trim((string) ($query['q'] ?? ''));
        $action = trim((string) ($query['action'] ?? ''));
        $entity = trim((string) ($query['entity'] ?? ''));
        $actor = trim((string) ($query['actor'] ?? $query['user_id'] ?? ''));
        $changeType = trim((string) ($query['change_type'] ?? ''));
        $from = trim((string) ($query['from'] ?? ''));
        $to = trim((string) ($query['to'] ?? ''));

        if (!Validator::isDate($from)) {
            $from = '';
        }
        if (!Validator::isDate($to)) {
            $to = '';
        }

        $filters = [
            'q'           => $q,
            'action'      => $action,
            'entity'      => $entity,
            'actor'       => $actor,
            'change_type' => $changeType,
            'from'        => $from,
            'to'          => $to,
        ];

        $auditRows = [];
        $bookingRows = [];
        $projectHistRows = [];
        $projectFileRows = [];
        $messageLogRows = [];
        $systemMsgRows = [];
        $backupRows = [];
        $rateLimitRows = [];
        $errorLogLines = [];

        if ($tab === 'audit') {
            $where = [];
            $params = [];

            if ($action !== '') {
                $where[] = 'al.action = ?';
                $params[] = $action;
            }
            if ($entity !== '') {
                $where[] = 'al.entity = ?';
                $params[] = $entity;
            }
            if ($actor === 'public') {
                $where[] = 'al.user_id IS NULL';
            } elseif ($actor !== '' && ctype_digit($actor)) {
                $where[] = 'al.user_id = ?';
                $params[] = (int) $actor;
            }
            if ($from !== '') {
                $where[] = 'al.created_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'al.created_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(al.action LIKE ? OR al.entity LIKE ? OR al.details LIKE ? OR al.ip LIKE ? OR u.username LIKE ? OR u.name LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }

            $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $auditRows = DB::all(
                "SELECT al.*, u.name AS actor_name, u.username AS actor_username, u.role AS actor_role
                 FROM audit_logs al
                 LEFT JOIN users u ON u.id = al.user_id
                 {$sqlWhere}
                 ORDER BY al.created_at DESC, al.id DESC
                 LIMIT {$limit}",
                $params
            );
        } elseif ($tab === 'bookings') {
            $where = [];
            $params = [];

            if ($changeType !== '') {
                $where[] = 'bc.change_type = ?';
                $params[] = $changeType;
            }
            if ($actor === 'public') {
                $where[] = 'bc.changed_by IS NULL';
            } elseif ($actor !== '' && ctype_digit($actor)) {
                $where[] = 'bc.changed_by = ?';
                $params[] = (int) $actor;
            }
            if ($from !== '') {
                $where[] = 'bc.changed_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'bc.changed_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(b.reference_code LIKE ? OR c.full_name LIKE ? OR bc.old_value LIKE ? OR bc.new_value LIKE ? OR bc.reason LIKE ?)';
                array_push($params, $like, $like, $like, $like, $like);
            }

            $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
            $bookingRows = DB::all(
                "SELECT bc.*, b.reference_code, c.full_name AS client_name, u.name AS actor_name
                 FROM booking_changes bc
                 JOIN bookings b ON b.id = bc.booking_id
                 JOIN clients c ON c.id = b.client_id
                 LEFT JOIN users u ON u.id = bc.changed_by
                 {$sqlWhere}
                 ORDER BY bc.changed_at DESC, bc.id DESC
                 LIMIT {$limit}",
                $params
            );
        } elseif ($tab === 'projects') {
            $where = [];
            $params = [];
            if ($from !== '') {
                $where[] = 'ph.changed_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'ph.changed_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(b.reference_code LIKE ? OR c.full_name LIKE ? OR ph.old_stage LIKE ? OR ph.new_stage LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
            $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $projectHistRows = DB::all(
                "SELECT ph.*, p.booking_id, b.reference_code, c.full_name AS client_name, u.name AS actor_name
                 FROM project_history ph
                 JOIN projects p ON p.id = ph.project_id
                 JOIN bookings b ON b.id = p.booking_id
                 JOIN clients c ON c.id = b.client_id
                 LEFT JOIN users u ON u.id = ph.changed_by
                 {$sqlWhere}
                 ORDER BY ph.changed_at DESC, ph.id DESC
                 LIMIT {$limit}",
                $params
            );

            $projectFileRows = DB::all(
                "SELECT p.*, b.reference_code, c.full_name AS client_name, u.name AS released_by_name
                 FROM projects p
                 JOIN bookings b ON b.id = p.booking_id
                 JOIN clients c ON c.id = b.client_id
                 LEFT JOIN users u ON u.id = p.released_by
                 ORDER BY p.id DESC
                 LIMIT {$limit}"
            );
        } elseif ($tab === 'notifications') {
            $where = [];
            $params = [];
            if ($from !== '') {
                $where[] = 'ml.sent_at >= ?';
                $params[] = $from . ' 00:00:00';
            }
            if ($to !== '') {
                $where[] = 'ml.sent_at <= ?';
                $params[] = $to . ' 23:59:59';
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(b.reference_code LIKE ? OR c.full_name LIKE ? OR ml.template_key LIKE ? OR ml.body LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
            $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $messageLogRows = DB::all(
                "SELECT ml.*, b.reference_code, c.full_name AS client_name, u.name AS actor_name
                 FROM message_log ml
                 JOIN bookings b ON b.id = ml.booking_id
                 JOIN clients c ON c.id = b.client_id
                 LEFT JOIN users u ON u.id = ml.sent_marked_by
                 {$sqlWhere}
                 ORDER BY ml.sent_at DESC, ml.id DESC
                 LIMIT {$limit}",
                $params
            );

            $systemMsgRows = DB::all(
                "SELECT m.id, m.conversation_id, m.body, m.read_at, m.created_at,
                        conv.booking_id, b.reference_code, c.full_name AS client_name
                 FROM messages m
                 JOIN conversations conv ON conv.id = m.conversation_id
                 LEFT JOIN bookings b ON b.id = conv.booking_id
                 LEFT JOIN clients c ON c.id = conv.client_id
                 WHERE m.sender_type = 'system'
                 ORDER BY m.created_at DESC, m.id DESC
                 LIMIT {$limit}"
            );
        } elseif ($tab === 'backups') {
            $backupRows = DB::all(
                "SELECT br.*, u.name AS created_by_name
                 FROM backup_runs br
                 LEFT JOIN users u ON u.id = br.created_by
                 ORDER BY br.created_at DESC, br.id DESC
                 LIMIT {$limit}"
            );
        } else {
            $rateLimitRows = DB::all(
                "SELECT * FROM rate_limits ORDER BY window_start DESC LIMIT 100"
            );

            $logFile = storage_path('logs/app.log');
            if (is_file($logFile) && is_readable($logFile)) {
                $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
                if (is_array($lines)) {
                    $errorLogLines = array_slice($lines, -200);
                }
            }
        }

        return [
            'tab'             => $tab,
            'filters'         => $filters,
            'auditRows'       => $auditRows,
            'bookingRows'     => $bookingRows,
            'projectHistRows' => $projectHistRows,
            'projectFileRows' => $projectFileRows,
            'messageLogRows'  => $messageLogRows,
            'systemMsgRows'   => $systemMsgRows,
            'backupRows'      => $backupRows,
            'rateLimitRows'   => $rateLimitRows,
            'errorLogLines'   => $errorLogLines,
        ];
    }
}
