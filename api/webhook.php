<?php
// POST /api/webhook.php – the payment company tells us a payment changed.
// Give Psoola exactly this address. The message is checked for Psoola's
// signature, logged, and then the payment's status is asked from Psoola
// directly; the message alone never creates a ticket.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\App;
use Ismile\Payments\Payments;

Api::run(static function (): void {
    Api::requireMethod('POST');
    Api::limit('webhook', 600, 600);   // far above what the payment company sends; stops a flood of junk
    $body = (string) file_get_contents('php://input', false, null, 0, 1_000_000);
    $result = Payments::handleWebhook((string) App::config('payments.gateway', 'fake'), Api::headers(), $body);
    Api::json($result['status'], ['ok' => $result['status'] === 200, 'outcome' => $result['outcome']]);
});
