<?php
// Users › one account (Owner only): what this account can open and change,
// its sign-in security, and everything it has done (from the activity log).
// Included by users.php after Page::guard('owner').

declare(strict_types=1);

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Erase;

if (!isset($user, $roleGuide) || ($user['role'] ?? '') !== 'owner') { http_response_code(403); exit; }

$account = Db::one("SELECT * FROM admin_users WHERE id = ? AND email NOT LIKE '%@deleted.invalid'", [(int) Page::query('account')]);
$e = [Page::class, 'e'];
Page::top('Account activity', 'users', '<a class="btn ghost" href="users.php">&larr; All users</a>');
if ($account === null) {
    echo Page::emptyState('Account not found', 'It may have been deleted. Go back to Users.', 'users');
    Page::bottom();
    exit;
}

$labels = [
    'login' => 'Signed in', 'logout' => 'Signed out', 'login.bad_code' => 'Wrong phone code', 'login.locked' => 'Locked after wrong passwords',
    'two_factor.enabled' => 'Set up the phone code', 'viewer.blocked' => 'Tried to change something (refused)', 'csrf.rejected' => 'Sent an expired form (refused)',
    'checkout.cash' => 'Registered someone for cash', 'payment.cash' => 'Cash sale confirmed by PSOOLA', 'association_guest.add' => 'Added an association guest',
    'checkout.phone' => 'Registered someone by phone', 'ticket.complimentary' => 'Gave a free ticket', 'ticket.complimentary_order' => 'Gave a free ticket (PSOOLA)',
    'registration.edit' => 'Changed a guest\'s details', 'registration.notes' => 'Wrote guest notes', 'registration.cancel' => 'Cancelled a registration',
    'registration.delete' => 'Deleted a registration for good', 'ticket.resend' => 'Re-sent a ticket', 'ticket.reissued' => 'Re-issued a ticket',
    'workshop.add' => 'Booked a workshop seat', 'workshop.payment' => 'Recorded a workshop payment', 'workshop.cash_received' => 'Took workshop cash',
    'workshop.remove' => 'Removed a workshop booking', 'workshop.edit' => 'Edited a workshop', 'workshop.seats' => 'Changed workshop seats',
    'export' => 'Downloaded a list (PDF or Excel)', 'photo.view' => 'Opened a student ID photo', 'checkin' => 'Checked a guest in',
    'badge.batch' => 'Made a badge batch', 'badge.download' => 'Downloaded badges', 'certificate.download' => 'Downloaded certificates',
    'certificate.email' => 'Emailed a certificate', 'sponsor.status' => 'Changed a sponsor status', 'sponsor.call' => 'Logged a sponsor call',
    'sponsor.paid' => 'Recorded a sponsor payment', 'settings' => 'Changed settings', 'user.add' => 'Added a team account', 'user.role' => 'Changed a role',
    'user.association_guest_grant' => 'Association guests access given', 'user.association_guest_revoke' => 'Association guests access removed',
    'user.registration_manage_grant' => 'Paid and free registration access given', 'user.registration_manage_revoke' => 'Paid and free registration access removed',
    'user.workshop_booking_grant' => 'Workshop booking access given', 'user.workshop_booking_revoke' => 'Workshop booking access removed',
    'user.badge_certificate_grant' => 'Badges and certificates access given', 'user.badge_certificate_revoke' => 'Badges and certificates access removed',
    'user.association_manage_grant' => 'Association access given', 'user.association_manage_revoke' => 'Association access removed',
    'user.hidden_pages' => 'Hidden pages changed', 'user.password' => 'Password changed', 'user.reset_2fa' => 'Phone code reset', 'user.disable' => 'Account disabled', 'user.enable' => 'Account enabled',
];
$label = static fn (string $action): string => $labels[$action] ?? ucfirst(str_replace(['_', '.'], [' ', ': '], $action));
// A download: which list or report, PDF or Excel, the order, the filters and how many rows (from what was saved when it was downloaded).
$exportText = static function (array $details, string $at): array {
    $query = is_array($details['filters'] ?? null) ? $details['filters'] : [];
    $what = (string) ($details['what'] ?? '');
    $list = $what === 'list' ? \Ismile\Admin\Lists::pick((string) ($query['list'] ?? '')) : null;
    $name = match ($what) {
        'list' => 'List: ' . \Ismile\Admin\Lists::ALL[$list][0],
        'all' => 'All reports in one PDF',
        'attendance' => 'Attendance report',
        'registrations' => ($query['workspace'] ?? '') === 'checkin' ? 'Registration page guest list' : 'Registrations',
        'payments' => 'Payments',
        'sponsors' => 'Sponsors',
        'workshop' => 'Workshop list' . (is_string($query['id'] ?? null) ? ': ' . $query['id'] : ''),
        'workshop-attendees' => 'Workshop attendee PDF' . (is_string($details['workshop'] ?? null) ? ': ' . $details['workshop'] : ''),
        'association' => 'Association batch' . (isset($query['id']) ? ' #' . (int) $query['id'] : ''),
        'association-guests' => 'Association guests list',
        'speaker-tickets' => 'Speaker list',
        default => $what !== '' ? ucfirst(str_replace('-', ' ', $what)) : 'A list',
    };
    $parts = [$name];
    $format = (string) ($details['format'] ?? $query['format'] ?? '');
    $parts[] = in_array($what, ['all', 'workshop-attendees', 'association-guests', 'speaker-tickets'], true) || $format === 'pdf' ? 'PDF' : 'Excel';
    $sorts = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A to Z', 'name_desc' => 'Name Z to A', 'latest' => 'Latest arrivals first', 'earliest' => 'Earliest arrivals first'];
    if (is_string($query['sort'] ?? null) && $query['sort'] !== '') {
        $parts[] = 'Order: ' . ($sorts[$query['sort']] ?? ucfirst(str_replace('_', ' ', $query['sort'])));
    } elseif ($what === 'list') {
        $parts[] = 'Order: the usual order of this list';
    }
    if ($list !== null) {
        $chosen = array_column(\Ismile\Admin\Lists::describe($list, \Ismile\Admin\Lists::filters($query)), 'label');
    } else {
        $chosen = [];
        foreach ($query as $key => $value) {
            if (in_array($key, ['what', 'format', 'sort', 'list', 'id', 'workspace', 'page'], true) || !is_string($value) || trim($value) === '') continue;
            $names = ['q' => 'Search', 'type' => 'Ticket', 'lang' => 'Language', 'arrival_date' => 'Arrival date', 'from_time' => 'From', 'to_time' => 'To', 'staff' => 'Checked in by'];
            $chosen[] = ($names[$key] ?? ucfirst(str_replace('_', ' ', (string) $key))) . ': ' . $value;
        }
    }
    $parts[] = $chosen ? 'Filters: ' . implode(', ', $chosen) : 'No filters';
    if (isset($details['rows'])) {
        // A Lists download saved its title line in the count too, so one less is the number of people.
        $people = max(0, (int) $details['rows'] - ($what === 'list' ? 1 : 0));
        $parts[] = 'In the file: ' . number_format($people) . ($people === 1 ? ' person' : ' people');
    }
    // Everyone registered at that moment (paid or free ticket by then, and not cancelled before it).
    $registered = (int) Db::value("SELECT COUNT(*) FROM registrations WHERE paid_at <= ? AND (status IN ('paid','complimentary') OR (status = 'cancelled' AND updated_at > ?))", [$at, $at]);
    $parts[] = 'Registered at that time: ' . number_format($registered);
    return $parts;
};
$id = (int) $account['id'];
$total = (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE user_id = ?', [$id]);
$byAction = Db::all('SELECT action, COUNT(*) AS n, MAX(created_at) AS last_at FROM audit_log WHERE user_id = ? GROUP BY action ORDER BY n DESC, action LIMIT 40', [$id]);
$recent = Db::all('SELECT action, target_type, target_id, details, ip, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$id]);
$aboutThem = Db::all("SELECT a.action, a.created_at, a.details, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id WHERE a.target_type = 'admin_user' AND a.target_id = ? AND (a.user_id IS NULL OR a.user_id <> ?) ORDER BY a.id DESC LIMIT 20", [$id, $id]);
$added = [
    'Registered people (cash or by phone)' => (int) Db::value("SELECT COUNT(*) FROM registrations WHERE created_by = ? AND status IN ('paid','complimentary')", [$id]),
    'Association guests added' => (int) Db::value('SELECT COUNT(*) FROM association_guests WHERE added_by = ?', [$id]),
    'Workshop seats booked' => (int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE booked_by = ? AND removed_at IS NULL', [$id]),
];
$grants = array_filter([
    'Association guests (everything)' => (int) ($account['association_guest_access'] ?? 0) === 1,
    'Paid and free registration' => (int) ($account['registration_manage_access'] ?? 0) === 1,
    'Workshop booking for everyone' => (int) ($account['workshop_booking_access'] ?? 0) === 1,
    'Badges and certificates' => (int) ($account['badge_certificate_access'] ?? 0) === 1,
    'Association (everything)' => (int) ($account['association_manage_access'] ?? 0) === 1,
    'Sponsor map booking' => (int) ($account['map_booking_access'] ?? 0) === 1,
]);
$role = $roleGuide[$account['role']] ?? ['users', ucfirst((string) $account['role']), ''];
$pages = [];
foreach (Page::MENU as $file => [$pageLabel, $area]) {
    $open = $area === 'owner' ? $account['role'] === 'owner' : Auth::can($account, $area);
    if (!$open) continue;
    $pages[] = [$file, $pageLabel, Auth::viewerMayPost($account, '/admin/' . $file . '.php')];
}
?>
<section class="card account-overview">
  <div class="account-overview-who">
    <span class="team-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $account['name'], 0, 1))) ?></span>
    <div><h2><?= $e($account['name']) ?></h2><p class="muted" dir="ltr"><?= $e($account['email']) ?></p>
      <div class="account-chips"><span class="pill <?= $account['disabled_at'] ? 'grey' : 'green' ?>"><?= $account['disabled_at'] ? 'Disabled' : 'Active' ?></span><span class="pill <?= (int) $account['totp_enabled'] ? 'green' : 'gold' ?>"><?= (int) $account['totp_enabled'] ? 'Phone code on' : 'Phone code off' ?></span></div></div>
  </div>
  <dl class="account-facts">
    <div><dt>Role</dt><dd><b><?= $e($role[1]) ?></b><small><?= $e($role[2]) ?></small></dd></div>
    <div><dt>Extra access</dt><dd><?php if ($grants): foreach (array_keys($grants) as $grant): ?><span class="added-by-tag association"><?= $e($grant) ?></span> <?php endforeach; else: ?><span class="muted">None</span><?php endif; ?><?php foreach (Auth::hiddenPages($account) as $hiddenArea): ?><span class="added-by-tag hidden-tag">Hidden: <?= $e(Auth::HIDEABLE_PAGES[$hiddenArea]) ?></span> <?php endforeach; ?></dd></div>
    <div><dt>Account made</dt><dd><?= $e(date('j M Y, H:i', strtotime((string) $account['created_at']))) ?></dd></div>
    <div><dt>Last sign-in</dt><dd><?= $account['last_login_at'] ? $e(date('j M Y, H:i', strtotime((string) $account['last_login_at']))) : '<span class="muted">Never signed in</span>' ?></dd></div>
  </dl>
</section>

<section class="card">
  <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('view') ?></span><div><h2>Pages this account can open <span class="result-count"><?= count($pages) ?></span></h2><p>Green = can add or change things there. Grey = can only look.</p></div></div>
  <div class="account-pages">
    <?php foreach ($pages as [$file, $pageLabel, $change]): ?><span class="account-page <?= $change ? 'can-change' : 'view-only' ?>"><?= Page::navIcon($file) ?><b><?= $e($pageLabel) ?></b><small><?= $change ? 'Can change' : 'View only' ?></small></span><?php endforeach; ?>
    <?php if (!$pages): ?><span class="muted">No pages.</span><?php endif; ?>
  </div>
</section>

<?php if ($account['role'] !== 'owner' && !Erase::isMainOwnerAccount($account)):
    $hidden = Auth::hiddenPages($account);
    $roleOnly = $account; $roleOnly['hidden_pages'] = '';
    $home = ['checkin' => 'checkin', 'map_viewer' => 'map', 'badges' => 'certificates', 'volunteer' => 'desk'][$account['role']] ?? ''; ?>
<section class="card">
  <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('settings') ?></span><div><h2>Hide pages from this account</h2><p>Tick a page to hide it. A hidden page leaves the menu and cannot be opened, whatever the role allows.</p></div></div>
  <form method="post" action="users.php" class="hide-pages-form">
    <?= Page::csrfField() ?><input type="hidden" name="do" value="hidden_pages"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="account">
    <div class="hide-pages">
      <?php foreach (Auth::HIDEABLE_PAGES as $area => $pageName): if (!Auth::can($roleOnly, $area) && !in_array($area, $hidden, true)) continue; $isHome = $area === $home; ?>
      <label class="hide-page<?= $isHome ? ' is-home' : '' ?>"><input type="checkbox" name="hide[]" value="<?= $e($area) ?>"<?= in_array($area, $hidden, true) ? ' checked' : '' ?><?= $isHome ? ' disabled' : '' ?>><span><b><?= $e($pageName) ?></b><small><?= $isHome ? 'Start page of this role' : (in_array($area, $hidden, true) ? 'Hidden' : 'Shown') ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <div class="form-actions"><button class="btn">Save hidden pages</button></div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('registrations') ?></span><div><h2>What this account has done</h2><p><?= number_format($total) ?> recorded actions in the activity log<?= $recent ? ', the latest on ' . $e(date('j M Y, H:i', strtotime((string) $recent[0]['created_at']))) : '' ?>.</p></div></div>
  <div class="attendance-tiles account-added">
    <?php $tones = ['blue', 'day2', 'day1']; $i = 0; foreach ($added as $what => $n): ?><div class="attendance-tile <?= $tones[$i++] ?>"><span><?= $e($what) ?></span><strong><?= number_format($n) ?></strong></div><?php endforeach; ?>
  </div>
  <?php if ($byAction): ?>
  <div class="table-wrap account-table"><table><thead><tr><th>Action</th><th class="num">Times</th><th>Last time</th></tr></thead><tbody>
    <?php foreach ($byAction as $row): ?><tr><td><?= $e($label((string) $row['action'])) ?></td><td class="num"><?= number_format((int) $row['n']) ?></td><td><?= $e(date('j M Y, H:i', strtotime((string) $row['last_at']))) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><?= Page::emptyState('Nothing recorded yet', 'This account has not done anything that the activity log records.', 'registrations') ?><?php endif; ?>
</section>

<?php if ($recent): ?>
<section class="card">
  <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('clock') ?></span><div><h2>Latest actions <span class="result-count"><?= count($recent) ?></span></h2><p>Newest first, Iraq time.</p></div></div>
  <div class="table-wrap account-table registration-directory-scroll"><table><thead><tr><th>When</th><th>Action</th><th>On</th><th>From</th></tr></thead><tbody>
    <?php foreach ($recent as $row):
        $details = json_decode((string) $row['details'], true);
        $ref = is_array($details) ? ($details['ref'] ?? null) : null;
        $target = $row['action'] === 'export' && is_array($details) ? '<span class="account-export">' . implode('', array_map(static fn (string $part): string => '<span>' . Page::e($part) . '</span>', $exportText($details, (string) $row['created_at']))) . '</span>'
            : ($row['target_type'] === 'registration' && $row['target_id'] ? '<a href="registration.php?id=' . (int) $row['target_id'] . '">Guest #' . (int) $row['target_id'] . '</a>'
            : ($ref ? '<code>' . $e((string) $ref) . '</code>' : ($row['target_type'] ? $e(str_replace('_', ' ', (string) $row['target_type'])) . ($row['target_id'] ? ' #' . (int) $row['target_id'] : '') : '–'))); ?>
    <tr><td><?= $e(date('j M, H:i:s', strtotime((string) $row['created_at']))) ?></td><td><?= $e($label((string) $row['action'])) ?></td><td><?= $target ?></td><td dir="ltr"><span class="muted"><?= $e((string) ($row['ip'] ?? '')) ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($aboutThem): ?>
<section class="card">
  <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('settings') ?></span><div><h2>Changes made to this account</h2><p>By you, another Owner or the deploy.</p></div></div>
  <div class="table-wrap account-table"><table><thead><tr><th>When</th><th>Change</th><th>By</th></tr></thead><tbody>
    <?php foreach ($aboutThem as $row): ?><tr><td><?= $e(date('j M Y, H:i', strtotime((string) $row['created_at']))) ?></td><td><?= $e($label((string) $row['action'])) ?></td><td><?= str_contains((string) $row['details'], '"by":"deploy"') ? 'The deploy (on your request)' : $e((string) ($row['name'] ?? 'The system')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<?php
Page::bottom();
exit;
