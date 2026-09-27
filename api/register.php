<?php
// POST /api/register.php  (multipart form, from register.html)
//
// Checks the form, keeps it while the person pays, and starts the payment.
// Nothing is registered here: the person is registered only when the payment
// company confirms the exact amount (Payments::apply). Students send their
// ID photo with the form and pay straight away, like everyone else.
// The server sets the price and the reference; nothing the browser sends
// about money is used.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Checkouts;
use Ismile\Links;
use Ismile\Payments\Payments;
use Ismile\UserError;

Api::run(static function (): void {
    Api::requireMethod('POST');
    Api::sameOrigin();
    Api::limit('register', 60, 600);   // generous: a whole class may register from one campus network

    // A field people never see: bots fill it in, people leave it empty.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        throw new UserError('err_server', null, 400);
    }

    $checkout = Checkouts::createFromForm($_POST, $_FILES['student_id'] ?? null);
    $answer = [
        'ok'        => true,
        'ref'       => $checkout['ref'],
        'statusUrl' => Links::statusUrl($checkout),
        'redirect'  => null,
        'payError'  => null,
    ];
    // If the payment cannot start right now, the answer is still "ok" with
    // the reason: the person's own payment page explains it and offers
    // "Try again", so the form is never sent a second time.
    try {
        $started = Payments::start($checkout);
        $answer['redirect'] = $started['redirect'];
        $answer['payError'] = $started['error'];
    } catch (UserError $error) {
        $answer['payError'] = $error->key;
    }
    Api::json(200, $answer);
});
