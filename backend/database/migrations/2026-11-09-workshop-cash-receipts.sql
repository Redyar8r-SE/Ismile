CREATE TABLE IF NOT EXISTS workshop_cash_receipts (
 booking_id INT UNSIGNED NOT NULL PRIMARY KEY,
 amount_minor BIGINT UNSIGNED NOT NULL,
 currency ENUM('IQD','USD') NOT NULL,
 quote_usd_minor BIGINT UNSIGNED NULL,
 amount_iqd INT UNSIGNED NOT NULL,
 recorded_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_workshop_cash_receipt_booking FOREIGN KEY (booking_id) REFERENCES workshop_bookings(id),
 CONSTRAINT fk_workshop_cash_receipt_staff FOREIGN KEY (recorded_by) REFERENCES admin_users(id),
 CONSTRAINT ck_workshop_cash_receipt_amount CHECK (amount_minor > 0 AND amount_iqd > 0 AND (currency='IQD' OR quote_usd_minor > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
