<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
\Ismile\Api::run(static function():void {
    \Ismile\Api::requireMethod('GET');
    \Ismile\Api::json(200,\Ismile\WorkshopCatalog::all());
});
