-- The website's registration and sponsor forms send a random "attempt" key.
-- Sending the same form again (a dropped connection, a second press) returns
-- the one already saved instead of making a second form, payment or emails.
ALTER TABLE checkouts ADD COLUMN client_attempt VARCHAR(64) NULL AFTER created_ip, ADD UNIQUE KEY uq_checkout_attempt (client_attempt);
ALTER TABLE sponsor_requests ADD COLUMN client_attempt VARCHAR(64) NULL AFTER created_ip, ADD UNIQUE KEY uq_sponsor_attempt (client_attempt);
