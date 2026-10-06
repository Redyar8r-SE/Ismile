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
status and message type filters, pagination and delivery details. Every email is
sent in English.
Skipped messages are separate from sent messages; log-mode emails are labelled
as saved locally. Team alerts are visible only to the Owner. This page does not
send or resend emails.

Backups is Owner-only. It shows the latest completed gzip database export, its
date and size, retained copies, and authenticated download buttons. Create
backup uses the same engine as the nightly job. Creation and downloads are
recorded in the activity log. Backup files stay in private storage and are
published only after the export finishes; partial or corrupt files are omitted.
Website files and images stored outside the database are not part of this copy.

Keep the nightly schedule outside busy registration/check-in hours (the example
uses 02:30 in the server's cron timezone). The nightly PHP process lowers its
CPU priority when supported. Manual and scheduled backups share one lock, so
only one export can run at a time. Fast gzip compression reduces CPU work but
may produce larger files. Retention keeps the newest five successful backups,
including manual copies. After a sixth copy finishes and passes verification,
the oldest successful copy and its verification sidecar are removed. Failed
exports preserve existing copies. Retention counts copies rather than days;
multiple manual backups in one day use the same five-copy allowance.
Table data is read sequentially with an unbuffered cursor, limiting PHP memory
to the current row and its SQL representation instead of the entire table.
Photo export uses one query per table. The original connection buffering mode
is restored after every table, including when an export fails. This reduces
PHP memory use; database and disk load during an export still need measurement.

Each new export is fully verified once. Catalogs and the missing-backup alert
reuse that result for up to 24 hours while file size, timestamps, inode, gzip
header and trailer match. Legacy or changed files are checked on first use;
expired results are rechecked. Downloads always run a fresh full verification.
The small private `.verified.json` sidecars are deleted with rotated backups.
This reduces repeated decompression; it does not eliminate the database reads
or disk work during an export. Measure response times during a backup on
staging before choosing the live schedule.

Focused checks: `php -d extension=mbstring backend/tests/admin-operations.php`.
Retention checks: `php -d extension=mbstring backend/tests/backup-retention.php`.
To also verify rotation after a real export (test database, disposable backup
storage): `ISMILE_CONFIG=/path/to/test-config.php php backend/tests/backup-retention.php --database`.
Database streaming checks (isolated test database only):
`ISMILE_CONFIG=/path/to/test-config.php php backend/tests/backup-streaming.php`.

## Database design

### Live exhibition map

`sponsor.html#exhibition` shows the supplied 44-booth floor plan and its tier,
number and size table. `GET /api/booths.php` returns only booked numbers; no
company, request ID, contact, payment or staff information is public. Visible
pages refresh every three seconds and immediately when returning to the tab.
Unavailable data is labelled unknown and retried; it is never shown as available.
The source floor plan is traced as theme-aware SVG vectors, keeping the same
booth positions and numbers. The map supports 100–200% zoom and exploration
on phones. Above 900px the map sits on the left with the compact table on the
right. Phones and portrait tablets switch between Map and Table views; the
directory is scrollable inside its panel. Selecting a number returns to the
map and centers its booth. Availability totals use the
same response as the red map/table marks; unknown data shows no available count.

In Sponsors or Exhibition requests, open a company, choose **Reserve booth**
under Details and save. Assignment reserves the space immediately, even before
payment. Choose **No booth reserved** to release it. Declined and waiting-list
requests release their spaces; reactivating one must pass the same uniqueness
check. Both sponsorship and exhibition requests share the same 44 spaces.
The database unique key refuses simultaneous reservations of one booth.

The active sponsorship and exhibition tiers are Platinum, Gold, Silver and
Bronze. Package `booth_tier` controls the booth selector on both staff forms.
Platinum: 37–42; Gold: 1, 6, 24, 30, 36, 43, 44; Silver: 2–5, 7–14,
25–28, 31–35; Bronze: 15–23, 29. `data/booth-tiers.json` supplies the public
map/table and backend validation, so the assignments cannot drift apart.
Migration `2026-10-06-align-booth-tiers.sql` preserves prices and request
history while hiding old packages and adding the four matching booth types.

Staff can also create companies directly: **Sponsors & booths → Sponsors →
Add sponsor booking**, or **Exhibition (booths) → Book exhibition booth**.
Enter company, contact and phone, choose a package/type and (for exhibition)
a numbered booth. Email is optional. An optional agreed price starts the
booking at Agreed; recording the full received payment starts it at Paid,
ready for the existing Confirm action. A selected booth is reserved on save.
Owner, Registration and Finance may use this flow. Creation is audited and
does not send website-request notification emails.

Existing bookings have an **Edit details** button in the directory and on the
company page. Staff can correct company/contact details, change the package and
matching booth, and enter a new agreed total. For a paid Gold-to-Platinum upgrade,
money already received is preserved; a higher total returns the booking to
Agreed with the outstanding balance shown. Record exactly that balance, then
confirm the upgraded booking. Old and new booths change together on save.
The agreed total cannot fall below money received. Edits and each additional
payment retain before/after details in the private audit history. Owner undo
removes the latest payment and restores the earlier money record. This uses
existing columns and requires no additional schema migration.

The main Node website has a read-only route at the same `/api/booths.php` path.
Set `BOOTH_API_URL` in its deployment environment to the PHP endpoint (for
example `https://api.ismile.krd/api/booths.php`). If that server has HTTP Basic
authentication, also set `BOOTH_API_USERNAME` and `BOOTH_API_PASSWORD`. These
credentials stay on the Node server. It forwards only validated booth numbers,
never the upstream's other fields. Without configuration, the map explicitly
shows availability as unavailable. On PHP hosting no proxy settings are needed.

Run the installer before serving the new API. Migration
`2026-10-06-live-booth-map.sql` adds a generated reservation column and a unique
key. Existing duplicate active assignments must be resolved before this
migration can succeed; it does not silently discard anyone's booking. Unchanged
legacy booth labels are preserved, but new assignments use numbers 1–44.

Checks: `php backend/tests/booth-reservations.php` for field validation; with an
isolated test database configured, add `--database` to check reservation and
release behavior. The test refuses live environments and non-test databases.

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
