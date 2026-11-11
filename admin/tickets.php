<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Badges;
use Ismile\Db;
use Ismile\PsoolaTickets;
use Ismile\Registrations;
use Ismile\UserError;

// Event tickets: everyone with an active ticket, with their original PSOOLA QR.
$user = Page::guard('certificates');
// The Owner's licensed badge font, kept in private storage (never in the repository).
Page::action(static fn (): string => match ((string) ($_POST['do'] ?? '')) {
    'badge_font' => Badges::installFont($user, $_FILES),
    'badge_font_remove' => Badges::removeFont($user),
    default => throw new UserError('Choose a badge font action.'),
}, 'tickets.php');
$e = [Page::class, 'e'];
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$where = "r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL";
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $where .= " AND (CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ? OR r.ref LIKE ? OR r.phone LIKE ? OR t.ticket_no LIKE ?)";
    $params = [$like, $like, $like, $like];
}
$total = (int) Db::value("SELECT COUNT(*) FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $where", $params);
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = min($page - 1, 100000) * 50;
$rows = Db::all("SELECT r.id, r.ref, r.first_name, r.father_name, r.grandfather_name, r.ticket_type, r.status, r.created_by, t.ticket_no,
    (EXISTS (SELECT 1 FROM sponsored_ticket_orders s WHERE s.registration_id=r.id AND s.status='issued' AND s.provider_order_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM payments p WHERE p.registration_id=r.id AND p.gateway='psoola' AND p.status='paid' AND p.provider_payment_id IS NOT NULL)) AS has_psoola,
    (SELECT GROUP_CONCAT(CONCAT('Day ', a.event_day) ORDER BY a.event_day SEPARATOR ' & ') FROM ticket_attendance a WHERE a.ticket_id=t.id) AS attended_days
    FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $where
    ORDER BY r.first_name, r.father_name, r.grandfather_name, r.id LIMIT 50 OFFSET $offset", $params);
$pageUrl = static fn (int $to): string => '?' . http_build_query(['q' => $search !== '' ? $search : null, 'page' => $to]);
Page::top('Tickets', 'tickets');
echo Page::ticketTabs('tickets');
?>
<section class="card" id="tickets" data-registration-details data-csrf="<?= $e(Auth::csrf()) ?>">
  <div class="panel-top"><?= Page::panelHeading('Event tickets', 'Show a guest’s original PSOOLA QR, one by one. PSOOLA’s gate app scans it at the door.', 'ticket') ?></div>
  <form method="get" action="tickets.php" class="inline-form" role="search">
    <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Name, reference, phone or ticket number" aria-label="Search tickets">
    <button class="btn ghost"><?= Page::navIcon('search') ?><span>Search</span></button>
    <?php if ($search !== ''): ?><a class="btn ghost" href="tickets.php">Clear</a><?php endif; ?>
  </form>
  <p class="rd-link-result" role="status" data-rd-link-result></p>
  <div data-live-region="ticket-directory">
  <div class="table-wrap"><table><tr><th>Name</th><th>Reference</th><th>Ticket</th><th>Type</th><th>Attendance</th><th>PSOOLA QR</th></tr>
  <?php foreach ($rows as $row): $canQr = PsoolaTickets::canView($user, $row); ?><tr><td><a href="registration.php?id=<?= (int)$row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a></td><td><?= $e($row['ref']) ?></td><td><code><?= $e($row['ticket_no']) ?></code></td><td><?= $e(Registrations::ticketName($row['ticket_type'])) ?></td><td><?= $row['attended_days'] ? $e($row['attended_days']) : 'Not yet' ?></td><td><?php if (!$row['has_psoola']): ?><span class="muted small">No PSOOLA ticket</span><?php elseif (!$canQr): ?><span class="muted small">No access</span><?php else: ?><div class="certificate-delivery-actions"><button class="btn small" type="button" data-rd-ticket="psoola" data-registration-id="<?= (int)$row['id'] ?>"><?= Page::navIcon('ticket') ?><span>Show QR</span></button><a class="btn small ghost" href="badge.php?id=<?= (int)$row['id'] ?>"><?= Page::navIcon('download') ?><span>Badge PDF</span></a><a class="btn small ghost" href="badge.php?id=<?= (int)$row['id'] ?>&amp;print=1" target="_blank" rel="noopener">Print</a></div><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6"><?= $search !== '' ? Page::emptyState('No ticket found', 'Try another name, reference, phone or ticket number.', 'search') : Page::emptyState('No tickets yet', 'Tickets appear here once a guest is registered.', 'ticket') ?></td></tr><?php endif; ?>
  </table></div>
  <p class="muted small"><?= number_format($total) ?> ticket(s). Private ticket access: show a QR only to its holder.</p>
  <?php if ($page > 1): ?><a class="btn ghost" href="<?= $e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
  <?php if ($offset + 50 < $total): ?><a class="btn ghost" href="<?= $e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
  </div>
  <?php if ($user['role'] === 'owner'): $licensed = Badges::hasLicensedFont(); ?>
  <details class="badge-font"><summary>Badge font: <b><?= $licensed ? 'Helvetica Now Display (uploaded)' : 'Inter Display (free match)' ?></b></summary>
    <form method="post" enctype="multipart/form-data" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="badge_font">
      <label>Black (.ttf) <input type="file" name="font_black" accept=".ttf" required></label>
      <label>Medium (.ttf) <input type="file" name="font_medium" accept=".ttf" required></label>
      <button class="btn small">Upload licensed font</button>
    </form>
    <?php if ($licensed): ?><form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="badge_font_remove"><button class="btn small ghost">Use the free font again</button></form><?php endif; ?>
    <p class="muted small">Only upload a font your licence covers. It is stored privately on the server, never on GitHub.</p>
  </details>
  <?php endif; ?>
  <dialog class="rd-dialog rd-provider-dialog" data-rd-psoola-dialog aria-labelledby="rd-psoola-title"><div class="rd-dialog-heading"><div><h2 id="rd-psoola-title">PSOOLA tickets</h2><p>Original ticket details and QR code</p></div><button type="button" class="icon-button" data-rd-close aria-label="Close PSOOLA tickets"><?= Page::navIcon('close') ?></button></div><div data-rd-psoola-body></div></dialog>
</section>
<?php Page::bottom();
