<?php
// Sponsors & exhibition booths: requests handled by a person.
//   New → Contacted (called) → Agreed (amount agreed on a call) → Paid (exact
//   amount) → Confirmed, or Declined / Waiting list.
// Every call is saved with its result and the amount told. A company cannot be
// Confirmed before it paid, and a package cannot take more companies than its
// places (the Owner can override the places, and it is logged).
// The Owner manages the packages and their prices at the bottom of the list.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Db;
use Ismile\SponsorPackages;
use Ismile\Sponsors;
use Ismile\UserError;

$user = Page::guard('sponsors');
$isOwner = $user['role'] === 'owner';
$id = (int) ($_GET['id'] ?? 0);
$kind = in_array(Page::query('kind'), ['sponsor', 'booth'], true) ? Page::query('kind') : 'sponsor';
$back = ($id > 0 ? 'sponsors.php?id=' . $id : 'sponsors.php?kind=' . $kind)
    . (str_starts_with((string) ($_POST['do'] ?? ''), 'pk_') ? '#packages' : '');

Page::action(static function () use ($user, $id): string {
    $do = (string) ($_POST['do'] ?? '');
    // ---- the packages and prices (Owner) ----
    if (str_starts_with($do, 'pk_')) {
        $package = (string) ($_POST['package'] ?? '');
        return match ($do) {
            'pk_create' => 'Package added: ' . SponsorPackages::name(SponsorPackages::find(SponsorPackages::create($_POST, $user))) . '.',
            'pk_update' => (static function () use ($package, $user): string {
                SponsorPackages::update($package, $_POST, $user);
                return 'Package saved.';
            })(),
            'pk_hide'   => (static function () use ($package, $user): string {
                SponsorPackages::setStatus($package, 'hidden', $user);
                return 'Hidden: no longer on the website or the form. Companies already on it stay.';
            })(),
            'pk_show'   => (static function () use ($package, $user): string {
                SponsorPackages::setStatus($package, 'active', $user);
                return 'Shown again.';
            })(),
            'pk_delete' => (static function () use ($package, $user): string {
                SponsorPackages::delete($package, $user);
                return 'Package deleted.';
            })(),
            default     => throw new UserError('Unknown action.'),
        };
    }
    // ---- one request ----
    $request = Sponsors::find($id) ?? throw new UserError('Request not found.');
    switch ($do) {
        case 'call':
            return Sponsors::logCall($id, $_POST, $user);
        case 'pay':
            Sponsors::recordPayment($id, $_POST, $user);
            return 'Payment recorded. Last step: press the green Confirm button.';
        case 'unpay':
            Sponsors::undoPayment($id, $user);
            return 'Payment removed. The request is back to Agreed.';
        case 'status':
            Sponsors::changeStatus($request, (string) ($_POST['status'] ?? ''), $user, ($_POST['override'] ?? '') === '1');
            return 'Status changed.';
        case 'details':
            Sponsors::saveDetails($id, $_POST, $user);
            return 'Saved.';
    }
    throw new UserError('Unknown action.');
}, $back);

$e = [Page::class, 'e'];
$money = static fn ($amount): string => $amount === null || $amount === '' ? '–' : Page::money((int) $amount);
$inputTime = static fn (?string $at): string => $at ? date('Y-m-d\TH:i', (int) strtotime($at)) : '';
$now = App::now();
$staff = Db::all("SELECT id, name FROM admin_users WHERE disabled_at IS NULL AND role IN ('owner','registration','finance') ORDER BY name");

// =========================== one request ===========================
if ($id > 0 && ($request = Sponsors::find($id))) {
    $package = SponsorPackages::find($request['package_id']);
    $packages = SponsorPackages::all($request['kind']);
    $calls = Sponsors::calls($id);
    $history = Db::all("SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id WHERE target_type = 'sponsor_request' AND target_id = ? ORDER BY a.id DESC LIMIT 50", [$id]);
    $isBooth = $request['kind'] === 'booth';
    $due = $request['next_call_at'] && $request['next_call_at'] <= $now && !in_array($request['status'], ['confirmed', 'declined'], true);
    Page::top($request['company'], 'sponsors');
    ?>
    <div class="toolbar"><a class="btn ghost" href="sponsors.php?kind=<?= $e($request['kind']) ?>">← All <?= $isBooth ? 'exhibition requests' : 'sponsor requests' ?></a><span><code class="big"><?= $e($request['ref']) ?></code> <?= Page::pill($request['status']) ?></span></div>
    <?php
    // ---- the progress bar: where this company is ----
    $stepOf = ['new' => 1, 'contacted' => 2, 'waiting_list' => 2, 'agreed' => 3, 'paid' => 4, 'confirmed' => 5, 'declined' => 0];
    $current = $stepOf[$request['status']] ?? 1;
    $stepNames = [1 => 'New request', 2 => 'Called', 3 => 'Price agreed', 4 => 'Paid', 5 => 'Confirmed'];
    $agreed = $request['amount_agreed'] !== null ? Page::money((int) $request['amount_agreed']) : '';
    $callForm = static function (bool $first) use ($e, $package): string {
        $choices = $first
            ? ['agreed' => ['✅', 'They agreed a price'], 'interested' => ['👍', 'Interested, not decided'], 'call_back' => ['⏰', 'Call back later'], 'no_answer' => ['📵', 'No answer'], 'declined' => ['❌', 'Not interested']]
            : array_map(static fn ($label) => ['', $label], Sponsors::OUTCOMES);
        $tiles = '';
        foreach ($choices as $key => [$icon, $label]) {
            $tiles .= '<label class="choice"><input type="radio" name="outcome" value="' . $key . '" required><span>' . ($icon !== '' ? '<b>' . $icon . '</b>' : '') . $e($label) . '</span></label>';
        }
        $hint = $package && $package['price'] > 0 ? ' (list price ' . number_format((int) $package['price']) . ')' : '';
        return '<form method="post" class="stack">' . Page::csrfField() . '<input type="hidden" name="do" value="call">'
            . '<div class="choices">' . $tiles . '</div>'
            . '<div class="row3">'
            . '<label>Price in IQD' . $e($hint) . '<input name="amount" inputmode="numeric" placeholder="e.g. 6000000"></label>'
            . '<label>Call again on (optional)<input type="datetime-local" name="next_call_at"></label>'
            . '<label>Note (optional)<input name="note" maxlength="500" placeholder="what they said"></label>'
            . '</div><button class="btn green big-btn">Save</button>'
            . '<p class="muted small">"They agreed a price" needs the price.</p></form>';
    };
    ?>
    <?php if ($current > 0): ?>
    <ol class="tracker">
      <?php foreach ($stepNames as $n => $name): ?>
        <li class="<?= $n < $current || $current === 5 ? 'done' : ($n === $current ? 'now' : '') ?>"><b><?= $n < $current || $current === 5 ? '✓' : $n ?></b><span><?= $e($name) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <div class="card next-step">
      <?php if ($request['status'] === 'new' || $request['status'] === 'contacted' || $request['status'] === 'waiting_list'): ?>
        <h2>👉 What to do now: call them and agree on a price</h2>
        <p class="lead-line">Call <b><?= $e($request['contact_name']) ?></b> from <b><?= $e($request['company']) ?></b>
          <a class="btn green" href="tel:<?= $e($request['phone']) ?>" dir="ltr">📞 <?= $e($request['phone']) ?></a></p>
        <p>They want: <b><?= $package ? $e(SponsorPackages::name($package)) : 'not sure yet' ?></b><?= $package && $package['price'] > 0 ? ', list price <b>' . Page::money((int) $package['price']) . '</b>' : '' ?>.
          <?= $request['status'] === 'waiting_list' ? '<span class="pill violet">on the waiting list</span>' : '' ?></p>
        <h3>After the call, what happened?</h3>
        <?= $callForm(true) ?>
      <?php elseif ($request['status'] === 'agreed'): ?>
        <h2>👉 What to do now: wait for the money, then record it</h2>
        <p class="lead-line">They agreed to pay <b class="big-money"><?= $agreed ?></b>. When the money arrives, press the button:</p>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pay">
          <input type="hidden" name="amount_paid" value="<?= (int) $request['amount_agreed'] ?>">
          <div class="choices">
            <?php foreach (Sponsors::PAID_HOW as $key => $label): ?><label class="choice"><input type="radio" name="paid_how" value="<?= $key ?>" required><span><b><?= ['cash' => '💵', 'transfer' => '🏦', 'psoola' => '💳', 'other' => '📝'][$key] ?></b><?= $e($label) ?></span></label><?php endforeach; ?>
          </div>
          <button class="btn green big-btn">💰 They paid <?= $agreed ?></button>
        </form>
        <details class="more"><summary>They want to pay a different amount?</summary>
          <p class="muted">Save a new call with "They agreed a price" and the new price. Then record the payment.</p>
          <?= $callForm(true) ?>
        </details>
      <?php elseif ($request['status'] === 'paid'): ?>
        <h2>👉 Last step: confirm them</h2>
        <p class="lead-line">They paid <b class="big-money"><?= Page::money((int) $request['amount_paid']) ?></b> (<?= $e(Sponsors::PAID_HOW[$request['paid_how']] ?? '') ?>, <?= Page::when($request['paid_at']) ?>).</p>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="confirmed">
          <?php $spot = $package ? (Sponsors::spots()[$package['id']] ?? null) : null; $full = $spot && $spot['spots'] > 0 && $spot['confirmed'] >= $spot['spots']; ?>
          <?php if ($full): ?><p class="flash warn">All <?= (int) $spot['spots'] ?> <?= $e($spot['name']) ?> places are taken.<?= $isOwner ? ' Tick the box to confirm anyway.' : ' Only the Owner can confirm more.' ?></p>
            <?php if ($isOwner): ?><label class="inline"><input type="checkbox" name="override" value="1"> Confirm anyway (Owner)</label><?php endif; ?><?php endif; ?>
          <button class="btn green big-btn">✅ Confirm <?= $e($request['company']) ?></button>
        </form>
        <?php if ($isOwner): ?><form method="post" style="margin-top:14px"><?= Page::csrfField() ?><input type="hidden" name="do" value="unpay"><button class="btn small ghost" data-confirm="Remove this payment? Only if it was recorded by mistake.">Payment recorded by mistake? Undo it (Owner)</button></form><?php endif; ?>
      <?php elseif ($request['status'] === 'confirmed'): ?>
        <h2>🎉 Done: <?= $e($request['company']) ?> is confirmed</h2>
        <p class="lead-line">Paid <b><?= Page::money((int) $request['amount_paid']) ?></b>.</p>
        <ul>
          <?php if ($isBooth): ?><li><?= $request['booth_number'] ? 'Their booth number is <b>' . $e($request['booth_number']) . '</b>.' : 'Give them a <b>booth number</b> (in "Details" below).' ?></li>
          <?php else: ?><li>Add their <b>logo</b> to the website: Site content → Sponsors & partners → Sponsors with a logo.</li><?php endif; ?>
        </ul>
      <?php else: ?>
        <h2>❌ Declined</h2>
        <p class="lead-line">This company said no, or was declined.</p>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="new"><button class="btn">They came back: start again</button></form>
      <?php endif; ?>
    </div>

    <?php if ($due): ?><div class="flash warn">📞 A call is due since <?= Page::when($request['next_call_at']) ?>.</div><?php endif; ?>
    <div class="grid2">
      <div class="card">
        <h2><?= $isBooth ? '🏪 Exhibition booth' : '🏆 Sponsorship' ?>: <?= $package ? $e(SponsorPackages::name($package)) : '<span class="muted">package not chosen yet</span>' ?></h2>
        <dl class="facts">
          <dt>Company</dt><dd><b><?= $e($request['company']) ?></b></dd>
          <dt>Contact</dt><dd><?= $e($request['contact_name']) ?><?= $request['contact_role'] ? ', ' . $e($request['contact_role']) : '' ?></dd>
          <dt>Phone</dt><dd dir="ltr"><a href="tel:<?= $e($request['phone']) ?>"><?= $e($request['phone']) ?></a></dd>
          <dt>Email</dt><dd dir="ltr"><a href="mailto:<?= $e($request['email']) ?>"><?= $e($request['email']) ?></a></dd>
          <dt>Website</dt><dd dir="ltr"><?= $e($request['website'] ?? '–') ?></dd>
          <dt>City</dt><dd><?= $e($request['city'] ?? '–') ?></dd>
          <dt>Speaks</dt><dd><?= $e(['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'][$request['lang']] ?? $request['lang']) ?></dd>
          <?php if ($request['booth_number']): ?><dt>Booth number</dt><dd><b><?= $e($request['booth_number']) ?></b></dd><?php endif; ?>
          <dt>Received</dt><dd><?= Page::when($request['created_at']) ?></dd>
          <dt>Price told</dt><dd><?= $money($request['price_quoted']) ?></dd>
          <dt>Agreed</dt><dd><b><?= $money($request['amount_agreed']) ?></b></dd>
          <dt>Paid</dt><dd><?= $request['amount_paid'] !== null ? '<b class="txt-ok">' . $money($request['amount_paid']) . '</b> · ' . $e(Sponsors::PAID_HOW[$request['paid_how']] ?? '') . ', ' . Page::when($request['paid_at']) : 'not paid' ?></dd>
        </dl>
        <?php if ($request['message']): ?><h3>Their message</h3><p class="message"><?= nl2br($e($request['message'])) ?></p><?php endif; ?>
      </div>
      <div class="card">
        <h2>Details</h2>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="details">
          <label><?= $isBooth ? 'Booth type' : 'Package' ?><select name="package_id"><option value="">Not chosen yet</option>
            <?php foreach ($packages as $option): ?><option value="<?= $e($option['id']) ?>"<?= $option['id'] === $request['package_id'] ? ' selected' : '' ?>><?= $e($option['name_en']) ?><?= $option['price'] > 0 ? ' · ' . number_format((int) $option['price']) . ' IQD' : '' ?><?= $option['status'] === 'hidden' ? ' (hidden)' : '' ?></option><?php endforeach; ?></select></label>
          <div class="row3">
            <label>Booth number<input name="booth_number" maxlength="20" value="<?= $e($request['booth_number'] ?? '') ?>" placeholder="<?= $isBooth ? 'e.g. B12' : 'if they have a booth' ?>"></label>
            <label>Handled by<select name="assigned_to"><option value="0">Nobody yet</option>
              <?php foreach ($staff as $person): ?><option value="<?= (int) $person['id'] ?>"<?= (int) $request['assigned_to'] === (int) $person['id'] ? ' selected' : '' ?>><?= $e($person['name']) ?></option><?php endforeach; ?></select></label>
            <label>Next call<input type="datetime-local" name="next_call_at" value="<?= $e($inputTime($request['next_call_at'])) ?>"></label>
          </div>
          <label>Notes<textarea name="notes" rows="4"><?= $e($request['notes'] ?? '') ?></textarea></label>
          <button class="btn">Save</button>
        </form>
      </div>
    </div>
    <details class="card more">
      <summary>More actions: save another call, waiting list, decline…</summary>
      <?php if (!in_array($request['status'], ['new', 'contacted', 'waiting_list'], true)): ?><h3>Save a call</h3><?= $callForm(false) ?><?php endif; ?>
      <h3>Move by hand</h3>
      <form method="post" class="status-buttons"><?= Page::csrfField() ?><input type="hidden" name="do" value="status">
        <?php foreach (['new' => 'Back to New', 'contacted' => 'Called', 'waiting_list' => 'Waiting list', 'declined' => 'Declined'] as $status => $label): ?>
          <?php if ($status !== $request['status']): ?><button class="btn small ghost" name="status" value="<?= $status ?>"><?= $e($label) ?></button><?php endif; ?>
        <?php endforeach; ?>
      </form>
      <p class="muted small">Once a company has paid, only the Owner can move it back or decline it (no refunds).</p>
    </details>
    <div class="card"><h2>📞 Calls (<?= count($calls) ?>)</h2><div class="table-wrap"><table>
      <tr><th>When</th><th>Who called</th><th>Result</th><th>Amount told</th><th>Note</th><th>Call again</th></tr>
      <?php foreach ($calls as $call): ?>
      <tr><td><?= Page::when($call['called_at']) ?></td><td><?= $e($call['caller'] ?? '–') ?></td><td><b><?= $e(Sponsors::OUTCOMES[$call['outcome']] ?? $call['outcome']) ?></b></td>
        <td><?= $money($call['amount_quoted']) ?></td><td><?= $e($call['note'] ?? '') ?></td><td><?= $call['next_call_at'] ? Page::when($call['next_call_at']) : '–' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$calls): ?><tr><td colspan="6" class="muted">Nobody has called them yet.</td></tr><?php endif; ?>
    </table></div></div>
    <div class="card"><h2>History</h2><ul class="history">
      <?php foreach ($history as $item): ?><li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'system') ?> · <?= $e($item['action']) ?> <span class="muted small"><?= $e((string) $item['details']) ?></span></li><?php endforeach; ?>
      <?php if (!$history): ?><li class="muted">No changes yet.</li><?php endif; ?>
    </ul></div>
    <?php
    Page::bottom();
    exit;
}

// =========================== the list ===========================
$filter = Page::query('status');
$dueOnly = Page::query('due') === '1';
$where = ['s.kind = ?'];
$params = [$kind];
if ($filter !== '' && in_array($filter, Sponsors::STATUSES, true)) {
    $where[] = 's.status = ?';
    $params[] = $filter;
}
if ($dueOnly) {
    $where[] = "s.next_call_at IS NOT NULL AND s.next_call_at <= ? AND s.status NOT IN ('confirmed','declined')";
    $params[] = $now;
}
$rows = Db::all("SELECT s.*, u.name AS handler, p.name_en AS package_name FROM sponsor_requests s
                 LEFT JOIN admin_users u ON u.id = s.assigned_to LEFT JOIN sponsor_packages p ON p.id = s.package_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY FIELD(s.status, 'new', 'contacted', 'agreed', 'paid', 'waiting_list', 'confirmed', 'declined'), s.next_call_at IS NULL, s.next_call_at, s.id DESC LIMIT 500", $params);
$kindCounts = [];
foreach (Db::all('SELECT kind, COUNT(*) AS n FROM sponsor_requests GROUP BY kind') as $row) {
    $kindCounts[$row['kind']] = (int) $row['n'];
}
$callsDue = Sponsors::callsDue($kind);
$packages = SponsorPackages::all($kind);
Page::top('Sponsors & booths', 'sponsors');
?>
<div class="toolbar tabs kind-tabs">
  <a class="btn <?= $kind === 'sponsor' ? '' : 'ghost' ?>" href="sponsors.php?kind=sponsor">🏆 Sponsors <span class="count"><?= $kindCounts['sponsor'] ?? 0 ?></span></a>
  <a class="btn <?= $kind === 'booth' ? '' : 'ghost' ?>" href="sponsors.php?kind=booth">🏪 Exhibition (booths) <span class="count"><?= $kindCounts['booth'] ?? 0 ?></span></a>
  <?php if ($callsDue): ?><a class="btn red" href="sponsors.php?kind=<?= $kind ?>&amp;due=1">📞 Calls due <span class="count"><?= $callsDue ?></span></a><?php endif; ?>
</div>
<div class="tiles small">
  <?php foreach ($packages as $package): if ($package['status'] !== 'active') { continue; } $full = $package['places'] > 0 && $package['confirmed'] >= $package['places']; ?>
    <div class="tile <?= $full ? 'red' : 'teal' ?>"><b><?= (int) $package['confirmed'] ?> / <?= $package['places'] > 0 ? (int) $package['places'] : '∞' ?></b><span><?= $e($package['name_en']) ?> confirmed</span><small><?= $package['price'] > 0 ? Page::money((int) $package['price']) : 'price not set' ?></small></div>
  <?php endforeach; ?>
</div>
<div class="toolbar tabs">
  <a class="btn small <?= $filter === '' && !$dueOnly ? '' : 'ghost' ?>" href="sponsors.php?kind=<?= $kind ?>">All</a>
  <?php foreach (Sponsors::STATUSES as $status): ?><a class="btn small <?= $filter === $status ? '' : 'ghost' ?>" href="sponsors.php?kind=<?= $kind ?>&amp;status=<?= $status ?>"><?= $e(str_replace('_', ' ', ucfirst($status))) ?></a><?php endforeach; ?>
  <a class="btn small green" href="export.php?what=list&amp;list=<?= $kind === 'booth' ? 'exhibition' : 'sponsors' ?>">Export to Excel</a>
</div>
<div class="card table-wrap"><table>
  <tr><th>Company</th><th><?= $kind === 'booth' ? 'Booth' : 'Package' ?></th><th>Status</th><th>Agreed / paid</th><th>Next call</th><th>Handled by</th><th>Received</th></tr>
  <?php foreach ($rows as $row): $due = $row['next_call_at'] && $row['next_call_at'] <= $now && !in_array($row['status'], ['confirmed', 'declined'], true); ?>
  <tr><td><a href="sponsors.php?id=<?= (int) $row['id'] ?>"><b><?= $e($row['company']) ?></b></a><br><small><?= $e($row['contact_name']) ?> · <span dir="ltr"><?= $e($row['phone']) ?></span></small></td>
    <td><?= $row['package_name'] ? $e($row['package_name']) : '<span class="muted">not chosen</span>' ?><?= $row['booth_number'] ? '<br><small>Booth ' . $e($row['booth_number']) . '</small>' : '' ?></td>
    <td><?= Page::pill($row['status']) ?></td>
    <td><?= $money($row['amount_agreed']) ?><?= $row['amount_paid'] !== null ? '<br><small class="txt-ok">paid ' . $money($row['amount_paid']) . '</small>' : '' ?></td>
    <td><?= $row['next_call_at'] ? ($due ? '<b class="txt-bad">📞 ' . Page::when($row['next_call_at']) . '</b>' : Page::when($row['next_call_at'])) : '–' ?></td>
    <td><?= $e($row['handler'] ?? '–') ?></td><td><?= Page::when($row['created_at']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted"><?= $kind === 'booth' ? 'No booth requests here.' : 'No sponsor requests here.' ?></td></tr><?php endif; ?>
</table></div>

<?php if ($isOwner):
    $fieldsFor = static function (?array $p) use ($e): string {
        $v = static fn (string $key): string => $e((string) ($p[$key] ?? ''));
        $styles = '';
        foreach (SponsorPackages::STYLES as $key => $label) {
            $styles .= '<option value="' . $key . '"' . (($p['style'] ?? 'tc-silver') === $key ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        return '<div class="row3">'
            . '<label>Name (English)<input name="name_en" required maxlength="80" value="' . $v('name_en') . '"></label>'
            . '<label>Name (Arabic)<input name="name_ar" dir="rtl" maxlength="80" value="' . $v('name_ar') . '"></label>'
            . '<label>Name (Kurdish)<input name="name_ku" dir="rtl" maxlength="80" value="' . $v('name_ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Subtitle (English, optional)<input name="subtitle_en" maxlength="160" value="' . $v('subtitle_en') . '"></label>'
            . '<label>Subtitle (Arabic)<input name="subtitle_ar" dir="rtl" maxlength="160" value="' . $v('subtitle_ar') . '"></label>'
            . '<label>Subtitle (Kurdish)<input name="subtitle_ku" dir="rtl" maxlength="160" value="' . $v('subtitle_ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Price (IQD; empty = not set yet)<input name="price" inputmode="numeric" placeholder="e.g. 5000000" value="' . ($p && $p['price'] > 0 ? (int) $p['price'] : '') . '"></label>'
            . '<label>Places (empty = no limit)<input type="number" min="0" max="500" name="places" placeholder="no limit" value="' . ($p && $p['places'] > 0 ? (int) $p['places'] : '') . '"></label>'
            . '<label>Colour on the website<select name="style">' . $styles . '</select></label></div>';
    }; ?>
<div class="card" id="packages">
  <h2>⚙ <?= $kind === 'booth' ? 'Booth types' : 'Sponsor packages' ?> and prices (Owner)</h2>
  <p class="muted">The price is what the team tells companies on the phone; it is never shown on the website. <?= $kind === 'sponsor' ? 'The website\'s sponsor cards (name, subtitle, places, colour) change at once.' : 'Booth types are not shown on the website: companies ask for "a booth" and you choose the type on the call.' ?></p>
  <div class="table-wrap"><table>
    <tr><th>Package</th><th>Price</th><th>Places</th><th>Confirmed</th><th>Shown</th><th></th></tr>
    <?php foreach ($packages as $p): ?>
    <tr>
      <td><b><?= $e($p['name_en']) ?></b><br><small class="muted"><?= $e($p['id']) ?><?= $p['subtitle_en'] ? ' · ' . $e($p['subtitle_en']) : '' ?></small></td>
      <td><?= $p['price'] > 0 ? Page::money((int) $p['price']) : '<span class="txt-bad">not set</span>' ?></td>
      <td><?= $p['places'] > 0 ? (int) $p['places'] : 'no limit' ?></td>
      <td><?= (int) $p['confirmed'] ?> <small class="muted">· <?= (int) $p['requests'] ?> asked</small></td>
      <td><?= $p['status'] === 'active' ? '<span class="pill green">Shown</span>' : '<span class="pill grey">Hidden</span>' ?></td>
      <td>
        <details class="edit"><summary class="btn small ghost">Edit</summary>
          <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pk_update"><input type="hidden" name="package" value="<?= $e($p['id']) ?>">
            <?= $fieldsFor($p) ?><button class="btn">Save</button></form>
        </details>
        <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="package" value="<?= $e($p['id']) ?>">
          <?php if ($p['status'] === 'active'): ?><button class="btn small gold" name="do" value="pk_hide" data-confirm="Hide this package? It leaves the website and the form. Companies already on it stay.">Hide</button>
          <?php else: ?><button class="btn small green" name="do" value="pk_show">Show again</button><?php endif; ?>
          <?php if ((int) $p['requests'] === 0): ?><button class="btn small red" name="do" value="pk_delete" data-confirm="Delete this package for good?">Delete</button><?php endif; ?>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$packages): ?><tr><td colspan="6" class="muted">No packages yet.</td></tr><?php endif; ?>
  </table></div>
  <details class="add-new" <?= $packages ? '' : 'open' ?>>
    <summary class="btn green">+ Add a <?= $kind === 'booth' ? 'booth type' : 'sponsor package' ?></summary>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pk_create"><input type="hidden" name="kind" value="<?= $kind ?>">
      <?= $fieldsFor(null) ?>
      <button class="btn green">Add</button>
    </form>
  </details>
</div>
<?php endif; ?>
<?php Page::bottom();
