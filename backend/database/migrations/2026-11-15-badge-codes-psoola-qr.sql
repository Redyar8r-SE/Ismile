-- Badges now hold PSOOLA's gate value (qrCode) instead of the short ticket
-- code. Clearing the saved codes makes the minute job fetch every guest's
-- new value at once (and a download fetches any still missing).
DELETE FROM badge_ticket_codes;
