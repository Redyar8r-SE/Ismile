<?php
// Only numbered availability is public. Never include company or contact data.
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Sponsors;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::json(200, ['ok' => true, 'booked' => Sponsors::bookedBooths()]);
});
