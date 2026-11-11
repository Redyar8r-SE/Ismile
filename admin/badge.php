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
//   GET badge.php?batch=ID          every guest of a saved badge batch
// &print=1 opens it to print instead of downloading.
$user = Page::guard('registrations');
$print = ($_GET['print'] ?? '') === '1';
$back = 'tickets.php';
try {
    if (isset($_GET['batch'])) {
        $batchId = max(0, (int) $_GET['batch']);
        $back = 'tickets.php?batch=' . $batchId;
        $batch = BadgeBatches::find($batchId) ?? throw new UserError('Badge batch not found. It may have been deleted.');
        [$pdf, $name] = [BadgeBatches::pdf($user, $batchId), 'ismile-badges-batch-' . (int) $batch['batch_number']];
    } else {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $back = 'registration.php?id=' . $id;
        $registration = Registrations::find($id) ?? throw new UserError('Registration not found.');
        $codes = PsoolaTickets::codesFor($user, [$registration]);
        [$pdf, $name] = [Badges::pdf($registration, $codes[$id]), 'ismile-badge-' . $registration['ref']];
        Audit::log((int) $user['id'], $print ? 'badge.print' : 'badge.download', 'registration', $id);
    }
} catch (UserError $error) {
    Page::flash('error', $error->key);
    Page::redirect($back);
} catch (Throwable) {
    Page::flash('error', 'The badges could not be made: PSOOLA did not answer. Try again in a moment.');
    Page::redirect($back);
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($print ? 'inline' : 'attachment') . '; filename="' . $name . '.pdf"');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
echo $pdf;
