-- The Owner can give one person everything on the Association guests page
-- (see the list, add by association, download the PDF), whatever their role.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS association_guest_access TINYINT(1) NOT NULL DEFAULT 0 AFTER badge_certificate_access;
