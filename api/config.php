<?php
// GET /api/config.php?lang=en
// What the registration page needs before it shows the form: is registration
// open, the prices (from data/tickets.json), and which lunch days are left.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Lang;
use Ismile\Registrations;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::json(200, ['ok' => true] + Registrations::publicState(Lang::pick($_GET['lang'] ?? 'en')));
});
