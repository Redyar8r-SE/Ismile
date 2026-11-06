# Registration details and guest workshops

The shared staff detail page (`admin/registration.php?id=…`) handles website,
cash, complimentary, cancelled and student registrations. Each record retains
its real payment method and confirmed total. Unpaid checkout forms remain in
the existing checkout/payment flow; this change does not create registrations
before payment.

The approved Pencil layout groups personal information, admission, workshops,
attendance and activity history. Registration metadata is expandable. Edit,
resend, certificates, cancellation and deletion keep their existing permissions
and confirmations. Resending a provider ticket still uses the PSOOLA partner
portal. Separate Payments, Emails and Notes panels are removed from this page;
their existing storage and dedicated administration pages remain intact.

## Complimentary workshops

Authorized workshop staff who can issue complimentary tickets can select an
active iSmile workshop with server-configured provider event/show/type IDs.
Company workshops are excluded. An active paid or complimentary summit
registration is required. The selected person's name, email, phone and locale
come from the database. Staff supply a reason and explicitly confirm WhatsApp
permission in the issuance confirmation. A phone number alone is insufficient.
Workshop booking requires Owner access or the individual workshop-booking grant.
Delegated summit guest-ticket access alone does not grant workshop issuance.
Staff with both grants can issue guest workshops for any registered attendee,
including people added by other staff or the website. They retain their existing
role and personal-detail editing scope. Owner-only cancellation, deletion and
workshop configuration are not granted.

The server reuses `GuestOrders`, its API client, credentials, error handling,
bounded retries and verified webhook handling. It sends quantity one and
`issuanceType: complimentary`; no amount, price, payment, return URL or checkout
session is sent. A paid response or positive total cannot fulfil the booking.

`guest_workshop_orders` stores the request before any issuance call, preserves
its payload and key, and prevents concurrent or cross-flow duplicate bookings.
Pending requests are resumed from the person's page with the same saved request.
After a definitively rejected request, staff may explicitly start a corrected
new request. Unknown outcomes retain their duplicate lock.

After PSOOLA confirms an issued zero-IQD complimentary order, the transaction
mirrors one complimentary `workshop_bookings` row. It does not reserve provider
inventory a second time or reject the last seat because PSOOLA already consumed
it. The local booking has zero agreed price and no received payment. Provider
orders retain actual face-value discounts. Email failure does not undo issuance.
Delivery status is shown on the workshop booking separately from fulfilment.

Private provider ticket links are retrieved on demand through authenticated,
CSRF-protected server calls. They are not persisted in the new request table,
logs or browser storage. No replacement PSOOLA QR or PDF is generated locally.
Provider-issued workshop cancellations must be managed in PSOOLA; a local
booking change must not imply that provider inventory was released.

## Configuration

- Existing `PSOOLA_API_BASE_URL`, including `/api/partner/v1`, and `PSOOLA_API_KEY`.
- Existing webhook secret and signed webhook endpoint.
- Active iSmile workshop provider mappings on the Workshops configuration.
- PSOOLA must grant the partner **Allow complimentary issuance**, separately
  from cash-sale permission.
- Run the normal installer/migrations on upgrade. The new table is additive;
  existing registrations, payments and bookings are preserved.

## Verification

Automated issuance checks use the local PSOOLA simulator and an isolated test
database. They cover zero totals, authorization, company-workshop exclusion,
consent, validation, inventory, duplicate prevention, uncertain outcomes,
recovery after a lost successful response, email failure and webhook replay.
The existing complimentary, paid workshop, cash-sale and permission tests are
also used for regression verification. Browser checks cover the shared ticket
states, protected deletion, responsive layout and the complete mocked booking.

No real production ticket is issued for verification. An authorized staging
event/key and recipient were not supplied, so real PSOOLA complimentary-workshop
permission, zero-total fulfilment, ticket-link access and email/WhatsApp delivery
remain unverified live. Read-only catalogue checks had confirmed partner access
to the two iSmile workshop events.

Local verification passed: 29 guest-workshop checks, 86 existing complimentary
checks, 27 paid workshop checks, 36 cash-sale checks, 33 deletion checks, 20 field
checks, 33 browser checks, the additive upgrade check and the repository's 87
JavaScript tests, lint, type and security checks. PHP syntax checks passed.
The initial profile release recorded a pre-existing `admin-operations.php` failure
at the Viewer + Sponsors save-permission assertion. Its sponsor/map authorization
behavior is not changed by the workshop delegation permission.

Individual workshop delegation is stored in `admin_users.workshop_booking_access`.
The Owner can grant/revoke this separately on Users. The CLI-only
`configure-workshop-manager.php` uses `WORKSHOP_EDITOR_EMAIL` to grant both guest
registration and workshop booking to one existing active account, verifies that
only those two flags changed, and preserves the role and sign-in security.
The deployment's optional `workshop_editor_email` input invokes this grant and
checks the designated account's real pages without issuing production tickets.

## View PSOOLA ticket

The extra button beside View ticket and Copy ticket link retrieves the existing
order's `ticketsUrl` through authenticated `POST admin/psoola-ticket.php`. That
endpoint makes only a PSOOLA `GET /orders/{saved-id}` request. It verifies order
identity/reference/status and confirmed amount/currency, retaining full zero-total
complimentary validation for guest orders. Owners, individually authorized
workshop staff, and staff accessing their own registrations can use it.

The link is returned uncached and is never saved in HTML, logs or browser storage.
It opens PSOOLA's original ticket page containing the real QR tickets. The API
guide does not provide a separate QR-code payload, and no replacement QR or PDF
is generated. Orders for which PSOOLA returns a null/invalid link show an explicit
retrieval error. Local-only tickets show a disabled provider button.

A live read-only check retrieved an existing provider ticket link and received
HTTP 200 from its official ticket page. Some other issued orders returned
`ticketsUrl: null`; the button cannot open a provider ticket page for those until
PSOOLA supplies the link. No real tickets were created or delivery retried.
