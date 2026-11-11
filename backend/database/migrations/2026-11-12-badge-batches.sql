-- Badge batches: every printed-badge PDF made in the admin (Tickets tab), so
-- the team can always see which guests' badges were made, when and by whom.
CREATE TABLE IF NOT EXISTS badge_batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  guest_count SMALLINT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_by_name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  last_downloaded_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY k_badge_batch_created (created_at),
  CONSTRAINT fk_badge_batch_creator FOREIGN KEY (created_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Printed badge batches';

CREATE TABLE IF NOT EXISTS badge_batch_items (
  batch_id INT UNSIGNED NOT NULL,
  registration_id INT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL,
  ref CHAR(12) NOT NULL,
  printed_name VARCHAR(190) NOT NULL,
  ticket_type VARCHAR(20) NOT NULL,
  PRIMARY KEY (batch_id, registration_id),
  KEY k_badge_item_guest (registration_id, batch_id),
  CONSTRAINT fk_badge_item_batch FOREIGN KEY (batch_id) REFERENCES badge_batches(id),
  CONSTRAINT fk_badge_item_guest FOREIGN KEY (registration_id) REFERENCES registrations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Guests in each printed badge batch';
