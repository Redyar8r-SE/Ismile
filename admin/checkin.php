<?php
// Check-in at the door. Phone-friendly: scan the QR with the camera (where the
// browser can), type the ticket number, or search a name. Admission requires
// a signed QR or staff-verified lookup, once on each of the two event days.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
use Ismile\Admin\GuestLookup;
use Ismile\App;
use Ismile\Attendance;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;

$user = Page::guard('checkin');
$showPhones = $user['role'] !== 'checkin';   // door staff see only the last four digits
$day = App::isLive() ? Attendance::currentDay() : (in_array((int)($_GET['day'] ?? 1), [1, 2], true) ? (int)($_GET['day'] ?? 1) : 1);
$tab = CheckinWorkspace::pick(Page::query('tab'));
if (in_array($tab, ['attended', 'guests', 'report'], true)) {
    require __DIR__ . '/_checkin_views.php';
    exit;
}

Page::action(static function () use ($user): string {
    $requestedDay = (int) ($_POST['day'] ?? 0);
    $method = (string)($_POST['method'] ?? 'signed_qr');
    $result = match ($method) {
        'signed_qr' => Attendance::checkIn((string)($_POST['qr'] ?? ''), $requestedDay, $user),
        'manual_lookup' => Attendance::checkInManually((int)($_POST['ticket_id'] ?? 0), (int)($_POST['ticket_version'] ?? 0), $requestedDay, $user, ($_POST['identity_verified'] ?? '') === '1'),
        default => throw new UserError('Choose QR check-in or a verified guest lookup.'),
    };
    $requestedDay = $result['day'];
    if ($result['duplicate']) throw new UserError('Entry blocked: already checked in on Day ' . $requestedDay . ' at ' . date('H:i', strtotime($result['arrival']['checked_in_at'])) . '.');
    return 'Checked in on Day ' . $requestedDay . ': ' . $result['ticket']['ticket_no'] . '. Certificate ready.';
}, 'checkin.php?day=' . ($day ?? 0));

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
            $results = Db::all('SELECT r.*, t.id AS ticket_id, t.version AS ticket_version, t.ticket_no, t.checked_in_at, t.cancelled_at FROM tickets t JOIN registrations r ON r.id = t.registration_id WHERE t.id = ?', [$scan['ticket']['id']]);
        }
    } else {
        [$where,$params] = GuestLookup::where($q);
        $results = Db::all(
            "SELECT r.*, t.id AS ticket_id, t.version AS ticket_version, t.ticket_no, t.checked_in_at, t.cancelled_at FROM registrations r LEFT JOIN tickets t ON t.registration_id = r.id
             WHERE $where ORDER BY r.first_name LIMIT 20",
            $params
        );
    }
}
$arrived = (int) Db::value('SELECT COUNT(*) FROM ticket_attendance a JOIN tickets t ON t.id=a.ticket_id WHERE a.event_day=? AND t.cancelled_at IS NULL', [$day ?? 0]);
$tickets = (int) Db::value('SELECT COUNT(*) FROM tickets WHERE cancelled_at IS NULL');
$arrivalPercent = $tickets > 0 ? min(100,(int)round($arrived/$tickets*100)) : 0;
$recentArrivals = Db::all('SELECT r.first_name, r.father_name, r.grandfather_name, a.checked_in_at FROM ticket_attendance a JOIN tickets t ON t.id=a.ticket_id JOIN registrations r ON r.id=t.registration_id WHERE a.event_day=? AND t.cancelled_at IS NULL ORDER BY a.checked_in_at DESC LIMIT 5', [$day ?? 0]);

Page::top('Registration · Check-in', 'checkin');
echo CheckinWorkspace::navigation('checkin', $user, $day);
$e = [Page::class, 'e'];
?>
<section class="card day-checkin"<?= App::isLive() ? ' data-checkin-rollover-ms="' . max(1, (strtotime('tomorrow midnight') - time()) * 1000) . '"' : '' ?>><b><?= $day ? 'Day ' . $day . ' check-in · ' . $e(\Ismile\Settings::get('event_day' . $day)) : 'Check-in opens on the event dates' ?></b><p>One QR. One admission each day. Same-day repeat entry is blocked. At 12:00 AM (Iraq time) on Day 2, the same QR becomes valid once again. Attendance creates the guest’s certificate.</p>
<?php if (!App::isLive()): ?><p class="muted small">Test rehearsal — live check-in follows the actual event date.</p><a class="btn <?= $day===1?'':'ghost' ?>" href="?day=1">Day 1</a> <a class="btn <?= $day===2?'':'ghost' ?>" href="?day=2">Day 2</a><?php endif; ?></section>
<section class="arrival-banner"><div><span class="welcome-label">THE ISMILE WELCOME DESK</span><h2>A warm welcome. A smooth arrival.</h2><p>Scan a ticket or find a registered guest to welcome them in.</p></div><div class="arrival-numbers"><div><b><?= $arrived ?></b><span>Checked in</span></div><div><b><?= max(0,$tickets-$arrived) ?></b><span>Still to arrive</span></div><div><b><?= $tickets ?></b><span>Valid tickets</span></div></div><div class="arrival-banner-progress"><span><?= $arrivalPercent ?>% of ticket holders have arrived</span><div class="track"><i class="teal" style="width:<?= $arrivalPercent ?>%"></i></div></div></section>
<div class="checkin-workspace"><div class="checkin-main">
  <section class="card scanner-panel">
  <?= Page::panelHeading('Find a guest', 'Scan a QR or search by name, phone, reference or ticket number.', 'checkin') ?>
  <div class="scan-illustration" aria-hidden="true"><span><?= Page::navIcon('checkin') ?></span></div>
  <form method="get" class="scan-form" id="scanForm" data-instant-search data-search-regions="checkin-results">
    <input type="hidden" name="day" value="<?= $day ?? 0 ?>">
    <label class="scan-input-label">Name, phone, reference or ticket number<input type="search" name="q" id="scanInput" value="<?= $e($q) ?>" placeholder="Guest name, 0750…, reference or ticket" autocomplete="off" autofocus></label>
    <button class="btn big">Find</button>
    <button type="button" class="btn big green" id="scanCamera"><?= Page::navIcon('camera') ?> Scan QR</button>
  </form>
  <video id="scanVideo" playsinline muted hidden></video>
  <button type="button" class="btn ghost" id="scanStop" hidden>Stop camera</button>
  <p id="scanStatus" class="muted small" role="status" aria-live="polite">Scan the QR from the guest’s email. Camera scanning requires HTTPS and camera permission.</p>
  <noscript><p class="flash err">Enable JavaScript to scan QR codes. You can also find the guest by name, phone, reference or ticket number.</p></noscript>
  <p class="scanner-note"><?= Page::navIcon('ticket') ?> Always check the guest’s name before confirming their arrival.</p>
  </section>

  <div data-search-region="checkin-results">
  <?php if ($scanNote): ?><div class="flash err big"><?= $e($scanNote) ?></div><?php endif; ?>

  <?php foreach ($results as $row):
      $hasTicket = $row['ticket_id'] && $row['cancelled_at'] === null && in_array($row['status'], ['paid', 'complimentary'], true);
      $row['checked_in_at'] = $row['ticket_id'] ? Db::value('SELECT checked_in_at FROM ticket_attendance WHERE ticket_id=? AND event_day=?', [$row['ticket_id'], $day ?? 0]) : null;
      $bookings = Db::all('SELECT workshop_id, payment_status FROM workshop_bookings WHERE registration_id = ? AND removed_at IS NULL', [$row['id']]); ?>
  <div class="card person <?= !$hasTicket ? 'bad' : ($row['checked_in_at'] ? 'warn' : 'good') ?>">
    <h2><?= $e(Registrations::fullName($row)) ?></h2>
    <p><code><?= $e($row['ref']) ?></code> · <?= Page::pill($row['status']) ?> <?= $e($row['ticket_type']) ?> · <code><?= $e($row['ticket_no'] ?? 'no ticket') ?></code>
      <?= $row['lunch_day1'] ? ' · lunch D1' : '' ?><?= $row['lunch_day2'] ? ' · lunch D2' : '' ?>
      · <span dir="ltr"><?= $e($showPhones ? $row['phone'] : '•••• ' . substr($row['phone'], -4)) ?></span></p>
    <?php foreach ($bookings as $booking): $workshop = SiteData::workshop($booking['workshop_id']); ?>
      <p>Workshop: <b><?= $e($workshop ? SiteData::workshopName($workshop) : $booking['workshop_id']) ?></b> <?= Page::pill($booking['payment_status']) ?><?= $booking['payment_status'] === 'unpaid' ? ' <b>collect payment</b>' : '' ?></p>
    <?php endforeach; ?>
    <?php if (!$hasTicket): ?>
      <p class="big-note">✗ No valid ticket. Send to the registration desk.</p>
    <?php elseif ($row['checked_in_at']): ?>
      <p class="big-note">Entry blocked: already checked in on Day <?= $day ?> at <?= date('H:i', strtotime($row['checked_in_at'])) ?>.</p>
    <?php else: ?>
      <?php if ($day && isset($scan['ticket']) && !isset($scan['error']) && str_starts_with($q, 'ISM26:')): ?>
      <form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="qr" value="<?= $e($q) ?>"><input type="hidden" name="day" value="<?= $day ?>"><button class="btn big green"><?= Page::navIcon('check') ?><span>Admit guest · Day <?= $day ?></span></button></form>
      <?php elseif ($day): ?>
      <form method="post" class="manual-checkin-form"><?= Page::csrfField() ?><input type="hidden" name="method" value="manual_lookup"><input type="hidden" name="ticket_id" value="<?= (int)$row['ticket_id'] ?>"><input type="hidden" name="ticket_version" value="<?= (int)$row['ticket_version'] ?>"><input type="hidden" name="day" value="<?= $day ?>">
        <p class="muted small">No QR available? Confirm the name and phone or reference with the guest before admission.</p>
        <label class="manual-checkin-verification"><input type="checkbox" name="identity_verified" value="1" required> I confirmed this is the guest in front of me.</label>
        <button class="btn big green"><?= Page::navIcon('check') ?><span>Admit without QR · Day <?= $day ?></span></button>
      </form>
      <?php else: ?><p class="big-note">Admissions are available only on the event dates.</p><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($q !== '' && !$results && !$scanNote): ?><div class="flash err">Nobody found for "<?= $e($q) ?>".</div><?php endif; ?>
  <?php if ($q===''): ?><div class="card checkin-ready"><?= Page::emptyState('Ready for your next guest', 'Type a name, phone or reference, or scan a ticket. Matches appear as you type.', 'users') ?></div><?php endif; ?>
  <?php if(count($results)===20): ?><p class="muted small">Showing the first 20 matches. Keep typing to narrow the list.</p><?php endif; ?>
  </div>
</div><aside class="checkin-side" aria-label="Check-in help">
  <section class="card"><?= Page::panelHeading('Three simple steps', 'A consistent welcome for every guest.', 'checkin') ?><ol class="welcome-steps"><li><b>Find their ticket</b><span>Scan their QR or search by name, phone or reference.</span></li><li><b>Confirm their details</b><span>Check the name and make sure the ticket is valid.</span></li><li><b>Welcome them in</b><span>Confirm admission for this day. A repeat admission is blocked.</span></li></ol></section>
  <section class="card"><?= Page::panelHeading('Recent arrivals', 'The latest guests welcomed by your team.', 'users') ?><?php if (!$recentArrivals): ?><?= Page::emptyState('The welcome desk is ready', 'Your first checked-in guests will appear here.', 'checkin') ?><?php else: ?><div class="recent-arrivals"><?php foreach ($recentArrivals as $guest): ?><div class="arrival-row"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($guest['first_name'],0,1))) ?></span><div><b><?= $e(Registrations::fullName($guest)) ?></b><small><?= Page::when($guest['checked_in_at']) ?></small></div><span class="arrival-check" aria-hidden="true"><?= Page::navIcon('check') ?></span></div><?php endforeach; ?></div><?php endif; ?></section>
  <div class="offline-note"><?= Page::navIcon('lists') ?><div><b>Keep the ticket QR available</b><p>Ask guests to keep their PDF ticket on their phone. Guests can check in without a QR or internet on their phone. The check-in desk needs a connection so every door sees the same admission status.</p></div></div>
</aside></div>
<?php Page::bottom();
