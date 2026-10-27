-- Map-only accounts and a private, per-account map booking grant.
-- No non-owner account gains booking access merely by having a staff role.
ALTER TABLE admin_users
  MODIFY role ENUM('owner','registration','finance','content','checkin','viewer','sponsors','map_viewer') NOT NULL,
  ADD COLUMN map_booking_access TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
