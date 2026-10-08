<?php
// Registrations: search, filters, and the list. Open one to see and change it.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\RegistrationQuery;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;

$user = Page::guard('registrations');

$in = [];
foreach (RegistrationQuery::FILTERS as $key) {
    $in[$key] = Page::query($key);
}
[$where, $params] = RegistrationQuery::where($in);
$total = (int) Db::value('SELECT COUNT(*) FROM ' . RegistrationQuery::from() . " WHERE $where", $params);
$perPage = 50;
$pageNo = min(100000, max(1, (int) ($_GET['page'] ?? 1)));
$offset = ($pageNo - 1) * $perPage;
$rows = Db::all(
    "SELECT r.*, t.ticket_no, t.checked_in_at,
            (SELECT COUNT(*) FROM emails e WHERE e.registration_id = r.id AND e.status = 'failed') AS failed_emails
     FROM " . RegistrationQuery::from() . " WHERE $where ORDER BY r.id DESC LIMIT $perPage OFFSET $offset",
    $params
);
$cities = array_column(Db::all('SELECT DISTINCT city FROM registrations ORDER BY city LIMIT 200'), 'city');

$overview = Db::one("SELECT COALESCE(SUM(status IN ('paid','complimentary')),0) AS registered, COALESCE(SUM(status IN ('paid','complimentary') AND ticket_type = 'professional'),0) AS professionals, COALESCE(SUM(status IN ('paid','complimentary') AND ticket_type = 'student'),0) AS students, COALESCE(SUM(status IN ('paid','complimentary') AND ticket_type = 'vip'),0) AS vip, COALESCE(SUM(status = 'cancelled'),0) AS cancelled FROM registrations");
Page::top('Registrations', 'registrations', Auth::can($user,'edit') ? '<a class="btn" href="registration.php?new=1">+ Register a caller (phone)</a>' : '');
$e = [Page::class, 'e'];
$select = static function (string $name, array $options, string $current): string {
    $label = ['type'=>'Ticket type', 'status'=>'Registration status', 'lunch'=>'Lunch', 'city'=>'City', 'lang'=>'Language', 'dup'=>'Duplicates', 'email'=>'Email delivery', 'attendance'=>'Attendance'][$name] ?? $name;
    $html = '<label class="filter-field"><span>' . Page::e($label) . '</span><select name="' . $name . '">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . Page::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . Page::e($label) . '</option>';
    }
    return $html . '</select></label>';
};
?>
<?= Page::liveUpdates() ?>
<div data-live-region="registration-totals">
<?= Page::stats([
    ['Registered guests', number_format((int)$overview['registered']), 'Paid and complimentary tickets', 'users', 'teal'],
    ['Professionals', number_format((int)$overview['professionals']), 'Registered professional guests', 'registrations', 'blue'],
    ['Students', number_format((int)$overview['students']), 'Registered student guests', 'workshops', 'violet'],
    ['VIP guests', number_format((int)$overview['vip']), 'Registered VIP guests', 'ticket', 'gold'],
    ['Cancelled', number_format((int)$overview['cancelled']), 'Cancelled registrations', 'close', 'gold'],
]) ?>
</div>
<form class="filters card filter-panel" method="get" data-instant-search data-search-regions="registration-totals registration-directory">
  <?= Page::panelHeading('Find the right guest', 'Search your directory or narrow it down with the filters below.', 'search') ?>
  <label class="filter-field filter-search"><span>Name, phone, email or reference</span><input type="search" name="q" value="<?= $e($in['q']) ?>" placeholder="Search your guests…" autofocus></label>
  <?= $select('type', ['' => 'Any ticket', 'professional' => 'Professional', 'student' => 'Student', 'vip' => 'VIP'], $in['type']) ?>
  <?= $select('status', ['' => 'Registered (paid + free)', 'paid' => 'Paid', 'complimentary' => 'Free ticket', 'cancelled' => 'Cancelled'], $in['status']) ?>
  <?= $select('lunch', ['' => 'Any lunch', 'day1' => 'Lunch day 1', 'day2' => 'Lunch day 2', 'none' => 'No lunch'], $in['lunch']) ?>
  <?= $select('city', ['' => 'Any city'] + array_combine($cities, $cities), $in['city']) ?>
  <?= $select('lang', ['' => 'Any language', 'en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'], $in['lang']) ?>
  <?= $select('dup', ['' => 'All', '1' => 'Possible duplicates'], $in['dup']) ?>
  <?= $select('email', ['' => 'Any email state', 'failed' => 'Email failed'], $in['email']) ?>
  <?= $select('attendance', ['' => 'Any attendance', 'attended' => 'Attended either day', 'day1' => 'Attended Day 1', 'day2' => 'Attended Day 2', 'both' => 'Attended both days', 'none' => 'Never attended'], $in['attendance']) ?>
  <div class="form-actions filter-actions"><a class="btn ghost" href="registrations.php">Clear filters</a><button class="btn">Search</button></div>
</form>

<div data-live-region="registration-directory">
<section class="card directory-panel"><div class="toolbar list-head">
  <div><h2>Guest directory <span class="result-count"><?= $total ?></span></h2><p class="muted small">Showing the registrations that match your filters.</p></div>
  <?php if (Auth::can($user, 'export')): ?>
  <div class="directory-export-actions">
    <a class="btn ghost" href="export.php?<?= $e(RegistrationQuery::queryString($in, ['what' => 'registrations', 'format' => 'pdf'])) ?>">Download PDF (this list)</a>
    <a class="btn green" href="export.php?<?= $e(RegistrationQuery::queryString($in, ['what' => 'registrations'])) ?>">Export to Excel (this list)</a>
  </div>
  <?php endif; ?>
</div>

<div class="table-wrap">
<table>
  <tr><th>Reference</th><th>Name</th><th>Phone</th><th>Ticket</th><th>Lunch</th><th>Status</th><th>Ticket no.</th><th>Registered</th></tr>
  <?php foreach ($rows as $row): ?>
  <tr data-registration-result>
    <td><a href="registration.php?id=<?= (int) $row['id'] ?>"><code><?= $e($row['ref']) ?></code></a>
      <?= $row['possible_duplicate'] ? ' <span class="pill violet" title="Same email or phone as another registration">duplicate?</span>' : '' ?></td>
    <td><div class="guest-cell"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($row['first_name'],0,1))) ?></span><div><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a><small><?= Page::paidBadge($row['status']) ?></small></div></div></td>
    <td dir="ltr"><?= $e($row['phone']) ?></td>
    <td><?= $e($row['ticket_type']) ?><br><small class="muted"><?= $e(Registrations::SPECIALTY_NAMES[$row['specialty']] ?? $row['specialty']) ?></small><?= $row['university'] ? '<br><small class="muted">' . $e($row['university']) . '</small>' : '' ?></td>
    <td><?= $row['lunch_day1'] ? 'D1 ' : '' ?><?= $row['lunch_day2'] ? 'D2' : '' ?><?= !$row['lunch_day1'] && !$row['lunch_day2'] ? '–' : '' ?></td>
    <td><?= Page::pill($row['status']) ?><?= $row['failed_emails'] ? ' ' . Page::pill('failed') . '<small> email</small>' : '' ?></td>
    <td><?= $row['ticket_no'] ? '<code>' . $e($row['ticket_no']) . '</code>' . ($row['checked_in_at'] ? ' ' . Page::pill('arrived') : '') : '–' ?></td>
    <td><?= Page::when($row['created_at']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted"><?= Page::emptyState('No guests in this view yet', 'Try another search or clear the filters. New tickets appear here after registration.', 'registrations') ?></td></tr><?php endif; ?>
</table>
</div>
</section>
<?php if ($total > $perPage): ?>
<div class="toolbar">
  <?php if ($pageNo > 1): ?><a class="btn ghost" href="?<?= $e(RegistrationQuery::queryString($in, ['page' => $pageNo - 1])) ?>">← Previous</a><?php endif; ?>
  <span>Page <?= $pageNo ?> of <?= (int) ceil($total / $perPage) ?></span>
  <?php if ($offset + $perPage < $total): ?><a class="btn ghost" href="?<?= $e(RegistrationQuery::queryString($in, ['page' => $pageNo + 1])) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php Page::bottom();
