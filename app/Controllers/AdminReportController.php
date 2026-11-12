<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Screen A16: Reports (/admin/reports & /admin/reports/export.csv).
 * Daily and Monthly booking, revenue, balance, and pending output reports with print & CSV export.
 */
final class AdminReportController
{
    /**
     * GET /admin/reports
     */
    public function index(): void
    {
        $report = $this->buildReportData($_GET);

        view('admin/reports', [
            'title'  => 'Reports',
            'screen' => 'A16',
            'back'   => '/admin/more',
            'report' => $report,
        ], 'admin');
    }

    /**
     * GET /admin/reports/export.csv
     */
    public function exportCsv(): void
    {
        $report = $this->buildReportData($_GET);

        Audit::log('report_exported', 'report', null, [
            'period' => $report['period'],
            'key'    => $report['period_key'],
            'count'  => count($report['bookings']),
        ]);

        $filename = 'douffer_report_' . $report['period'] . '_' . $report['period_key'] . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        // Summary header rows
        fputcsv($out, ['Douffer PhotoSys - Studio Report', $report['label']]);
        fputcsv($out, ['Generated at', Clock::now()]);
        fputcsv($out, [
            'Bookings', $report['tiles']['bookings'],
            'Income received (PHP)', number_format($report['tiles']['income_received'], 2, '.', ''),
            'Outstanding balances (PHP)', number_format($report['tiles']['outstanding_balances'], 2, '.', ''),
            'Pending outputs', $report['tiles']['pending_outputs'],
        ]);
        fputcsv($out, []);

        // Bookings table
        fputcsv($out, [
            'Reference No.',
            'Event Date',
            'Start Time',
            'Client Name',
            'Client Mobile',
            'Event Type',
            'Package',
            'Booking Status',
            'Total Amount (PHP)',
            'Verified Paid (PHP)',
            'Outstanding Balance (PHP)',
            'Project Stage',
            'Project Due Date',
        ]);

        foreach ($report['bookings'] as $b) {
            fputcsv($out, [
                $b['reference_code'],
                $b['event_date'],
                $b['start_time'] ?? '',
                $b['client_name'],
                $b['client_mobile'],
                $b['event_type'],
                $b['package_name'],
                $b['status'],
                number_format((float) $b['total_amount'], 2, '.', ''),
                number_format((float) $b['verified_paid'], 2, '.', ''),
                number_format((float) $b['balance_due'], 2, '.', ''),
                $b['project_stage'] ?? 'n/a',
                $b['project_due_date'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Build report metrics and rows for the selected Monthly or Daily period.
     */
    private function buildReportData(array $query): array
    {
        $today = Clock::today();
        $rawPeriod = strtolower(trim((string) ($query['period'] ?? $query['mode'] ?? 'monthly')));
        $period = in_array($rawPeriod, ['daily', 'day'], true) ? 'daily' : 'monthly';

        if ($period === 'daily') {
            $rawDate = trim((string) ($query['date'] ?? ''));
            $date = Validator::isDate($rawDate) ? $rawDate : $today;
            $startDate = $date;
            $endDate = $date;
            $periodKey = $date;
            $label = date_long($date);
            $prevKey = date('Y-m-d', strtotime($date . ' -1 day'));
            $nextKey = date('Y-m-d', strtotime($date . ' +1 day'));
            $month = substr($date, 0, 7);
        } else {
            $rawMonth = trim((string) ($query['month'] ?? ''));
            $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth) ? $rawMonth : substr($today, 0, 7);
            $startDate = $month . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));
            $periodKey = $month;
            $label = date('F Y', strtotime($startDate));
            $prevKey = date('Y-m', strtotime($startDate . ' -1 month'));
            $nextKey = date('Y-m', strtotime($startDate . ' +1 month'));
            $date = $today;
        }

        // Bookings whose event_date falls in [startDate, endDate] (non-archived)
        $bookings = DB::all(
            "SELECT b.id, b.reference_code, b.event_type, b.event_date, b.start_time, b.end_time,
                    b.venue, b.status, b.total_amount, b.down_payment_due,
                    c.full_name AS client_name, c.mobile AS client_mobile,
                    p.name AS package_name,
                    COALESCE(vp.verified_paid, 0) AS verified_paid,
                    COALESCE(vp.forfeited_paid, 0) AS forfeited_paid,
                    pr.stage AS project_stage, pr.due_date AS project_due_date
             FROM bookings b
             JOIN clients c ON c.id = b.client_id
             JOIN packages p ON p.id = b.package_id
             LEFT JOIN (
                 SELECT booking_id,
                        SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END) AS verified_paid,
                        SUM(CASE WHEN status = 'verified' AND is_forfeited = 1 THEN amount ELSE 0 END) AS forfeited_paid
                 FROM payments
                 GROUP BY booking_id
             ) vp ON vp.booking_id = b.id
             LEFT JOIN projects pr ON pr.booking_id = b.id
             WHERE b.is_archived = 0
               AND b.event_date BETWEEN ? AND ?
             ORDER BY b.event_date ASC, b.start_time ASC, b.id ASC",
            [$startDate, $endDate]
        );

        $totalBookings = count($bookings);
        $statusCounts = [
            'confirmed' => 0,
            'completed' => 0,
            'pending'   => 0,
            'cancelled' => 0,
        ];
        $incomeFromPeriodEvents = 0.0;
        $outstandingBalances = 0.0;
        $pendingOutputs = 0;
        $overdueOutputs = 0;

        foreach ($bookings as &$b) {
            $st = (string) $b['status'];
            if (isset($statusCounts[$st])) {
                $statusCounts[$st]++;
            }
            $paid = round((float) $b['verified_paid'], 2);
            $total = round((float) $b['total_amount'], 2);
            $balance = $st === 'cancelled' ? 0.0 : max(0.0, round($total - $paid, 2));
            $b['balance_due'] = $balance;

            $incomeFromPeriodEvents += $paid;
            if ($st === 'confirmed' && $balance > 0) {
                $outstandingBalances += $balance;
            }
            if (!empty($b['project_stage']) && $b['project_stage'] !== 'released' && $st !== 'cancelled') {
                $pendingOutputs++;
                if (!empty($b['project_due_date']) && $b['project_due_date'] < $today) {
                    $overdueOutputs++;
                }
            }
        }
        unset($b);

        // Verified payments collected within the date window (by paid_at / verified_at)
        $paymentsInWindow = DB::all(
            "SELECT py.id, py.receipt_no, py.type, py.method, py.amount, py.paid_at, py.verified_at,
                    b.reference_code, b.event_date, c.full_name AS client_name
             FROM payments py
             JOIN bookings b ON b.id = py.booking_id
             JOIN clients c ON c.id = b.client_id
             WHERE py.status = 'verified'
               AND b.is_archived = 0
               AND (
                   DATE(COALESCE(py.paid_at, py.verified_at, py.created_at)) BETWEEN ? AND ?
                   OR b.event_date BETWEEN ? AND ?
               )
             ORDER BY COALESCE(py.paid_at, py.verified_at, py.created_at) DESC, py.id DESC",
            [$startDate, $endDate, $startDate, $endDate]
        );

        return [
            'period'          => $period,
            'period_key'      => $periodKey,
            'month'           => $month,
            'date'            => $date,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'label'           => $label,
            'prev_key'        => $prevKey,
            'next_key'        => $nextKey,
            'studio_name'     => Settings::get('studio_name', 'Douffer Photography'),
            'studio_address'  => Settings::get('studio_address', ''),
            'tiles'           => [
                'bookings'             => $totalBookings,
                'income_received'      => round($incomeFromPeriodEvents, 2),
                'outstanding_balances' => round($outstandingBalances, 2),
                'pending_outputs'      => $pendingOutputs,
                'overdue_outputs'      => $overdueOutputs,
            ],
            'status_counts'   => $statusCounts,
            'bookings'        => $bookings,
            'payments'        => $paymentsInWindow,
        ];
    }
}
