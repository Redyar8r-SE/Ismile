-- Volunteer accounts: they see only Workshop & Register (cash sales at the desk).
ALTER TABLE admin_users
  MODIFY role ENUM('owner','registration','finance','content','checkin','viewer','sponsors','map_viewer','badges','volunteer') NOT NULL;
