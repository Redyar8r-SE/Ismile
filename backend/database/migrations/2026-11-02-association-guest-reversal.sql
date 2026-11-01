ALTER TABLE association_batch_items
  ADD COLUMN reversed_by INT UNSIGNED NULL AFTER reversed_at,
  ADD COLUMN reversal_reason VARCHAR(1000) NULL AFTER reversed_by,
  ADD CONSTRAINT fk_association_item_reverser FOREIGN KEY (reversed_by) REFERENCES admin_users(id);
