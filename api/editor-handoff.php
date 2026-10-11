<?php
// GET /api/editor-handoff.php?t=<pass>  — asked only by the editor's server
// (ismile.krd), behind the site password like every private API. Says whose
// one-time "Site content" pass this is, so the editor opens signed in.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\EditorHandoff;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::limit('handoff', 30, 300);
    $email = EditorHandoff::redeem((string) ($_GET['t'] ?? ''));
    if ($email === null) {
        Api::json(401, ['ok' => false, 'error' => 'handoff']);
    }
    Api::json(200, ['ok' => true, 'email' => $email]);
});
