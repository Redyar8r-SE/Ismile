-- VIP $100 (main Owner only) comes without the included VIP lunch (owner, 18 Nov 2026):
-- a VIP may now have no included lunch day (vip_lunch_day empty). A day that is
-- included must still be ticked. New names, so a half-done upgrade can resume.
ALTER TABLE checkouts
  DROP CONSTRAINT ck_checkout_vip_lunch,
  ADD CONSTRAINT ck_checkout_vip_lunch_v2 CHECK (
    (ticket_type <> 'vip' AND vip_lunch_day IS NULL) OR
    (ticket_type = 'vip' AND (vip_lunch_day IS NULL OR
      (vip_lunch_day = 1 AND lunch_day1 = 1) OR (vip_lunch_day = 2 AND lunch_day2 = 1))));

ALTER TABLE registrations
  DROP CONSTRAINT ck_reg_vip_lunch,
  ADD CONSTRAINT ck_reg_vip_lunch_v2 CHECK (
    (ticket_type <> 'vip' AND vip_lunch_day IS NULL) OR
    (ticket_type = 'vip' AND (vip_lunch_day IS NULL OR
      (vip_lunch_day = 1 AND lunch_day1 = 1) OR (vip_lunch_day = 2 AND lunch_day2 = 1))));
