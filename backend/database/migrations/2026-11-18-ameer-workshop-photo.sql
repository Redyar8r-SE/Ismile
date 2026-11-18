-- Dr. Ameer Alshabeeb's workshop photo: a clean cut-out under a new file name,
-- so Cloudflare fetches it fresh (the old name was cached with the jagged one).
-- Only this one workshop, and only while it still has the old photo.
UPDATE workshops SET photo = 'assets/workshops/ameer-alshabeeb-cutout-20261118.webp'
 WHERE photo = 'assets/workshops/ameer-alshabeeb-cutout-20261117.webp';
