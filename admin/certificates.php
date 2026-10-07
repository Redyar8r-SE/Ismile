<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
use Ismile\Certificates;
use Ismile\Db;
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
Page::top('Certificates', 'certificates', $total ? '<form method="post" class="inline-form">'.Page::csrfField().'<input type="hidden" name="do" value="email_all"><button class="btn">'.Page::navIcon('mail').'<span>Send all certificates by email</span></button></form><a class="btn green" href="certificate-download.php">Download all certificates (PDF)</a>' : '');
if ($inCheckin) echo CheckinWorkspace::navigation('certificate',$user,isset($_GET['day']) ? (int)$_GET['day'] : null);
$e = [Page::class, 'e'];
$detailContext = $inCheckin ? '&workspace=checkin' . (isset($_GET['day']) && in_array((int)$_GET['day'],[1,2],true) ? '&day='.(int)$_GET['day'] : '') : '';
?>
<?= Page::liveUpdates() ?>
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
    <p>In recognition of participation in iSmile 2026<br>Grand Millennium Sulaimani · 20–21 November 2026</p>
    <b>iSmile Organizing Committee</b><small>Certificate number · Verified attendance</small>
  </section>
  <section class="card"><?= Page::panelHeading('Attendance earns the certificate', 'Every confirmed arrival creates the guest’s named certificate.', 'checkin') ?>
    <ol class="welcome-steps"><li><b>Confirm attendance</b><span>Scan a QR or verify a guest lookup. One admission is allowed each day.</span></li><li><b>Their name is added automatically</b><span>Attendance on either day qualifies. Payment without attendance does not.</span></li><li><b>Send or download manually</b><span>Press an email button to send personal PDFs. Download one certificate or all eligible certificates in a combined PDF. Check-in never sends certificate emails.</span></li></ol>
    <p class="muted small">The PDF uses this iSmile design. Final artwork can replace the background later. Correcting a guest’s name updates their certificate.</p>
    <a class="btn ghost" href="export.php?what=attendance&amp;format=pdf">Download attendance report (PDF)</a>
  </section>
</div>
<section class="card" data-live-region="certificate-directory"><div class="panel-top"><?= Page::panelHeading('Participant certificates', 'Only guests with recorded attendance appear here.', 'users') ?></div>
<div class="table-wrap"><table><tr><th>Name</th><th>Reference</th><th>Attendance</th><th>Certificate</th><th>Created</th><th>Email</th><th>Delivery</th><th>Actions</th></tr>
<?php foreach ($rows as $row): ?><tr><td><a href="registration.php?id=<?= (int)$row['registration_id'] ?><?= $e($detailContext) ?>"><b><?= $e($row['recipient_name']) ?></b></a></td><td><?= $e($row['ref']) ?></td><td><?= $e($row['attended_days']) ?></td><td><code><?= $e($row['certificate_no']) ?></code></td><td><?= Page::when($row['issued_at']) ?></td><td><?= $e($row['email']) ?></td><td><?= $row['email_status']?Page::pill($row['email_status']):'Not sent' ?></td><td><div class="certificate-delivery-actions"><a class="btn small ghost" href="certificate-download.php?id=<?= (int)$row['registration_id'] ?>">PDF</a><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="email_one"><input type="hidden" name="registration_id" value="<?= (int)$row['registration_id'] ?>"><button class="btn small ghost"<?= $row['email_status']==='pending'?' disabled':'' ?>><?= match($row['email_status']){'sent'=>'Resend email','pending'=>'Email queued','failed'=>'Retry email',default=>'Send PDF by email'} ?></button></form></div></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8"><?= Page::emptyState('Waiting for your first participant', 'Certificates appear here after QR or verified manual check-in.', 'checkin') ?></td></tr><?php endif; ?>
</table></div>
<?php if ($page > 1): ?><a class="btn ghost" href="<?= $e($inCheckin ? CheckinWorkspace::url('certificate',isset($_GET['day'])?(int)$_GET['day']:null,['page'=>$page-1]) : '?page='.($page-1)) ?>">Previous</a><?php endif; ?>
<?php if ($offset+50 < $total): ?><a class="btn ghost" href="<?= $e($inCheckin ? CheckinWorkspace::url('certificate',isset($_GET['day'])?(int)$_GET['day']:null,['page'=>$page+1]) : '?page='.($page+1)) ?>">Next</a><?php endif; ?>
</section>
<?php Page::bottom();
