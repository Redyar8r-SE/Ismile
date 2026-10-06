<?php
// GET /api/pay.php?r=ISM26-XXXXXX&k=TOKEN
// The "Pay now" link (emailed for a registration taken by phone) and the
// "Try again" button on payment.html. Starts a fresh payment for the form
// waiting for payment and sends the visitor to the payment page. The link is
// personal and works until the form expires.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\App;
use Ismile\Checkouts;
use Ismile\Links;
use Ismile\Payments\Payments;
use Ismile\RateLimit;
use Ismile\Registrations;
use Ismile\UserError;

Api::requireMethod('GET');
if (!RateLimit::hit('pay:' . App::clientIp(), 30, 600)) {
    Api::redirect(App::url('payment.html?e=busy'));
}
$ref = (string) ($_GET['r'] ?? '');
$key = (string) ($_GET['k'] ?? '');

// Already registered (paid): the page shows the ticket.
$registration = Registrations::findByRef($ref);
if ($registration !== null && Links::viewMatches($registration, $key)) {
    Api::redirect(Links::statusUrl($registration));
}
$checkout = Checkouts::findByRef($ref);
if ($checkout === null || !Links::viewMatches($checkout, $key)) {
    Api::redirect(App::url('payment.html?e=link'));
}
$statusPage = Links::statusUrl($checkout);

try {
    $started = Payments::start($checkout);
} catch (UserError $error) {
    Api::redirect($statusPage . '&e=' . rawurlencode($error->key));
} catch (\Throwable $error) {
    App::log('error', 'Pay link failed', ['ref' => $checkout['ref'], 'error' => $error->getMessage()]);
    Api::redirect($statusPage . '&e=pay_start_failed');
}

Api::redirect($started['redirect'] ?? ($statusPage . '&e=pay_start_failed'));
