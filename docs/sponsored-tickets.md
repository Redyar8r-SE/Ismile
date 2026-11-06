# Sponsored free tickets

**Issuance is paused.** The supplied PSOOLA `POST /orders` documentation creates
full-price cash orders, which does not meet the requirement for zero-price
tickets. `SponsoredTickets::issue()` stops before database writes or provider
calls, including retries. The form's submit button is disabled. Do not re-enable
issuance until PSOOLA documents and verifies a true zero-price route or ticket
type. Existing provider amounts and order history remain unchanged.

Run `php backend/tests/sponsored-issuance-pause.php` to verify the stop without
any database or provider connection. The integration tests below describe the
previous implementation and are not evidence of PSOOLA zero-price support.

Authorized staff open **Registrations → Free ticket** and enter the guest's
first, second and third names, specialty, email, WhatsApp number and sponsoring
company. Owner accounts and accounts with individual registration access can
issue tickets. Ordinary cash registrars and viewers cannot.

The new form uses PSOOLA's `POST /api/partner/v1/orders` endpoint. Tickets are
free to the guest in iSmile. PSOOLA calculates and records its direct-order
price; the integration does not send a zero price or claim PSOOLA waived it.
The actual provider amount, currency, order ID and sponsoring company remain
in `sponsored_ticket_orders`, separate from guest payment income. Sponsored
guests contribute zero to Association handovers.

PSOOLA handles ticket email and, when enabled by PSOOLA and agreed to by the
guest, WhatsApp delivery. Permission is unchecked initially. When staff record
the guest's agreement, its timestamp, source, reference and utility category
are durably saved with the original request. No duplicate iSmile email is sent.
Private ticket links are removed from stored provider responses.

Each request has one saved body and idempotency key. Uncertain responses can
be retried from **Your recent free ticket requests** using the original body,
including its original consent record. A new request key cannot issue another
ticket to the same active guest. Only a verified paid response creates the
complimentary registration. Signed webhooks can recover a lost response;
provider scans also match the saved order when no partner reference is present.

The installer applies `2026-11-05-sponsored-tickets.sql` on MySQL or MariaDB
and can resume after the company column has already been added. Existing
paid student eligibility checks remain enforced. Complimentary students do
not need documents proving eligibility for a paid student discount.

## Local verification

Use an isolated database named `ismile_sponsored_test_*`, test environment and
log-only mail. Set `payments.gateway` to `psoola` and configure its connection:

- `api_base`: `http://127.0.0.1:8862`
- `api_key`: `sponsored-local-test-key` (local fixture only)
- `event_id` / `show_id`: `event-test` / `show-test`
- `ticket_types.professional` / `student`: `professional-test` / `student-test`
- `webhook_secret`: any local test secret

After running the database installer with that configuration:

```sh
node backend/tests/sponsored-provider.mjs
# In another terminal, pointing ISMILE_CONFIG at the isolated configuration:
php backend/tests/sponsored-tickets.php
```

The fixture never contacts PSOOLA or sends messages. The checks exercise
permissions, validation, accounting, consent, response mismatches, lost replies,
concurrent submissions, webhook recovery, delivery updates, admission and
archiving. Existing cash-sale, payment-breakdown and registration-access suites
also cover shared code.
