<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
use Ismile\Auth;
use Ismile\Certificates;
use Ismile\Db;
use Ismile\PsoolaTickets;
use Ismile\Registrations;
use Ismile\UserError;
use Ismile\App;

$user = Page::guard('certificates');
$inCheckin = ($_GET['workspace'] ?? '') === 'checkin';
if ($inCheckin) Page::redirect('certificates.php');
Page::action(static function () use ($user): string {
    $action=(string)($_POST['do']??'');
    if (!in_array($action,['email_all','email_one'],true)) throw new UserError('Choose a certificate email action.');
    $id=$action==='email_one'?(int)($_POST['registration_id']??0):null;
    if ($id!==null && $id<1) throw new UserError('Choose a participant.');
    $result=Certificates::queueEmails($id,$user,$action==='email_one');
    return $result['queued'].' certificate email(s) queued. '.$result['skipped'].' already sent, pending or unavailable. Each guest receives only their own PDF.';
}, 'certificates.php');
$total = (int) Db::value('SELECT COUNT(*) FROM certificates c JOIN registrations r ON r.id=c.registration_id JOIN tickets t ON t.registration_id=r.id WHERE ' . Certificates::ELIGIBLE_SQL);
$dayCounts = Db::all('SELECT a.event_day, COUNT(*) AS n FROM ticket_attendance a JOIN tickets t ON t.id=a.ticket_id JOIN registrations r ON r.id=t.registration_id WHERE ' . Certificates::ELIGIBLE_SQL . ' GROUP BY a.event_day');
$counts = array_column($dayCounts, 'n', 'event_day');
$waiting = (int) Db::value("SELECT COUNT(*) FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL AND NOT EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id=t.id)");
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = min($page - 1, 100000) * 50;
$rows = Db::all("SELECT c.*, r.ref, r.email, (SELECT CASE WHEN e.provider_message_id LIKE 'skipped:%' THEN 'skipped' ELSE e.status END FROM emails e WHERE e.registration_id=r.id AND e.kind='certificate' AND e.to_email=r.email ORDER BY e.id DESC LIMIT 1) AS email_status, (SELECT GROUP_CONCAT(CONCAT('Day ', a.event_day) ORDER BY a.event_day SEPARATOR ' & ') FROM ticket_attendance a WHERE a.ticket_id=t.id) AS attended_days FROM certificates c JOIN registrations r ON r.id=c.registration_id JOIN tickets t ON t.registration_id=r.id WHERE " . Certificates::ELIGIBLE_SQL . " ORDER BY c.recipient_name, c.id LIMIT 50 OFFSET $offset");
// Event tickets: everyone with an active ticket, with their original PSOOLA QR.
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$ticketWhere = "r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL";
$ticketParams = [];
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $ticketWhere .= " AND (CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ? OR r.ref LIKE ? OR r.phone LIKE ? OR t.ticket_no LIKE ?)";
    $ticketParams = [$like, $like, $like, $like];
}
$ticketTotal = (int) Db::value("SELECT COUNT(*) FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $ticketWhere", $ticketParams);
$ticketPage = max(1, (int) ($_GET['tpage'] ?? 1));
$ticketOffset = min($ticketPage - 1, 100000) * 50;
$tickets = Db::all("SELECT r.id, r.ref, r.first_name, r.father_name, r.grandfather_name, r.ticket_type, r.status, r.created_by, t.ticket_no,
    (EXISTS (SELECT 1 FROM sponsored_ticket_orders s WHERE s.registration_id=r.id AND s.status='issued' AND s.provider_order_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM payments p WHERE p.registration_id=r.id AND p.gateway='psoola' AND p.status='paid' AND p.provider_payment_id IS NOT NULL)) AS has_psoola,
    (SELECT GROUP_CONCAT(CONCAT('Day ', a.event_day) ORDER BY a.event_day SEPARATOR ' & ') FROM ticket_attendance a WHERE a.ticket_id=t.id) AS attended_days
    FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE $ticketWhere
    ORDER BY r.first_name, r.father_name, r.grandfather_name, r.id LIMIT 50 OFFSET $ticketOffset", $ticketParams);
$ticketUrl = static fn (int $to): string => '?' . http_build_query(['q' => $search !== '' ? $search : null, 'tpage' => $to]) . '#tickets';
Page::top('Certificates & Tickets', 'certificates', $total ? '<form method="post" class="inline-form">'.Page::csrfField().'<input type="hidden" name="do" value="email_all"><button class="btn">'.Page::navIcon('mail').'<span>Send all certificates by email</span></button></form><a class="btn green" href="certificate-download.php">Download all certificates (PDF)</a>' : '');
if ($inCheckin) echo CheckinWorkspace::navigation('certificate',$user,isset($_GET['day']) ? (int)$_GET['day'] : null);
$e = [Page::class, 'e'];
$detailContext = $inCheckin ? '&workspace=checkin' . (isset($_GET['day']) && in_array((int)$_GET['day'],[1,2],true) ? '&day='.(int)$_GET['day'] : '') : '';
?>
<section class="card" id="tickets" data-registration-details data-csrf="<?= $e(Auth::csrf()) ?>">
  <div class="panel-top"><?= Page::panelHeading('Event tickets', 'Show a guest’s original PSOOLA QR, one by one. PSOOLA’s gate app scans it at the door.', 'ticket') ?></div>
  <form method="get" action="certificates.php#tickets" class="inline-form" role="search">
    <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Name, reference, phone or ticket number" aria-label="Search tickets">
    <button class="btn ghost"><?= Page::navIcon('search') ?><span>Search</span></button>
    <?php if ($search !== ''): ?><a class="btn ghost" href="certificates.php#tickets">Clear</a><?php endif; ?>
  </form>
  <p class="rd-link-result" role="status" data-rd-link-result></p>
  <div data-live-region="ticket-directory">
  <div class="table-wrap"><table><tr><th>Name</th><th>Reference</th><th>Ticket</th><th>Type</th><th>Attendance</th><th>PSOOLA QR</th></tr>
  <?php foreach ($tickets as $row): $canQr = PsoolaTickets::canView($user, $row); ?><tr><td><a href="registration.php?id=<?= (int)$row['id'] ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a></td><td><?= $e($row['ref']) ?></td><td><code><?= $e($row['ticket_no']) ?></code></td><td><?= $e(Registrations::ticketName($row['ticket_type'])) ?></td><td><?= $row['attended_days'] ? $e($row['attended_days']) : 'Not yet' ?></td><td><?php if (!$row['has_psoola']): ?><span class="muted small">No PSOOLA ticket</span><?php elseif (!$canQr): ?><span class="muted small">No access</span><?php else: ?><button class="btn small" type="button" data-rd-ticket="psoola" data-registration-id="<?= (int)$row['id'] ?>"><?= Page::navIcon('ticket') ?><span>Show QR</span></button><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$tickets): ?><tr><td colspan="6"><?= $search !== '' ? Page::emptyState('No ticket found', 'Try another name, reference, phone or ticket number.', 'search') : Page::emptyState('No tickets yet', 'Tickets appear here once a guest is registered.', 'ticket') ?></td></tr><?php endif; ?>
  </table></div>
  <p class="muted small"><?= number_format($ticketTotal) ?> ticket(s). Private ticket access: show a QR only to its holder.</p>
  <?php if ($ticketPage > 1): ?><a class="btn ghost" href="<?= $e($ticketUrl($ticketPage - 1)) ?>">Previous</a><?php endif; ?>
  <?php if ($ticketOffset + 50 < $ticketTotal): ?><a class="btn ghost" href="<?= $e($ticketUrl($ticketPage + 1)) ?>">Next</a><?php endif; ?>
  </div>
  <dialog class="rd-dialog rd-provider-dialog" data-rd-psoola-dialog aria-labelledby="rd-psoola-title"><div class="rd-dialog-heading"><div><h2 id="rd-psoola-title">PSOOLA tickets</h2><p>Original ticket details and QR code</p></div><button type="button" class="icon-button" data-rd-close aria-label="Close PSOOLA tickets"><?= Page::navIcon('close') ?></button></div><div data-rd-psoola-body></div></dialog>
</section>
<p class="muted small">Certificates are emailed only when you press an email button. Each guest receives their own PDF. Bulk sending skips certificates already sent or waiting to send. <?php if(App::config('mail.driver')==='log'): ?>Local preview: emails and PDF attachments are saved on this computer.<?php else: ?>Queued emails are delivered by the email job; check the Communication center for progress.<?php endif; ?></p>
<div data-live-region="certificate-totals">
<?= Page::stats([
    ['Certificates ready', number_format($total), 'Created automatically after attendance', 'check', 'teal'],
    ['Day 1 arrivals', number_format((int)($counts[1] ?? 0)), 'One admission per guest on Day 1', 'checkin', 'blue'],
    ['Day 2 arrivals', number_format((int)($counts[2] ?? 0)), 'One admission per guest on Day 2', 'checkin', 'violet'],
    ['Awaiting attendance', number_format($waiting), 'No certificate until the guest attends', 'clock', 'gold'],
]) ?>
</div>
<div class="grid2">
  <section class="card certificate-art" aria-label="Certificate design preview">
    <span class="certificate-brand">iSmile <b>2026</b></span><small>DENTAL SUMMIT · SULAIMANI</small>
    <h2>Certificate of<br>Participation</h2><p>This certificate is proudly presented to</p>
    <strong class="certificate-name">Guest’s full name</strong>
    <p>In recognition of participation in iSmile 2026<br>Grand Millennium Sulaimani · 19–20 November 2026</p>
    <b>iSmile Organizing Committee</b><small>Certificate number · Verified attendance</small>
  </section>
  <section class="card"><?= Page::panelHeading('Attendance earns the certificate', 'Every confirmed arrival creates the guest’s named certificate.', 'checkin') ?>
    <ol class="welcome-steps"><li><b>Confirm attendance</b><span>Psoola scans the ticket at the door; the scans are copied here every 5 minutes. Free tickets are marked attended in Registration.</span></li><li><b>Their name is added automatically</b><span>Attendance on either day qualifies. Payment without attendance does not.</span></li><li><b>Send or download manually</b><span>Press an email button to send personal PDFs. Download one certificate or all eligible certificates in a combined PDF. Check-in never sends certificate emails.</span></li></ol>
    <p class="muted small">The PDF uses this iSmile design. Final artwork can replace the background later. Correcting a guest’s name updates their certificate.</p>
    <a class="btn ghost" href="export.php?what=attendance&amp;format=pdf">Download attendance report (PDF)</a>
  </section>
</div>
<section class="card" data-live-region="certificate-directory"><div class="panel-top"><?= Page::panelHeading('Participant certificates', 'Only guests with recorded attendance appear here.', 'users') ?></div>
<div class="table-wrap"><table><tr><th>Name</th><th>Reference</th><th>Attendance</th><th>Certificate</th><th>Created</th><th>Email</th><th>Delivery</th><th>Actions</th></tr>
<?php foreach ($rows as $row): ?><tr><td><a href="registration.php?id=<?= (int)$row['registration_id'] ?><?= $e($detailContext) ?>"><b><?= $e($row['recipient_name']) ?></b></a></td><td><?= $e($row['ref']) ?></td><td><?= $e($row['attended_days']) ?></td><td><code><?= $e($row['certificate_no']) ?></code></td><td><?= Page::when($row['issued_at']) ?></td><td><?= $e($row['email']) ?></td><td><?= $row['email_status']?Page::pill($row['email_status']):'Not sent' ?></td><td><div class="certificate-delivery-actions"><a class="btn small ghost" href="certificate-download.php?id=<?= (int)$row['registration_id'] ?>">PDF</a><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="email_one"><input type="hidden" name="registration_id" value="<?= (int)$row['registration_id'] ?>"><button class="btn small ghost"<?= $row['email_status']==='pending'?' disabled':'' ?>><?= match($row['email_status']){'sent'=>'Resend email','pending'=>'Email queued','failed'=>'Retry email',default=>'Send PDF by email'} ?></button></form></div></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8"><?= Page::emptyState('Waiting for your first participant', 'Certificates appear here after a Psoola gate scan or a free ticket marked attended.', 'checkin') ?></td></tr><?php endif; ?>
</table></div>
<?php if ($page > 1): ?><a class="btn ghost" href="<?= $e($inCheckin ? CheckinWorkspace::url('certificate',isset($_GET['day'])?(int)$_GET['day']:null,['page'=>$page-1]) : '?page='.($page-1)) ?>">Previous</a><?php endif; ?>
<?php if ($offset+50 < $total): ?><a class="btn ghost" href="<?= $e($inCheckin ? CheckinWorkspace::url('certificate',isset($_GET['day'])?(int)$_GET['day']:null,['page'=>$page+1]) : '?page='.($page+1)) ?>">Next</a><?php endif; ?>
</section>
<?php Page::bottom();
