<?php
// POST /api/sponsor.php (JSON, from sponsor.html) – saves a sponsorship or
// booth request. The company gets a "we received your request" email and the
// sponsors team gets a notification.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\Sponsors;
use Ismile\UserError;

Api::run(static function (): void {
    Api::requireMethod('POST');
    Api::sameOrigin();
    Api::limit('sponsor', 10, 600);
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 100_000), true);
    if (!is_array($in)) {
        throw new UserError('err_server', null, 400);
    }
    if (trim((string) ($in['hp'] ?? '')) !== '') {
        throw new UserError('err_server', null, 400);
    }
    $request = Sponsors::createFromForm($in);
    Api::json(200, ['ok' => true, 'ref' => $request['ref']]);
});
