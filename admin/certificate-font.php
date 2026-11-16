<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Badges;

// The badge name font, so the Certificates preview shows the guest name in the
// same font the badge and certificate PDFs print. Signed-in team members only;
// the licensed font is never public or cached by shared caches.
Page::guard('certificates');
$font = Badges::nameFontBytes();
if ($font === null) { http_response_code(404); exit; }
header('Content-Type: font/ttf');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $font;
