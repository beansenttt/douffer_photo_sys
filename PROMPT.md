# Douffer PhotoSys: Claude Code Build Prompt (v2)

> **How to use (for the team):**
> 1. Create an empty project folder, e.g. `douffer-photosys/`.
> 2. Copy these three files into `docs/reference/`: `SAD_Preliminary_Investigation_Format.docx`, `Business_Case.docx` (or `.doc`) and `Douffer-PhotoSys-Paper-Prototype-Guide.pdf`.
> 3. Save this file as `PROMPT.md` in the project root, open Claude Code there, and say: **"Read PROMPT.md and the files in docs/reference/, then follow it. Start with your plan."**
>
> Everything Claude Code needs is also written below, so it still works if the reference files are missing.

---

## 1. Mission

You are the lead full-stack developer for **Douffer PhotoSys**, the *Douffer Photography Management and Booking System*. It is a **mobile-first web application** for a small photo and video studio in Nasugbu, Batangas, Philippines (owner: Mr. Jeffrey D. Madrigal).

This is a **BSIT 3rd-year Systems Analysis and Design project**. Deliver a **working, demo-ready prototype** that:

- runs on **localhost (XAMPP or `php -S`)** and can be deployed to cheap shared hosting later,
- implements the **paper prototype** (23 screens), the documented requirements, and the extra features in this brief (including an **in-app web messaging system**, which the professor asked for),
- is simple enough that the students can read it, explain it, and defend it to a panel.

**Working agreement**

- Reply first with a **short plan**: the folder tree, the route map, and your assumptions. Then build **phase by phase** (Section 14). Stop at the end of each phase and report what was built, how to test it, assumptions made, and what's next.
- Make sensible decisions instead of asking many questions. Where the documents leave a question open, use the **default in Section 3.3** and make it a **setting the owner can change**, so nothing is hard-coded.
- Prefer boring, readable code over clever code. Comment the tricky logic (availability check, payment verification, reschedule/cancel, polling endpoint).

---

## 2. Sources of truth and precedence

1. **Business rules and requirements** come from the Business Case and the Preliminary Investigation Report (PIR). They win over everything else.
2. **Screens and flows** come from the **Paper Prototype Guide** (screen IDs `C1–C5`, `A1–A18`). Build every one of those screens. **The visual design may differ slightly from the sketches** (spacing, colors, components, exact layout), but each screen must keep its purpose, its data, and its navigation. Where you deviate, note it in `docs/DEVIATIONS.md` in one line each.
3. **This brief** adds: mobile-first rules, the messaging system, a settings screen, and the extra features. Where this brief and the prototype disagree on *behavior*, this brief wins. Where they disagree on *layout*, use your judgment and keep the prototype's intent.

Prototype colors: **teal** for the client portal, **blue** for the admin side. Keep that distinction, but you may choose the exact shades and fonts. Use a clean, professional, photography-studio feel.

---

## 3. Business context and rules

### 3.1 The studio today (the As-Is)

The owner runs everything from his phone: client details, dates, packages, down payments and balances in the **Notes app**; inquiries and **GCash screenshots in Facebook Messenger**; plus phone calls and walk-ins. The studio advertises photo, video, invitations, photobooth, give-aways, emcee, catering and a makeup artist, but **real demand is almost entirely photo and video coverage**.

Problems to solve: manual date checking and double-booking risk; balances scattered across notes and chats; no written confirmation or receipt; no shared crew roster; no defined file storage or backup (a lost phone loses everything); no tracking of editing and delivery deadlines. The owner's top priorities are **keeping client files safe** and **releasing edited outputs on time**.

### 3.2 Documented business rules (enforce these in code)

| ID | Rule |
|---|---|
| BR-1 | A reservation is **locked only when the down payment is received and verified** by the owner or authorized staff. |
| BR-2 | A **verified down payment is non-refundable** on cancellation. The system records it as **forfeited**; it never offers a refund. |
| BR-3 | **Rescheduling is free** if the new date is open (passes the availability check). |
| BR-4 | **Double-booking must be impossible**: the availability check runs on the server inside a transaction, not only in JavaScript. |
| BR-5 | Clients pay by **GCash**. They provide a **reference number and a screenshot**, which go into a **verification queue**. The system never marks a payment verified automatically. |
| BR-6 | **Facebook Messenger and GCash stay** as the client-facing channels. The system keeps the records behind them, and also offers its own in-app chat (Section 8). |
| BR-7 | Project stages are **Booked → Covered → Editing → Ready → Released**. Editing has a checklist: editing, rendering, album layout. |
| BR-8 | Records are **archived (with restore), never hard-deleted** from the UI. |
| BR-9 | Customer data, contact details and payment screenshots must follow the **Data Privacy Act of 2012 (RA 10173)**. |
| BR-10 | The system **logs where files are kept** (folder names, backup done, cloud copy done) and logs how and when outputs are released. It does **not** store the photo and video files themselves. Only GCash screenshots and chat image attachments are uploaded. |

### 3.3 Open questions: use these defaults and make them settings (Settings screen A20)

| Open question in the documents | Default to implement | Setting |
|---|---|---|
| Down payment: fixed amount or percentage? | **Percentage of the total, default 30%**, rounded up to the nearest ₱50. Can be switched to a fixed amount per package. | `down_payment_mode`, `down_payment_value`, per-package override |
| When is the balance due? | **On or before the event date.** Flag *Balance due* once the down payment is verified and balance > 0. Flag *Overdue balance* when the event date has passed and balance > 0, or N days after release. | `balance_overdue_days` (default 0) |
| How many events per day? | **Max 2 confirmed events per day.** A day with 2 events shows a red outline and a crew warning if crew is short. | `max_events_per_day` |
| Does a request hold the date before payment is verified? | **No.** Only a verified down payment locks it (BR-1). Pending requests show as dashed on the admin calendar. | none |
| Package names, inclusions, rates | Seed **clearly labelled sample packages** (the documents give no rates). The owner edits them in A15. | n/a |
| Default delivery time after the event | **14 days**, editable per project. | `default_delivery_days` |
| How are outputs released? | Pickup, Messenger link, Other (as in the prototype). | n/a |
| Roles | **Owner** (full), **Staff** (permission checkboxes), **Crew** (view-only A18). | n/a |
| Who sends client messages? | Owner and staff with permission. | n/a |

---

## 4. Tech stack and architecture

| Layer | Choice |
|---|---|
| Backend | **PHP 8.0+** (keep compatible with typical shared hosting), `declare(strict_types=1)`, **PDO with prepared statements only**. Small hand-written MVC: front controller `public/index.php`, a tiny router, controllers, models/repositories, PHP view templates. **No framework and no Composer dependency is required.** If you really need one, ask first. |
| Database | **MySQL / MariaDB** (XAMPP default), InnoDB, utf8mb4, 3NF, foreign keys. Money as `DECIMAL(10,2)`. Timezone **Asia/Manila**. |
| Frontend | Server-rendered HTML + **one small custom CSS file** + **vanilla JavaScript** (small modules, no jQuery, no React, no build step). Progressive enhancement: forms must still work if JS fails, except the chat live-update. |
| Server | Apache (XAMPP) or `php -S localhost:8000 -t public` for quick dev |
| Auth | PHP sessions, `password_hash()`/`password_verify()`, CSRF tokens on every state-changing request, role + permission checks on every route |
| Files | Receipt screenshots and chat attachments live in `storage/` **outside the web root**, served only through an authenticated PHP route. Add `.htaccess` deny rules as a fallback for shared hosting. |
| Currency / locale | **₱** with thousands separators, dates like "Sat, Dec 12, 2026", 12-hour times, Philippine mobile numbers (`09xx xxx xxxx` or `+63`) |

Suggested structure:

```
douffer-photosys/
├─ public/                 # web root: index.php, assets/css, assets/js, manifest.json, sw.js, icons
├─ app/
│  ├─ Core/                # Router, DB, Auth, Csrf, Validator, View, Flash, RateLimiter, Audit
│  ├─ Controllers/         # Client*, Admin*, Crew*, Api*
│  ├─ Models/              # repositories: Booking, Payment, Package, Crew, Project, Message...
│  ├─ Services/            # AvailabilityService, PaymentService, BookingService, ProjectService, BackupService
│  └─ Views/               # layouts/, client/, admin/, crew/, partials/
├─ config/                 # config.sample.php (commit), config.php (git-ignored)
├─ database/               # schema.sql, seed.sql, install.php (CLI installer)
├─ storage/                # receipts/, chat/, backups/, logs/  (outside public/, git-ignored)
├─ tests/                  # run.php: simple CLI tests for the business rules (no PHPUnit needed)
└─ docs/                   # reference/, DEVIATIONS.md, ERD.md, DFD.md, UseCases.md, TestCases.md, TRACEABILITY.md, CHANGELOG.md
```

Provide `php database/install.php` (creates DB, imports schema and seed) **and** plain `schema.sql`/`seed.sql` for phpMyAdmin.

---

## 5. Mobile-first requirements (this is a hard requirement, not polish)

The owner works from his phone, mostly on **cellular data**, often on event days. Design **phone-first (360 px wide)** and let the same layout widen on tablet and desktop.

**Layout and navigation**
- `<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">` and respect `env(safe-area-inset-*)`.
- **Client portal:** top bar + **bottom nav with 3 items** (Home · Availability · My Booking).
- **Admin:** top bar + **bottom nav with 5 items** (Home · Calendar · Bookings · Projects · More). On **≥ 1024 px** the bottom nav becomes a **left sidebar** and content gets a sensible max width.
- **Crew:** single screen, no nav (A18).
- Back arrow on every inner screen. A **sticky bottom action bar** for the primary action on long forms (e.g. "Review and pay down payment").
- Tables become **cards** on phones. Never require horizontal scrolling (except inside a calendar, which must fit 360 px without scrolling).

**Touch and input**
- Tap targets **≥ 44×44 px**, ≥ 8 px spacing. No hover-only interactions.
- Body text **≥ 16 px** (this also prevents iOS zoom on input focus). Labels above fields. Clear error text under the field.
- Right input types: `type="tel"` + `inputmode="numeric"` for mobile and reference numbers, `type="date"`/`"time"` where it helps, `autocomplete` attributes, `enterkeyhint`.
- Screenshot upload uses `<input type="file" accept="image/*">` so phones offer **camera and gallery**. **Compress the image client-side** (canvas, max ~1600 px, JPEG ~0.8) before upload to save data. The server still validates everything.
- Dropdowns and date pickers must work well with the native phone pickers.

**Performance (target: usable in < 2.5 s on mid-range phone + 4G)**
- Budget: **HTML < 50 KB** per page, **CSS ≤ 30 KB**, **JS ≤ 60 KB total** (uncompressed is fine), **no frameworks, no web-font downloads** (system font stack), no large images. Lazy-load images below the fold. Enable gzip and cache headers for static files.
- Receipt thumbnails should be small, generated versions. Full size loads only on tap.
- Chat polling must be light (Section 8.3).

**Installable and flaky-network friendly (PWA-lite)**
- `manifest.json` (name, icons, `display: standalone`, theme color) so it can be **added to the home screen**.
- A small **service worker** that caches the **static shell only** (CSS, JS, icons) and shows a friendly **offline page**. **Never cache authenticated pages or private data.**

**Accessibility and robustness**
- Color contrast AA, visible focus states, semantic HTML, `aria-live` for toasts and new chat messages, status never conveyed by color alone (tags also have text).
- Support current Chrome, Safari (iOS), Samsung Internet, and Firefox. No features that need a recent browser without a fallback.

**Verify it.** Check every screen at **360×640, 390×844, 768×1024 and 1280×800**. If you can run a headless browser (Playwright/Chrome), take screenshots of the key screens at 360 px and fix overflow. If not, list the manual checks in the README. Add a Lighthouse-style checklist to `docs/TestCases.md`.

---

## 6. Roles and access

| Role | Who | Access |
|---|---|---|
| **Client** (no account) | Public visitor | Portal `C1–C6`. Sees own booking/chat only via **reference no. + mobile no.** (or an inquiry-chat token). |
| **Owner** | Mr. Madrigal | Everything, including users, settings, backups, verify payments, archive/restore |
| **Staff** | Studio assistant | Everything the owner allows with **permission flags**: `view_bookings` (default on), `record_payments` (default on), `verify_payments` (default off), `archive_records` (default off), `manage_messages` (default on) |
| **Crew** (on-call photographer/videographer) | Freelancers | Login → `A18` only: their own assigned events, no payments, no other clients' data, **no client chats** |

Every route checks role **and** permission. A forbidden request returns a friendly 403 page, not a blank screen. Seed one owner, one staff, and two crew accounts. Print the credentials in the README and show a **"change default passwords"** notice on the owner dashboard until they are changed.

---

## 7. Screens to build (23 from the paper prototype + 5 added)

Build **all** of these. "CORE" screens come first. Keep the screen IDs in the code (route names, view file names, a comment at the top of each view) so the team can trace the prototype to the code.

### 7.1 Client portal (teal, public)

| ID | Route (suggested) | Purpose and must-have content |
|---|---|---|
| **C1** Home & Packages | `/` | Studio headline; main button **Check availability / Book now**; package cards (Photography, Videography, Photo + Video) from the DB with inclusions and rate; "Other services" chips (invitations, photobooth, give-aways, emcee, catering, makeup artist) that show "Please message the studio to ask about this service" with a link to the **chat (C6)** or Messenger. Tapping a package shows full inclusions and rate (bottom sheet). A **floating "Chat with us" button** opens C6. |
| **C2** Check Availability | `/availability` | Month calendar (◀ ▶). Unavailable dates greyed and crossed out and not selectable (blocked, or confirmed events ≥ max per day). Selected date highlighted; legend; result card "Dec 15, 2026 · Available"; **Continue** carries the date to C3. Tapping a blocked date shows "Sorry, this date is not available. Please choose another date." |
| **C3** Booking Details | `/book` | Step 1 of 2. Full name\*, mobile\*, Messenger name/link, event type\* (dropdown), package\* (dropdown), event date (prefilled), start/end time, venue\*, special requests, **privacy consent checkbox\***. The date is **re-validated on the server**. Button: Review and pay down payment. |
| **C4** Summary & GCash Payment | `/book/pay` | Summary card (package, date, venue, total, down payment, balance); GCash box (QR image, account name, number, amount, all from Settings); **GCash reference no.\*** (required) + **screenshot upload\*** (with client-side compression and thumbnail preview); checkbox\* "I understand the down payment is non-refundable if I cancel. Rescheduling is free if the new date is open." (store timestamp and the policy text version); **Submit booking**. On submit create the booking (`pending`), the booking conversation, and a `pending` payment that shows up in **A7**. |
| **C5** Booking Status | `/booking/{ref}` after lookup | Big check mark; **reference no.** (`DPS-2026-0001` format); 3-step progress (Request sent → Payment being checked → Booking confirmed); one sentence on what happens next; **View booking confirmation** (disabled until confirmed); **Message the studio** (opens C6); show balance, payment status, and project stage once confirmed; show the rejection message and a re-upload option if the payment was rejected. **My Booking tab** goes to a lookup form (reference no. + mobile no.) with rate limiting. |
| **C6** Client Chat *(added)* | `/chat` | In-app messaging, see Section 8. |

### 7.2 Admin side (blue, login required)

| ID | Route (suggested) | Purpose and must-have content |
|---|---|---|
| **A1** Login | `/admin/login` | Logo, username, password (with show/hide), **Log in**, "Forgot password? Ask the owner." Throttle failures. Owner/staff → A2, crew → A18. |
| **A2** Dashboard | `/admin` | Today's date; **4 tiles** (Events today · Payments to verify · Balances due · Outputs overdue), each linking to its screen/filter; **+ New booking**; **Needs attention** cards (same-day crew gap = *Conflict*, overdue output = *Overdue*, balance due this week = *Balance due*) with empty state "Nothing needs attention today ✓"; **backup status line** ("Last backup: … ✓", with warning color if older than 7 days); **unread-messages badge**. Top bar has a message icon with the unread count on every admin screen. |
| **A3** Booking Calendar | `/admin/calendar` | Month grid (confirmed = dot; 2+ events = red outline; pending-only = dashed outline; blocked dates marked); day panel listing events (time, client, status tag); **+ Add booking on this date** (→ A5 with date prefilled); tap an event → A6. Must fit 360 px. |
| **A4** Bookings Ledger | `/admin/bookings` | **Search** (name, date, reference no.), filter chips (All · Pending · Confirmed · Balance due · Completed · Archived), a card per booking with status tag and balance. Fast (indexed, paginated). |
| **A5** New Booking | `/admin/bookings/new` | For Messenger/call/walk-in/referral inquiries. Chips for **contact channel**, client name, mobile, event type, date/time with a live **availability tag** (AJAX check, server re-validates), venue, package, add-on, checkbox **"Down payment received now"** (reveals amount + GCash ref no. and records a verified payment). Save → A6. Reuse an existing client if the mobile matches. |
| **A6** Booking Details *(hub)* | `/admin/bookings/{id}` | Header card (client – event, date/time, venue, ref no., status tag). **Tabs: Overview · Payments · Crew · Project · Files.** Payments tab: total, down payment (GCash ref + date), balance due, **Record balance payment**. Buttons: **Print receipt**, **Message client**, links **Reschedule · Cancel · Archive** (⋮ menu). Archive shows a confirm sheet: "Archive this booking? You can restore it later." Shows a history timeline (status changes, payments, reschedules). |
| **A7** Payments to Verify | `/admin/payments` | Queue with count; card per payment (client, package, date, screenshot thumbnail, GCash ref, amount); **Verify** and **Reject** (reject reason sheet: screenshot unclear / amount doesn't match / other). **Verify** re-runs the availability check in a transaction, then confirms the booking, issues an official receipt number, posts a system message in the chat, and opens A8. **Flag duplicate GCash reference numbers** with a visible warning. Tap the thumbnail for a zoomed view. Empty state "No payments waiting." |
| **A8** Confirmation & Receipt | `/admin/bookings/{id}/confirmation` | Tabs **Booking confirmation** and **Official receipt**. Print-friendly sheet (A4 print CSS, hides nav): studio name and address, ref no., client, event, date/time, venue, package, inclusions, total, down payment, GCash ref, balance, **Terms** line. Buttons **Print** and **Copy for Messenger** (plain-text copy). The client sees the confirmation (not the receipt list) via C5. |
| **A9** Crew & Equipment | `/admin/crew?date=…` | Date line with event count; **red banner for same-day events without full crew**; per-event crew with **+ add** (lead/backup, owner counts as a crew member); on-call roster with **Free/Busy** tags for that date; **equipment checklist** (cameras, lenses, lights, memory cards/batteries: seeded generic items, editable); **Save assignment**; banner turns green "All events have crew". **Block** assigning a person to two events whose times overlap. **Warn** for same-day non-overlapping events. |
| **A10** Project Tracker | `/admin/projects` | Stage chips with counts (Booked · Covered · Editing · Ready · Released); sorted by due date; cards with due date and **Overdue** tag; Editing checklist (editing/rendering/album layout); **Move to Ready** (with confirm); **Open files and handover** → A11. Moving stages writes timestamps to the history. |
| **A11** Files & Handover Log | `/admin/projects/{id}/files` | Text fields for **raw folder** and **edited folder** names, checkboxes **local backup done** / **cloud copy done**, handover record (Pickup / Messenger link / Other; date released; received by), **Mark as released**. Warn "No cloud copy yet. Release anyway?" and, if a balance remains, "Balance not fully paid." (allow the owner to override). Does **not** upload media. |
| **A12** Client Messages (templates) | `/admin/bookings/{id}/messages/templates` | Template chips (Booking confirmed · Balance reminder · Output ready · Reschedule; admin-editable, Filipino/English) with placeholders filled ([Client name], [Event], [Event date], [Balance]); editable preview; **Copy message** (for Messenger), **Mark as sent**, and **Send in app chat** (posts into the booking's conversation, A19). Sent history. |
| **A13** Reschedule or Cancel | `/admin/bookings/{id}/change` | Radio: Reschedule / Cancel. Reschedule: new date + live availability banner ("Date is open ✓ Rescheduling is free." or "That date is not open. Please choose another date.") + reason + **Confirm** (applies BR-3, keeps history, notifies via system message). Cancel: sheet "Cancel this booking? The down payment (₱…) is non-refundable." → marks the booking `cancelled`, down payment **forfeited**, frees the date, keeps the record. |
| **A14** Clients & Inquiries | `/admin/clients` | Search; client cards with mobile, contact channel, inquiry history, bookings; **+ Add inquiry note**. |
| **A15** Packages & Services | `/admin/packages` | Tabs Photo & video / Other services; package cards (name, inclusions, rate, Active tag, Edit); add/edit form (name, category, inclusions, rate, down-payment override, active toggle); add-on services. Changes appear in C1. |
| **A16** Reports | `/admin/reports` | Daily / Monthly; month selector; tiles (Bookings, Income received, Outstanding balances, Pending outputs); booking list; **Print report** (print CSS) and **Export CSV**. |
| **A17** Users & Access | `/admin/users` | User cards (role, Active tag), Reset password, Deactivate, add user; staff **permission checkboxes**; Save. Owner only. |
| **A18** Crew: My Events | `/crew` | Read-only. Next assigned event card (date, time, client, venue, package), event details (client contact, call time, inclusions, owner notes), later events, "View-only. Payments and other records are hidden." Empty state "No events assigned yet." |
| **A19** Messages Inbox & Thread *(added)* | `/admin/messages`, `/admin/messages/{id}` | Admin side of the chat, see Section 8. |
| **A20** Settings & Blocked Dates *(added)* | `/admin/settings` | Studio info; GCash name/number/QR upload; down payment rule; max events per day; delivery days; balance overdue days; terms text (versioned); **blocked dates** (add/remove with reason); change password; **Backup now** and backup history; quick-reply/template editing. Owner only. |
| **A21** Audit & System Logs *(added)* | `/admin/logs` | Centralized viewer & export for all system logs (**Owner only**): Tabs for **Security & Audit Logs** (`audit_logs`), **Booking History Logs** (`booking_changes`), **Project Stage & Handover Logs** (`project_history` + `projects`), **Notification Logs** (`message_log`), **Backup Logs** (`backup_runs`), and **System Error Logs** (`storage/logs/app.log`). Filter by log type, user/actor, action, entity (`booking`, `payment`, `project`, `conversation`, `user`, `setting`), or date range; search by keyword or IP; **Print** and **Export CSV**. Append-only (cannot be edited or deleted from the UI). |

**Also needed:** the "More" menu (Clients, Packages and services, Reports, Users and access, Messages, Settings, Audit & system logs, Log out); empty states; error and 403/404 pages; flash/toast messages ("Booking saved", "Copied ✓", "Booking rescheduled"); confirmation sheets for destructive actions. In the prototype these are paper "slips". Build them as real UI states.

---

## 8. In-app messaging system (the professor's request)

**Goal:** conversations with clients live **inside the system, next to the booking**, instead of being scattered over Messenger. Keep it simple and reliable. **No WebSockets and no paid services** (XAMPP and shared hosting).

### 8.1 What it does

- **Booking conversation:** one thread per booking, created automatically with the booking. Client and studio can write in it.
- **Pre-booking inquiry chat:** the floating "Chat with us" button on public pages (C6). The visitor gives name + mobile number + the **privacy consent checkbox**, which creates a conversation with no booking. The admin can **link it to a booking or client** later. Rate-limit and add a hidden honeypot field to prevent spam.
- **Client access without an account:** from C5 after the reference-number lookup, or via an inquiry link with a long random token. Store a hash of the token. Validate ownership on **every** request. Never trust an ID in the URL.
- **Admin inbox (A19):** conversations sorted by latest message; **unread badges**; search (name, mobile, ref no.); filters (Unread / Open / Closed); thread view with a **booking summary card** (status, balance, event date) and quick links to A6/A12; **Close/Reopen**; **Link to booking**.
- **Message features:** text (max 1,000 chars), timestamps, **Sent / Seen** (`read_at`), optional **image attachment** (JPG/PNG, ≤ 2 MB, compressed client-side, validated server-side, served through an authenticated route), **quick replies** (admin-editable) and the A12 templates, and **system messages** posted automatically ("Payment verified ✔", "Your booking was rescheduled to Dec 20", "Your output is ready").
- **Notifications (free):** unread badge in the nav, `document.title` shows "(2) New message", optional browser `Notification` if the user allows it, and a **"Copy for Messenger"** button so the owner can still reply through Messenger when needed. No SMS or email API.
- **Crew cannot see client chats.** The owner can add an **internal note** on a booking (visible to the assigned crew in A18 and to staff, never to the client). Store it separately from client messages.
- **Mobile-first UI:** the thread fills the screen, the input bar is **sticky above the keyboard** (use `visualViewport` or `dvh` units), the newest message scrolls into view, send on a button (and Enter on desktop), and large bubbles (client right, studio left on the client side; reverse on the admin side).

### 8.2 Safety, privacy and abuse limits

Escape all output (messages are plain text, never HTML); CSRF on every POST; **rate limit** (e.g. 10 messages per minute per conversation and per IP); length limits; attachments type/size/signature checks; no directory listing; conversation data visible **only** to the owning client, owner, and permitted staff (RA 10173); write read/close/link actions to the **audit log**; include conversations in backups; allow the admin to **export/print** a thread.

### 8.3 Polling design (light on mobile data)

- `GET /api/conversations/{id}/messages?since_id=N` returns **only new messages** as small JSON (`[]` most of the time) plus the unread count.
- The client polls **every 4–5 s while the chat is visible**, **backs off to ~20 s when the tab is hidden** (`document.visibilityState`) or after several empty polls, and **stops when offline** (resumes on the `online` event). A manual refresh is never required.
- Sending uses a normal `fetch` POST (with an optimistic bubble and a retry on failure). A global badge endpoint (`/api/unread`) is polled every ~30 s on admin screens.
- Index `messages(conversation_id, id)` so polling stays cheap.

---

## 9. Business logic specifications

### 9.1 Availability (BR-1, BR-3, BR-4)

```
isDateAvailable(date, excludeBookingId = null):
    if date is in the past                         → false
    if date in blocked_dates                       → false
    count = confirmed bookings on date (status='confirmed', not archived, id != exclude)
    return count < settings.max_events_per_day
```

- **Pending requests never count** toward capacity (BR-1), but the admin calendar shows them dashed, and A7 warns "N other requests are waiting for this date".
- **Race safety:** whenever a booking becomes confirmed (A7 Verify, A5 "down payment received now", A13 reschedule), start a transaction, **lock the date** (e.g. a `date_locks` row per date with `SELECT … FOR UPDATE`), re-run `isDateAvailable`, then write. If it fails, abort with a clear message ("This date just became full. Reschedule or reject.").
- Also warn (not block) when two confirmed events on the same day have **overlapping times**.

### 9.2 State machines

- **Booking status:** `pending → confirmed → completed`, and `pending|confirmed → cancelled`. `archived` is a separate flag. `rescheduled` is a history event, not a status.
- **Derived tags (compute, don't store):** *Balance due* = confirmed and balance > 0; *Overdue balance* per Section 3.3; *Overdue output* = project not released and due date < today.
- **Payment status:** `pending → verified | rejected`. A rejected payment lets the client re-upload from C5. Payment types: `down`, `balance`, `other`; methods: `gcash`, `cash`, `other`. Cancelled bookings keep their verified down payment as **forfeited** (flag).
- **Project stage:** `booked → covered → editing → ready → released`. A project row is created when the booking is confirmed. Every move is timestamped in a history table.

### 9.3 Money

`total = package price + add-ons` (store the price at booking time). `down_payment = per settings/package`. `balance = total − sum(verified payments)`. Show amounts with ₱ and two decimals. Every verified payment gets an **official receipt number** (`OR-2026-0001`). Booking reference format: `DPS-YYYY-NNNN` (sequential per year, unique).

### 9.4 Duplicate / fraud checks (Risk 3)

Reference number is **required**. **Flag duplicates** (same GCash ref used on another payment) with a visible warning in A7 that the owner must acknowledge before verifying. Never auto-approve.

### 9.5 Reschedule and cancel (BR-2, BR-3)

- **Reschedule:** only to a date that passes `isDateAvailable(newDate, excludeBookingId)`. Keep a `booking_changes` history (old/new date, reason, by whom). Notify the client by a system message.
- **Cancel:** require confirmation. Set `cancelled`, mark the down payment **forfeited**, free the date, keep the record, write the history, audit-log it.

### 9.6 Backups (Risk 4)

**Backup now** (A20) generates a `.sql` dump using PDO (or `mysqldump` if available) into `storage/backups/`, offers it as a download, and records it in a `backup_runs` table. The dashboard shows the latest run. Provide a **CLI script** (`php database/backup.php`) and README instructions to schedule it (Windows Task Scheduler / cron). **Cloud copy:** the system cannot reach the owner's cloud by itself, so A11 and A20 record that a cloud copy was **done by the owner** (checkbox + date). Say this honestly in the README. The dashboard shows a warning when the last backup is older than 7 days.

### 9.7 Dashboard "Needs attention" rules

Same-day events with fewer assigned crew than events → *Conflict*. Output overdue → *Overdue*. Confirmed bookings with balance > 0 and event within 7 days → *Balance due*. Unverified payments older than 24 h → highlight. Unread messages older than 1 h → highlight.

### 9.8 Audit logs and system-wide logging (BR-8, BR-9, BR-10, RA 10173)

Maintain complete, tamper-evident, append-only logs across six distinct log streams, and surface them both in context (on the relevant screen) and in the centralized **A21 Audit & System Logs** screen (`/admin/logs`, owner only):

1. **Security & Audit Log (`audit_logs` table via `App\Core\Audit::log()`):**
   - Records `user_id` (or `NULL` for public/client actions), `action`, `entity`, `entity_id`, JSON `details`, client `ip`, and `created_at` (`Asia/Manila`).
   - **Must log at minimum:** login success/failure & lockouts (`login_success`, `login_failed`, `logout`, `password_changed`), client booking creation & lookup failures (`booking_created`, `booking_lookup_failed`), payment proof uploads, verifications (including `duplicate_acknowledged`), rejections, and manual payment recordings (`payment_proof_uploaded`, `payment_verified`, `payment_rejected`, `payment_recorded`), booking reschedules, cancellations, archives, and restores (`booking_rescheduled`, `booking_cancelled`, `booking_archived`, `booking_restored`), crew & equipment changes (`crew_assigned`), project stage changes & release overrides (`project_stage_changed`, `project_released`), conversation read/close/reopen/link actions (`conversation_closed`, `conversation_reopened`, `conversation_linked`), catalog/user/permission/setting/blocked-date updates (`package_saved`, `user_saved`, `permissions_updated`, `settings_updated`, `date_blocked`, `date_unblocked`), and backup runs (`backup_created`, `backup_cloud_marked`).
2. **Booking Change & Policy History Log (`booking_changes` table):**
   - Tracks every lifecycle event on a booking (`created`, `status`, `reschedule`, `cancel`, `archive`, `restore`, `note`) with `old_value`, `new_value`, `reason`, `changed_by`, and `changed_at`. Displayed as the timeline on **A6** and filterable in **A21**.
3. **Project Stage, File Storage & Handover Log (`project_history` + `projects` tables):**
   - `project_history` records every transition across `booked → covered → editing → ready → released` with `old_stage`, `new_stage`, `changed_by`, and `changed_at`.
   - `projects` logs raw/edited folder paths, local backup timestamp (`local_backup_at`), cloud copy timestamp (`cloud_copy_at`), release method (`pickup`, `messenger_link`, `other`), release date (`released_at`), recipient (`received_by`), and staff (`released_by`) on **A10/A11** and in **A21**.
4. **Client Notification & Message Log (`message_log` + `messages` system entries):**
   - `message_log` records every template message prepared, copied for Messenger, or sent in-app on **A12** (`booking_id`, `template_key`, `language`, `channel`, `sent_marked_by`, `sent_at`).
   - `messages` with `sender_type = 'system'` keeps an in-thread log of automated notices (booking received, payment verified/rejected, rescheduled, output ready).
5. **Backup & Data Protection Log (`backup_runs` table):**
   - Logs every manual or scheduled `.sql` dump (`created_at`, `file_name`, `size_bytes`, `type`, `created_by`, `cloud_copy_marked_at`), shown on **A2**, **A20**, and **A21**.
6. **Application Error & Rate-Limit Logs (`storage/logs/app.log` and `rate_limits` table):**
   - Uncaught exceptions and runtime errors are written to `storage/logs/app.log` (never exposed in production HTML) and can be inspected (last N lines, read-only) by the owner in **A21**.
   - `rate_limits` tracks per-IP and per-account throttling windows for logins, booking lookups, payment uploads, and chat messages.

---

## 10. Data model (refine, keep 3NF, add indexes and FKs)

Create `database/schema.sql` and `database/seed.sql`.

- `users` (id, name, username, email NULL, password_hash, role ENUM('owner','staff','crew'), permissions JSON/columns, must_change_password, is_active, last_login_at, created_at)
- `clients` (id, full_name, mobile, mobile_normalized, messenger_name, contact_channel ENUM('messenger','call','walk_in','referral','website'), created_at)
- `client_inquiries` (id, client_id, channel, note, created_by, created_at)
- `packages` (id, name, category ENUM('photo','video','photo_video','other'), description, inclusions, price, down_payment_override NULL, is_active, sort_order)
- `addons` (id, name, price, is_active)
- `bookings` (id, reference_code UNIQUE, client_id, package_id, event_type, event_date, start_time, end_time, venue, special_requests, source_channel, status ENUM('pending','confirmed','completed','cancelled'), total_amount, down_payment_due, is_archived, terms_version, terms_accepted_at, privacy_consent_at, confirmed_at, cancelled_at, created_by NULL, created_at) with indexes on `(event_date, status)`, `reference_code`, `client_id`
- `booking_addons` (booking_id, addon_id, price_at_booking)
- `booking_changes` (id, booking_id, change_type ENUM('reschedule','cancel','status','note'), old_value, new_value, reason, changed_by, changed_at)
- `payments` (id, booking_id, type, method, amount, gcash_reference_no, receipt_path, status ENUM('pending','verified','rejected'), rejection_reason, is_forfeited, receipt_no UNIQUE NULL, verified_by, verified_at, paid_at, created_at), index on `gcash_reference_no`
- `blocked_dates` (id, date UNIQUE, reason)
- `date_locks` (date PRIMARY KEY)
- `crew_members` (id, name, type ENUM('owner','photographer','videographer','other'), phone, user_id NULL, is_on_call, is_active)
- `crew_assignments` (id, booking_id, crew_member_id, role ENUM('lead','backup'), assigned_by, created_at), UNIQUE `(booking_id, crew_member_id)`
- `equipment_items` (id, name, category, is_active) and `booking_equipment` (booking_id, equipment_item_id, is_checked)
- `projects` (id, booking_id UNIQUE, stage ENUM('booked','covered','editing','ready','released'), due_date, editing_done, rendering_done, album_done, raw_folder, edited_folder, local_backup_done, cloud_copy_done, released_how ENUM('pickup','messenger_link','other') NULL, released_at NULL, received_by NULL)
- `project_history` (id, project_id, old_stage, new_stage, changed_by, changed_at)
- `message_templates` (id, key, title, body_tl, body_en, is_active) and `message_log` (id, booking_id, template_key, sent_marked_by, sent_at)
- `conversations` (id, booking_id NULL, client_id NULL, guest_name, guest_mobile, access_token_hash, status ENUM('open','closed'), last_message_at, created_at)
- `messages` (id, conversation_id, sender_type ENUM('client','admin','system'), sender_user_id NULL, body, attachment_path NULL, read_at NULL, created_at), index `(conversation_id, id)`
- `booking_notes` (id, booking_id, body, visible_to_crew, created_by, created_at) for the internal notes
- `quick_replies` (id, title, body, is_active)
- `settings` (key PRIMARY KEY, value) and `terms_versions` (id, version, body, effective_from)
- `audit_logs` (id, user_id NULL, action, entity, entity_id, details, ip, created_at)
- `backup_runs` (id, created_at, file_name, size_bytes, type ENUM('manual','scheduled'), created_by, cloud_copy_marked_at NULL)
- `rate_limits` (key, window_start, hits) or a session/IP-based equivalent

---

## 11. Security and privacy checklist (verify in Phase 6)

- Prepared statements everywhere (no string-built SQL); output escaping on every view; **CSRF** on all POSTs; secure session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` when on HTTPS), `session_regenerate_id` on login; **login throttling**; passwords hashed; strong-password rule for new users; `must_change_password` for seeded accounts.
- **Authorization on every route and every record** (IDOR checks): a client can only open their own booking/chat; crew only their own events; staff only what their permissions allow.
- Uploads: allowlist JPG/PNG/WebP (and PDF only if you add it), size limits, `finfo` MIME check, re-encode images with GD to strip metadata, random filenames, stored outside `public/`, served through an authenticated route with `Content-Disposition`/`X-Content-Type-Options: nosniff`.
- Security headers (CSP without inline script if practical, `X-Frame-Options`, `Referrer-Policy`), generic error messages (no stack traces in production), logging to `storage/logs/`.
- **RA 10173:** consent checkboxes (booking and chat), privacy notice page, collect only needed data, access limited by role, audit log of who accessed what matters, archive instead of delete, mention retention in the README.

---

## 12. Seed data (match the prototype so the demo looks like the paper test)

Add a config value **`demo_date`** (optional). When set, "today" for the dashboard equals that date, so the team can demo **Sat, Dec 12, 2026** exactly as in the prototype. Seed (all clearly sample data):

- Packages: Photography, Videography, Photo + Video (sample rates/inclusions marked "SAMPLE – edit in Packages"), plus add-ons and "other services".
- **Santos – Wedding**, Dec 12, 9:00 AM, Photo + Video, **Confirmed**, down payment verified, balance due, owner assigned as lead.
- **Reyes – Birthday**, Dec 12, 2:00 PM, Photo, **Pending** with a GCash payment waiting in A7, **no crew** (triggers the same-day crew warning).
- **Mendoza – Christening**, Dec 24, Photo + Video, **Pending**, payment waiting in A7.
- **Cruz – Birthday**, Dec 5, Video, confirmed, **balance due**, project in **Editing, due Dec 10 → Overdue**.
- **Lopez – Anniversary**, Nov 21, project **Editing**, due Dec 19.
- **Dela Cruz – Debut**, Nov 28, **Completed**, fully paid, **Released**.
- **Garcia – Wedding**, Dec 19, confirmed.
- **Dec 15 is open** (for task T1).
- 2 crew accounts + on-call roster (Photographer A, Videographer A, Photographer B), owner as crew member, 4 equipment checklist items, message templates (Booking confirmed, Balance reminder, Output ready, Reschedule), a few conversations (one unread), terms v1, a recent backup run.

---

## 13. Documentation deliverables (needed for the class)

Generate these **from the actual implementation**:

1. `README.md`: XAMPP setup, install, default logins, how to demo (`demo_date`), feature list, honest notes (cloud copy is manual; no SMS/email), troubleshooting, backup scheduling, deployment notes for shared hosting.
2. `docs/DEVIATIONS.md`: every difference from the paper prototype, one line each with the reason.
3. `docs/ERD.md`: Mermaid ERD matching the schema.
4. `docs/DFD.md`: Mermaid **Context Diagram** (Client, Studio Owner/Admin), **DFD Level 0**, and **Level 1** with processes **1.0 Verify Package & Date Availability, 2.0 Stage Reservation, 3.0 Verify Payment, 4.0 Track Deliverables** and four data stores (suggested: D1 Clients & Bookings, D2 Packages & Calendar, D3 Payments & Receipts, D4 Projects & Deliverables). Show the messaging as a flow too.
5. `docs/UseCases.md`: use cases per actor (Client, Owner, Staff, Crew).
6. `docs/TestCases.md`: table (ID, feature, steps, expected, status), including Section 15.
7. `docs/TRACEABILITY.md`: requirement → screen → route → test case, mirroring the requirement table in the prototype guide (Client & Inquiry Management, Package Catalog, Calendar & Availability, Confirmation & Receipts, Payment Tracking, Policy Handling, Crew & Equipment, Project Tracking, File Storage & Handover Log, Client Notification, Reports, User Access, Security, Reliability, Usability, Availability, Performance).
8. `docs/CHANGELOG.md`: what was built in each phase.

---

## 14. Build phases (stop after each for review)

**Phase 1: Foundation.** Folder structure, config, PDO connection, router, layouts (client teal / admin blue), base CSS with the mobile components (bottom nav, top bar, cards, chips, tags, sheets, toasts), session auth with roles and permissions, CSRF, validation helpers, schema + seed + installer. **Done when:** you can log in as each role and see the right empty shell on a 360 px screen.

**Phase 2: Client booking path (T1).** C1 → C2 → C3 → C4 → C5, package catalog, availability calendar, server-side availability, reference numbers, payment upload with client-side compression, terms and privacy consent, lookup.

**Phase 3: Payments and confirmation (T2, T6).** A7 queue (verify/reject, duplicate flag), booking confirmation and official receipt (A8, print CSS), A6 payment tab, ledger/balances, receipt numbers.

**Phase 4: Admin operations (T3, T4, T5).** A2, A3, A4, A5, A6, A9 (crew + equipment + conflicts), A10, A11, A13, plus A12 templates.

**Phase 5: Messaging (Section 8).** C6, A19, polling API, unread badges, attachments, quick replies, system messages, internal notes. Hook system messages into payment verify, reschedule, release.

**Phase 6: Remaining admin, logs and crew (T7).** A14, A15, A16 (+ CSV), A17, A18, A20 (settings, blocked dates, backup), A21 (audit & system logs viewer + CSV export).

**Phase 7: Hardening, PWA and docs.** PWA manifest and service worker, offline page, security review (Section 11), performance pass, the 4-viewport check, accessibility pass, `tests/run.php`, all docs in Section 13.

At the end of every phase, report: **what was built · how to test it · assumptions · what's next**.

**Definition of Done (each phase):** it runs from a clean install; no PHP warnings; every new route has role/permission checks; forms validate on the server; the screens work at 360 px with no horizontal scroll; new logic has a test in `tests/run.php` or a documented manual test; docs updated.

---

## 15. Acceptance tests (map to the paper-prototype tasks)

| ID | Scenario | Pass when |
|---|---|---|
| T1 | Client books Photo + Video for **Dec 15, 2026**: checks date, fills details, uploads GCash screenshot + ref, submits, finds the reference number | Booking is `pending`, appears in A7, C5 shows the reference and "Payment being checked" |
| T2 | Admin verifies a payment, confirms, copies the message | Booking becomes `confirmed`, receipt number is issued, date counts toward capacity, system message posted, A8 opens, template copied |
| T3 | Reschedule Santos from Dec 12 to Dec 20 | New date validated, history recorded, client notified in chat; rescheduling to a full/blocked date is refused |
| T4 | Dec 12 has two events and the second has no crew; assign an on-call photographer | Warning appears, assignment saves, banner turns green; overlapping-time double assignment is blocked |
| T5 | Cruz project overdue: move to Ready, record folders, mark released, send "output ready" | Stage history updated, handover logged, overdue tag clears, message in chat |
| T6 | Record Santos' balance payment and print the receipt | Balance shows ₱0.00, new receipt number, print layout is clean |
| T7 | Crew logs in and finds the venue and call time for Dec 12 | A18 shows only their events, no payment data, and other routes return 403 |
| T8 | Two clients try to book the last open slot at the same time | Only one can be confirmed; the other gets a clear message (race-safe) |
| T9 | Duplicate GCash reference | A7 shows a duplicate warning that must be acknowledged |
| T10 | Cancel a confirmed booking | Down payment marked forfeited, date freed, record kept |
| T11 | Messaging: client ↔ admin | Messages appear within ~5 s without refresh in both directions; unread counts correct; another client's chat cannot be opened by changing the ID/token; crew get 403; `<script>` is shown as text; the rate limit works; non-image and oversized uploads are rejected; payment verify posts a system message |
| T12 | Mobile | All screens usable at 360/390/768/1280 px, tap targets ≥ 44 px, no horizontal scroll, pages load fast over throttled 4G, the app can be added to the home screen, and the offline page shows without leaking private data |
| T13 | Security & Audit Logs | SQLi/XSS/CSRF probes fail, unauthenticated access to uploads fails, session fixation is prevented, and every state-changing or security-sensitive action writes a corresponding entry in `audit_logs`, `booking_changes`, `project_history`, `message_log`, or `backup_runs` visible in A21 (`/admin/logs`) |

---

## 16. Rules and guardrails

- **Never** concatenate SQL. **Never** trust client-side validation alone. **Never** hard-code credentials. Use `config.php` (git-ignored) and `config.sample.php`.
- Do **not** add paid services, SMS/email APIs, WebSockets, or heavy libraries.
- Do **not** silently skip a screen. If you must defer one, say so at the end of the phase and why.
- Keep the UI **plain-language, big buttons, few taps** (the owner has little time for training). Always show status with a **colored tag plus text** (Pending, Confirmed, Overdue, Balance due, Completed).
- Keep all names, dates and amounts in seed data as **sample data**. No real client information in the repo.
- If something in this brief conflicts with itself, choose the most sensible option, state it, and continue.

**Start now:** reply with your short plan, the folder tree, the route map, and your assumptions, then begin Phase 1.
