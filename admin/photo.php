<?php
// Shows one registered student's ID photo, read from the database (table
// student_id_photos), only to a signed-in person whose role may see ID photos.
// Every view is in the audit log. ?id= is the registration.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Db;
use Ismile\IdPhotos;

$user = Page::guard('photos');
$id = (int) ($_GET['id'] ?? 0);
$photo = IdPhotos::find((int) Db::value('SELECT id_photo_id FROM registrations WHERE id = ?', [$id]));
if ($photo === null) {
    http_response_code(404);
    exit('No photo');
}
if (($_GET['log'] ?? '1') !== '0') {
    Audit::log((int) $user['id'], 'photo.view', 'registration', $id);
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($photo['image']));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="student-id-' . $id . '.jpg"');
echo $photo['image'];
