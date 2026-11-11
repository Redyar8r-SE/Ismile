<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Badges;
use Ismile\PsoolaTickets;
use Ismile\Registrations;
use Ismile\UserError;

// GET badge.php?id=REGISTRATION[&print=1] – the guest's two-sided badge PDF.
$user = Page::guard('certificates');
$registration = Registrations::find(max(0, (int) ($_GET['id'] ?? 0)));
$print = ($_GET['print'] ?? '') === '1';
try {
    if (!$registration) throw new UserError('Registration not found.');
    if (!PsoolaTickets::canView($user, $registration)) throw new UserError('Your account cannot view this guest\'s PSOOLA ticket.');
    $pdf = Badges::pdf($registration, PsoolaTickets::codes($user, (int) $registration['id']));
} catch (UserError $error) {
    Page::flash('error', $error->key);
    Page::redirect('tickets.php');
} catch (Throwable) {
    Page::flash('error', 'The badge could not be made: PSOOLA did not answer. Try again in a moment.');
    Page::redirect('tickets.php');
}
Audit::log((int) $user['id'], $print ? 'badge.print' : 'badge.download', 'registration', (int) $registration['id']);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($print ? 'inline' : 'attachment') . '; filename="ismile-badge-' . $registration['ref'] . '.pdf"');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
echo $pdf;
