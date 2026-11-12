<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Services\DashboardService;

/**
 * Screen A2: Admin Dashboard Controller.
 * Handles rendering of live summary tiles, "Needs attention" studio alert rules,
 * and persistent backup status line.
 */
final class AdminDashboardController
{
    public function index(): void
    {
        $user = Auth::user();
        $today = Clock::today();

        $metrics = DashboardService::getMetrics();
        $alerts = DashboardService::getAlerts();
        $backupStatus = DashboardService::getBackupStatus();

        view('admin/dashboard', [
            'title'        => 'Dashboard',
            'screen'       => 'A2',
            'user'         => $user,
            'today'        => $today,
            'mustChange'   => (int) ($user['must_change_password'] ?? 0) === 1,
            'metrics'      => $metrics,
            'alerts'       => $alerts,
            'backupStatus' => $backupStatus,
        ], 'admin');
    }
}
