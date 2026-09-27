<?php
// Loaded first by every entry point: the public API, the admin pages and the
// timed jobs. It reads config.php, sets the clock and error handling, and
// loads the classes in src/.

declare(strict_types=1);

use Ismile\App;

if (PHP_VERSION_ID < 80100) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'The iSmile backend needs PHP 8.1 or newer; this is PHP ' . PHP_VERSION . ".\n");
        exit(1);
    }
    http_response_code(500);
    exit('The iSmile backend needs PHP 8.1 or newer.');
}

require __DIR__ . '/vendor/autoload.php';

$configFile = getenv('ISMILE_CONFIG') ?: __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The backend is not set up yet: config.php is missing.');
}

App::boot(require $configFile);
