# Douffer PhotoSys: Build Plan

The short plan required by `PROMPT.md` §1 ("Working agreement"). The system is built in 7 phases and stops after each one for review.

## 1. Approach

- **Stack:** PHP 8.0+ (tested on XAMPP PHP 8.2), MariaDB/MySQL, hand-written MVC, vanilla JS, one CSS file. No Composer, no framework, no build step.
- **Project root = `C:\Xampp\htdocs\douffer_photosys`.** The web root is `public/`. A root `.htaccess` sends every request into `public/`, so both URLs work:
  - XAMPP Apache: `http://localhost/douffer_photosys/`
  - PHP built-in server: `php -S localhost:8000 -t public public/index.php`
- The base path (`/douffer_photosys` or empty) is **auto-detected**. It can be overridden with `base_path` in `config/config.php`.
- Screen IDs (`C1`–`C6`, `A1`–`A20`) appear in route comments, view file names and a comment at the top of every view.

## 2. Folder tree

```
douffer_photosys/
├─ .htaccess               # forwards everything to public/ (XAMPP)
├─ public/                 # web root
│  ├─ index.php            # front controller
│  ├─ .htaccess            # rewrite to index.php, gzip, cache headers
│  ├─ assets/css/app.css   # the single stylesheet
│  ├─ assets/js/           # app.js (+ small feature modules later)
│  ├─ assets/img/          # icons / logo (SVG)
│  ├─ manifest.json, sw.js, offline.html      (Phase 7)
├─ app/
│  ├─ bootstrap.php, helpers.php, routes.php
│  ├─ Core/                # App, Router, DB, Auth, Csrf, Validator, View, Flash, RateLimiter, Audit, Settings, Clock, HttpException
│  ├─ Controllers/         # Client*, Admin*, Crew*, Api*, Auth, Account, Page
│  ├─ Models/              # repositories (Booking, Payment, Package, ...)
│  ├─ Services/            # AvailabilityService, PaymentService, BookingService, ProjectService, BackupService
│  └─ Views/               # layouts/, client/, admin/, crew/, partials/, errors/
├─ config/                 # config.sample.php (commit), config.php (git-ignored)
├─ database/               # schema.sql, seed.sql, install.php, backup.php
├─ storage/                # receipts/, chat/, uploads/, backups/, logs/ (outside public/, git-ignored)
├─ tests/run.php           # CLI tests for the business rules
└─ docs/                   # reference/, PLAN, DEVIATIONS, ERD, DFD, UseCases, TestCases, TRACEABILITY, CHANGELOG
```

## 3. Route map

| Screen | Method + route | Access | Phase |
|---|---|---|---|
| C1 Home & Packages | GET `/` | public | 2 |
| C2 Check Availability | GET `/availability` (+ GET `/api/availability?month=`) | public | 2 |
| C3 Booking Details | GET/POST `/book` | public | 2 |
| C4 Summary & GCash | GET/POST `/book/pay` | public (session draft) | 2 |
| C5 Booking Status | GET/POST `/booking` (lookup), GET `/booking/{ref}`, POST `/booking/{ref}/reupload` | public + lookup session | 2 |
| C6 Client Chat | GET/POST `/chat`, GET `/chat/t/{token}` | public + token / lookup | 5 |
| Privacy notice | GET `/privacy` | public | 1 |
| A1 Login | GET/POST `/admin/login`, POST `/admin/logout` | public | 1 |
| Change password | GET/POST `/account/password` | any logged-in user | 1 |
| A2 Dashboard | GET `/admin` | owner, staff | 1 (shell) → 4 |
| More menu | GET `/admin/more` | owner, staff | 1 |
| A3 Booking Calendar | GET `/admin/calendar` | `view_bookings` | 4 |
| A4 Bookings Ledger | GET `/admin/bookings` | `view_bookings` | 4 |
| A5 New Booking | GET/POST `/admin/bookings/new` | `view_bookings` | 4 |
| A6 Booking Details | GET `/admin/bookings/{id}` (+ archive/restore/notes POSTs) | `view_bookings` (`archive_records` for archive) | 4 |
| A7 Payments to Verify | GET `/admin/payments`, POST `/admin/payments/{id}/verify`, `/reject` | `verify_payments` | 3 |
| A8 Confirmation & Receipt | GET `/admin/bookings/{id}/confirmation`, GET `/admin/payments/{id}/receipt` | `view_bookings` | 3 |
| A6 Payments tab: record payment | POST `/admin/bookings/{id}/payments` | `record_payments` | 3 |
| A9 Crew & Equipment | GET/POST `/admin/crew?date=` | `view_bookings` | 4 |
| A10 Project Tracker | GET `/admin/projects`, POST `/admin/projects/{id}/stage`, `/checklist` | `view_bookings` | 4 |
| A11 Files & Handover | GET/POST `/admin/projects/{id}/files` | `view_bookings` | 4 |
| A12 Client Messages | GET/POST `/admin/bookings/{id}/messages/templates` | `manage_messages` | 4 |
| A13 Reschedule or Cancel | GET/POST `/admin/bookings/{id}/change` | `view_bookings` (+ owner or `archive_records` for cancel) | 4 |
| A14 Clients & Inquiries | GET `/admin/clients`, GET `/admin/clients/{id}`, POST notes | `view_bookings` | 6 |
| A15 Packages & Services | GET/POST `/admin/packages` … | owner | 6 |
| A16 Reports | GET `/admin/reports`, GET `/admin/reports/export.csv` | owner, staff with `view_bookings` | 6 |
| A17 Users & Access | GET/POST `/admin/users` … | owner | 6 |
| A18 Crew: My Events | GET `/crew` | crew | 1 (shell) → 6 |
| A19 Messages Inbox/Thread | GET `/admin/messages`, GET `/admin/messages/{id}` | `manage_messages` | 5 |
| A20 Settings & Blocked Dates | GET/POST `/admin/settings` … , POST `/admin/backups` | owner | 6 |
| A21 Audit & System Logs | GET `/admin/logs`, GET `/admin/logs/export.csv` | owner | 6 |
| Private files | GET `/files/receipt/{id}`, `/files/chat/{id}` | authorised viewer only | 2/5 |
| JSON API | GET `/api/conversations/{id}/messages?since_id=`, POST `/api/conversations/{id}/messages`, GET `/api/unread`, GET `/api/availability/check` | per conversation / admin | 4–5 |

## 4. Assumptions

1. Open questions use the defaults in PROMPT §3.3, all stored in the `settings` table (editable in A20): 30% down payment rounded up to ₱50, max 2 confirmed events per day, 14 delivery days, balance overdue after the event date (+0 days).
2. **`demo_date` defaults to `2026-12-12`** in `config.php`, so the dashboard matches the paper prototype. Set it to `null` to use the real date. "Today" everywhere (availability, overdue, timestamps written by PHP) goes through one `Clock` class.
3. **Pending requests count for crew planning** (A9 shows them with a *Pending* tag), but **not for date capacity** (BR-1). This is how the Dec 12 "second event has no crew" warning in T4 works while Reyes is still pending.
4. A booking becomes **Completed** when its project is marked **Released** and the balance is ₱0.00. If a balance remains, it stays Confirmed with an *Overdue balance* tag.
5. Seeded **Mendoza** payment reuses a GCash reference that already exists, so T9 (duplicate warning) can be demonstrated right away.
6. Staff need the `view_bookings` permission for calendar/ledger/projects. Reports are open to the owner and to staff with `view_bookings`. Packages, users and settings are owner only.
7. Crew call time is stored per assignment. If it is empty, it shows as 1 hour before the start time.
8. The GD extension is commented out in the default XAMPP `php.ini`. Uploads still work without it (validated with `finfo` + `getimagesize`), but metadata stripping and thumbnails need GD. The README explains how to enable it (`extension=gd`).
9. Event types are a setting (`event_types`): Wedding, Birthday, Debut, Christening, Anniversary, Corporate, Other.
