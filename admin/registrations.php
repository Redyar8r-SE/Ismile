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

Page::top('Registrations', 'registrations');
$e = [Page::class, 'e'];
$select = static function (string $name, array $options, string $current): string {
    $html = '<select name="' . $name . '">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . Page::e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . Page::e($label) . '</option>';
    }
    return $html . '</select>';
};
?>
<form class="filters card" method="get">
  <input type="search" name="q" value="<?= $e($in['q']) ?>" placeholder="Name, phone, email, ISM26-… or T26-…" autofocus>
  <?= $select('type', ['' => 'Any ticket', 'professional' => 'Professional', 'student' => 'Student'], $in['type']) ?>
  <?= $select('status', ['' => 'Registered (paid + free)', 'paid' => 'Paid', 'complimentary' => 'Free ticket', 'cancelled' => 'Cancelled'], $in['status']) ?>
  <?= $select('lunch', ['' => 'Any lunch', 'day1' => 'Lunch day 1', 'day2' => 'Lunch day 2', 'none' => 'No lunch'], $in['lunch']) ?>
  <?= $select('city', ['' => 'Any city'] + array_combine($cities, $cities), $in['city']) ?>
  <?= $select('lang', ['' => 'Any language', 'en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'], $in['lang']) ?>
  <?= $select('dup', ['' => 'All', '1' => 'Possible duplicates'], $in['dup']) ?>
  <?= $select('email', ['' => 'Any email state', 'failed' => 'Email failed'], $in['email']) ?>
  <button class="btn">Search</button>
  <a class="btn ghost" href="registrations.php">Clear</a>
</form>

<div class="toolbar">
  <span><b><?= $total ?></b> registration(s)</span>
  <?php if (Auth::can($user, 'export')): ?>
    <a class="btn green" href="export.php?<?= $e(RegistrationQuery::queryString($in, ['what' => 'registrations'])) ?>">Export to Excel (this list)</a>
  <?php endif; ?>
  <?php if (Auth::can($user, 'edit')): ?>
    <a class="btn ghost" href="registration.php?new=1">+ Register a caller (phone)</a>
  <?php endif; ?>
</div>

<div class="card table-wrap">
<table>
  <tr><th>Reference</th><th>Name</th><th>Phone</th><th>Ticket</th><th>Lunch</th><th>Status</th><th>Ticket no.</th><th>Registered</th></tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><a href="registration.php?id=<?= (int) $row['id'] ?>"><code><?= $e($row['ref']) ?></code></a>
      <?= $row['possible_duplicate'] ? ' <span class="pill violet" title="Same email or phone as another registration">duplicate?</span>' : '' ?></td>
    <td><a href="registration.php?id=<?= (int) $row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a> <?= Page::paidBadge($row['status']) ?></td>
    <td dir="ltr"><?= $e($row['phone']) ?></td>
    <td><?= $e($row['ticket_type']) ?><?= $row['university'] ? '<br><small class="muted">' . $e($row['university']) . '</small>' : '' ?></td>
    <td><?= $row['lunch_day1'] ? 'D1 ' : '' ?><?= $row['lunch_day2'] ? 'D2' : '' ?><?= !$row['lunch_day1'] && !$row['lunch_day2'] ? '–' : '' ?></td>
    <td><?= Page::pill($row['status']) ?><?= $row['failed_emails'] ? ' ' . Page::pill('failed') . '<small> email</small>' : '' ?></td>
    <td><?= $row['ticket_no'] ? '<code>' . $e($row['ticket_no']) . '</code>' . ($row['checked_in_at'] ? ' ' . Page::pill('arrived') : '') : '–' ?></td>
    <td><?= Page::when($row['created_at']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">Nothing found.</td></tr><?php endif; ?>
</table>
</div>
<?php if ($total > $perPage): ?>
<div class="toolbar">
  <?php if ($pageNo > 1): ?><a class="btn ghost" href="?<?= $e(RegistrationQuery::queryString($in, ['page' => $pageNo - 1])) ?>">← Previous</a><?php endif; ?>
  <span>Page <?= $pageNo ?> of <?= (int) ceil($total / $perPage) ?></span>
  <?php if ($offset + $perPage < $total): ?><a class="btn ghost" href="?<?= $e(RegistrationQuery::queryString($in, ['page' => $pageNo + 1])) ?>">Next →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php Page::bottom();
