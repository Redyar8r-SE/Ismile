-- Record whether staff admitted the guest with a signed QR or a verified lookup.
-- Existing arrivals retain their original QR classification.
ALTER TABLE ticket_attendance
  ADD COLUMN checkin_method ENUM('signed_qr','manual_lookup') NOT NULL DEFAULT 'signed_qr';
