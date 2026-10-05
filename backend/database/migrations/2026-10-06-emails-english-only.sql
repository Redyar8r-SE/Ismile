-- Emails are sent in English only: the email queue no longer records a
-- language, and the unused "email_language" setting is removed.
ALTER TABLE emails DROP COLUMN lang;
DELETE FROM settings WHERE k = 'email_language';
