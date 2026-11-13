<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Certificates;

// The installed certificate artwork, for the preview on the Certificates page.
// It carries real signatures, so it is served only to signed-in team members
// who may open certificates, and never cached by shared caches.
Page::guard('certificates');
$file = Certificates::designFile();
if ($file === null) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($file);
