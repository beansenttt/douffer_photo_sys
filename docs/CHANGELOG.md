# Changelog

## Phase 7: Hardening, PWA & Documentation (`T12`, `T13`, Section 13) (2026-11-10)

**Built**
- **PWA-Lite & Offline Support (`T12`):**
  - `public/manifest.json`: Web App Manifest (`standalone` display, `#0f766e` theme color, SVG maskable icon) enabling "Add to Home Screen" on mobile devices.
  - `public/sw.js`: Service Worker that caches **only** the static shell (`app.css`, `app.js`, `pay.js`, `chat.js`, `admin_booking.js`, `icon.svg`, `manifest.json`, `offline.html`), never caches authenticated or private HTML/API responses, and serves `public/offline.html` when navigation fails offline.
  - `public/offline.html`: Strict-CSP-compliant offline fallback page that leaks zero private client or studio data.
  - Updated `app/Views/partials/head.php` and `public/assets/js/app.js` to link `manifest.json` and register `/sw.js`.
- **Security, Performance & 4-Viewport Hardening (`T12`, `T13`):**
  - Added `tests/e2e_phase7.php` (**45 checks**) verifying PWA endpoints, static asset budgets (`app.css` ≤ 30 KB, total JS ≤ 60 KB, HTML < 50 KB), responsive viewport meta and tap targets, SQLi parameterization, XSS escaping, CSRF enforcement, private file access controls (`/files/receipt/*`, `/files/chat/*`), session fixation prevention, and 6-stream forensic audit trail completeness on `A21`.
  - Expanded `tests/run.php` to **87 unit/service tests** (0 failures).
- **Complete Section 13 Class Documentation Deliverables:**
  - `README.md`: complete setup, default credentials, `demo_date` guide, all 28 screens (`C1–C6`, `A1–A21`, `MORE`), honest notes, Windows Task Scheduler / cron backup scheduling, troubleshooting, and shared hosting deployment guide.
  - `docs/ERD.md`: Mermaid Entity-Relationship Diagram matching all 29 tables in `database/schema.sql`.
  - `docs/DFD.md`: Mermaid Context Diagram, DFD Level 0, and DFD Level 1 (`1.0–4.0` + messaging/audit flow and data stores `D1–D5`).
  - `docs/UseCases.md`: actor overview diagram and detailed use cases (`UC-01` to `UC-14`) across Client, Owner, Staff, and Crew.
  - `docs/TestCases.md`: master test cases (`T1`–`T13`, `TC-14`–`TC-22`) and 4-viewport / Lighthouse mobile checklist.
  - `docs/TRACEABILITY.md`: full traceability matrix (`BR-1`–`BR-10` and all 17 requirement categories → Screen → Route → Test Case).
  - `docs/DEVIATIONS.md` & `docs/CHANGELOG.md`: updated through Phase 7.

## Phase 6: Remaining admin, logs & crew (`T7`, `A14–A18`, `A20`, `A21`) (2026-11-10)

**Built**
- Models & Services:
  - `CrewService::getEventsForUser` (`app/Services/CrewService.php`): queries assigned events for the logged-in crew member (`crew_members.user_id = Auth::id()`), computes effective call time (explicit `call_time` or 1 hour before `start_time`), loads package inclusions, add-on names, co-assigned crew, equipment checklist, and crew-visible studio notes (`visible_to_crew = 1`), and strictly excludes all financial/payment columns (`T7`).
  - `Package` (`app/Models/Package.php`): added admin catalog queries (`allForAdmin`, `findById`, `activeAddons`, `allAddons`, `findAddonById`).
  - `BackupService` (`app/Services/BackupService.php`) & CLI `database/backup.php`: generates complete PDO SQL dumps (`CREATE TABLE` + `INSERT INTO` for all 29 tables) into `storage/backups/`, logs runs in `backup_runs` and `audit_logs` (`backup_created`), streams `.sql` downloads, and marks manual cloud copies (`backup_cloud_marked`).
- Controllers & Views:
  - **A14 Clients & Inquiries** (`AdminClientController`, `app/Views/admin/clients.php`, `app/Views/admin/client_show.php`): searchable client directory with channel filter chips (`All · Messenger · Call · Walk-in · Referral · Website · Archived`), booking history, inquiry history (`client_inquiries`), `+ Add client / Record inquiry` and `+ Add inquiry note` forms, client detail editor, and archive/restore toggle (`client_saved`, `client_inquiry_added`, `client_archived`, `client_restored`).
  - **A15 Packages & Services** (`AdminPackageController`, `app/Views/admin/packages.php`, Owner only): tabs for `Photo & video`, `Other services`, and `Add-ons`; create, edit, and activate/deactivate packages (with optional fixed down-payment override) and add-ons; changes immediately reflect on `C1` (`package_saved`).
  - **A16 Reports** (`AdminReportController`, `app/Views/admin/reports.php`): Monthly and Daily report views with period navigation, 4 summary tiles (`Bookings`, `Income received`, `Outstanding balances`, `Pending outputs`), booking financial breakdown, verified receipts list, A4 print layout (`data-print`), and CSV export (`/admin/reports/export.csv`).
  - **A17 Users & Access** (`AdminUserController`, `app/Views/admin/users.php`, Owner only): user cards with role and status tags, create Owner/Staff/Crew user (auto-linking `crew_members` for Crew accounts), granular staff permission checkboxes (`view_bookings`, `record_payments`, `verify_payments`, `archive_records`, `manage_messages`), password reset (`must_change_password = 1`), and account activation/deactivation (`user_saved`, `permissions_updated`).
  - **A18 Crew: My Events** (`CrewController`, `app/Views/crew/index.php`, `T7`): read-only view for crew accounts showing next assigned event card, call time, venue, client contact, coverage inclusions, crew-visible studio notes, team roster, equipment checklist, later/past events, zero payment figures, and `403` on all other routes.
  - **A20 Settings, Blocked Dates & Backups** (`AdminSettingController`, `app/Views/admin/settings.php`, `FileController::gcashQr`, Owner only): studio profile, GCash account & QR image upload (`/files/gcash-qr`), booking/capacity rules (`down_payment_mode`, `down_payment_value`, `max_events_per_day`, `default_delivery_days`, `balance_overdue_days`, `event_types`), blocked dates management (`date_blocked`, `date_unblocked`), versioned terms (`terms_versions`), quick replies & bilingual message templates editor, and **Backup now** / backup history / cloud copy confirmation.
  - **A21 Audit & System Logs** (`AdminLogController`, `app/Views/admin/logs.php`, Owner only): centralized append-only viewer across all 6 log streams (`audit_logs`, `booking_changes`, `project_history` + `projects`, `message_log` + system `messages`, `backup_runs`, and `storage/logs/app.log` + `rate_limits`) with filtering by action, entity, actor, change type, date range, and keyword/IP search, plus Print and CSV export (`/admin/logs/export.csv`).
- Tests:
  - Expanded `tests/run.php` to **83 unit/service tests** (0 failures).
  - Added `tests/e2e_phase6.php` (**51 HTTP end-to-end checks**, 0 failures).

## Phase 5: In-app messaging (`C6`, `A19`, `T11`) (2026-11-10)

**Built**
- Models & Services:
  - `Message` (`app/Models/Message.php`): `unreadForAdmin`, `unreadForClientConversation`, `findConversation`, `findByToken`, `findByBookingId`, `messagesFor` (with `since_id` delta query using `idx_messages_conv_id`), `formatMessage`, `lastSeenIdForSender`, `quickReplies`, `inboxCounts`, `inboxList` (filter chips `all · unread · open · closed · inquiries` and search across client/guest names, mobile numbers, booking references, and message text).
  - `MessageService` (`app/Services/MessageService.php`): `postSystem`, `ensureBookingConversation`, `startInquiry` (generates 64-char hex token, stores only SHA-256 hash in `conversations.access_token_hash`), `sendMessage` (up to 1,000 chars + optional ≤ 2 MB image attachment), `markRead` (`Sent` -> `Seen` via `read_at` + `conversation_read` audit logging), `setStatus` (`open`/`closed` with `conversation_closed`/`conversation_reopened` audit logging), `linkConversation` (links pre-booking inquiry to a booking or client, merging messages if the booking already has a thread, and logs `conversation_linked`), and `clientOwnsConversation` (strict per-request ownership check).
  - `UploadService::saveChatImage`: enforces the 2 MB chat attachment limit (`CHAT_MAX_BYTES = 2 MB`) with `finfo` + `getimagesize` validation and stores attachments in `storage/chat/`.
- Controllers, Views & Assets:
  - **C6 Client Chat** (`ClientChatController`, `app/Views/client/chat.php`): pre-booking inquiry form with hidden honeypot (`website`), RA 10173 privacy consent, rate limiting, bookmarkable `/chat/t/{token}` private link with Copy button, `/booking/{ref}/chat` booking thread view, and sticky message composer with photo attachment preview.
  - **A19 Admin Messages Inbox & Thread** (`AdminMessageController`, `app/Views/admin/messages_index.php`, `app/Views/admin/messages_show.php`): searchable inbox with filter chips and unread badges, thread view with booking summary card (links to `A6` and `A12`), pre-booking inquiry linking sheet, quick-reply chips (`quick_replies`), Close/Reopen controls, Copy for Messenger, Print thread, and plain-text transcript export (`/admin/messages/{id}/export`).
  - **Light Polling & AJAX API** (`ApiMessageController`, `public/assets/js/chat.js`, `public/assets/js/app.js`): `/api/conversations/{id}/messages?since_id=N` (4.5s visible polling, 20s hidden/idle backoff, pauses offline), `POST /api/conversations/{id}/messages` (optimistic bubble + retry on failure, 10 msgs/min rate limit), and `/api/unread` global badge polling.
  - **Private Chat Attachment Streaming** (`FileController::chatAttachment` at `/files/chat/{id}`): serves `storage/chat/` images only to the owning client or authorized admin/staff; crew role receives 403.
- Tests:
  - Expanded `tests/run.php` to **79 unit/service tests** (0 failures).
  - Added `tests/e2e_chat.php` (**42 HTTP end-to-end checks** covering Scenario `T11`: inquiry creation, honeypot, `<script>` XSS escaping, IDOR/token isolation, crew 403s, unread counts, two-way polling, Sent/Seen receipts, valid/invalid/oversized attachments, close/reopen, inquiry-to-booking linking, transcript export, payment-verify system messages, and rate limiting).

## Phase 4: Admin operations (T3, T4, T5) (2026-11-10)

**Built**
- Services:
  - `BookingService`: direct booking creation with instant down payment verification and receipt issuance (`createDirect`), transactional reschedule with `date_locks` mutex, date capacity assertion, cancellation with verified down payment forfeiture (`is_forfeited = 1`), internal notes logging.
  - `CrewService`: roster loading, schedule inspection (`getScheduleForDate`), multi-event shortage detection (`has_shortage`), overlapping time conflict hard blocking (`CrewOverlapConflictException`), equipment checklist persistence (`saveEquipment`).
  - `ProjectService`: linear stage lifecycle (`booked -> covered -> editing -> ready -> released`), editing checklist (`editing_done`, `rendering_done`, `album_done`), overdue deliverable detection (`due_date < today AND stage != released`), handover metadata logging (RAW & EDITED paths, local & cloud backup timestamps, release channel, recipient), release warning evaluation (unpaid balance, missing cloud copy) with override confirmation, booking auto-completion on ₱0 balance.
  - `MessageTemplateService`: 4 bilingual templates (Tagalog / English) with dynamic placeholder substitution (`[Client name]`, `[Event]`, `[Event date]`, `[Balance]`, `[Reference no.]`, `[Studio]`), alias normalization, dispatch logging (`message_log`), direct thread posting into conversation.
  - `DashboardService`: 4 live metric tiles (events today, payments to verify, balances due, outputs overdue), 6 studio alert rules (crew shortage, overdue outputs, upcoming balance due, unverified payments, stale bookings, old backup warning), backup status indicator.
  - `CalendarService`: month grid data mapping daily capacity, pending requests badge, blocked date reasons; day schedule inspection panel.
- Models:
  - Expanded `Booking` with helper methods for direct creation, cancellation forfeiture, rescheduling history, and internal notes persistence.
- Controllers & Views:
  - **A2 Admin Dashboard** (`AdminDashboardController`, `app/Views/admin/dashboard.php`): live metric cards, "Needs attention" alert box, backup status bar, role-based navigation.
  - **A3 Booking Calendar** (`AdminCalendarController`, `app/Views/admin/calendar.php`): month grid with capacity status (open, full with red outline, pending dashed indicator, blocked date with reason), interactive day inspection drawer/panel with event list and direct "+ Add booking on this date" button.
  - **A5 New Booking Entry** (`AdminBookingController::newBookingForm` & `createNewBooking`, `app/Views/admin/booking_new.php`): walk-in / call / messenger entry, server-side availability check + `/api/availability/check`, "Down payment received now" direct booking checkbox with instant OR receipt issuance (`OR-YYYY-NNNN`) and project creation, client auto-matching by normalized mobile.
  - **A6 Booking Details Hub** (`AdminBookingController::show`, `app/Views/admin/booking_show.php`): enhanced tabs (Overview, Payments, Crew, Project, Files, Notes), internal staff notes form with optional crew visibility, change history audit timeline.
  - **A9 Crew & Equipment Assignment** (`AdminCrewController`, `app/Views/admin/crew.php`): date-based crew assignment matrix, prominent red shortage alert banner on multi-event days transitioning to green "All Events Covered" when staffed, hard rejection error banner on overlapping event assignments, non-overlapping same-day warning tag, equipment item checklist.
  - **A10 Project Tracker** (`AdminProjectController`, `app/Views/admin/projects.php`): stage filter chips, overdue deliverable alert badges, stage progression form (`Booked -> Covered -> Editing -> Ready -> Released`), editing checkboxes (`editing_done`, `rendering_done`, `album_done`).
  - **A11 Files & Handover Log** (`AdminProjectController::showFiles` & `saveFiles`, `app/Views/admin/project_files.php`): directory paths (RAW/EDITED), local backup & cloud copy flags with timestamps, handover method & recipient, release warnings (missing backup, unpaid balance) with required confirmation override, auto-marks booking `completed` when fully paid.
  - **A12 Client Message Templates** (`AdminMessageTemplateController`, `app/Views/admin/messages_templates.php`): preview of 4 bilingual templates (`booking_confirmed`, `balance_reminder`, `output_ready`, `reschedule`), language switch (Tagalog / English), live placeholder substitution, Copy for Messenger button, Mark as sent logging, Send in client chat action.
  - **A13 Reschedule or Cancel** (`AdminBookingController::showChangeForm` & `applyChange`, `app/Views/admin/booking_change.php`): free reschedule to open date with capacity check and system chat notification, hard rejection if new date is full/blocked/past; cancellation workflow enforcing non-refundable deposit policy (down payment marked `is_forfeited = 1`, no refund), date capacity freed immediately, requires explicit confirmation checkbox.
  - **API Availability** (`ApiAvailabilityController`, `public/assets/js/admin_booking.js`): JSON endpoint `/api/availability/check` consumed by client JS without violating strict CSP.
- Tests & Quality Assurance:
  - `tests/run.php`: expanded from 34 to 73 unit and service tests covering all Phase 4 business logic (T3, T4, T5, A2, A3, A5, A6, A9, A10, A11, A12, A13).
  - `tests/e2e_admin.php`: comprehensive end-to-end HTTP test suite (117 checks) testing admin authentication, CSRF handling, scenario T3, scenario T4, scenario T5, and verification of all 6 log streams.
  - Strict Content Security Policy (`script-src 'self'; style-src 'self'`) verified across all Phase 4 views (0 inline scripts, 0 inline styles, 0 on* event handlers).
  - Zero UTF-8 BOM verification across all new PHP files (`declare(strict_types=1);` as first statement).

## Phase 3: Payments and confirmation (2026-11-09)

**Built**
- Services: `PaymentService` (`duplicatesOf`, transactional `verify` with date-lock re-validation, `reject`, `record` balance/other payments, sequential `issueReceiptNo` `OR-YYYY-NNNN`, `createProject` on confirmation), `DocumentService` (`forBooking`, `receipt` with remaining balance after payment, `messengerText` plain-text export), `MessageService` (`postSystem` into booking conversation).
- Models: expanded `Booking` (`findById`, `payments`, `addons`, `changes`, `crew`, `notes`, `pendingPaymentsQueue`, `ledger`).
- Controllers & Views:
  - **A7 Payments to Verify** (`AdminPaymentController`, `app/Views/admin/payments.php`): queue with count, client/package/date/ref/amount, zoomable screenshot bottom sheet, waiting-requests notice (`N other requests waiting for this date`), duplicate GCash reference warning box + required acknowledgement checkbox (`ack_duplicate`), Verify & confirm button, and Reject bottom sheet (unclear / amount mismatch / other).
  - **A8 Confirmation & Official Receipt** (`AdminDocumentController`, `ClientStatusController::confirmation`, `app/Views/admin/confirmation.php`): print-friendly A4 sheet (`@media print` hides nav/buttons), tabs for **Booking confirmation** and **Official receipt** (with receipt selector when a booking has multiple verified payments), **Print** button (`data-print`), and **Copy for Messenger** button (`data-copy`). Clients can view/print their Booking Confirmation from C5 once confirmed.
  - **A6 Booking Details Hub** (`AdminBookingController::show`, `app/Views/admin/booking_show.php`): header card with status and balance-due tags, tabs (`Overview · Payments · Crew · Project · Files`), full **Payments** tab with **Record balance payment** bottom sheet, **Overview** tab with history timeline, and **Archive / Restore** confirmation sheet (BR-8).
  - **A4 Bookings Ledger** (`AdminBookingController::index`, `app/Views/admin/bookings.php`): search by name, date, mobile, event type or reference code, filter chips (`All · Pending · Confirmed · Balance due · Completed · Archived`), balance display, and status tags.
- Tests: added Phase 3 unit tests to `tests/run.php` (34 total, 0 failed) and created `tests/e2e_payments.php` (18 HTTP end-to-end checks covering T2, T6, T8, T9, A4, A6, A7, A8, and C5 re-upload).

## Phase 2: Client booking path (2026-11-09)

**Built**
- Services: `AvailabilityService` (`reason`, `isDateAvailable`, `confirmedCount`, `pendingCount`, `monthMap`, `lockAndAssertOpen` using `date_locks` row locking), `UploadService` (`finfo` + `getimagesize` verification, random hex filenames in `storage/receipts/`), `BookingService` (`downPayment` 30% rounded up to nearest ₱50, `nextNumber`, `nextReference`, `createPending`, `addPaymentProof`).
- Models: `Package`, `Booking`, `Message`.
- Screens: **C1 Home & Packages** (with package bottom sheets and other-services inquiry sheet), **C2 Check Availability** (server-rendered month grid, crossed-out unavailable/past/blocked dates, result card), **C3 Booking Details** (Step 1 of 2, server date re-validation, RA 10173 consent), **C4 Summary & GCash Payment** (`pay.js` client-side canvas image compression to 1600px JPEG + preview, non-refundable down payment policy checkbox), **C5 Booking Status & Lookup** (rate-limited ref + mobile lookup, 3-step progress, rejection box + screenshot re-upload), and `FileController` (authenticated serving of private receipt screenshots).
- Tests: `tests/run.php` (29 tests) and `tests/e2e_booking.php` (40 HTTP checks for T1 flow, IDOR protection, fake image rejection).

## Phase 1: Foundation (2026-11-09)

**Built**
- Folder structure, `.htaccess` rules (root → `public/`, deny rules on private folders), `.gitignore`.
- `config/config.sample.php` (DB, `demo_date`, `base_path` auto-detection, session idle timeout).
- Core: front controller, `Router` (digit-only `{id}`, 404/405), `DB` (PDO, prepared statements only, transactions), `Session` (HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, idle timeout), `Auth` (roles + staff permission flags, re-checked on every request), `Guard` (route access rules), `Csrf`, `Validator` (plain-language messages, PH mobile numbers, strong passwords), `Flash` (toasts + old input), `RateLimiter`, `Audit`, `Settings`, `Clock` (demo date), `View`.
- Security headers: strict CSP (no inline scripts or styles), X-Frame-Options, Referrer-Policy, `Cache-Control: no-store` on pages. Friendly 403/404/405/500 pages and a "Session expired" page.
- Layouts: client (teal, top bar + 3-item bottom nav + "Chat with us" button), admin (blue, top bar with unread-message badge + 5-item bottom nav that becomes a left sidebar at ≥ 1024 px), crew (single screen), auth.
- One CSS file (~24 KB) with the mobile components: top bar, bottom nav/sidebar, cards, tiles, tags, chips, buttons, bottom sheets, toasts, alerts, forms, calendar grid, print styles. One JS file with sheets, toasts, show/hide password, copy to clipboard and fetch helpers.
- Screens: **A1 Login** (throttled: 5 failures per user / 20 per IP in 15 min), **change password**, **More menu** (filtered by permission), **privacy notice**, and shells for **C1, A2, A18**. Every other nav target shows a "built in Phase N" placeholder, so no link is broken.
- Database: full `schema.sql` (29 tables, FKs, indexes), `seed.sql` with the prototype's sample data, `install.php` (creates config, DB, tables, seed, storage folders, sample receipt images).
- Tests: `tests/run.php` (19 tests) and `tests/smoke_http.php` (32 HTTP checks: roles, redirects, 403s, CSRF, headers). Both pass on `php -S` and XAMPP Apache.
