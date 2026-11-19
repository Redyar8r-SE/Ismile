-- Guest tickets on Workshop & Register (owner, 19 Nov 2026): the Owner switches
-- it on or off for each account on its Account activity page. The five
-- volunteers who already had it (Nma, Lanu, Rawaz, Danial, Helin) keep it; they
-- are matched by the SHA-256 of their sign-in email, because this repo is public.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS desk_guest_access TINYINT(1) NOT NULL DEFAULT 0 AFTER association_guest_access;
UPDATE admin_users SET desk_guest_access = 1 WHERE SHA2(LOWER(email), 256) IN (
  'a1d336a6d6ef4a367ef38d4b28b8359cd0b2156e70e3e689211a1cab61a3e12f',
  '22482e42f7d923dd6dbfaca9c699a331fc4fd2eb5a9976397592d0a764a412c3',
  '6a7012d63067f07ccb82b4a2f5512456a5973b0879dc7424c61ca3a3b1778c9b',
  'a147025e9c50291c9419371e77548ab7273abe67d98f6086f5bd5a4e4a1ede83',
  'f50d4686dd48d455f18d610bba64a86260527c3444ffff1fb86dac552ec8beba');
