<?php
/**
 * Douffer PhotoSys: route map.
 * Screen IDs from the paper prototype are noted next to each route.
 * Access: 'auth' => admin|owner|crew|any, 'perm' => staff permission flag(s).
 */
declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\AdminBookingController;
use App\Controllers\AdminCalendarController;
use App\Controllers\AdminClientController;
use App\Controllers\AdminCrewController;
use App\Controllers\AdminDashboardController;
use App\Controllers\AdminDocumentController;
use App\Controllers\AdminLogController;
use App\Controllers\AdminMessageController;
use App\Controllers\AdminMessageTemplateController;
use App\Controllers\AdminMoreController;
use App\Controllers\AdminPackageController;
use App\Controllers\AdminPaymentController;
use App\Controllers\AdminProjectController;
use App\Controllers\AdminReportController;
use App\Controllers\AdminSettingController;
use App\Controllers\AdminUserController;
use App\Controllers\ApiAvailabilityController;
use App\Controllers\ApiMessageController;
use App\Controllers\AuthController;
use App\Controllers\ClientAvailabilityController;
use App\Controllers\ClientBookController;
use App\Controllers\ClientChatController;
use App\Controllers\ClientHomeController;
use App\Controllers\ClientStatusController;
use App\Controllers\CrewController;
use App\Controllers\FileController;
use App\Controllers\PageController;
use App\Core\Router;

return static function (Router $r): void {
    // ---------------------------------------------------------- client portal (public, teal)
    $r->get('/', [ClientHomeController::class, 'home']);                                   // C1
    $r->get('/availability', [ClientAvailabilityController::class, 'index']);             // C2
    $r->get('/book', [ClientBookController::class, 'details']);                           // C3
    $r->post('/book', [ClientBookController::class, 'saveDetails']);
    $r->get('/book/pay', [ClientBookController::class, 'pay']);                           // C4
    $r->post('/book/pay', [ClientBookController::class, 'submitPay']);
    $r->get('/booking', [ClientStatusController::class, 'lookupForm']);                   // C5 lookup (My Booking tab)
    $r->post('/booking', [ClientStatusController::class, 'lookup']);
    $r->get('/booking/{ref}', [ClientStatusController::class, 'show']);                   // C5 status
    $r->post('/booking/{ref}/reupload', [ClientStatusController::class, 'reupload']);
    $r->get('/booking/{ref}/confirmation', [ClientStatusController::class, 'confirmation']); // A8 (client view)
    $r->get('/booking/{ref}/chat', [ClientChatController::class, 'bookingChat']);         // C6 (booking chat)

    // Private uploads: access is checked inside FileController
    $r->get('/files/receipt/{id}', [FileController::class, 'receipt']);
    $r->get('/files/receipt/{id}/thumb', [FileController::class, 'receiptThumb']);
    $r->get('/files/chat/{id}', [FileController::class, 'chatAttachment']);
    $r->get('/files/gcash-qr', [FileController::class, 'gcashQr']);

    // Client chat (C6)
    $r->get('/chat', [ClientChatController::class, 'index']);                             // C6
    $r->post('/chat/start', [ClientChatController::class, 'startInquiry']);               // C6 pre-booking inquiry
    $r->get('/chat/t/{token}', [ClientChatController::class, 'showByToken']);             // C6 token link
    $r->post('/chat/{id}/messages', [ClientChatController::class, 'sendMessage']);        // C6 form send

    $r->get('/privacy', [PageController::class, 'privacy']);                               // RA 10173 notice

    // ---------------------------------------------------------- json api
    $r->get('/api/availability/check', [ApiAvailabilityController::class, 'check'], ['api' => true]);
    $r->get('/api/unread', [ApiMessageController::class, 'unread'],
        ['api' => true, 'auth' => 'admin', 'perm' => 'manage_messages']);
    $r->get('/api/conversations/{id}/messages', [ApiMessageController::class, 'poll'], ['api' => true]);
    $r->post('/api/conversations/{id}/messages', [ApiMessageController::class, 'send'], ['api' => true]);

    // ---------------------------------------------------------- authentication
    $r->get('/admin/login', [AuthController::class, 'showLogin']);                          // A1
    $r->post('/admin/login', [AuthController::class, 'login']);
    $r->post('/admin/logout', [AuthController::class, 'logout'], ['auth' => 'any']);
    $r->get('/account/password', [AccountController::class, 'showPassword'], ['auth' => 'any']);
    $r->post('/account/password', [AccountController::class, 'updatePassword'], ['auth' => 'any']);

    // ---------------------------------------------------------- admin (owner + staff, blue)
    $r->get('/admin', [AdminDashboardController::class, 'index'], ['auth' => 'admin']);      // A2
    $r->get('/admin/more', [AdminMoreController::class, 'index'], ['auth' => 'admin']);      // More menu

    $vb = ['auth' => 'admin', 'perm' => 'view_bookings'];
    $mm = ['auth' => 'admin', 'perm' => 'manage_messages'];
    $ow = ['auth' => 'owner'];

    $r->get('/admin/calendar', [AdminCalendarController::class, 'index'], $vb);               // A3
    $r->get('/admin/bookings', [AdminBookingController::class, 'index'], $vb);               // A4
    $r->get('/admin/bookings/new', [AdminBookingController::class, 'newBookingForm'], $vb);  // A5
    $r->post('/admin/bookings/new', [AdminBookingController::class, 'createNewBooking'], $vb);
    $r->get('/admin/bookings/{id}', [AdminBookingController::class, 'show'], $vb);           // A6
    $r->post('/admin/bookings/{id}/notes', [AdminBookingController::class, 'addNote'], $vb); // A6 notes
    $r->post('/admin/bookings/{id}/archive', [AdminBookingController::class, 'toggleArchive'],
        ['auth' => 'admin', 'perm' => 'archive_records']);
    $r->post('/admin/bookings/{id}/payments', [AdminPaymentController::class, 'record'],
        ['auth' => 'admin', 'perm' => 'record_payments']);
    $r->get('/admin/bookings/{id}/confirmation', [AdminDocumentController::class, 'show'], $vb); // A8
    $r->get('/admin/bookings/{id}/messages/templates', [AdminMessageTemplateController::class, 'index'], $vb); // A12
    $r->post('/admin/bookings/{id}/messages/templates/send', [AdminMessageTemplateController::class, 'send'], $vb);
    $r->get('/admin/bookings/{id}/chat', [AdminMessageController::class, 'openForBooking'], $mm); // A6 -> A19
    $r->get('/admin/bookings/{id}/change', [AdminBookingController::class, 'showChangeForm'], $vb); // A13
    $r->post('/admin/bookings/{id}/change', [AdminBookingController::class, 'applyChange'], $vb);

    $r->get('/admin/payments', [AdminPaymentController::class, 'index'],
        ['auth' => 'admin', 'perm' => 'verify_payments']);                                   // A7
    $r->post('/admin/payments/{id}/verify', [AdminPaymentController::class, 'verify'],
        ['auth' => 'admin', 'perm' => 'verify_payments']);
    $r->post('/admin/payments/{id}/reject', [AdminPaymentController::class, 'reject'],
        ['auth' => 'admin', 'perm' => 'verify_payments']);

    $r->get('/admin/projects', [AdminProjectController::class, 'index'], $vb);               // A10
    $r->post('/admin/projects/{id}/stage', [AdminProjectController::class, 'updateStage'], $vb);
    $r->post('/admin/projects/{id}/checklist', [AdminProjectController::class, 'updateChecklist'], $vb);
    $r->get('/admin/projects/{id}/files', [AdminProjectController::class, 'showFiles'], $vb); // A11
    $r->post('/admin/projects/{id}/files', [AdminProjectController::class, 'saveFiles'], $vb);
    $r->get('/admin/crew', [AdminCrewController::class, 'index'], $vb);                       // A9
    $r->post('/admin/crew', [AdminCrewController::class, 'handlePost'], $vb);
    $r->post('/admin/crew/assign', [AdminCrewController::class, 'assign'], $vb);
    $r->post('/admin/crew/unassign', [AdminCrewController::class, 'unassign'], $vb);
    $r->post('/admin/crew/equipment', [AdminCrewController::class, 'saveEquipment'], $vb);

    // Admin Messages Inbox & Thread (A19)
    $r->get('/admin/messages', [AdminMessageController::class, 'index'], $mm);               // A19
    $r->get('/admin/messages/{id}', [AdminMessageController::class, 'show'], $mm);           // A19 thread
    $r->post('/admin/messages/{id}/messages', [AdminMessageController::class, 'sendMessage'], $mm);
    $r->post('/admin/messages/{id}/status', [AdminMessageController::class, 'updateStatus'], $mm);
    $r->post('/admin/messages/{id}/link', [AdminMessageController::class, 'link'], $mm);
    $r->get('/admin/messages/{id}/export', [AdminMessageController::class, 'export'], $mm);

    // Clients & Inquiries (A14)
    $r->get('/admin/clients', [AdminClientController::class, 'index'], $vb);                 // A14
    $r->post('/admin/clients', [AdminClientController::class, 'store'], $vb);
    $r->get('/admin/clients/{id}', [AdminClientController::class, 'show'], $vb);             // A14 detail
    $r->post('/admin/clients/{id}', [AdminClientController::class, 'update'], $vb);
    $r->post('/admin/clients/{id}/inquiries', [AdminClientController::class, 'addInquiry'], $vb);
    $r->post('/admin/clients/{id}/archive', [AdminClientController::class, 'toggleArchive'],
        ['auth' => 'admin', 'perm' => 'archive_records']);

    // Packages & Services (A15 - Owner only)
    $r->get('/admin/packages', [AdminPackageController::class, 'index'], $ow);               // A15
    $r->post('/admin/packages', [AdminPackageController::class, 'store'], $ow);
    $r->post('/admin/packages/addons', [AdminPackageController::class, 'storeAddon'], $ow);
    $r->post('/admin/packages/addons/{id}', [AdminPackageController::class, 'updateAddon'], $ow);
    $r->post('/admin/packages/addons/{id}/toggle', [AdminPackageController::class, 'toggleAddon'], $ow);
    $r->post('/admin/packages/{id}', [AdminPackageController::class, 'update'], $ow);
    $r->post('/admin/packages/{id}/toggle', [AdminPackageController::class, 'toggle'], $ow);

    // Reports (A16)
    $r->get('/admin/reports', [AdminReportController::class, 'index'], $vb);                 // A16
    $r->get('/admin/reports/export.csv', [AdminReportController::class, 'exportCsv'], $vb);
    $r->get('/admin/reports/export', [AdminReportController::class, 'exportCsv'], $vb);

    // Users & Access (A17 - Owner only)
    $r->get('/admin/users', [AdminUserController::class, 'index'], $ow);                     // A17
    $r->post('/admin/users', [AdminUserController::class, 'store'], $ow);
    $r->post('/admin/users/{id}', [AdminUserController::class, 'update'], $ow);
    $r->post('/admin/users/{id}/permissions', [AdminUserController::class, 'updatePermissions'], $ow);
    $r->post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword'], $ow);
    $r->post('/admin/users/{id}/toggle', [AdminUserController::class, 'toggle'], $ow);

    // Settings, Blocked Dates & Backups (A20 - Owner only)
    $r->get('/admin/settings', [AdminSettingController::class, 'index'], $ow);               // A20
    $r->post('/admin/settings', [AdminSettingController::class, 'save'], $ow);
    $r->post('/admin/settings/blocked-dates', [AdminSettingController::class, 'addBlockedDate'], $ow);
    $r->post('/admin/settings/blocked-dates/{id}/delete', [AdminSettingController::class, 'removeBlockedDate'], $ow);
    $r->post('/admin/settings/terms', [AdminSettingController::class, 'saveTerms'], $ow);
    $r->post('/admin/settings/quick-replies', [AdminSettingController::class, 'saveQuickReply'], $ow);
    $r->post('/admin/settings/quick-replies/{id}/delete', [AdminSettingController::class, 'deleteQuickReply'], $ow);
    $r->post('/admin/settings/templates/{id}', [AdminSettingController::class, 'saveTemplate'], $ow);
    $r->post('/admin/backups', [AdminSettingController::class, 'createBackup'], $ow);
    $r->get('/admin/backups/{id}/download', [AdminSettingController::class, 'downloadBackup'], $ow);
    $r->post('/admin/backups/{id}/cloud-copy', [AdminSettingController::class, 'markCloudCopy'], $ow);

    // Audit & System Logs (A21 - Owner only)
    $r->get('/admin/logs', [AdminLogController::class, 'index'], $ow);                       // A21
    $r->get('/admin/logs/export.csv', [AdminLogController::class, 'exportCsv'], $ow);
    $r->get('/admin/logs/export', [AdminLogController::class, 'exportCsv'], $ow);

    // ---------------------------------------------------------- crew (view-only)
    $r->get('/crew', [CrewController::class, 'index'], ['auth' => 'crew']);                  // A18
};
