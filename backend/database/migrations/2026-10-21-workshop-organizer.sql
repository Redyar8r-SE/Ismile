-- Who runs a workshop: iSmile (people register on the website with the email
-- they registered for the summit with) or a company (people call the
-- company's phone number to register).
ALTER TABLE workshops
  ADD COLUMN organizer VARCHAR(10) NOT NULL DEFAULT 'ismile' AFTER company_ku,
  ADD COLUMN company_phone VARCHAR(40) NULL AFTER organizer,
  ADD CONSTRAINT ck_workshop_organizer CHECK (organizer IN ('ismile','company'));
