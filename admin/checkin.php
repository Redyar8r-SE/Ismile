<?php
// Check-in at the door. Phone-friendly: scan the QR with the camera (where the
// browser can), type the ticket number, or search a name. A second scan shows
// "already checked in at 09:12" and the door staff decide.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;

$user = Page::guard('checkin');
$showPhones = $user['role'] !== 'checkin';   // check-in staff do not see phone numbers

Page::action(static function () use ($user): string {
    $ticket = Db::one('SELECT * FROM tickets WHERE id = ?', [(int) ($_POST['ticket'] ?? 0)]);
    if ($ticket === null || $ticket['cancelled_at'] !== null) {
        throw new UserError('This ticket is cancelled or unknown. Send the person to the registration desk.');
    }
    if ($ticket['checked_in_at'] !== null) {
        return 'Already checked in at ' . date('H:i', strtotime($ticket['checked_in_at'])) . '.';
    }
    Db::run('UPDATE tickets SET checked_in_at = ?, checked_in_by = ? WHERE id = ? AND checked_in_at IS NULL', [App::now(), $user['id'], $ticket['id']]);
    Audit::log((int) $user['id'], 'checkin', 'registration', (int) $ticket['registration_id'], ['ticket' => $ticket['ticket_no']]);
    return '✓ Checked in: ' . $ticket['ticket_no'];
}, 'checkin.php');

$q = Page::query('q', 200);
$results = [];
$scanNote = null;
if ($q !== '') {
    $scan = Tickets::readScan($q);
    if ($scan !== null) {
        if (isset($scan['error'])) {
            $scanNote = match ($scan['error']) {
                'forged'      => 'This QR code is NOT a genuine iSmile ticket.',
                'old_version' => 'This is an OLD ticket (the name was changed and a new ticket was sent). Ask for the newest email, or search the name.',
                default       => 'No ticket with this number.',
            };
        }
        if (isset($scan['ticket']) && !isset($scan['error'])) {
            $results = Db::all('SELECT r.*, t.id AS ticket_id, t.ticket_no, t.checked_in_at, t.cancelled_at FROM tickets t JOIN registrations r ON r.id = t.registration_id WHERE t.id = ?', [$scan['ticket']['id']]);
        }
    } else {
        $phone = \Ismile\Validate::phone($q);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $results = Db::all(
            "SELECT r.*, t.id AS ticket_id, t.ticket_no, t.checked_in_at, t.cancelled_at FROM registrations r LEFT JOIN tickets t ON t.registration_id = r.id
             WHERE CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ? OR r.ref = ?" . ($phone ? ' OR r.phone = ?' : '') . ' ORDER BY r.first_name LIMIT 20',
            $phone ? [$like, strtoupper($q), $phone] : [$like, strtoupper($q)]
        );
    }
}
$arrived = (int) Db::value('SELECT COUNT(*) FROM tickets WHERE checked_in_at IS NOT NULL AND cancelled_at IS NULL');
$tickets = (int) Db::value('SELECT COUNT(*) FROM tickets WHERE cancelled_at IS NULL');
$arrivalPercent = $tickets > 0 ? min(100,(int)round($arrived/$tickets*100)) : 0;
$recentArrivals = Db::all('SELECT r.first_name, r.father_name, r.grandfather_name, t.checked_in_at FROM tickets t JOIN registrations r ON r.id=t.registration_id WHERE t.checked_in_at IS NOT NULL AND t.cancelled_at IS NULL ORDER BY t.checked_in_at DESC LIMIT 5');

Page::top('Check-in', 'checkin');
$e = [Page::class, 'e'];
?>
<section class="arrival-banner"><div><span class="welcome-label">THE ISMILE WELCOME DESK</span><h2>A warm welcome. A smooth arrival.</h2><p>Scan a ticket or search a guest to get them through the door.</p></div><div class="arrival-numbers"><div><b><?= $arrived ?></b><span>Checked in</span></div><div><b><?= max(0,$tickets-$arrived) ?></b><span>Still to arrive</span></div><div><b><?= $tickets ?></b><span>Valid tickets</span></div></div><div class="arrival-banner-progress"><span><?= $arrivalPercent ?>% of ticket holders have arrived</span><div class="track"><i class="teal" style="width:<?= $arrivalPercent ?>%"></i></div></div></section>
<div class="checkin-workspace"><div class="checkin-main">
  <section class="card scanner-panel">
  <?= Page::panelHeading('Find a guest', 'Use a ticket number, guest name or a ticket QR code.', 'checkin') ?>
  <div class="scan-illustration" aria-hidden="true"><span><?= Page::navIcon('checkin') ?></span></div>
  <form method="get" class="scan-form" id="scanForm">
    <label class="scan-input-label">Ticket number or guest name<input type="search" name="q" id="scanInput" value="<?= $e($q) ?>" placeholder="T26-… or a guest’s name" autocomplete="off" autofocus></label>
    <button class="btn big">Find</button>
    <button type="button" class="btn big green" id="scanCamera" hidden><?= Page::navIcon('camera') ?> Scan QR</button>
  </form>
  <video id="scanVideo" playsinline muted hidden></video>
  <p class="scanner-note"><?= Page::navIcon('ticket') ?> Always check the guest’s name before confirming their arrival.</p>
  </section>

  <?php if ($scanNote): ?><div class="flash err big"><?= $e($scanNote) ?></div><?php endif; ?>

  <?php foreach ($results as $row):
      $hasTicket = $row['ticket_id'] && $row['cancelled_at'] === null && in_array($row['status'], ['paid', 'complimentary'], true);
      $bookings = Db::all('SELECT workshop_id, payment_status FROM workshop_bookings WHERE registration_id = ? AND removed_at IS NULL', [$row['id']]); ?>
  <div class="card person <?= !$hasTicket ? 'bad' : ($row['checked_in_at'] ? 'warn' : 'good') ?>">
    <h2><?= $e(Registrations::fullName($row)) ?></h2>
    <p><?= Page::pill($row['status']) ?> <?= $e($row['ticket_type']) ?> · <code><?= $e($row['ticket_no'] ?? 'no ticket') ?></code>
      <?= $row['lunch_day1'] ? ' · lunch D1' : '' ?><?= $row['lunch_day2'] ? ' · lunch D2' : '' ?>
      <?= $showPhones ? ' · <span dir="ltr">' . $e($row['phone']) . '</span>' : '' ?></p>
    <?php foreach ($bookings as $booking): $workshop = SiteData::workshop($booking['workshop_id']); ?>
      <p>Workshop: <b><?= $e($workshop ? SiteData::workshopName($workshop) : $booking['workshop_id']) ?></b> <?= Page::pill($booking['payment_status']) ?><?= $booking['payment_status'] === 'unpaid' ? ' <b>collect payment</b>' : '' ?></p>
    <?php endforeach; ?>
    <?php if (!$hasTicket): ?>
      <p class="big-note">✗ No valid ticket. Send to the registration desk.</p>
    <?php elseif ($row['checked_in_at']): ?>
      <p class="big-note">Already checked in at <?= date('H:i', strtotime($row['checked_in_at'])) ?>.</p>
    <?php else: ?>
      <form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="ticket" value="<?= (int) $row['ticket_id'] ?>"><button class="btn big green"><?= Page::navIcon('check') ?><span>Check in</span></button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($q !== '' && !$results && !$scanNote): ?><div class="flash err">Nobody found for "<?= $e($q) ?>".</div><?php endif; ?>
  <?php if ($q===''): ?><div class="card checkin-ready"><?= Page::emptyState('Ready for your next guest', 'Search or scan their ticket above. Their name and ticket status will appear here.', 'users') ?></div><?php endif; ?>
</div><aside class="checkin-side" aria-label="Check-in help">
  <section class="card"><?= Page::panelHeading('Three simple steps', 'A consistent welcome for every guest.', 'checkin') ?><ol class="welcome-steps"><li><b>Find their ticket</b><span>Scan the newest QR code or search their name.</span></li><li><b>Confirm their details</b><span>Check the name and make sure the ticket is valid.</span></li><li><b>Welcome them in</b><span>Press Check in. An earlier arrival is clearly marked.</span></li></ol></section>
  <section class="card"><?= Page::panelHeading('Recent arrivals', 'The latest guests welcomed by your team.', 'users') ?><?php if (!$recentArrivals): ?><?= Page::emptyState('The welcome desk is ready', 'Your first checked-in guests will appear here.', 'checkin') ?><?php else: ?><div class="recent-arrivals"><?php foreach ($recentArrivals as $guest): ?><div class="arrival-row"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($guest['first_name'],0,1))) ?></span><div><b><?= $e(Registrations::fullName($guest)) ?></b><small><?= Page::when($guest['checked_in_at']) ?></small></div><span class="arrival-check" aria-hidden="true"><?= Page::navIcon('check') ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
  <div class="offline-note"><?= Page::navIcon('lists') ?><div><b>Have a printed list ready</b><p>If the venue loses internet, use the exported list and record arrivals here afterwards.</p></div></div>
</aside></div>
<?php Page::bottom();
