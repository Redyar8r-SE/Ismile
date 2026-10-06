<?php
// Finds the backend folder and loads it. The backend lives OUTSIDE the public
// web folder on the server, so its code and settings have no web address.
// Where it is looked for, in order:
//   1. the ISMILE_BACKEND environment variable (SetEnv in .htaccess),
//   2. api/_backend.php, a one-line file written on the server
//      (<?php return '/home/USER/ismile-backend';), not in Git,
//   3. a folder "ismile-backend" next to the web folder,
//   4. backend/ inside the project (local development only).

declare(strict_types=1);

(static function (): void {
    $candidates = [getenv('ISMILE_BACKEND') ?: null];
    if (is_file(__DIR__ . '/_backend.php')) {
        $candidates[] = require __DIR__ . '/_backend.php';
    }
    $candidates[] = dirname(__DIR__, 2) . '/ismile-backend';
    $candidates[] = dirname(__DIR__) . '/backend';
    foreach ($candidates as $folder) {
        if (is_string($folder) && is_file($folder . '/bootstrap.php')) {
            require $folder . '/bootstrap.php';
            // No form or link of ours sends lists (name[]=…). Turning any that
            // arrive into empty text stops "array to string" errors (500s).
            foreach ([&$_GET, &$_POST] as &$input) {
                foreach ($input as $key => $value) {
                    if (!is_string($value)) {
                        $input[$key] = '';
                    }
                }
            }
            unset($input);
            return;
        }
    }
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"backend_missing"}';
    exit;
})();
