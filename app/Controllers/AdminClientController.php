<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\DB;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Validator;

/**
 * Screen A14: Clients & Inquiries (/admin/clients).
 * Directory of clients, search, channel filter chips, booking history, and inquiry notes.
 */
final class AdminClientController
{
    private const CHANNELS = ['messenger', 'call', 'walk_in', 'referral', 'website'];

    /**
     * GET /admin/clients
     */
    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $filter = trim((string) ($_GET['filter'] ?? 'all'));
        $validFilters = array_merge(['all', 'archived'], self::CHANNELS);
        if (!in_array($filter, $validFilters, true)) {
            $filter = 'all';
        }

        $where = [];
        $params = [];

        if ($filter === 'archived') {
            $where[] = 'c.is_archived = 1';
        } else {
            $where[] = 'c.is_archived = 0';
            if (in_array($filter, self::CHANNELS, true)) {
                $where[] = 'c.contact_channel = ?';
                $params[] = $filter;
            }
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $norm = preg_replace('/\D+/', '', $q);
            if ($norm !== '') {
                $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR c.mobile_normalized LIKE ? OR c.messenger_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = '%' . $norm . '%';
                $params[] = $like;
            } else {
                $where[] = '(c.full_name LIKE ? OR c.mobile LIKE ? OR c.messenger_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $clients = DB::all(
            "SELECT c.*
             FROM clients c
             {$sqlWhere}
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 100",
            $params
        );

        // Hydrate bookings and inquiry notes for the listed clients
        $clientIds = array_map(static fn(array $c): int => (int) $c['id'], $clients);
        $bookingsByClient = [];
        $inquiriesByClient = [];

        if (!empty($clientIds)) {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));

            $bookings = DB::all(
                "SELECT b.id, b.client_id, b.reference_code, b.event_type, b.event_date, b.status,
                        b.total_amount, b.is_archived, p.name AS package_name
                 FROM bookings b
                 JOIN packages p ON p.id = b.package_id
                 WHERE b.client_id IN ({$placeholders})
                 ORDER BY b.event_date DESC, b.id DESC",
                $clientIds
            );
            foreach ($bookings as $b) {
                $bookingsByClient[(int) $b['client_id']][] = $b;
            }

            $inquiries = DB::all(
                "SELECT ci.id, ci.client_id, ci.channel, ci.note, ci.created_at,
                        u.name AS author_name
                 FROM client_inquiries ci
                 LEFT JOIN users u ON u.id = ci.created_by
                 WHERE ci.client_id IN ({$placeholders})
                 ORDER BY ci.created_at DESC, ci.id DESC",
                $clientIds
            );
            foreach ($inquiries as $inq) {
                $inquiriesByClient[(int) $inq['client_id']][] = $inq;
            }
        }

        foreach ($clients as &$client) {
            $cid = (int) $client['id'];
            $client['bookings'] = $bookingsByClient[$cid] ?? [];
            $client['inquiries'] = $inquiriesByClient[$cid] ?? [];
        }
        unset($client);

        // Counts for filter chips
        $counts = [
            'all'       => (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0'),
            'messenger' => (int) DB::value("SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND contact_channel = 'messenger'"),
            'call'      => (int) DB::value("SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND contact_channel = 'call'"),
            'walk_in'   => (int) DB::value("SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND contact_channel = 'walk_in'"),
            'referral'  => (int) DB::value("SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND contact_channel = 'referral'"),
            'website'   => (int) DB::value("SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND contact_channel = 'website'"),
            'archived'  => (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 1'),
        ];

        view('admin/clients', [
            'title'   => 'Clients & Inquiries',
            'screen'  => 'A14',
            'back'    => '/admin/more',
            'clients' => $clients,
            'q'       => $q,
            'filter'  => $filter,
            'counts'  => $counts,
        ], 'admin');
    }

    /**
     * GET /admin/clients/{id}
     */
    public function show(string $id): void
    {
        $clientId = (int) $id;
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        $bookings = DB::all(
            "SELECT b.*, p.name AS package_name
             FROM bookings b
             JOIN packages p ON p.id = b.package_id
             WHERE b.client_id = ?
             ORDER BY b.event_date DESC, b.id DESC",
            [$clientId]
        );

        $inquiries = DB::all(
            "SELECT ci.*, u.name AS author_name
             FROM client_inquiries ci
             LEFT JOIN users u ON u.id = ci.created_by
             WHERE ci.client_id = ?
             ORDER BY ci.created_at DESC, ci.id DESC",
            [$clientId]
        );

        $conversations = DB::all(
            "SELECT c.id, c.booking_id, c.status, c.last_message_at, b.reference_code
             FROM conversations c
             LEFT JOIN bookings b ON b.id = c.booking_id
             WHERE c.client_id = ?
             ORDER BY c.last_message_at DESC, c.id DESC",
            [$clientId]
        );

        view('admin/client_show', [
            'title'         => $client['full_name'],
            'screen'        => 'A14',
            'back'          => '/admin/clients',
            'client'        => $client,
            'bookings'      => $bookings,
            'inquiries'     => $inquiries,
            'conversations' => $conversations,
        ], 'admin');
    }

    /**
     * POST /admin/clients — Record a new client (or reuse by mobile) + optional initial inquiry note.
     */
    public function store(): void
    {
        $errors = Validator::make($_POST, [
            'full_name'       => 'required|max:120',
            'mobile'          => 'required|mobile',
            'messenger_name'  => 'max:150',
            'contact_channel' => 'required|in:messenger,call,walk_in,referral,website',
            'note'            => 'max:2000',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please fix the highlighted client fields.');
            redirect('/admin/clients');
        }

        $fullName = trim((string) $_POST['full_name']);
        $mobileRaw = trim((string) $_POST['mobile']);
        $mobileNorm = (string) normalize_mobile($mobileRaw);
        $messenger = trim((string) ($_POST['messenger_name'] ?? ''));
        $channel = (string) $_POST['contact_channel'];
        $note = trim((string) ($_POST['note'] ?? ''));
        $now = Clock::now();

        $existing = DB::one('SELECT * FROM clients WHERE mobile_normalized = ? ORDER BY id ASC LIMIT 1', [$mobileNorm]);
        if ($existing) {
            $clientId = (int) $existing['id'];
            DB::update('clients', [
                'full_name'       => $fullName,
                'mobile'          => format_mobile($mobileNorm),
                'messenger_name'  => $messenger !== '' ? $messenger : $existing['messenger_name'],
                'contact_channel' => $channel,
                'is_archived'     => 0,
            ], 'id = ?', [$clientId]);
        } else {
            $clientId = DB::insert('clients', [
                'full_name'          => $fullName,
                'mobile'             => format_mobile($mobileNorm),
                'mobile_normalized'  => $mobileNorm,
                'messenger_name'     => $messenger !== '' ? $messenger : null,
                'contact_channel'    => $channel,
                'privacy_consent_at' => $now,
                'is_archived'        => 0,
                'created_at'         => $now,
            ]);
        }

        Audit::log('client_saved', 'client', $clientId, [
            'full_name'       => $fullName,
            'mobile'          => $mobileNorm,
            'contact_channel' => $channel,
        ]);

        if ($note !== '') {
            $inqId = DB::insert('client_inquiries', [
                'client_id'  => $clientId,
                'channel'    => $channel,
                'note'       => $note,
                'created_by' => Auth::id(),
                'created_at' => $now,
            ]);
            Audit::log('client_inquiry_added', 'client', $clientId, [
                'inquiry_id' => $inqId,
                'channel'    => $channel,
            ]);
        }

        Flash::set('success', 'Client record saved ✓');
        redirect('/admin/clients');
    }

    /**
     * POST /admin/clients/{id} — Update client contact details.
     */
    public function update(string $id): void
    {
        $clientId = (int) $id;
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        $errors = Validator::make($_POST, [
            'full_name'       => 'required|max:120',
            'mobile'          => 'required|mobile',
            'messenger_name'  => 'max:150',
            'contact_channel' => 'required|in:messenger,call,walk_in,referral,website',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please check the client details.');
            redirect('/admin/clients/' . $clientId);
        }

        $fullName = trim((string) $_POST['full_name']);
        $mobileNorm = (string) normalize_mobile((string) $_POST['mobile']);
        $messenger = trim((string) ($_POST['messenger_name'] ?? ''));
        $channel = (string) $_POST['contact_channel'];

        DB::update('clients', [
            'full_name'         => $fullName,
            'mobile'            => format_mobile($mobileNorm),
            'mobile_normalized' => $mobileNorm,
            'messenger_name'    => $messenger !== '' ? $messenger : null,
            'contact_channel'   => $channel,
        ], 'id = ?', [$clientId]);

        Audit::log('client_saved', 'client', $clientId, [
            'full_name'       => $fullName,
            'mobile'          => $mobileNorm,
            'contact_channel' => $channel,
        ]);

        Flash::set('success', 'Client details updated ✓');
        redirect('/admin/clients/' . $clientId);
    }

    /**
     * POST /admin/clients/{id}/inquiries — Add an inquiry note to a client.
     */
    public function addInquiry(string $id): void
    {
        $clientId = (int) $id;
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        $target = ($returnTo === 'show') ? '/admin/clients/' . $clientId : '/admin/clients';

        $errors = Validator::make($_POST, [
            'channel' => 'required|in:messenger,call,walk_in,referral,website',
            'note'    => 'required|max:2000',
        ]);

        if ($errors) {
            Flash::withInput($_POST, $errors);
            Flash::set('error', 'Please enter an inquiry note (max 2,000 characters).');
            redirect($target);
        }

        $channel = (string) $_POST['channel'];
        $note = trim((string) $_POST['note']);
        $now = Clock::now();

        $inqId = DB::insert('client_inquiries', [
            'client_id'  => $clientId,
            'channel'    => $channel,
            'note'       => $note,
            'created_by' => Auth::id(),
            'created_at' => $now,
        ]);

        Audit::log('client_inquiry_added', 'client', $clientId, [
            'inquiry_id' => $inqId,
            'channel'    => $channel,
            'note'       => mb_substr($note, 0, 120),
        ]);

        Flash::set('success', 'Inquiry note added ✓');
        redirect($target);
    }

    /**
     * POST /admin/clients/{id}/archive — Archive or restore a client record (BR-8).
     */
    public function toggleArchive(string $id): void
    {
        $clientId = (int) $id;
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
        if (!$client) {
            throw new HttpException(404, 'Client not found.');
        }

        $newArchived = (int) $client['is_archived'] === 1 ? 0 : 1;
        DB::update('clients', ['is_archived' => $newArchived], 'id = ?', [$clientId]);

        Audit::log($newArchived === 1 ? 'client_archived' : 'client_restored', 'client', $clientId, [
            'full_name'   => $client['full_name'],
            'is_archived' => $newArchived,
        ]);

        Flash::set('success', $newArchived === 1 ? 'Client archived ✓' : 'Client restored ✓');
        redirect('/admin/clients/' . $clientId);
    }
}
