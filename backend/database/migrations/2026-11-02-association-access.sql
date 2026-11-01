-- Grant full Association operations to individual accounts without changing their role.
ALTER TABLE admin_users ADD COLUMN association_manage_access TINYINT(1) NOT NULL DEFAULT 0 AFTER map_booking_access;
