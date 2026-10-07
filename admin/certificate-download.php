<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Certificates;

$user = Page::guard('certificates');
$id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : null;
$rows = Certificates::rows($id);
if (!$rows) {
    Page::flash('error', 'No certificates are available. Guests must check in first.');
    Page::redirect('certificates.php');
}
$pdf = Certificates::pdf($rows);
Audit::log((int) $user['id'], 'certificate.download', 'registration', $id, ['count' => count($rows)]);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ismile-certificates' . ($id !== null ? '-' . $id : '-all') . '.pdf"');
header('Cache-Control: no-store');
echo $pdf;
