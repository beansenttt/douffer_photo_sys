<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Services\CrewService;

/**
 * Screen A18: Crew - My Events (Scenario T7).
 * Read-only view of the logged-in crew member's assigned events, venue, call time,
 * inclusions, equipment checklist, and crew-visible notes. No payment or client chat data.
 */
final class CrewController
{
    public function index(): void
    {
        $user = Auth::user();
        $schedule = CrewService::getEventsForUser((int) $user['id']);

        view('crew/index', [
            'title'        => 'My Events',
            'screen'       => 'A18',
            'user'         => $user,
            'mustChange'   => (int) $user['must_change_password'] === 1,
            'crewMember'   => $schedule['crew_member'],
            'nextEvent'    => $schedule['next_event'],
            'laterEvents'  => $schedule['later_events'],
            'pastEvents'   => $schedule['past_events'],
        ], 'crew');
    }
}
