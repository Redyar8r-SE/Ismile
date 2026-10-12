<?php
// Psoola orders: every confirmed sale PSOOLA recorded for iSmile, card and
// cash, read live from PSOOLA (GET /orders). Each sale is matched to our
// registration by its reference, so a sale without a registration stands out.
// Ticket links are never shown or kept (PSOOLA's rule).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Db;
use Ismile\Payments\PsoolaCheckIns;
use Ismile\Payments\PsoolaGateway;
use Ismile\Registrations;

$user = Page::guard('payments');
$e = [Page::class, 'e'];
$channel = in_array($_GET['channel'] ?? '', ['checkout', 'direct'], true) ? (string) $_GET['channel'] : '';
$q = Page::query('q', 120);

$orders = [];
$problem = null;
if (!PsoolaCheckIns::active()) {
    $problem = 'This site does not sell through Psoola, so there are no Psoola orders to show.';
} else {
    try {
        $gateway = new PsoolaGateway();
        $cursor = null;
        $pages = 0;
        do {
            $page = $gateway->listOrders($cursor);
            array_push($orders, ...$page['orders']);
            $cursor = $page['next'];
        } while ($cursor !== null && ++$pages < 30);
    } catch (\Throwable $error) {
        App::log('error', 'Psoola orders could not be read', ['error' => $error->getMessage()]);
        $problem = 'Psoola could not be reached just now. Refresh the page in a minute.';
    }
}

// Our registrations for these sales, by reference.
$refs = array_values(array_unique(array_filter(array_map(static fn (array $o) => is_string($o['partnerReference'] ?? null) ? $o['partnerReference'] : '', $orders))));
$ours = [];
foreach (array_chunk($refs, 500) as $chunk) {
    foreach (Db::all('SELECT id, ref, status, first_name, father_name, grandfather_name FROM registrations WHERE ref IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
        $ours[$row['ref']] = $row;
    }
}

// Our names for PSOOLA's ticket types ("Professional" is our Dentist package).
$ourType = array_flip(array_filter((array) App::config('payments.psoola.ticket_types', []), 'is_string'));
$itemsText = static function (array $order) use ($ourType): string {
    $parts = [];
    foreach ((array) ($order['order']['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $name = isset($ourType[$item['ticketTypeId'] ?? '']) ? Registrations::ticketName($ourType[$item['ticketTypeId']]) : (string) ($item['name'] ?? 'Ticket');
        $text = $name . ((int) ($item['quantity'] ?? 1) > 1 ? ' ×' . (int) $item['quantity'] : '');
        $addOns = array_map(static fn ($a) => is_array($a) ? (string) ($a['name'] ?? '') : '', (array) ($item['addOns'] ?? []));
        $addOns = array_filter($addOns);
        $parts[] = $text . ($addOns ? ' + ' . implode(', ', $addOns) : '');
    }
    return implode(' · ', $parts) ?: '—';
};
$millis = static fn ($ms): ?string => is_int($ms) || is_float($ms) ? date('Y-m-d H:i:s', (int) floor($ms / 1000)) : null;

// Totals over every sale (before the filters).
$sum = ['orders' => 0, 'tickets' => 0, 'card' => 0, 'cash' => 0, 'cash_orders' => 0, 'used' => 0, 'missing' => 0];
foreach ($orders as $order) {
    $total = (int) ($order['order']['totalAmount'] ?? 0);
    $sum['orders']++;
    $sum['tickets'] += (int) ($order['order']['ticketCount'] ?? 0);
    if (($order['channel'] ?? '') === 'direct') {
        $sum['cash'] += $total;
        $sum['cash_orders']++;
    } else {
        $sum['card'] += $total;
    }
    foreach ((array) ($order['tickets'] ?? []) as $ticket) {
        if (is_array($ticket) && !empty($ticket['checkedIn'])) $sum['used']++;
    }
    if (!isset($ours[$order['partnerReference'] ?? ''])) $sum['missing']++;
}

// The filters.
$shown = array_values(array_filter($orders, static function (array $order) use ($channel, $q): bool {
    if ($channel !== '' && ($order['channel'] ?? '') !== $channel) return false;
    if ($q === '') return true;
    $haystack = mb_strtolower(implode(' ', [
        $order['id'] ?? '', $order['partnerReference'] ?? '',
        $order['buyer']['name'] ?? '', $order['buyer']['email'] ?? '', $order['buyer']['phone'] ?? '',
    ]));
    return str_contains($haystack, mb_strtolower($q)) || str_contains(preg_replace('/\D/', '', $haystack), preg_replace('/\D/', '', $q) ?: "\0");
}));

Page::top('Psoola orders', 'psoola-orders', '<a class="btn ghost" href="psoola-orders.php">' . Page::navIcon('clock') . '<span>Refresh from Psoola</span></a>');
if ($problem !== null) {
    echo '<div class="card">' . Page::emptyState('Psoola orders are not available', $problem, 'psoola-orders') . '</div>';
    Page::bottom();
    exit;
}
echo Page::stats([
    ['Sales', number_format($sum['orders']), number_format($sum['tickets']) . ' tickets · ' . number_format($sum['used']) . ' used at the door', 'psoola-orders', 'blue'],
    ['Paid on Psoola', number_format($sum['card']) . ' IQD', 'Card and wallet payments (FIB, FastPay, NassWallet, Qi)', 'payments', 'teal'],
    ['Cash sales', number_format($sum['cash']) . ' IQD', $sum['cash_orders'] . ($sum['cash_orders'] === 1 ? ' sale' : ' sales') . ' · iSmile owes Psoola this ticket price', 'check', 'green'],
    ['Not in our database', number_format($sum['missing']), $sum['missing'] ? 'Sales with no matching registration: check these' : 'Every sale has its registration', 'users', $sum['missing'] ? 'gold' : 'violet'],
]);
?>
<form method="get" class="card registration-filter-card">
  <div class="registration-filter-main">
    <label class="filter-field filter-search"><span>Name, email, phone or reference</span><input type="search" name="q" value="<?= $e($q) ?>" placeholder="Find a sale…"></label>
    <label class="filter-field"><span>Sale type</span><select name="channel"><option value="">Card and cash</option><option value="checkout"<?= $channel === 'checkout' ? ' selected' : '' ?>>Paid on Psoola</option><option value="direct"<?= $channel === 'direct' ? ' selected' : '' ?>>Cash sales</option></select></label>
  </div>
  <div class="registration-filter-actions"><span></span><div><a class="btn ghost" href="psoola-orders.php">Clear filters</a><button class="btn">Apply filters</button></div></div>
</form>
<section class="card registration-directory">
  <div class="panel-top"><?= Page::panelHeading('Sales recorded by Psoola', number_format(count($shown)) . ' matching sales · newest first. Read live from Psoola; refunds are made by Psoola.', 'psoola-orders') ?></div>
  <div class="table-wrap"><table class="psoola-orders"><thead><tr><th>Paid</th><th>Buyer</th><th>Bought</th><th>Total</th><th>Sale</th><th>Ticket email</th><th>At the door</th><th>Our registration</th></tr></thead><tbody>
  <?php foreach ($shown as $order):
      $paid = $millis($order['paidAt'] ?? null);
      $mail = (string) ($order['emailStatus'] ?? '');
      $tickets = array_filter((array) ($order['tickets'] ?? []), 'is_array');
      $used = count(array_filter($tickets, static fn ($t) => !empty($t['checkedIn'])));
      $mine = $ours[$order['partnerReference'] ?? ''] ?? null; ?>
    <tr>
      <td data-label="Paid"><?= $paid ? $e(Page::when($paid)) : '—' ?><small><code><?= $e((string) ($order['id'] ?? '')) ?></code></small></td>
      <td data-label="Buyer"><b><?= $e((string) ($order['buyer']['name'] ?? '')) ?></b><small dir="ltr"><?= $e((string) ($order['buyer']['email'] ?? '')) ?></small><small dir="ltr"><?= $e((string) ($order['buyer']['phone'] ?? '')) ?></small></td>
      <td data-label="Bought"><?= $e($itemsText($order)) ?></td>
      <td data-label="Total"><b><?= number_format((int) ($order['order']['totalAmount'] ?? 0)) ?></b> <?= $e((string) ($order['order']['currency'] ?? 'IQD')) ?></td>
      <td data-label="Sale"><?= ($order['channel'] ?? '') === 'direct' ? '<span class="psoola-channel cash">Cash</span>' : '<span class="psoola-channel">Paid on Psoola</span>' ?></td>
      <td data-label="Ticket email"><?php if ($mail === ''): ?><span class="muted">—</span><?php else: ?><span class="psoola-mail<?= in_array($mail, ['bounced', 'complained', 'failed', 'delayed'], true) ? ' bad' : '' ?>"><?= $e(ucfirst($mail)) ?></span><?php endif; ?></td>
      <td data-label="At the door"><?= $tickets ? $used . ' of ' . count($tickets) . ' used' : '—' ?></td>
      <td data-label="Our registration"><?php if ($mine): ?><a href="registration.php?id=<?= (int) $mine['id'] ?>"><?= $e(Registrations::fullName($mine)) ?></a><small><code><?= $e($mine['ref']) ?></code> · <?= Page::pill($mine['status']) ?></small><?php else: ?><span class="psoola-missing">Not in our database</span><small><code><?= $e((string) ($order['partnerReference'] ?? 'no reference')) ?></code></small><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$shown): ?><tr class="empty-row"><td colspan="8"><?= Page::emptyState($orders ? 'No sales match these filters' : 'No sales yet', $orders ? 'Clear a filter or search for another buyer.' : 'Sales paid on Psoola and cash sales appear here.', 'psoola-orders') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php Page::bottom();
