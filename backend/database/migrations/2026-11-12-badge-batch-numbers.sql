-- Badge batches get their own number, like Association: a deleted batch's
-- number is given to the next new batch. Existing batches are numbered 1, 2, 3...
ALTER TABLE badge_batches ADD COLUMN batch_number INT UNSIGNED NULL AFTER id;
SET @badge_number := 0;
UPDATE badge_batches SET batch_number = (@badge_number := @badge_number + 1) ORDER BY id;
ALTER TABLE badge_batches MODIFY batch_number INT UNSIGNED NOT NULL, ADD UNIQUE KEY uq_badge_batch_number (batch_number);
