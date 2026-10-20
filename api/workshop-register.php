<?php
// POST /api/workshop-register.php  (from workshop.html: workshop + email)
//
// "Register for workshop": the visitor types the email they registered for
// iSmile 2026 with. A registered person gets a seat, booked NOT PAID (the
// office collects the workshop money); anyone else is told to register for
// the summit first. Errors are website translation keys (wd_err_*).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\UserError;
use Ismile\Workshops;

Api::run(static function (): void {
    Api::requireMethod('POST');
    Api::sameOrigin();
    Api::limit('workshop-register', 10, 600);   // stops anyone trying many emails

    // A field people never see: bots fill it in, people leave it empty.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        throw new UserError('err_server', null, 400);
    }
    Workshops::registerOnline((string) ($_POST['workshop'] ?? ''), (string) ($_POST['email'] ?? ''));
    Api::json(200, ['ok' => true]);
});
