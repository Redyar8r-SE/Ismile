<?php
// Deleted people (owner, 19 Nov 2026): everyone deleted from the database,
// who deleted them and when, and whether their PSOOLA ticket still works.
// Only the main Owner opens this page; a ticket is cancelled in PSOOLA only
// when the Owner presses that person's button. PSOOLA answers are saved, so
// the page opens fast; "Check PSOOLA now" asks again about working tickets.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Auth;
use Ismile\Erase;
use Ismile\PsoolaCancel;
use Ismile\UserError;

$user = Page::guard('owner');
$e = [Page::class, 'e'];
if (!Erase::isMainOwner($user)) {
    http_response_code(403);
    Page::top('Not allowed', 'registrations');
    echo '<div class="card"><h2>Not allowed</h2><p>Only the main Owner can open this page.</p></div>';
    Page::bottom();
    exit;
}
$show = is_string($_GET['show'] ?? null) && isset(PsoolaCancel::STATES[$_GET['show']]) ? $_GET['show'] : 'active';
$q = Page::query('q', 120);
$here = static fn (array $more = []): string => 'deleted-psoola.php?' . http_build_query(array_filter(['show' => $show, 'q' => $q] + $more, static fn ($v) => $v !== ''));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'cancel') {
    try {
        Auth::checkCsrf();
        Page::flash('ok', 'Cancelled in PSOOLA: ' . PsoolaCancel::cancelDeleted((int) ($_POST['record'] ?? 0), $user));
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (\Throwable $error) {
        App::log('error', 'Cancel deleted person in PSOOLA failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
        Page::flash('error', 'Something went wrong. Nothing more was cancelled. Try again in a minute.');
    }
    Page::redirect($here());
}

$problem = null;
$all = [];
if (!PsoolaCancel::active()) {
    $problem = 'PSOOLA is not the payment gateway on this server, so PSOOLA tickets cannot be checked.';
}
try {
    $all = PsoolaCancel::deletedPeople(500, ($_GET['fresh'] ?? '') === '1');
} catch (\Throwable $error) {
    App::log('error', 'Deleted people PSOOLA check failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
    $problem = 'PSOOLA could not be checked right now. Try "Check PSOOLA now" in a minute.';
}
$counts = array_fill_keys(array_keys(PsoolaCancel::STATES), 0);
foreach ($all as $person) { $counts[$person['state']]++; $counts['all']++; }
$people = array_values(array_filter($all, static function (array $person) use ($show, $q): bool {
    if ($show !== 'all' && $person['state'] !== $show) return false;
    if ($q === '') return true;
    $r = $person['record'];
    return str_contains(mb_strtolower($r['label'] . ' ' . $r['ref'] . ' ' . ($r['phone'] ?? '') . ' ' . ($r['deleted_by_name'] ?? '')), mb_strtolower($q));
}));
$cards = [
    'active'    => ['Ticket still works', 'Deleted here, the QR still opens the gate', 'ticket', 'red'],
    'cancelled' => ['Cancelled in PSOOLA', 'QR stopped, seat back in PSOOLA', 'check', 'green'],
    'none'      => ['No PSOOLA ticket', 'Nothing to cancel in PSOOLA', 'close', 'blue'],
    'all'       => ['Everyone deleted', 'All people deleted from the database', 'users', 'teal'],
];
$state = [
    'active' => ['dp-active', 'Ticket still works'], 'cancelled' => ['dp-cancelled', 'Cancelled in PSOOLA'],
    'none' => ['dp-none', 'No PSOOLA ticket'], 'unknown' => ['dp-unknown', 'PSOOLA not checked'],
];
$orderText = static function (array $order): string {
    if ($order['shared']) return 'kept, another person uses this order';
    if ($order['error'] !== null) return $order['error'];
    $look = $order['look'];
    if (!$look || !$look['found']) return 'PSOOLA does not know it';
    return PsoolaCancel::isCancelled($look) ? 'cancelled' : 'still works';
};

Page::top('Deleted people', 'registrations', '<a class="btn ghost" href="registrations.php">Back to Registrations</a>');
?>
<div class="deleted-page" data-no-live>
  <div class="operation-summary dp-cards no-print">
    <?php foreach ($cards as $key => [$label, $note, $icon, $tone]): ?>
    <a class="metric-card <?= $tone ?><?= $show === $key ? ' on' : '' ?>" href="<?= $e('deleted-psoola.php?' . http_build_query(array_filter(['show' => $key, 'q' => $q]))) ?>"<?= $show === $key ? ' aria-current="true"' : '' ?>>
      <div class="metric-top"><span><?= $e($label) ?></span><i class="metric-icon"><?= Page::navIcon($icon) ?></i></div>
      <strong><?= (int) $counts[$key] ?></strong><small><?= $e($note) ?></small>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($counts['unknown']): ?><p class="flash warn"><?= (int) $counts['unknown'] ?> could not be checked because PSOOLA did not answer. <a href="<?= $e($here(['show' => 'unknown'])) ?>">Show them</a> or press Check PSOOLA now.</p><?php endif; ?>
  <?php if ($problem): ?><p class="flash err"><?= $e($problem) ?></p><?php endif; ?>

  <section class="card dp-panel">
    <form method="get" class="dp-toolbar">
      <input type="hidden" name="show" value="<?= $e($show) ?>">
      <label class="dp-search"><?= Page::navIcon('search') ?><input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search name, phone, reference or who deleted" aria-label="Search deleted people"></label>
      <button class="btn">Search</button>
      <?php if ($q !== ''): ?><a class="dp-clear" href="<?= $e('deleted-psoola.php?show=' . $show) ?>">Clear search</a><?php endif; ?>
      <a class="btn ghost dp-refresh" href="<?= $e($here(['fresh' => '1'])) ?>"><?= Page::navIcon('clock') ?>Check PSOOLA now</a>
    </form>
    <div class="dp-head">
      <h2><?= $e(PsoolaCancel::STATES[$show]) ?> <span class="dp-count"><?= count($people) ?></span></h2>
      <?php if ($show === 'active'): ?><p>These people are deleted from the database, but their QR still opens the gate. Press <b>Cancel in PSOOLA</b> for each one who should not come in. PSOOLA has no undo.</p><?php endif; ?>
    </div>

    <?php if (!$people): ?>
      <div class="dp-empty"><?= Page::navIcon($show === 'active' && $q === '' ? 'check' : 'search') ?>
        <b><?= $show === 'active' && $q === '' ? 'All clear' : 'Nobody here' ?></b>
        <span><?= $show === 'active' && $q === '' ? 'No deleted person has a PSOOLA ticket that still works.' : 'Nobody matches this filter or search.' ?></span></div>
    <?php else: ?>
    <ul class="dp-list">
      <?php foreach ($people as $person): $r = $person['record']; [$class, $word] = $state[$person['state']]; ?>
      <li class="dp-row <?= $class ?>">
        <span class="dp-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $r['label'], 0, 1))) ?></span>
        <div class="dp-person">
          <b><?= $e($r['label']) ?></b>
          <div class="dp-tags">
            <code><?= $e($r['ref']) ?></code>
            <?php if (($r['phone'] ?? '') !== ''): ?><span dir="ltr"><?= $e($r['phone']) ?></span><?php endif; ?>
            <?php if (($r['ticket_type'] ?? '') !== ''): ?><span class="dp-chip"><?= $e(\Ismile\Registrations::ticketName((string) $r['ticket_type'])) ?><?= $r['status'] === 'complimentary' ? ', free' : '' ?></span><?php endif; ?>
          </div>
        </div>
        <div class="dp-when"><small>Deleted</small><b><?= Page::when($r['deleted_at']) ?></b><small>by <?= $e($r['deleted_by_name'] ?? 'Unknown') ?></small></div>
        <div class="dp-state"><span class="dp-badge"><?= $e($word) ?></span>
          <?php foreach ($person['orders'] as $order): ?><small><?= $e($order['what']) ?>: <?= $e($orderText($order)) ?></small><?php endforeach; ?></div>
        <div class="dp-action">
          <?php if (in_array($person['state'], ['active', 'unknown'], true)): ?>
          <form method="post" action="<?= $e($here()) ?>"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="record" value="<?= (int) $r['id'] ?>">
            <button class="btn red"<?= Page::confirm('red', 'ticket', 'Cancel this ticket in PSOOLA?', 'The QR stops working at the gate and the seat comes back. PSOOLA has no undo.',
              [['user', (string) $r['label']], ['hash', (string) $r['ref']]], ['yes' => 'Yes, cancel in PSOOLA', 'no' => 'No, keep it']) ?>><?= Page::navIcon('ticket') ?>Cancel in PSOOLA</button></form>
          <?php elseif ($person['state'] === 'cancelled'): ?><span class="dp-done"><?= Page::navIcon('check') ?>Done</span><?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
<?php Page::bottom();
