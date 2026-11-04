-- Allow individual staff accounts to register both paid and complimentary guests.
ALTER TABLE admin_users ADD COLUMN registration_manage_access TINYINT(1) NOT NULL DEFAULT 0 AFTER association_manage_access;
