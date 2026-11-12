-- =====================================================================
-- Douffer PhotoSys: database schema (MySQL 5.7+ / MariaDB 10.3+)
-- InnoDB, utf8mb4, 3NF, foreign keys. Money = DECIMAL(10,2).
-- Import with phpMyAdmin (into an empty database) or run:
--     php database/install.php
-- Records are archived, never hard-deleted from the UI (BR-8), so foreign
-- keys use the default RESTRICT behaviour.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS backup_runs;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS terms_versions;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS quick_replies;
DROP TABLE IF EXISTS booking_notes;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS conversations;
DROP TABLE IF EXISTS message_log;
DROP TABLE IF EXISTS message_templates;
DROP TABLE IF EXISTS project_history;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS booking_equipment;
DROP TABLE IF EXISTS equipment_items;
DROP TABLE IF EXISTS crew_assignments;
DROP TABLE IF EXISTS crew_members;
DROP TABLE IF EXISTS date_locks;
DROP TABLE IF EXISTS blocked_dates;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS booking_changes;
DROP TABLE IF EXISTS booking_addons;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS counters;
DROP TABLE IF EXISTS addons;
DROP TABLE IF EXISTS packages;
DROP TABLE IF EXISTS client_inquiries;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- users
-- Roles: owner (full), staff (permission flags), crew (view-only A18).
CREATE TABLE users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(100) NOT NULL,
  username              VARCHAR(50)  NOT NULL,
  email                 VARCHAR(150) NULL,
  password_hash         VARCHAR(255) NOT NULL,
  role                  ENUM('owner','staff','crew') NOT NULL,
  perm_view_bookings    TINYINT(1) NOT NULL DEFAULT 1,
  perm_record_payments  TINYINT(1) NOT NULL DEFAULT 1,
  perm_verify_payments  TINYINT(1) NOT NULL DEFAULT 0,
  perm_archive_records  TINYINT(1) NOT NULL DEFAULT 0,
  perm_manage_messages  TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password  TINYINT(1) NOT NULL DEFAULT 1,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at         DATETIME NULL,
  password_changed_at   DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- clients
CREATE TABLE clients (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name           VARCHAR(120) NOT NULL,
  mobile              VARCHAR(20)  NOT NULL,
  mobile_normalized   CHAR(11)     NOT NULL,          -- 09XXXXXXXXX
  messenger_name      VARCHAR(150) NULL,
  contact_channel     ENUM('messenger','call','walk_in','referral','website') NOT NULL DEFAULT 'website',
  privacy_consent_at  DATETIME NULL,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clients_mobile (mobile_normalized),
  KEY idx_clients_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_inquiries (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED NOT NULL,
  channel     ENUM('messenger','call','walk_in','referral','website') NOT NULL,
  note        TEXT NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inquiries_client (client_id),
  CONSTRAINT fk_inquiries_client FOREIGN KEY (client_id) REFERENCES clients (id),
  CONSTRAINT fk_inquiries_user   FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- catalog
-- category 'other' = inquire-only services (invitations, photobooth...). price NULL = "ask the studio".
CREATE TABLE packages (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                   VARCHAR(100) NOT NULL,
  category               ENUM('photo','video','photo_video','other') NOT NULL,
  description            VARCHAR(255) NULL,
  inclusions             TEXT NULL,                    -- one inclusion per line
  price                  DECIMAL(10,2) NULL,
  down_payment_override  DECIMAL(10,2) NULL,           -- fixed down payment for this package
  is_active              TINYINT(1) NOT NULL DEFAULT 1,
  sort_order             INT NOT NULL DEFAULT 0,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_packages_active (is_active, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addons (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequential numbers per year: DPS-2026-0001 (booking), OR-2026-0001 (receipt).
CREATE TABLE counters (
  name        VARCHAR(20) NOT NULL,
  year        SMALLINT UNSIGNED NOT NULL,
  last_value  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- bookings
-- status: pending -> confirmed -> completed; pending|confirmed -> cancelled.
-- archived is a separate flag. Derived tags (balance due, overdue) are computed, not stored.
CREATE TABLE bookings (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_code      VARCHAR(20)  NOT NULL,
  client_id           INT UNSIGNED NOT NULL,
  package_id          INT UNSIGNED NOT NULL,
  event_type          VARCHAR(50)  NOT NULL,
  event_date          DATE         NOT NULL,
  start_time          TIME NULL,
  end_time            TIME NULL,
  venue               VARCHAR(255) NOT NULL,
  special_requests    TEXT NULL,
  source_channel      ENUM('messenger','call','walk_in','referral','website') NOT NULL DEFAULT 'website',
  status              ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  package_price       DECIMAL(10,2) NOT NULL,          -- price at booking time
  total_amount        DECIMAL(10,2) NOT NULL,          -- package + add-ons
  down_payment_due    DECIMAL(10,2) NOT NULL,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  terms_version       VARCHAR(10) NULL,
  terms_accepted_at   DATETIME NULL,
  privacy_consent_at  DATETIME NULL,
  confirmed_at        DATETIME NULL,
  cancelled_at        DATETIME NULL,
  completed_at        DATETIME NULL,
  archived_at         DATETIME NULL,
  created_by          INT UNSIGNED NULL,               -- NULL = client via portal
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_ref (reference_code),
  KEY idx_bookings_date_status (event_date, status),
  KEY idx_bookings_client (client_id),
  KEY idx_bookings_status (status, is_archived),
  CONSTRAINT fk_bookings_client  FOREIGN KEY (client_id)  REFERENCES clients (id),
  CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES packages (id),
  CONSTRAINT fk_bookings_user    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_addons (
  booking_id        INT UNSIGNED NOT NULL,
  addon_id          INT UNSIGNED NOT NULL,
  price_at_booking  DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (booking_id, addon_id),
  CONSTRAINT fk_ba_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_ba_addon   FOREIGN KEY (addon_id)   REFERENCES addons (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History timeline of a booking (A6). "rescheduled" is a history event, not a status.
CREATE TABLE booking_changes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   INT UNSIGNED NOT NULL,
  change_type  ENUM('created','status','reschedule','cancel','archive','restore','note') NOT NULL,
  old_value    VARCHAR(255) NULL,
  new_value    VARCHAR(255) NULL,
  reason       VARCHAR(500) NULL,
  changed_by   INT UNSIGNED NULL,                      -- NULL = client / system
  changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_changes_booking (booking_id, changed_at),
  CONSTRAINT fk_changes_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_changes_user    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- payments
-- status: pending -> verified | rejected. Never verified automatically (BR-5).
-- is_forfeited = verified down payment kept after cancellation (BR-2).
CREATE TABLE payments (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id           INT UNSIGNED NOT NULL,
  type                 ENUM('down','balance','other') NOT NULL,
  method               ENUM('gcash','cash','other') NOT NULL DEFAULT 'gcash',
  amount               DECIMAL(10,2) NOT NULL,
  gcash_reference_no   VARCHAR(40) NULL,
  receipt_path         VARCHAR(255) NULL,              -- relative to storage/, never public
  receipt_thumb_path   VARCHAR(255) NULL,
  status               ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  rejection_reason     VARCHAR(255) NULL,
  is_forfeited         TINYINT(1) NOT NULL DEFAULT 0,
  receipt_no           VARCHAR(20) NULL,               -- OR-2026-0001
  submitted_via        ENUM('portal','admin') NOT NULL DEFAULT 'portal',
  duplicate_ack_by     INT UNSIGNED NULL,
  duplicate_ack_at     DATETIME NULL,
  recorded_by          INT UNSIGNED NULL,
  verified_by          INT UNSIGNED NULL,
  verified_at          DATETIME NULL,
  paid_at              DATETIME NULL,
  notes                VARCHAR(255) NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_receipt_no (receipt_no),
  KEY idx_payments_booking (booking_id),
  KEY idx_payments_status (status, created_at),
  KEY idx_payments_gcash_ref (gcash_reference_no),
  CONSTRAINT fk_payments_booking  FOREIGN KEY (booking_id)  REFERENCES bookings (id),
  CONSTRAINT fk_payments_verifier FOREIGN KEY (verified_by) REFERENCES users (id),
  CONSTRAINT fk_payments_recorder FOREIGN KEY (recorded_by) REFERENCES users (id),
  CONSTRAINT fk_payments_dupack   FOREIGN KEY (duplicate_ack_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- calendar
CREATE TABLE blocked_dates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  date        DATE NOT NULL,
  reason      VARCHAR(255) NULL,
  created_by  INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blocked_date (date),
  CONSTRAINT fk_blocked_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per date, locked with SELECT ... FOR UPDATE while a booking is
-- being confirmed, so two people can never confirm the last slot at once (BR-4).
CREATE TABLE date_locks (
  date  DATE NOT NULL,
  PRIMARY KEY (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- crew & equipment
CREATE TABLE crew_members (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100) NOT NULL,
  type        ENUM('owner','photographer','videographer','other') NOT NULL,
  phone       VARCHAR(20) NULL,
  user_id     INT UNSIGNED NULL,                       -- login account (crew role)
  is_on_call  TINYINT(1) NOT NULL DEFAULT 1,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crew_user (user_id),
  CONSTRAINT fk_crew_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crew_assignments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id      INT UNSIGNED NOT NULL,
  crew_member_id  INT UNSIGNED NOT NULL,
  role            ENUM('lead','backup') NOT NULL DEFAULT 'lead',
  call_time       TIME NULL,                           -- NULL = 1 hour before start
  assigned_by     INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assignment (booking_id, crew_member_id),
  KEY idx_assign_crew (crew_member_id),
  CONSTRAINT fk_assign_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_assign_crew    FOREIGN KEY (crew_member_id) REFERENCES crew_members (id),
  CONSTRAINT fk_assign_user    FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL,
  category   VARCHAR(50) NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_equipment (
  booking_id         INT UNSIGNED NOT NULL,
  equipment_item_id  INT UNSIGNED NOT NULL,
  is_checked         TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (booking_id, equipment_item_id),
  CONSTRAINT fk_be_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_be_item    FOREIGN KEY (equipment_item_id) REFERENCES equipment_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- projects
-- stage: booked -> covered -> editing -> ready -> released (BR-7).
-- Only folder NAMES are logged; photo/video files are never uploaded (BR-10).
CREATE TABLE projects (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id         INT UNSIGNED NOT NULL,
  stage              ENUM('booked','covered','editing','ready','released') NOT NULL DEFAULT 'booked',
  due_date           DATE NOT NULL,
  editing_done       TINYINT(1) NOT NULL DEFAULT 0,
  rendering_done     TINYINT(1) NOT NULL DEFAULT 0,
  album_done         TINYINT(1) NOT NULL DEFAULT 0,
  raw_folder         VARCHAR(255) NULL,
  edited_folder      VARCHAR(255) NULL,
  local_backup_done  TINYINT(1) NOT NULL DEFAULT 0,
  local_backup_at    DATETIME NULL,
  cloud_copy_done    TINYINT(1) NOT NULL DEFAULT 0,
  cloud_copy_at      DATETIME NULL,
  released_how       ENUM('pickup','messenger_link','other') NULL,
  released_note      VARCHAR(255) NULL,
  released_at        DATE NULL,
  received_by        VARCHAR(120) NULL,
  released_by        INT UNSIGNED NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_booking (booking_id),
  KEY idx_projects_stage_due (stage, due_date),
  CONSTRAINT fk_projects_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_projects_user    FOREIGN KEY (released_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  old_stage   VARCHAR(20) NULL,
  new_stage   VARCHAR(20) NOT NULL,
  changed_by  INT UNSIGNED NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ph_project (project_id, changed_at),
  CONSTRAINT fk_ph_project FOREIGN KEY (project_id) REFERENCES projects (id),
  CONSTRAINT fk_ph_user    FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- client messages (A12)
CREATE TABLE message_templates (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_key  VARCHAR(50)  NOT NULL,
  title         VARCHAR(100) NOT NULL,
  body_tl       TEXT NOT NULL,                         -- Filipino
  body_en       TEXT NOT NULL,                         -- English
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_log (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id      INT UNSIGNED NOT NULL,
  template_key    VARCHAR(50) NULL,
  language        ENUM('tl','en') NOT NULL DEFAULT 'en',
  body            TEXT NOT NULL,
  channel         ENUM('copy','in_app') NOT NULL DEFAULT 'copy',
  sent_marked_by  INT UNSIGNED NULL,
  sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ml_booking (booking_id, sent_at),
  CONSTRAINT fk_ml_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_ml_user    FOREIGN KEY (sent_marked_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- in-app chat (C6 / A19)
-- booking_id NULL = pre-booking inquiry. Guests reach their chat via a random
-- token; only its SHA-256 hash is stored.
CREATE TABLE conversations (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id          INT UNSIGNED NULL,
  client_id           INT UNSIGNED NULL,
  guest_name          VARCHAR(120) NULL,
  guest_mobile        VARCHAR(20)  NULL,
  access_token_hash   CHAR(64) NULL,
  privacy_consent_at  DATETIME NULL,
  status              ENUM('open','closed') NOT NULL DEFAULT 'open',
  last_message_at     DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_booking (booking_id),
  UNIQUE KEY uq_conv_token (access_token_hash),
  KEY idx_conv_last (status, last_message_at),
  KEY idx_conv_client (client_id),
  CONSTRAINT fk_conv_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_conv_client  FOREIGN KEY (client_id)  REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- read_at = when the OTHER side saw it (Sent / Seen).
CREATE TABLE messages (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id  INT UNSIGNED NOT NULL,
  sender_type      ENUM('client','admin','system') NOT NULL,
  sender_user_id   INT UNSIGNED NULL,
  body             TEXT NOT NULL,                      -- plain text only, escaped on output
  attachment_path  VARCHAR(255) NULL,                  -- relative to storage/
  read_at          DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_conv_id (conversation_id, id),      -- cheap "since_id" polling
  KEY idx_messages_unread (sender_type, read_at),
  CONSTRAINT fk_messages_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id),
  CONSTRAINT fk_messages_user FOREIGN KEY (sender_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Internal notes: staff + assigned crew only, never shown to the client.
CREATE TABLE booking_notes (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id       INT UNSIGNED NOT NULL,
  body             TEXT NOT NULL,
  visible_to_crew  TINYINT(1) NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notes_booking (booking_id),
  CONSTRAINT fk_notes_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT fk_notes_user    FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quick_replies (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title      VARCHAR(60) NOT NULL,
  body       VARCHAR(1000) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- settings & terms (A20)
CREATE TABLE settings (
  setting_key    VARCHAR(60) NOT NULL,
  setting_value  TEXT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE terms_versions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version         VARCHAR(10) NOT NULL,
  body            TEXT NOT NULL,
  effective_from  DATE NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_terms_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- audit, backups, rate limits
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(100) NOT NULL,
  entity      VARCHAR(50) NULL,
  entity_id   INT UNSIGNED NULL,
  details     TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backup_runs (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  file_name             VARCHAR(255) NOT NULL,
  size_bytes            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  type                  ENUM('manual','scheduled') NOT NULL DEFAULT 'manual',
  created_by            INT UNSIGNED NULL,
  cloud_copy_marked_at  DATETIME NULL,                 -- owner confirms a cloud copy was made
  PRIMARY KEY (id),
  KEY idx_backup_created (created_at),
  CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  rl_key        VARCHAR(190) NOT NULL,
  window_start  INT UNSIGNED NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rl_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
