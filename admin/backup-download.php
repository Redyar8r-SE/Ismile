<?php
// Backups contain private event records. Every request needs an Owner session.
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Backup;

$user = Page::guard('owner');
$backup = Backup::find((string)($_GET['file'] ?? ''));
if ($backup === null) {
    http_response_code(404);
    Page::top('Backup not found','backups');
    echo Page::emptyState('This backup is not available','Choose a completed copy from the Backups page.','backups');
    echo '<div class="form-actions"><a class="btn" href="backups.php">Back to backups</a></div>';
    Page::bottom();
    exit;
}
$stream = fopen($backup['path'],'rb');
if ($stream === false) {
    http_response_code(404);
    exit('Backup is not available.');
}
try {
    Audit::log((int)$user['id'],'backup.downloaded',null,null,['file'=>$backup['name']]);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $backup['name'] . '"');
    header('Content-Length: ' . $backup['size']);
    header('Cache-Control: private, no-store');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    fpassthru($stream);
} finally {
    fclose($stream);
}
