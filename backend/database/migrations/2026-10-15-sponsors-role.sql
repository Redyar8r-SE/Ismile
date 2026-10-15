-- Sponsors: a team account that books sponsor map places and exhibition booths, nothing else.
ALTER TABLE admin_users MODIFY role ENUM('owner','registration','finance','content','checkin','viewer','sponsors') NOT NULL;
