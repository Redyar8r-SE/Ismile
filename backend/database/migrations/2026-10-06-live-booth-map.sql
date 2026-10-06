-- An assigned booth is reserved immediately, before payment or confirmation.
-- Declined and waiting-list requests keep their history but release the space.
-- Leading zeros are ignored so 08 cannot be booked separately from 8.
ALTER TABLE sponsor_requests
  ADD COLUMN reserved_booth VARCHAR(20) AS (
    IF(status NOT IN ('declined','waiting_list') AND booth_number IS NOT NULL,
       TRIM(LEADING '0' FROM TRIM(booth_number)), NULL)
  ) STORED,
  ADD UNIQUE KEY uq_sponsor_reserved_booth (reserved_booth);
