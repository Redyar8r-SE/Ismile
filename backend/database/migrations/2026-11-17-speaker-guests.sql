-- Speakers: each one gets a free (complimentary) PSOOLA ticket from the
-- Speakers page (Owner only). PSOOLA emails the QR and sends it on WhatsApp.
-- Linked by the order's reference (ISM26-XXXXXX), shared with the guest record.
CREATE TABLE IF NOT EXISTS speaker_guests (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref          CHAR(12)     NOT NULL,
  speaker_name VARCHAR(120) NOT NULL,
  added_by     INT UNSIGNED NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_speaker_guest_ref (ref),
  KEY k_speaker_guest_name (speaker_name),
  CONSTRAINT fk_speaker_guest_adder FOREIGN KEY (added_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
