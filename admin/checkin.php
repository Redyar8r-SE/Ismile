<?php
// Registration workspace: Attended, Guest list and Report. PSOOLA scans the
// tickets at the door; its check-ins are copied in every 5 minutes (and with
// "Refresh from Psoola"). Guests with a free iSmile ticket, which PSOOLA's
// gate cannot read, are marked attended here by staff who checked them.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
use Ismile\App;
use Ismile\Attendance;
use Ismile\Db;
use Ismile\Payments\PsoolaCheckIns;
use Ismile\UserError;

$user = Page::guard('checkin');
$day = App::isLive() ? Attendance::currentDay() : (in_array((int)($_GET['day'] ?? 1), [1, 2], true) ? (int)($_GET['day'] ?? 1) : 1);
$tab = CheckinWorkspace::pick(Page::query('tab'));
$back = CheckinWorkspace::url($tab, $day);

Page::action(static function () use ($user): string {
    if (($_POST['do'] ?? '') === 'psoola_sync') {
        if (!PsoolaCheckIns::active()) throw new UserError('This site does not sell through Psoola, so there is nothing to copy.');
        try {
            $counts = PsoolaCheckIns::sync();
        } catch (\Ismile\Payments\GatewayNotReady $error) {
            App::log('error', 'Psoola check-ins could not be copied', ['error' => $error->getMessage()]);
            throw new UserError('Psoola could not be reached. Try again in a minute; the timed job also copies the scans every 5 minutes.');
        }
        $count = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
        return 'Psoola check-ins copied: ' . $count($counts['seen'], 'scan', 'scans') . ' read, ' . $count($counts['added'], 'new arrival', 'new arrivals') . ' recorded'
            . ($counts['unmatched'] ? ', ' . $count($counts['unmatched'], 'scan', 'scans') . ' not found in our registrations' : '')
            . ($counts['outside_event_days'] ? ', ' . $count($counts['outside_event_days'], 'scan', 'scans') . ' outside the event days' : '') . '.';
    }
    // A free iSmile ticket, confirmed by the staff member in front of the guest.
    $ticket = Db::one('SELECT * FROM tickets WHERE id = ?', [(int) ($_POST['ticket_id'] ?? 0)]);
    if ($ticket === null || $ticket['source'] === 'payment') {
        throw new UserError('Psoola scans paid tickets at the door. Only free tickets are marked attended here.');
    }
    $result = Attendance::checkInManually((int) $ticket['id'], (int) ($_POST['ticket_version'] ?? 0), (int) ($_POST['day'] ?? 0), $user, ($_POST['identity_verified'] ?? '') === '1');
    if ($result['duplicate']) throw new UserError('Already marked attended on Day ' . $result['day'] . ' at ' . date('H:i', strtotime($result['arrival']['checked_in_at'])) . '.');
    return 'Marked attended on Day ' . $result['day'] . ': ' . $result['ticket']['ticket_no'] . '. Certificate ready.';
}, $back);

require __DIR__ . '/_checkin_views.php';
