<?php
declare(strict_types=1);

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Erase;
use Ismile\GuestOrders;
use Ismile\GuestWorkshops;
use Ismile\Links;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\Workshops;
use Ismile\WorkshopCatalog;

if (!isset($registration, $user, $id)) { http_response_code(404); exit; }

$ticket = Tickets::forRegistration($id);
$activeTicket = $ticket && $ticket['cancelled_at'] === null;
$attendance = $activeTicket ? Db::all('SELECT event_day, checked_in_at FROM ticket_attendance WHERE ticket_id=? ORDER BY event_day', [$ticket['id']]) : [];
$payments = Db::all('SELECT * FROM payments WHERE registration_id=? ORDER BY id DESC', [$id]);
$paidPayment = null;
foreach ($payments as $payment) if ($payment['status'] === 'paid') { $paidPayment = $payment; break; }
$bookings = Db::all('SELECT wb.*,u.name AS booked_by_name FROM workshop_bookings wb LEFT JOIN admin_users u ON u.id=wb.booked_by WHERE wb.registration_id=? AND wb.removed_at IS NULL ORDER BY wb.id', [$id]);
$requests = Db::all('SELECT g.*,o.status AS order_status,o.last_error,o.support_request_id,o.email_status,o.whatsapp_status FROM guest_workshop_orders g LEFT JOIN sponsored_ticket_orders o ON o.attempt_key=g.attempt_key WHERE g.registration_id=? AND g.active_pair IS NOT NULL ORDER BY g.id DESC', [$id]);
$guestBookingOrders = [];
foreach ($requests as $request) if ($request['booking_id']) $guestBookingOrders[(int)$request['booking_id']] = $request;
$pending = array_filter($requests, static fn($r) => $r['state'] !== 'issued');
$history = Db::all('SELECT a.*,u.name FROM audit_log a LEFT JOIN admin_users u ON u.id=a.user_id WHERE a.target_type=? AND a.target_id=? ORDER BY a.id DESC LIMIT 50', ['registration',$id]);
$twins = Db::all("SELECT id,ref,first_name,father_name,grandfather_name,status FROM registrations WHERE id<>? AND (email=? OR phone=?) AND status<>'cancelled'", [$id,$registration['email'],$registration['phone']]);
$sponsoredOrder = Db::one("SELECT * FROM sponsored_ticket_orders WHERE registration_id=? AND status='issued'", [$id]);
$canWorkshop = Auth::canBookWorkshops($user);
$canGuestWorkshop = $canWorkshop && Auth::canIssueComplimentary($user) && GuestOrders::enabled() && in_array($registration['status'], ['paid','complimentary'], true);
$eligible = [];
if (Auth::can($user,'workshops')) foreach (Workshops::all() as $workshop) {
    if (!GuestWorkshops::allowed($workshop)) continue;
    $availability = WorkshopCatalog::one($workshop['id']);
    if ($availability && $availability['availabilityVerified']) $workshop = array_replace($workshop, array_intersect_key($availability, array_flip(['scheduledDate','startTime','endTime'])));
    $already = (bool)array_filter($bookings, static fn($b) => $b['workshop_id'] === $workshop['id']);
    $waiting = (bool)array_filter($requests, static fn($r) => $r['workshop_id'] === $workshop['id']);
    $eligible[] = ['workshop'=>$workshop, 'available'=>(bool)($availability['availabilityVerified'] ?? false) && (int)($availability['seatsLeft'] ?? 0)>0,
        'verified'=>(bool)($availability['availabilityVerified'] ?? false), 'blocked'=>$already || $waiting];
}
$isComp = $registration['status'] === 'complimentary';
$statusLabel = match ($registration['status']) {
    'complimentary'=>'Complimentary', 'paid'=>$registration['pay_method'] === 'cash' ? 'Paid by cash' : (in_array($registration['pay_method'],['visa','mastercard','fib','fastpay'],true) ? 'Paid online' : 'Paid'),
    'cancelled'=>'Cancelled', default=>'Awaiting payment',
};
$statusTone = $isComp ? 'violet' : ($registration['status'] === 'paid' ? ($registration['pay_method'] === 'cash' ? 'gold' : 'green') : ($registration['status'] === 'cancelled' ? 'red' : 'gold'));
$total = $isComp ? 0 : ($paidPayment && $paidPayment['amount_confirmed'] !== null ? (int)$paidPayment['amount_confirmed'] : null);
$currency = $paidPayment['currency'] ?? 'IQD';
$fullName = Registrations::fullName($registration);
$specialty = Registrations::SPECIALTY_NAMES[$registration['specialty']] ?? $registration['specialty'];
$initials = mb_strtoupper(mb_substr($registration['first_name'],0,1).mb_substr($registration['father_name'],0,1));
$e = [Page::class,'e'];
$icon = [Page::class,'navIcon'];
$field = static function (string $label, mixed $value, string $glyph = 'user', bool $ltr = false) use ($e,$icon): void { ?>
  <div class="rd-field"><?= $icon($glyph) ?><div><dt><?= $e($label) ?></dt><dd<?= $ltr ? ' dir="ltr"' : '' ?>><?= $e($value !== null && $value !== '' ? $value : '—') ?></dd></div></div>
<?php };
$providerAccess = $sponsoredOrder && (int)$sponsoredOrder['is_complimentary'] && Auth::canIssueComplimentary($user)
    && ($user['role'] === 'owner' || (int)$sponsoredOrder['created_by'] === (int)$user['id']);
$ticketUrl = Links::statusUrl($registration);
Page::top('Registration details', $inCheckin ? 'checkin' : 'registrations', '', true);
if ($inCheckin) echo \Ismile\Admin\CheckinWorkspace::navigation('guests',$user,$workspaceDay);
?>
<section class="rd-profile" data-registration-details data-csrf="<?= $e(Auth::csrf()) ?>">
  <nav class="rd-breadcrumb" aria-label="Breadcrumb"><a href="<?= $e($listUrl) ?>"><span aria-hidden="true">←</span> <?= $inCheckin ? 'Guest list' : 'Registrations' ?></a><span aria-hidden="true">/</span><span>Registration details</span></nav>
  <header class="rd-hero rd-card">
    <span class="rd-avatar" aria-hidden="true"><?= $e($initials) ?></span>
    <div class="rd-identity"><div class="rd-name"><h1><?= $e($fullName) ?></h1><span class="rd-badge <?= $statusTone ?>"><?= $e($statusLabel) ?></span></div><p><code><?= $e($registration['ref']) ?></code><span>·</span><?= $e($specialty) ?><?php if ($registration['sponsoring_company']): ?><span>·</span><?= $e($registration['sponsoring_company']) ?><?php endif; ?></p></div>
    <div class="rd-actions no-print">
      <?php if ($canChange): ?><button class="btn ghost" type="button" data-rd-open="rd-edit"><?= $icon('content') ?><span>Edit details</span></button><?php endif; ?>
      <details class="rd-menu"><summary class="btn ghost" aria-label="More registration actions"><span aria-hidden="true">···</span> More</summary><div class="rd-menu-items">
        <?php if ($canChange && $activeTicket && !Tickets::issuedByPsoola($ticket)): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="resend_ticket"><button><?= $icon('mail') ?>Resend ticket</button></form><?php endif; ?>
        <?php if ($activeTicket && Tickets::issuedByPsoola($ticket)): ?><span class="rd-menu-hint">Resend provider tickets in the PSOOLA partner portal.</span><?php endif; ?>
        <?php if ($attendance && Auth::can($user,'certificates')): ?><a href="certificate-download.php?id=<?= $id ?>"><?= $icon('certificates') ?>Download certificate</a><?php elseif (Auth::can($user,'certificates')): ?><span class="rd-menu-hint"><?= $icon('certificates') ?>Certificate available after attendance</span><?php endif; ?>
        <button type="button" data-rd-open="rd-history"><?= $icon('clock') ?>Activity history</button>
        <?php if ($user['role'] === 'owner' && $registration['status'] !== 'cancelled'): ?><button class="rd-cancel-action" type="button" data-rd-open="rd-cancel"><?= $icon('ticket-x') ?>Cancel registration</button><?php endif; ?>
        <?php if (Erase::isMainOwner($user)):
          $paidFact = $isComp ? 'Complimentary: 0 IQD' : ($paidPayment ? Page::money((int)($paidPayment['amount_confirmed'] ?? $paidPayment['amount_expected']),$paidPayment['currency']) : $statusLabel);
          $eraseBox = Page::confirm('red','trash','Delete this person for good?','Everything about this person is removed. This cannot be undone from the admin.',
            [['user',$fullName],['hash',$registration['ref']],['ticket',Registrations::ticketName($registration['ticket_type']).($ticket ? ' · '.$ticket['ticket_no'] : '')],['cash',$paidFact]],
            ['deletes'=>['Ticket & QR','Check-ins','Certificate','Workshop seats','Payment records','Emails','Student ID photo'],
             'tip'=>$registration['status'] !== 'cancelled' ? 'Use Cancel registration to stop the ticket and keep the history.' : null,
             'type'=>$registration['ref'],'field'=>'confirm_ref','note'=>'Only the main Owner can do this. A full copy is kept in deleted_records.',
             'yes'=>'Yes, delete for good','no'=>'No, keep this person']); ?>
          <form method="post" id="deleteRecord"><?= Page::csrfField() ?><input type="hidden" name="do" value="erase"><input type="hidden" name="confirm_ref" value=""><button class="rd-delete-action"<?= $eraseBox ?>><?= $icon('trash') ?>Delete for good</button></form>
        <?php elseif ($locked = Erase::lockedReason($user)): ?><span class="rd-menu-hint"><?= $e($locked) ?></span><?php endif; ?>
      </div></details>
    </div>
  </header>
  <?php if ($twins): ?><div class="flash warn">Possible duplicate: same email or phone as <?php foreach ($twins as $twin): ?><a href="registration.php?id=<?= (int)$twin['id'] ?><?= $e($workspaceQuery) ?>"><?= $e(Registrations::fullName($twin)) ?> (<?= $e($twin['ref']) ?>)</a> <?php endforeach; ?>. Families may share a phone; check before changing anything.</div><?php endif; ?>
  <div class="rd-columns">
    <aside class="rd-personal">
      <section class="rd-card"><div class="rd-section-title"><h2>Personal information</h2><?= $icon('registrations') ?></div><dl class="rd-fields">
        <?php $field('Full name',$fullName); $field('Email address',$registration['email'],'mail',true); $field('WhatsApp number',$registration['phone'],'phone',true); $field('Specialty',$specialty,'workshops'); if ($registration['sponsoring_company']) $field('Sponsoring company',$registration['sponsoring_company'],'sponsors'); $field('City',$registration['city'],'map'); if ($registration['gender'] || $registration['age']) $field('Gender / age',implode(' · ',array_filter([$registration['gender'] ?? null,$registration['age'] ?? null])),'user'); ?>
      </dl><?php if (!$canChange && Auth::can($user,'edit')): ?><p class="rd-hint">Personal details: you can edit only the people you added yourself.</p><?php endif; ?></section>
      <?php if ($isComp): ?><section class="rd-card"><h2>Complimentary details</h2><dl class="rd-fields"><?php $field('Reason',$registration['comp_reason'],'ticket'); $field('Issued by',$addedBy ?? 'Event administrator','user'); ?></dl></section><?php endif; ?>
      <details class="rd-card rd-information"><summary><span>Registration information</span><span aria-hidden="true">⌄</span></summary><p class="rd-hint">Ticket type, lunch, language and record details</p><dl class="rd-fields">
        <?php $field('Ticket type',Registrations::ticketName($registration['ticket_type']),'ticket'); $field('Lunch',trim(($registration['lunch_day1'] ? 'Day 1 ' : '').($registration['lunch_day2'] ? 'Day 2' : '')) ?: 'None','lunch');
        if ($registration['ticket_type'] === 'vip') $field('VIP included lunch','Day '.$registration['vip_lunch_day'].' · Arrange home badge delivery.','lunch');
        $field('Language',$registration['lang'],'content'); $field('Form sent',Page::when($registration['created_at']),'calendar');
        $field('Added by',$registration['created_by'] === null ? 'Website' : ($addedBy ?? 'A removed account'),'user');
        $field('No-refund terms',$registration['terms_accepted_at'] ? 'Accepted '.Page::when($registration['terms_accepted_at']) : 'Registered by the office','check');
        if ($ticket) { $field('Ticket number',$ticket['ticket_no'],'ticket'); $field('Ticket version / source',$ticket['version'].' / '.$ticket['source'],'ticket'); }
        if ($sponsoredOrder) { $field('PSOOLA order',$sponsoredOrder['provider_order_id'],'psoola-orders'); $field('Provider order total',Page::money((int)$sponsoredOrder['provider_amount'],$sponsoredOrder['provider_currency']),'cash'); }
        ?>
      </dl></details>
      <?php if ($registration['ticket_type'] === 'student'): ?><section class="rd-card"><h2>Student information</h2><dl class="rd-fields"><?php $field('University',$registration['university'],'workshops'); if ($registration['study_stage']) $field('Study stage',Registrations::stageName((int)$registration['study_stage']),'workshops'); if ($registration['ambassador_code']) $field('Ambassador code',$registration['ambassador_code'],'hash'); ?></dl>
        <?php if ($registration['id_photo_id'] && Auth::can($user,'photos')): ?><a class="rd-student-photo" href="photo.php?id=<?= $id ?>" target="_blank" rel="noopener"><img class="idphoto" src="photo.php?id=<?= $id ?>" alt="Student ID photo" loading="lazy"></a><?php elseif ($registration['id_photo_deleted_at']): ?><p class="rd-hint">Photo deleted <?= Page::when($registration['id_photo_deleted_at']) ?>.</p><?php elseif ($registration['created_ip'] === 'office'): ?><p class="rd-hint">Registered by the office; ID seen in person or by phone.</p><?php elseif ($registration['created_by'] === null): ?><p class="rd-hint">No ID photo is stored for this student.</p><?php endif; ?>
      </section><?php endif; ?>
    </aside>
    <div class="rd-main">
      <section class="rd-card rd-admission"><div class="rd-section-title"><h2><?= $icon('ticket') ?><?= $isComp ? 'Guest ticket' : 'Event ticket' ?></h2><span class="rd-badge <?= $activeTicket ? 'green' : ($ticket ? 'red' : 'gold') ?>"><?= $activeTicket ? 'Issued' : ($ticket ? 'Cancelled' : 'Not issued') ?></span></div>
        <div class="rd-ticket-summary"><div><h3>iSmile International Dental Summit</h3><p>19–20 November 2026 <span>·</span> <?= $e(Registrations::ticketName($registration['ticket_type'])) ?></p></div><div class="rd-total"><span><?= $registration['status'] === 'paid' ? 'PAID TOTAL' : 'TOTAL' ?></span><strong><?= $total !== null ? $e(Page::money($total,$currency)) : 'Not confirmed' ?></strong></div></div>
        <?php if ($activeTicket): ?><div class="rd-ticket-actions"><a class="btn violet" href="<?= $e($ticketUrl) ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" data-rd-ticket="view"<?= $providerAccess ? ' data-order-id="'.(int)$sponsoredOrder['id'].'"' : '' ?>><?= $icon('external') ?>View ticket</a><button class="btn ghost" type="button" data-rd-ticket="copy"<?= $providerAccess ? ' data-order-id="'.(int)$sponsoredOrder['id'].'"' : '' ?>><?= $icon('copy') ?>Copy ticket link</button></div><p class="rd-link-result" role="status" data-rd-link-result></p>
        <?php elseif ($ticket): ?><p class="rd-hint">This ticket has been cancelled.</p><?php else: ?><p class="rd-hint">A ticket appears after payment or complimentary issuance is confirmed.</p><?php endif; ?>
      </section>
      <?php if (Auth::can($user,'workshops')): ?><section class="rd-card rd-workshops"><div class="rd-section-title"><h2><?= $icon('workshops') ?>Workshops</h2><span class="rd-badge neutral">iSmile</span></div>
        <?php if (!$bookings): ?><div class="rd-empty"><?= $icon('calendar') ?><div><h3>No workshops booked</h3><p>Choose an iSmile workshop for this attendee.</p></div></div><?php endif; ?>
        <?php foreach ($bookings as $booking): $workshop = Workshops::find($booking['workshop_id']); $guestRequest = $guestBookingOrders[(int)$booking['id']] ?? null; ?>
          <article class="rd-booking"><div class="rd-section-title"><h3><?= $e($workshop ? Workshops::name($workshop) : $booking['workshop_id']) ?></h3><span class="rd-badge <?= $booking['payment_status'] === 'complimentary' ? 'violet' : ($booking['payment_status'] === 'paid' ? 'green' : 'gold') ?>"><?= $e($booking['payment_status'] === 'complimentary' ? 'Complimentary' : ucfirst($booking['payment_status'])) ?></span></div>
          <?php if ($workshop): ?><p class="rd-hint"><?= $e(($workshop['scheduledDate'] ?? '').' · '.($workshop['startTime'] ?? '').'–'.($workshop['endTime'] ?? '')) ?></p><?php endif; ?>
          <p class="rd-hint">Booked by <?= $e($booking['booked_by_name'] ?? 'Website') ?> · <?= Page::when($booking['created_at']) ?> · <?= $booking['payment_status'] === 'complimentary' ? 'Total: 0 IQD' : $e(Workshops::money((int)$booking['price_agreed'],$workshop['currency'] ?? 'IQD')) ?></p>
          <?php if ($guestRequest): ?><p class="rd-hint"><?= $e(json_decode($guestRequest['payload_json'],true)['complimentary_reason']) ?></p><?php if (Auth::canIssueComplimentary($user) && ($user['role'] === 'owner' || (int)$guestRequest['created_by'] === (int)$user['id'])): ?><div class="rd-ticket-actions"><button type="button" class="btn ghost small" data-rd-ticket="view" data-order-id="<?= (int)$guestRequest['guest_order_id'] ?>"><?= $icon('external') ?>View tickets</button><button type="button" class="btn ghost small" data-rd-ticket="copy" data-order-id="<?= (int)$guestRequest['guest_order_id'] ?>"><?= $icon('copy') ?>Copy ticket link</button></div><?php endif; ?><p class="rd-hint">Email: <?= $e(ucfirst($guestRequest['email_status'] ?: 'Pending update')) ?> · WhatsApp: <?= $e(ucfirst($guestRequest['whatsapp_status'] ?: 'Not confirmed')) ?>. Queued or sent does not confirm delivery.</p><p class="rd-hint">PSOOLA manages this ticket and its inventory.</p>
          <?php elseif ($canWorkshop): ?><?= Page::workshopPaymentForm($booking,$user,$back) ?><?php endif; ?>
          </article>
        <?php endforeach; ?>
        <?php foreach ($pending as $request): $workshop = Workshops::find($request['workshop_id']); ?><div class="rd-pending"><h3>Workshop request awaiting confirmation</h3><p><?= $e($workshop ? Workshops::name($workshop) : $request['workshop_id']) ?></p><?php if ($request['last_error']): ?><p><?= $e($request['last_error']) ?><?= $request['support_request_id'] ? ' · Support: '.$e($request['support_request_id']) : '' ?></p><?php endif; ?><p>Resume this saved request. Its original key and details are preserved.</p><?php if ($canGuestWorkshop): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="guest_workshop_retry"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn violet small">Resume saved request</button></form><?php endif; ?></div><?php endforeach; ?>
        <?php if ($canGuestWorkshop && $eligible): ?><button class="btn violet rd-book-trigger" type="button" data-rd-open="rd-workshop"><?= $icon('plus') ?>Book guest workshop</button><?php endif; ?>
        <?php if ($eligible): ?><div class="rd-available"><h3>AVAILABLE WORKSHOPS</h3><?php foreach ($eligible as $choice): $workshop = $choice['workshop']; ?><div class="rd-workshop-option"><div><strong><?= $e(Workshops::name($workshop)) ?></strong><small><?= $e(($workshop['scheduledDate'] ?? '').' · '.($workshop['speaker']['en'] ?? '')) ?></small></div><span class="rd-badge <?= $choice['blocked'] ? 'neutral' : ($choice['available'] ? 'green' : 'gold') ?>"><?= $choice['blocked'] ? 'Existing request' : ($choice['available'] ? 'Available' : ($choice['verified'] ? 'Fully booked' : 'Unavailable')) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($canWorkshop && $registration['status'] !== 'cancelled'): ?><details class="rd-legacy-booking"><summary>Other booking options</summary><?= Page::workshopAddForm($user,$registration,$back,true) ?></details><?php endif; ?>
      </section><?php endif; ?>
      <section class="rd-card rd-attendance"><div class="rd-section-title"><h2>Attendance</h2><div class="rd-days"><?php foreach ([1,2] as $day): $arrival = array_values(array_filter($attendance,static fn($a)=>(int)$a['event_day']===$day))[0] ?? null; ?><span class="<?= $arrival ? 'arrived' : '' ?>" title="<?= $e($arrival ? Page::when($arrival['checked_in_at']) : 'Not checked in') ?>"><?= $icon($arrival ? 'check' : 'clock') ?>Day <?= $day ?></span><?php endforeach; ?></div></div><div class="rd-record-links"><button type="button" data-rd-open="rd-history"><?= $icon('clock') ?>Activity history</button><?php if ($attendance && Auth::can($user,'certificates')): ?><a href="certificate-download.php?id=<?= $id ?>"><?= $icon('certificates') ?>Download certificate</a><?php elseif (Auth::can($user,'certificates')): ?><span class="rd-hint">Certificate available after attendance.</span><?php endif; ?></div></section>
    </div>
  </div>
  <dialog id="rd-history" class="rd-dialog"><div class="rd-dialog-heading"><h2>Activity history</h2><button type="button" class="icon-button" data-rd-close aria-label="Close activity history"><?= $icon('close') ?></button></div><ul class="history"><?php foreach ($history as $item): ?><li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'System') ?> · <?= $e($item['action']) ?><span class="rd-hint"><?= $e(mb_substr((string)$item['details'],0,300)) ?></span></li><?php endforeach; ?><?php if (!$history): ?><li class="rd-hint">No changes yet.</li><?php endif; ?></ul></dialog>
  <?php if ($canChange): ?><dialog id="rd-edit" class="rd-dialog"><div class="rd-dialog-heading"><h2>Edit personal details</h2><button type="button" class="icon-button" data-rd-close aria-label="Close edit details"><?= $icon('close') ?></button></div><form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="edit"><div class="row3"><label>First name<input name="first_name" value="<?= $e($registration['first_name']) ?>" required></label><label>Second name<input name="father_name" value="<?= $e($registration['father_name']) ?>" required></label><label>Third name<input name="grandfather_name" value="<?= $e($registration['grandfather_name']) ?>" required></label></div><div class="row3"><label>Email address<input type="email" name="email" dir="ltr" value="<?= $e($registration['email']) ?>" required></label><label>WhatsApp number<input name="phone" dir="ltr" value="<?= $e($registration['phone']) ?>" required></label><label>City<input name="city" value="<?= $e($registration['city']) ?>"></label></div><p class="rd-hint"><?= $ticket && Tickets::issuedByPsoola($ticket) ? 'Provider ticket names must also be updated in PSOOLA after saving here.' : 'Changing the name reissues the ticket and emails it again.' ?> Every change is logged.</p><div class="rd-dialog-footer"><button class="btn ghost" type="button" data-rd-close>Cancel</button><button class="btn violet">Save details</button></div></form></dialog><?php endif; ?>
  <?php if ($user['role'] === 'owner' && $registration['status'] !== 'cancelled'): ?><dialog id="rd-cancel" class="rd-dialog"><div class="rd-dialog-heading"><h2>Cancel registration</h2><button class="icon-button" type="button" data-rd-close aria-label="Close cancellation"><?= $icon('close') ?></button></div><p>Stops the ticket and frees local workshop bookings. Tickets are non-refundable. Provider-issued tickets must also be managed in PSOOLA.</p><form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel"><label>Reason for cancelling<input name="reason" required maxlength="500"></label><div class="rd-dialog-footer"><button type="button" class="btn ghost" data-rd-close>Keep registration</button><button class="btn red"<?= Page::confirm('gold','ticket-x','Cancel this registration?','The local registration is cancelled and local workshop bookings are removed.',[['user',$fullName.' · '.$registration['ref']],['ban','No money is returned']],['note'=>'The record and payment history are kept. Manage provider tickets separately in PSOOLA.','yes'=>'Yes, cancel it','no'=>'No, keep the ticket']) ?>>Cancel registration</button></div></form></dialog><?php endif; ?>
  <?php if ($canGuestWorkshop && $eligible): ?><dialog id="rd-workshop" class="rd-dialog rd-workshop-dialog"><div class="rd-dialog-heading"><div><h2>Book guest workshop</h2><p><?= $e($fullName) ?> · <?= $e($registration['ref']) ?></p></div><button class="icon-button" type="button" data-rd-close aria-label="Close workshop booking"><?= $icon('close') ?></button></div><form method="post" class="stack" data-rd-workshop-form><?= Page::csrfField() ?><input type="hidden" name="do" value="guest_workshop"><input type="hidden" name="attempt" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="delivery_confirmation" value=""><div class="rd-selected-contact"><span><?= $icon('mail') ?><?= $e($registration['email']) ?></span><span dir="ltr"><?= $icon('phone') ?><?= $e($registration['phone']) ?></span></div><fieldset class="rd-workshop-choices"><legend>iSmile workshops</legend><?php foreach ($eligible as $choice): $workshop = $choice['workshop']; ?><label class="rd-workshop-choice"><input type="radio" name="workshop" value="<?= $e($workshop['id']) ?>" required<?= !$choice['available'] || $choice['blocked'] ? ' disabled' : '' ?>><span><strong><?= $e(Workshops::name($workshop)) ?></strong><small><?= $e(($workshop['scheduledDate'] ?? '').' · '.($workshop['startTime'] ?? '').'–'.($workshop['endTime'] ?? '')) ?></small></span><span class="rd-badge <?= $choice['available'] && !$choice['blocked'] ? 'green' : 'neutral' ?>"><?= $choice['blocked'] ? 'Existing request' : ($choice['available'] ? 'Available' : ($choice['verified'] ? 'Fully booked' : 'Unavailable')) ?></span></label><?php endforeach; ?></fieldset><label>Reason for complimentary issuance<textarea name="complimentary_reason" rows="2" required maxlength="500" placeholder="For example, invited speaker"></textarea></label><div class="rd-delivery-notice"><?= $icon('mail') ?><div><b>Email + WhatsApp</b><p>By booking, you confirm the attendee agreed to WhatsApp ticket messages. PSOOLA handles delivery.</p></div></div><div class="rd-dialog-footer"><div class="rd-total"><span>TOTAL</span><strong>0 IQD</strong></div><button class="btn violet" data-rd-issue<?= Page::confirm('violet','ticket','Issue free workshop ticket?','Issue one complimentary workshop ticket for this selected attendee.',[['user',$fullName],['cash','Total: 0 IQD']],['note'=>'Confirm the attendee agreed to WhatsApp ticket messages. No paid fallback is used.','yes'=>'Yes, issue free ticket','no'=>'Go back']) ?>>Book guest workshop</button></div></form></dialog><?php endif; ?>
</section>
<?php Page::bottom(); ?>
