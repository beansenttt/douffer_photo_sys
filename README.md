# Douffer PhotoSys

**Douffer Photography Management and Booking System** — a mobile-first PHP/MySQL web application for **Douffer Photography** (owner: Mr. Jeffrey D. Madrigal) in Victoria Ville Subd., Brgy. Bilaran, Nasugbu, Batangas, Philippines.

Built as a **BSIT 3rd-Year Systems Analysis and Design (SAD)** project. **All names, mobile numbers, dates, and monetary amounts in the seed data are sample data (`SAMPLE`). No real client information is stored in the repository.**

> **Project Status:** **100% Complete (Phases 1–7 Verified)**. All 28 screens (`C1–C6`, `A1–A21`, `MORE`), all 10 Business Rules (`BR-1` to `BR-10`), all 13 Acceptance Scenarios (`T1` to `T13`), PWA-lite offline support, and all Section 13 documentation deliverables are implemented and verified across **8 automated test suites (435 total assertions, 0 failures)**.

---

## 1. Complete Feature List (All 28 Screens)

### Client Portal (Teal Theme — Public, Mobile-First)
* **`C1` Home & Packages (`/`)**: Studio hero banner, **Check availability / Book now** CTA, bookable Photo & Video package cards (`packages`) with inclusions bottom sheets, inquire-only **Other services** chips (`Invitations`, `Photobooth`, `Give-aways`, `Emcee`, `Catering`, `Makeup artist`), and floating **Chat with us** button.
* **`C2` Check Availability (`/availability`)**: Server-rendered interactive month calendar (`◀ ▶`). Past dates, studio-blocked dates (`blocked_dates`), and full dates (`confirmed` bookings `>= max_events_per_day`) are greyed out and crossed out (`BR-1`, `BR-4`).
* **`C3` Booking Details (`/book`)**: Step 1 of 2. Captures client name, normalized PH mobile (`09XXXXXXXXX`), Messenger name, event type, package, event date/time, venue, special requests, and **RA 10173 Data Privacy Act consent** checkbox. Re-validates date availability on the server.
* **`C4` Summary & GCash Payment (`/book/pay`)**: Step 2 of 2. Displays package total, computed down payment (default 30% rounded up to nearest ₱50, or package override), remaining balance, GCash account details & QR code, required GCash reference number, client-side compressed screenshot upload (`pay.js` canvas ≤1600 px JPEG), and versioned **non-refundable down payment policy** checkbox (`terms_versions`).
* **`C5` Booking Status & Lookup (`/booking`, `/booking/{ref}`)**: Rate-limited lookup by reference number (`DPS-YYYY-NNNN`) + mobile number. Shows 3-step progress bar, payment status, balance, project stage, **View booking confirmation** (`A8`), **Message the studio** (`C6`), and screenshot re-upload if a payment was rejected.
* **`C6` Client In-App Chat (`/chat`, `/chat/t/{token}`, `/booking/{ref}/chat`)**: Pre-booking inquiry chat with honeypot spam guard and bookmarkable private link (`SHA-256` token hash stored in DB), plus booking-linked chat. Supports up to 1,000-char messages, `≤ 2 MB` image attachments, `Sent`/`Seen` receipts (`read_at`), and adaptive light polling (`4.5 s` visible, `20 s` hidden/idle backoff, paused offline).

### Studio Admin Portal (Blue Theme — Owner & Permitted Staff)
* **`A1` Admin Login (`/admin/login`)**: Username + password with Show/Hide toggle, login throttling (`5` attempts per user / `20` per IP per 15 min), `session_regenerate_id()` on login, and role-based redirect (`Owner`/`Staff` → `A2`, `Crew` → `A18`).
* **`A2` Live Dashboard (`/admin`)**: Displays today's date (`demo_date`), **4 live summary tiles** (*Events today · Payments to verify · Balances due · Outputs overdue*), **+ New booking**, **Needs attention** alert cards (*Conflict · Overdue · Balance due · Unverified payments · Unread messages*), backup health status line (warns if >7 days old), and global unread-messages badge.
* **`A3` Booking Calendar (`/admin/calendar`)**: Monthly grid fitting `360 px` screens (confirmed = dot, full = red outline, pending-only = dashed outline, blocked = marked) with day event panel and **+ Add booking on this date** (`→ A5`).
* **`A4` Bookings Ledger (`/admin/bookings`)**: Fast indexed search (name, date, reference code, mobile) and filter chips (*All · Pending · Confirmed · Balance due · Completed · Archived*).
* **`A5` New Booking (`/admin/bookings/new`)**: Walk-in / Call / Messenger / Referral booking form with live AJAX availability check (`/api/availability/check`), add-on selection, client auto-matching by mobile, and **Down payment received now** checkbox for instant confirmation + `OR-YYYY-NNNN` receipt issuance.
* **`A6` Booking Details Hub (`/admin/bookings/{id}`)**: Multi-tab hub (*Overview · Payments · Crew · Project · Files*) with internal staff/crew notes (`booking_notes`), balance payment recording, archive/restore confirmation sheet (`BR-8`), and full lifecycle timeline (`booking_changes`).
* **`A7` Payments to Verify (`/admin/payments`)**: Verification queue with screenshot zoom sheet, waiting-requests count on the same date, **duplicate GCash reference fraud warning** + required `ack_duplicate` checkbox (`Risk 3`, `T9`), transactional **Verify** (locks `date_locks` row via `SELECT ... FOR UPDATE`, re-checks capacity, confirms booking, issues `OR-YYYY-NNNN`, spawns `projects` row, posts system chat notice, opens `A8`), and **Reject** with reason.
* **`A8` Confirmation & Official Receipt (`/admin/bookings/{id}/confirmation`)**: Print-friendly A4 sheet (`@media print` hides nav/controls) with tabs for **Booking confirmation** and **Official receipt** (with receipt selector chips when multiple verified payments exist), **Print**, and **Copy for Messenger**.
* **`A9` Crew & Equipment (`/admin/crew`)**: Date schedule with red crew-shortage banner (`T4`), per-event Lead/Backup assignment, hard block on overlapping event hours (`CrewOverlapConflictException`), same-day non-overlapping warning, on-call roster with Free/Busy tags, and equipment checklist (`booking_equipment`).
* **`A10` Project Tracker (`/admin/projects`)**: Stage filter chips (*Booked · Covered · Editing · Ready · Released*), due-date sorting, **Overdue** badge, editing checklist (*editing · rendering · album layout*), and stage progression logging (`project_history`).
* **`A11` Files & Handover Log (`/admin/projects/{id}/files`)**: Logs RAW and EDITED folder paths (`BR-10`), local backup and cloud copy checkboxes/timestamps, release method (*Pickup · Messenger link · Other*), release date, and recipient. Enforces override confirmation (`confirm_release_override`) if cloud copy is missing or balance > ₱0, and auto-completes the booking when balance is ₱0.00.
* **`A12` Client Message Templates (`/admin/bookings/{id}/messages/templates`)**: Bilingual (Filipino/English) templates (*Booking confirmed · Balance reminder · Output ready · Reschedule*) with live placeholder substitution, **Copy for Messenger**, **Mark as sent** (`message_log`), and **Send in app chat** (`A19`).
* **`A13` Reschedule or Cancel (`/admin/bookings/{id}/change`)**: Free reschedule to an open date (`BR-3`, `T3`) with live availability check and system chat notice; cancellation workflow (`BR-2`, `T10`) marking verified down payments **forfeited** (`is_forfeited = 1`), freeing the date, and preserving the record.
* **`A14` Clients & Inquiries (`/admin/clients`, `/admin/clients/{id}`)**: Searchable client directory, contact-channel filter chips, booking and inquiry history, **+ Add client / Record inquiry**, and **+ Add inquiry note** (`client_inquiries`).
* **`A15` Packages & Services (`/admin/packages` — Owner only)**: Manage **Photo & video** packages, **Other services**, and **Add-ons** (rates, inclusions, fixed down-payment overrides, active toggles).
* **`A16` Reports (`/admin/reports`, `/admin/reports/export.csv`)**: **Daily** and **Monthly** reports with 4 summary tiles (*Bookings · Income received · Outstanding balances · Pending outputs*), booking breakdown, verified receipts list, **Print report**, and **Export CSV**.
* **`A17` Users & Access (`/admin/users` — Owner only)**: Manage Owner, Staff, and Crew accounts, granular staff permission checkboxes (`view_bookings`, `record_payments`, `verify_payments`, `archive_records`, `manage_messages`), password reset, and account activation/deactivation.
* **`A18` Crew: My Events (`/crew` — Crew only, Scenario `T7`)**: Read-only view of the logged-in crew member's next assigned event (date, time, call time, role, venue, client contact, inclusions, equipment, crew-visible studio notes) and later/past events, with zero financial or chat data exposed.
* **`A19` Studio Messages Inbox & Thread (`/admin/messages`, `/admin/messages/{id}`)**: Inbox with filter chips (*All · Unread · Open · Closed · Inquiries*), search, unread badges, thread view with booking summary card, quick-reply chips, **Link to booking/client**, **Close/Reopen**, **Copy last reply**, **Print thread**, and `.txt` transcript export.
* **`A20` Settings, Blocked Dates & Backups (`/admin/settings` — Owner only)**: Studio profile, GCash details & QR upload (`/files/gcash-qr`), booking/capacity rules, blocked dates (`blocked_dates`), versioned terms (`terms_versions`), quick-reply & template editors, and **Backup now** (`.sql` dump + download + **Mark cloud copy done**).
* **`A21` Audit & System Logs (`/admin/logs`, `/admin/logs/export.csv` — Owner only)**: Centralized append-only viewer across all **6 log streams** (`1. Security & Audit`, `2. Booking History`, `3. Projects & Handover`, `4. Notifications`, `5. Backups`, `6. Errors & Rate Limits`) with filters, search, **Print**, and **Export CSV**.

---

## 2. Requirements & Installation (XAMPP or Built-In Server)

### System Requirements
* **PHP 8.0+** (tested on XAMPP PHP 8.2.12) with `pdo_mysql`, `mbstring`, `fileinfo` (enabled by default in XAMPP), and `gd` (recommended for thumbnail generation and EXIF stripping).
* **MySQL 5.7+ / MariaDB 10.3+** (XAMPP default).
* **Zero external dependencies:** No Composer, no Node.js/npm, no external APIs.

### Step-by-Step XAMPP Setup
1. Place the project folder at `C:\xampp\htdocs\douffer_photosys`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Run the CLI installer to create `config/config.php`, the `douffer_photosys` database, all 29 tables, sample seed data, and private `storage/` directories:
   ```powershell
   C:\xampp\php\php.exe database\install.php --fresh
   ```
4. Open **`http://localhost/douffer_photosys/`** in your browser.

### Alternative: PHP Built-In Development Server
```powershell
C:\xampp\php\php.exe -S localhost:8000 -t public public/index.php
```
Then open **`http://localhost:8000/`**.

### Alternative: Manual phpMyAdmin Setup (No CLI)
1. In phpMyAdmin, create a new database named `douffer_photosys` (`utf8mb4_unicode_ci`).
2. Import [`database/schema.sql`](database/schema.sql), then import [`database/seed.sql`](database/seed.sql).
3. Copy [`config/config.sample.php`](config/config.sample.php) to `config/config.php` and set your database credentials.

---

## 3. Default Logins (Sample Accounts)

| Role | Username | Default Password | Landing Screen | Notes |
|---|---|---|---|---|
| **Owner** | `owner` | `Owner@2026` | `A2` Dashboard (`/admin`) | Full access to all screens, settings, backups, users, and logs |
| **Staff** | `staff` | `Staff@2026` | `A2` Dashboard (`/admin`) | Governed by permission checkboxes in `A17` |
| **Crew (Photographer A)** | `crew1` | `Crew1@2026` | `A18` My Events (`/crew`) | Unassigned initially (demonstrates empty state & `T4` assignment) |
| **Crew (Videographer A)** | `crew2` | `Crew2@2026` | `A18` My Events (`/crew`) | Assigned to Dec 12 **Santos – Wedding** (`T7` walkthrough) |

* All seeded accounts have `must_change_password = 1` and display a **"Change the default password"** banner until updated at `/account/password`.
* Seeded pre-booking inquiry chat link for **Ana V. (Sample)**: `http://localhost:8000/chat/t/demo-inquiry-token`

---

## 4. How to Demo (`demo_date`)

In `config/config.php`, `'demo_date' => '2026-12-12'` pins the application's clock (`App\Core\Clock::today()`) to **Saturday, December 12, 2026** so that every demonstration matches the paper prototype:
* **Dec 12, 2026 ("Today"):**
  * **Santos – Wedding (`DPS-2026-0004`, 9:00 AM):** Confirmed, down payment verified (`₱4,800.00`), balance due (`₱11,200.00`), Owner (Lead) + Videographer A (`crew2`, Backup, `7:30 AM` call time) assigned.
  * **Reyes – Birthday (`DPS-2026-0006`, 2:00 PM):** Pending with GCash payment waiting in `A7` and **no crew assigned** (triggers the same-day crew shortage warning in `A2` and `A9` for `T4`).
* **Dec 5, 2026 (`Cruz – Birthday`, `DPS-2026-0003`):** Confirmed, project in `Editing` stage with due date **Dec 10, 2026 → Overdue** (`T5`).
* **Dec 15, 2026:** Open date for the client booking walkthrough (`T1`).
* **Dec 24, 2026 (`Mendoza – Christening`, `DPS-2026-0007`):** Pending payment in `A7` reusing GCash reference `1001234567807` (triggers the duplicate-reference fraud warning for `T9`).
* **Dec 25 & Dec 31, 2026:** Blocked studio holidays (`blocked_dates`).

To switch the app to the real system clock, set `'demo_date' => null` in `config/config.php`.

---

## 5. Honest Architectural Notes & Data Privacy (RA 10173)

1. **Manual Cloud Backup Confirmation (`§9.6`, `BR-10`):** Because the application runs on localhost or standard shared hosting without paid third-party cloud API credentials, **Backup now** (`A20`) and `php database/backup.php` generate a complete `.sql` dump in `storage/backups/` for download. The owner manually uploads the `.sql` file (and project RAW/EDITED folders) to Google Drive/OneDrive and clicks **Mark cloud copy done** in `A20` / `A11`, which timestamps and audit-logs the confirmation.
2. **No Paid SMS or Email APIs (`§8.1`, `BR-6`):** All notifications work at zero cost via the built-in web chat (`C6`/`A19`), unread nav badges, browser tab title alerts, and one-tap **Copy for Messenger** buttons (`A8`, `A12`, `A19`).
3. **Media Deliverables Are Never Uploaded (`BR-10`):** Only compressed GCash payment screenshots (`storage/receipts/`) and chat image attachments (`storage/chat/`, `≤ 2 MB`) are uploaded and served through authenticated `FileController` routes. Heavy event photo/video deliverables stay on the studio's drives and are tracked by folder path in `A11`.
4. **Data Privacy Act of 2012 (`RA 10173`, `BR-8`, `BR-9`):**
   * Explicit consent checkboxes are required before submitting a booking (`C3`) or starting a chat (`C6`), with full notice at `/privacy`.
   * Records are archived (`is_archived = 1`) and never hard-deleted from the UI (`BR-8`). Active client/booking records are retained for studio accounting and warranty tracking and may be archived once completed.
   * Every sensitive action is recorded in the 6 append-only log streams visible to the Owner in `A21` (`/admin/logs`).

---

## 6. Automated Backup Scheduling (`database/backup.php`)

To run a scheduled backup from the command line:
```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\douffer_photosys\database\backup.php
```
* **Windows Task Scheduler:** Create a Basic Task → Trigger: *Daily at 9:00 PM* → Action: *Start a program* → Program/script: `C:\xampp\php\php.exe` → Add arguments: `"C:\xampp\htdocs\douffer_photosys\database\backup.php"`.
* **Linux Shared Hosting (cron):**
  ```cron
  0 21 * * * /usr/bin/php /home/username/public_html/douffer_photosys/database/backup.php >/dev/null 2>&1
  ```

---

## 7. Running the Automated Test Suites (435 Checks)

Start the server (`C:\xampp\php\php.exe -S localhost:8000 -t public public/index.php`) and run:

```powershell
# 1. Unit & Service Business-Rule Tests (87 assertions)
C:\xampp\php\php.exe tests\run.php

# 2. HTTP Role, CSRF & Security Header Smoke Tests (32 assertions)
C:\xampp\php\php.exe tests\smoke_http.php http://localhost:8000

# 3. Phase 2 Client Booking Path E2E (Scenario T1: 40 assertions)
C:\xampp\php\php.exe tests\e2e_booking.php http://localhost:8000

# 4. Phase 3 Payments, Duplicate Ref & Receipts E2E (Scenarios T2, T6, T8, T9: 21 assertions)
C:\xampp\php\php.exe tests\e2e_payments.php http://localhost:8000

# 5. Phase 4 Admin Operations E2E (Scenarios T3, T4, T5, T10 & Screens A2–A13: 117 assertions)
C:\xampp\php\php.exe tests\e2e_admin.php http://localhost:8000

# 6. Phase 5 In-App Messaging E2E (Scenario T11, C6 & A19: 42 assertions)
C:\xampp\php\php.exe tests\e2e_chat.php http://localhost:8000

# 7. Phase 6 Clients, Catalog, Reports, Users, Crew, Settings & Logs E2E (Scenario T7, A14–A21: 51 assertions)
C:\xampp\php\php.exe tests\e2e_phase6.php http://localhost:8000

# 8. Phase 7 PWA, Performance Budget & Security/Audit Hardening E2E (Scenarios T12 & T13: 45 assertions)
C:\xampp\php\php.exe tests\e2e_phase7.php http://localhost:8000
```

---

## 8. Documentation Index (`docs/`)

* [`docs/PLAN.md`](docs/PLAN.md) — Architecture, folder tree, route map, and assumptions.
* [`docs/ERD.md`](docs/ERD.md) — Mermaid Entity-Relationship Diagram for all 29 tables.
* [`docs/DFD.md`](docs/DFD.md) — Mermaid Context Diagram, DFD Level 0, and DFD Level 1 (`1.0–5.0` & `D1–D5`).
* [`docs/UseCases.md`](docs/UseCases.md) — Actor overview and detailed Use Case specifications (`UC-01` to `UC-14`).
* [`docs/TestCases.md`](docs/TestCases.md) — Acceptance test cases (`T1`–`T13`), functional test cases (`TC-14`–`TC-22`), and 4-viewport / Lighthouse mobile checklist.
* [`docs/TRACEABILITY.md`](docs/TRACEABILITY.md) — Requirement → Screen → Route → Test Case traceability matrix.
* [`docs/DEVIATIONS.md`](docs/DEVIATIONS.md) — Line-by-line log of differences from the paper prototype and their reasons.
* [`docs/CHANGELOG.md`](docs/CHANGELOG.md) — Phase-by-phase build log (Phases 1 to 7).

---

## 9. Troubleshooting & Shared Hosting Deployment

* **Enable GD for Image Thumbnails & Metadata Stripping:** Open `C:\xampp\php\php.ini`, uncomment `extension=gd` (remove leading `;`), and restart Apache.
* **404 on Sub-Routes in Apache:** Ensure Apache `mod_rewrite` is enabled and `AllowOverride All` is set for `htdocs`.
* **Deploying to Shared Hosting (cPanel / DirectAdmin):**
  1. Upload all files to your hosting folder (or point the domain document root directly to `public/`).
  2. Each internal folder (`app/`, `config/`, `database/`, `storage/`, `tests/`, `docs/`) includes a `.htaccess` with `Require all denied` so private files and `storage/` uploads are never web-accessible even when deployed to a shared `public_html` root.
  3. Import `database/schema.sql` and `database/seed.sql` via phpMyAdmin, copy `config/config.sample.php` to `config/config.php`, set `'env' => 'production'` and `'demo_date' => null`, and update your MySQL credentials.
#   d o u f f e r _ p h o t o _ s y s  
 