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

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Lists;
use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Registrations;

$user = Page::guard('registrations');
$canPhotos = Auth::can($user, 'photos');
$current = Lists::pick(Page::query('list'));
if ($current === 'studentids' && !$canPhotos) {
    $current = 'students';   // Finance sees the students, not their ID photos
}
$rows = Lists::rows($current);
$counts = Lists::counts();
$columns = Lists::columns($current);

Page::top('Lists', 'lists', Auth::can($user, 'export') ? '<a class="btn green" href="export.php?what=all&amp;format=pdf">Download all event lists (PDF)</a>' : '');
$e = [Page::class, 'e'];
?>
<?= Page::liveUpdates() ?>
<div class="list-workspace" data-live-region="event-lists"><nav class="list-library no-print" aria-label="Customer lists">
  <span class="library-caption">YOUR CUSTOMER LISTS</span>
  <?php foreach (Lists::ALL as $key => [$label]): if ($key === 'studentids' && !$canPhotos) { continue; } ?>
    <a class="library-link<?= $key === $current ? ' on' : '' ?>"<?= $key === $current ? ' aria-current="page"' : '' ?> href="lists.php?list=<?= $e($key) ?>"><?= Page::navIcon(match($key){'lunch1','lunch2'=>'lunch','workshops'=>'workshops','sponsors','exhibition'=>'sponsors','students','studentids'=>'users','cancelled'=>'close',default=>'registrations'}) ?><span><?= $e($label) ?></span><b><?= (int) ($counts[$key] ?? 0) ?></b></a>
  <?php endforeach; ?>
</nav>

<div class="card list-content">
  <div class="list-head">
    <div>
      <h2><?= $e(Lists::ALL[$current][0]) ?>: <?= $current === 'workshops' ? (int) ($counts['workshops'] ?? 0) . ' bookings' : count($rows) ?></h2>
      <p class="muted"><?= $e(Lists::ALL[$current][1]) ?></p>
    </div>
    <div class="toolbar no-print">
      <button class="btn ghost" type="button" data-print>Print</button>
      <?php if (Auth::can($user, 'export')): ?><a class="btn ghost" href="export.php?what=list&amp;list=<?= $e($current) ?>&amp;format=pdf">Download PDF</a><?php endif; ?>
      <?php if (Auth::can($user, 'export')): ?><a class="btn green" href="export.php?what=list&amp;list=<?= $e($current) ?>">Export to Excel</a><?php endif; ?>
    </div>
  </div>
  <?php if ($current === 'studentids'): ?>
    <div class="id-grid">
    <?php foreach ($rows as $row): ?>
      <div class="id-card">
        <?php if ($row['id_photo'] === 'stored'): ?>
          <a href="photo.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener"><img src="photo.php?id=<?= (int) $row['id'] ?>&amp;log=0" alt="ID photo" loading="lazy"></a>
        <?php else: ?><div class="no-photo"><?= $row['id_photo_deleted_at'] ? 'Photo deleted after the summit' : 'No photo (registered by the office)' ?></div><?php endif; ?>
        <div class="id-card-text">
          <a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a> <?= Page::paidBadge($row['status']) ?><br>
          <?= $e($row['university'] ?? '') ?><br>
          <small class="muted" dir="ltr"><?= $e($row['phone']) ?> · <?= $e($row['ref']) ?><?= $row['photo_uploaded'] ? ' · sent ' . Page::when($row['photo_uploaded']) : '' ?></small>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$rows): ?><p class="muted">No registered students yet.</p><?php endif; ?>
  <?php elseif ($current === 'workshops'): ?>
    <?php foreach (Lists::workshopGroups() as ['workshop' => $workshop, 'people' => $people]): ?>
    <div class="group">
      <div class="group-head"><h3><?= $e(\Ismile\SiteData::workshopName($workshop)) ?><?= $workshop['status'] === 'hidden' ? ' <small class="muted">(hidden)</small>' : '' ?></h3>
        <span><b><?= count($people) ?></b> of <?= (int) $workshop['totalSeats'] ?> seats · <?= (int) $workshop['seatsLeft'] ?> left</span></div>
      <div class="table-wrap"><table class="name-list">
        <tr><th>#</th><th>Name</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
        <?php foreach ($people as $i => $row): ?>
        <tr><td><?= $i + 1 ?></td><td><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a></td>
          <?php foreach (array_keys($columns) as $key): ?><td<?= $key === 'phone' ? ' dir="ltr"' : '' ?>><?= match ($key) {
            'ref', 'ticket_no' => $row[$key] ? '<code>' . $e($row[$key]) . '</code>' : '–',
            'paid_at', 'created_at' => Page::when($row[$key]),
            'lunch' => trim(($row['lunch_day1'] ? 'Day 1 ' : '') . ($row['lunch_day2'] ? 'Day 2' : '')) ?: '–',
            'workshop_paid' => Page::paidBadge((string) $row['workshop_paid']),
            'amount_paid' => $row['amount_paid'] !== null ? number_format((int) $row['amount_paid']) : '–',
            'sponsor_status' => Page::pill((string) $row['sponsor_status']),
            'id_photo' => $row['id_photo'] !== 'stored' ? '<span class="muted">none</span>' : ($canPhotos ? '<a href="photo.php?id=' . (int) $row['id'] . '" target="_blank" rel="noopener">✓ see photo</a>' : '✓ stored'),
            'package' => $row['package'] !== null ? $e((string) $row['package']) : '<span class="muted">not chosen</span>',
            'amount_agreed' => $row['amount_agreed'] !== null ? number_format((int) $row['amount_agreed']) : '–',
            'next_call_at' => $row['next_call_at'] ? Page::when($row['next_call_at']) : '–',
            default => $e((string) ($row[$key] ?? '')),
        } ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$people): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted">Nobody booked yet.</td></tr><?php endif; ?>
      </table></div>
    </div>
    <?php endforeach; ?>
  <?php elseif (isset(Lists::COMPANY_LISTS[$current])): ?>
  <div class="table-wrap"><table class="name-list">
    <tr><th>#</th><th>Company</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $i => $row): ?>
    <tr><td><?= $i + 1 ?></td><td><a href="<?= $current==='exhibition'?'booths.php':'sponsors.php' ?>?id=<?= (int) $row['id'] ?>"><b><?= $e($row['company']) ?></b></a></td>
      <?php foreach (array_keys($columns) as $key): ?><td<?= in_array($key, ['phone', 'email'], true) ? ' dir="ltr"' : '' ?>><?= match ($key) {
            'ref', 'ticket_no' => $row[$key] ? '<code>' . $e($row[$key]) . '</code>' : '–',
            'paid_at', 'created_at' => Page::when($row[$key]),
            'lunch' => trim(($row['lunch_day1'] ? 'Day 1 ' : '') . ($row['lunch_day2'] ? 'Day 2' : '')) ?: '–',
            'workshop_paid' => Page::paidBadge((string) $row['workshop_paid']),
            'amount_paid' => $row['amount_paid'] !== null ? number_format((int) $row['amount_paid']) : '–',
            'sponsor_status' => Page::pill((string) $row['sponsor_status']),
            'id_photo' => $row['id_photo'] !== 'stored' ? '<span class="muted">none</span>' : ($canPhotos ? '<a href="photo.php?id=' . (int) $row['id'] . '" target="_blank" rel="noopener">✓ see photo</a>' : '✓ stored'),
            'package' => $row['package'] !== null ? $e((string) $row['package']) : '<span class="muted">not chosen</span>',
            'amount_agreed' => $row['amount_agreed'] !== null ? number_format((int) $row['amount_agreed']) : '–',
            'next_call_at' => $row['next_call_at'] ? Page::when($row['next_call_at']) : '–',
            default => $e((string) ($row[$key] ?? '')),
        } ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted">No requests yet.</td></tr><?php endif; ?>
  </table></div>
  <?php else: ?>
  <div class="table-wrap"><table class="name-list">
    <tr><th>#</th><th>Name</th><?php foreach ($columns as $label): ?><th><?= $e($label) ?></th><?php endforeach; ?></tr>
    <?php foreach ($rows as $i => $row): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?php if ($row['id']): ?><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a><?php else: ?><b><?= $e(Registrations::fullName($row)) ?></b><?php endif; ?> <?= Page::paidBadge($row['status']) ?></td>
      <?php foreach (array_keys($columns) as $key): ?>
        <td<?= $key === 'phone' ? ' dir="ltr"' : '' ?>><?= match ($key) {
            'ref', 'ticket_no' => $row[$key] ? '<code>' . $e($row[$key]) . '</code>' : '–',
            'paid_at', 'created_at' => Page::when($row[$key]),
            'lunch' => trim(($row['lunch_day1'] ? 'Day 1 ' : '') . ($row['lunch_day2'] ? 'Day 2' : '')) ?: '–',
            'workshop_paid' => Page::paidBadge((string) $row['workshop_paid']),
            'amount_paid' => $row['amount_paid'] !== null ? number_format((int) $row['amount_paid']) : '–',
            'form_status' => Page::pill((string) $row['form_status']),
            'sponsor_status' => Page::pill((string) $row['sponsor_status']),
            'id_photo' => $row['id_photo'] !== 'stored' ? '<span class="muted">none</span>' : ($canPhotos ? '<a href="photo.php?id=' . (int) $row['id'] . '" target="_blank" rel="noopener">✓ see photo</a>' : '✓ stored'),
            'package' => $row['package'] !== null ? $e((string) $row['package']) : '<span class="muted">not chosen</span>',
            'amount_agreed' => $row['amount_agreed'] !== null ? number_format((int) $row['amount_agreed']) : '–',
            'next_call_at' => $row['next_call_at'] ? Page::when($row['next_call_at']) : '–',
            default => $e((string) ($row[$key] ?? '')),
        } ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($columns) + 2 ?>" class="muted">Nobody on this list yet.</td></tr><?php endif; ?>
  </table></div>
  <?php endif; ?>
</div>
</div>
<?php Page::bottom();
