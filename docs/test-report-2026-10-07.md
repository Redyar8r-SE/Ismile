# Website and database test report — 7 October 2026

The completed local checks passed. No application bug was found in the flows exercised. This is evidence from the current local code and databases; it does not guarantee every production condition is covered.

## Coverage and results

| Area | Result |
| --- | --- |
| Application syntax | All 96 PHP files and 47 JavaScript files passed; changed PHP test files checked again |
| JavaScript lint and types | Passed |
| JavaScript unit tests | 26 passed |
| Full registration and payment scenarios | 217 passed, zero failures |
| Office registration field validation | 16 passed |
| Communication, backup catalog and admin permissions | 33 passed |
| Sponsor/booth reservations, Standard booth type, cancellation and history | 112 database checks passed |
| QR, two-day attendance, manual admission, certificates and manual certificate email | 104 passed |
| QR camera code and midnight rollover | 7 passed, using simulated camera/browser conditions |
| Backup retention | 13 passed, including real test-database export |
| Backup memory and streaming | 6 passed; a 32 MiB fixture exported within a 32 MiB PHP memory limit |
| Backup restoration | All 20 tables and 14 views restored independently; every row matched, including photo bytes and generated values |
| Current local database | 43 read-only health checks passed; all tables healthy, views readable and migrations applied |
| Browser page/layout audit | All five public pages and 20 dashboard views opened; desktop, tablet and phone widths down to 320 px passed |
| Browser forms and downloads | Registration → simulated payment → QR ticket; QR/manual check-in; individual/bulk certificate email actions; PDF/Excel exports; booking cancellation; backup creation/download passed |
| Automatic search | 35 browser checks passed, including first-letter search, partial phone numbers, delayed responses, IME typing, network failure/recovery, focus and filter preservation |
| Languages and navigation | 52 browser checks passed for English, Arabic, Kurdish, themes, mobile navigation and public sponsor/booth submissions |
| Staff access | Owner, Registration, Finance, Check-in and Content access boundaries passed |
| Browser runtime/resources | No uncaught JavaScript exceptions or unexpected missing local resources in the audited pages |
| Whitespace | Git diff checks passed |

## Important behaviors verified

- Payment alone does not grant a participation certificate.
- One signed QR admits its guest once on Day 1 and once on Day 2. Same-day repeats and simultaneous admissions are blocked.
- The live admission day follows the actual event date and Iraq midnight. A rehearsal day selector remains available only in test mode.
- Staff can find a guest by name, phone, reference or ticket and admit them without QR after confirming identity.
- Arrival records retain the day, exact timestamp, staff member and admission method.
- Check-in creates the personalized certificate without automatically emailing it. Email requires the individual or bulk Send action; repeated bulk clicks avoid duplicate queued messages.
- New guests and arrivals appear automatically; searches update as staff type. Slow earlier responses cannot replace newer results.
- Export links retain the current filters. Individual and combined certificates are valid PDFs.
- Sponsors and Standard booths have separate pages. Only sponsors use the numbered map. Cancellation retains the agreed amount and history.

## Test maintenance completed

The older full-scenario suite still expected 13 database views and tiered booths with numbered map positions. Its expectations now match the 14-view schema, single Standard booth type and sponsor-only map. Its cron subprocess now inherits the PHP configuration and checks its exit status. The attendance rendering helper supports isolated site-data snapshots while preserving the configured web directory on deployed installations.

Three errors in the new browser audit harness (a terms checkbox selector, an export URL and a backup-button selector) were corrected. Their affected checks were rerun successfully; they were not application failures. Backup comparison was run after browser mutations finished, and the independent restore matched all records.

## Local test boundaries

Mutation tests used fresh, separate databases and private storage. The existing `ismile_local` database received read-only health checks. Payments used the fake gateway, and emails used local log storage. No real payment, external email, push or deployment was performed. Test credentials and fixtures remain inside the ignored `.local` directory.

The normal local PHP website/dashboard is available at `http://127.0.0.1:5501/`; the existing static Live Server on port 5500 is unchanged. Temporary audit web servers were stopped after testing.

Before event use, complete and verify the real Psoola integration: `backend/src/Payments/PsoolaGateway.php` still contains unfinished provider integration methods. Also verify real email credentials/delivery, production scheduled jobs and hosting behavior, and scan the QR using the actual staff phones/cameras over the event network. GitHub-backed content publishing was not exercised against the external service.

## Deployment follow-up

The merged public-site release passed lint, types and all 27 JavaScript tests. The first API deployment saved a database backup but stopped during migration: creating a missing attendance table from the current schema already included `checkin_method`, while the following migration tried to add it again. The installer now records that migration without repeating the column addition when the column is present.

A new isolated regression test upgrades the actual previous release schema, checks all three new migrations, queries all 14 views, verifies the single Standard booth, resumes an interrupted upgrade, exercises adding the column to a pre-existing attendance table, and repeats installation. These upgrade checks passed before redeployment. The earlier local QA results above describe the pre-deployment run.

The first live review also found that nginx's allow-list did not serve the local QR decoder. Browsers without native QR detection consequently hid Scan QR. The server now permits that specific script, the button remains visible, and unavailable camera/decoder conditions show useful guidance. All nine scanner and midnight-rollover checks passed. Deployment now checks the decoder through nginx and runs read-only dashboard rendering, database integrity, feature presence, PDF generation and source-file comparisons on the actual deployed installation.
