ALTER TABLE association_batches
  ADD COLUMN batch_number INT UNSIGNED NULL AFTER id,
  ADD UNIQUE KEY uq_association_batch_number (batch_number);
