<?php
// GET /api/psoola-prices.php
// PSOOLA's prices for the iSmile ticket types and lunch days, when the
// gateway is 'psoola'. Read from PSOOLA's partner API on the server and cached
// for five minutes (Payments\PsoolaPrices); the API key never leaves the
// server. The registration page receives the same prices through config.php.
// 404 when the gateway is not Psoola, 503 when PSOOLA's prices are unavailable.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Api;
use Ismile\App;
use Ismile\Payments\PsoolaPrices;

Api::run(static function (): void {
    Api::requireMethod('GET');
    Api::limit('psoola-prices', 240, 600);
    if ((string) App::config('payments.gateway', 'fake') !== 'psoola') {
        Api::json(404, ['ok' => false, 'error' => 'not_psoola']);
    }
    $prices = PsoolaPrices::get();
    if ($prices === null) {
        Api::json(503, ['ok' => false, 'error' => 'prices_unavailable']);
    }
    Api::json(200, ['ok' => true] + $prices);
});
