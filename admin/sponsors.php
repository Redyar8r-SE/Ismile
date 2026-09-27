<?php
// Sponsors & booths: requests handled by a person. New → Contacted → Agreed →
// Paid → Confirmed (or Declined / Waiting list). A request cannot be Confirmed
// before it is Paid, and a tier cannot take more sponsors than its spots;
// the Owner can override both, and it is logged.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Sponsors;
use Ismile\UserError;

$user = Page::guard('sponsors');
$id = (int) ($_GET['id'] ?? 0);

Page::action(static function () use ($user, $id): string {
    $request = Sponsors::find($id);
    if ($request === null) {
        throw new UserError('Request not found.');
    }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'status') {
        Sponsors::changeStatus($request, (string) ($_POST['status'] ?? ''), $user, ($_POST['override'] ?? '') === '1');
        return 'Status changed.';
    }
    if ($do === 'details') {
        $assigned = (int) ($_POST['assigned_to'] ?? 0);
        $amount = trim((string) ($_POST['amount_agreed'] ?? ''));
        Db::update('sponsor_requests', [
            'assigned_to'   => $assigned > 0 ? $assigned : null,
            'amount_agreed' => $amount === '' ? null : max(0, (int) $amount),
            'notes'         => Page::post('notes', 5000) ?: null,
            'updated_at'    => App::now(),
        ], 'id = ?', [$request['id']]);
        Audit::log((int) $user['id'], 'sponsor.details', 'sponsor_request', (int) $request['id'], ['assigned_to' => $assigned, 'amount' => $amount]);
        return 'Saved.';
    }
    throw new UserError('Unknown action.');
}, 'sponsors.php?id=' . $id);

$spots = Sponsors::spots();
$staff = Db::all("SELECT id, name FROM admin_users WHERE disabled_at IS NULL AND role IN ('owner','registration','finance') ORDER BY name");
$e = [Page::class, 'e'];

if ($id > 0 && ($request = Sponsors::find($id))) {
    $history = Db::all("SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id WHERE target_type = 'sponsor_request' AND target_id = ? ORDER BY a.id DESC", [$id]);
    Page::top($request['company'], 'sponsors');
    $tier = $request['package'] ? ($spots[$request['package']] ?? null) : null;
    ?>
    <div class="toolbar"><a class="btn ghost" href="sponsors.php">← All requests</a><span><code class="big"><?= $e($request['ref']) ?></code> <?= Page::pill($request['status']) ?></span></div>
    <div class="grid2">
      <div class="card">
        <h2><?= $request['kind'] === 'booth' ? 'Exhibition booth' : 'Sponsorship' ?><?= $tier ? ': ' . $e($tier['name']) : ($request['package'] === 'unsure' ? ': not sure yet' : '') ?></h2>
        <?php if ($tier): ?><p><?= $tier['confirmed'] ?> of <?= $tier['spots'] ?> <?= $e($tier['name']) ?> spots confirmed.</p><?php endif; ?>
        <dl class="facts">
          <dt>Company</dt><dd><b><?= $e($request['company']) ?></b></dd>
          <dt>Contact</dt><dd><?= $e($request['contact_name']) ?><?= $request['contact_role'] ? ', ' . $e($request['contact_role']) : '' ?></dd>
          <dt>Phone</dt><dd dir="ltr"><?= $e($request['phone']) ?></dd>
          <dt>Email</dt><dd dir="ltr"><?= $e($request['email']) ?></dd>
          <dt>Website</dt><dd dir="ltr"><?= $e($request['website'] ?? '–') ?></dd>
          <dt>City</dt><dd><?= $e($request['city'] ?? '–') ?></dd>
          <dt>Language</dt><dd><?= $e($request['lang']) ?></dd>
          <dt>Received</dt><dd><?= Page::when($request['created_at']) ?></dd>
        </dl>
        <?php if ($request['message']): ?><h3>Their message</h3><p class="message"><?= nl2br($e($request['message'])) ?></p><?php endif; ?>
      </div>
      <div class="card">
        <h2>Move it forward</h2>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="status">
          <div class="status-buttons">
            <?php foreach (Sponsors::STATUSES as $status): ?>
              <button class="btn small <?= $status === $request['status'] ? '' : 'ghost' ?>" name="status" value="<?= $status ?>"><?= $e(str_replace('_', ' ', ucfirst($status))) ?></button>
            <?php endforeach; ?>
          </div>
          <?php if ($user['role'] === 'owner'): ?><label class="inline"><input type="checkbox" name="override" value="1"> Owner override (confirm before paid, or beyond the spots)</label><?php endif; ?>
          <p class="muted small">Confirmed only after Paid. Only then add their logo in Site content → Sponsors.</p>
        </form>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="details">
          <label>Handled by<select name="assigned_to"><option value="0">Nobody yet</option>
            <?php foreach ($staff as $person): ?><option value="<?= (int) $person['id'] ?>"<?= (int) $request['assigned_to'] === (int) $person['id'] ? ' selected' : '' ?>><?= $e($person['name']) ?></option><?php endforeach; ?></select></label>
          <label>Amount agreed (IQD)<input type="number" name="amount_agreed" min="0" step="1000" value="<?= $e($request['amount_agreed'] ?? '') ?>"></label>
          <label>Notes (calls, what was agreed, booth position…)<textarea name="notes" rows="6"><?= $e($request['notes'] ?? '') ?></textarea></label>
          <button class="btn">Save</button>
        </form>
      </div>
    </div>
    <div class="card"><h2>History</h2><ul class="history">
      <?php foreach ($history as $item): ?><li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'system') ?> · <?= $e($item['action']) ?> <span class="muted small"><?= $e((string) $item['details']) ?></span></li><?php endforeach; ?>
      <?php if (!$history): ?><li class="muted">No changes yet.</li><?php endif; ?>
    </ul></div>
    <?php
    Page::bottom();
    exit;
}

$filter = Page::query('status');
// Sponsors and exhibition booths are different things: shown apart.
$kind = in_array(Page::query('kind'), ['sponsor', 'booth'], true) ? Page::query('kind') : 'sponsor';
$where = ['s.kind = ?'];
$params = [$kind];
if ($filter !== '' && in_array($filter, Sponsors::STATUSES, true)) {
    $where[] = 's.status = ?';
    $params[] = $filter;
}
$rows = Db::all("SELECT s.*, u.name AS handler FROM sponsor_requests s LEFT JOIN admin_users u ON u.id = s.assigned_to WHERE " . implode(' AND ', $where)
    . " ORDER BY FIELD(s.status, 'new', 'contacted', 'agreed', 'paid', 'waiting_list', 'confirmed', 'declined'), s.id DESC LIMIT 300", $params);
$kindCounts = [];
foreach (Db::all('SELECT kind, COUNT(*) AS n FROM sponsor_requests GROUP BY kind') as $row) {
    $kindCounts[$row['kind']] = (int) $row['n'];
}
Page::top('Sponsors & booths', 'sponsors');
?>
<?php if ($kind === 'sponsor'): ?>
<div class="tiles small">
  <?php foreach ($spots as $tier): ?>
    <div class="tile <?= $tier['spots'] > 0 && $tier['confirmed'] >= $tier['spots'] ? 'red' : 'teal' ?>"><b><?= $tier['confirmed'] ?> / <?= $tier['spots'] ?></b><span><?= $e($tier['name']) ?> confirmed</span></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="toolbar tabs kind-tabs">
  <a class="btn <?= $kind === 'sponsor' ? '' : 'ghost' ?>" href="sponsors.php?kind=sponsor">🏆 Sponsors <span class="count"><?= $kindCounts['sponsor'] ?? 0 ?></span></a>
  <a class="btn <?= $kind === 'booth' ? '' : 'ghost' ?>" href="sponsors.php?kind=booth">🏪 Exhibition (booths) <span class="count"><?= $kindCounts['booth'] ?? 0 ?></span></a>
</div>
<div class="toolbar tabs">
  <a class="btn small <?= $filter === '' ? '' : 'ghost' ?>" href="sponsors.php?kind=<?= $kind ?>">All</a>
  <?php foreach (Sponsors::STATUSES as $status): ?><a class="btn small <?= $filter === $status ? '' : 'ghost' ?>" href="sponsors.php?kind=<?= $kind ?>&amp;status=<?= $status ?>"><?= $e(str_replace('_', ' ', ucfirst($status))) ?></a><?php endforeach; ?>
  <a class="btn small green" href="export.php?what=list&amp;list=<?= $kind === 'booth' ? 'exhibition' : 'sponsors' ?>">Export to Excel</a>
</div>
<div class="card table-wrap"><table>
  <tr><th>Reference</th><th>Company</th><th>Wants</th><th>Contact</th><th>Status</th><th>Handled by</th><th>Received</th></tr>
  <?php foreach ($rows as $row): ?>
  <tr><td><a href="sponsors.php?id=<?= (int) $row['id'] ?>"><code><?= $e($row['ref']) ?></code></a></td><td><a href="sponsors.php?id=<?= (int) $row['id'] ?>"><b><?= $e($row['company']) ?></b></a></td>
    <td><?= $row['kind'] === 'booth' ? 'Booth' : 'Sponsor' ?><?= $row['package'] ? ' · ' . $e($spots[$row['package']]['name'] ?? $row['package']) : '' ?></td>
    <td><?= $e($row['contact_name']) ?><br><small dir="ltr"><?= $e($row['phone']) ?></small></td><td><?= Page::pill($row['status']) ?></td>
    <td><?= $e($row['handler'] ?? '–') ?></td><td><?= Page::when($row['created_at']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $kind === 'booth' ? 'No booth requests.' : 'No sponsor requests.' ?></td></tr><?php endif; ?>
</table></div>
<?php Page::bottom();
