<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;

/** The admin "More" menu (5th bottom-nav item). Items are filtered by role/permission. */
final class AdminMoreController
{
    public function index(): void
    {
        $items = [
            ['/admin/payments', 'Payments to verify', 'wallet', Auth::can('verify_payments')],
            ['/admin/messages', 'Messages', 'chat', Auth::can('manage_messages')],
            ['/admin/crew', 'Crew and equipment', 'camera', Auth::can('view_bookings')],
            ['/admin/clients', 'Clients and inquiries', 'users', Auth::can('view_bookings')],
            ['/admin/packages', 'Packages and services', 'box', Auth::isOwner()],
            ['/admin/reports', 'Reports', 'chart', Auth::can('view_bookings')],
            ['/admin/users', 'Users and access', 'shield', Auth::isOwner()],
            ['/admin/settings', 'Settings and blocked dates', 'gear', Auth::isOwner()],
            ['/admin/logs', 'Audit and system logs', 'list', Auth::isOwner()],
            ['/account/password', 'Change my password', 'key', true],
        ];
        view('admin/more', [
            'title'  => 'More',
            'screen' => 'MORE',
            'items'  => array_values(array_filter($items, static fn ($i) => $i[3])),
        ], 'admin');
    }
}
