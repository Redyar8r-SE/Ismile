-- Student tickets are for dental students from 3rd stage up: the stage they
-- are in is kept with the form and the registration.
ALTER TABLE checkouts ADD COLUMN study_stage TINYINT UNSIGNED NULL AFTER university;
ALTER TABLE registrations ADD COLUMN study_stage TINYINT UNSIGNED NULL AFTER university;
