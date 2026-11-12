<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\Validator;
use App\Services\CrewOverlapConflictException;
use App\Services\CrewService;
use Throwable;

/**
 * Screen A9: Crew & Equipment Assignment.
 * Manages event crew rosters, on-call assignments, equipment checklists, and overlap conflicts.
 */
final class AdminCrewController
{
    /**
     * GET /admin/crew?date=YYYY-MM-DD
     */
    public function index(): void
    {
        $rawDate = (string) ($_GET['date'] ?? '');
        $date = Validator::isDate($rawDate) ? $rawDate : Clock::today();

        $schedule = CrewService::getScheduleForDate($date);

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $equipmentItems = DB::all('SELECT * FROM equipment_items WHERE is_active = 1 ORDER BY id ASC');

        view('admin/crew', [
            'title'            => 'Crew & Equipment',
            'screen'           => 'A9',
            'back'             => '/admin/calendar',
            'date'             => $date,
            'events'           => $schedule['events'],
            'crew_roster'      => $schedule['crew_roster'],
            'has_shortage'     => $schedule['has_shortage'],
            'shortage_message' => $schedule['shortage_message'],
            'equipment_items'  => $equipmentItems,
            'prev_date'        => $prevDate,
            'next_date'        => $nextDate,
        ], 'admin');
    }

    /**
     * POST /admin/crew/assign
     */
    public function assign(): void
    {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $crewMemberId = (int) ($_POST['crew_member_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'lead');
        $callTime = trim((string) ($_POST['call_time'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? ''));
        $redirectDate = Validator::isDate($date) ? $date : Clock::today();

        if ($bookingId <= 0 || $crewMemberId <= 0) {
            Flash::set('error', 'Please select a valid crew member and event.');
            redirect('/admin/crew?date=' . urlencode($redirectDate));
        }

        try {
            CrewService::assign(
                $bookingId,
                $crewMemberId,
                $role,
                $callTime !== '' ? $callTime : null,
                (int) Auth::id()
            );
            Flash::set('success', 'Crew member assigned successfully ✓');
        } catch (CrewOverlapConflictException $e) {
            Flash::set('error', $e->getMessage());
        } catch (Throwable $e) {
            Flash::set('error', 'Unable to assign crew: ' . $e->getMessage());
        }

        redirect('/admin/crew?date=' . urlencode($redirectDate));
    }

    /**
     * POST /admin/crew/unassign
     */
    public function unassign(): void
    {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $crewMemberId = (int) ($_POST['crew_member_id'] ?? 0);
        $date = trim((string) ($_POST['date'] ?? ''));
        $redirectDate = Validator::isDate($date) ? $date : Clock::today();

        if ($bookingId <= 0 || $crewMemberId <= 0) {
            Flash::set('error', 'Invalid booking or crew member.');
            redirect('/admin/crew?date=' . urlencode($redirectDate));
        }

        try {
            CrewService::unassign($bookingId, $crewMemberId, (int) Auth::id());
            Flash::set('success', 'Crew member unassigned ✓');
        } catch (Throwable $e) {
            Flash::set('error', 'Unable to unassign crew: ' . $e->getMessage());
        }

        redirect('/admin/crew?date=' . urlencode($redirectDate));
    }

    /**
     * POST /admin/crew/equipment
     */
    public function saveEquipment(): void
    {
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $checked = isset($_POST['equipment_ids']) && is_array($_POST['equipment_ids'])
            ? array_map('intval', $_POST['equipment_ids'])
            : [];
        $date = trim((string) ($_POST['date'] ?? ''));
        $redirectDate = Validator::isDate($date) ? $date : Clock::today();

        if ($bookingId <= 0) {
            Flash::set('error', 'Invalid booking ID.');
            redirect('/admin/crew?date=' . urlencode($redirectDate));
        }

        try {
            CrewService::saveEquipment($bookingId, $checked, (int) Auth::id());
            Flash::set('success', 'Equipment checklist saved ✓');
        } catch (Throwable $e) {
            Flash::set('error', 'Unable to save equipment checklist: ' . $e->getMessage());
        }

        redirect('/admin/crew?date=' . urlencode($redirectDate));
    }

    /**
     * Unified handler for POST /admin/crew with action param.
     */
    public function handlePost(): void
    {
        $action = (string) ($_POST['action'] ?? '');
        match ($action) {
            'assign'    => $this->assign(),
            'unassign'  => $this->unassign(),
            'equipment' => $this->saveEquipment(),
            default     => redirect('/admin/crew?date=' . urlencode((string) ($_POST['date'] ?? Clock::today()))),
        };
    }
}
