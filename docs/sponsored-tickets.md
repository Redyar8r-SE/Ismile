# Complimentary guest tickets

Authorized staff open **Registrations > Guest ticket** alongside cash sales.
**Choose event, ticket options and language** opens
`registration.php?new=free&options=1`. This selectable form loads the authorized
general-admission catalogue through our server, with dependent event, show and
ticket-type choices, quantity, supported complimentary add-ons and ticket language
(`en`, `ar`, `ckb`, `kmr`). Refresh availability retains the current selection.
It collects guest name, email, phone and the required reason, and reviews the
selection and **Total: 0 IQD** before issuance. WhatsApp is unchecked by default;
staff may check it only with actual guest agreement. Otherwise the API payload
omits WhatsApp consent. The same saved-order, retry, private-link and reporting
implementation handles both forms. `&ui=ar` or `&ui=ckb` preserves RTL.

The original single iSmile guest form remains available without changes to its
fixed selection or delivery confirmation:
This simplified form collects first, second and third names, specialty, email, WhatsApp
number, sponsoring company and a required reason. It has no language controls,
event/show/type/quantity selectors, add-on section or availability-refresh button.
The server selects the configured event and show, one ticket, English and no
add-ons. Student specialty uses the configured student type; all other
specialties use professional. Posted ticket selections cannot override these
defaults. Availability and permission are still checked through PSOOLA before
issuance. Saved historical retries retain their exact original payloads.
After verified issuance, each single summit guest is included in Association's
Guest directory as a complimentary registration with a zero contribution. The
issued order links directly to that entry using **View in Association**. The
directory has a **Guest ticket** label and filter; repeated requests do not
create another directory entry.

Sponsoring company is optional. Labels omit asterisks while required validation
remains enforced. The approved layout pairs guest details with a complimentary
ticket preview and a live guest-name summary. It has no introductory subtitle,
setup banner or delivery checkbox. Issuance still obeys its server feature flag.

The staff form requests delivery by **Email + WhatsApp**. Its delivery notice and
final Issue confirmation explicitly state that issuing confirms the guest's
WhatsApp permission. The browser records `delivery_confirmation` only when the
staff member submits the Issue action. The server rejects a missing confirmation
before calling PSOOLA, then saves the existing consent contract, confirmation time,
reference and staff identity with the immutable order. Saved retries preserve
their original delivery choices and consent evidence.

## Configuration and activation

Reuse the existing server-side PSOOLA configuration, or set:

- `PSOOLA_API_BASE_URL`: the partner portal base including `/api/partner/v1`.
- `PSOOLA_API_KEY`: the server-only partner key.
- The existing webhook secret from the portal's webhook contract.
- Staff issuance is available by default with the configured PSOOLA gateway.
  Set `PSOOLA_COMPLIMENTARY_ENABLED=0` or
  `payments.psoola.complimentary_enabled = false` to pause it explicitly.

PSOOLA separately enforces **Allow complimentary issuance** on every explicit
complimentary request; cash-sale permission is insufficient. If permission is
absent, the form shows PSOOLA's permission error without a cash-sale fallback.
An enabled local button does not establish the partner's actual permission.

The existing GitHub deployment reuses the private `API_PSOOLA_CONFIG` secret:
`api_base`, `api_key`, event/type/add-on mapping and webhook secret. The optional
`complimentary_enabled` boolean defaults to true; explicit false pauses issuance.
Both host-only and full-prefix API bases remain supported.

Use an authorized staging event and staging key for live verification. Never
issue production tickets as a deployment test. If staging access is unavailable,
report actual provider issuance, private QR access, delivery and signed webhook
receipt as unverified; do not claim mocks establish these outcomes.

Apply database updates with the existing `backend/tools/install.php` procedure.
The additive `2026-11-06-complimentary-orders.sql` migration preserves legacy
cash orders and can resume after partially added columns. Keep those historical
provider amounts unchanged; old cash request bodies cannot be retried here.

## Request and recovery

All catalogue, issuance and order lookup requests run on the authenticated
server using the existing API client. Staff authorization and CSRF protection
apply to issuance and private ticket links. The server constructs `POST /orders`
with `issuanceType: complimentary` and `complimentaryReason`. It sends no prices,
payment details, totals or return URL, and never falls back to a cash order or
checkout session.

Before POST, the database saves the original payload, staff identity, reason,
local reference and a distinct `ismile-guest-<local order key>` idempotency key. Retries preserve
the body and key, including any original consent evidence. Uncertain results
remain pending reconciliation; order-reference lookup and bounded retries
recover them. Rate limits respect `Retry-After`. Rejected requests retain their
error; staff must deliberately start a corrected request rather than edit a
pending order. Concurrent duplicate submissions share one provider key.

Only a verified response with `isComplimentary: true`, status `paid`, channel
`direct`, the matching reference, reason and items, and an IQD total of zero
marks the order issued. The returned discount is stored as supplied. A positive
total or mismatched response stays unresolved and adds no revenue. Errors retain
safe support request IDs without logging guest payloads or credentials.

History and PSOOLA reporting show complimentary quantities, reason, staff,
discount and zero total separately from cash collections and paid tickets.
One eligible summit ticket may link to an administrative complimentary attendee
record; group orders remain in order history without invented attendee details.
The integration uses PSOOLA's real QR tickets and does not generate replacement
QR codes, ticket PDFs or duplicate ticket emails.

## Ticket access, delivery and webhooks

View/copy fetches `ticketsUrl` through a fresh authenticated `GET /orders/{id}`.
The server verifies the saved order before returning that private link with
`Cache-Control: no-store` and a no-referrer policy. Links are stripped recursively
from stored provider responses and webhook logs and are absent from initial
page HTML, browser storage and analytics.

PSOOLA handles email delivery. Email and WhatsApp statuses are separate from
issuance; queued/sent does not mean delivered. An email failure retains the
issued order and link and never causes reissuance. WhatsApp consent is sent only
when staff explicitly confirm the guest's agreement in the Issue action, using
the existing consent contract; a phone number alone grants no consent. The
general integration still omits WhatsApp consent unless explicitly supplied.

The existing HMAC verifier checks the original raw body, timestamp freshness and
signature in constant time. Guest webhook processing requires an event ID,
deduplicates it and confirms `GET /orders/{data.sessionId}` before updating the
order. Replayed events cannot create another order or count tickets twice.

## Automated verification

Use isolated local databases and log-only mail. The complimentary suite rejects
any database outside `ismile_sponsored_test_*` or provider outside the loopback
fixture at port 8862. Set `ISMILE_CONFIG` to that test configuration, with the
complimentary feature enabled and a local-only key/secret.

```sh
node backend/tests/sponsored-provider.mjs
# In a separate terminal, using the isolated test configuration:
php backend/tests/complimentary-orders.php
# Windows local browser harness, after provisioning .local/sponsored-ticket-test/config.php:
node backend/tests/guest-ticket-options-browser.mjs
```

The fixture sends no real tickets or messages. Coverage includes zero totals,
realistic waived amounts, add-ons/capacity, authorization, validation, permission
errors, buyer limits, immutable retries, lost replies, concurrency, malformed
provider responses, delivery failures, webhook verification/deduplication,
private-link removal, legacy cash protection and zero revenue. Run the existing
PSOOLA gateway, cash-sale, registration-access and payment-breakdown regressions
as well. Browser checks cover staff roles, catalogues, confirmation, private
links, translations and mobile RTL layout.

## Required live staging checks

Mocks do not establish the actual partner permission or production API behavior.
With PSOOLA-authorized staging access, verify a real zero-total complimentary
order, its actual ticket-page/QR access, selected add-on capacity, configured
limits, delivery statuses and signed webhook receipt. Confirm a replay returns
the same order and an induced lost response recovers it. Use designated staging
recipients. These live checks remain unverified until authorized staging access is available.
