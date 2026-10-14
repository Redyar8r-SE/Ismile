-- Viewer: a team account that opens every event page but cannot change anything.
ALTER TABLE admin_users MODIFY role ENUM('owner','registration','finance','content','checkin','viewer') NOT NULL;
