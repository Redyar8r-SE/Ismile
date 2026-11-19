<?php
// Psoola orders: every confirmed sale PSOOLA recorded for iSmile, card and
// cash, read live from PSOOLA (GET /orders). Each sale is matched to our
// registration by its reference, so a sale without a registration stands out.
// Ticket links are never shown or kept (PSOOLA's rule).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\PsoolaOrderSummary;
use Ismile\App;
use Ismile\Db;
use Ismile\Payments\PsoolaCheckIns;
use Ismile\Payments\PsoolaGateway;
use Ismile\Registrations;

$user = Page::guard('payments');
$e = [Page::class, 'e'];
// The filters (owner, 19 Nov 2026): Ticket email (incl. "Not received"), Sale type, Ticket and At the door.
$chosen = [];
foreach (PsoolaOrderSummary::FILTERS as $group => $choices) {
    $chosen[$group] = is_string($_GET[$group] ?? null) && isset($choices[$_GET[$group]]) ? $_GET[$group] : '';
}
$q = Page::query('q', 120);
// A link to this page with one filter changed (the others and the search are kept).
$link = static function (array $change) use ($chosen, $q): string {
    $query = array_filter(['q' => $q] + array_replace($chosen, $change), static fn ($v): bool => $v !== '' && $v !== null);
    return 'psoola-orders.php' . ($query ? '?' . http_build_query($query) : '');
};

// How many rows to draw: 100 first, then "Show more" adds 200 (a long table made the page slow).
$rows = max(100, min(5000, (int) Page::query('rows')));
$orders = [];
$readAt = time();
$problem = null;
if (!PsoolaCheckIns::active()) {
    $problem = 'This site does not sell through Psoola, so there are no Psoola orders to show.';
} else {
    try {
        // PSOOLA's list is kept for one minute (fast filters); "Refresh from Psoola" reads it again.
        $fetchAll = static function (): array {
            $gateway = new PsoolaGateway();
            $all = [];
            $cursor = null;
            $pages = 0;
            do {
                $page = $gateway->listOrders($cursor);
                array_push($all, ...$page['orders']);
                $cursor = $page['next'];
            } while ($cursor !== null && ++$pages < 30);
            return $all;
        };
        $read = PsoolaOrderSummary::orders($fetchAll, Page::query('fresh') === '1');
        $orders = $read['orders'];
        $readAt = $read['at'];
        if ($read['stale']) PsoolaOrderSummary::refreshLater($fetchAll);   // an older copy is shown now; PSOOLA is read just after
    } catch (\Throwable $error) {
        App::log('error', 'Psoola orders could not be read', ['error' => $error->getMessage()]);
        $problem = 'Psoola could not be reached just now. Refresh the page in a minute.';
    }
}

// Our registrations for these sales, by reference.
$refs = array_values(array_unique(array_filter(array_map(static fn (array $o) => is_string($o['partnerReference'] ?? null) ? $o['partnerReference'] : '', $orders))));
$associationTags = \Ismile\AssociationGuests::tags($refs);
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
$guestOrders=[];
foreach(Db::all('SELECT o.id,o.partner_reference,o.provider_order_id,o.complimentary_reason,o.ticket_quantity,u.name AS staff_name FROM sponsored_ticket_orders o JOIN admin_users u ON u.id=o.created_by WHERE o.is_complimentary=1 AND o.status=\'issued\'') as $guestOrder) $guestOrders[$guestOrder['provider_order_id']]=$guestOrder;

$known=$ours;foreach($guestOrders as $guestOrder)$known[$guestOrder['partner_reference']]=true;
$sum=PsoolaOrderSummary::totals($orders,$known);

// The filters: first the search, then each group; the counts keep the other choices.
$searched = array_values(array_filter($orders, static function (array $order) use ($q): bool {
    if ($q === '') return true;
    $haystack = mb_strtolower(implode(' ', [
        $order['id'] ?? '', $order['partnerReference'] ?? '',
        $order['buyer']['name'] ?? '', $order['buyer']['email'] ?? '', $order['buyer']['phone'] ?? '',
    ]));
    return str_contains($haystack, mb_strtolower($q)) || str_contains(preg_replace('/\D/', '', $haystack), preg_replace('/\D/', '', $q) ?: "\0");
}));
$facts = array_map(static fn (array $order): array => PsoolaOrderSummary::facts($order, $ourType), $searched);
$counts = PsoolaOrderSummary::counts($facts, $chosen);
$shown = [];
foreach ($searched as $i => $order) if (PsoolaOrderSummary::passes($facts[$i], $chosen)) $shown[] = $order;
$missedAll = count(array_filter($orders, static fn (array $order): bool => PsoolaOrderSummary::emailGroup($order) === 'missed'));
$filtered = $q !== '' || array_filter($chosen) !== [];

$withMore = static fn (string $url, array $more): string => $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($more);
$age = time() - $readAt;
$ageText = $age < 10 ? 'just now' : ($age < 60 ? $age . ' seconds ago' : intdiv($age, 60) . ' min ago');
Page::top('Psoola orders', 'psoola-orders', '<a class="btn ghost" href="' . Page::e($withMore($link([]), ['fresh' => '1'])) . '" data-po-busy>' . Page::navIcon('clock') . '<span>Refresh from Psoola</span></a>');
if ($problem !== null) {
    echo '<div class="card">' . Page::emptyState('Psoola orders are not available', $problem, 'psoola-orders') . '</div>';
    Page::bottom();
    exit;
}
echo Page::stats([
    ['Orders', number_format($sum['orders']), number_format($sum['tickets']) . ' tickets · ' . number_format($sum['used']) . ' used at the door', 'psoola-orders', 'blue'],
    ['Paid on Psoola', number_format($sum['card']) . ' IQD', 'Card and wallet payments (FIB, FastPay, NassWallet, Qi)', 'payments', 'teal'],
    ['Cash sales', number_format($sum['cash']) . ' IQD', $sum['cash_orders'] . ($sum['cash_orders'] === 1 ? ' sale' : ' sales') . ' · iSmile owes Psoola this ticket price', 'check', 'green'],
    ['Guest tickets', number_format($sum['complimentary_tickets']), $sum['complimentary_orders'].' complimentary orders · 0 IQD collected', 'ticket', 'violet'],
    ['Not in our database', number_format($sum['missing']), $sum['missing'] ? 'Sales with no matching registration: check these' : 'Every sale has its registration', 'users', $sum['missing'] ? 'gold' : 'violet'],
]);
?>
<?php if ($missedAll > 0): $onMissed = $chosen['email'] === 'missed'; ?>
<a class="po-alert<?= $onMissed ? ' is-on' : '' ?>" href="<?= $e($link(['email' => $onMissed ? '' : 'missed'])) ?>">
  <span class="po-alert-icon"><?= Page::navIcon('mail') ?></span>
  <span class="po-alert-text"><b><?= number_format($missedAll) ?> <?= $missedAll === 1 ? 'person did' : 'people did' ?> not receive the ticket email</b><small>Psoola could not deliver it (bounced or failed). Check the email address, or send them the QR by WhatsApp.</small></span>
  <em><?= $onMissed ? 'Show everyone' : 'Show them' ?></em>
</a>
<?php endif; ?>
<section class="card po-filters" aria-label="Filter the sales">
  <form method="get" class="po-search" action="psoola-orders.php">
    <?php foreach ($chosen as $group => $value): if ($value === '') continue; ?><input type="hidden" name="<?= $e($group) ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
    <label class="po-search-box"><span class="po-search-ic" aria-hidden="true"><?= Page::navIcon('search') ?></span><input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search a name, email, phone or reference" aria-label="Search the sales"></label>
    <button class="btn">Search</button>
    <?php if ($filtered): ?><a class="btn ghost" href="psoola-orders.php">Clear all</a><?php endif; ?>
  </form>
  <div class="po-groups">
  <?php foreach ([
      'email'   => ['Ticket email', 'mail', ['delivered' => 'green', 'missed' => 'red', 'waiting' => 'gold']],
      'channel' => ['Sale type', 'cash', ['checkout' => 'teal', 'direct' => 'green', 'complimentary' => 'violet']],
      'ticket'  => ['Ticket', 'ticket', ['professional' => 'teal', 'student' => 'blue', 'vip' => 'gold']],
      'door'    => ['At the door', 'checkin', ['used' => 'green', 'unused' => 'blue']],
  ] as $group => [$label, $icon, $tones]): ?>
    <div class="po-group">
      <span class="po-group-label"><?= Page::navIcon($icon) ?><?= $e($label) ?></span>
      <div class="po-chips">
        <a class="po-chip tone-all<?= $chosen[$group] === '' ? ' is-on' : '' ?>" href="<?= $e($link([$group => ''])) ?>"<?= $chosen[$group] === '' ? ' aria-current="true"' : '' ?>><span>All</span><b><?= number_format($counts[$group]['']) ?></b></a>
        <?php foreach (PsoolaOrderSummary::FILTERS[$group] as $value => $name): $on = $chosen[$group] === $value; ?>
        <a class="po-chip tone-<?= $tones[$value] ?><?= $on ? ' is-on' : '' ?><?= $counts[$group][$value] === 0 && !$on ? ' is-empty' : '' ?>" href="<?= $e($link([$group => $on ? '' : $value])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?php if ($group === 'email' && $value === 'missed'): ?><i aria-hidden="true">!</i><?php endif; ?><span><?= $e($name) ?></span><b><?= number_format($counts[$group][$value]) ?></b></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<section class="card registration-directory">
  <div class="panel-top"><?= Page::panelHeading('Sales recorded by Psoola', number_format(count($shown)) . ' matching sales, newest first. Read from Psoola ' . $ageText . '; refunds are made by Psoola.', 'psoola-orders') ?></div>
  <div class="table-wrap"><table class="psoola-orders"><thead><tr><th>Paid</th><th>Buyer</th><th>Bought</th><th>Total</th><th>Sale</th><th>Complimentary reason / staff</th><th>Ticket email</th><th>At the door</th><th>Our registration</th></tr></thead><tbody>
  <?php foreach (array_slice($shown, 0, $rows) as $order):
      $paid = $millis($order['paidAt'] ?? null);
      $mail = (string) ($order['emailStatus'] ?? '');
      $tickets = array_filter((array) ($order['tickets'] ?? []), 'is_array');
      $used = count(array_filter($tickets, static fn ($t) => !empty($t['checkedIn'])));
      $mine = $ours[$order['partnerReference'] ?? ''] ?? null;
      $kind=PsoolaOrderSummary::kind($order);$guestOrder=$guestOrders[$order['id']??'']??null; ?>
    <tr>
      <td data-label="Paid"><?= $paid ? $e(Page::when($paid)) : '—' ?><small><code><?= $e((string) ($order['id'] ?? '')) ?></code></small></td>
      <td data-label="Buyer"><b><?= $e((string) ($order['buyer']['name'] ?? '')) ?></b><small dir="ltr"><?= $e((string) ($order['buyer']['email'] ?? '')) ?></small><small dir="ltr"><?= $e((string) ($order['buyer']['phone'] ?? '')) ?></small></td>
      <td data-label="Bought"><?= $e($itemsText($order)) ?></td>
      <td data-label="Total"><b><?= number_format((int) ($order['order']['totalAmount'] ?? 0)) ?></b> <?= $e((string) ($order['order']['currency'] ?? 'IQD')) ?></td>
      <td data-label="Sale"><?= match($kind){'complimentary'=>'<span class="pill violet">Complimentary</span>','review'=>'<span class="pill gold">Needs review</span>','cash'=>'<span class="psoola-channel cash">Cash</span>'.(isset($associationTags[$order['partnerReference'] ?? '']) ? '<br><span class="added-by-tag association">'.$e($associationTags[$order['partnerReference']]).'</span>' : ''),default=>'<span class="psoola-channel">Paid on Psoola</span>'} ?></td>
      <td data-label="Complimentary reason / staff"><?php if(in_array($kind,['complimentary','review'],true)): ?><?= $e((string)($order['complimentaryReason']??$guestOrder['complimentary_reason']??'')) ?><small><?= $e($guestOrder['staff_name']??'PSOOLA') ?></small><?php else: ?>—<?php endif; ?></td>
      <td data-label="Ticket email"><?php if ($mail === ''): ?><span class="muted">—</span><?php else: ?><span class="psoola-mail<?= in_array($mail, ['bounced', 'complained', 'failed', 'delayed'], true) ? ' bad' : '' ?>"><?= $e(ucfirst($mail)) ?></span><?php endif; ?></td>
      <td data-label="At the door"><?= $tickets ? $used . ' of ' . count($tickets) . ' used' : '—' ?></td>
      <td data-label="Our registration"><?php if ($mine): ?><a href="registration.php?id=<?= (int) $mine['id'] ?>"><?= $e(Registrations::fullName($mine)) ?></a><small><code><?= $e($mine['ref']) ?></code> · <?= Page::pill($mine['status']) ?></small><?php elseif($guestOrder): ?><?php if(\Ismile\Auth::canIssueComplimentary($user)): ?><a href="registration.php?new=free&amp;order=<?= (int)$guestOrder['id'] ?>">Guest order</a><?php else: ?><span>Complimentary guest order</span><?php endif; ?><?php else: ?><span class="psoola-missing">Not in our database</span><small><code><?= $e((string) ($order['partnerReference'] ?? 'no reference')) ?></code></small><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$shown): ?><tr class="empty-row"><td colspan="9"><?= Page::emptyState($orders ? 'No sales match these filters' : 'No sales yet', $orders ? 'Clear a filter or search for another buyer.' : 'Sales paid on Psoola and cash sales appear here.', 'psoola-orders') ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if (count($shown) > $rows): ?>
  <div class="po-more" id="more"><span>Showing <b><?= number_format($rows) ?></b> of <b><?= number_format(count($shown)) ?></b> sales</span><a class="btn" href="<?= $e($withMore($link([]), ['rows' => $rows + 200]) . '#more') ?>" data-po-busy>Show <?= number_format(min(200, count($shown) - $rows)) ?> more</a></div>
  <?php endif; ?>
</section>
<?php Page::bottom();
