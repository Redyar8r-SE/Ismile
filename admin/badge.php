<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\BadgeBatches;
use Ismile\Badges;
use Ismile\PsoolaTickets;
use Ismile\Registrations;
use Ismile\UserError;

// The two-sided badge PDF (10 × 11.5 cm pages).
//   GET badge.php?id=REGISTRATION   one guest, for yourself: not saved as a batch
//   GET badge.php?batch=ID&sheet=a4 every guest of a saved badge batch, on print sheets (a4, a3, sra3, single)
$user = Page::guard('registrations');
$back = 'tickets.php';
try {
    if (isset($_GET['batch'])) {
        $batchId = max(0, (int) $_GET['batch']);
        $back = 'tickets.php?batch=' . $batchId;
        $batch = BadgeBatches::find($batchId) ?? throw new UserError('Badge batch not found. It may have been deleted.');
        $sheet = isset(Badges::SHEETS[$_GET['sheet'] ?? '']) ? (string) $_GET['sheet'] : 'a4';
        [$pdf, $name] = [BadgeBatches::pdf($user, $batchId, $sheet), 'ismile-badges-batch-' . (int) $batch['batch_number'] . '-' . $sheet];
    } else {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $back = 'registration.php?id=' . $id;
        $registration = Registrations::find($id) ?? throw new UserError('Registration not found.');
        $codes = PsoolaTickets::codesFor($user, [$registration]);
        [$pdf, $name] = [Badges::pdf($registration, $codes[$id]), 'ismile-badge-' . $registration['ref']];
        Audit::log((int) $user['id'], 'badge.download', 'registration', $id);
    }
} catch (UserError $error) {
    Page::flash('error', $error->key);
    Page::redirect($back);
} catch (Throwable) {
    Page::flash('error', 'The badges could not be made: PSOOLA did not answer. Try again in a moment.');
    Page::redirect($back);
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
echo $pdf;
