<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Validator;
use App\Services\CalendarService;

/**
 * Screen A3: Admin Booking Calendar Controller.
 * Handles month grid navigation and day schedule inspection.
 */
final class AdminCalendarController
{
    public function index(): void
    {
        $today = Clock::today();
        $todayDt = Clock::dateTime();

        $year = (int) $todayDt->format('Y');
        $month = (int) $todayDt->format('n');

        if (isset($_GET['month']) && is_string($_GET['month'])) {
            if (preg_match('/^(\d{4})-(\d{2})$/', $_GET['month'], $m)) {
                $year = (int) $m[1];
                $month = (int) $m[2];
            } elseif (ctype_digit($_GET['month'])) {
                $month = (int) $_GET['month'];
            }
        }

        if (isset($_GET['year']) && (is_int($_GET['year']) || (is_string($_GET['year']) && ctype_digit($_GET['year'])))) {
            $year = (int) $_GET['year'];
        }

        // Clamp month and year
        $month = max(1, min(12, $month));
        if ($year < 2000 || $year > 2100) {
            $year = (int) $todayDt->format('Y');
        }

        // If date parameter is given and month/year were not explicitly provided, sync month/year with date
        if (isset($_GET['date']) && is_string($_GET['date']) && Validator::isDate($_GET['date'])) {
            $selectedDate = $_GET['date'];
            if (!isset($_GET['month']) && !isset($_GET['year'])) {
                $year = (int) substr($selectedDate, 0, 4);
                $month = (int) substr($selectedDate, 5, 2);
            }
        } else {
            // Default selected date: today if viewing current month, or the 1st of viewed month
            $currentViewMonth = sprintf('%04d-%02d', $year, $month);
            $selectedDate = ($currentViewMonth === substr($today, 0, 7))
                ? $today
                : sprintf('%04d-%02d-01', $year, $month);
        }

        $prevYear = $month === 1 ? $year - 1 : $year;
        $prevMonth = $month === 1 ? 12 : $month - 1;
        $nextYear = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;

        $monthData = CalendarService::getMonthData($year, $month);
        $daySchedule = CalendarService::getDaySchedule($selectedDate);

        view('admin/calendar', [
            'title'        => 'Booking Calendar',
            'screen'       => 'A3',
            'user'         => Auth::user(),
            'today'        => $today,
            'year'         => $year,
            'month'        => $month,
            'selectedDate' => $selectedDate,
            'monthData'    => $monthData,
            'daySchedule'  => $daySchedule,
            'prevYear'     => $prevYear,
            'prevMonth'    => $prevMonth,
            'nextYear'     => $nextYear,
            'nextMonth'    => $nextMonth,
        ], 'admin');
    }
}
