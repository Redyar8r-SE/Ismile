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

Page::top('Check-in', 'checkin');
$e = [Page::class, 'e'];
?>
<div class="checkin">
  <p class="muted"><b><?= $arrived ?></b> of <b><?= $tickets ?></b> tickets checked in.</p>
  <form method="get" class="scan-form" id="scanForm">
    <input type="search" name="q" id="scanInput" value="<?= $e($q) ?>" placeholder="Scan, T26-…, or a name" autocomplete="off" autofocus>
    <button class="btn big">Find</button>
    <button type="button" class="btn big green" id="scanCamera" hidden>📷 Scan QR</button>
  </form>
  <video id="scanVideo" playsinline muted hidden></video>

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
      <form method="post"><?= Page::csrfField() ?><input type="hidden" name="ticket" value="<?= (int) $row['ticket_id'] ?>"><button class="btn big green">✓ Check in</button></form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($q !== '' && !$results && !$scanNote): ?><div class="flash err">Nobody found for "<?= $e($q) ?>".</div><?php endif; ?>
  <p class="muted small">No internet at the venue? Use the printed list (Registrations → Export, the night before) and type the arrivals in afterwards.</p>
</div>
<?php Page::bottom();
