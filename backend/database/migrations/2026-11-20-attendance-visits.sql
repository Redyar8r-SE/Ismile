-- Every visit inside the venue (owner, 20 Nov 2026): each in and out scan is
-- kept, so the time at the summit pauses while the guest is outside.
-- What was known before is copied in: the arrival and, if any, the last exit.
CREATE TABLE IF NOT EXISTS attendance_visits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  event_day TINYINT UNSIGNED NOT NULL,
  entered_at DATETIME NOT NULL,                            -- scanned in (or checked in at the desk)
  left_at DATETIME NULL,                                   -- scanned out; NULL while inside
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visit_entry (ticket_id, event_day, entered_at),
  KEY k_visit_open (ticket_id, event_day, left_at),
  CONSTRAINT fk_visit_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT ck_visit_day CHECK (event_day IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO attendance_visits (ticket_id, event_day, entered_at, left_at, created_at)
SELECT ticket_id, event_day, checked_in_at, checked_out_at, NOW() FROM ticket_attendance;
