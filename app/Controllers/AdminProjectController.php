<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Services\ProjectService;
use App\Services\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Controller for Screen A10 (Project Tracker) & A11 (Files & Handover Log).
 */
final class AdminProjectController
{
    /**
     * Screen A10: Project Tracker.
     * Filterable by stage (Booked, Covered, Editing, Ready, Released) or overdue status.
     */
    public function index(): void
    {
        $rawStage = (string) ($_GET['stage'] ?? 'all');
        $allowed = array_merge(['all'], ProjectService::STAGES);
        $stage = in_array($rawStage, $allowed, true) ? $rawStage : 'all';
        $overdue = !empty($_GET['overdue']);
        $q = trim((string) ($_GET['q'] ?? ''));

        $projects = ProjectService::getAll([
            'stage'   => $stage,
            'overdue' => $overdue,
            'q'       => $q,
        ]);
        $stageCounts = ProjectService::getStageCounts();

        view('admin/projects', [
            'title'         => 'Project Tracker',
            'screen'        => 'A10',
            'projects'      => $projects,
            'stageCounts'   => $stageCounts,
            'filter'        => $stage,
            'isOverdueOnly' => $overdue,
            'q'             => $q,
        ], 'admin');
    }

    /**
     * Update project stage (e.g. booked -> covered -> editing -> ready).
     */
    public function updateStage(string $id): void
    {
        $projectId = (int) $id;
        $newStage = trim((string) ($_POST['stage'] ?? ''));

        try {
            ProjectService::updateStage($projectId, $newStage, Auth::id() ?? 1);
            Flash::set('success', 'Stage updated to ' . ucfirst($newStage) . ' ✓');
        } catch (InvalidArgumentException | RuntimeException $e) {
            Flash::set('error', $e->getMessage());
        }

        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref && str_contains($ref, '/admin/')) {
            redirect($ref);
        }
        redirect('/admin/projects');
    }

    /**
     * Update editing checklist items (editing_done, rendering_done, album_done).
     */
    public function updateChecklist(string $id): void
    {
        $projectId = (int) $id;
        $editing = !empty($_POST['editing_done']);
        $rendering = !empty($_POST['rendering_done']);
        $album = !empty($_POST['album_done']);

        try {
            ProjectService::updateChecklist($projectId, $editing, $rendering, $album, Auth::id() ?? 1);
            Flash::set('success', 'Editing checklist saved ✓');
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref && str_contains($ref, '/admin/')) {
            redirect($ref);
        }
        redirect('/admin/projects');
    }

    /**
     * Screen A11: Files & Handover Log.
     */
    public function showFiles(string $id): void
    {
        $projectId = (int) $id;
        $project = ProjectService::getById($projectId);
        if (!$project) {
            abort(404, 'Project not found.');
        }

        $warnings = ProjectService::checkReleaseWarnings($projectId);
        $history = ProjectService::getHistory($projectId);

        view('admin/project_files', [
            'title'    => 'Files & Handover',
            'screen'   => 'A11',
            'back'     => '/admin/projects',
            'project'  => $project,
            'warnings' => $warnings,
            'history'  => $history,
        ], 'admin');
    }

    /**
     * Save storage paths, backup checkboxes, and optionally mark project as released.
     */
    public function saveFiles(string $id): void
    {
        $projectId = (int) $id;
        $data = [
            'raw_folder'               => $_POST['raw_folder'] ?? null,
            'edited_folder'            => $_POST['edited_folder'] ?? null,
            'local_backup_done'        => !empty($_POST['local_backup_done']),
            'cloud_copy_done'          => !empty($_POST['cloud_copy_done']),
            'released_how'             => $_POST['released_how'] ?? null,
            'released_note'            => $_POST['released_note'] ?? null,
            'received_by'              => $_POST['received_by'] ?? null,
            'released_at'              => $_POST['released_at'] ?? null,
            'mark_released'            => !empty($_POST['mark_released']),
            'confirm_release_override' => !empty($_POST['confirm_release_override']),
        ];

        try {
            ProjectService::saveHandover($projectId, $data, Auth::id() ?? 1);
            $msg = !empty($data['mark_released']) ? 'Deliverables released ✓' : 'File & backup details saved ✓';
            Flash::set('success', $msg);
        } catch (ValidationException $e) {
            Flash::set('error', $e->getMessage());
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        redirect('/admin/projects/' . $projectId . '/files');
    }
}
