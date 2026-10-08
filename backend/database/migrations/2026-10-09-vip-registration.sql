-- VIP keeps its included lunch choice through checkout and ticket issuance.
-- install.php adapts DROP CHECK to MariaDB's DROP CONSTRAINT syntax.
ALTER TABLE checkouts
  DROP CHECK ck_checkout_student,
  MODIFY COLUMN ticket_type ENUM('professional','student','vip') NOT NULL,
  ADD COLUMN vip_lunch_day TINYINT UNSIGNED NULL AFTER lunch_day2,
  ADD CONSTRAINT ck_checkout_student CHECK (specialty <> 'student' OR ticket_type IN ('student','vip')),
  ADD CONSTRAINT ck_checkout_vip_lunch CHECK (
    (ticket_type <> 'vip' AND vip_lunch_day IS NULL) OR
    (ticket_type = 'vip' AND vip_lunch_day IS NOT NULL AND
      ((vip_lunch_day = 1 AND lunch_day1 = 1) OR (vip_lunch_day = 2 AND lunch_day2 = 1))));

ALTER TABLE registrations
  DROP CHECK ck_reg_student,
  MODIFY COLUMN ticket_type ENUM('professional','student','vip') NOT NULL,
  ADD COLUMN vip_lunch_day TINYINT UNSIGNED NULL AFTER lunch_day2,
  ADD CONSTRAINT ck_reg_student CHECK (specialty <> 'student' OR ticket_type IN ('student','vip')),
  ADD CONSTRAINT ck_reg_vip_lunch CHECK (
    (ticket_type <> 'vip' AND vip_lunch_day IS NULL) OR
    (ticket_type = 'vip' AND vip_lunch_day IS NOT NULL AND
      ((vip_lunch_day = 1 AND lunch_day1 = 1) OR (vip_lunch_day = 2 AND lunch_day2 = 1))));
