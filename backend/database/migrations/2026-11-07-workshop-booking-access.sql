ALTER TABLE admin_users ADD COLUMN workshop_booking_access TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_manage_access;
