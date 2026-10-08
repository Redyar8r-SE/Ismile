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

Registration and program visibility are controlled in Database → Settings.
`registration_open` and `program_hidden` are stored in the MySQL `settings`
table. Installation imports the previous JSON switches once, preserving
their effective state. Later installs and content synchronization leave the
database controls unchanged. The website reads `/api/site-state.php` and
`/api/config.php` with no caching; closing registration also blocks submissions.

The Node content editor saves directly to `CONTENT_ROOT`, a persistent folder
outside release directories (the VPS deployment uses `TARGET/shared-content`).
Public JSON and uploaded pictures are read from that same folder immediately.
These edits are no longer GitHub commits; back up this folder separately from
the database. GitHub deploys update code and provide defaults for unedited files.
Standalone PHP hosting continues to write its website files directly.

The Node website requires `WEBSITE_API_URL` (a URL in the PHP backend's `/api/`
directory; `BOOTH_API_URL` can also supply the base) and, when protected,
`BOOTH_API_USERNAME` / `BOOTH_API_PASSWORD`. The VPS workflow loads missing
connection values from the existing `/opt/ismile-test/secrets.env` without
printing secrets. The browser never receives these credentials.

Focused regression checks: `npm run check-errors` and
`ISMILE_CONFIG=/isolated/config.php php backend/tests/website-settings.php`
(a fresh `ismile_website_test_*` database and separate website content).

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

### Two-day QR admission and certificates

The admin Registration workspace (`checkin.php`) groups Check-in, Attended,
Guest list and Report. Registration forms and certificates remain on their
separate pages. Guest and attendee filters include category, lunch bookings,
student ID status, specialty, city, university, ambassador, language, payment
status and arrival date/time/staff. PDF and Excel exports preserve these filters.
Attended shows exact arrival timestamps in Iraq time and the staff member for
each day. The report refreshes every 15 seconds through an authenticated,
uncached endpoint, with cumulative Day 1/Day 2 charts, latest arrivals, guest
breakdowns, attendance rates and lunch planning. Lunch counts represent bookings
and event arrivals, not meals collected. Check-in staff cannot view contact
details or ID images, or download detailed reports; existing role rules apply.

Every valid ticket email includes its signed QR and PDF ticket. The same QR
admits the guest once on Day 1 and once on Day 2. A repeat admission on the same
day is blocked, including simultaneous scans at different desks. Name changes
invalidate old QR versions without resetting admissions. Search helps locate
a guest. Staff can also select **Admit without QR** after confirming the
identity of the guest in front of them. Search accepts name, phone, registration
reference or ticket number. This admits an existing paid or complimentary
guest; it does not register or approve an unpaid person. Guests need no phone
internet, but the check-in desk must stay connected to the database.
QR and manual admissions share the same ticket lock and daily limit. Manual
admission after a QR check-in (or vice versa) is blocked on the same day.
The current ticket version is checked again when staff submit the form.
Migration `2026-10-07-manual-checkin.sql` records the method on each arrival;
Attended, guest details, reports and filtered PDF/Excel exports identify manual
admission and retain its time and staff member. Certificates are issued by
both methods.

The live welcome desk selects the day from the server’s Asia/Baghdad date:
20 November 2026 and 21 November 2026. Admissions outside those dates are
rejected. Test mode offers a Day 1 / Day 2 rehearsal switch. The camera uses
native decoding when available and a local jsQR fallback. HTTPS and camera
permission are required. A complete QR payload can also be pasted from an
external scanner. Staff confirm the guest’s name before admitting them.
At 12:00 AM Iraq time on Day 2, the same QR becomes available for its second
admission. Day 1 attendance stays recorded. Live admission always uses the
current server date, even if a form was opened before midnight or waited on
another scanner’s lock. An open live scanner refreshes at midnight and when
returning from sleep after that deadline. No third-day admission is available.

`ticket_attendance` has a unique `(ticket_id, event_day)` key and records
the timestamp and staff member. Admission, certificate creation and audit
logging share a transaction. `tickets.checked_in_at` retains the first
arrival for existing dashboard queries. `certificates` stores one named,
numbered certificate per registration. Attendance on either day qualifies;
payment alone does not. The Certificate page and downloads exclude cancelled
tickets and registrations. Correcting a name updates the certificate.
PDFs render from these records when downloaded, using current artwork.

Certificates are **never emailed automatically by attendance**. On Certificates,
press **Send PDF by email** for one guest, or **Send all certificates by email** for everyone
eligible. These authenticated, CSRF-protected actions queue a separate email
and personal PDF per guest. Bulk sending skips certificates already sent or
pending at their current email address. Individual resend is explicit; failed
messages can be retried. The sending job checks attendance and active status
again and skips cancelled/ineligible guests. Delivery states appear on the
certificate list and in Communication center. Local mail-driver `log` saves
emails and PDF attachments to private storage instead of contacting guests.
Individual and combined PDF downloads remain available.

Check-in, Registrations, Guest list, Attended and Communication center update
their search results as staff type, after a 250 ms pause. A single letter such
as `R` finds names beginning with that letter; typing more narrows the list.
The welcome desk also accepts partial phone numbers, references and tickets,
including local Iraqi phone prefixes such as `0750`. It shows the first 20
matches. The header search offers up to eight clickable guest suggestions.
Filters and export links update with the current search without reloading
the page. Older responses cannot overwrite newer queries. Searching never
admits a guest; the admission action and identity verification remain explicit.


**Certificates** provides individual PDFs and one combined PDF with a page
per participant. **Lists** offers individual PDF reports and **Download all
event lists (PDF)**. Payments are included in that bundle only for roles
with payment access. Student ID photos use their own protected PDF export;
Finance cannot download those images. Registration exports retain current
filters, including Day 1, Day 2, both days, and no attendance. Wide reports
split columns into readable groups and repeat the reference in each group.

The sample design is `docs/ismile-certificate-preview.pdf`. Optional final
artwork can be installed as a PNG/JPEG in private storage and configured via
`certificates.background` in `config.php`. The sample config documents the
name and certificate number positions. No signature image is fabricated.

Upgrade: deploy the source and run `php backend/tools/install.php` on staging
first. Migration `2026-10-06-two-day-attendance.sql` preserves legacy arrivals
whose dates match the two event dates and creates their certificate records.
Other historic timestamps remain in the legacy field without assigning an
event day. New installations include both tables and the
`14_attendance_and_certificates` read-only database view.

Focused checks (isolated `ismile_attendance_test_*` database only):
`ISMILE_CONFIG=/path/to/test-config.php php backend/tests/attendance-certificates.php`.
This creates fixture registrations and queued log-mode ticket emails; it does
not send them. It verifies both admissions, concurrent scans, rollback,
revocation, names, migration, email attachments and PDF rendering. Camera
decoder tests use its generated fixture:
`node backend/tests/qr-scanner.mjs /path/to/test-storage/tmp/qr-test.json`.
These automate camera logic; test actual camera permission and QR capture on
the event’s phones before opening the doors.

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

Event operations has separate **Sponsors** and **Booths** pages. Sponsors
choose a sponsorship tier and optionally reserve a numbered position on the
map. Assignment reserves immediately, before payment. Declined, waiting-list
and cancelled requests release the position. The database unique key refuses
simultaneous reservations of one sponsor position.

Sponsorship tiers are Platinum, Gold, Silver and Bronze. Package `booth_tier`
controls the sponsor selector. Platinum: 37?42; Gold: 1, 6, 24, 30, 36, 43, 44;
Silver: 2?5, 7?14, 25?28, 31?35; Bronze: 15?23, 29.
`data/booth-tiers.json` supplies the public map and backend validation.
The public floor plan is hidden when the request is for an exhibition booth.

Booths has one **Standard booth** type, with its own price and capacity.
Booth bookings have no sponsorship tiers or numbered map reservations.
Migration `2026-10-07-separate-sponsors-booths.sql` moves existing exhibition
bookings to Standard, archives previous types and audits their original
package and booth number. Existing agreements, payments and calls are retained.

Staff can create companies using **Sponsors ? Add sponsor booking** or
**Booths ? Book exhibition booth**. Enter company, contact and phone; email
is optional. An agreed amount starts the booking at Agreed. Recording the full
payment starts it at Paid, ready for Confirm. Owner, Registration and Finance
can create bookings; creation is audited and sends no request emails.

**Cancel booking** requires a reason and records the time and staff member.
Cancellation retains agreed amounts, receipts and call history, removes
follow-up reminders and releases the sponsor map position. Only the Owner
can cancel a booking with a recorded payment. Unpaid cancelled bookings may
be reopened as New, with no map position assigned; paid ones remain cancelled.

Dashboard, Lists, Registrations, Guest list, Attended, Sponsors and Booths refresh
read-only results every five seconds without navigating. Current filters,
pagination, open guest details and table scrolling are preserved. Hidden tabs
pause updates and resume on return. Authentication remains required for each
request; connection errors retain the last successful list and retry.

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
