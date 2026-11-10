The $200 VIP package includes one included hosted lunch (Day 1 or Day 2), an optional paid second lunch, and one workshop: Simplified Direct Veneers or Crown vs Veneer. The website VIP count has no fixed cap. Provider stock and physical workshop capacity still apply.

`vip_package_choices` stores the allowed workshop ID, checkout ID, later registration ID, immutable fulfillment request key, hold, fulfillment state and final workshop booking/provider request IDs. Unique keys prevent a second choice per checkout/registration and duplicate booking links. Checkout cleanup keeps the paid registration's choice. Existing VIP registrations receive no retroactive workshop entitlement.

Payment confirmation links the saved choice in the same database transaction as the registration. Fulfillment then issues one complimentary PSOOLA workshop order, included in the paid VIP package. Its zero amount adds no extra workshop revenue. Email delivery does not imply WhatsApp consent. Failed fulfillment remains queued for cron/admin review and retries the original persisted request. The admin registration displays the workshop and ticket state; workshop attendees display “Included with VIP”.

Deployment preparation (after normal migrations):

```sh
php backend/tools/configure-vip-package.php --prepare
```

This sets `vip_package_enabled=1`, `vip_registration_limit=0`, and **`vip_package_sales_open=0`** atomically. Keep sales closed while waiting for PSOOLA. The existing VIP form, with workshop cards below lunch, may be viewed at `/register.html`; payment stays disabled. Normal tickets remain available under their existing settings.

After PSOOLA confirms the main VIP ticket is $200, inspect the read-only verification:

```sh
php backend/tools/configure-vip-package.php --verify
```

The amount must match the official provider currency conversion. At the currently observed 1,690 IQD/USD, $200 is 338,000 IQD. This rate is derived from PSOOLA original-price metadata, not assumed by the code. Recheck PSOOLA before activation. Do not use a price override or charge a second workshop payment.

Only when reopening is authorized and the provider price is correct:

```sh
php backend/tools/configure-vip-package.php --open
```

The command verifies the actual price, complimentary fulfillment configuration and workshop availability before committing the sales switch. Preparation can close new VIP checkouts again. Existing paid entitlements continue fulfillment through the cron. Provider sales made outside the website cannot share our MySQL hold; if stock changes before confirmation, the payment is held for Finance review rather than silently issuing another workshop.

Validation: `backend/tests/vip-packages.php` is guarded to run only against an isolated local database and fake provider. It covers persisted selection, pricing gates, one lunch, exact package charge, zero extra workshop revenue, provider outage/lost-response recovery, repeated confirmation, checkout cleanup, more than six VIPs and concurrent last-workshop-seat holds.
