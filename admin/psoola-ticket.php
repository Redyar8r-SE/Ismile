<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\PsoolaTickets;
use Ismile\UserError;

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$user = Page::guard('registrations');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private, max-age=0');
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new UserError('POST required.',null,405);
    Auth::checkCsrf();
    $result = PsoolaTickets::link($user,(int)($_POST['registration_id'] ?? 0));
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_HEX_TAG);
} catch (UserError $error) {
    http_response_code($error->status);
    echo json_encode(['ok'=>false,'error'=>$error->key],JSON_HEX_TAG);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'The PSOOLA ticket page is temporarily unavailable. Try again later.']);
}
