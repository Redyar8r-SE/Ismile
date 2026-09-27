-- ============================================================================
-- iSmile 2026: the registrations database
-- ============================================================================
--
-- This file is always the COMPLETE, CURRENT design. It is run by
-- `php backend/tools/install.php` (safe to run again: existing tables are left
-- as they are). Changes to a database that is already in use go in
-- database/migrations/ (see the README there), never by editing live tables
-- by hand.
--
-- Rules the design follows:
--   * utf8mb4 everywhere, so Kurdish and Arabic are stored exactly as typed.
--   * Nothing is deleted by the application. Rows are marked instead
--     (status, removed_at, disabled_at, cancelled_at), so history stays.
--   * Every link between tables is a FOREIGN KEY: the database itself refuses
--     a payment for a registration that does not exist, a ticket for a
--     payment that does not exist, an action by an unknown admin, and so on.
--   * Fixed lists (status, ticket type, language, payment method...) are ENUMs:
--     the database refuses any value that is not on the list.
--   * Money is whole Iraqi dinars (INT UNSIGNED), never decimals.
--   * Times are the server's local time (Asia/Baghdad), set by the code.
--
-- Sections:
--   1. Admin accounts          admin_users
--   2. Waiting for payment     student_id_photos, checkouts (temporary: deleted if not paid)
--   3. Registrations           registrations (PAID people only), ambassadors
--   4. Money and tickets       payments, tickets
--   5. Workshops and sponsors  workshops, workshop_bookings, sponsor_packages,
--                              sponsor_requests, sponsor_calls
--   6. Messages                emails, webhook_log
--   7. System                  settings, audit_log, rate_limits, schema_migrations
--   8. The simple lists        01_registered_people … 12_money_per_day (numbered,
--                              plain column names, read-only: for everyone)
--
-- The one rule above all: a person is in "registrations" only after the
-- payment company confirmed the exact amount (or the Owner gave a free
-- ticket). An unpaid form is never a registration.

SET NAMES utf8mb4;


-- ============================================================================
-- 1. ADMIN ACCOUNTS
-- ============================================================================

-- People who can sign in to /admin/, and their role. Disabled, never deleted.
CREATE TABLE IF NOT EXISTS admin_users (
  id               INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  email            VARCHAR(190)      NOT NULL,                 -- stored lower-case
  name             VARCHAR(120)      NOT NULL,
  role             ENUM('owner','registration','finance','content','checkin') NOT NULL,
  password_hash    VARCHAR(255)      NOT NULL,                 -- bcrypt/argon, never the password
  totp_secret      VARCHAR(64)       NULL,                     -- the phone-code key
  totp_enabled     TINYINT(1)        NOT NULL DEFAULT 0,
  totp_last_step   BIGINT UNSIGNED   NULL,                     -- last phone code used: a code works only once
  session_version  INT UNSIGNED      NOT NULL DEFAULT 1,       -- raised on password change / phone reset: signs out old sessions
  failed_logins    TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  locked_until     DATETIME          NULL,
  last_login_at    DATETIME          NULL,
  disabled_at      DATETIME          NULL,
  created_at       DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email),
  CONSTRAINT ck_admin_totp CHECK (totp_enabled IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Admin accounts and roles';


-- ============================================================================
-- 2. WAITING FOR PAYMENT (temporary)
-- ============================================================================

-- Student ID photos, stored IN the database (not as loose files), so they
-- are in every backup and can only be seen through the admin after sign-in.
-- Each photo is checked and re-drawn as a clean JPEG on the server before it
-- is saved. Deleted automatically with an unpaid form, and for everyone
-- 90 days after the summit (Settings).
CREATE TABLE IF NOT EXISTS student_id_photos (
  id             INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  image          MEDIUMBLOB         NOT NULL,                  -- the photo itself (JPEG)
  mime           ENUM('image/jpeg') NOT NULL DEFAULT 'image/jpeg',
  width          SMALLINT UNSIGNED  NOT NULL,
  height         SMALLINT UNSIGNED  NOT NULL,
  bytes          INT UNSIGNED       NOT NULL,
  sha256         CHAR(64)           NOT NULL,                  -- fingerprint: the same photo sent twice is visible
  original_name  VARCHAR(190)       NULL,                      -- the file name on the student's phone
  uploaded_at    DATETIME           NOT NULL,
  uploaded_ip    VARCHAR(45)        NULL,
  PRIMARY KEY (id),
  KEY k_photo_sha (sha256),
  CONSTRAINT ck_photo_bytes CHECK (bytes > 0 AND bytes = OCTET_LENGTH(image))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Student ID photos (the images themselves)';

-- A filled-in form that has NOT been paid yet. It is not a registration: it
-- never appears in the registrations, the lists or the counts. The server
-- keeps it only so that it knows who is paying while the person is on
-- Psoola's page. When Psoola confirms the money, it becomes a registration;
-- if nobody pays, it is deleted automatically (with the student ID photo).
CREATE TABLE IF NOT EXISTS checkouts (
  id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  ref                CHAR(12)          NOT NULL,               -- ISM26-XXXXXX; the registration keeps the same reference
  source             ENUM('website','office') NOT NULL DEFAULT 'website',
  status             ENUM('open','paid','expired') NOT NULL DEFAULT 'open',
  -- the form, exactly as the registration will be
  first_name         VARCHAR(60)       NOT NULL,
  father_name        VARCHAR(60)       NOT NULL,
  grandfather_name   VARCHAR(60)       NOT NULL,
  phone              VARCHAR(20)       NOT NULL,
  email              VARCHAR(190)      NOT NULL,
  city               VARCHAR(80)       NOT NULL,
  gender             ENUM('female','male','other','prefer-not') NOT NULL,
  age                TINYINT UNSIGNED  NULL,
  specialty          ENUM('gp','spec','omfs','lab','acad','student') NOT NULL,
  lang               ENUM('en','ar','ku') NOT NULL DEFAULT 'en',
  ticket_type        ENUM('professional','student') NOT NULL,
  lunch_day1         TINYINT(1)        NOT NULL DEFAULT 0,
  lunch_day2         TINYINT(1)        NOT NULL DEFAULT 0,
  pay_method         ENUM('visa','mastercard','fib','fastpay') NOT NULL DEFAULT 'visa',
  university         VARCHAR(160)      NULL,                   -- students
  ambassador_code    VARCHAR(40)       NULL,
  id_photo_id        INT UNSIGNED      NULL,                   -- the student ID photo (student_id_photos)
  terms_accepted_at  DATETIME          NULL,                   -- ticked "tickets are non-refundable" (website form)
  view_nonce         CHAR(32)          NOT NULL,               -- with the secret: their payment page link
  registration_id    INT UNSIGNED      NULL,                   -- set when paid
  created_by         INT UNSIGNED      NULL,                   -- the staff member, for a phone registration
  created_ip         VARCHAR(45)       NULL,
  created_at         DATETIME          NOT NULL,
  expires_at         DATETIME          NOT NULL,               -- after this, it cannot be paid any more
  PRIMARY KEY (id),
  UNIQUE KEY uq_checkout_ref (ref),
  KEY k_checkout_status_expires (status, expires_at),          -- clean-up job, "paying now" holds
  CONSTRAINT fk_checkout_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id),
  CONSTRAINT fk_checkout_photo      FOREIGN KEY (id_photo_id) REFERENCES student_id_photos (id) ON DELETE SET NULL,
  CONSTRAINT ck_checkout_lunch CHECK (lunch_day1 IN (0, 1) AND lunch_day2 IN (0, 1)),
  CONSTRAINT ck_checkout_student CHECK (specialty <> 'student' OR ticket_type = 'student'),
  CONSTRAINT ck_checkout_university CHECK (ticket_type <> 'student' OR university IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Forms waiting for payment (temporary, not registrations)';


-- ============================================================================
-- 3. REGISTRATIONS (paid people only)
-- ============================================================================

-- One row per person registered for the event. A row exists ONLY after the
-- payment was confirmed by Psoola (or the Owner gave a free ticket).
CREATE TABLE IF NOT EXISTS registrations (
  id                 INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  ref                CHAR(12)          NOT NULL,               -- ISM26-XXXXXX
  -- the person
  first_name         VARCHAR(60)       NOT NULL,
  father_name        VARCHAR(60)       NOT NULL,
  grandfather_name   VARCHAR(60)       NOT NULL,
  phone              VARCHAR(20)       NOT NULL,               -- normalised: +9647XXXXXXXXX or +<international>
  email              VARCHAR(190)      NOT NULL,
  city               VARCHAR(80)       NOT NULL,
  gender             ENUM('female','male','other','prefer-not') NOT NULL,
  age                TINYINT UNSIGNED  NULL,
  specialty          ENUM('gp','spec','omfs','lab','acad','student') NOT NULL,
  lang               ENUM('en','ar','ku') NOT NULL DEFAULT 'en',  -- language of their emails and ticket
  -- what they chose and paid for
  ticket_type        ENUM('professional','student') NOT NULL,
  lunch_day1         TINYINT(1)        NOT NULL DEFAULT 0,
  lunch_day2         TINYINT(1)        NOT NULL DEFAULT 0,
  pay_method         ENUM('visa','mastercard','fib','fastpay') NULL,   -- NULL for a free ticket
  -- students: kept as a record (no approval step)
  university         VARCHAR(160)      NULL,
  ambassador_code    VARCHAR(40)       NULL,                   -- as typed; unknown codes are shown in Settings
  id_photo_id        INT UNSIGNED      NULL,                   -- the student ID photo; NULL after it is deleted (90 days after the summit)
  id_photo_deleted_at DATETIME         NULL,
  terms_accepted_at  DATETIME          NULL,                   -- when they accepted "tickets are non-refundable" (proof)
  -- where it stands
  status             ENUM('paid','complimentary','cancelled') NOT NULL,
  possible_duplicate TINYINT(1)        NOT NULL DEFAULT 0,     -- same phone or email as another registration
  comp_reason        VARCHAR(255)      NULL,                   -- why the Owner gave a free ticket
  notes              TEXT              NULL,                   -- office notes
  view_nonce         CHAR(32)          NOT NULL,               -- with the secret: their own ticket page link
  -- record keeping
  created_by         INT UNSIGNED      NULL,                   -- the staff member, for phone / free tickets
  created_ip         VARCHAR(45)       NULL,
  created_at         DATETIME          NOT NULL,               -- when the form was sent
  paid_at            DATETIME          NOT NULL,               -- when the payment was confirmed (or the free ticket given)
  updated_at         DATETIME          NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_ref (ref),
  KEY k_reg_status_paid (status, paid_at),
  KEY k_reg_email (email),                                     -- duplicate check, search
  KEY k_reg_phone (phone),
  KEY k_reg_ambassador (ambassador_code),
  CONSTRAINT fk_reg_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id),
  CONSTRAINT fk_reg_photo      FOREIGN KEY (id_photo_id) REFERENCES student_id_photos (id) ON DELETE SET NULL,
  CONSTRAINT ck_reg_lunch CHECK (lunch_day1 IN (0, 1) AND lunch_day2 IN (0, 1)),
  CONSTRAINT ck_reg_student CHECK (specialty <> 'student' OR ticket_type = 'student'),
  CONSTRAINT ck_reg_university CHECK (ticket_type <> 'student' OR university IS NOT NULL),
  CONSTRAINT ck_reg_age CHECK (age IS NULL OR age BETWEEN 16 AND 120),
  CONSTRAINT ck_reg_free CHECK (status <> 'complimentary' OR comp_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered people (paid or free ticket only)';

-- Ambassador codes, so student registrations can be counted per ambassador.
CREATE TABLE IF NOT EXISTS ambassadors (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)   NOT NULL,
  owner_name  VARCHAR(120)  NOT NULL,
  university  VARCHAR(160)  NULL,
  active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ambassador_code (code)                         -- the collation makes it case-insensitive
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ambassador codes';


-- ============================================================================
-- 4. MONEY AND TICKETS
-- ============================================================================

-- One row per payment attempt, made for a form waiting for payment. When
-- Psoola confirms it, the registration is created and linked here.
CREATE TABLE IF NOT EXISTS payments (
  id                   INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  checkout_id          INT UNSIGNED       NULL,                -- the form being paid (NULL once that is cleaned up)
  registration_id      INT UNSIGNED       NULL,                -- the registration this payment created or belongs to
  gateway              ENUM('fake','psoola') NOT NULL,         -- fake = the pretend gateway on the test site
  provider_payment_id  VARCHAR(120)       NULL,                -- Psoola's id for this payment
  method               ENUM('visa','mastercard','fib','fastpay') NULL,
  amount_expected      INT UNSIGNED       NOT NULL,            -- set by the server when the attempt is made
  currency             CHAR(3)            NOT NULL DEFAULT 'IQD',
  amount_confirmed     INT UNSIGNED       NULL,                -- what Psoola itself confirmed
  status               ENUM('created','waiting','paid','failed','expired','mismatch','duplicate','kept') NOT NULL DEFAULT 'created',
                       -- mismatch = wrong amount, duplicate = paid twice: no ticket, held for Finance;
                       -- kept = Finance reviewed it. iSmile does not refund money.
  redirect_url         TEXT               NULL,                -- Psoola's payment page for this attempt
  last_error           VARCHAR(500)       NULL,
  raw_create           MEDIUMTEXT         NULL,                -- Psoola's answers, kept for disputes
  raw_status           MEDIUMTEXT         NULL,
  checks               SMALLINT UNSIGNED  NOT NULL DEFAULT 0,  -- how often we asked Psoola
  created_at           DATETIME           NOT NULL,
  updated_at           DATETIME           NOT NULL,
  confirmed_at         DATETIME           NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pay_provider (gateway, provider_payment_id),   -- each Psoola payment is recorded once
  KEY k_pay_checkout (checkout_id, status),
  KEY k_pay_registration (registration_id),
  KEY k_pay_status_created (status, created_at),               -- the 5-minute job, the Payments page
  KEY k_pay_confirmed (confirmed_at),                          -- daily totals
  CONSTRAINT fk_pay_checkout     FOREIGN KEY (checkout_id)     REFERENCES checkouts (id) ON DELETE SET NULL,
  CONSTRAINT fk_pay_registration FOREIGN KEY (registration_id) REFERENCES registrations (id),
  CONSTRAINT ck_pay_amount CHECK (amount_expected > 0),
  CONSTRAINT ck_pay_paid CHECK (status <> 'paid' OR (registration_id IS NOT NULL AND amount_confirmed = amount_expected))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payment attempts';

-- Tickets: exactly one per registered person, made only from a confirmed
-- payment or by the Owner as a free ticket.
CREATE TABLE IF NOT EXISTS tickets (
  id               INT UNSIGNED       NOT NULL AUTO_INCREMENT,
  registration_id  INT UNSIGNED       NOT NULL,
  ticket_no        VARCHAR(16)        NOT NULL,                -- T26-00001
  version          SMALLINT UNSIGNED  NOT NULL DEFAULT 1,      -- raised when a name change reissues it; old QR stops working
  source           ENUM('payment','complimentary') NOT NULL,
  payment_id       INT UNSIGNED       NULL,                    -- the payment that paid for it
  issued_by        INT UNSIGNED       NULL,                    -- the Owner, for free tickets
  created_at       DATETIME           NOT NULL,
  checked_in_at    DATETIME           NULL,
  checked_in_by    INT UNSIGNED       NULL,
  cancelled_at     DATETIME           NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_registration (registration_id),
  UNIQUE KEY uq_ticket_no (ticket_no),
  UNIQUE KEY uq_ticket_payment (payment_id),                   -- one payment never makes two tickets
  KEY k_ticket_checked_in (checked_in_at),
  CONSTRAINT fk_ticket_registration FOREIGN KEY (registration_id) REFERENCES registrations (id),
  CONSTRAINT fk_ticket_payment      FOREIGN KEY (payment_id)      REFERENCES payments (id),
  CONSTRAINT fk_ticket_issued_by    FOREIGN KEY (issued_by)       REFERENCES admin_users (id),
  CONSTRAINT fk_ticket_checked_by   FOREIGN KEY (checked_in_by)   REFERENCES admin_users (id),
  CONSTRAINT ck_ticket_source CHECK ((source = 'payment' AND payment_id IS NOT NULL) OR (source = 'complimentary' AND payment_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tickets';


-- ============================================================================
-- 5. WORKSHOPS AND SPONSORS
-- ============================================================================

-- The workshops themselves. Added, changed and hidden on the admin's
-- Workshops page (Owner); the website's workshop cards are made from this
-- table automatically. total_seats is the limit: nobody can book past it.
CREATE TABLE IF NOT EXISTS workshops (
  id           VARCHAR(40)        NOT NULL,                   -- short name used in links, e.g. "implant"
  icon         VARCHAR(20)        NOT NULL DEFAULT 'tools',   -- the picture on the website card
  title_en     VARCHAR(160)       NULL,                       -- empty = "Coming soon" on the website
  title_ar     VARCHAR(160)       NULL,
  title_ku     VARCHAR(160)       NULL,
  company_en   VARCHAR(160)       NULL,
  company_ar   VARCHAR(160)       NULL,
  company_ku   VARCHAR(160)       NULL,
  speaker_en   VARCHAR(160)       NULL,
  speaker_ar   VARCHAR(160)       NULL,
  speaker_ku   VARCHAR(160)       NULL,
  price        INT UNSIGNED       NOT NULL DEFAULT 0,         -- IQD per seat; 0 = price not set yet
  total_seats  SMALLINT UNSIGNED  NOT NULL,                   -- the limit
  status       ENUM('active','hidden') NOT NULL DEFAULT 'active',   -- hidden = not on the website, no new bookings
  sort_order   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at   DATETIME           NOT NULL,
  updated_at   DATETIME           NOT NULL,
  PRIMARY KEY (id),
  KEY k_workshop_status (status, sort_order),
  CONSTRAINT ck_workshop_seats CHECK (total_seats BETWEEN 1 AND 2000),
  CONSTRAINT ck_workshop_id CHECK (id REGEXP '^[a-z0-9-]{2,40}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workshops (managed in the admin)';

-- Workshops are booked by phone; the office records each booking here.
-- Only registered (paid) people can be booked. A workshop is "paid" only
-- with exactly the agreed amount. Workshop money is not refunded.
CREATE TABLE IF NOT EXISTS workshop_bookings (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  registration_id   INT UNSIGNED  NOT NULL,
  workshop_id       VARCHAR(40)   NOT NULL,                    -- which workshop (workshops.id)
  price_agreed      INT UNSIGNED  NOT NULL DEFAULT 0,          -- IQD, fixed when booked
  payment_status    ENUM('unpaid','paid','complimentary') NOT NULL DEFAULT 'unpaid',
  amount_paid       INT UNSIGNED  NULL,                        -- IQD received; must equal price_agreed
  paid_how          ENUM('cash','transfer','psoola') NULL,
  paid_at           DATETIME      NULL,
  paid_recorded_by  INT UNSIGNED  NULL,                        -- who took / recorded the money
  booked_by         INT UNSIGNED  NULL,
  over_capacity     TINYINT(1)    NOT NULL DEFAULT 0,          -- the Owner booked it although it was full
  notes             VARCHAR(500)  NULL,
  created_at        DATETIME      NOT NULL,
  updated_at        DATETIME      NOT NULL,
  removed_at        DATETIME      NULL,                        -- a removed booking frees the seat
  removed_by        INT UNSIGNED  NULL,
  -- 1 while the booking is active, NULL once removed: lets the database refuse
  -- the same person twice on one workshop, while keeping removed history.
  active            TINYINT(1) AS (IF(removed_at IS NULL, 1, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wb_person_workshop (registration_id, workshop_id, active),
  KEY k_wb_workshop (workshop_id, removed_at),                 -- seats left per workshop
  KEY k_wb_registration (registration_id),
  CONSTRAINT fk_wb_registration FOREIGN KEY (registration_id)  REFERENCES registrations (id),
  CONSTRAINT fk_wb_workshop     FOREIGN KEY (workshop_id)      REFERENCES workshops (id),
  CONSTRAINT fk_wb_booked_by    FOREIGN KEY (booked_by)        REFERENCES admin_users (id),
  CONSTRAINT fk_wb_paid_by      FOREIGN KEY (paid_recorded_by) REFERENCES admin_users (id),
  CONSTRAINT fk_wb_removed_by   FOREIGN KEY (removed_by)       REFERENCES admin_users (id),
  CONSTRAINT ck_wb_paid CHECK (
       (payment_status = 'paid'          AND amount_paid = price_agreed AND paid_how IS NOT NULL AND paid_at IS NOT NULL)
    OR (payment_status = 'unpaid'        AND amount_paid IS NULL AND paid_how IS NULL)
    OR (payment_status = 'complimentary' AND amount_paid IS NULL AND paid_how IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workshop bookings (by phone)';

-- The sponsorship packages (Diamond, Platinum, Gold, Silver…) and the
-- exhibition booth types, each with its price and number of places. Managed
-- by the Owner on the admin's Sponsors page; the website's tier cards are made
-- from this table automatically.
CREATE TABLE IF NOT EXISTS sponsor_packages (
  id           VARCHAR(40)        NOT NULL,                   -- short name, e.g. "gold", "booth-standard"
  kind         ENUM('sponsor','booth') NOT NULL,              -- sponsorship, or an exhibition booth
  name_en      VARCHAR(80)        NOT NULL,
  name_ar      VARCHAR(80)        NULL,
  name_ku      VARCHAR(80)        NULL,
  subtitle_en  VARCHAR(160)       NULL,                       -- e.g. "Headline partners"
  subtitle_ar  VARCHAR(160)       NULL,
  subtitle_ku  VARCHAR(160)       NULL,
  price        INT UNSIGNED       NOT NULL DEFAULT 0,         -- IQD, the list price told on the phone; 0 = not set yet
  places       SMALLINT UNSIGNED  NOT NULL DEFAULT 0,         -- how many can be sold; 0 = no limit
  style        VARCHAR(30)        NOT NULL DEFAULT 'tc-silver', -- the colour of the website card
  status       ENUM('active','hidden') NOT NULL DEFAULT 'active',
  sort_order   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
  created_at   DATETIME           NOT NULL,
  updated_at   DATETIME           NOT NULL,
  PRIMARY KEY (id),
  KEY k_package_kind (kind, status, sort_order),
  CONSTRAINT ck_package_id CHECK (id REGEXP '^[a-z0-9-]{2,40}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sponsor packages and booth types, with prices';

-- Sponsorship and booth requests: handled by a person, never sold like tickets.
-- The steps: New → Contacted (called) → Agreed (amount agreed) → Paid → Confirmed,
-- or Declined / Waiting list.
CREATE TABLE IF NOT EXISTS sponsor_requests (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ref            CHAR(11)      NOT NULL,                       -- SPN26-XXXXX
  kind           ENUM('sponsor','booth') NOT NULL,
  package_id     VARCHAR(40)   NULL,                           -- the package they want; NULL = not sure yet
  -- who they are (exactly what the website form collects)
  company        VARCHAR(160)  NOT NULL,
  contact_name   VARCHAR(120)  NOT NULL,
  contact_role   VARCHAR(120)  NULL,
  phone          VARCHAR(20)   NOT NULL,
  email          VARCHAR(190)  NOT NULL,
  website        VARCHAR(190)  NULL,
  city           VARCHAR(80)   NULL,
  message        TEXT          NULL,
  lang           ENUM('en','ar','ku') NOT NULL DEFAULT 'en',
  -- where it stands
  status         ENUM('new','contacted','agreed','paid','confirmed','declined','waiting_list') NOT NULL DEFAULT 'new',
  assigned_to    INT UNSIGNED  NULL,                           -- the team member handling it
  price_quoted   INT UNSIGNED  NULL,                           -- IQD, the amount last told on the phone
  amount_agreed  INT UNSIGNED  NULL,                           -- IQD, the amount they agreed to pay
  amount_paid    INT UNSIGNED  NULL,                           -- IQD, received
  paid_how       ENUM('cash','transfer','psoola','other') NULL,
  paid_at        DATETIME      NULL,
  booth_number   VARCHAR(20)   NULL,                           -- where their booth is (exhibition)
  last_call_at   DATETIME      NULL,
  next_call_at   DATETIME      NULL,                           -- when to call them again
  notes          TEXT          NULL,
  created_ip     VARCHAR(45)   NULL,
  created_at     DATETIME      NOT NULL,
  updated_at     DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sponsor_ref (ref),
  KEY k_sponsor_status (status, created_at),
  KEY k_sponsor_package (kind, package_id, status),            -- places taken per package
  KEY k_sponsor_next_call (next_call_at),                      -- "calls due today"
  CONSTRAINT fk_sponsor_assigned FOREIGN KEY (assigned_to) REFERENCES admin_users (id),
  CONSTRAINT fk_sponsor_package  FOREIGN KEY (package_id)  REFERENCES sponsor_packages (id),
  CONSTRAINT ck_sponsor_paid CHECK (status NOT IN ('paid','confirmed') OR (amount_paid IS NOT NULL AND paid_at IS NOT NULL AND paid_how IS NOT NULL)),
  CONSTRAINT ck_sponsor_agreed CHECK (status NOT IN ('agreed','paid','confirmed') OR amount_agreed IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sponsor and booth requests';

-- Every phone call with a sponsor or exhibitor: who called, when, what
-- happened, which amount was told, and when to call again.
CREATE TABLE IF NOT EXISTS sponsor_calls (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  request_id      INT UNSIGNED  NOT NULL,
  called_by       INT UNSIGNED  NULL,
  called_at       DATETIME      NOT NULL,
  outcome         ENUM('reached','no_answer','call_back','interested','agreed','declined') NOT NULL,
  amount_quoted   INT UNSIGNED  NULL,                          -- IQD told on this call
  note            VARCHAR(500)  NULL,
  next_call_at    DATETIME      NULL,
  PRIMARY KEY (id),
  KEY k_call_request (request_id, called_at),
  CONSTRAINT fk_call_request FOREIGN KEY (request_id) REFERENCES sponsor_requests (id),
  CONSTRAINT fk_call_by      FOREIGN KEY (called_by)  REFERENCES admin_users (id),
  CONSTRAINT ck_call_agreed CHECK (outcome <> 'agreed' OR amount_quoted IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Phone calls with sponsors and exhibitors';



-- ============================================================================
-- 6. MESSAGES
-- ============================================================================

-- Every email the system sends, as a queue. The content is written at send
-- time from the registration, so a resend after fixing an address is right.
CREATE TABLE IF NOT EXISTS emails (
  id                   INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  kind                 VARCHAR(30)       NOT NULL,             -- ticket, pay_now, alert, sponsor_received, sponsor_notify
  registration_id      INT UNSIGNED      NULL,
  checkout_id          INT UNSIGNED      NULL,                 -- a "pay now" link for a phone registration
  sponsor_request_id   INT UNSIGNED      NULL,
  to_email             VARCHAR(190)      NOT NULL,
  lang                 ENUM('en','ar','ku') NOT NULL DEFAULT 'en',
  data                 TEXT              NULL,                 -- extra details as JSON (e.g. a rejection reason)
  status               ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts             TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  next_attempt_at      DATETIME          NOT NULL,
  last_error           VARCHAR(500)      NULL,
  provider_message_id  VARCHAR(190)      NULL,                 -- Brevo's id, to trace a delivery
  created_at           DATETIME          NOT NULL,
  sent_at              DATETIME          NULL,
  PRIMARY KEY (id),
  KEY k_email_queue (status, next_attempt_at),                 -- the sending job
  KEY k_email_registration (registration_id, kind),
  KEY k_email_sponsor (sponsor_request_id),
  KEY k_email_checkout (checkout_id),
  CONSTRAINT fk_email_registration FOREIGN KEY (registration_id)    REFERENCES registrations (id),
  CONSTRAINT fk_email_checkout     FOREIGN KEY (checkout_id)        REFERENCES checkouts (id) ON DELETE CASCADE,
  CONSTRAINT fk_email_sponsor      FOREIGN KEY (sponsor_request_id) REFERENCES sponsor_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Email queue';

-- Every message the payment company sent us, and what we did with it.
CREATE TABLE IF NOT EXISTS webhook_log (
  id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  received_at          DATETIME      NOT NULL,
  ip                   VARCHAR(45)   NULL,
  gateway              ENUM('fake','psoola') NOT NULL,
  signature_ok         TINYINT(1)    NOT NULL DEFAULT 0,
  provider_payment_id  VARCHAR(120)  NULL,
  outcome              VARCHAR(80)   NOT NULL,                 -- e.g. "waiting -> paid", "REJECTED: signature"
  headers              TEXT          NULL,
  body                 MEDIUMTEXT    NULL,
  PRIMARY KEY (id),
  KEY k_webhook_received (received_at),
  KEY k_webhook_outcome (outcome, received_at),                -- counting forged messages
  KEY k_webhook_provider (provider_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Messages from the payment company';


-- ============================================================================
-- 7. SYSTEM
-- ============================================================================

-- Settings changed on the admin's Settings page (open/closed, capacity, test mode...).
-- Prices are not here: they stay in data/tickets.json, edited in the admin.
CREATE TABLE IF NOT EXISTS settings (
  k           VARCHAR(60)  NOT NULL,
  v           TEXT         NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Owner settings';

-- Who did what, and when. Never edited, never deleted.
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED  NULL,                             -- NULL = the system itself
  action       VARCHAR(60)   NOT NULL,                         -- e.g. student.approve, payment.confirmed
  target_type  VARCHAR(30)   NULL,
  target_id    INT UNSIGNED  NULL,
  details      TEXT          NULL,
  ip           VARCHAR(45)   NULL,
  created_at   DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY k_audit_target (target_type, target_id),
  KEY k_audit_user (user_id, created_at),
  KEY k_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit log';

-- Counters for rate limits (form submissions, sign-ins, pay links).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket        VARCHAR(120)  NOT NULL,
  window_start  INT UNSIGNED  NOT NULL,                        -- Unix time
  hits          INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start),
  KEY k_rate_window (window_start)                             -- nightly clean-up
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rate-limit counters';

-- Which files in database/migrations/ this database already has.
CREATE TABLE IF NOT EXISTS schema_migrations (
  name        VARCHAR(120)  NOT NULL,
  applied_at  DATETIME      NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Applied database upgrades';


-- ============================================================================
-- 8. THE SIMPLE LISTS (for everyone)
-- ============================================================================
-- Ready-made, numbered lists with plain column names, for anyone who opens the
-- database (for example with MySQL Workbench). They only READ; nothing can be
-- changed through them. A "viewer" login (tools/install.php --viewer) sees
-- ONLY these lists, never the tables behind them.
-- SQL SECURITY DEFINER: they read the tables with the installer's rights, so
-- the viewer login needs no rights on the tables themselves.

-- Everyone registered for the event (paid, or a free ticket from the Owner).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `01_registered_people` AS
SELECT
  r.ref                                                              AS `Reference`,
  CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name)    AS `Full name`,
  r.phone                                                            AS `Phone`,
  r.email                                                            AS `Email`,
  r.city                                                             AS `City`,
  IF(r.ticket_type = 'student', 'Student', 'Professional')           AS `Ticket`,
  r.university                                                       AS `University`,
  IF(r.lunch_day1 = 1, 'Yes', '')                                    AS `Lunch day 1`,
  IF(r.lunch_day2 = 1, 'Yes', '')                                    AS `Lunch day 2`,
  CASE r.status WHEN 'paid' THEN 'Paid' WHEN 'complimentary' THEN 'Free ticket' ELSE 'Cancelled' END AS `Status`,
  (SELECT p.amount_confirmed FROM payments p WHERE p.registration_id = r.id AND p.status = 'paid' LIMIT 1) AS `Paid (IQD)`,
  UPPER(r.pay_method)                                                AS `Paid by`,
  r.paid_at                                                          AS `Paid on`,
  t.ticket_no                                                        AS `Ticket number`,
  t.checked_in_at                                                    AS `Arrived at the door`,
  IF(r.terms_accepted_at IS NULL, '', 'Yes')                         AS `Accepted no-refund terms`
FROM registrations r
LEFT JOIN tickets t ON t.registration_id = r.id;

-- The caterer's lists.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `02_lunch_day_1` AS
SELECT CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) AS `Full name`, r.phone AS `Phone`,
       IF(r.ticket_type = 'student', 'Student', 'Professional') AS `Ticket`, r.ref AS `Reference`
FROM registrations r WHERE r.status IN ('paid','complimentary') AND r.lunch_day1 = 1;

CREATE OR REPLACE SQL SECURITY DEFINER VIEW `03_lunch_day_2` AS
SELECT CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) AS `Full name`, r.phone AS `Phone`,
       IF(r.ticket_type = 'student', 'Student', 'Professional') AS `Ticket`, r.ref AS `Reference`
FROM registrations r WHERE r.status IN ('paid','complimentary') AND r.lunch_day2 = 1;

-- Registered students (the ID photos are seen in the admin).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `04_students` AS
SELECT CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) AS `Full name`, r.phone AS `Phone`,
       r.university AS `University`, r.ambassador_code AS `Ambassador code`,
       IF(r.id_photo_id IS NULL, 'No', 'Yes') AS `ID photo stored`, r.ref AS `Reference`
FROM registrations r WHERE r.ticket_type = 'student' AND r.status IN ('paid','complimentary');

-- Every workshop: price, seats, how many booked and paid, the money.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `05_workshops` AS
SELECT
  COALESCE(w.title_en, CONCAT(w.id, ' (title not announced)'))  AS `Workshop`,
  w.price                                                       AS `Price (IQD)`,
  w.total_seats                                                 AS `Seats`,
  COUNT(wb.id)                                                  AS `Booked`,
  w.total_seats - COUNT(wb.id)                                  AS `Seats left`,
  COALESCE(SUM(wb.payment_status = 'paid'), 0)                  AS `Paid`,
  COALESCE(SUM(wb.payment_status = 'unpaid'), 0)                AS `Not paid yet`,
  COALESCE(SUM(wb.amount_paid), 0)                              AS `Money received (IQD)`,
  IF(w.status = 'active', 'Shown', 'Hidden')                    AS `On the website`
FROM workshops w
LEFT JOIN workshop_bookings wb ON wb.workshop_id = w.id AND wb.removed_at IS NULL
GROUP BY w.id, w.title_en, w.price, w.total_seats, w.status, w.sort_order
ORDER BY w.sort_order;

-- Who booked which workshop.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `06_workshop_people` AS
SELECT
  COALESCE(w.title_en, w.id)                                         AS `Workshop`,
  CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name)    AS `Full name`,
  r.phone                                                            AS `Phone`,
  CASE wb.payment_status WHEN 'paid' THEN 'Paid' WHEN 'complimentary' THEN 'Free' ELSE 'Not paid' END AS `Paid?`,
  wb.amount_paid                                                     AS `Amount paid (IQD)`,
  wb.paid_how                                                        AS `Paid how`,
  wb.created_at                                                      AS `Booked on`,
  r.ref                                                              AS `Reference`
FROM workshop_bookings wb
JOIN workshops w     ON w.id = wb.workshop_id
JOIN registrations r ON r.id = wb.registration_id
WHERE wb.removed_at IS NULL
ORDER BY w.sort_order, `Full name`;

-- Sponsorship requests.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `07_sponsors` AS
SELECT
  s.ref                                                  AS `Reference`,
  s.company                                              AS `Company`,
  COALESCE(p.name_en, 'Not sure yet')                    AS `Package`,
  s.contact_name                                         AS `Contact person`,
  s.phone                                                AS `Phone`,
  s.email                                                AS `Email`,
  CASE s.status WHEN 'new' THEN 'New' WHEN 'contacted' THEN 'Contacted' WHEN 'agreed' THEN 'Agreed'
    WHEN 'paid' THEN 'Paid' WHEN 'confirmed' THEN 'Confirmed' WHEN 'declined' THEN 'Declined' ELSE 'Waiting list' END AS `Status`,
  s.price_quoted                                         AS `Price told (IQD)`,
  s.amount_agreed                                        AS `Agreed (IQD)`,
  s.amount_paid                                          AS `Paid (IQD)`,
  s.last_call_at                                         AS `Last call`,
  s.next_call_at                                         AS `Next call`,
  u.name                                                 AS `Handled by`,
  s.created_at                                           AS `Received on`
FROM sponsor_requests s
LEFT JOIN sponsor_packages p ON p.id = s.package_id
LEFT JOIN admin_users u      ON u.id = s.assigned_to
WHERE s.kind = 'sponsor';

-- Exhibition booth requests (separate from the sponsors).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `08_exhibition` AS
SELECT
  s.ref                                                  AS `Reference`,
  s.company                                              AS `Company`,
  COALESCE(p.name_en, 'Booth (type not chosen)')         AS `Booth type`,
  s.booth_number                                         AS `Booth number`,
  s.contact_name                                         AS `Contact person`,
  s.phone                                                AS `Phone`,
  s.email                                                AS `Email`,
  CASE s.status WHEN 'new' THEN 'New' WHEN 'contacted' THEN 'Contacted' WHEN 'agreed' THEN 'Agreed'
    WHEN 'paid' THEN 'Paid' WHEN 'confirmed' THEN 'Confirmed' WHEN 'declined' THEN 'Declined' ELSE 'Waiting list' END AS `Status`,
  s.price_quoted                                         AS `Price told (IQD)`,
  s.amount_agreed                                        AS `Agreed (IQD)`,
  s.amount_paid                                          AS `Paid (IQD)`,
  s.last_call_at                                         AS `Last call`,
  s.next_call_at                                         AS `Next call`,
  u.name                                                 AS `Handled by`,
  s.created_at                                           AS `Received on`
FROM sponsor_requests s
LEFT JOIN sponsor_packages p ON p.id = s.package_id
LEFT JOIN admin_users u      ON u.id = s.assigned_to
WHERE s.kind = 'booth';

-- Every phone call with a sponsor or exhibitor, newest first.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `09_sponsor_calls` AS
SELECT
  s.company                                              AS `Company`,
  IF(s.kind = 'booth', 'Exhibition', 'Sponsor')          AS `Type`,
  c.called_at                                            AS `Called on`,
  u.name                                                 AS `Called by`,
  CASE c.outcome WHEN 'reached' THEN 'Talked' WHEN 'no_answer' THEN 'No answer' WHEN 'call_back' THEN 'Call back later'
    WHEN 'interested' THEN 'Interested' WHEN 'agreed' THEN 'Agreed' ELSE 'Declined' END AS `Result`,
  c.amount_quoted                                        AS `Amount told (IQD)`,
  c.note                                                 AS `Note`,
  c.next_call_at                                         AS `Next call`
FROM sponsor_calls c
JOIN sponsor_requests s ON s.id = c.request_id
LEFT JOIN admin_users u ON u.id = c.called_by
ORDER BY c.called_at DESC;

-- The packages and booth types with their prices and places.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `10_sponsor_packages` AS
SELECT
  IF(p.kind = 'booth', 'Exhibition booth', 'Sponsorship')                AS `Type`,
  p.name_en                                                              AS `Package`,
  p.price                                                                AS `Price (IQD)`,
  IF(p.places = 0, 'No limit', p.places)                                 AS `Places`,
  (SELECT COUNT(*) FROM sponsor_requests s WHERE s.package_id = p.id AND s.status = 'confirmed') AS `Confirmed`,
  IF(p.places = 0, '', p.places - (SELECT COUNT(*) FROM sponsor_requests s WHERE s.package_id = p.id AND s.status = 'confirmed')) AS `Places left`,
  IF(p.status = 'active', 'Shown', 'Hidden')                              AS `On the website`
FROM sponsor_packages p
ORDER BY p.kind, p.sort_order;

-- Every payment attempt for event tickets.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `11_payments` AS
SELECT
  COALESCE(r.ref, c.ref)                                                   AS `Reference`,
  COALESCE(CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name),
           CONCAT_WS(' ', c.first_name, c.father_name, c.grandfather_name), '(form deleted)') AS `Full name`,
  UPPER(p.method)                                                          AS `Method`,
  p.amount_expected                                                        AS `Amount (IQD)`,
  p.amount_confirmed                                                       AS `Confirmed (IQD)`,
  CASE p.status WHEN 'paid' THEN 'Paid' WHEN 'waiting' THEN 'Paying now' WHEN 'created' THEN 'Starting'
    WHEN 'failed' THEN 'Failed' WHEN 'expired' THEN 'Not completed' WHEN 'mismatch' THEN 'Wrong amount (Finance)'
    WHEN 'duplicate' THEN 'Paid twice (Finance)' ELSE 'Reviewed by Finance' END AS `Status`,
  p.created_at                                                             AS `Started`,
  p.confirmed_at                                                           AS `Confirmed on`
FROM payments p
LEFT JOIN registrations r ON r.id = p.registration_id
LEFT JOIN checkouts c     ON c.id = p.checkout_id
ORDER BY p.id DESC;

-- The money per day, to compare with Psoola's report.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW `12_money_per_day` AS
SELECT
  DATE(p.confirmed_at)                                                   AS `Day`,
  SUM(p.status = 'paid')                                                 AS `Tickets paid`,
  COALESCE(SUM(IF(p.status = 'paid', p.amount_confirmed, 0)), 0)         AS `Ticket money (IQD)`,
  COALESCE(SUM(IF(p.status IN ('mismatch','duplicate','kept'), p.amount_confirmed, 0)), 0) AS `Held for Finance (IQD)`
FROM payments p
WHERE p.confirmed_at IS NOT NULL AND p.status IN ('paid','mismatch','duplicate','kept')
GROUP BY DATE(p.confirmed_at)
ORDER BY `Day` DESC;
