-- Viewer + Sponsors: sees every event page, and books sponsor map places and exhibition booths.
ALTER TABLE admin_users MODIFY role ENUM('owner','registration','finance','content','checkin','viewer','sponsors') NOT NULL;
