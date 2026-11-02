<?php
// POST /api/workshop-register.php  (from workshop.html: workshop + email)
//
// "Register for workshop": the visitor types the email they registered for
// iSmile 2026 with. A paid summit attendee is redirected to PSOOLA checkout.
// Workshop registration is created only after verified payment.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\UserError;

Api::run(static function (): void {
    Api::requireMethod('POST');
    Api::sameOrigin();
    Api::limit('workshop-register', 200, 600);
    Api::limit('workshop-email-'.substr(hash('sha256',strtolower(trim((string)($_POST['email'] ?? '')))),0,24),6,600);

    // A field people never see: bots fill it in, people leave it empty.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        throw new UserError('err_server', null, 400);
    }
    Api::json(200, \Ismile\WorkshopCheckout::start((string) ($_POST['workshop'] ?? ''), (string) ($_POST['email'] ?? '')));
});
