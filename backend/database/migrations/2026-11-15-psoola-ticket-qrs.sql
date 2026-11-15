-- Each PSOOLA ticket's entry QR (PSOOLA's QR access), shown only on the
-- buyer's own status page. A QR admits whoever holds it: never export it.
CREATE TABLE IF NOT EXISTS psoola_ticket_qrs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  psoola_order_id VARCHAR(190) NOT NULL,
  code VARCHAR(190) NOT NULL,
  ticket_type_name VARCHAR(190) NOT NULL DEFAULT '',
  holder_name VARCHAR(190) NOT NULL DEFAULT '',
  add_ons VARCHAR(2000) NOT NULL DEFAULT '[]',
  qr_code VARCHAR(2048) NULL,
  qr_image_url VARCHAR(4096) NULL,
  status VARCHAR(20) NOT NULL,
  synced_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_psoola_ticket (psoola_order_id, code),
  KEY idx_psoola_ticket_guest (registration_id),
  CONSTRAINT fk_psoola_ticket_guest FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='PSOOLA entry QRs for the buyer page (private)';
