<?php
// GET /api/status.php?r=ISM26-XXXXXX&k=TOKEN[&p=PAYMENT]
//
// Used by payment.html ("Checking your payment…") and by the person's own
// link. It never decides anything itself: when a payment is still waiting, it
// asks the payment company (at most every 10 seconds), exactly as the webhook
// and the timed job do.
//
// status: "paid" / "complimentary" (registered, with the ticket),
//         "unpaid" (the form is waiting for payment: NOT registered yet),
//         "expired" (the form was not paid in time: fill it in again),
//         "cancelled".

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Links;
use Ismile\Payments\Payments;
use Ismile\RateLimit;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::limit('status', 240, 600);
    $ref = (string) ($_GET['r'] ?? '');
    $key = (string) ($_GET['k'] ?? '');

    // Not registered yet? Then the form waiting for payment, and its payment.
    $registration = Registrations::findByRef($ref);
    if ($registration === null || !Links::viewMatches($registration, $key)) {
        $checkout = Checkouts::findByRef($ref);
        if ($checkout === null || !Links::viewMatches($checkout, $key)) {
            throw new UserError('link', null, 404);
        }
        $paymentId = (int) ($_GET['p'] ?? 0);
        $payment = $paymentId > 0
            ? Db::one('SELECT * FROM payments WHERE id = ? AND checkout_id = ?', [$paymentId, $checkout['id']])
            : Db::one('SELECT * FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$checkout['id']]);
        if ($payment !== null && in_array($payment['status'], ['created', 'waiting'], true) && RateLimit::hit('check:' . $payment['id'], 1, 10)) {
            Payments::check($payment);
            $payment = Payments::find((int) $payment['id']);
        }
        $registration = $payment !== null && $payment['registration_id'] !== null ? Registrations::find((int) $payment['registration_id']) : null;
        if ($registration === null) {
            $quote = SiteData::quoteFor($checkout);
            Api::json(200, [
                'ok'        => true,
                'ref'       => $checkout['ref'],
                'firstName' => $checkout['first_name'],
                'status'    => Checkouts::isOpen($checkout) ? 'unpaid' : 'expired',
                'ticket'    => $checkout['ticket_type'],
                'lunch1'    => (bool) $checkout['lunch_day1'],
                'lunch2'    => (bool) $checkout['lunch_day2'],
                'amount'    => SiteData::pricesReadyFor($checkout) ? $quote['amount'] : null,
                'currency'  => $quote['currency'],
                'totals'    => $quote['totals'],
                'payment'   => $payment['status'] ?? null,
                'ticketNo'  => null,
                'qr'        => null,
            ]);
        }
    }

    $ticket = Tickets::forRegistration((int) $registration['id']);
    $hasTicket = $ticket !== null && $ticket['cancelled_at'] === null && in_array($registration['status'], ['paid', 'complimentary'], true);
    Api::json(200, [
        'ok'        => true,
        'ref'       => $registration['ref'],
        'firstName' => $registration['first_name'],
        'status'    => $registration['status'],
        'ticket'    => $registration['ticket_type'],
        'lunch1'    => (bool) $registration['lunch_day1'],
        'lunch2'    => (bool) $registration['lunch_day2'],
        'amount'    => null,
        'currency'  => null,
        'payment'   => 'paid',
        'ticketNo'  => $hasTicket ? $ticket['ticket_no'] : null,
        'qr'        => $hasTicket ? 'api/qr.php?r=' . rawurlencode($registration['ref']) . '&k=' . rawurlencode($key) . '&v=' . $ticket['version'] : null,
    ]);
});
