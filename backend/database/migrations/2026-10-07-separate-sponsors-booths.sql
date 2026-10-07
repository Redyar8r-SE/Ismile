-- Cancellation preserves booking and payment history. Only sponsors reserve the map.
ALTER TABLE sponsor_requests
  MODIFY COLUMN status ENUM('new','contacted','agreed','paid','confirmed','declined','waiting_list','cancelled') NOT NULL DEFAULT 'new',
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancellation_reason VARCHAR(500) NULL,
  ADD CONSTRAINT fk_sponsor_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES admin_users(id),
  ADD CONSTRAINT ck_sponsor_cancelled CHECK (status<>'cancelled' OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND CHAR_LENGTH(TRIM(cancellation_reason))>0)),
  DROP INDEX uq_sponsor_reserved_booth,
  DROP COLUMN reserved_booth,
  ADD COLUMN reserved_booth VARCHAR(20) AS (
    IF(kind='sponsor' AND status NOT IN ('declined','waiting_list','cancelled') AND booth_number IS NOT NULL,
       TRIM(LEADING '0' FROM TRIM(booth_number)),NULL)) STORED,
  ADD UNIQUE KEY uq_sponsor_reserved_booth(reserved_booth);

-- Retain old package records, but offer one Standard booth type from now on.
INSERT INTO sponsor_packages (id,kind,name_en,price,places,style,booth_tier,status,sort_order,created_at,updated_at)
VALUES ('booth-standard','booth','Standard booth',0,0,'tc-exhibitor',NULL,'active',1,NOW(),NOW())
ON DUPLICATE KEY UPDATE name_en='Standard booth',style='tc-exhibitor',booth_tier=NULL,updated_at=NOW();

INSERT INTO audit_log (user_id,action,target_type,target_id,details,ip,created_at)
SELECT NULL,'booth.standardize','sponsor_request',id,
  JSON_OBJECT('previous_package',package_id,'package','booth-standard','previous_booth_number',booth_number),
  'migration',NOW()
FROM sponsor_requests WHERE kind='booth' AND (package_id IS NULL OR package_id<>'booth-standard');

UPDATE sponsor_requests SET package_id='booth-standard',updated_at=NOW()
WHERE kind='booth' AND (package_id IS NULL OR package_id<>'booth-standard');

UPDATE sponsor_packages SET status='hidden',booth_tier=NULL,updated_at=NOW()
WHERE kind='booth' AND id<>'booth-standard';

ALTER TABLE sponsor_packages
  ADD COLUMN active_booth_type VARCHAR(8) AS (IF(kind='booth' AND status='active','standard',NULL)) STORED,
  ADD UNIQUE KEY uq_active_booth_type(active_booth_type),
  ADD CONSTRAINT ck_standard_booth CHECK (kind<>'booth' OR status='hidden' OR (id='booth-standard' AND booth_tier IS NULL));
