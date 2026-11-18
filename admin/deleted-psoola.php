<?php
// People deleted earlier whose PSOOLA ticket may still work (owner, 19 Nov
// 2026). Only the main Owner opens this page; each person is cancelled in
// PSOOLA only when the Owner presses that person's button.

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
    Page::redirect('deleted-psoola.php');
}

$problem = null;
$all = [];
$show = is_string($_GET['show'] ?? null) && isset(PsoolaCancel::STATES[$_GET['show']]) ? $_GET['show'] : 'active';
$q = Page::query('q', 120);
if (!PsoolaCancel::active()) {
    $problem = 'PSOOLA is not the payment gateway on this server, so PSOOLA tickets cannot be checked.';
}
try {
    $all = PsoolaCancel::deletedPeople();
} catch (\Throwable $error) {
    App::log('error', 'Deleted people PSOOLA check failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
    $problem = 'PSOOLA could not be checked right now. Reload the page in a minute.';
}
$counts = array_fill_keys(array_keys(PsoolaCancel::STATES), 0);
foreach ($all as $person) { $counts[$person['state']]++; $counts['all']++; }
$people = array_values(array_filter($all, static function (array $person) use ($show, $q): bool {
    if ($show !== 'all' && $person['state'] !== $show) return false;
    if ($q === '') return true;
    $hay = mb_strtolower($person['record']['label'] . ' ' . $person['record']['ref'] . ' ' . ($person['record']['deleted_by_name'] ?? ''));
    return str_contains($hay, mb_strtolower($q));
}));
$statusText = static function (array $order): string {
    if ($order['shared']) return 'Kept: another person in the database uses this order';
    if ($order['error'] !== null) return 'Not checked: ' . $order['error'];
    $look = $order['look'];
    if (!$look || !$look['found']) return 'PSOOLA does not know this order';
    if (PsoolaCancel::isCancelled($look)) return 'Cancelled';
    return 'Still works (order ' . $look['status'] . ', ticket ' . implode(', ', $look['tickets']) . ')';
};
$pill = ['active' => ['red', 'Still works'], 'cancelled' => ['green', 'Cancelled'], 'none' => ['grey', 'No PSOOLA ticket'], 'unknown' => ['gold', 'Not checked']];

Page::top('Deleted people', 'registrations');
?>
<div data-no-live>
<section class="card">
  <h2>Deleted people</h2>
  <p class="muted">Everyone deleted from the database, who deleted them and when, and whether their PSOOLA ticket still works. Press <b>Cancel in PSOOLA</b> to stop a ticket that still works: the QR stops working and the seat comes back. Nothing happens until you press the button. Only you can see this page.</p>
  <form method="get" class="list-filters deleted-filters">
    <input type="hidden" name="show" value="<?= $e($show) ?>">
    <div class="filter-seg"><b class="deleted-filter-title">Show</b><div>
      <?php foreach (PsoolaCancel::STATES as $value => $text): ?><a class="<?= $show === $value ? 'on' : '' ?>" href="deleted-psoola.php?<?= $e(http_build_query(['show' => $value] + ($q !== '' ? ['q' => $q] : []))) ?>"><span><?= $e($text) ?> (<?= (int) $counts[$value] ?>)</span></a><?php endforeach; ?>
    </div></div>
    <div class="filter-search-row"><label class="filter-search"><?= Page::navIcon('search') ?><input name="q" value="<?= $e($q) ?>" placeholder="Search name, reference or who deleted" aria-label="Search name, reference or who deleted"></label>
    <button class="btn">Search</button><a class="filter-clear" href="deleted-psoola.php">Clear all</a><a class="btn ghost" href="registrations.php">Back to Registrations</a></div>
  </form>
  <?php if ($problem): ?><p class="flash err"><?= $e($problem) ?></p><?php endif; ?>
  <?php if (!$problem && $show === 'active' && !$people && $q === ''): ?><p><b>All clear.</b> No deleted person has a PSOOLA ticket that still works.</p><?php endif; ?>
  <?php if (!$people && !($show === 'active' && $q === '')): ?><p class="muted">Nobody matches.</p><?php endif; ?>
  <?php if ($people): ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Deleted person</th><th>Reference</th><th>Ticket</th><th>Deleted</th><th>Deleted by</th><th>PSOOLA</th><th></th></tr>
    <?php foreach ($people as $i => $person): $record = $person['record']; [$tone, $word] = $pill[$person['state']]; ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><b><?= $e($record['label']) ?></b></td>
      <td><code><?= $e($record['ref']) ?></code></td>
      <td><?= $record['ticket_type'] !== '' ? $e(\Ismile\Registrations::ticketName($record['ticket_type'])) : '–' ?><?= $record['status'] === 'complimentary' ? ' <span class="pill violet">Free</span>' : '' ?></td>
      <td><?= Page::when($record['deleted_at']) ?></td>
      <td><?= $e($record['deleted_by_name'] ?? 'Unknown') ?></td>
      <td><span class="pill <?= $tone ?>"><?= $e($word) ?></span><?php foreach ($person['orders'] as $order): ?><div><small><?= $e($order['what']) ?>: <?= $e($statusText($order)) ?></small></div><?php endforeach; ?></td>
      <td><?php if (in_array($person['state'], ['active', 'unknown'], true)): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="record" value="<?= (int) $record['id'] ?>">
        <button class="btn red"<?= Page::confirm('red', 'ticket', 'Cancel this ticket in PSOOLA?', 'The QR stops working at the gate and the seat comes back. PSOOLA has no undo.',
          [['user', (string) $record['label']], ['hash', (string) $record['ref']]], ['yes' => 'Yes, cancel in PSOOLA', 'no' => 'No, keep it']) ?>>Cancel in PSOOLA</button></form><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>
<?php Page::bottom();
