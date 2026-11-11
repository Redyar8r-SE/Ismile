<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\BadgeBatches;
use Ismile\UserError;

// The two-sided badge PDF. Every badge made here is saved in the badge batch history.
//   GET  badge.php?id=REGISTRATION   one guest (a new batch of one)
//   POST badge.php  guests[]=…       the guests ticked on the Tickets tab (a new batch)
//   GET  badge.php?batch=N           a saved batch again
// &print=1 (or print=1 in the form) opens it to print instead of downloading.
$user = Page::guard('registrations');
$print = ($_GET['print'] ?? $_POST['print'] ?? '') === '1';
$back = 'tickets.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Auth::checkCsrf();
        $made = BadgeBatches::create($user, array_map('intval', (array) ($_POST['guests'] ?? [])));
        [$pdf, $name] = [$made['pdf'], 'ismile-badges-batch-' . $made['id']];
    } elseif (isset($_GET['batch'])) {
        $batchId = max(0, (int) $_GET['batch']);
        $back = 'tickets.php?batch=' . $batchId;
        [$pdf, $name] = [BadgeBatches::download($user, $batchId), 'ismile-badges-batch-' . $batchId];
    } else {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $back = 'registration.php?id=' . $id;
        $made = BadgeBatches::create($user, [$id]);
        [$pdf, $name] = [$made['pdf'], 'ismile-badge-batch-' . $made['id']];
    }
} catch (UserError $error) {
    Page::flash('error', $error->key);
    Page::redirect($back);
} catch (Throwable) {
    Page::flash('error', 'The badges could not be made: PSOOLA did not answer. Try again in a moment; nothing was saved.');
    Page::redirect($back);
}
// Tells the Tickets tab the PDF is ready, so it can reload and show the new batch.
$done = (string) ($_POST['done'] ?? '');
if (preg_match('/^[a-z0-9]{8,32}$/', $done)) {
    setcookie('badge_done', $done, ['path' => '/admin/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'expires' => time() + 300]);
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($print ? 'inline' : 'attachment') . '; filename="' . $name . '.pdf"');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
echo $pdf;
