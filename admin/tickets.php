<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\BadgeBatches;
use Ismile\Badges;
use Ismile\Db;
use Ismile\PsoolaTickets;
use Ismile\Registrations;
use Ismile\UserError;

// Event tickets: everyone with an active ticket and their original PSOOLA QR.
// Ticked guests become a badge batch (like Association), with one PDF of all
// their badges; batches can be deleted and their numbers are reused.
$user = Page::guard('certificates');
$canBadge = Auth::can($user, 'registrations');
Page::action(static function () use ($user, $canBadge): string {
    $action = (string) ($_POST['do'] ?? '');
    if (in_array($action, ['badge_batch', 'badge_batch_delete'], true) && !$canBadge) throw new UserError('Your account cannot make badges.');
    if ($action === 'badge_batch') {
        // One JSON field, like Association: the admin turns list fields (name[]=…) into empty text.
        $ticked = json_decode((string) ($_POST['guests_json'] ?? ''), true);
        $id = BadgeBatches::create($user, is_array($ticked) ? array_map('intval', array_filter($ticked, 'is_int')) : []);
        Page::flash('ok', BadgeBatches::label(BadgeBatches::find($id)) . ' created. Download or print its PDF below.');
        Page::redirect('tickets.php?batch=' . $id);
    }
    return match ($action) {
        'badge_batch_delete' => BadgeBatches::delete($user, (int) ($_POST['batch_id'] ?? 0)),
        default => throw new UserError('Choose an action.'),
    };
}, 'tickets.php#badge-history');
$e = [Page::class, 'e'];
$batchId = max(0, (int) ($_GET['batch'] ?? 0));
$batch = $batchId ? BadgeBatches::find($batchId) : null;

// Filters: kept in the address, so a filtered list can be reopened.
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$type = in_array($_GET['type'] ?? '', ['professional', 'student', 'vip'], true) ? $_GET['type'] : '';
$made = in_array($_GET['badge'] ?? '', ['none', 'made'], true) ? $_GET['badge'] : '';
$arrived = in_array($_GET['arrived'] ?? '', ['yes', 'no'], true) ? $_GET['arrived'] : '';
$sort = in_array($_GET['sort'] ?? '', ['name_desc', 'newest'], true) ? $_GET['sort'] : 'name';
$where = "r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL";
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= " AND (CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ? OR r.ref LIKE ? OR r.phone LIKE ? OR t.ticket_no LIKE ?)";
    array_push($params, $like, $like, $like, $like);
}
if ($type !== '') {
    $where .= ' AND r.ticket_type = ?';
    $params[] = $type;
}
if ($made !== '') $where .= ' AND' . ($made === 'made' ? '' : ' NOT') . ' EXISTS (SELECT 1 FROM badge_batch_items bi WHERE bi.registration_id = r.id)';
if ($arrived !== '') $where .= ' AND' . ($arrived === 'yes' ? '' : ' NOT') . ' EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id = t.id)';
$order = match ($sort) {
    'name_desc' => 'r.first_name DESC, r.father_name DESC, r.grandfather_name DESC, r.id DESC',
    'newest' => 'r.paid_at DESC, r.id DESC',
    default => 'r.first_name, r.father_name, r.grandfather_name, r.id',
};
$rows = $batch ? [] : Db::all("SELECT r.id, r.ref, r.phone, r.first_name, r.father_name, r.grandfather_name, r.ticket_type, r.status, r.created_by, t.ticket_no,
    (EXISTS (SELECT 1 FROM sponsored_ticket_orders s WHERE s.registration_id=r.id AND s.status='issued' AND s.provider_order_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM payments p WHERE p.registration_id=r.id AND p.gateway='psoola' AND p.status='paid' AND p.provider_payment_id IS NOT NULL)) AS has_psoola,
    (SELECT GROUP_CONCAT(CONCAT('Day ', a.event_day) ORDER BY a.event_day SEPARATOR ' & ') FROM ticket_attendance a WHERE a.ticket_id=t.id) AS attended_days,
    (SELECT MAX(bi.batch_id) FROM badge_batch_items bi WHERE bi.registration_id=r.id) AS last_batch,
    (SELECT b.batch_number FROM badge_batch_items bi JOIN badge_batches b ON b.id=bi.batch_id WHERE bi.registration_id=r.id ORDER BY b.id DESC LIMIT 1) AS last_batch_number,
    (SELECT COUNT(*) FROM badge_batch_items bi WHERE bi.registration_id=r.id) AS batch_count
    FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $where ORDER BY $order LIMIT 2000", $params);
$letterOf = static function (array $row): string {
    $first = mb_strtoupper(mb_substr(ltrim((string) $row['first_name']), 0, 1));
    return preg_match('/^[A-Z]$/', $first) ? $first : '#';
};
$letters = array_unique(array_map($letterOf, $rows));
$history = $batch ? [] : BadgeBatches::history();
$batchUrl = static fn (int $id): string => 'tickets.php?batch=' . $id;
$deleteButton = static function (array $b, string $size) use ($e): string {
    $label = BadgeBatches::label($b);
    return '<form method="post" action="tickets.php" class="inline-form">' . Page::csrfField() . '<input type="hidden" name="do" value="badge_batch_delete"><input type="hidden" name="batch_id" value="' . (int) $b['id'] . '">'
        . '<button class="btn ' . $size . ' ghost"' . Page::confirm('red', 'trash', 'Delete ' . $label . '?', 'Only the batch and its list are removed; the guests and their tickets stay. Its number goes to the next new batch.', [['users', (int) $b['guest_count'] . ' guest(s) in this batch']], ['deletes' => [$label]]) . '>Delete</button></form>';
};
$select = static function (string $name, string $label, array $options, string $value) use ($e): string {
    $html = '<label class="ticket-filter"><span>' . $e($label) . '</span><select name="' . $name . '" data-auto-submit>';
    foreach ($options as $key => $text) $html .= '<option value="' . $e($key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . $e($text) . '</option>';
    return $html . '</select></label>';
};
$totals = $batch ? [] : Db::one("SELECT COUNT(*) AS guests, COALESCE(SUM(r.ticket_type='professional'),0) AS professional, COALESCE(SUM(r.ticket_type='student'),0) AS student, COALESCE(SUM(r.ticket_type='vip'),0) AS vip,
    COALESCE(SUM(EXISTS (SELECT 1 FROM badge_batch_items bi WHERE bi.registration_id=r.id)),0) AS made,
    COALESCE(SUM(EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id=t.id)),0) AS arrived
    FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL");
// A link to this list with one filter changed (the others kept).
$listUrl = static function (array $change) use ($search, $type, $made, $arrived, $sort): string {
    $query = array_filter($change + ['q' => $search, 'type' => $type, 'badge' => $made, 'arrived' => $arrived, 'sort' => $sort === 'name' ? '' : $sort], static fn ($v): bool => $v !== '');
    return 'tickets.php' . ($query ? '?' . http_build_query($query) : '');
};
$chip = static function (string $label, int $count, bool $on, string $href, string $tone = '') use ($e): string {
    return '<a class="tk-chip' . ($on ? ' on' : '') . ($tone !== '' ? ' ' . $tone : '') . '" href="' . $e($href) . '"' . ($on ? ' aria-current="true"' : '') . '>' . $e($label) . '<b>' . number_format($count) . '</b></a>';
};
Page::top($batch ? BadgeBatches::label($batch) : 'Tickets', 'tickets', $batch ? '<a class="btn ghost" href="tickets.php">Back to tickets</a>' : '<a class="btn ghost" href="#badge-history">Batch history</a>');
echo Page::ticketTabs('tickets');

if ($batchId && !$batch): ?>
<section class="card"><h2>Badge batch not found</h2><a class="btn" href="tickets.php">Back to tickets</a></section>
<?php elseif ($batch):
    $items = BadgeBatches::items($batchId);
    $kinds = array_count_values(array_column($items, 'ticket_type'));
    $arrivedCount = count(array_filter($items, static fn (array $i): bool => (bool) $i['attended_days']));
    $typePill = static fn (string $type): string => match ($type) { 'Student' => 'green', 'VIP' => 'gold', default => 'blue' };
?>
<section class="card bb-hero">
  <div class="bb-number" aria-hidden="true"><small>Batch</small><b>#<?= (int) $batch['batch_number'] ?></b></div>
  <div class="bb-summary">
    <h2><?= count($items) ?> badge<?= count($items) === 1 ? '' : 's' ?> ready to print</h2>
    <p class="muted">Made <?= $e(date('j M Y, H:i', strtotime((string) $batch['created_at']))) ?> (Iraq) by <b><?= $e($batch['created_by_name']) ?></b></p>
    <div class="bb-chips">
      <span class="bb-chip"><?= Page::navIcon('users') ?><b><?= count($items) ?></b> guest<?= count($items) === 1 ? '' : 's' ?></span>
      <?php foreach (['Dentist', 'Student', 'VIP'] as $kind): if (!empty($kinds[$kind])): ?><span class="pill <?= $typePill($kind) ?>"><?= (int) $kinds[$kind] ?> <?= $kind ?></span><?php endif; endforeach; ?>
      <span class="bb-chip"><?= Page::navIcon('download') ?><?= (int) $batch['downloads'] ?> download<?= (int) $batch['downloads'] === 1 ? '' : 's' ?><?= $batch['last_downloaded_at'] ? ' · last ' . $e(date('j M, H:i', strtotime((string) $batch['last_downloaded_at']))) : '' ?></span>
      <span class="bb-chip"><?= Page::navIcon('checkin') ?><?= $arrivedCount ?> arrived</span>
    </div>
  </div>
  <?php if ($canBadge): ?><div class="bb-danger"><?= $deleteButton($batch, '') ?></div><?php endif; ?>
</section>
<?php if ($canBadge): ?>
<section class="card bb-print">
  <div class="panel-top"><?= Page::panelHeading('Print this batch', 'Choose the paper, then download the PDF. Every badge comes out exactly 10 × 11.5 cm.', 'ticket') ?></div>
  <form method="get" action="badge.php"><input type="hidden" name="batch" value="<?= $batchId ?>">
    <fieldset class="bb-papers"><legend class="sr-only">Paper</legend>
    <?php foreach (Badges::SHEETS as $key => [$paper, $w, $h, $cols, $rows]): $sheets = (int) ceil(count($items) / ($cols * $rows)); [$name, $per] = array_map('trim', explode('·', $paper) + [1 => '']); ?>
      <label class="bb-paper">
        <input type="radio" name="sheet" value="<?= $e($key) ?>"<?= $key === 'a4' ? ' checked' : '' ?>>
        <?php $px = min(84 / $h, 120 / $w); /* the real paper, drawn small */ ?><span class="bb-sheet" style="width:<?= round($w * $px) ?>px;height:<?= round($h * $px) ?>px;padding:<?= round(($h - $rows * 115) / 2 * $px, 1) ?>px <?= round(($w - $cols * 100) / 2 * $px, 1) ?>px;--cols:<?= $cols ?>;--rows:<?= $rows ?>"><?= str_repeat('<i></i>', $cols * $rows) ?></span>
        <b><?= $e($key === 'single' ? 'Card printer' : $name) ?></b>
        <small><?= $e($key === 'single' ? 'One badge per page' : $per) ?></small>
        <em><?= $key === 'single' ? count($items) * 2 . ' pages' : $sheets . ' sheet' . ($sheets === 1 ? '' : 's') . ' · ' . $sheets * 2 . ' pages' ?></em>
      </label>
    <?php endforeach; ?>
    </fieldset>
    <div class="bb-actions">
      <button class="btn green bb-big"><?= Page::navIcon('download') ?><span>Download PDF</span></button>
    </div>
  </form>
</section>
<?php endif; ?>
<section class="card directory-panel">
  <div class="toolbar list-head"><div><h2>Guests in this batch <span class="result-count"><?= count($items) ?></span></h2></div></div>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>Name on badge</th><th>Type</th><th>Reference</th><th>Ticket</th><th>Attendance</th></tr></thead><tbody>
  <?php foreach ($items as $item): ?><tr>
    <td class="bb-pos"><?= (int) $item['position'] ?></td>
    <td><a class="bb-guest" href="registration.php?id=<?= (int) $item['registration_id'] ?>"><span class="bb-initial"><?= $e(mb_substr((string) $item['printed_name'], 0, 1)) ?></span><b><?= $e($item['printed_name']) ?></b></a><?= $item['status'] === 'cancelled' ? ' <span class="pill red">Cancelled since</span>' : '' ?></td>
    <td><span class="pill <?= $typePill((string) $item['ticket_type']) ?>"><?= $e($item['ticket_type']) ?></span></td>
    <td><code><?= $e($item['ref']) ?></code></td><td><code><?= $e((string) $item['ticket_no']) ?></code></td>
    <td><?= $item['attended_days'] ? '<span class="pill green">' . $e($item['attended_days']) . '</span>' : '<span class="pill grey">Not yet</span>' ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php else: ?>
<form id="badge-batch-form" method="post" action="tickets.php" data-badge-batch-form><?= Page::csrfField() ?><input type="hidden" name="do" value="badge_batch"><input type="hidden" name="guests_json" value="" data-badge-guests></form>
<?= Page::stats([
    ['Guests with a ticket', number_format((int) $totals['guests']), (int) $totals['professional'] . ' Dentist · ' . (int) $totals['student'] . ' Student · ' . (int) $totals['vip'] . ' VIP', 'users', 'blue'],
    ['Badges made', number_format((int) $totals['made']), number_format((int) $totals['guests'] - (int) $totals['made']) . ' not made yet', 'ticket', 'teal'],
    ['Badge batches', number_format(count($history)), 'Saved with Create batch', 'lists', 'violet'],
    ['Arrived', number_format((int) $totals['arrived']), 'Scanned at the door', 'checkin', 'green'],
]) ?>
<section class="card tk-panel" id="tickets" data-registration-details data-ticket-directory data-no-live data-csrf="<?= $e(Auth::csrf()) ?>">
  <div class="tk-head">
    <?= Page::panelHeading('Guests & badges', 'Find a guest, show their PSOOLA QR, or tick guests and press Create batch.', 'ticket') ?>
    <form method="get" action="tickets.php" class="tk-search" role="search">
      <?= Page::navIcon('search') ?>
      <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Find a name, reference, phone or ticket…" aria-label="Find a guest" data-ticket-search autocomplete="off">
      <?php foreach (['type' => $type, 'badge' => $made, 'arrived' => $arrived, 'sort' => $sort === 'name' ? '' : $sort] as $key => $value): if ($value !== ''): ?><input type="hidden" name="<?= $key ?>" value="<?= $e($value) ?>"><?php endif; endforeach; ?>
    </form>
  </div>
  <div class="tk-toolbar">
    <div class="tk-chips" role="group" aria-label="Type">
      <?= $chip('All', (int) $totals['guests'], $type === '', $listUrl(['type' => ''])) ?>
      <?= $chip('Dentist', (int) $totals['professional'], $type === 'professional', $listUrl(['type' => 'professional']), 'blue') ?>
      <?= $chip('Student', (int) $totals['student'], $type === 'student', $listUrl(['type' => 'student']), 'green') ?>
      <?= $chip('VIP', (int) $totals['vip'], $type === 'vip', $listUrl(['type' => 'vip']), 'gold') ?>
    </div>
    <div class="tk-chips" role="group" aria-label="Badge">
      <?= $chip('Not in a batch', (int) $totals['guests'] - (int) $totals['made'], $made === 'none', $listUrl(['badge' => $made === 'none' ? '' : 'none']), 'gold') ?>
      <?= $chip('In a batch', (int) $totals['made'], $made === 'made', $listUrl(['badge' => $made === 'made' ? '' : 'made']), 'teal') ?>
    </div>
    <form method="get" action="tickets.php" class="tk-selects">
      <?php foreach (['q' => $search, 'type' => $type, 'badge' => $made] as $key => $value): if ($value !== ''): ?><input type="hidden" name="<?= $key ?>" value="<?= $e($value) ?>"><?php endif; endforeach; ?>
      <?= $select('arrived', 'Attendance', ['' => 'Everyone', 'yes' => 'Arrived', 'no' => 'Not arrived'], $arrived) ?>
      <?= $select('sort', 'Order', ['name' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'newest' => 'Newest first'], $sort) ?>
    </form>
  </div>
  <?php if ($sort !== 'newest' && $rows): ?><nav class="ticket-az" aria-label="Jump to a letter"><?php foreach (array_merge(range('A', 'Z'), ['#']) as $letter): ?><button type="button" data-letter="<?= $letter ?>"<?= in_array($letter, $letters, true) ? '' : ' disabled' ?>><?= $letter ?></button><?php endforeach; ?></nav><?php endif; ?>
  <p class="tk-count"><b data-ticket-count><?= count($rows) ?></b> guest(s) shown<?php if ($search !== '' || $type !== '' || $made !== '' || $arrived !== '' || $sort !== 'name'): ?> · <a href="tickets.php">Clear all filters</a><?php endif; ?></p>
  <p class="rd-link-result" role="status" data-rd-link-result></p>
  <div class="table-wrap ticket-scroll"><table><thead><tr><th><?php if ($canBadge): ?><input type="checkbox" data-ticket-all aria-label="Select every guest shown"><?php endif; ?></th><th>Guest</th><th>Type</th><th>Ticket</th><th>Attendance</th><th>Badge</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $row): $canQr = $row['has_psoola'] && PsoolaTickets::canView($user, $row); $name = Registrations::fullName($row); $kind = Registrations::ticketName($row['ticket_type']); ?>
  <tr<?= $row['last_batch'] ? ' class="tk-in-batch"' : '' ?> data-letter="<?= $letterOf($row) ?>" data-search="<?= $e(mb_strtolower($name . ' ' . $row['ref'] . ' ' . $row['phone'] . ' ' . $row['ticket_no'])) ?>">
    <td><?php if ($row['last_batch']): ?><span class="tk-taken" title="<?= $e('Already in Badge batch #' . (int) $row['last_batch_number']) ?>">✓</span><?php elseif ($canQr && $canBadge): ?><input type="checkbox" data-guest="<?= (int) $row['id'] ?>" aria-label="<?= $e('Select ' . $name) ?>"><?php endif; ?></td>
    <td><a class="bb-guest" href="registration.php?id=<?= (int) $row['id'] ?>"><span class="bb-initial"><?= $e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><span><b><?= $e($name) ?></b><small class="tk-ref"><?= $e($row['ref']) ?></small></span></a></td>
    <td><span class="pill <?= $kind === 'Student' ? 'green' : ($kind === 'VIP' ? 'gold' : 'blue') ?>"><?= $e($kind) ?></span></td>
    <td><code><?= $e($row['ticket_no']) ?></code></td>
    <td><?= $row['attended_days'] ? '<span class="pill green">' . $e($row['attended_days']) . '</span>' : '<span class="pill grey">Not yet</span>' ?></td>
    <td><?php if ($row['last_batch']): ?><a class="pill teal" href="<?= $e($batchUrl((int) $row['last_batch'])) ?>">In batch #<?= (int) $row['last_batch_number'] ?></a><?php else: ?><span class="pill gold">Not made</span><?php endif; ?></td>
    <td><?php if (!$row['has_psoola']): ?><span class="muted small">No PSOOLA ticket</span><?php elseif (!$canQr): ?><span class="muted small">No access</span><?php else: ?><div class="certificate-delivery-actions"><button class="btn small" type="button" data-rd-ticket="psoola" data-registration-id="<?= (int) $row['id'] ?>"><?= Page::navIcon('ticket') ?><span>Show QR</span></button><?php if ($canBadge): ?><a class="btn small ghost" href="badge.php?id=<?= (int) $row['id'] ?>"><?= Page::navIcon('download') ?><span>Badge PDF</span></a><?php endif; ?></div><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <tr data-ticket-empty<?= $rows ? ' hidden' : '' ?>><td colspan="7"><?= Page::emptyState('No guest found', 'Try another name, or clear the filters.', 'search') ?></td></tr>
  </tbody></table></div>
  <?php if ($canBadge): ?><div class="tk-selbar" data-ticket-selbar>
    <span class="tk-selbar-icon"><?= Page::navIcon('ticket') ?></span>
    <span class="tk-selbar-text"><b data-ticket-selected>None selected</b><small>Tick guests above, then create a batch of their badges. Guests with ✓ are already in a batch.</small></span>
    <button type="button" class="btn ghost" data-ticket-clear>Clear</button>
    <button class="btn green" form="badge-batch-form" data-ticket-batch disabled><?= Page::navIcon('ticket') ?><span>Create batch</span></button>
  </div><?php endif; ?>
  <dialog class="rd-dialog rd-provider-dialog" data-rd-psoola-dialog aria-labelledby="rd-psoola-title"><div class="rd-dialog-heading"><div><h2 id="rd-psoola-title">PSOOLA tickets</h2><p>Original ticket details and QR code</p></div><button type="button" class="icon-button" data-rd-close aria-label="Close PSOOLA tickets"><?= Page::navIcon('close') ?></button></div><div data-rd-psoola-body></div></dialog>
</section>
<section class="card directory-panel" id="badge-history"><div class="toolbar list-head"><div><h2>Batch history <span class="result-count"><?= count($history) ?></span></h2><p class="muted small">Deleting a batch frees its number for the next new batch.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Batch</th><th>Guests</th><th>Made</th><th>Downloads</th><th></th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr>
    <td><a class="tk-batch" href="<?= $e($batchUrl((int) $h['id'])) ?>"><span class="tk-batch-no">#<?= (int) $h['batch_number'] ?></span><b><?= $e(BadgeBatches::label($h)) ?></b></a></td>
    <td><b><?= (int) $h['guest_count'] ?></b> <span class="muted small"><?= $e((string) $h['first_names']) ?><?= (int) $h['guest_count'] > 3 ? ' and ' . ((int) $h['guest_count'] - 3) . ' more' : '' ?></span></td>
    <td><?= $e(date('j M, H:i', strtotime((string) $h['created_at']))) ?><br><small class="muted">by <?= $e($h['created_by_name']) ?></small></td>
    <td><?= (int) $h['downloads'] ?><?= $h['last_downloaded_at'] ? '<br><small class="muted">last ' . $e(date('j M, H:i', strtotime((string) $h['last_downloaded_at']))) . '</small>' : '' ?></td>
    <td><div class="certificate-delivery-actions"><a class="btn small" href="<?= $e($batchUrl((int) $h['id'])) ?>">Open</a><?php if ($canBadge): ?><a class="btn small ghost" href="badge.php?batch=<?= (int) $h['id'] ?>&amp;sheet=a4"><?= Page::navIcon('download') ?><span>PDF</span></a><?= $deleteButton($h, 'small') ?><?php endif; ?></div></td>
  </tr><?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="5"><?= Page::emptyState('No badge batches yet', 'Tick guests above and press Create batch.', 'ticket') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif;
Page::bottom();
