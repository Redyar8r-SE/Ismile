-- Each guest's PSOOLA ticket code(s), fetched by the 5-minute job, so badges are
-- ready at once (and still work if PSOOLA is slow or down on the event day).
CREATE TABLE IF NOT EXISTS badge_ticket_codes (
  registration_id INT UNSIGNED NOT NULL,
  codes VARCHAR(2000) NOT NULL,
  synced_at DATETIME NOT NULL,
  PRIMARY KEY (registration_id),
  CONSTRAINT fk_badge_code_guest FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saved PSOOLA ticket codes for badges';
