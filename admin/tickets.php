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

// Event tickets: everyone with an active ticket, their original PSOOLA QR and
// printed badges, with a batch history of every badge PDF (like Association).
$user = Page::guard('certificates');
// The Owner's licensed badge font, kept in private storage (never in the repository).
Page::action(static fn (): string => match ((string) ($_POST['do'] ?? '')) {
    'badge_font' => Badges::installFont($user, $_FILES),
    'badge_font_remove' => Badges::removeFont($user),
    default => throw new UserError('Choose a badge font action.'),
}, 'tickets.php');
$e = [Page::class, 'e'];
$canBadge = Auth::can($user, 'registrations');
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
    (SELECT COUNT(*) FROM badge_batch_items bi WHERE bi.registration_id=r.id) AS batch_count
    FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $where ORDER BY $order LIMIT 2000", $params);
$letterOf = static function (array $row): string {
    $first = mb_strtoupper(mb_substr(ltrim((string) $row['first_name']), 0, 1));
    return preg_match('/^[A-Z]$/', $first) ? $first : '#';
};
$letters = array_unique(array_map($letterOf, $rows));
$madeCount = count(array_filter($rows, static fn (array $r): bool => (int) $r['batch_count'] > 0));
$history = $batch ? [] : BadgeBatches::history();
$batchUrl = static fn (int $id): string => 'tickets.php?batch=' . $id;
$select = static function (string $name, string $label, array $options, string $value) use ($e): string {
    $html = '<label class="ticket-filter"><span>' . $e($label) . '</span><select name="' . $name . '" data-auto-submit>';
    foreach ($options as $key => $text) $html .= '<option value="' . $e($key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . $e($text) . '</option>';
    return $html . '</select></label>';
};
Page::top($batch ? BadgeBatches::label($batchId) : 'Tickets', 'tickets', $batch ? '<a class="btn ghost" href="tickets.php">Back to tickets</a>' : '<a class="btn ghost" href="#badge-history">Batch history</a>');
echo Page::ticketTabs('tickets');

if ($batchId && !$batch): ?>
<section class="card"><h2>Badge batch not found</h2><a class="btn" href="tickets.php">Back to tickets</a></section>
<?php elseif ($batch): $items = BadgeBatches::items($batchId); ?>
<section class="card directory-panel">
  <div class="toolbar list-head"><div><h2><?= $e(BadgeBatches::label($batchId)) ?></h2>
    <p class="muted"><?= (int) $batch['guest_count'] ?> guest(s) &middot; made <?= $e($batch['created_at']) ?> (Iraq) by <?= $e($batch['created_by_name']) ?> &middot; downloaded <?= (int) $batch['downloads'] ?> time(s)<?= $batch['last_downloaded_at'] ? ', last ' . $e($batch['last_downloaded_at']) : '' ?></p></div>
    <?php if ($canBadge): ?><div class="certificate-delivery-actions"><a class="btn green" href="badge.php?batch=<?= $batchId ?>"><?= Page::navIcon('download') ?><span>Download batch PDF</span></a><a class="btn ghost" href="badge.php?batch=<?= $batchId ?>&amp;print=1" target="_blank" rel="noopener">Print batch</a></div><?php endif; ?></div>
  <p class="muted small">Names as printed in this batch. Downloading again uses each guest’s current name and PSOOLA ticket.</p>
  <div class="table-wrap"><table><thead><tr><th>#</th><th>Name on badge</th><th>Type</th><th>Reference</th><th>Ticket</th><th>Attendance</th></tr></thead><tbody>
  <?php foreach ($items as $item): ?><tr><td><?= (int) $item['position'] ?></td><td><a href="registration.php?id=<?= (int) $item['registration_id'] ?>"><b><?= $e($item['printed_name']) ?></b></a><?= $item['status'] === 'cancelled' ? ' <span class="pill gold">Cancelled since</span>' : '' ?></td><td><?= $e($item['ticket_type']) ?></td><td><code><?= $e($item['ref']) ?></code></td><td><code><?= $e((string) $item['ticket_no']) ?></code></td><td><?= $item['attended_days'] ? $e($item['attended_days']) : 'Not yet' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php else: ?>
<form id="badge-batch-form" method="post" action="badge.php" data-badge-batch-form><?= Page::csrfField() ?><input type="hidden" name="done" value="" data-badge-done></form>
<section class="card" id="tickets" data-registration-details data-ticket-directory data-no-live data-csrf="<?= $e(Auth::csrf()) ?>">
  <div class="panel-top"><?= Page::panelHeading('Event tickets', 'Find a guest, show their original PSOOLA QR, or make printed badges. Every badge PDF is saved in the batch history below.', 'ticket') ?></div>
  <form method="get" action="tickets.php" class="ticket-filters" role="search">
    <label class="ticket-filter ticket-search"><span>Find a name</span><input type="search" name="q" value="<?= $e($search) ?>" placeholder="Type a name, reference, phone or ticket number" aria-label="Find a guest" data-ticket-search autocomplete="off"></label>
    <?= $select('type', 'Type', ['' => 'All types', 'professional' => 'Dentist', 'student' => 'Student', 'vip' => 'VIP'], $type) ?>
    <?= $select('badge', 'Badge', ['' => 'All', 'none' => 'Not made yet', 'made' => 'Already made'], $made) ?>
    <?= $select('arrived', 'Attendance', ['' => 'All', 'yes' => 'Arrived', 'no' => 'Not arrived'], $arrived) ?>
    <?= $select('sort', 'Order', ['name' => 'Name A–Z', 'name_desc' => 'Name Z–A', 'newest' => 'Newest first'], $sort) ?>
    <?php if ($search !== '' || $type !== '' || $made !== '' || $arrived !== '' || $sort !== 'name'): ?><a class="btn ghost" href="tickets.php">Clear</a><?php endif; ?>
  </form>
  <?php if ($sort !== 'newest' && $rows): ?><nav class="ticket-az" aria-label="Jump to a letter"><?php foreach (array_merge(range('A', 'Z'), ['#']) as $letter): ?><button type="button" data-letter="<?= $letter ?>"<?= in_array($letter, $letters, true) ? '' : ' disabled' ?>><?= $letter ?></button><?php endforeach; ?></nav><?php endif; ?>
  <div class="ticket-bar">
    <p class="muted small"><b data-ticket-count><?= count($rows) ?></b> guest(s) shown &middot; badges made for <?= $madeCount ?> of them</p>
    <?php if ($canBadge): ?><div class="certificate-delivery-actions"><span class="muted small" data-ticket-selected>None selected</span>
      <button class="btn small green" form="badge-batch-form" data-ticket-batch disabled><?= Page::navIcon('download') ?><span>Download selected</span></button>
      <button class="btn small ghost" form="badge-batch-form" name="print" value="1" formtarget="_blank" data-ticket-batch disabled>Print selected</button></div><?php endif; ?>
  </div>
  <p class="rd-link-result" role="status" data-rd-link-result></p>
  <div class="table-wrap ticket-scroll"><table><thead><tr><th><?php if ($canBadge): ?><input type="checkbox" data-ticket-all aria-label="Select every guest shown"><?php endif; ?></th><th>Name</th><th>Reference</th><th>Ticket</th><th>Type</th><th>Attendance</th><th>Badge</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $row): $canQr = $row['has_psoola'] && PsoolaTickets::canView($user, $row); $name = Registrations::fullName($row); ?>
  <tr data-letter="<?= $letterOf($row) ?>" data-search="<?= $e(mb_strtolower($name . ' ' . $row['ref'] . ' ' . $row['phone'] . ' ' . $row['ticket_no'])) ?>">
    <td><?php if ($canQr && $canBadge): ?><input type="checkbox" name="guests[]" value="<?= (int) $row['id'] ?>" form="badge-batch-form" aria-label="<?= $e('Select ' . $name) ?>"><?php endif; ?></td>
    <td><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e($name) ?></b></a></td><td><?= $e($row['ref']) ?></td><td><code><?= $e($row['ticket_no']) ?></code></td>
    <td><?= $e(Registrations::ticketName($row['ticket_type'])) ?></td><td><?= $row['attended_days'] ? $e($row['attended_days']) : 'Not yet' ?></td>
    <td><?php if ($row['last_batch']): ?><a class="pill teal" href="<?= $e($batchUrl((int) $row['last_batch'])) ?>">Batch #<?= (int) $row['last_batch'] ?></a><?= (int) $row['batch_count'] > 1 ? ' <small class="muted">×' . (int) $row['batch_count'] . '</small>' : '' ?><?php else: ?><span class="pill gold">Not made</span><?php endif; ?></td>
    <td><?php if (!$row['has_psoola']): ?><span class="muted small">No PSOOLA ticket</span><?php elseif (!$canQr): ?><span class="muted small">No access</span><?php else: ?><div class="certificate-delivery-actions"><button class="btn small" type="button" data-rd-ticket="psoola" data-registration-id="<?= (int) $row['id'] ?>"><?= Page::navIcon('ticket') ?><span>Show QR</span></button><?php if ($canBadge): ?><a class="btn small ghost" href="badge.php?id=<?= (int) $row['id'] ?>"><?= Page::navIcon('download') ?><span>Badge PDF</span></a><a class="btn small ghost" href="badge.php?id=<?= (int) $row['id'] ?>&amp;print=1" target="_blank" rel="noopener">Print</a><?php endif; ?></div><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <tr data-ticket-empty<?= $rows ? ' hidden' : '' ?>><td colspan="8"><?= Page::emptyState('No guest found', 'Try another name, or clear the filters.', 'search') ?></td></tr>
  </tbody></table></div>
  <p class="muted small">Private ticket access: show a QR only to its holder. Badges are made with each guest’s PSOOLA ticket code.</p>
  <?php if ($user['role'] === 'owner'): $licensed = Badges::hasLicensedFont(); ?>
  <details class="badge-font"><summary>Badge font: <b><?= $licensed ? 'Helvetica Now Display (uploaded)' : 'Inter Display (free match)' ?></b></summary>
    <form method="post" enctype="multipart/form-data" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="badge_font">
      <label>Black (.ttf) <input type="file" name="font_black" accept=".ttf" required></label>
      <label>Medium (.ttf) <input type="file" name="font_medium" accept=".ttf" required></label>
      <button class="btn small">Upload licensed font</button>
    </form>
    <?php if ($licensed): ?><form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="badge_font_remove"><button class="btn small ghost">Use the free font again</button></form><?php endif; ?>
    <p class="muted small">Only upload a font your licence covers. It is stored privately on the server, never on GitHub.</p>
  </details>
  <?php endif; ?>
  <dialog class="rd-dialog rd-provider-dialog" data-rd-psoola-dialog aria-labelledby="rd-psoola-title"><div class="rd-dialog-heading"><div><h2 id="rd-psoola-title">PSOOLA tickets</h2><p>Original ticket details and QR code</p></div><button type="button" class="icon-button" data-rd-close aria-label="Close PSOOLA tickets"><?= Page::navIcon('close') ?></button></div><div data-rd-psoola-body></div></dialog>
</section>
<section class="card directory-panel" id="badge-history"><div class="toolbar list-head"><div><h2>Batch history</h2><p class="muted small">Every badge PDF made in the admin, newest first: one guest from their page, or many ticked above.</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Batch</th><th>Made (Iraq)</th><th>Guests</th><th>Names</th><th>Made by</th><th>Downloads</th><th>PDF</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?><tr><td><a href="<?= $e($batchUrl((int) $h['id'])) ?>"><b><?= $e(BadgeBatches::label((int) $h['id'])) ?></b></a></td><td><?= $e($h['created_at']) ?></td><td><?= (int) $h['guest_count'] ?></td>
    <td><?= $e((string) $h['first_names']) ?><?= (int) $h['guest_count'] > 3 ? ' <span class="muted small">and ' . ((int) $h['guest_count'] - 3) . ' more</span>' : '' ?></td><td><?= $e($h['created_by_name']) ?></td>
    <td><?= (int) $h['downloads'] ?><?= $h['last_downloaded_at'] ? '<br><small class="muted">last ' . $e($h['last_downloaded_at']) . '</small>' : '' ?></td>
    <td><div class="certificate-delivery-actions"><a class="btn small ghost" href="<?= $e($batchUrl((int) $h['id'])) ?>">Open</a><?php if ($canBadge): ?><a class="btn small ghost" href="badge.php?batch=<?= (int) $h['id'] ?>">PDF</a><a class="btn small ghost" href="badge.php?batch=<?= (int) $h['id'] ?>&amp;print=1" target="_blank" rel="noopener">Print</a><?php endif; ?></div></td></tr><?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="7"><?= Page::emptyState('No badges made yet', 'Tick guests above and press Download selected, or use Badge PDF on one guest.', 'ticket') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php endif;
Page::bottom();
