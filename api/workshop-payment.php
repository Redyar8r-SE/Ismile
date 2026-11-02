<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
\Ismile\Api::run(static function():void {
    \Ismile\Api::requireMethod('GET');
    $token = (string)($_GET['token'] ?? '');
    \Ismile\Api::limit('workshop-status-'.substr(hash('sha256',$token),0,24),12,60);
    \Ismile\Api::json(200,\Ismile\WorkshopCheckout::status($token));
});
