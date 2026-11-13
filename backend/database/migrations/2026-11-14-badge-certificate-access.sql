ALTER TABLE admin_users ADD COLUMN badge_certificate_access TINYINT(1) NOT NULL DEFAULT 0 AFTER workshop_booking_access;
