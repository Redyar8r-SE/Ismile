-- Workshop attendance (owner, 19 Nov 2026): each paid iSmile workshop is its
-- own PSOOLA event, and its door scans are copied onto the booking: the first
-- scan (arrived) and the exit scan (left; cleared when scanned back in).
ALTER TABLE workshop_bookings ADD COLUMN IF NOT EXISTS checked_in_at DATETIME NULL AFTER removed_by;
ALTER TABLE workshop_bookings ADD COLUMN IF NOT EXISTS checked_out_at DATETIME NULL AFTER checked_in_at;
