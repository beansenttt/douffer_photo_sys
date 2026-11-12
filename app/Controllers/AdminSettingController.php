<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Settings;
use App\Core\Validator;
use App\Services\BackupService;
use App\Services\UploadException;
use App\Services\UploadService;
use Throwable;

/**
 * Screen A20: Settings, Blocked Dates & Backups (/admin/settings, Owner only).
 * Manages studio profile, GCash QR & details, booking rules, versioned terms,
 * blocked dates, quick replies, message templates, and database backups.
 */
final class AdminSettingController
{
    /**
     * GET /admin/settings
     */
    public function index(): void
    {
        $tab = trim((string) ($_GET['tab'] ?? 'general'));
        if (!in_array($tab, ['general', 'blocked', 'terms', 'templates', 'backups'], true)) {
            $tab = 'general';
        }

        Settings::flush();
        $settings = Settings::all();

        $blockedDates = DB::all(
            "SELECT bd.*, u.name AS created_by_name
             FROM blocked_dates bd
             LEFT JOIN users u ON u.id = bd.created_by
             ORDER BY bd.date ASC"
        );

        $termsVersions = DB::all(
            "SELECT * FROM terms_versions ORDER BY id DESC"
        );

        $quickReplies = DB::all(
            "SELECT * FROM quick_replies ORDER BY id ASC"
        );

        $templates = DB::all(
            "SELECT * FROM message_templates ORDER BY sort_order ASC, id ASC"
        );

        $backups = BackupService::listAll();

        view('admin/settings', [
            'title'         => 'Settings & Blocked Dates',
            'screen'        => 'A20',
            'back'          => '/admin/more',
            'tab'           => $tab,
            'settings'      => $settings,
            'blockedDates'  => $blockedDates,
            'termsVersions' => $termsVersions,
            'quickReplies'  => $quickReplies,
            'templates'     => $templates,
            'backups'       => $backups,
        ], 'admin');
    }

    /**
     * POST /admin/settings — Save studio info, GCash details/QR, and booking rules.
     */
    public function save(): void
    {
        $errors = Validator::make($_POST, [
            'studio_name'           => 'required|max:120',
            'studio_address'        => 'required|max:255',
            'studio_phone'          => 'required|max:40',
            'studio_messenger_url'  => 'max:255',
            'gcash_name'            => 'required|max:120',
            'gcash_number'          => 'required|max:40',
            'down_payment_mode'     => 'required|in:percent,fixed',
            'down_payment_value'    => 'required|money|min_value:1',
            'max_events_per_day'    => 'required|integer|min_value:1',
            'default_delivery_days' => 'required|integer|min_value:1',
            'balance_overdue_days'  => 'required|integer|min_value:0',
            'event_types'           => 'required|max:1000',
        ]);

        $dpMode = (string) ($_POST['down_payment_mode'] ?? 'percent');
        $dpVal = (float) str_replace(',', '', (string) ($_POST['down_payment_value'] ?? '30'));
        if ($dpMode === 'percent' && ($dpVal < 1 || $dpVal > 100)) {
            $errors['down_payment_value'] = 'Percentage down payment must be between 1 and 100.';
        }

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please fix the highlighted settings fields.');
            redirect('/admin/settings?tab=general');
        }

        // Optional GCash QR upload
        $qrPath = Settings::get('gcash_qr_path', '');
        if (!empty($_FILES['gcash_qr']) && ($_FILES['gcash_qr']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                [$newQrPath, $_thumb] = UploadService::saveImage($_FILES['gcash_qr'], 'uploads');
                if ($qrPath !== '') {
                    UploadService::delete($qrPath);
                }
                $qrPath = $newQrPath;
            } catch (UploadException $e) {
                Flash::withInput($_POST, ['gcash_qr' => $e->getMessage()]);
                Flash::set('error', $e->getMessage());
                redirect('/admin/settings?tab=general');
            }
        }

        $keys = [
            'studio_name'           => trim((string) $_POST['studio_name']),
            'studio_address'        => trim((string) $_POST['studio_address']),
            'studio_phone'          => trim((string) $_POST['studio_phone']),
            'studio_messenger_url'  => trim((string) ($_POST['studio_messenger_url'] ?? '')),
            'gcash_name'            => trim((string) $_POST['gcash_name']),
            'gcash_number'          => trim((string) $_POST['gcash_number']),
            'gcash_qr_path'         => (string) $qrPath,
            'down_payment_mode'     => $dpMode,
            'down_payment_value'    => (string) $dpVal,
            'max_events_per_day'    => (string) (int) $_POST['max_events_per_day'],
            'default_delivery_days' => (string) (int) $_POST['default_delivery_days'],
            'balance_overdue_days'  => (string) (int) $_POST['balance_overdue_days'],
            'event_types'           => trim((string) $_POST['event_types']),
        ];

        foreach ($keys as $k => $v) {
            Settings::set($k, $v);
        }

        Audit::log('settings_updated', 'setting', null, [
            'down_payment_mode'     => $dpMode,
            'down_payment_value'    => $keys['down_payment_value'],
            'max_events_per_day'    => $keys['max_events_per_day'],
            'default_delivery_days' => $keys['default_delivery_days'],
            'balance_overdue_days'  => $keys['balance_overdue_days'],
        ]);

        Flash::set('success', 'Studio settings saved ✓');
        redirect('/admin/settings?tab=general');
    }

    /**
     * POST /admin/settings/blocked-dates — Block a date on the calendar.
     */
    public function addBlockedDate(): void
    {
        $errors = Validator::make($_POST, [
            'date'   => 'required|date',
            'reason' => 'required|max:255',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please enter a valid date and reason.');
            redirect('/admin/settings?tab=blocked');
        }

        $date = trim((string) $_POST['date']);
        $reason = trim((string) $_POST['reason']);

        $existing = DB::one('SELECT * FROM blocked_dates WHERE date = ?', [$date]);
        if ($existing) {
            $id = (int) $existing['id'];
            DB::update('blocked_dates', [
                'reason'     => $reason,
                'created_by' => Auth::id(),
            ], 'id = ?', [$id]);
        } else {
            $id = DB::insert('blocked_dates', [
                'date'       => $date,
                'reason'     => $reason,
                'created_by' => Auth::id(),
                'created_at' => Clock::now(),
            ]);
        }

        Audit::log('date_blocked', 'blocked_date', $id, [
            'date'   => $date,
            'reason' => $reason,
        ]);

        Flash::set('success', 'Date ' . date_short($date) . ' blocked ✓');
        redirect('/admin/settings?tab=blocked');
    }

    /**
     * POST /admin/settings/blocked-dates/{id}/delete — Unblock a date.
     */
    public function removeBlockedDate(string $id): void
    {
        $blockId = (int) $id;
        $row = DB::one('SELECT * FROM blocked_dates WHERE id = ?', [$blockId]);
        if (!$row) {
            throw new HttpException(404, 'Blocked date not found.');
        }

        DB::run('DELETE FROM blocked_dates WHERE id = ?', [$blockId]);

        Audit::log('date_unblocked', 'blocked_date', $blockId, [
            'date'   => $row['date'],
            'reason' => $row['reason'],
        ]);

        Flash::set('success', 'Date ' . date_short($row['date']) . ' unblocked ✓');
        redirect('/admin/settings?tab=blocked');
    }

    /**
     * POST /admin/settings/terms — Publish a new terms version (or update active terms).
     */
    public function saveTerms(): void
    {
        $errors = Validator::make($_POST, [
            'version'        => 'required|max:10',
            'effective_from' => 'required|date',
            'body'           => 'required|max:5000',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please fill in the terms version, effective date, and text.');
            redirect('/admin/settings?tab=terms');
        }

        $version = trim((string) $_POST['version']);
        $effectiveFrom = trim((string) $_POST['effective_from']);
        $body = trim((string) $_POST['body']);

        $existing = DB::one('SELECT * FROM terms_versions WHERE version = ?', [$version]);
        if ($existing) {
            $termId = (int) $existing['id'];
            DB::update('terms_versions', [
                'body'           => $body,
                'effective_from' => $effectiveFrom,
            ], 'id = ?', [$termId]);
        } else {
            $termId = DB::insert('terms_versions', [
                'version'        => $version,
                'body'           => $body,
                'effective_from' => $effectiveFrom,
                'created_at'     => Clock::now(),
            ]);
        }

        Settings::set('current_terms_version', $version);

        Audit::log('settings_updated', 'terms_version', $termId, [
            'version'        => $version,
            'effective_from' => $effectiveFrom,
        ]);

        Flash::set('success', 'Terms & conditions (v' . $version . ') saved ✓');
        redirect('/admin/settings?tab=terms');
    }

    /**
     * POST /admin/settings/quick-replies — Add or update a chat quick reply.
     */
    public function saveQuickReply(): void
    {
        $errors = Validator::make($_POST, [
            'title' => 'required|max:60',
            'body'  => 'required|max:1000',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please provide both a short title and message text for the quick reply.');
            redirect('/admin/settings?tab=templates');
        }

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $title = trim((string) $_POST['title']);
        $body = trim((string) $_POST['body']);
        $isActive = isset($_POST['is_active']) ? ((int) $_POST['is_active'] === 1 ? 1 : 0) : 1;

        if ($id > 0 && DB::one('SELECT id FROM quick_replies WHERE id = ?', [$id])) {
            DB::update('quick_replies', [
                'title'     => $title,
                'body'      => $body,
                'is_active' => $isActive,
            ], 'id = ?', [$id]);
        } else {
            $id = DB::insert('quick_replies', [
                'title'     => $title,
                'body'      => $body,
                'is_active' => $isActive,
            ]);
        }

        Audit::log('settings_updated', 'quick_reply', $id, [
            'title'     => $title,
            'is_active' => $isActive,
        ]);

        Flash::set('success', 'Quick reply saved ✓');
        redirect('/admin/settings?tab=templates');
    }

    /**
     * POST /admin/settings/quick-replies/{id}/delete — Delete a quick reply.
     */
    public function deleteQuickReply(string $id): void
    {
        $qrId = (int) $id;
        $row = DB::one('SELECT * FROM quick_replies WHERE id = ?', [$qrId]);
        if (!$row) {
            throw new HttpException(404, 'Quick reply not found.');
        }

        DB::run('DELETE FROM quick_replies WHERE id = ?', [$qrId]);

        Audit::log('settings_updated', 'quick_reply', $qrId, [
            'action' => 'deleted',
            'title'  => $row['title'],
        ]);

        Flash::set('success', 'Quick reply removed ✓');
        redirect('/admin/settings?tab=templates');
    }

    /**
     * POST /admin/settings/templates/{id} — Update a bilingual message template (A12).
     */
    public function saveTemplate(string $id): void
    {
        $tplId = (int) $id;
        $tpl = DB::one('SELECT * FROM message_templates WHERE id = ?', [$tplId]);
        if (!$tpl) {
            throw new HttpException(404, 'Message template not found.');
        }

        $errors = Validator::make($_POST, [
            'title'   => 'required|max:100',
            'body_tl' => 'required|max:3000',
            'body_en' => 'required|max:3000',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please fill in the template title and both Filipino and English messages.');
            redirect('/admin/settings?tab=templates');
        }

        $title = trim((string) $_POST['title']);
        $bodyTl = trim((string) $_POST['body_tl']);
        $bodyEn = trim((string) $_POST['body_en']);
        $isActive = isset($_POST['is_active']) ? ((int) $_POST['is_active'] === 1 ? 1 : 0) : 1;

        DB::update('message_templates', [
            'title'     => $title,
            'body_tl'   => $bodyTl,
            'body_en'   => $bodyEn,
            'is_active' => $isActive,
        ], 'id = ?', [$tplId]);

        Audit::log('settings_updated', 'message_template', $tplId, [
            'template_key' => $tpl['template_key'],
            'title'        => $title,
            'is_active'    => $isActive,
        ]);

        Flash::set('success', 'Message template "' . $title . '" updated ✓');
        redirect('/admin/settings?tab=templates');
    }

    /**
     * POST /admin/backups — Trigger a manual SQL backup now (§9.6).
     */
    public function createBackup(): void
    {
        try {
            $run = BackupService::create('manual', Auth::id());
            Flash::set('success', 'Backup created (' . $run['file_name'] . ') ✓');
        } catch (Throwable $e) {
            Flash::set('error', 'Backup failed: ' . $e->getMessage());
        }
        redirect('/admin/settings?tab=backups');
    }

    /**
     * GET /admin/backups/{id}/download — Download a .sql dump file.
     */
    public function downloadBackup(string $id): void
    {
        $backupId = (int) $id;
        $run = DB::one('SELECT * FROM backup_runs WHERE id = ?', [$backupId]);
        if (!$run) {
            throw new HttpException(404, 'Backup record not found.');
        }

        $path = BackupService::ensureFileForDownload($run);
        if ($path === null || !is_file($path)) {
            throw new HttpException(404, 'Backup file not found on disk.');
        }

        if (!headers_sent()) {
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . basename((string) $run['file_name']) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }
        readfile($path);
        exit;
    }

    /**
     * POST /admin/backups/{id}/cloud-copy — Owner confirms a cloud copy was made (§9.6).
     */
    public function markCloudCopy(string $id): void
    {
        $backupId = (int) $id;
        try {
            BackupService::markCloudCopy($backupId, Auth::id());
            Flash::set('success', 'Cloud copy marked as done ✓');
        } catch (Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        redirect('/admin/settings?tab=backups');
    }
}
