# Workshops administration

The approved Pencil layout is implemented at `admin/workshops.php`. It keeps the
shared navigation and places the workshop directory beside the selected workshop
and its attendee list. The directory supports organizer filters and trainer/title
search; attendees support name/reference/phone search, payment filters and paging.
Overview and existing audit activity are available alongside the attendee list.

The default interface shows Available, Fully booked, Availability unavailable or
Company managed. Numerical capacity is confined to existing Owner settings.
Portraits use contain fitting, natural proportions and eight pixels of headroom;
the entire supplied head and shoulders remain visible on desktop and mobile.

Owner settings retain all existing creation, multilingual editing, schedule,
photo upload/removal, organizer contacts, currencies, capacity, hide/show and
eligible deletion controls. PDF and CSV sign-in sheets remain available. Attendee
menus preserve authorized payment recording, corrections, removal and explicit
paid provider ticket issuance. Existing delegated workshop permissions are reused.

The booking drawer searches active paid or complimentary summit registrations.
Paid bookings reuse Office::addWorkshop and the exact-receipt payment rules.
Guest tickets use GuestWorkshops::start/resume and the existing complimentary
client, stable saved payload/key, permission checks and inventory enforcement.
The server constructs the zero-total request; no prices or payments are submitted
for guest tickets. WhatsApp consent is explicitly confirmed before issuance.
Errors appear inside the drawer. Existing bookings and pending orders prevent a
second issuance; uncertain guest requests expose Resume saved request.
Company workshops retain the organizer contact route.

Confirmed bookings and guest tickets are counted separately. Collections sum only
paid booking amounts, grouped by currency, and never count complimentary tickets.
The redesign requires no database migration and changes no account grants.

Verification: 18 read-only workspace checks; 30 browser checks covering search,
photographs, 320–1440px layout, Owner settings, delegated access, zero-total mock
issuance, duplicate prevention, exact cash receipt recording, provider-permission
errors and Viewer restrictions. Existing 86 complimentary-order, 32 guest-workshop,
163 workshop-access and 27 registration-access checks pass. The repository's 87
JavaScript tests, security checks, lint and type checks pass. PHP/JS syntax checks
pass. All issuance tests use the local provider simulator and isolated databases.
No production tickets are issued, messaged, charged or scanned for verification.

Deployment checks render the new layout and verify that `workshops.css` and
`workshops.js` match the release through nginx. The nginx asset allow-list includes
these two files; all controllers and templates with underscore names remain
inaccessible as public PHP routes.
