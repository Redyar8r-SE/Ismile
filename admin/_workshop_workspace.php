<?php
declare(strict_types=1);
use Ismile\Admin\Page;
use Ismile\Db;
use Ismile\GuestWorkshops;
use Ismile\Registrations;
use Ismile\Workshops;
if(!isset($user,$workshops,$url)){http_response_code(404);exit;}
$words=static fn(mixed $value):string=>is_array($value)?(string)(($value['en']??'')?: (array_values(array_filter($value))[0]??'')):(string)$value;
$schedule=static function(array $w):string{
    $date=$w['scheduledDate']??'';
    if($date){try{$date=(new DateTimeImmutable($date))->format('d M Y');}catch(Throwable){}}
    return implode(' · ',array_filter([$date,implode('–',array_filter([$w['startTime']??'',$w['endTime']??'']))]));
};
$availability=static function(array $w)use($liveCatalog):array{
    if($w['organizer']==='company')return ['Company managed','neutral'];
    $stock=$liveCatalog[$w['id']]??null;
    if(!$stock||!$stock['availabilityVerified'])return ['Availability unavailable','neutral'];
    return $stock['seatsLeft']>0?['Available','green']:['Fully booked','neutral'];
};
$portrait=static function(array $w)use($e,$words):string{
    $path=(string)($w['photo']??'');
    if(!preg_match('~^assets/(?:workshops|uploads/workshops)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g)$~i',$path)||str_contains($path,'..'))return '<span class="ws-portrait ws-portrait-empty">'.Page::navIcon('user').'</span>';
    return '<span class="ws-portrait"><span><img src="../'.$e($path).'" alt="'.$e($words($w['speaker']??'Trainer')).'" loading="lazy" width="76" height="88"></span></span>';
};
$initials=static fn(array $r):string=>mb_strtoupper(mb_substr($r['first_name'],0,1).mb_substr($r['father_name'],0,1));
?>
<div class="ws-workspace" data-workshop-workspace>
<?php if($canBook&&$selected&&$selected['organizer']==='ismile'&&!empty($selected['providerEventId'])&&$mode!=='cash'): ?><p><a class="btn ws-primary" href="<?= $e($url(['booking'=>'1','mode'=>'cash'],'#book')) ?>">+ Register (cash)</a></p><?php endif; ?>
<?php foreach($needsReview as $review): ?><div class="flash err" role="alert"><b>PSOOLA workshop payment received: review required.</b> <a href="registration.php?id=<?= (int)$review['registration_id'] ?>"><?= $e(Registrations::fullName($review)) ?></a><p><?= $e($review['fulfillment_error']) ?></p></div><?php endforeach; ?>
<section class="ws-summary" aria-label="Workshop summary">
  <div><span>Active workshops</span><strong><?= number_format(count($workshops)) ?></strong><small><?= count(array_filter($workshops,static fn($w)=>$w['organizer']==='ismile')) ?> iSmile · <?= count(array_filter($workshops,static fn($w)=>$w['organizer']==='company')) ?> company</small></div>
  <div><span>Confirmed bookings</span><strong><?= number_format($bookingTotal) ?></strong><small>Paid and complimentary</small></div>
  <div class="ws-summary-guest"><span>Guest tickets</span><strong><?= number_format($freeTotal) ?></strong><small>Complimentary · 0 IQD</small></div>
  <div><span>Collected</span><strong class="ws-summary-money"><?php foreach($collected?:['IQD'=>0] as $currency=>$amount): ?><span><?= $e(Workshops::money($amount,$currency)) ?></span><?php endforeach; ?></strong><small>Guest tickets excluded</small></div>
</section>
<?php if($pendingTotal||$unpaidTotal): ?><p class="ws-hint ws-pending-note"><?= number_format($unpaidTotal) ?> unpaid bookings · <?= number_format($pendingTotal) ?> online payments pending. Pending checkouts are not confirmed bookings.</p><?php endif; ?>
<div class="ws-toolbar">
  <nav class="ws-filters" aria-label="Workshop organizers"><?php foreach([''=>'All workshops','ismile'=>'iSmile','company'=>'Company'] as $key=>$label): ?><a href="<?= $e($url(['group'=>$key])) ?>"<?= $group===$key?' class="is-active" aria-current="true"':'' ?>><?= $e($label) ?></a><?php endforeach; ?></nav>
  <form class="ws-search" method="get" role="search"><input type="hidden" name="id" value="<?= $e($open) ?>"><input type="hidden" name="group" value="<?= $e($group) ?>"><?= Page::navIcon('search') ?><input type="search" name="search" value="<?= $e($workshopQuery) ?>" aria-label="Search workshops or trainer" placeholder="Search workshops or trainer"><button type="submit" aria-label="Search workshops"><?= Page::navIcon('arrow') ?></button></form>
</div>
<div class="ws-columns">
  <aside class="ws-directory" aria-label="Workshop directory">
    <div class="ws-directory-list" role="region" aria-label="Workshop list" tabindex="0">
    <?php foreach($displayWorkshops as $w): $counts=$workshopCounts[$w['id']]??$emptyCounts;[$label,$tone]=$availability($w); ?>
    <a class="ws-workshop-card<?= $w['id']===$open?' is-selected':'' ?>" href="<?= $e($url(['id'=>$w['id']], '#people')) ?>"<?= $w['id']===$open?' aria-current="true"':'' ?>>
      <span class="ws-card-organizer"><?= $w['organizer']==='ismile'?'iSmile workshop':'Organized by '.$e($words($w['company']??'Company')) ?><?= Page::navIcon('arrow') ?></span>
      <span class="ws-card-identity"><span><strong><?= $e(Workshops::name($w)) ?></strong><small><?= $e($words($w['speaker']??'Trainer to be announced')) ?></small></span><?= $portrait($w) ?></span>
      <span class="ws-card-schedule"><?= Page::navIcon('calendar') ?><?= $e($schedule(array_replace($w,$liveCatalog[$w['id']]??[]))) ?></span>
      <span class="ws-card-footer"><span class="ws-badge <?= $e($tone) ?>"><?= $e($label) ?></span><small><?= $w['organizer']==='company'?'Company registration':number_format((int)$counts['booked']).' attendees' ?></small></span>
    </a>
    <?php endforeach; ?>
    <?php if(!$displayWorkshops): ?><div class="ws-empty"><h2><?= $workshops?'No matching workshops':'Your workshops start here' ?></h2><p><?= $workshops?'Try another search or organizer.':'Add a workshop in Workshop settings to begin.' ?></p><?php if($workshops): ?><a href="<?= $e($url(['search'=>'','group'=>''])) ?>">Clear filters</a><?php endif; ?></div><?php endif; ?>
    </div>
    <p class="ws-directory-help"><?= Page::navIcon('info') ?><span>Select an iSmile workshop to book a guest. Company workshops are managed by their organizer.</span></p>
  </aside>
  <?php if($selected): $counts=$workshopCounts[$open]??$emptyCounts;[$label,$tone]=$availability($selected); ?>
  <section class="ws-detail" id="people" aria-labelledby="ws-selected-title">
    <header class="ws-detail-header"><span class="ws-badge <?= $selected['organizer']==='ismile'?'violet':'neutral' ?>"><?= $selected['organizer']==='ismile'?'iSmile workshop':'Organized by '.$e($words($selected['company']??'Company')) ?></span><span class="ws-badge <?= $e($tone) ?>"><?= $e($label) ?></span></header>
    <div class="ws-detail-title"><h2 id="ws-selected-title"><?= $e(Workshops::name($selected)) ?></h2><?php if($words($selected['format']??'')): ?><p><?= $e($words($selected['format'])) ?></p><?php endif; ?></div>
    <div class="ws-trainer"><?= $portrait($selected) ?><div><strong><?= $e($words($selected['speaker']??'Trainer to be announced')) ?></strong><small><?= $e($schedule($selected)) ?><?= $schedule($selected)?' · Iraq time':'' ?></small></div><div class="ws-price"><strong><?= (int)$selected['price']>0?$e(Workshops::money((int)$selected['price'],$selected['currency'])):'Price to be set' ?></strong><small>per paid ticket</small></div></div>
    <div class="ws-detail-toolbar"><nav class="ws-tabs" aria-label="Workshop details"><?php foreach(['attendees'=>'Attendees '.count($people),'overview'=>'Overview','activity'=>'Activity'] as $key=>$label): ?><a href="<?= $e($url(['view'=>$key],'#people')) ?>"<?= $view===$key?' class="is-active" aria-current="page"':'' ?>><?= $e($label) ?></a><?php endforeach; ?></nav><details class="ws-menu"><summary><?= Page::navIcon('download') ?><span>Sign-in sheet</span></summary><div class="ws-menu-content"><a href="export.php?what=workshop&amp;id=<?= rawurlencode($open) ?>&amp;format=pdf">PDF sign-in sheet</a><a href="export.php?what=workshop&amp;id=<?= rawurlencode($open) ?>">CSV sign-in sheet</a></div></details></div>
    <?php $cashRequests=$canBook?\Ismile\WorkshopCash::pending($open):[]; if($cashRequests): ?>
    <section class="ws-book-notice" aria-label="Cash registration requests"><h3>Cash requests awaiting payment</h3><p>These requests are unpaid. A seat is confirmed after cash is collected and the paid ticket is issued.</p>
    <?php foreach($cashRequests as $request): ?><p><strong><?= $e(Registrations::fullName($request)) ?></strong> · <?= $e($request['ref']) ?> · <?= $e($request['phone']) ?> · <?= $e(Workshops::money((int)$request['amount'],$request['currency'])) ?> <a class="btn small" href="<?= $e($url(['booking'=>'1','rid'=>(int)$request['registration_id'],'mode'=>'cash'],'#book')) ?>">Record cash payment</a></p><?php endforeach; ?>
    </section><?php endif; ?>
    <?php if($view==='attendees'): ?>
    <form method="get" class="ws-attendee-search" role="search"><input type="hidden" name="id" value="<?= $e($open) ?>"><input type="hidden" name="group" value="<?= $e($group) ?>"><label><?= Page::navIcon('search') ?><input type="search" name="attendee" value="<?= $e($attendeeQuery) ?>" placeholder="Find an attendee…" aria-label="Find an attendee"></label><select name="ticket" aria-label="Filter workshop ticket type"><?php foreach([''=>'All ticket types','paid'=>'Paid','complimentary'=>'Guest ticket','unpaid'=>'Unpaid'] as $key=>$label): ?><option value="<?= $e($key) ?>"<?= $ticketFilter===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select><button class="btn small ghost" type="submit">Find</button></form>
    <div class="ws-attendees" role="table" aria-label="Workshop attendees" tabindex="0"><div class="ws-attendee-head" role="row"><span role="columnheader">Attendee</span><span role="columnheader">Ticket</span><span role="columnheader">Amount</span><span class="sr-only" role="columnheader">Actions</span></div>
    <?php foreach($shownPeople as $attendee): $guest=$attendee['payment_status']==='complimentary';$paid=$attendee['payment_status']==='paid';$freeRegistration=$paid&&(int)$attendee['price_agreed']===0&&(int)$attendee['amount_paid']===0;$online=$paid&&($attendee['online_status']==='paid'||$attendee['paid_how']==='psoola');$kind=$freeRegistration?'Registered online':($guest?'Guest ticket':($paid?($online?'Paid online':($attendee['paid_how']==='cash'?'Paid by cash':'Paid by transfer')):'Unpaid')); ?>
      <div class="ws-attendee" role="row">
        <div class="ws-attendee-person" role="cell"><span class="ws-avatar<?= $guest?' is-guest':'' ?>" aria-hidden="true"><?= $e($initials($attendee)) ?></span><div><a href="registration.php?id=<?= (int)$attendee['rid'] ?>"><?= $e(Registrations::fullName($attendee)) ?></a><small><?= $e($attendee['ref']) ?> · <?= in_array($attendee['reg_status'],['paid','complimentary'],true)?'Summit confirmed':'Summit inactive' ?></small></div></div>
        <div role="cell"><span class="ws-badge <?= $guest?'violet':($paid?($attendee['paid_how']==='cash'?'gold':'green'):'red') ?>"><?= $e($kind) ?></span></div>
        <strong class="ws-attendee-amount" role="cell"><?= $guest?'0 IQD':$e(Workshops::money((int)($paid?$attendee['amount_paid']:$attendee['price_agreed']),$selected['currency'])) ?><?= !$paid&&!$guest?'<small>to collect</small>':'' ?><?php $cashReceipt=\Ismile\Db::one('SELECT amount_minor,currency FROM workshop_cash_receipts WHERE booking_id=?',[$attendee['id']]); if($cashReceipt): ?><small>Received <?= $cashReceipt['currency']==='USD'?'$'.number_format((int)$cashReceipt['amount_minor']/100,2):number_format((int)$cashReceipt['amount_minor']).' IQD' ?> cash</small><?php endif; ?></strong>
        <div role="cell"><details class="ws-menu ws-person-menu"><summary aria-label="Actions for <?= $e(Registrations::fullName($attendee)) ?>"><?= Page::navIcon('more') ?><span class="sr-only">Actions</span></summary><div class="ws-menu-content"><a href="registration.php?id=<?= (int)$attendee['rid'] ?>">Open registration</a><span class="ws-hint" dir="ltr"><?= $e($attendee['phone']) ?></span><span class="ws-hint">Event ticket: <?= $e($attendee['reg_status']) ?></span>
        <?php if($freeRegistration): ?><p class="ws-hint"><?= $attendee['paid_how']==='psoola'?'Registered online through PSOOLA. Your ticket is emailed.':'Registered online with iSmile.' ?> No workshop payment is required.</p><?php elseif($attendee['guest_request_id']): ?><p class="ws-hint">Complimentary PSOOLA ticket. Manage provider delivery from the registration.</p><?php elseif($attendee['online_status']): ?><p class="ws-hint">Paid online through PSOOLA. Ticket issued automatically.</p><?php elseif($canBook): ?><?= Page::workshopPaymentForm($attendee,$user,$back.'#people') ?><?php endif; ?>
        <?php if($attendee['guest_request_id']&&$user['role']==='owner'): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="workshop_change"><input type="hidden" name="booking" value="<?= (int)$attendee['id'] ?>"><input type="hidden" name="remove" value="1"><button class="btn small red"<?= Page::confirm('gold','user-x','Remove this workshop booking?','Removes the local booking. Manage its issued ticket separately in PSOOLA.',[['user',Registrations::fullName($attendee)]],['yes'=>'Remove booking']) ?>>Remove</button></form><?php endif; ?>
        <?php if(!$attendee['online_status']&&!$guest&&($user['role']==='owner'||($canBook&&$attendee['paid_how']==='cash'&&(int)$attendee['booked_by']===(int)$user['id']))&&$paid&&!empty($selected['providerEventId'])): $order=Db::one('SELECT provider_status FROM workshop_ticket_orders WHERE booking_id=?',[$attendee['id']]); ?>
        <?php if($order): ?><p class="ws-hint">PSOOLA workshop ticket: <?= $e($order['provider_status']) ?></p><?php endif; ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="workshop_issue_ticket"><input type="hidden" name="booking" value="<?= (int)$attendee['id'] ?>"><button class="btn small green"><?= ($order['provider_status']??'')==='paid'?'Verify workshop ticket':'Issue paid workshop ticket' ?></button></form><p class="ws-hint">Issue after collecting payment. PSOOLA emails the ticket.</p><?php endif; ?>
        </div></details></div>
      </div>
    <?php endforeach; ?>
    <?php if(!$shownPeople): ?><div class="ws-empty"><h3><?= $people?'No matching attendees':'No attendees yet' ?></h3><p><?= $people?'Try another name or ticket type.':'Book a registered guest to get started.' ?></p></div><?php endif; ?>
    </div>
    <footer class="ws-people-footer"><div><strong>Collected: <?= $e(Workshops::money((int)$counts['money'],$selected['currency'])) ?></strong><small><?= (int)$counts['paid'] ?> paid · <?= (int)$counts['free'] ?> guest tickets<?= (int)$counts['unpaid']?' · '.(int)$counts['unpaid'].' unpaid':'' ?><br>Guest tickets add no revenue</small></div><nav aria-label="Attendee pages"><span><?= count($filteredPeople)?(($page-1)*10+1).'–'.min($page*10,count($filteredPeople)):0 ?> of <?= count($filteredPeople) ?></span><?php if($page>1): ?><a aria-label="Previous attendees" href="<?= $e($url(['attendee'=>$attendeeQuery,'ticket'=>$ticketFilter,'page'=>$page-1],'#people')) ?>">‹</a><?php endif; ?><?php if($page<$pages): ?><a aria-label="Next attendees" href="<?= $e($url(['attendee'=>$attendeeQuery,'ticket'=>$ticketFilter,'page'=>$page+1],'#people')) ?>">›</a><?php endif; ?></nav></footer>
    <?php elseif($view==='overview'): ?>
      <div class="ws-overview"><p><?= nl2br($e($words($selected['overview']??'Workshop details will be announced soon.'))) ?></p><dl><?php foreach(['credentials'=>'Trainer qualifications','format'=>'Format','duration'=>'Duration','level'=>'Level'] as $key=>$label): if(!$words($selected[$key]??''))continue; ?><div><dt><?= $e($label) ?></dt><dd><?= $e($words($selected[$key])) ?></dd></div><?php endforeach; ?></dl></div>
    <?php else: ?><ul class="ws-activity"><?php foreach($activity as $item): ?><li><strong><?= $e(ucwords(str_replace(['workshop.','_'],['',' '],$item['action']))) ?></strong><small><?= $e($item['name']??'System') ?> · <?= Page::when($item['created_at']) ?></small></li><?php endforeach; ?><?php if(!$activity): ?><li class="ws-hint">No workshop activity recorded yet.</li><?php endif; ?></ul><?php endif; ?>
    <div class="ws-detail-actions"><?php if($user['role']==='owner'): ?><a href="#manage" data-ws-open="ws-settings" data-ws-edit="<?= $e($open) ?>"><?= Page::navIcon('settings') ?>Edit workshop</a><?php else: ?><span></span><?php endif; ?><?php if($selected['organizer']==='company'): ?><div class="ws-company-contact"><span>Register with <?= $e($words($selected['company']??'the organizer')) ?></span><?php foreach($selected['companyPhones']??[] as $phone): ?><a dir="ltr" href="tel:<?= $e(preg_replace('/[^+0-9]/','',$phone)) ?>"><?= $e($phone) ?></a><?php endforeach; ?></div><?php elseif($canBook): ?><div class="ws-cash-actions"><?php if($mode!=='cash'&&!empty($selected['providerEventId'])): ?><a class="btn ws-primary" href="<?= $e($url(['booking'=>'1','mode'=>'cash'],'#book')) ?>"><?= Page::navIcon('plus') ?>Register (cash)</a><?php endif; ?><a class="btn <?= $mode==='cash'?'ws-primary':'ghost' ?>" href="<?= $e($url(['booking'=>'1'],'#book')) ?>" data-ws-open="ws-book"><?= Page::navIcon('plus') ?><?= $mode==='cash'?'Register (cash)':'Book a guest' ?></a></div><?php endif; ?></div>
  </section>
  <?php endif; ?>
</div>
<?php if($canBook&&$selected): require __DIR__.'/_workshop_booking.php'; endif; ?>
<?php if($user['role']==='owner'): ?><dialog class="ws-dialog ws-settings-dialog" id="ws-settings" aria-labelledby="ws-settings-title"><header class="ws-dialog-heading"><div><span class="ws-eyebrow">Owner access</span><h2 id="ws-settings-title">Workshop settings</h2></div><button class="icon-button" type="button" data-ws-close aria-label="Close workshop settings"><?= Page::navIcon('close') ?></button></header><?php require __DIR__.'/_workshop_settings.php'; ?></dialog><?php endif; ?>
<noscript><style>.ws-dialog{display:block;position:static;max-height:none;width:100%;margin-block:24px}.ws-dialog [data-ws-close]{display:none}</style></noscript>
</div>
