<?php
// Lists: ready-made name lists, each with Excel export and print.
//   Registered              everyone registered: paid (or a free ticket)
//   Lunch day 1 / day 2     names for the caterer
//   Students                registered students and their university
//   Workshops               each workshop with the people booked on it
//   Sponsors / Exhibition   the sponsor requests and the booth requests, apart
//   All forms sent          every form, paid or not (paid = registered)
//   Cancelled
// Only paid people are registrations, so every list holds paid people only.
// Every list has filters (search, ticket, lunch, ambassador…); Print, PDF and
// Excel use the same filters, so they hold exactly the people on the screen.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Lists;
use Ismile\Admin\Page;
use Ismile\Admin\PaymentBreakdown;
use Ismile\Auth;
use Ismile\Registrations;

$user = Page::guard('registrations');
$canPhotos = Auth::can($user, 'photos');
$current = Lists::pick(Page::query('list'));
if ($current === 'studentids' && !$canPhotos) {
    $current = 'students';   // Finance sees the students, not their ID photos
}
$filters = Lists::filters($_GET);
$offers = array_flip(Lists::available($current));
$active = Lists::describe($current, $filters);
$counts = Lists::counts();
$columns = Lists::columns($current);
if ($current === 'workshops') {
    $groups = Lists::workshopGroups();
    $everyone = array_merge([], ...array_column($groups, 'people'));
    foreach ($groups as &$group) {
        $group['people'] = Lists::filter($current, $group['people'], $filters);
    }
    unset($group);
    $total = count($everyone);
    $shown = array_sum(array_map(static fn (array $group): int => count($group['people']), $groups));
} else {
    $everyone = Lists::rows($current);
    $rows = Lists::filter($current, $everyone, $filters);
    $total = count($everyone);
    $shown = count($rows);
}
$options = Lists::options($everyone);
$query = Lists::query($current, $filters);
$isCompany = isset(Lists::COMPANY_LISTS[$current]);
$paymentPeople = $isCompany ? [] : PaymentBreakdown::attach($current === 'workshops' ? array_merge([], ...array_column($groups, 'people')) : $rows);
$noun = $current === 'workshops' ? 'bookings' : ($isCompany ? 'companies' : 'people');

Page::top('Lists', 'lists', Auth::can($user, 'export') ? '<a class="btn green" href="export.php?what=all&amp;format=pdf">Download all event lists (PDF)</a>' : '');
$e = [Page::class, 'e'];
$cell = static function (string $key, array $row) use ($e, $canPhotos): string {
    return match ($key) {
        'specialty' => $e(Registrations::SPECIALTY_NAMES[$row[$key] ?? ''] ?? (string) ($row[$key] ?? '')),
        'ticket_type' => $e(\Ismile\Registrations::ticketName((string) $row['ticket_type'])),
        'ref', 'ticket_no' => $row[$key] ? '<code>' . $e($row[$key]) . '</code>' : '–',
        'paid_at', 'created_at' => Page::when($row[$key]),
        'lunch' => trim(($row['lunch_day1'] ? 'Day 1 ' : '') . ($row['lunch_day2'] ? 'Day 2' : '')) ?: '–',
        'workshop_paid' => Page::paidBadge((string) $row['workshop_paid']),
        'amount_paid' => $row['amount_paid'] !== null ? number_format((int) $row['amount_paid']) : '–',
        'form_status' => Page::pill((string) $row['form_status']),
        'sponsor_status' => Page::pill((string) $row['sponsor_status']),
        'study_stage' => $row['study_stage'] ? $e(Registrations::stageName((int) $row['study_stage'])) : '<span class="muted">–</span>',
        'ambassador_code' => Lists::ambassadorCode($row) !== '' ? '<span class="amb-code">' . $e(Lists::ambassadorCode($row)) . '</span>' : '<span class="muted">–</span>',
        'id_photo' => $row['id_photo'] !== 'stored' ? '<span class="muted">none</span>' : ($canPhotos ? '<a href="photo.php?id=' . (int) $row['id'] . '" target="_blank" rel="noopener">✓ see photo</a>' : '✓ stored'),
        'package' => $row['package'] !== null ? $e((string) $row['package']) : '<span class="muted">not chosen</span>',
        'amount_agreed' => $row['amount_agreed'] !== null ? number_format((int) $row['amount_agreed']) : '–',
        'next_call_at' => $row['next_call_at'] ? Page::when($row['next_call_at']) : '–',
        default => $e((string) ($row[$key] ?? '')),
    };
};
// A row of choices that look like one control ("All · Dentist · Student · VIP").
$segments = static function (string $name, string $label, array $choices) use ($e, $filters): string {
    $html = '<fieldset class="filter-seg"><legend>' . $e($label) . '</legend><div>';
    foreach ($choices as $value => $text) {
        $html .= '<label><input type="radio" name="' . $e($name) . '" value="' . $e((string) $value) . '"' . ((string) $value === ($filters[$name] ?? '') ? ' checked' : '') . '><span>' . $e($text) . '</span></label>';
    }
    return $html . '</div></fieldset>';
};
$select = static function (string $name, string $label, array $choices, string $all) use ($e, $filters): string {
    $html = '<label class="filter-field"><span>' . $e($label) . '</span><select name="' . $e($name) . '"><option value="">' . $e($all) . '</option>';
    foreach ($choices as $value => $text) {
        $html .= '<option value="' . $e((string) $value) . '"' . ((string) $value === ($filters[$name] ?? '') ? ' selected' : '') . '>' . $e($text) . '</option>';
    }
    return $html . '</select></label>';
};
$more = count(array_filter($active, static fn (array $chip): bool => in_array($chip['key'], ['city', 'specialty', 'university', 'from', 'to', 'amb', 'status', 'package', 'source', 'cert'], true)));
$pickedAmb = $filters['amb'];
$ambNames = array_column($options['ambassadors'], 'name', 'code');
$pickedLabel = !$pickedAmb ? 'All ambassadors' : (count($pickedAmb) > 1 ? count($pickedAmb) . ' ambassadors'
    : ($pickedAmb[0] === '-' ? 'No ambassador' : (($ambNames[$pickedAmb[0]] ?? '') !== '' ? $ambNames[$pickedAmb[0]] : $pickedAmb[0])));
?>
<div class="list-workspace"><nav class="list-library no-print" aria-label="Customer lists" data-live-region="list-library">
  <span class="library-caption">YOUR CUSTOMER LISTS</span>
  <?php foreach (Lists::ALL as $key => [$label]): if ($key === 'studentids' && !$canPhotos) { continue; } ?>
    <a class="library-link<?= $key === $current ? ' on' : '' ?>"<?= $key === $current ? ' aria-current="page"' : '' ?> href="lists.php?list=<?= $e($key) ?>"><?= Page::navIcon(match($key){'lunch1','lunch2'=>'lunch','workshops'=>'workshops','sponsors','exhibition'=>'sponsors','students','studentids'=>'users','cancelled'=>'close',default=>'registrations'}) ?><span><?= $e($label) ?></span><b><?= (int) ($counts[$key] ?? 0) ?></b></a>
  <?php endforeach; ?>
</nav>

<div class="card list-content">
  <div class="list-head" data-live-region="list-head">
    <div>
      <h2><?= $e(Lists::ALL[$current][0]) ?> <span class="list-count"><?= $active ? number_format($shown) . ' of ' . number_format($total) : number_format($total) ?> <?= $noun ?></span></h2>
      <p class="muted"><?= $e(Lists::ALL[$current][1]) ?></p>
    </div>
    <div class="toolbar no-print">
      <button class="btn ghost" type="button" data-print>Print</button>
      <?php if (Auth::can($user, 'export')): ?><a class="btn ghost" href="export.php?what=list&amp;<?= $e($query) ?>&amp;format=pdf">Download PDF</a><?php endif; ?>
      <?php if (Auth::can($user, 'export')): ?><a class="btn green" href="export.php?what=list&amp;<?= $e($query) ?>">Export to Excel</a><?php endif; ?>
    </div>
  </div>

  <form class="list-filters no-print" method="get" action="lists.php" data-instant-search data-search-regions="list-head list-active list-results">
    <input type="hidden" name="list" value="<?= $e($current) ?>">
    <input type="hidden" name="amb" value="<?= $e(implode(',', $filters['amb'])) ?>" data-amb-value>
    <div class="filter-search-row">
      <label class="filter-search"><?= Page::navIcon('search') ?><span class="sr-only">Search this list</span>
        <input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="<?= $isCompany ? 'Search company, contact, phone, email or reference' : 'Search name, phone, email, reference or ticket no.' ?>"></label>
      <a class="filter-clear" href="lists.php?list=<?= $e($current) ?>">Clear all</a>
    </div>
    <div class="filter-quick">
      <?php if (isset($offers['ticket'])) echo $segments('ticket', 'Ticket', ['' => 'All'] + \Ismile\Registrations::TICKET_NAMES); ?>
      <?php if (isset($offers['lunch'])) echo $segments('lunch', 'Lunch', ['' => 'Any', 'day1' => 'Day 1', 'day2' => 'Day 2', 'both' => 'Both', 'none' => 'None']); ?>
      <?php if (isset($offers['arrived'])) echo $segments('arrived', 'Arrived', ['' => 'Any', 'day1' => 'Day 1', 'day2' => 'Day 2', 'none' => 'Not yet']); ?>
      <?php if (isset($offers['sale'])) echo $segments('sale', 'Sale type', ['' => 'All'] + Lists::SALE_TYPES); ?>
      <?php if (isset($offers['payment'])) echo $segments('payment', 'Payment', ['' => 'All', 'paid' => 'Paid', 'waiting' => 'Waiting', 'expired' => 'Expired']); ?>
      <?php if (isset($offers['wpaid'])) echo $segments('wpaid', 'Workshop paid', ['' => 'All', 'yes' => 'Paid', 'no' => 'Not paid']); ?>
      <?php if (isset($offers['calls'])) echo $segments('calls', 'Calls', ['' => 'All', 'due' => 'Due today', 'overdue' => 'Overdue']); ?>
    </div>
    <details class="filter-more"<?= $more ? ' open' : '' ?>>
      <summary><?= Page::navIcon('settings') ?><span>More filters</span><?php if ($more): ?><b><?= $more ?></b><?php endif; ?></summary>
      <div class="filter-fields">
        <?php if (isset($offers['status'])) echo $select('status', 'Status', array_combine($options['status'], array_map(static fn ($s) => ucfirst(str_replace('_', ' ', $s)), $options['status'])) ?: [], 'All statuses'); ?>
        <?php if (isset($offers['package'])) echo $select('package', $current === 'exhibition' ? 'Booth type' : 'Package', (array_combine($options['package'], $options['package']) ?: []) + ['-' => 'Not chosen'], 'All packages'); ?>
        <?php if (isset($offers['city']) && $options['city']) echo $select('city', 'City', array_combine($options['city'], $options['city']), 'All cities'); ?>
        <?php if (isset($offers['specialty']) && $options['specialty']) echo $select('specialty', 'Specialty', array_intersect_key(Registrations::SPECIALTY_NAMES, array_flip($options['specialty'])), 'All specialties'); ?>
        <?php if (isset($offers['university']) && $options['university']) echo $select('university', 'University', array_combine($options['university'], $options['university']), 'All universities'); ?>
        <?php if (isset($offers['source'])) echo $select('source', 'Sent from', ['website' => 'Website', 'office' => 'Office'], 'Website and office'); ?>
        <?php if (isset($offers['cert'])) echo $select('cert', 'Certificate', ['yes' => 'Issued', 'no' => 'Not yet'], 'All'); ?>
        <?php if (isset($offers['from'])): ?>
          <label class="filter-field"><span><?= $current === 'forms' ? 'Sent from' : 'Paid from' ?> (date)</span><input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
          <label class="filter-field"><span>Until (date)</span><input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
        <?php endif; ?>
        <?php if (isset($offers['amb'])): ?>
        <div class="filter-field amb-field">
          <span>Ambassador</span>
          <details class="amb-picker">
            <summary><?= Page::navIcon('users') ?><span data-amb-summary><?= $e($pickedLabel) ?></span></summary>
            <div class="amb-menu">
              <input type="search" class="amb-find" placeholder="Find an ambassador or code…" aria-label="Find an ambassador" data-amb-find>
              <div class="amb-head"><span>Ambassador</span><span>People joined</span></div>
              <?php foreach ($options['ambassadors'] as $amb): ?>
              <label class="amb-option" data-amb-text="<?= $e(mb_strtolower($amb['name'] . ' ' . $amb['code'])) ?>"><input type="checkbox" value="<?= $e($amb['code']) ?>"<?= in_array($amb['code'], $pickedAmb, true) ? ' checked' : '' ?>>
                <span><b><?= $e($amb['name'] !== '' ? $amb['name'] : $amb['code']) ?></b><?php if ($amb['name'] !== ''): ?><small>Code <?= $e($amb['code']) ?></small><?php endif; ?></span><em><?= (int) $amb['count'] ?></em></label>
              <?php endforeach; ?>
              <label class="amb-option" data-amb-text="no ambassador none"><input type="checkbox" value="-"<?= in_array('-', $pickedAmb, true) ? ' checked' : '' ?>><span><b>No ambassador</b><small>Registered without a code</small></span><em><?= (int) $options['no_ambassador'] ?></em></label>
              <?php if (!$options['ambassadors']): ?><p class="muted small">Nobody on this list used an ambassador code yet.</p><?php endif; ?>
              <p class="muted small">Tick more than one to compare ambassadors.</p>
            </div>
          </details>
        </div>
        <?php endif; ?>
        <?php if (isset($offers['sort'])) echo $select('sort', 'Sort by', ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => $isCompany ? 'Company A–Z' : 'Name A–Z'], 'Standard order'); ?>
      </div>
    </details>
    <noscript><button class="btn">Apply filters</button></noscript>
  </form>

  <div class="filter-active" data-live-region="list-active">
    <?php if ($active): ?>
      <span class="filter-active-label">Showing:</span>
      <?php foreach ($active as $chip):
          $without = $filters;
          $without[$chip['key']] = is_array($filters[$chip['key']]) ? array_values(array_diff($filters[$chip['key']], [$chip['value']])) : ''; ?>
        <a class="filter-chip" href="lists.php?<?= $e(Lists::query($current, $without)) ?>" title="Remove this filter"><?= $e($chip['label']) ?> <span aria-hidden="true">×</span></a>
      <?php endforeach; ?>
      <span class="filter-note"><?= Page::navIcon('check') ?>Print, PDF and Excel include only these <?= number_format($shown) ?> <?= $noun ?></span>
    <?php else: ?>
      <span class="filter-note muted">Showing everyone on this list. Use the filters above to narrow it down.</span>
    <?php endif; ?>
  </div>

  <div class="list-results" data-live-region="list-results">
  <div<?= !$isCompany && $current !== 'studentids' ? ' class="no-print"' : '' ?>>
  <?php if ($current === 'studentids'): ?>
    <div class="id-grid">
    <?php foreach ($rows as $row): ?>
      <div class="id-card">
        <?php if ($row['id_photo'] === 'stored'): ?>
          <a href="photo.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener"><img src="photo.php?id=<?= (int) $row['id'] ?>&amp;log=0" alt="ID photo" loading="lazy"></a>
        <?php else: ?><div class="no-photo"><?= $row['id_photo_deleted_at'] ? 'Photo deleted after the summit' : 'No photo (registered by the office)' ?></div><?php endif; ?>
        <div class="id-card-text">
          <a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a> <?= Page::paidBadge($row['status']) ?><br>
          <?= $e($row['university'] ?? '') ?><?= Lists::ambassadorCode($row) !== '' ? ' · <span class="amb-code">' . $e(Lists::ambassadorCode($row)) . '</span>' : '' ?><br>
          <small class="muted" dir="ltr"><?= $e($row['phone']) ?> · <?= $e($row['ref']) ?><?= $row['photo_uploaded'] ? ' · sent ' . Page::when($row['photo_uploaded']) : '' ?></small>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$rows): ?><p class="muted"><?= $active ? 'No students match these filters.' : 'No registered students yet.' ?></p><?php endif; ?>
  <?php elseif ($current === 'workshops'): ?>
    <?php foreach ($groups as ['workshop' => $workshop, 'people' => $people]): ?>
    <div class="group">
      <div class="group-head"><h3><?= $e(\Ismile\SiteData::workshopName($workshop)) ?><?= $workshop['status'] === 'hidden' ? ' <small class="muted">(hidden)</small>' : '' ?></h3>
        <span><b><?= count($people) ?></b><?= $active ? ' shown' : '' ?> · <?= (int) $workshop['totalSeats'] ?> seats · <?= (int) $workshop['seatsLeft'] ?> left</span></div>
      <div class="table-wrap"><table class="name-list">
        <tr><th>#</th><th>Name</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
        <?php foreach ($people as $i => $row): ?>
        <tr><td><?= $i + 1 ?></td><td><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a></td>
          <?php foreach (array_keys($columns) as $key): ?><td<?= $key === 'phone' ? ' dir="ltr"' : '' ?>><?= $cell($key, $row) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$people): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted"><?= $active ? 'Nobody here matches these filters.' : 'Nobody booked yet.' ?></td></tr><?php endif; ?>
      </table></div>
    </div>
    <?php endforeach; ?>
  <?php elseif ($isCompany): ?>
  <div class="table-wrap"><table class="name-list">
    <tr><th>#</th><th>Company</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $i => $row): ?>
    <tr><td><?= $i + 1 ?></td><td><a href="<?= $current==='exhibition'?'booths.php':'sponsors.php' ?>?id=<?= (int) $row['id'] ?>"><b><?= $e($row['company']) ?></b></a></td>
      <?php foreach (array_keys($columns) as $key): ?><td<?= in_array($key, ['phone', 'email'], true) ? ' dir="ltr"' : '' ?>><?= $cell($key, $row) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted"><?= $active ? 'No companies match these filters.' : 'No requests yet.' ?></td></tr><?php endif; ?>
  </table></div>
  <?php else: ?>
  <div class="table-wrap"><table class="name-list">
    <tr><th>#</th><th>Name</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $i => $row): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?php if ($row['id']): ?><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a><?php else: ?><b><?= $e(Registrations::fullName($row)) ?></b><?php endif; ?> <?= Page::paidBadge($row['status']) ?></td>
      <?php foreach (array_keys($columns) as $key): ?>
        <td<?= $key === 'phone' ? ' dir="ltr"' : '' ?>><?= $cell($key, $row) ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted"><?= $active ? 'Nobody matches these filters.' : 'Nobody on this list yet.' ?></td></tr><?php endif; ?>
  </table></div>
  <?php endif; ?>
  </div>
  <?php if (!$isCompany): $paymentRows = Lists::reportRows($current, $paymentPeople); $paymentHeaders = array_shift($paymentRows); ?>
  <section class="group payment-breakdown">
    <div class="group-head"><h3>Attendee and payment details</h3></div>
    <p class="muted small">Amounts show the recorded currency (IQD = Iraqi dinars). “Not recorded” is not zero. Lunch quantity includes any lunch included with a VIP ticket.</p>
    <div class="table-wrap"><table class="name-list">
      <thead><tr><?php foreach ($paymentHeaders as $heading): ?><th><?= $e($heading) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($paymentRows as $paymentCells): ?><tr><?php foreach ($paymentCells as $value): ?><td><?= $e((string) $value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      <?php if (!$paymentRows): ?><tr><td colspan="<?= count($paymentHeaders) ?>" class="muted">Nobody matches this list.</td></tr><?php endif; ?></tbody>
    </table></div>
  </section>
  <?php endif; ?>
  </div>
</div>
</div>
<?php Page::bottom();
