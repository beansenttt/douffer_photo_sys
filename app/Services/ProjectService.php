<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Models\Booking;
use InvalidArgumentException;
use RuntimeException;

/**
 * Screen A10 (Project Tracker) & A11 (Files & Handover Log) service (Milestone 3 / Scenario T5).
 * Enforces linear stage transitions, editing checklists, overdue detection,
 * file storage path logging, backup completion tracking, and booking completion on release.
 */
final class ProjectService
{
    public const STAGES = ['booked', 'covered', 'editing', 'ready', 'released'];
    public const RELEASE_METHODS = ['pickup', 'messenger_link', 'other'];

    /**
     * Get a project by its associated booking ID with joined metadata and calculated fields.
     */
    public static function getByBookingId(int $bookingId): ?array
    {
        $row = DB::one(
            "SELECT p.*,
                    b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                    b.venue, b.status AS booking_status, b.total_amount, b.is_archived,
                    c.id AS client_id, c.full_name AS client_name, c.mobile, c.messenger_name,
                    pk.id AS package_id, pk.name AS package_name, pk.category AS package_category,
                    u.name AS released_by_name
             FROM projects p
             JOIN bookings b ON b.id = p.booking_id
             JOIN clients c ON c.id = b.client_id
             JOIN packages pk ON pk.id = b.package_id
             LEFT JOIN users u ON u.id = p.released_by
             WHERE p.booking_id = ?",
            [$bookingId]
        );

        return $row ? self::enrichProject($row) : null;
    }

    /**
     * Get a project by its primary ID with joined metadata and calculated fields.
     */
    public static function getById(int $id): ?array
    {
        $row = DB::one(
            "SELECT p.*,
                    b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                    b.venue, b.status AS booking_status, b.total_amount, b.is_archived,
                    c.id AS client_id, c.full_name AS client_name, c.mobile, c.messenger_name,
                    pk.id AS package_id, pk.name AS package_name, pk.category AS package_category,
                    u.name AS released_by_name
             FROM projects p
             JOIN bookings b ON b.id = p.booking_id
             JOIN clients c ON c.id = b.client_id
             JOIN packages pk ON pk.id = b.package_id
             LEFT JOIN users u ON u.id = p.released_by
             WHERE p.id = ?",
            [$id]
        );

        return $row ? self::enrichProject($row) : null;
    }

    /**
     * Fetch all projects matching optional filters (stage, overdue, search query).
     *
     * @param array{stage?: string, overdue?: bool, q?: string} $filters
     * @return array<int, array>
     */
    public static function getAll(array $filters = []): array
    {
        $today = Clock::today();
        $where = ['b.is_archived = 0'];
        $params = [];

        if (!empty($filters['stage']) && $filters['stage'] !== 'all') {
            if (!in_array($filters['stage'], self::STAGES, true)) {
                throw new InvalidArgumentException("Invalid stage filter: {$filters['stage']}");
            }
            $where[] = 'p.stage = ?';
            $params[] = $filters['stage'];
        }

        if (!empty($filters['overdue'])) {
            $where[] = 'p.due_date < ? AND p.stage != "released"';
            $params[] = $today;
        }

        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            if ($q !== '') {
                $like = '%' . $q . '%';
                $where[] = '(c.full_name LIKE ? OR b.reference_code LIKE ? OR b.venue LIKE ? OR b.event_type LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }
        }

        $sql = "SELECT p.*,
                       b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                       b.venue, b.status AS booking_status, b.total_amount, b.is_archived,
                       c.id AS client_id, c.full_name AS client_name, c.mobile, c.messenger_name,
                       pk.id AS package_id, pk.name AS package_name, pk.category AS package_category,
                       u.name AS released_by_name
                FROM projects p
                JOIN bookings b ON b.id = p.booking_id
                JOIN clients c ON c.id = b.client_id
                JOIN packages pk ON pk.id = b.package_id
                LEFT JOIN users u ON u.id = p.released_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.due_date ASC, p.id ASC";

        $rows = DB::all($sql, $params);
        return array_map([self::class, 'enrichProject'], $rows);
    }

    /**
     * Compute summary counts for stage filter chips on Screen A10.
     *
     * @return array{all: int, booked: int, covered: int, editing: int, ready: int, released: int, overdue: int}
     */
    public static function getStageCounts(): array
    {
        $today = Clock::today();
        $rows = DB::all(
            "SELECT p.stage,
                    COUNT(*) AS count,
                    SUM(CASE WHEN p.due_date < ? AND p.stage != 'released' THEN 1 ELSE 0 END) AS overdue_count
             FROM projects p
             JOIN bookings b ON b.id = p.booking_id
             WHERE b.is_archived = 0
             GROUP BY p.stage",
            [$today]
        );

        $counts = [
            'all'      => 0,
            'booked'   => 0,
            'covered'  => 0,
            'editing'  => 0,
            'ready'    => 0,
            'released' => 0,
            'overdue'  => 0,
        ];

        foreach ($rows as $r) {
            $stage = $r['stage'];
            $c = (int) $r['count'];
            if (isset($counts[$stage])) {
                $counts[$stage] = $c;
            }
            $counts['all'] += $c;
            $counts['overdue'] += (int) $r['overdue_count'];
        }

        return $counts;
    }

    /**
     * Progress or change stage (e.g. booked -> covered -> editing -> ready -> released).
     * Records transition in project_history and audit_logs.
     */
    public static function updateStage(int $projectId, string $newStage, int $userId): void
    {
        if (!in_array($newStage, self::STAGES, true)) {
            throw new InvalidArgumentException("Invalid project stage: '{$newStage}'");
        }

        DB::transaction(function () use ($projectId, $newStage, $userId) {
            $project = DB::one('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if (!$project) {
                throw new RuntimeException("Project #{$projectId} not found.");
            }

            $oldStage = $project['stage'];
            if ($oldStage === $newStage) {
                return;
            }

            $now = Clock::now();
            DB::update('projects', [
                'stage'      => $newStage,
                'updated_at' => $now,
            ], 'id = ?', [$projectId]);

            DB::insert('project_history', [
                'project_id' => $projectId,
                'old_stage'  => $oldStage,
                'new_stage'  => $newStage,
                'changed_by' => $userId,
                'changed_at' => $now,
            ]);

            Audit::log('project_stage_changed', 'project', $projectId, [
                'old_stage' => $oldStage,
                'new_stage' => $newStage,
            ]);

            if ($newStage === 'ready') {
                MessageService::postSystem((int) $project['booking_id'], 'Your photos and videos are now ready!');
            }
        });
    }

    /**
     * Update editing checklist items (Screen A10).
     */
    public static function updateChecklist(int $projectId, bool $editing, bool $rendering, bool $album, int $userId = 1): void
    {
        DB::transaction(function () use ($projectId, $editing, $rendering, $album, $userId) {
            $project = DB::one('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if (!$project) {
                throw new RuntimeException("Project #{$projectId} not found.");
            }

            DB::update('projects', [
                'editing_done'   => $editing ? 1 : 0,
                'rendering_done' => $rendering ? 1 : 0,
                'album_done'     => $album ? 1 : 0,
                'updated_at'     => Clock::now(),
            ], 'id = ?', [$projectId]);

            Audit::log('project_checklist_updated', 'project', $projectId, [
                'editing_done'   => (int) $editing,
                'rendering_done' => (int) $rendering,
                'album_done'     => (int) $album,
            ]);
        });
    }

    /**
     * Check release warnings for Screen A11 confirmation sheet.
     *
     * @return array{has_missing_cloud_copy: bool, cloud_warning: string, has_unpaid_balance: bool, balance_amount: float, balance_warning: string}
     */
    public static function checkReleaseWarnings(int $projectId): array
    {
        $project = self::getById($projectId);
        if (!$project) {
            throw new RuntimeException("Project #{$projectId} not found.");
        }

        $hasMissingCloud = empty($project['cloud_copy_done']);
        $hasUnpaidBalance = $project['has_unpaid_balance'];
        $balance = $project['balance_amount'];

        return [
            'has_missing_cloud_copy' => $hasMissingCloud,
            'cloud_warning'          => 'No cloud copy yet. Release anyway?',
            'has_unpaid_balance'     => $hasUnpaidBalance,
            'balance_amount'         => $balance,
            'balance_warning'        => 'Balance not fully paid (' . peso($balance) . '). Release anyway?',
        ];
    }

    /**
     * Save handover metadata, storage paths, backup flags, and optionally mark project as released.
     * If marking released:
     * - verifies release warnings and requires confirm_release_override if warnings apply
     * - sets stage = 'released'
     * - logs to project_history
     * - checks unpaid balance: if balance is ₱0, sets booking status = 'completed' and logs to booking_changes.
     *
     * @param array{
     *   raw_folder?: ?string,
     *   edited_folder?: ?string,
     *   local_backup_done?: bool|int,
     *   cloud_copy_done?: bool|int,
     *   released_how?: ?string,
     *   released_note?: ?string,
     *   received_by?: ?string,
     *   released_at?: ?string,
     *   mark_released?: bool|int,
     *   confirm_release_override?: bool|int
     * } $data
     */
    public static function saveHandover(int $projectId, array $data, int $userId): void
    {
        DB::transaction(function () use ($projectId, $data, $userId) {
            $project = DB::one('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if (!$project) {
                throw new RuntimeException("Project #{$projectId} not found.");
            }

            $bookingId = (int) $project['booking_id'];
            $booking = DB::one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if (!$booking) {
                throw new RuntimeException("Booking #{$bookingId} not found.");
            }

            $now = Clock::now();
            $today = Clock::today();

            $rawFolder = isset($data['raw_folder']) ? trim((string) $data['raw_folder']) : $project['raw_folder'];
            $editedFolder = isset($data['edited_folder']) ? trim((string) $data['edited_folder']) : $project['edited_folder'];

            $localDone = array_key_exists('local_backup_done', $data)
                ? (!empty($data['local_backup_done']) ? 1 : 0)
                : (int) ($project['local_backup_done'] ?? 0);
            $localAt = $localDone
                ? ($project['local_backup_at'] ?? $now)
                : null;

            $cloudDone = array_key_exists('cloud_copy_done', $data)
                ? (!empty($data['cloud_copy_done']) ? 1 : 0)
                : (int) ($project['cloud_copy_done'] ?? 0);
            $cloudAt = $cloudDone
                ? ($project['cloud_copy_at'] ?? $now)
                : null;

            $markReleased = !empty($data['mark_released']) || ($project['stage'] === 'released');

            $balance = Booking::balance($booking);

            if ($markReleased) {
                $hasWarning = (!$cloudDone || $balance > 0.009);
                if ($hasWarning && empty($data['confirm_release_override'])) {
                    throw new ValidationException(
                        'Confirmation required to release deliverables with missing cloud copy or unpaid balance.',
                        ['confirm_release_override' => 'Confirmation required to release deliverables with warnings.']
                    );
                }

                $releasedHow = $data['released_how'] ?? $project['released_how'] ?? 'pickup';
                if (!in_array($releasedHow, self::RELEASE_METHODS, true)) {
                    $releasedHow = 'pickup';
                }

                $releasedNote = isset($data['released_note']) ? trim((string) $data['released_note']) : $project['released_note'];
                $clientFullName = DB::value('SELECT full_name FROM clients WHERE id = ?', [$booking['client_id']]) ?? 'Client';
                $receivedBy = isset($data['received_by']) && trim((string) $data['received_by']) !== ''
                    ? trim((string) $data['received_by'])
                    : ($project['received_by'] ?? $clientFullName);
                $releasedAt = !empty($data['released_at']) ? (string) $data['released_at'] : ($project['released_at'] ?? $today);

                $oldStage = $project['stage'];
                $updates = [
                    'stage'             => 'released',
                    'released_how'      => $releasedHow,
                    'released_note'     => $releasedNote !== '' ? $releasedNote : null,
                    'released_at'       => $releasedAt,
                    'received_by'       => $receivedBy,
                    'released_by'       => $userId,
                    'raw_folder'        => $rawFolder !== '' ? $rawFolder : null,
                    'edited_folder'     => $editedFolder !== '' ? $editedFolder : null,
                    'local_backup_done' => $localDone,
                    'local_backup_at'   => $localAt,
                    'cloud_copy_done'   => $cloudDone,
                    'cloud_copy_at'     => $cloudAt,
                    'updated_at'        => $now,
                ];

                DB::update('projects', $updates, 'id = ?', [$projectId]);

                if ($oldStage !== 'released') {
                    DB::insert('project_history', [
                        'project_id' => $projectId,
                        'old_stage'  => $oldStage,
                        'new_stage'  => 'released',
                        'changed_by' => $userId,
                        'changed_at' => $now,
                    ]);
                }

                // Balance check for auto-completing booking
                if ($balance <= 0.009) {
                    if ($booking['status'] !== 'completed') {
                        DB::update('bookings', [
                            'status'       => 'completed',
                            'completed_at' => $now,
                        ], 'id = ?', [$bookingId]);

                        DB::insert('booking_changes', [
                            'booking_id'  => $bookingId,
                            'change_type' => 'status',
                            'old_value'   => $booking['status'],
                            'new_value'   => 'completed',
                            'reason'      => 'Output released and fully paid',
                            'changed_by'  => $userId,
                            'changed_at'  => $now,
                        ]);

                        Audit::log('booking_completed', 'booking', $bookingId, [
                            'project_id'  => $projectId,
                            'released_at' => $releasedAt,
                        ]);

                        MessageService::postSystem($bookingId, 'Output released and booking marked completed. Thank you for choosing Douffer Photography!');
                    }
                } else {
                    MessageService::postSystem($bookingId, "Output has been released via {$releasedHow}. Remaining balance: " . peso($balance) . ".");
                }

                Audit::log('project_released', 'project', $projectId, [
                    'released_how'      => $releasedHow,
                    'received_by'       => $receivedBy,
                    'balance_remaining' => $balance,
                ]);
            } else {
                $updates = [
                    'raw_folder'        => $rawFolder !== '' ? $rawFolder : null,
                    'edited_folder'     => $editedFolder !== '' ? $editedFolder : null,
                    'local_backup_done' => $localDone,
                    'local_backup_at'   => $localAt,
                    'cloud_copy_done'   => $cloudDone,
                    'cloud_copy_at'     => $cloudAt,
                    'updated_at'        => $now,
                ];
                DB::update('projects', $updates, 'id = ?', [$projectId]);

                Audit::log('project_files_updated', 'project', $projectId, [
                    'local_backup' => $localDone,
                    'cloud_copy'   => $cloudDone,
                ]);
            }
        });
    }

    /**
     * Retrieve stage transition timeline for a project.
     */
    public static function getHistory(int $projectId): array
    {
        return DB::all(
            "SELECT ph.*, u.name AS user_name
             FROM project_history ph
             LEFT JOIN users u ON u.id = ph.changed_by
             WHERE ph.project_id = ?
             ORDER BY ph.changed_at ASC, ph.id ASC",
            [$projectId]
        );
    }

    /**
     * Calculate derived fields (overdue status, balance, days left/overdue).
     */
    private static function enrichProject(array $p): array
    {
        $today = Clock::today();
        $isOverdue = ($p['due_date'] < $today && $p['stage'] !== 'released');

        $todayDt = new \DateTime($today);
        $dueDt = new \DateTime($p['due_date']);

        $daysOverdue = 0;
        $daysLeft = 0;

        if ($todayDt > $dueDt) {
            $daysOverdue = (int) $todayDt->diff($dueDt)->days;
        } else {
            $daysLeft = (int) $dueDt->diff($todayDt)->days;
        }

        $paidAmount = Booking::paidTotal((int) $p['booking_id']);
        $totalAmount = (float) $p['total_amount'];
        $balance = max(0.0, round($totalAmount - $paidAmount, 2));

        $p['is_overdue'] = $isOverdue;
        $p['days_overdue'] = $daysOverdue;
        $p['days_left'] = $daysLeft;
        $p['paid_amount'] = $paidAmount;
        $p['balance_amount'] = $balance;
        $p['has_unpaid_balance'] = ($balance > 0.009);

        return $p;
    }
}
