-- Speaker tickets: the lunch days the Owner ticked, and why PSOOLA did not add
-- one yet. The free lunch is added to the speaker's ticket after it is issued
-- (PSOOLA: POST orders/{order}/tickets/{code}/add-ons, complimentary).
ALTER TABLE speaker_tickets
  ADD COLUMN lunch_day1 TINYINT(1) NOT NULL DEFAULT 0 AFTER added_by,
  ADD COLUMN lunch_day2 TINYINT(1) NOT NULL DEFAULT 0 AFTER lunch_day1,
  ADD COLUMN lunch_error VARCHAR(255) NULL AFTER lunch_day2;
