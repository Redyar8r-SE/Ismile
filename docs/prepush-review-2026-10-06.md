# Local Database review — 6 October 2026

## Result

All completed checks passed after the fixes below. No known blocker remains in
the reviewed local changes. This is evidence from the local test environment,
not a guarantee that every possible production condition has been exercised.

## Fixes made during review

- Enforce enabled phone codes for every staff role. Owner and Finance still
  have mandatory setup; other roles can choose to enable phone codes.
- Preserve columns with automatic defaults in database backups. Only actual
  calculated columns are omitted from inserts.
- Use the existing iSmile PNG favicon instead of a missing SVG file.
- Keep Site content's overview inside the Database when its editor is hosted
  on another address. The configured editor address is preserved.
- Prevent a requeued email from being labelled as already saved locally.
- Align the content editor's asset versions and safely handle unknown section links.
- Fix the Windows path assumption in the static server test and require LF
  line endings for files checked by the JavaScript formatter.

## Verification

| Check | Result |
| --- | --- |
| PHP syntax | 80 files passed; changed files checked again after the final fixes |
| JavaScript syntax | 38 files passed |
| JavaScript lint and type checks | Passed |
| JavaScript unit tests | 14 passed |
| Full backend scenarios | 213 passed, zero failures, on a fresh isolated test database |
| Phone caller field validation | 16 passed |
| Communication, backup and content navigation checks | 22 passed |
| Actual communication and backup HTTP requests | 34 passed |
| Owner, Registration, Finance, Content and Check-in access | 476 access, menu, rendering and asset assertions passed |
| Browser layout and accessibility checks | 254 passed; desktop and phone widths, icons, buttons, drawer keyboard behavior, editor and sign-in |
| Actual backup restoration | 38 passed; every table and view matched, including ID photo bytes, Kurdish/Arabic text, default timestamps and calculated fields |
| Git whitespace checks | Passed |
| Pending file scan | No local credentials, private data paths or common secret patterns found |

The full scenarios used fake payments and wrote email files to private local
storage. No real payment service or email delivery was exercised. Temporary
communication records and staff accounts used in the local preview were removed.
Real backup copies created locally remain available to the Owner.

`.local/`, backend configuration, private storage, backups, server credentials
and the API backend pointer are ignored by Git. No push or deployment was performed.

## Before pushing

The current branch is `backend`. Its existing GitHub workflow automatically
updates **api.ismile.krd** after a push. A push to `main` automatically deploys
the main website. These workflows were inspected but were not changed or run.

Nightly backup scheduling, mail credentials, real gateway configuration and
the deployed server's permissions must be checked in that environment; local
tests cannot confirm them. Local nightly backups have not been scheduled.
