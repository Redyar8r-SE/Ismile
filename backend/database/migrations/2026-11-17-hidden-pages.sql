-- Pages the Owner hides for one account (Users › What this account can do),
-- whatever its role allows. A comma-separated list of page areas, e.g. "map,desk".
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS hidden_pages VARCHAR(255) NOT NULL DEFAULT '' AFTER association_guest_access;
