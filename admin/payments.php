<?php
// Payments: every attempt with the company's id, method, amount and status;
// the flagged ones (wrong amount, paid twice) in red; a daily total to compare
// with Psoola's settlement report; closing reviewed problems (iSmile does not
// refund); the webhook log.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Payments\Payments;
use Ismile\Registrations;
use Ismile\UserError;

$user = Page::guard('payments');

Page::action(static function () use ($user): string {
    $payment = Payments::find((int) ($_POST['id'] ?? 0));
    if ($payment === null) {
        throw new UserError('Payment not found.');
    }
    if (($_POST['do'] ?? '') === 'check') {
        $status = Payments::check($payment);
        return "Asked the payment company about #{$payment['id']}: $status.";
    }
    if (($_POST['do'] ?? '') === 'kept') {
        // iSmile does not refund. Finance looks at a wrong-amount or double
        // payment, talks to the person, writes what was agreed, and closes it.
        if (!in_array($payment['status'], ['duplicate', 'mismatch'], true)) {
            throw new UserError('Only a wrong-amount or double payment needs this.');
        }
        $note = Page::post('note');
        if ($note === '') {
            throw new UserError('Write what was agreed with the person (no refunds are made).');
        }
        Db::update('payments', ['status' => 'kept', 'last_error' => 'Reviewed, no refund: ' . $note, 'updated_at' => App::now()], 'id = ?', [$payment['id']]);
        Audit::log((int) $user['id'], 'payment.kept', 'payment', (int) $payment['id'], ['note' => $note]);
        return "Payment #{$payment['id']} reviewed and closed (no refund).";
    }
    throw new UserError('Unknown action.');
}, 'payments.php' . (isset($_GET['status']) ? '?status=' . rawurlencode((string) $_GET['status']) : ''));

$filter = Page::query('status');
$where = match ($filter) {
    'flagged' => "p.status IN ('mismatch','duplicate')",
    'paid', 'waiting', 'failed', 'expired', 'kept' => 'p.status = ' . App::db()->quote($filter),
    default   => '1 = 1',
};
// A payment belongs to a registration once paid; before that, to the form
// waiting for payment (which is not a registration).
$rows = Db::all("SELECT p.*, COALESCE(r.ref, c.ref) AS ref, COALESCE(r.first_name, c.first_name) AS first_name, COALESCE(r.father_name, c.father_name) AS father_name,
                        COALESCE(r.grandfather_name, c.grandfather_name) AS grandfather_name
                 FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id LEFT JOIN checkouts c ON c.id = p.checkout_id
                 WHERE $where ORDER BY p.id DESC LIMIT 300");
// Per day: ticket money, and money that arrived without a ticket (wrong amount,
// paid twice). Together they should equal Psoola's settlement for the day.
$daily = Db::all("SELECT DATE(confirmed_at) AS day, currency,
                         SUM(status = 'paid') AS n, COALESCE(SUM(IF(status = 'paid', amount_confirmed, 0)), 0) AS total,
                         COALESCE(SUM(IF(status IN ('mismatch','duplicate','kept'), amount_confirmed, 0)), 0) AS held
                  FROM payments WHERE confirmed_at IS NOT NULL AND status IN ('paid','mismatch','duplicate','kept')
                  GROUP BY DATE(confirmed_at), currency ORDER BY day DESC LIMIT 60");
$hooks = Db::all('SELECT * FROM webhook_log ORDER BY id DESC LIMIT 50');
$flagged = (int) Db::value("SELECT COUNT(*) FROM payments WHERE status IN ('mismatch','duplicate')");
$paymentSummary = Db::one("SELECT COUNT(*) AS attempts, COALESCE(SUM(status='paid'),0) AS paid, COALESCE(SUM(status IN ('created','waiting')),0) AS waiting FROM payments");

Page::top('Payments', 'payments');
$e = [Page::class, 'e'];
?>
<?= Page::stats([
    ['Confirmed payments', number_format((int)$paymentSummary['paid']), 'Successful ticket payments', 'payments', 'green'],
    ['Waiting for payment', number_format((int)$paymentSummary['waiting']), 'Created and waiting attempts', 'clock', 'gold'],
    ['Needs Finance', number_format($flagged), 'Wrong amount or duplicate payment', 'search', $flagged ? 'red' : 'blue'],
    ['Payment attempts', number_format((int)$paymentSummary['attempts']), 'All recorded payment attempts', 'registrations', 'violet'],
]) ?>
<div class="toolbar tabs segmented-tabs">
  <?php foreach (['' => 'All', 'flagged' => "Needs Finance ($flagged)", 'paid' => 'Paid', 'waiting' => 'Waiting', 'failed' => 'Failed', 'expired' => 'Expired', 'kept' => 'Reviewed (kept)'] as $value => $label): ?>
    <a class="btn small <?= $filter === $value ? '' : 'ghost' ?><?= $value === 'flagged' && $flagged ? ' red' : '' ?>"<?= $filter === $value ? ' aria-current="page"' : '' ?> href="payments.php<?= $value !== '' ? '?status=' . $value : '' ?>"><?= $e($label) ?></a>
  <?php endforeach; ?>
</div>

<section class="card ledger-panel">
<div class="panel-top"><?= Page::panelHeading('Payment ledger', 'Review each attempt, the amount received and any action needed.', 'payments') ?><div class="panel-actions"><a class="btn green" href="export.php?what=payments">Export to Excel</a></div></div>
<div class="table-wrap">
<table>
  <tr><th>#</th><th>Person</th><th>Method</th><th>Expected</th><th>Confirmed</th><th>Status</th><th>Company id</th><th>Started</th><th class="table-action-heading">Actions</th></tr>
  <?php foreach ($rows as $row): ?>
  <tr class="<?= in_array($row['status'], ['mismatch', 'duplicate'], true) ? 'row-red' : '' ?>">
    <td><?= (int) $row['id'] ?></td>
    <td><?php if ($row['registration_id']): ?><a href="registration.php?id=<?= (int) $row['registration_id'] ?>"><code><?= $e($row['ref']) ?></code></a><?php else: ?><code><?= $e($row['ref'] ?? '–') ?></code> <span class="muted small">not registered</span><?php endif; ?>
      <br><small><?= $row['first_name'] !== null ? $e(Registrations::fullName($row)) : '<span class="muted">form deleted</span>' ?></small></td>
    <td><?= $e(strtoupper((string) $row['method'])) ?></td>
    <td><?= Page::money((int) $row['amount_expected'], $row['currency']) ?></td>
    <td><?= Page::money($row['amount_confirmed'] === null ? null : (int) $row['amount_confirmed'], $row['currency']) ?></td>
    <td><?= Page::pill($row['status']) ?><?= $row['last_error'] ? '<br><small class="muted">' . $e($row['last_error']) . '</small>' : '' ?></td>
    <td><code><?= $e($row['provider_payment_id'] ?? '–') ?></code><br><small class="muted"><?= $e($row['gateway']) ?></small></td>
    <td><?= Page::when($row['created_at']) ?></td>
    <td class="table-action-cell"><div class="row-actions">
      <?php if (in_array($row['status'], ['created', 'waiting'], true)): ?>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="check"><button class="btn small ghost">Check now</button></form>
      <?php elseif (in_array($row['status'], ['duplicate', 'mismatch'], true)): ?>
        <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="kept">
          <input name="note" aria-label="What was agreed with the customer" placeholder="What was agreed (no refund)" required><div class="form-actions compact"><button class="btn small violet">Reviewed, close</button></div></form>
      <?php endif; ?>
    </div></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="muted"><?= Page::emptyState('No payments in this view', 'Payment attempts will appear here when guests start paying. Choose All to see every status.', 'payments') ?></td></tr><?php endif; ?>
</table>
</div>
</section>

<div class="grid2">
  <div class="card">
    <?= Page::panelHeading('Daily settlement', 'Confirmed ticket money and held payments, grouped by day.', 'calendar') ?>
    <p class="muted small">Compare each day with Psoola's settlement report: <b>Tickets + Held</b> should equal what Psoola paid. "Held" is money that arrived without a ticket (wrong amount or paid twice), see the red rows.</p>
    <div class="table-wrap"><table><tr><th>Day</th><th>Payments</th><th>Tickets</th><th>Held</th><th>Total received</th></tr>
      <?php foreach ($daily as $day): ?><tr><td><?= $e($day['day']) ?></td><td><?= (int) $day['n'] ?></td><td><?= Page::money((int) $day['total'], $day['currency']) ?></td>
        <td><?= (int) $day['held'] ? '<b class="red-text">' . Page::money((int) $day['held'], $day['currency']) . '</b>' : '–' ?></td><td><b><?= Page::money((int) $day['total'] + (int) $day['held'], $day['currency']) ?></b></td></tr><?php endforeach; ?>
      <?php if (!$daily): ?><tr><td colspan="5" class="muted">No confirmed payments yet.</td></tr><?php endif; ?>
    </table></div>
  </div>
  <div class="card" id="webhooks">
    <?= Page::panelHeading('Payment company messages', 'Recent confirmations and delivery results.', 'mail') ?>
    <p class="muted small">The first place to look when someone says "I paid but got nothing".</p>
    <div class="table-wrap"><table><tr><th>When</th><th>Payment</th><th>Signature</th><th>Result</th></tr>
      <?php foreach ($hooks as $hook): ?>
        <tr class="<?= str_starts_with($hook['outcome'], 'REJECTED') ? 'row-red' : '' ?>"><td><?= Page::when($hook['received_at']) ?></td><td><code><?= $e($hook['provider_payment_id'] ?? '–') ?></code></td>
          <td><?= $hook['signature_ok'] ? Page::pill('confirmed') : Page::pill('failed') ?></td><td><?= $e($hook['outcome']) ?><br><small class="muted"><?= $e($hook['ip']) ?></small></td></tr>
      <?php endforeach; ?>
      <?php if (!$hooks): ?><tr><td colspan="4" class="muted">None yet.</td></tr><?php endif; ?>
    </table></div>
  </div>
</div>
<?php Page::bottom();
