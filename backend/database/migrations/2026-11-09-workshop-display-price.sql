ALTER TABLE workshops
  ADD COLUMN display_price INT UNSIGNED NULL AFTER currency,
  ADD COLUMN display_currency CHAR(3) NULL AFTER display_price,
  ADD CONSTRAINT ck_workshop_display_price CHECK (
    (display_price IS NULL AND display_currency IS NULL)
    OR (display_price IS NOT NULL AND display_price > 0 AND display_currency IS NOT NULL AND display_currency = 'USD')
  );
