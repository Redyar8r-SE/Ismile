<?php
// Public website controls. No private settings or guest data are returned.
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Settings;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::json(200, [
        'ok' => true,
        'registrationOpen' => Settings::bool('registration_open'),
        'programHidden' => Settings::bool('program_hidden'),
    ]);
});
