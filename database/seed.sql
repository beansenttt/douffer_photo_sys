-- =====================================================================
-- Douffer PhotoSys: SAMPLE seed data (matches the paper prototype)
-- Every name, number, date and amount here is SAMPLE data. No real clients.
-- Demo "today" = Sat, Dec 12, 2026 (config demo_date).
--
-- Default logins (change them after the first login):
--   owner / Owner@2026   staff / Staff@2026   crew1 / Crew1@2026   crew2 / Crew2@2026
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------------- users
INSERT INTO users (id, name, username, password_hash, role,
  perm_view_bookings, perm_record_payments, perm_verify_payments, perm_archive_records, perm_manage_messages,
  must_change_password, created_at) VALUES
(1, 'Jeffrey D. Madrigal', 'owner', '$2y$10$k3vqYv5.R8jCAuvWe/jj8.zoR/LFcScoFF0AUT6R2DfcJXxIsLP9e', 'owner', 1, 1, 1, 1, 1, 1, '2026-10-01 08:00:00'),
(2, 'Studio Assistant (Sample)', 'staff', '$2y$10$vpyyjk8jiycVwyXyXtEtReDeTTPFhLxh6Jk14vW3Cm.Fq7ipakjuC', 'staff', 1, 1, 0, 0, 1, 1, '2026-10-01 08:00:00'),
(3, 'Photographer A', 'crew1', '$2y$10$1zXrUheqnk0PGWDz8hsNw.9HN4KpqDfAtMzHHOnvwDGCuFuaYtSbO', 'crew', 0, 0, 0, 0, 0, 1, '2026-10-01 08:00:00'),
(4, 'Videographer A', 'crew2', '$2y$10$IyZA3a2DyEZcm9apOLQHGOOCuc/p0Ho.TqOrpWgPoSaOzsE6s6YzG', 'crew', 0, 0, 0, 0, 0, 1, '2026-10-01 08:00:00');

-- ---------------------------------------------------------------- settings (A20)
INSERT INTO settings (setting_key, setting_value) VALUES
('studio_name', 'Douffer Photography'),
('studio_address', 'Victoria Ville Subd., Brgy. Bilaran, Nasugbu, Batangas'),
('studio_phone', '0917 000 0000'),
('studio_messenger_url', 'https://m.me/'),
('gcash_name', 'J. D. M. (SAMPLE)'),
('gcash_number', '0917 000 0000'),
('gcash_qr_path', ''),
('down_payment_mode', 'percent'),
('down_payment_value', '30'),
('max_events_per_day', '2'),
('default_delivery_days', '14'),
('balance_overdue_days', '0'),
('current_terms_version', '1'),
('event_types', 'Wedding\nBirthday\nDebut\nChristening\nAnniversary\nCorporate\nOther');

INSERT INTO terms_versions (id, version, body, effective_from) VALUES
(1, '1', 'Your date is reserved only after the studio verifies your GCash down payment. The down payment is non-refundable if you cancel. Rescheduling is free if the new date is open. The remaining balance is due on or before the event date. Edited photos and videos are released within the agreed delivery period (default 14 days).', '2026-10-01');

-- ---------------------------------------------------------------- catalog (SAMPLE rates)
INSERT INTO packages (id, name, category, description, inclusions, price, down_payment_override, is_active, sort_order) VALUES
(1, 'Photography Package', 'photo', 'Event photo coverage. SAMPLE – edit in Packages.',
 'SAMPLE – edit in Packages\n1 photographer, up to 6 hours\nAll edited photos (online gallery)\n20 printed photos (5R)', 8000.00, NULL, 1, 1),
(2, 'Videography Package', 'video', 'Event video coverage. SAMPLE – edit in Packages.',
 'SAMPLE – edit in Packages\n1 videographer, up to 6 hours\n3–5 minute highlight video\nFull event video (USB)', 10000.00, NULL, 1, 2),
(3, 'Photo + Video Package', 'photo_video', 'Photo and video coverage together. SAMPLE – edit in Packages.',
 'SAMPLE – edit in Packages\n1 photographer + 1 videographer, up to 8 hours\nAll edited photos + 30-page album\n3–5 minute highlight video + full video', 16000.00, NULL, 1, 3),
(4, 'Invitations', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 10),
(5, 'Photobooth', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 11),
(6, 'Give-aways', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 12),
(7, 'Emcee', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 13),
(8, 'Catering', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 14),
(9, 'Makeup artist', 'other', 'Please message the studio to ask about this service.', NULL, NULL, NULL, 1, 15);

INSERT INTO addons (id, name, price, is_active) VALUES
(1, 'Extra hour of coverage (SAMPLE)', 1500.00, 1),
(2, 'Same-day edit video (SAMPLE)', 3500.00, 1),
(3, 'Extra 20 printed photos (SAMPLE)', 1000.00, 1);

-- ---------------------------------------------------------------- clients (SAMPLE)
INSERT INTO clients (id, full_name, mobile, mobile_normalized, messenger_name, contact_channel, privacy_consent_at, created_at) VALUES
(1, 'Maria Dela Cruz (Sample)', '0917 000 0001', '09170000001', 'Maria DC', 'messenger', '2026-10-18 10:00:00', '2026-10-18 10:00:00'),
(2, 'Ramon Lopez (Sample)', '0917 000 0002', '09170000002', NULL, 'call', '2026-10-24 15:00:00', '2026-10-24 15:00:00'),
(3, 'Carlo Cruz (Sample)', '0917 000 0003', '09170000003', NULL, 'walk_in', '2026-11-09 11:00:00', '2026-11-09 11:00:00'),
(4, 'Angela Santos (Sample)', '0917 000 0004', '09170000004', 'Angela Santos', 'messenger', '2026-11-14 10:00:00', '2026-11-14 10:00:00'),
(5, 'Paolo Garcia (Sample)', '0917 000 0005', '09170000005', 'Paolo G.', 'referral', '2026-11-19 13:00:00', '2026-11-19 13:00:00'),
(6, 'Liza Reyes (Sample)', '0917 000 0006', '09170000006', 'Liza Reyes', 'website', '2026-12-11 18:20:00', '2026-12-11 18:20:00'),
(7, 'Joy Mendoza (Sample)', '0917 000 0007', '09170000007', NULL, 'website', '2026-12-10 09:05:00', '2026-12-10 09:05:00');

INSERT INTO client_inquiries (client_id, channel, note, created_by, created_at) VALUES
(2, 'call', 'Asked for a small photo package for a 25th anniversary dinner at home.', 1, '2026-10-24 15:00:00'),
(3, 'walk_in', 'Walked in with cash down payment. Wants video only for a 7th birthday.', 1, '2026-11-09 11:00:00'),
(5, 'referral', 'Referred by Maria Dela Cruz. Asked about same-day edit video.', 1, '2026-11-19 13:00:00');

-- ---------------------------------------------------------------- bookings (SAMPLE)
-- 1 Dela Cruz debut (completed, released) · 2 Lopez anniversary (editing, due Dec 19)
-- 3 Cruz birthday (editing, due Dec 10 = OVERDUE, balance due) · 4 Santos wedding Dec 12 (confirmed, balance due)
-- 5 Garcia wedding Dec 19 (confirmed) · 6 Reyes birthday Dec 12 (PENDING, no crew) · 7 Mendoza christening Dec 24 (PENDING)
-- Dec 15, 2026 is open (task T1).
INSERT INTO bookings (id, reference_code, client_id, package_id, event_type, event_date, start_time, end_time, venue, special_requests,
  source_channel, status, package_price, total_amount, down_payment_due, terms_version, terms_accepted_at, privacy_consent_at,
  confirmed_at, completed_at, created_by, created_at) VALUES
(1, 'DPS-2026-0001', 1, 3, 'Debut', '2026-11-28', '17:00:00', '22:00:00', 'Villa Rosa Events Place, Nasugbu (SAMPLE)', 'Cotillion and 18 roses coverage.',
 'messenger', 'completed', 16000.00, 16000.00, 4800.00, '1', '2026-10-18 10:00:00', '2026-10-18 10:00:00', '2026-10-20 09:00:00', '2026-12-08 16:00:00', 1, '2026-10-18 10:00:00'),
(2, 'DPS-2026-0002', 2, 1, 'Anniversary', '2026-11-21', '16:00:00', '20:00:00', 'Lopez residence, Brgy. Wawa, Nasugbu (SAMPLE)', NULL,
 'call', 'confirmed', 8000.00, 8000.00, 2400.00, '1', '2026-10-24 15:00:00', '2026-10-24 15:00:00', '2026-10-25 10:00:00', NULL, 1, '2026-10-24 15:00:00'),
(3, 'DPS-2026-0003', 3, 2, 'Birthday', '2026-12-05', '14:00:00', '18:00:00', 'Cruz residence, Brgy. Bucana, Nasugbu (SAMPLE)', 'Kids party, mostly indoors.',
 'walk_in', 'confirmed', 10000.00, 10000.00, 3000.00, '1', '2026-11-09 11:00:00', '2026-11-09 11:00:00', '2026-11-10 11:00:00', NULL, 1, '2026-11-09 11:00:00'),
(4, 'DPS-2026-0004', 4, 3, 'Wedding', '2026-12-12', '09:00:00', '17:00:00', 'St. Francis Xavier Parish + reception at Nasugbu Beach Hall (SAMPLE)', 'Church ceremony 9:00 AM, reception 12:00 NN.',
 'messenger', 'confirmed', 16000.00, 16000.00, 4800.00, '1', '2026-11-14 10:00:00', '2026-11-14 10:00:00', '2026-11-15 08:30:00', NULL, NULL, '2026-11-14 10:00:00'),
(5, 'DPS-2026-0005', 5, 3, 'Wedding', '2026-12-19', '10:00:00', '18:00:00', 'Caleruega Church, Brgy. Kaylaway, Nasugbu (SAMPLE)', 'Wants a same-day edit video.',
 'referral', 'confirmed', 16000.00, 19500.00, 5850.00, '1', '2026-11-19 13:00:00', '2026-11-19 13:00:00', '2026-11-20 09:00:00', NULL, 1, '2026-11-19 13:00:00'),
(6, 'DPS-2026-0006', 6, 1, 'Birthday', '2026-12-12', '14:00:00', '18:00:00', 'Reyes residence, Brgy. Lumbangan, Nasugbu (SAMPLE)', '1st birthday, garden setup.',
 'website', 'pending', 8000.00, 8000.00, 2400.00, '1', '2026-12-11 18:20:00', '2026-12-11 18:20:00', NULL, NULL, NULL, '2026-12-11 18:20:00'),
(7, 'DPS-2026-0007', 7, 3, 'Christening', '2026-12-24', '08:00:00', '12:00:00', 'Sts. Peter and Paul Parish, Nasugbu (SAMPLE)', NULL,
 'website', 'pending', 16000.00, 16000.00, 4800.00, '1', '2026-12-10 09:05:00', '2026-12-10 09:05:00', NULL, NULL, NULL, '2026-12-10 09:05:00');

INSERT INTO booking_addons (booking_id, addon_id, price_at_booking) VALUES
(5, 2, 3500.00);

INSERT INTO counters (name, year, last_value) VALUES
('booking', 2026, 7),
('receipt', 2026, 7);

INSERT INTO booking_changes (booking_id, change_type, old_value, new_value, reason, changed_by, changed_at) VALUES
(1, 'created', NULL, 'pending', 'Recorded from Messenger', 1, '2026-10-18 10:00:00'),
(1, 'status', 'pending', 'confirmed', 'Down payment verified', 1, '2026-10-20 09:00:00'),
(1, 'status', 'confirmed', 'completed', 'Output released and fully paid', 1, '2026-12-08 16:00:00'),
(2, 'created', NULL, 'pending', 'Recorded from a phone call', 1, '2026-10-24 15:00:00'),
(2, 'status', 'pending', 'confirmed', 'Down payment verified', 1, '2026-10-25 10:00:00'),
(3, 'created', NULL, 'pending', 'Walk-in', 1, '2026-11-09 11:00:00'),
(3, 'status', 'pending', 'confirmed', 'Down payment verified', 1, '2026-11-10 11:00:00'),
(4, 'created', NULL, 'pending', 'Booked online', NULL, '2026-11-14 10:00:00'),
(4, 'status', 'pending', 'confirmed', 'Down payment verified', 1, '2026-11-15 08:30:00'),
(5, 'created', NULL, 'pending', 'Referral', 1, '2026-11-19 13:00:00'),
(5, 'status', 'pending', 'confirmed', 'Down payment verified', 1, '2026-11-20 09:00:00'),
(6, 'created', NULL, 'pending', 'Booked online', NULL, '2026-12-11 18:20:00'),
(7, 'created', NULL, 'pending', 'Booked online', NULL, '2026-12-10 09:05:00');

-- ---------------------------------------------------------------- payments (SAMPLE)
-- Payment 9 (Mendoza) reuses the GCash reference of payment 7 on purpose: demo of the duplicate warning (T9).
INSERT INTO payments (id, booking_id, type, method, amount, gcash_reference_no, receipt_path, status, receipt_no, submitted_via,
  recorded_by, verified_by, verified_at, paid_at, created_at) VALUES
(1, 1, 'down', 'gcash', 4800.00, '1001234567801', NULL, 'verified', 'OR-2026-0001', 'admin', 1, 1, '2026-10-20 09:00:00', '2026-10-19 20:00:00', '2026-10-19 20:00:00'),
(2, 2, 'down', 'gcash', 2400.00, '1001234567802', NULL, 'verified', 'OR-2026-0002', 'admin', 1, 1, '2026-10-25 10:00:00', '2026-10-25 09:30:00', '2026-10-25 09:30:00'),
(3, 1, 'balance', 'cash', 11200.00, NULL, NULL, 'verified', 'OR-2026-0003', 'admin', 1, 1, '2026-11-28 16:30:00', '2026-11-28 16:30:00', '2026-11-28 16:30:00'),
(4, 2, 'balance', 'cash', 5600.00, NULL, NULL, 'verified', 'OR-2026-0004', 'admin', 1, 1, '2026-11-21 15:30:00', '2026-11-21 15:30:00', '2026-11-21 15:30:00'),
(5, 3, 'down', 'cash', 3000.00, NULL, NULL, 'verified', 'OR-2026-0005', 'admin', 1, 1, '2026-11-10 11:00:00', '2026-11-10 11:00:00', '2026-11-10 11:00:00'),
(6, 4, 'down', 'gcash', 4800.00, '1001234567806', 'receipts/sample-santos.png', 'verified', 'OR-2026-0006', 'portal', NULL, 1, '2026-11-15 08:30:00', '2026-11-14 10:00:00', '2026-11-14 10:00:00'),
(7, 5, 'down', 'gcash', 5850.00, '1001234567807', NULL, 'verified', 'OR-2026-0007', 'admin', 1, 1, '2026-11-20 09:00:00', '2026-11-20 08:45:00', '2026-11-20 08:45:00'),
(8, 6, 'down', 'gcash', 2400.00, '1001234567808', 'receipts/sample-reyes.png', 'pending', NULL, 'portal', NULL, NULL, NULL, '2026-12-11 18:20:00', '2026-12-11 18:20:00'),
(9, 7, 'down', 'gcash', 4800.00, '1001234567807', 'receipts/sample-mendoza.png', 'pending', NULL, 'portal', NULL, NULL, NULL, '2026-12-10 09:05:00', '2026-12-10 09:05:00');

-- ---------------------------------------------------------------- calendar
INSERT INTO blocked_dates (date, reason, created_by) VALUES
('2026-12-25', 'Christmas Day: studio closed (SAMPLE)', 1),
('2026-12-31', 'New Year''s Eve: studio closed (SAMPLE)', 1);

-- ---------------------------------------------------------------- crew & equipment
INSERT INTO crew_members (id, name, type, phone, user_id, is_on_call, is_active) VALUES
(1, 'Jeffrey D. Madrigal (Owner)', 'owner', '0917 000 0000', 1, 0, 1),
(2, 'Photographer A', 'photographer', '0917 000 1001', 3, 1, 1),
(3, 'Videographer A', 'videographer', '0917 000 1002', 4, 1, 1),
(4, 'Photographer B', 'photographer', '0917 000 1003', NULL, 1, 1);

INSERT INTO crew_assignments (booking_id, crew_member_id, role, call_time, assigned_by, created_at) VALUES
(1, 1, 'lead', '16:00:00', 1, '2026-10-20 09:00:00'),
(1, 3, 'backup', '16:00:00', 1, '2026-10-20 09:00:00'),
(2, 1, 'lead', NULL, 1, '2026-10-25 10:00:00'),
(3, 1, 'lead', NULL, 1, '2026-11-10 11:00:00'),
(4, 1, 'lead', '07:30:00', 1, '2026-11-15 08:30:00'),
(4, 3, 'backup', '07:30:00', 1, '2026-11-15 08:30:00'),
(5, 1, 'lead', NULL, 1, '2026-11-20 09:00:00');

INSERT INTO equipment_items (id, name, category, is_active) VALUES
(1, 'Cameras (2 bodies)', 'Camera', 1),
(2, 'Lenses (24-70mm, 70-200mm, 50mm)', 'Lens', 1),
(3, 'Lights (2 speedlights, 1 video light)', 'Lighting', 1),
(4, 'Memory cards and charged batteries', 'Power & storage', 1);

INSERT INTO booking_equipment (booking_id, equipment_item_id, is_checked) VALUES
(4, 1, 1), (4, 2, 1), (4, 3, 0), (4, 4, 0);

-- ---------------------------------------------------------------- projects
INSERT INTO projects (id, booking_id, stage, due_date, editing_done, rendering_done, album_done, raw_folder, edited_folder,
  local_backup_done, local_backup_at, cloud_copy_done, cloud_copy_at, released_how, released_note, released_at, received_by, released_by, created_at) VALUES
(1, 1, 'released', '2026-12-12', 1, 1, 1, 'D:/Douffer/2026/2026-11-28_DelaCruz_Debut/RAW', 'D:/Douffer/2026/2026-11-28_DelaCruz_Debut/EDITED',
 1, '2026-11-29 10:00:00', 1, '2026-11-29 12:00:00', 'messenger_link', 'Google Drive link sent in Messenger', '2026-12-08', 'Maria Dela Cruz (Sample)', 1, '2026-10-20 09:00:00'),
(2, 2, 'editing', '2026-12-19', 1, 0, 0, 'D:/Douffer/2026/2026-11-21_Lopez_Anniversary/RAW', NULL,
 1, '2026-11-22 09:00:00', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-25 10:00:00'),
(3, 3, 'editing', '2026-12-10', 1, 0, 0, 'D:/Douffer/2026/2026-12-05_Cruz_Birthday/RAW', NULL,
 1, '2026-12-06 09:00:00', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-10 11:00:00'),
(4, 4, 'booked', '2026-12-26', 0, 0, 0, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-15 08:30:00'),
(5, 5, 'booked', '2027-01-02', 0, 0, 0, NULL, NULL, 0, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-20 09:00:00');

INSERT INTO project_history (project_id, old_stage, new_stage, changed_by, changed_at) VALUES
(1, NULL, 'booked', 1, '2026-10-20 09:00:00'),
(1, 'booked', 'covered', 1, '2026-11-28 22:30:00'),
(1, 'covered', 'editing', 1, '2026-11-29 10:00:00'),
(1, 'editing', 'ready', 1, '2026-12-07 18:00:00'),
(1, 'ready', 'released', 1, '2026-12-08 16:00:00'),
(2, NULL, 'booked', 1, '2026-10-25 10:00:00'),
(2, 'booked', 'covered', 1, '2026-11-21 20:30:00'),
(2, 'covered', 'editing', 1, '2026-11-22 09:00:00'),
(3, NULL, 'booked', 1, '2026-11-10 11:00:00'),
(3, 'booked', 'covered', 1, '2026-12-05 18:30:00'),
(3, 'covered', 'editing', 1, '2026-12-06 09:00:00'),
(4, NULL, 'booked', 1, '2026-11-15 08:30:00'),
(5, NULL, 'booked', 1, '2026-11-20 09:00:00');

-- ---------------------------------------------------------------- message templates (A12)
-- Placeholders: [Client name] [Event] [Event date] [Balance] [Reference no.] [Studio]
INSERT INTO message_templates (id, template_key, title, body_tl, body_en, is_active, sort_order) VALUES
(1, 'booking_confirmed', 'Booking confirmed',
 'Hi [Client name]! Kumpirmado na po ang inyong booking para sa [Event] sa [Event date]. Reference no.: [Reference no.]. Natanggap na po namin ang inyong down payment. Natitirang balanse: [Balance]. Salamat po! – [Studio]',
 'Hi [Client name]! Your booking for the [Event] on [Event date] is confirmed. Reference no.: [Reference no.]. We received your down payment. Remaining balance: [Balance]. Thank you! – [Studio]', 1, 1),
(2, 'balance_reminder', 'Balance reminder',
 'Hi [Client name]! Paalala lang po: ang natitirang balanse para sa [Event] sa [Event date] ay [Balance]. Maaari po kayong magbayad sa GCash o cash. Salamat po! – [Studio]',
 'Hi [Client name]! A friendly reminder: the remaining balance for the [Event] on [Event date] is [Balance]. You can pay by GCash or cash. Thank you! – [Studio]', 1, 2),
(3, 'output_ready', 'Output ready',
 'Hi [Client name]! Handa na po ang inyong photos at videos mula sa [Event] ([Event date]). Ipaalam lang po kung pick-up o Messenger link ang gusto ninyo. Salamat po! – [Studio]',
 'Hi [Client name]! Your photos and videos from the [Event] ([Event date]) are ready. Let us know if you prefer pickup or a Messenger link. Thank you! – [Studio]', 1, 3),
(4, 'reschedule', 'Reschedule',
 'Hi [Client name]! Na-reschedule na po ang inyong [Event] sa [Event date]. Libre po ang reschedule. Reference no.: [Reference no.]. Salamat po! – [Studio]',
 'Hi [Client name]! Your [Event] has been rescheduled to [Event date]. Rescheduling is free. Reference no.: [Reference no.]. Thank you! – [Studio]', 1, 4);

INSERT INTO message_log (booking_id, template_key, language, body, channel, sent_marked_by, sent_at) VALUES
(1, 'output_ready', 'en', 'Hi Maria Dela Cruz (Sample)! Your photos and videos from the Debut (Sat, Nov 28, 2026) are ready. Let us know if you prefer pickup or a Messenger link. Thank you! – Douffer Photography', 'copy', 1, '2026-12-07 18:10:00');

INSERT INTO quick_replies (id, title, body, is_active) VALUES
(1, 'Thank you', 'Thank you po! We will get back to you shortly.', 1),
(2, 'Payment received', 'We received your GCash screenshot. We will check it and confirm your booking soon.', 1),
(3, 'Ask for venue', 'Could you please send the exact venue address and a contact person on the event day?', 1),
(4, 'Office hours', 'Our office hours are 9:00 AM to 6:00 PM, Monday to Saturday.', 1);

-- ---------------------------------------------------------------- conversations (C6 / A19)
-- Conversation 3 is a guest inquiry. Its sample access token is "demo-inquiry-token" (only the hash is stored).
INSERT INTO conversations (id, booking_id, client_id, guest_name, guest_mobile, access_token_hash, privacy_consent_at, status, last_message_at, created_at) VALUES
(1, 4, 4, NULL, NULL, NULL, '2026-11-14 10:00:00', 'open', '2026-12-11 20:15:00', '2026-11-14 10:00:00'),
(2, 6, 6, NULL, NULL, NULL, '2026-12-11 18:20:00', 'open', '2026-12-11 18:20:00', '2026-12-11 18:20:00'),
(3, NULL, NULL, 'Ana V. (Sample)', '09170000008', SHA2('demo-inquiry-token', 256), '2026-12-09 07:40:00', 'open', '2026-12-09 08:05:00', '2026-12-09 07:40:00');

INSERT INTO messages (conversation_id, sender_type, sender_user_id, body, attachment_path, read_at, created_at) VALUES
(1, 'system', NULL, 'Booking request received. Reference no. DPS-2026-0004. Your payment is being checked.', NULL, '2026-11-14 10:01:00', '2026-11-14 10:00:00'),
(1, 'system', NULL, 'Payment verified ✔ Your booking is confirmed. Official receipt OR-2026-0006.', NULL, '2026-11-15 09:00:00', '2026-11-15 08:30:00'),
(1, 'admin', 1, 'Hello po, Angela! Congratulations in advance. We will send the final timeline a few days before the wedding.', NULL, '2026-11-15 12:00:00', '2026-11-15 09:10:00'),
(1, 'client', NULL, 'Good evening po! What time will the team arrive at the church tomorrow?', NULL, NULL, '2026-12-11 20:15:00'),
(2, 'system', NULL, 'Booking request received. Reference no. DPS-2026-0006. Your payment is being checked.', NULL, NULL, '2026-12-11 18:20:00'),
(3, 'client', NULL, 'Hi! Magkano po ang photobooth for a 7th birthday?', NULL, '2026-12-09 08:00:00', '2026-12-09 07:40:00'),
(3, 'admin', 1, 'Hello po! Photobooth rates depend on the hours and print size. May we know the date and venue?', NULL, NULL, '2026-12-09 08:05:00');

-- Internal note (staff + assigned crew only; never shown to the client)
INSERT INTO booking_notes (booking_id, body, visible_to_crew, created_by, created_at) VALUES
(4, 'Bride prefers candid shots. Park at the side gate of the church. Reception program starts 12:00 NN.', 1, 1, '2026-12-10 19:00:00');

-- ---------------------------------------------------------------- backups
INSERT INTO backup_runs (created_at, file_name, size_bytes, type, created_by, cloud_copy_marked_at) VALUES
('2026-12-10 21:00:00', 'douffer_backup_2026-12-10_210000_SAMPLE.sql', 184320, 'scheduled', NULL, '2026-12-10 21:30:00');
