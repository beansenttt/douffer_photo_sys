<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Validator;
use App\Services\AvailabilityService;

/** C2 Check Availability: month calendar rendered on the server (works without JS). */
final class ClientAvailabilityController
{
    public function index(): void
    {
        $today = Clock::today();
        $minMonth = substr($today, 0, 7);
        $maxMonth = date('Y-m', strtotime($today . ' +24 months'));

        $month = (string) ($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = substr((string) ($_GET['date'] ?? ''), 0, 7);
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $month = $minMonth;
            }
        }
        $month = max($minMonth, min($maxMonth, $month));
        [$year, $mon] = array_map('intval', explode('-', $month));

        $selected = (string) ($_GET['date'] ?? '');
        $selected = Validator::isDate($selected) ? $selected : null;

        view('client/availability', [
            'title'     => 'Check Availability',
            'screen'    => 'C2',
            'back'      => '/',
            'navActive' => 'availability',
            'year'      => $year,
            'mon'       => $mon,
            'month'     => $month,
            'prevMonth' => $month > $minMonth ? date('Y-m', strtotime("$month-01 -1 month")) : null,
            'nextMonth' => $month < $maxMonth ? date('Y-m', strtotime("$month-01 +1 month")) : null,
            'map'       => AvailabilityService::monthMap($year, $mon),
            'today'     => $today,
            'selected'  => $selected,
            'reason'    => $selected ? AvailabilityService::reason($selected) : null,
        ], 'client');
    }
}
