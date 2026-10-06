<?php
// GET /api/qr.php?r=ISM26-XXXXXX&k=TOKEN – the ticket's QR code as an image,
// for the ticket email and the person's own status page. Personal link only.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\App;
use Ismile\Links;
use Ismile\RateLimit;
use Ismile\Registrations;
use Ismile\Tickets;

if (!RateLimit::hit('qr:' . App::clientIp(), 120, 600)) {
    Api::json(429, ['ok' => false, 'error' => 'err_busy']);
}
$registration = Registrations::findByRef((string) ($_GET['r'] ?? ''));
if ($registration === null || !Links::viewMatches($registration, (string) ($_GET['k'] ?? ''))) {
    Api::json(404, ['ok' => false, 'error' => 'link']);
}
$ticket = Tickets::forRegistration((int) $registration['id']);
if ($ticket === null || $ticket['cancelled_at'] !== null || !in_array($registration['status'], ['paid', 'complimentary'], true)) {
    Api::json(404, ['ok' => false, 'error' => 'no_ticket']);
}
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo Tickets::qrPng($ticket);
