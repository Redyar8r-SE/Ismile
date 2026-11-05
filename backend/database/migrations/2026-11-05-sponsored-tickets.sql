ALTER TABLE registrations ADD COLUMN sponsoring_company VARCHAR(160) NULL AFTER comp_reason;
-- Sponsored students do not need to prove eligibility for a paid discount.
ALTER TABLE registrations DROP CHECK ck_reg_university, ADD CONSTRAINT ck_reg_university CHECK (ticket_type <> 'student' OR status <> 'paid' OR university IS NOT NULL);

CREATE TABLE IF NOT EXISTS sponsored_ticket_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_key CHAR(32) NOT NULL,
  partner_reference CHAR(12) NOT NULL,
  registration_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  guest_key CHAR(64) NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  guest_json JSON NOT NULL,
  request_json JSON NOT NULL,
  status ENUM('pending','uncertain','rejected','issued') NOT NULL DEFAULT 'pending',
  active_guest_key CHAR(64) NULL,
  provider_order_id VARCHAR(190) NULL,
  provider_amount BIGINT UNSIGNED NULL,
  provider_currency CHAR(3) NULL,
  email_status VARCHAR(40) NULL,
  whatsapp_status VARCHAR(40) NULL,
  raw_response MEDIUMTEXT NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sponsored_attempt (attempt_key),
  UNIQUE KEY uq_sponsored_reference (partner_reference),
  UNIQUE KEY uq_sponsored_guest (active_guest_key),
  CONSTRAINT ck_sponsored_active_guest CHECK ((status='rejected' AND active_guest_key IS NULL) OR (status<>'rejected' AND active_guest_key IS NOT NULL AND active_guest_key=guest_key)),
  UNIQUE KEY uq_sponsored_provider (provider_order_id),
  UNIQUE KEY uq_sponsored_registration (registration_id),
  CONSTRAINT fk_sponsored_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL,
  CONSTRAINT fk_sponsored_staff FOREIGN KEY (created_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sponsored guest issuance; provider cash-sale amounts are retained separately from guest income';
