CREATE TABLE IF NOT EXISTS ticket_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  event_day TINYINT UNSIGNED NOT NULL,
  checked_in_at DATETIME NOT NULL,
  checked_in_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_ticket_day (ticket_id, event_day),
  KEY k_attendance_day_time (event_day, checked_in_at),
  CONSTRAINT fk_attendance_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id),
  CONSTRAINT fk_attendance_staff FOREIGN KEY (checked_in_by) REFERENCES admin_users (id),
  CONSTRAINT ck_attendance_day CHECK (event_day IN (1, 2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id INT UNSIGNED NOT NULL,
  certificate_no VARCHAR(40) NOT NULL,
  recipient_name VARCHAR(190) NOT NULL,
  issued_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificate_registration (registration_id),
  UNIQUE KEY uq_certificate_no (certificate_no),
  CONSTRAINT fk_certificate_registration FOREIGN KEY (registration_id) REFERENCES registrations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve legacy arrivals only when their calendar date identifies an event day.
INSERT IGNORE INTO ticket_attendance (ticket_id, event_day, checked_in_at, checked_in_by)
SELECT id, CASE WHEN DATE(checked_in_at) = '2026-11-20' THEN 1 ELSE 2 END, checked_in_at, checked_in_by
FROM tickets WHERE DATE(checked_in_at) IN ('2026-11-20', '2026-11-21');

INSERT IGNORE INTO certificates (registration_id, certificate_no, recipient_name, issued_at)
SELECT r.id, CONCAT('ISM26-C-', LPAD(r.id, GREATEST(5, CHAR_LENGTH(r.id)), '0')),
       CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name), MIN(a.checked_in_at)
FROM registrations r JOIN tickets t ON t.registration_id=r.id JOIN ticket_attendance a ON a.ticket_id=t.id
WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL
GROUP BY r.id, r.first_name, r.father_name, r.grandfather_name;

INSERT INTO settings (k, v, updated_at) VALUES ('ticket_qr_in_email', '1', NOW())
ON DUPLICATE KEY UPDATE v='1', updated_at=NOW();
