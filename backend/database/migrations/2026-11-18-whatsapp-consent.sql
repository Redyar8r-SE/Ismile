-- The person agreed to get the ticket QR by WhatsApp as well as email (ticked
-- on the team's sale forms). PSOOLA sends the QR on WhatsApp only with this
-- agreement (buyer.whatsappConsent). Kept on the form and on the registration.
ALTER TABLE checkouts ADD COLUMN whatsapp_consent_at DATETIME NULL;
ALTER TABLE registrations ADD COLUMN whatsapp_consent_at DATETIME NULL;
