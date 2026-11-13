<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Certificates;

$user = Page::guard('certificates');
$id = isset($_GET['id']) ? max(0, (int) $_GET['id']) : null;
// A sample shows the design with an example name; it is never a real certificate.
$sample = isset($_GET['sample']);
$rows = $sample ? [['recipient_name' => 'Dr. Example Attendee Name', 'certificate_no' => 'ISM26-C-SAMPLE', 'attended_days' => 'Day 1 & Day 2']] : Certificates::rows($id);
if (!$rows) {
    Page::flash('error', 'No certificates are available. Guests must check in first.');
    Page::redirect('certificates.php');
}
$pdf = Certificates::pdf($rows);
if (!$sample) Audit::log((int) $user['id'], 'certificate.download', 'registration', $id, ['count' => count($rows)]);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ismile-certificates' . ($sample ? '-sample' : ($id !== null ? '-' . $id : '-all')) . '.pdf"');
header('Cache-Control: no-store');
echo $pdf;
