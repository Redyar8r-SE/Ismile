CREATE TABLE IF NOT EXISTS workshop_cash_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 registration_id BIGINT UNSIGNED NOT NULL,
 workshop_id VARCHAR(80) NOT NULL,
 amount INT UNSIGNED NOT NULL,
 currency VARCHAR(3) NOT NULL,
 payment_method VARCHAR(10) NOT NULL DEFAULT 'cash',
 created_at DATETIME NOT NULL,
 UNIQUE KEY workshop_cash_attendee (registration_id,workshop_id),
 KEY workshop_cash_workshop (workshop_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
