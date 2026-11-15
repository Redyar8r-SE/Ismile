-- Tickets & Certificates only accounts: they see no other Database page.
ALTER TABLE admin_users
  MODIFY role ENUM('owner','registration','finance','content','checkin','viewer','sponsors','map_viewer','badges') NOT NULL;
