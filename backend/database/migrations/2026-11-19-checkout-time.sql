-- Check-out (owner, 19 Nov 2026): PSOOLA's gate scans a guest again when they
-- leave and then reports inside = false with the last scan time. That time is
-- kept per event day; it is cleared again when the guest is scanned back in.
ALTER TABLE ticket_attendance ADD COLUMN IF NOT EXISTS checked_out_at DATETIME NULL AFTER checked_in_at;
