# iSmile 2026 — registration requirements, and how they map onto pretix

**Status:** draft for discussion · **Written:** 24 September 2026
**Audience:** whoever sets up or extends pretix for iSmile 2026

This document does two things:

1. Writes down what the iSmile registration flow **already does**, as a list of
   requirements, so nothing is lost when moving to pretix.
2. Says, for each requirement, whether pretix does it **out of the box**, with
   **configuration**, with a **plugin we must write**, or **not at all**.

Everything in part 1 is implemented and working on the current website
(front-end only — see "Where the current site stops"). Part 2 contains claims
about pretix that were checked against the official documentation, plus a few
marked **[verify]** that must be confirmed before committing to the plan.

---

## Where the current site stops

The website today has the complete visitor-facing flow, but **no server**. The
registration is remembered in the visitor's own browser and is never sent
anywhere. Concretely, `js/sections/registration.js` ends at:

```js
// TODO: send { attendee, order } to your server here.
```

So today: no payments are taken, no emails are sent, no seat count is shared
between visitors, and no reference number is verifiable. The organiser sends
each payment link and confirms each payment by hand.

**This is exactly the gap pretix is meant to close.**

---

## Part 1 — Requirements

### R1 · Attendee details

Every attendee provides, in one form:

| Field | Rule |
|---|---|
| First name | required, min 2 characters |
| Second name | required, min 2 characters |
| Third name | required, min 2 characters |
| Phone | Iraqi mobile (`07XXXXXXXXX`, `+9647XXXXXXXXX`) **or** any international `+…` number |
| Email | required, valid address |
| City | required |
| Gender | female / male / other / prefer not to say |
| Age | integer, 16–120 |
| Specialty | general dentist / specialist / oral & maxillofacial surgeon / dental technician / academic or researcher / dental student |
| Ticket type | professional / student |

**R1.1** — Three separate name fields, not one. The three are joined for display
but captured separately.

**R1.2** — Choosing the specialty "dental student" automatically switches the
ticket type to Student.

**R1.3** — Before leaving the details step, a confirmation dialog shows the full
name, phone and email and asks "Is everything correct?". The name is highlighted
and labelled as the name that will be printed on the certificate.

**R1.4** — A notice above the name fields states that these details are used for
the attendance certificate.

### R2 · Student verification

**R2.1** — Choosing the Student ticket reveals three extra fields:
- University and department — required
- Ambassador code — optional
- **Student ID photo** — required; JPG, PNG or WebP; maximum 8 MB; with an image
  preview and drag-and-drop

**R2.2** — The photo is for the organiser to check by eye. A student ticket is
not valid until someone has looked at the photo.

### R3 · Ticket types and prices

**R3.1** — Two ticket types: Professional and Student, at different prices.

**R3.2** — Prices are in **Iraqi dinars (IQD)**, whole numbers, no decimals.

**R3.3** — A price of `0` means "not announced yet" and displays as *"Price
soon"* rather than "free". The event opened for registration before prices were
decided, and this must keep working.

**R3.4** — Prices are changed by the organiser without a developer.

### R4 · Workshops as paid add-ons

**R4.1** — Workshops are **separate paid items attached to a registration**. A
workshop seat cannot exist without a registration.

**R4.2** — Each workshop has: title, company, speaker, price per seat, total
seats, seats remaining, an icon, and a stable ID used in links.

**R4.3** — An attendee may take **any number** of workshops. There is no
scheduling conflict rule at present (no two workshops are yet marked as running
at the same time) — but see Open question O4.

**R4.4** — Workshops are **optional**. The workshop step can be skipped, and
the button says "Skip workshops" when nothing is selected.

**R4.5** — Ticket and workshops are paid as **one order with one total**.

**R4.6** — Someone who is already registered can come back later and buy **only
workshops**, as a second, smaller order with no ticket line.

**R4.7** — A workshop already booked by that attendee cannot be booked twice; it
shows as "Reserved" and is not selectable.

### R5 · Seats and the waiting list

**R5.1** — Each workshop has a limited number of seats, shown as a number and a
progress bar.

**R5.2** — A workshop with zero seats shows as **Full** and cannot be selected.

**R5.3** — ~~A full workshop offers "Notify me", to be told when a seat opens.~~
**Removed from the website on 24 September 2026.** The button stored nothing and
notified nobody, so it promised something we could not deliver. A full workshop
now simply shows "Full".

*Still worth having, and pretix provides it properly:* pretix has a real waiting
list that collects the address and issues a voucher automatically when a seat
frees up. Turn it on there rather than rebuilding it here.

**R5.4** — **No seat-hold timer.** This was explicitly decided: seats are not
held for a period and then released. An unpaid booking simply stays unpaid.

### R6 · Payment

**R6.1** — Payment methods are **FIB (First Iraqi Bank)** and **FastPay** — the
two methods Iraqi attendees actually use. Card payment is not expected.

**R6.2 — The goal:** payment is taken **automatically** when someone registers.
The organiser does not want to send links and confirm transfers by hand. *(Not
possible on the current site; this is a main reason for moving to pretix.)*

**R6.3** — Until R6.2 is possible, the fallback is: the attendee chooses FIB or
FastPay, and receives a payment link by email.

**R6.4** — Tickets are **non-refundable**, and the attendee confirms they
understand this before ordering.

### R7 · Ticket delivery

**R7.1** — After payment is confirmed, the attendee receives their ticket **by
email, automatically**.

**R7.2** — Every registration gets a human-readable reference shown on screen
immediately. Current format: `ISM26-` plus 6 characters from an alphabet with no
easily-confused characters (no I, O, 0, 1) — e.g. `ISM26-PUPU92`.

**R7.3** — The ticket should carry a **unique ID and a QR code** for check-in at
the door, as an inline image and a PDF attachment.

**R7.4** — A ticket must never be sent twice for the same registration, failed
sends must be retried, the send status must be visible per attendee, and the
organiser needs a **Resend** action.

### R8 · Certificates

**R8.1** — Attendees receive an **attendance certificate** carrying the name
exactly as typed at registration.

**R8.2** — Workshop attendees should be distinguishable from conference-only
attendees, since workshop certificates differ.

### R9 · Languages

**R9.1** — Three languages: **English, Arabic, Kurdish (Sorani)**.

**R9.2** — Arabic and Kurdish are **right-to-left**, including the form layout,
not only the text.

**R9.3** — The chosen language is remembered for the visitor, and Arabic-speaking
browsers get Arabic by default.

**R9.4** — Numbers and prices stay in Latin digits in every language, so the
amount on screen matches the amount on the payment link.

**R9.5** — **Every** visitor-facing string is translatable, including buttons,
error messages and email text.

### R10 · Organiser administration

**R10.1** — The organiser edits everything without a developer: prices, workshop
list, seat counts, icons, and every piece of wording in all three languages.

**R10.2** — Changes take effect immediately.

**R10.3** — The organiser is not technical. The admin has plain-language labels
and hints, not database field names.

### R11 · Ambassador codes

**R11.1** — Students may enter an optional **ambassador code**, to credit the
student representative who brought them.

**R11.2** — The organiser needs to see how many registrations each code brought.

### R12 · Look and feel

**R12.1** — The registration pages match the iSmile brand: navy and teal, the
iSmile logo and wordmark, Schibsted Grotesk for Latin text and Noto Kufi Arabic
for Arabic and Kurdish.

**R12.2** — Light and dark themes, following the visitor's device.

**R12.3** — Fully usable on a phone. Most attendees register on a phone.

---

## Part 2 — How this maps onto pretix

### Summary

pretix is a **strong fit**. The hardest part of the requirements — workshops as
paid add-ons with their own seat limits, sold in one order with the ticket — is
a **core pretix feature**, not something to build. The real work is in three
places: **Iraqi payment methods**, **Kurdish language**, and **certificates**.

| | Requirement |
|---|---|
| ✅ **Native — configure only** | R1, R2.1, R3.1, R3.2, R3.4, R4.1–R4.7, R5.1, R5.2, R5.3, R5.4, R6.4, R7.1, R7.2, R7.3, R7.4, R9.1 (EN/AR), R9.3, R10.1–R10.3, R11.1 |
| 🔌 **Plugin needed** | R6.1, R6.2, R6.3 (payment), R8 (certificates), R12.1 (branding beyond settings) |
| ⚠️ **Needs checking** | R3.3, R9.2, R9.4, R11.2 |
| 🌍 **Translation work** | R9.1 (Kurdish) |

### The good news, in detail

**Workshops → pretix "add-on products" (R4).** pretix lets a product (the
conference ticket) offer **add-ons** chosen from a category (the workshops),
within one order. This is the `/items/{id}/addons/` API and the matching admin
screens. Everything in R4 follows from it:

- R4.1 (no workshop without a ticket) — inherent: an add-on belongs to a base product
- R4.3 (any number) — the add-on rule has minimum and maximum counts; set the max high
- R4.4 (optional) — set minimum 0
- R4.5 (one total) — add-ons are lines in the same order
- R4.7 (no double booking) — pretix will not let the same add-on be picked twice on one ticket

**Seats → pretix "quotas" (R5.1, R5.2).** A quota caps how many of an item can
be sold. One quota per workshop with `size` = seats. pretix computes
availability by subtracting paid orders, carts, blocking vouchers and waiting
list entries. It also handles what our site cannot: **two people buying the last
seat at the same time.**

**"Notify me" → pretix waiting list (R5.3).** A real waiting list, with
automatic voucher issuing when a seat frees up. This is better than what we
have — our button currently stores nothing.

**All of R1 → pretix "questions".** Per-order and per-attendee custom fields,
with types for text, number, choice, phone and **file upload [verify]** — which
covers the student ID photo (R2.1). pretix natively separates *order* data
(buyer) from *attendee* data (per ticket).

**R2.2 (student verification) → pretix "require approval".** An order can be
marked as requiring organiser approval before it can be paid. Apply it to the
student ticket, and no student pays until someone has looked at the ID photo.
This is better than our current flow, where a student could pay first.

**R7 (tickets, QR, PDF, resend) → native.** PDF tickets with QR codes, automatic
email on payment, a resend action, and a check-in app are all core pretix. The
whole ticket-email specification written earlier is **already built** — it does
not need to be developed.

**R11.1 (ambassador codes) → pretix vouchers.** A voucher per ambassador, with
usage counts. Note the difference in behaviour below.

**R10 (organiser admin) → native**, and considerably more capable than the
current JSON admin.

### What must be built as plugins

pretix has a documented plugin API — plugins are Django apps that hook in
through signals. The two we need are both well-supported extension points.

#### Plugin 1 — FIB and FastPay payment (R6.1, R6.2, R6.3) — **required**

This is the one plugin without which the project cannot launch. pretix ships
Stripe, PayPal, SEPA, bank transfer and others; **no Iraqi provider**.

The documented approach:

```python
from django.dispatch import receiver
from pretix.base.signals import register_payment_providers

@receiver(register_payment_providers, dispatch_uid="payment_fib")
def register_payment_provider(sender, **kwargs):
    from .payment import FIB
    return FIB
```

The provider subclasses `pretix.base.payment.BasePaymentProvider`, and
`settings_form_fields` gives the organiser the merchant credentials form.

**Effort:** moderate and well-trodden — *if* FIB and FastPay provide an online
payment API. See Open question O1, which is the single biggest risk in this
plan. A provider that offers only a static payment link, with no callback to
confirm payment, cannot deliver R6.2 (automatic payment) no matter how the
plugin is written; it can only reproduce today's manual flow inside pretix.

#### Plugin 2 — Attendance certificates (R8) — **required, no native support**

pretix generates tickets and invoices, not certificates. A plugin can:

- listen for the event ending, or expose an organiser action
- render a PDF per attendee carrying the attendee name from R1
- distinguish workshop attendees from conference-only ones by reading the
  add-ons on each order position (R8.2)
- email it out

pretix's PDF layout machinery and its invoice-renderer pattern
(`register_invoice_renderers`) are good models to follow.

**Effort:** moderate. Self-contained and low-risk.

#### Plugin 3 — iSmile branding (R12.1) — **optional**

pretix supports colours, a logo and custom CSS through event settings, which may
be enough. A plugin is only needed if the shop must look exactly like the
current pages. **Recommendation: try settings first, decide after seeing it.**

### Considered and rejected: one event per workshop

A reasonable alternative was raised: make each workshop its **own pretix event**,
so every workshop has its own code and its own limit. It is possible, but it
breaks two of our own rules, and it wins nothing we do not already have.

**The deciding fact: an order belongs to a single event.** Separate events mean
separate carts, separate checkouts and separate payments.

| | Add-ons (one event) | One event per workshop |
|---|---|---|
| Own seat limit per workshop | yes — one quota each | yes |
| Own code / own page | yes — own product | yes — own event slug |
| Own check-in list at the door | yes — list filtered to that product | yes |
| **One order, one total (R4.5)** | **yes** | **no — pays 3–4 times** |
| **No workshop without a registration (R4.1)** | **yes, inherent** | **no native rule exists** |
| Attendee types their details once | yes | no — once per event |
| Buying workshops later (R4.6) | yes, added to the order | yes, naturally |
| Selling a workshop to a non-attendee | no, by design | yes |
| One list of who is in what | yes | no — reconcile 4–5 events |
| Certificate plugin (R8) | reads the add-ons on the order | must match people by email across events |

Making separate events obey R4.1 would need a plugin that issues a voucher in
each workshop event whenever a conference ticket is paid for — real work, to
rebuild what add-ons do for free.

**When separate events would be right:** a workshop sold to the public
independently of the conference, held on a different date, or run by a different
team collecting its own money. If one workshop is like that, split out that one
and leave the rest as add-ons.

**Not applicable: event series / subevents.** pretix's third model exists for
the same product sold across many dates (weekly classes, time slots). It keeps a
single order, but it does not describe a two-day conference with parallel
workshops.

**Decision: one event, workshops as add-on products.**

### Where pretix will change how things work

These are not problems, but they are decisions, and someone will notice:

1. **The reference number format (R7.2).** pretix generates its own order codes.
   Keeping `ISM26-XXXXXX` exactly would mean fighting the system. **Recommend:
   accept pretix's codes.** They are unique, checkable and printed on the
   ticket — which ours are not.

2. **Ambassador codes (R11.1).** Ours is a free-text field anyone can type;
   pretix vouchers must exist in advance and typically affect price or
   availability. pretix's version is stronger (real counting, R11.2), but
   ambassadors must be created up front, and a wrong code will be rejected
   rather than silently accepted.

3. **"Price soon" (R3.3).** pretix will show a `0` price as **free** and let
   people order for nothing. **This must be handled before opening the shop** —
   either keep products inactive until prices are set, or hide the price with a
   small plugin or CSS. Do not simply set everything to 0. **[verify]**

4. **The reference-number lookup we built** (someone on another device typing
   their reference) becomes unnecessary: pretix has real order links and an
   order lookup by code and email. Drop it.

5. **Buying workshops later (R4.6).** pretix does support adding to an existing
   order. **[verify]** whether the self-service version of this covers our case
   or whether the organiser must do it.

---

### Appendix — configuring the workshops (no code needed)

The workshop requirement (R4) is configuration, roughly an hour of admin work.

1. Create an item **category** called "Workshops", marked as an add-on category.
2. Create one **item per workshop** inside it, each with its own price (R4.2).
3. Create one **quota per workshop**, `size` = the number of seats (R5.1).
4. On the **Professional ticket** item, open its add-ons and attach the
   Workshops category with the settings below.
5. **Repeat step 4 on the Student ticket.** Easy to forget, and students would
   then see no workshops at all with no error to explain it.

| Field | Value | Requirement it satisfies |
|---|---|---|
| `addon_category` | the Workshops category | R4.1 |
| `min_count` | `0` | **R4.4 — workshops are optional; registering for the conference alone is valid** |
| `max_count` | number of workshops | R4.3 — any number |
| `multi_allowed` | `false` | R4.7 — the same workshop cannot be booked twice |
| `price_included` | **`false`** | **R4 — workshops are paid.** See the warning below. |

> **Trap: `price_included` must be `false`.** When `true`, the add-on is free
> with the ticket. It appears as `true` in pretix's own API documentation
> example, so it is easy to copy by mistake — and every workshop would then cost
> nothing.

What this gives us without writing any code: live seat counts, sold-out states,
the waiting list, no double-booking, one order with one total, and no workshop
seat without a registration.

## Open questions — answer these before committing

| # | Question | Why it matters |
|---|---|---|
| **O1** | **Do FIB and FastPay offer an online payment API with a server callback confirming payment?** Ask both for *merchant / online payment gateway* access for a business. | **The whole automatic-payment goal depends on this.** Without it, payment stays manual whatever we use. Expect company documents and several weeks. |
| **O2** | Is **Kurdish (Sorani)** available in pretix, and how complete is **Arabic**? | R9.1. Kurdish is very unlikely to exist. It would mean contributing a translation — a real but one-off effort. Budget for it. |
| **O3** | Does pretix's **right-to-left** support cover the full shop layout, not just text direction? | R9.2. Our site is fully RTL today; a half-RTL checkout would be a visible step backwards for most of our audience. |
| **O4** | Will any two workshops **run at the same time**? | If yes, we need a rule preventing clashing bookings. pretix does not do this natively — it would be a third plugin. Cheap to answer now, expensive to discover later. |
| **O5** | **Self-hosted or pretix Hosted?** | A custom payment plugin generally requires self-hosting. This likely decides it — but confirm, because self-hosting means someone maintains a server. |
| **O6** | Confirm the **file-upload question type** exists for the student ID photo (R2.1). | If not, student verification needs another route. |
| **O7** | What is the **deadline**? The summit is 20–21 November 2026. | Plugin work plus a merchant account is not a two-week job. This decides whether the first event runs on pretix at all, or whether the current site runs it once while pretix is prepared properly. |

---

## Recommendation

1. **Answer O1 today.** Everything else is secondary — if there is no payment
   API, the automatic-payment goal is out of reach for this event regardless of
   the ticketing system, and the decision changes shape.
2. **Answer O2, O3 and O7 this week.** Language and time are the other two
   things that could make pretix the wrong choice *for November 2026*,
   even though it is the right choice in general.
3. Then set up a **trial pretix event** and configure R1–R5 and R10 with no
   plugins at all. That is most of the requirements, and it will be quick — this
   proves the fit before anyone writes code.
4. Start the **FIB/FastPay plugin** only once O1 is answered, and the
   **certificate plugin** any time, since it depends on nothing.

The honest summary: **pretix fits this event well and replaces a large amount of
work we would otherwise have to build and maintain ourselves** — payments,
ticket emails, QR check-in, waiting lists and seat accounting are all solved
problems there. The risk is not pretix. The risk is the Iraqi payment providers
and the November deadline.
