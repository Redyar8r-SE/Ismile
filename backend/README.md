# iSmile backend

Registrations, payments (Psoola), tickets by email, student ID checks,
sponsor requests, workshops booked by phone, check-in, and the admin pages
at `/admin/`. PHP 8.1+ and MySQL/MariaDB, no framework; one library (TCPDF,
for the PDF ticket and QR codes).

The plan behind it: `docs/ismile-backend-plan-2026-09-27.html`.

## Where things live on the server

```
/home/USER/
  public_html/            the live website (ismile.krd), incl. api/ and admin/
  test.ismile.krd/        the test website (password-protected)
  ismile-backend/         this folder, for live   (NOT inside public_html)
  ismile-backend-test/    this folder, for test
  repositories/Ismile/    the Git copy cPanel deploys from
```

Each website finds its backend through `api/_backend.php` (one line, written
by the deploy script, not in Git).

## First-time setup (per copy: test first, then live)

1. cPanel → MySQL Databases: create a database and a user, give the user
   ALL PRIVILEGES on it.
2. cPanel → Git Version Control → clone `https://github.com/Redyar8r-SE/Ismile`.
   Then in the Terminal: `bash ~/repositories/Ismile/backend/tools/deploy.sh test`
3. `cp ~/ismile-backend-test/config.sample.php ~/ismile-backend-test/config.php`
   and fill it in: database, `site_url`, `site_root`, a new random `secret`
   (`php -r "echo bin2hex(random_bytes(32));"`), mail settings.
   Test copy: `env` = `test`, `payments.gateway` = `fake`.
   Live copy: `env` = `live`, `payments.gateway` = `psoola` (once Psoola's keys are in).
4. Tables and the first Owner account:
   `php ~/ismile-backend-test/tools/install.php --owner you@example.com "Your Name"`
5. If the deploy script warned that Composer is missing: upload the `vendor/`
   folder (from `composer install --no-dev` on any computer) into the backend folder.
6. cPanel → Cron Jobs (replace USER; use the same PHP 8.1+ the deploy script
   printed, e.g. `/opt/cpanel/ea-php81/root/usr/bin/php`, because plain `php`
   can be an older version on cPanel):
   ```
   * * * * *    /opt/cpanel/ea-php81/root/usr/bin/php /home/USER/ismile-backend/cron/run.php minute
   */5 * * * *  /opt/cpanel/ea-php81/root/usr/bin/php /home/USER/ismile-backend/cron/run.php five
   30 2 * * *   /opt/cpanel/ea-php81/root/usr/bin/php /home/USER/ismile-backend/cron/run.php nightly
   ```
   Check in cPanel → MultiPHP Manager that the domain uses PHP 8.1 or newer.
   `api/.user.ini` raises the upload limit to 10 MB for student ID photos.
7. Sign in at `/admin/`, set up the phone code, then Settings: capacity,
   email test mode + test address, sponsor notification address.
8. Run the checklist on the TEST copy (never on live):
   `php ~/ismile-backend-test/tests/scenarios.php` — every line must pass.

## Updating

Push to GitHub → cPanel Git → "Update from Remote" → "Deploy HEAD Commit"
(deploys to test). Check the test site, then:
`bash ~/repositories/Ismile/backend/tools/deploy.sh live`

The deploy never overwrites `data/` files the admin has edited (it only adds
new files and new translation keys), `assets/uploads/`, `config.php` or
`storage/`.

## Psoola

`src/Payments/PsoolaGateway.php` has three marked places to fill from
Psoola's documentation: create a payment, check a payment, verify the
webhook. Until then the test site uses the pretend gateway
(`api/fake-psoola.php`), which lets you choose: pay, fail, pay a wrong amount,
lose the webhook, close the page. Give Psoola this webhook address:
`https://ismile.krd/api/webhook.php`.

## Emails

`mail.driver = log` writes emails to `storage/outbox/` as files (testing).
`mail.driver = brevo` sends through Brevo from tickets@ismile.krd; add Brevo's
SPF/DKIM records to the domain first. Settings → Test mode sends every email to
one test address until launch.

### Communication center and backups

The Database menu includes a Communication center for the Owner, Registration
and Finance. It reads the existing email queue, with recipient/reference search,
status, message type and language filters, pagination and delivery details.
Skipped messages are separate from sent messages; log-mode emails are labelled
as saved locally. Team alerts are visible only to the Owner. This page does not
send or resend emails.

Backups is Owner-only. It shows the latest completed gzip database export, its
date and size, retained copies, and authenticated download buttons. Create
backup uses the same engine as the nightly job. Creation and downloads are
recorded in the activity log. Backup files stay in private storage and are
published only after the export finishes; partial or corrupt files are omitted.
Website files and images stored outside the database are not part of this copy.

Focused checks: `php -d extension=mbstring backend/tests/admin-operations.php`.

## Database design

`database/schema.sql` is the full design (13 tables in 6 sections, with
foreign keys, allowed-value lists and 3 read-only views). Later changes to a
live database go in `database/migrations/` (see the README there);
`tools/install.php` applies them once each, and the deploy script runs it.

### Office registration and ambassador codes

The phone registration form requires the caller's specialty. Dental students
receive a student ticket, which requires their university; an ambassador code
is optional. The chosen specialty is kept on both paid and complimentary tickets.

Owners manage ambassador codes under Settings → Ambassador codes. Save an
existing code to update its name or university. Delete removes the code from
the management list and records its details in the audit log. Codes already
recorded on registrations are preserved and shown below the list after deletion.
This uses the existing database columns; no schema migration is needed.

Field checks without a configured database: `php backend/tests/office-fields.php`.
The full test-site scenarios also check ambassador permissions, deletion,
registration history and specialty persistence after payment.

## Local development

PHP 8.1+ and MariaDB on your computer, then:
```
ISMILE_CONFIG=/path/to/dev-config.php php -S 127.0.0.1:8090 -t .
```
`api/_boot.php` finds `backend/` next to it when there is no `_backend.php`.
