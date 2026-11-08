ALTER TABLE workshop_bookings
  MODIFY COLUMN paid_how ENUM('cash','transfer','psoola','free') NULL,
  ADD CONSTRAINT ck_wb_free CHECK (paid_how <> 'free' OR (price_agreed = 0 AND amount_paid = 0));
