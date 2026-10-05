<?php
// Dashboard: real activity, guest mix, arrival progress and the team's next tasks.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CommunicationQuery;
use Ismile\App;
use Ismile\Backup;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;

$user = Page::guard('dashboard');

$count = static fn (string $sql, array $params = []): int => (int) Db::value($sql, $params);
$paid = $count("SELECT COUNT(*) FROM registrations WHERE status = 'paid'");
$comp = $count("SELECT COUNT(*) FROM registrations WHERE status = 'complimentary'");
$cancelled = $count("SELECT COUNT(*) FROM registrations WHERE status = 'cancelled'");
// Forms waiting for payment are NOT registrations; shown only as a number.
$payingNow = $count("SELECT COUNT(*) FROM checkouts WHERE status = 'open' AND expires_at > ?", [\Ismile\App::now()]);
$profPaid = $count("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','complimentary') AND ticket_type = 'professional'");
$studPaid = $count("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','complimentary') AND ticket_type = 'student'");
$money = (int) Db::value("SELECT COALESCE(SUM(amount_confirmed), 0) FROM payments WHERE status = 'paid'");
[$emailScope,$emailParams] = CommunicationQuery::where($user);
$emailsSent = $count("SELECT COUNT(*) FROM emails e WHERE $emailScope AND (" . CommunicationQuery::STATE_SQL . ") = 'sent'",$emailParams);
$emailsFailed = $count("SELECT COUNT(*) FROM emails e WHERE $emailScope AND e.status = 'failed'",$emailParams);
$openSponsors = $count("SELECT COUNT(*) FROM sponsor_requests WHERE status IN ('new','contacted','agreed','paid')");
$sponsorMoney = $count("SELECT COALESCE(SUM(amount_paid), 0) FROM sponsor_requests WHERE status IN ('paid','confirmed')");
$callsDue = \Ismile\Sponsors::callsDue();
$currency = SiteData::prices()['currency'];

// ---- Needs attention ----
$attention = [];
$flagged = $count("SELECT COUNT(*) FROM payments WHERE status IN ('mismatch','duplicate')");
if ($flagged) {
    $attention[] = ["$flagged payment(s) need Finance: wrong amount or paid twice. No ticket was made for them.", 'payments.php?status=flagged'];
}
if ($callsDue && \Ismile\Auth::can($user, 'sponsors')) {
    $attention[] = ["$callsDue sponsor / exhibition call(s) are due. Call them and save the call.", 'sponsors.php?due=1'];
}
if ($emailsFailed) {
    $attention[] = ["$emailsFailed email(s) failed. Review their delivery details in the Communication center.", 'communications.php?status=failed'];
}
$badHooks = $count("SELECT COUNT(*) FROM webhook_log WHERE outcome LIKE 'REJECTED%' AND received_at > ?", [date('Y-m-d H:i:s', time() - 86400)]);
if ($badHooks) {
    $attention[] = ["$badHooks payment message(s) with a bad signature in the last 24 hours (possible fakes; nothing was changed).", 'payments.php#webhooks'];
}
$lastBackup = Backup::latestTime();
if ($paid + $comp > 0 && ($lastBackup === null || $lastBackup < time() - 36 * 3600)) {
    $attention[] = ['No database backup in the last 36 hours. Check your backups.', $user['role'] === 'owner' ? 'backups.php' : null];
}
$prices = SiteData::prices();
if ($prices['professional'] <= 0 || $prices['student'] <= 0) {
    $attention[] = ['Ticket prices are not set. Nobody can pay until they are (Site content → Ticket prices).', Page::contentUrl()];
}
if (Settings::bool('email_test_mode')) {
    $attention[] = ['Email test mode is ON: every email goes to the test address, not to the people.', 'settings.php'];
}
if (App::isLive() && App::config('payments.gateway') !== 'psoola') {
    $attention[] = ['The live site is not connected to Psoola (payments.gateway in config.php).', null];
}

$days = (int) Page::query('days');
$days = in_array($days, [7, 14, 30], true) ? $days : 14;
$since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$dailyCounts = array_column(Db::all("SELECT DATE(created_at) AS day, COUNT(*) AS n FROM registrations WHERE status IN ('paid','complimentary') AND created_at >= ? GROUP BY DATE(created_at)", [$since . ' 00:00:00']), 'n', 'day');
$trend = [];
for ($i = 0; $i < $days; $i++) {
    $day = date('Y-m-d', strtotime($since . ' +' . $i . ' days'));
    $trend[$day] = (int) ($dailyCounts[$day] ?? 0);
}
$trendMax = max(1, ...array_values($trend));
$registered = $paid + $comp;
$arrived = $count('SELECT COUNT(*) FROM tickets WHERE checked_in_at IS NOT NULL AND cancelled_at IS NULL');
$validTickets = $count('SELECT COUNT(*) FROM tickets WHERE cancelled_at IS NULL');
$arrivalPercent = $validTickets > 0 ? min(100, (int) round($arrived / $validTickets * 100)) : 0;
$booked = $count('SELECT COUNT(*) FROM workshop_bookings WHERE removed_at IS NULL');
$workshopCards = [];
foreach (SiteData::workshops() as $workshop) {
    $used = \Ismile\Office::bookedCount($workshop['id']);
    $workshopCards[] = [$workshop, $used, (int) ($workshop['totalSeats'] ?? 0)];
}
$professionalPercent = $profPaid + $studPaid > 0 ? (int) round($profPaid / ($profPaid + $studPaid) * 100) : 0;
$recent = Db::all("SELECT id, ref, first_name, father_name, grandfather_name, ticket_type, status, created_at FROM registrations ORDER BY id DESC LIMIT 6");
$firstName = explode(' ', trim((string) $user['name']))[0];
$e = [Page::class, 'e'];
Page::top('Dashboard', 'index');
?>
<section class="dashboard-hero">
  <div class="hero-copy"><span class="welcome-label">A GREAT EVENT STARTS HERE</span><h2>Welcome back, <?= $e($firstName) ?>.</h2><p>A little clarity for your day. Every guest, every detail, one beautiful workspace.</p>
    <div class="hero-actions">
      <?php if (\Ismile\Auth::can($user, 'edit')): ?><a class="btn" href="registration.php?new=1">+ Register a caller</a><?php endif; ?>
      <a class="btn ghost" href="registrations.php">View registrations <?= Page::navIcon('arrow') ?></a>
    </div>
  </div>
  <div class="hero-event"><span class="hero-orbit" aria-hidden="true"><?= Page::navIcon('ticket') ?></span><span class="hero-date"><?= Page::navIcon('calendar') ?> <?= $e(date('j F Y')) ?></span><span class="hero-registration"><?= Page::pill(Registrations::isOpen() ? 'open' : 'closed') ?> Registration</span></div>
</section>
<?= Page::stats([
    ['Registered guests', number_format($registered), $comp ? "$paid paid · $comp complimentary" : 'Paid and complimentary tickets', 'users', 'teal'],
    ['Ticket revenue', Page::money($money, $currency), 'Confirmed ticket payments', 'payments', 'blue'],
    ['Workshop bookings', number_format($booked), count($workshopCards) . ' active workshops', 'workshops', 'violet'],
    ['Guests checked in', number_format($arrived), $validTickets . ' valid tickets · ' . $arrivalPercent . '% arrived', 'checkin', 'green'],
]) ?>
<div class="dashboard-columns">
  <div class="dashboard-main">
    <section class="card trend-panel">
      <div class="panel-top"><?= Page::panelHeading('Registration activity', 'Paid and complimentary guests by registration date.', 'registrations') ?>
        <nav class="period-switch" aria-label="Registration chart period"><?php foreach ([7,14,30] as $period): ?><a href="index.php?days=<?= $period ?>"<?= $days === $period ? ' class="on" aria-current="true"' : '' ?>><?= $period ?> days</a><?php endforeach; ?></nav>
      </div>
      <div class="chart-summary"><strong><?= number_format(array_sum($trend)) ?></strong><span>registrations in the last <?= $days ?> days</span><span class="chart-legend"><i></i> Registered guests</span></div>
      <figure class="trend-figure">
        <div class="trend-plot" aria-hidden="true"><div class="chart-axis"><span><?= $trendMax ?></span><span><?= $trendMax > 1 ? (int) ceil($trendMax / 2) : '' ?></span><span>0</span></div><div class="chart-bars" style="--days:<?= $days ?>">
          <?php $i = 0; foreach ($trend as $day => $n): ?><div class="chart-day"><div class="chart-column"><span class="chart-bar<?= $n === 0 ? ' zero' : '' ?>" style="height:<?= (int) round($n / $trendMax * 100) ?>%" title="<?= $e(date('j M', strtotime($day)) . ': ' . $n . ' registrations') ?>"></span></div><span class="chart-label<?= $i++ % ($days > 14 ? 5 : ($days > 7 ? 2 : 1)) !== 0 ? ' quiet-label' : '' ?>"><?= $e(date('j', strtotime($day))) ?></span></div><?php endforeach; ?>
        </div></div>
        <figcaption><?= $e(date('j M', strtotime($since))) ?> – <?= $e(date('j M')) ?><?= array_sum($trend) === 0 ? ' · No registrations in this period yet.' : '' ?></figcaption>
        <table class="sr-only"><caption>Daily registrations, last <?= $days ?> days</caption><tr><th>Date</th><th>Registered guests</th></tr><?php foreach ($trend as $day => $n): ?><tr><td><?= $e($day) ?></td><td><?= $n ?></td></tr><?php endforeach; ?></table>
      </figure>
    </section>
    <section class="card">
      <div class="panel-top"><?= Page::panelHeading('Latest registrations', 'The latest people added to your database.', 'ticket') ?><a class="text-action" href="registrations.php">View all <?= Page::navIcon('arrow') ?></a></div>
      <?php if ($recent): ?><div class="table-wrap"><table><tr><th>Guest</th><th>Ticket</th><th>Status</th><th>Registered</th></tr><?php foreach ($recent as $row): ?><tr><td><div class="guest-cell"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($row['first_name'],0,1))) ?></span><div><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a><small><?= $e($row['ref']) ?></small></div></div></td><td><?= $e(ucfirst($row['ticket_type'])) ?></td><td><?= Page::pill($row['status']) ?></td><td><?= Page::when($row['created_at']) ?></td></tr><?php endforeach; ?></table></div>
      <?php else: ?><?= Page::emptyState('Your guest list starts here', 'New registrations will appear here as soon as a ticket is issued.', 'registrations') ?><?php if (\Ismile\Auth::can($user, 'edit')): ?><div class="empty-action"><a class="btn ghost" href="registration.php?new=1">Register your first caller</a></div><?php endif; ?><?php endif; ?>
    </section>
    <section class="card">
      <?= Page::panelHeading('Your next task', 'A shortcut to the work that matters.', 'index') ?>
      <div class="shortcut-grid"><?php foreach ([
          ['registrations', 'Guest directory', 'Find a person or their ticket', 'registrations.php', 'registrations'],
          ['lists', 'Customer lists', 'Prepare lists, print and export', 'lists.php', 'registrations'],
          ['payments', 'Payment review', 'Follow up and reconcile', 'payments.php', 'payments'],
          ['workshops', 'Workshop desk', 'Book guests and manage seats', 'workshops.php', 'workshops'],
          ['sponsors', 'Partnerships', 'Sponsors, booths and calls', 'sponsors.php', 'sponsors'],
          ['checkin', 'Welcome desk', 'Scan a ticket or find a guest', 'checkin.php', 'checkin'],
      ] as [$icon,$label,$note,$link,$area]): if (!\Ismile\Auth::can($user,$area)) { continue; } ?><a class="shortcut" href="<?= $e($link) ?>"><span class="shortcut-top"><span class="shortcut-icon"><?= Page::navIcon($icon) ?></span><span class="shortcut-arrow"><?= Page::navIcon('arrow') ?></span></span><strong><?= $e($label) ?></strong><small><?= $e($note) ?></small></a><?php endforeach; ?></div>
    </section>
  </div>
  <aside class="dashboard-side" aria-label="Event overview">
    <section class="card guest-mix">
      <?= Page::panelHeading('Your guests', 'A snapshot of your ticket holders.', 'users') ?>
      <div class="ticket-donut<?= $registered === 0 ? ' is-empty' : '' ?>" style="--share:<?= $professionalPercent ?>%" role="img" aria-label="<?= $e($profPaid . ' professionals and ' . $studPaid . ' students') ?>"><div><strong><?= number_format($registered) ?></strong><span>registered</span></div></div>
      <div class="mix-legend"><span><i class="dot teal"></i> Professionals <b><?= number_format($profPaid) ?></b></span><span><i class="dot violet"></i> Students <b><?= number_format($studPaid) ?></b></span></div>
      <div class="arrival-summary"><span>Arrival progress <b><?= $arrivalPercent ?>%</b></span><div class="track"><i class="teal" style="width:<?= $arrivalPercent ?>%"></i></div><small><?= $arrived ?> of <?= $validTickets ?> valid tickets checked in</small></div>
    </section>
    <section class="card priority-panel">
      <div class="panel-top"><?= Page::panelHeading('Needs attention', 'Keep the day moving smoothly.', 'clock') ?><span class="pill <?= $attention ? 'gold' : 'green' ?>"><?= count($attention) ?></span></div>
      <?php if (!$attention): ?><?= Page::emptyState('You are all caught up', 'No outstanding issues are flagged right now.', 'checkin') ?><?php else: ?><ul class="priority-list"><?php foreach ($attention as [$text,$link]):
          $area = $link === null ? null : match (strtok($link, '.?#')) { 'payments' => 'payments', 'registrations' => 'registrations', 'sponsors' => 'sponsors', '../admin', 'content' => 'content', 'communications' => 'communications', 'backups' => 'owner', default => 'owner' };
          $canOpen = $area !== null && \Ismile\Auth::can($user,$area); ?><li><span class="priority-dot" aria-hidden="true"></span><div><p><?= $e($text) ?></p><?php if ($canOpen): ?><a class="text-action" href="<?= $e($link) ?>">Review <?= Page::navIcon('arrow') ?></a><?php endif; ?></div></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <section class="card lunch-panel">
      <?= Page::panelHeading('Lunch planning', 'Bookings against the caterer’s limits.', 'lunch') ?>
      <?php foreach ([1,2] as $day): $used = Registrations::lunchTaken($day); $limit = Settings::int('lunch_capacity_day' . $day); $percent = $limit > 0 ? min(100,(int) round($used/$limit*100)) : 0; ?><div class="lunch-day"><span class="day-marker">D<?= $day ?></span><div><div class="meter-top"><b>Day <?= $day ?></b><span><?= $used ?> / <?= $limit > 0 ? $limit : 'no limit' ?></span></div><div class="track"><i class="gold" style="width:<?= $percent ?>%"></i></div></div></div><?php endforeach; ?>
    </section>
    <?php if (\Ismile\Auth::can($user,'workshops')): ?><section class="card workshop-preview"><?= Page::panelHeading('Workshop availability', 'Keep an eye on the seats.', 'workshops') ?>
      <?php if (!$workshopCards): ?><?= Page::emptyState('Workshops coming soon', 'Active workshops will appear here.', 'workshops') ?><?php endif; ?>
      <?php foreach ($workshopCards as [$workshop,$used,$capacity]): ?><a class="availability-row" href="workshops.php?id=<?= rawurlencode($workshop['id']) ?>#people"><span><b><?= $e(SiteData::workshopName($workshop)) ?></b><small><?= max(0,$capacity-$used) ?> seats left</small></span><span class="pill <?= $used >= $capacity ? 'red' : 'green' ?>"><?= $used ?> / <?= $capacity ?></span></a><?php endforeach; ?>
    </section><?php endif; ?>
  </aside>
</div>
<?= Page::stats([
    ['Paying right now', number_format($payingNow), 'Open forms · ticket not issued yet', 'clock', 'gold'],
    ['Email delivery', "$emailsSent / $emailsFailed", 'Sent / failed emails', 'mail', $emailsFailed ? 'red' : 'green'],
    ['Open partnerships', number_format($openSponsors), Page::money($sponsorMoney,$currency) . ' confirmed', 'sponsors', 'blue'],
    ['Cancelled tickets', number_format($cancelled), 'Cancelled registrations', 'close', 'violet'],
]) ?>
<?php Page::bottom();
