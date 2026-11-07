<?php
declare(strict_types=1);
if (!isset($user)||!\Ismile\Auth::canIssueComplimentary($user)) {http_response_code(403);exit;}
use Ismile\Admin\Page;use Ismile\Admin\GuestTicketText;use Ismile\GuestOrders;use Ismile\UserError;
$ui=($_GET['options']??'')==='1'?GuestTicketText::locale($_GET['ui']??'en'):'en';$words=GuestTicketText::all($ui);$t=static fn(string $key):string=>$words[$key]??$key;
$e=[Page::class,'e'];$rootUrl='registration.php?new=free';
$options=($_GET['options']??'')==='1';
if($options)$rootUrl.='&options=1&ui='.rawurlencode($ui);
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=$method==='POST'?($_POST['do']??'issue'):($_GET['do']??'');
$privateHeaders=static function():void{header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');};
if($action==='catalogue') {
    $privateHeaders();header('Content-Type: application/json; charset=utf-8');
    try {
        if($method!=='GET')throw new UserError('GET required.',null,405);
        $id=Page::query('event_id');
        if($id!==''&&!preg_match('/^[A-Za-z0-9_-]{1,190}$/D',$id))throw new UserError('Select a valid event.');
        $result=$id!==''?['event'=>\Ismile\GuestTicketCatalog::event($id)]:['events'=>\Ismile\GuestTicketCatalog::events()];
        echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_HEX_TAG);
    }catch(UserError $error){http_response_code($error->status);echo json_encode(['ok'=>false,'error'=>$error->key],JSON_HEX_TAG);}
    catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'error'=>$t('catalogue_error')],JSON_HEX_TAG);}
    exit;
}
if ($action==='link') {
    $privateHeaders();header('Content-Type: application/json; charset=utf-8');
    try {
        if($method!=='POST')throw new UserError('POST required.',null,405);\Ismile\Auth::checkCsrf();$result=GuestOrders::ticketLink($user,(int)($_POST['order_id']??0));
        echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR|JSON_HEX_TAG);
    }catch(UserError $error){http_response_code($error->status);echo json_encode(['ok'=>false,'error'=>$error->key],JSON_HEX_TAG);}
    catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'error'=>$t('error')],JSON_HEX_TAG);}
    exit;
}
$values=$_POST;$order=null;
if($method==='POST') {
    try {
        \Ismile\Auth::checkCsrf();
        $issued=$action==='retry'?GuestOrders::retry($user,is_string($_POST['attempt']??null)?$_POST['attempt']:''):($options?GuestOrders::issue($user,$_POST):GuestOrders::issueSingle($user,$_POST));
        Page::flash('ok',$t('issued'));Page::redirect($rootUrl.'&order='.(int)$issued['id']);
    }catch(UserError $error){
        Page::flash('error',$error->key);
        if(is_string($_POST['attempt']??null))try{$order=GuestOrders::byKey($user,$_POST['attempt']);}catch(UserError){}
    }catch(Throwable){Page::flash('error',$t('error'));if(is_string($_POST['attempt']??null))try{$order=GuestOrders::byKey($user,$_POST['attempt']);}catch(UserError){}}
}
if($method==='GET' && (int)($_GET['order']??0)>0)try{$order=GuestOrders::get($user,(int)$_GET['order']);}catch(UserError $error){http_response_code($error->status);Page::flash('error',$error->key);}
if($method==='GET'&&isset($_GET['retry']))try{$order=GuestOrders::byKey($user,Page::query('retry'));}catch(UserError $error){Page::flash('error',$error->key);}
if($method==='GET'&&isset($_GET['copy']))try{
    $previous=GuestOrders::byKey($user,Page::query('copy'));
    if($previous['status']!=='rejected'||!(int)$previous['is_complimentary'])throw new UserError('Only a definitively rejected complimentary request can be corrected as a new request.');
    $guest=json_decode($previous['guest_json'],true,512,JSON_THROW_ON_ERROR);$body=json_decode($previous['request_json'],true,512,JSON_THROW_ON_ERROR);
    $names=array_pad(preg_split('/\s+/u',$guest['buyer_name']??'',3)?:[],3,'');
    $values=$guest+['first_name'=>$names[0],'father_name'=>$names[1],'grandfather_name'=>$names[2]];
    if($options)$values+=['event_id'=>$body['eventId'],'show_id'=>$body['showId'],'ticket_type_id'=>$body['items'][0]['ticketTypeId'],'quantity'=>(string)$body['items'][0]['quantity'],'add_on_ids'=>$body['items'][0]['addOnIds']??[]];
    // Deliberate correction starts a new request. Permission must be obtained again.
}catch(UserError $error){Page::flash('error',$error->key);}
$privateHeaders();
Page::top($t('title'),'registrations','<a class="btn ghost" href="registrations.php">'.$e($t('back')).'</a>');
$value=static fn(string $key):string=>Page::e(is_string($values[$key]??null)?$values[$key]:'');
$attempt=is_string($values['attempt']??null)&&preg_match('/^[a-f0-9]{32}$/D',$values['attempt'])?$values['attempt']:bin2hex(random_bytes(16));
$dir=in_array($ui,['ar','ckb'],true)?'rtl':'ltr';
?>
<div class="guest-ticket-workspace" data-guest-tickets lang="<?= $e($ui) ?>" dir="<?= $dir ?>">
<?php if($order):
 $guest=json_decode($order['guest_json'],true,512,JSON_THROW_ON_ERROR);$body=json_decode($order['request_json'],true,512,JSON_THROW_ON_ERROR);
 $verified=(int)$order['is_complimentary']===1&&$order['status']==='issued'&&(int)$order['provider_amount']===0&&$order['provider_currency']==='IQD';
 $guestName=$guest['buyer_name']??trim(implode(' ',array_map(static fn($key)=>$guest[$key]??'', ['first_name','father_name','grandfather_name'])));
 $deliveryText=static function(?string $status)use($words,$t):string{$key=$status==='pending'?'pending_delivery':($status?:'unknown');return $words[$key]??$t('unknown');};
 $statusText=!(int)$order['is_complimentary']?'Historical ticket order':($verified?$t('issued'):($order['status']==='rejected'?$t('rejected'):$t('pending')));
?>
<section class="guest-order-confirmation<?= $verified?' is-issued':'' ?>" data-guest-order="<?= (int)$order['id'] ?>">
 <header class="guest-order-heading"><span class="guest-order-symbol"><?= Page::navIcon($verified?'check':'clock') ?></span><div><h2><?= $e($statusText) ?></h2><p><?= $verified?'The ticket is ready. Nothing is due.':$e($statusText) ?></p></div></header>
 <p class="pill <?= $verified?'green':'gold' ?>"><?= $e($verified?$t('complimentary'):(!(int)$order['is_complimentary']?$t('legacy'):$statusText)) ?></p>
 <dl class="facts"><dt><?= $e($t('name')) ?></dt><dd><?= $e($guestName) ?></dd><dt><?= $e($t('email')) ?></dt><dd dir="ltr"><?= $e($guest['email']) ?></dd><dt><?= $e($t('phone')) ?></dt><dd dir="ltr"><?= $e($guest['phone']) ?></dd>
 <dt><?= $e($t('event')) ?></dt><dd><?= $e($order['event_name']??$body['eventId']) ?></dd><dt><?= $e($t('show')) ?></dt><dd><?= $e($order['show_name']??$body['showId']) ?></dd><dt><?= $e($t('quantity')) ?></dt><dd><?= (int)$order['ticket_quantity'] ?></dd>
 <dt><?= $e($t('reason')) ?></dt><dd><?= $e($order['complimentary_reason']??$guest['sponsoring_company']??'') ?></dd><dt><?= $e($t('reference')) ?></dt><dd><code><?= $e($order['partner_reference']) ?></code></dd>
 <dt><?= $e($t('staff')) ?></dt><dd><?= $e($order['staff_name']??\Ismile\Db::value('SELECT name FROM admin_users WHERE id=?',[$order['created_by']])) ?></dd>
 <?php if($verified): ?><dt><?= $e($t('discount')) ?></dt><dd><?= $e(Page::money((int)$order['discount_amount'],'IQD')) ?></dd><?php endif; ?>
 <dt><?= $e($t('email_status')) ?></dt><dd data-guest-email-status><?= $e($deliveryText($order['email_status'])) ?></dd>
 <dt><?= $e($t('whatsapp_status')) ?></dt><dd data-guest-whatsapp-status><?= $e($deliveryText($order['whatsapp_status'])) ?></dd></dl>
 <?php if($verified): ?><p class="guest-zero-total"><strong><?= $e($t('total')) ?></strong></p><p class="muted small"><?= $e($t('delivery_note')) ?></p>
 <div class="form-actions"><button class="btn violet" type="button" data-guest-ticket-link="view" data-order="<?= (int)$order['id'] ?>"><?= $e($t('view')) ?></button><button class="btn ghost" type="button" data-guest-ticket-link="copy" data-order="<?= (int)$order['id'] ?>"><?= $e($t('copy')) ?></button></div>
 <?php if($order['registration_id']&&\Ismile\Auth::can($user,'association')): $associationRef=\Ismile\Db::value('SELECT ref FROM registrations WHERE id=?',[$order['registration_id']]); ?><p><a class="btn ghost" data-guest-association href="association.php?<?= $e(http_build_query(['q'=>$associationRef,'sale'=>'guest','status'=>'complimentary'])) ?>">View in Association</a></p><?php endif; ?>
 <p data-guest-link-result role="status" aria-live="polite"></p>
 <?php elseif(!(int)$order['is_complimentary']): ?><p class="flash warn"><?= $e($t('legacy')) ?> · <?= $e(Page::money((int)$order['provider_amount'],$order['provider_currency']??'IQD')) ?></p>
 <?php else: ?><p class="muted"><?= $e($t('saved')) ?></p><?php if($order['last_error']): ?><p class="flash warn"><?= $e($order['last_error']) ?></p><?php endif; ?>
 <?php if($order['support_request_id']): ?><p><?= $e($t('support')) ?>: <code><?= $e($order['support_request_id']) ?></code></p><?php endif; ?>
 <?php if($order['retry_after']): ?><p><?= $e($t('retry_after')) ?>: <?= $e($order['retry_after']) ?></p><?php endif; ?>
 <?php if($order['status']==='rejected'): ?><a class="btn ghost" href="<?= $e('registration.php?new=free&options=1&ui='.$ui.'&copy='.$order['attempt_key']) ?>"><?= $e($t('correct')) ?></a>
 <?php else: ?><form method="post" action="<?= $e($rootUrl) ?>" data-guest-retry><?= Page::csrfField() ?><input type="hidden" name="do" value="retry"><input type="hidden" name="attempt" value="<?= $e($order['attempt_key']) ?>"><button class="btn violet"<?= !GuestOrders::enabled()||($order['retry_after']&&strtotime($order['retry_after'])>time())?' disabled':'' ?>><?= $e($t('retry')) ?></button></form><?php endif; ?>
 <?php endif; ?>
</section>
<?php elseif($options): require __DIR__.'/_guest_ticket_options.php'; ?>
<?php else: ?>
<p><a class="btn ghost" href="registration.php?new=free&amp;options=1">Choose event, ticket options and language</a></p>
<form method="post" action="<?= $e($rootUrl) ?>" id="free-ticket-form" class="guest-issuance-layout" data-guest-ticket-form>
 <?= Page::csrfField() ?><input type="hidden" name="do" value="issue"><input type="hidden" name="attempt" value="<?= $e($attempt) ?>"><input type="hidden" name="delivery_confirmation" value="">
 <section class="guest-details-panel" aria-labelledby="guest-details-title">
  <h2 id="guest-details-title">Guest details</h2>
  <div class="guest-name-fields">
   <label>First name<input name="first_name" minlength="2" maxlength="60" autocomplete="given-name" placeholder="Enter name" value="<?= $value('first_name') ?>" required></label>
   <label>Second name<input name="father_name" minlength="2" maxlength="60" autocomplete="additional-name" placeholder="Enter name" value="<?= $value('father_name') ?>" required></label>
   <label>Third name<input name="grandfather_name" minlength="2" maxlength="60" autocomplete="family-name" placeholder="Enter name" value="<?= $value('grandfather_name') ?>" required></label>
  </div>
  <div class="guest-field-pair">
   <label>Email address<input type="email" name="email" maxlength="190" autocomplete="email" dir="ltr" placeholder="guest@example.com" value="<?= $value('email') ?>" required></label>
   <label>WhatsApp number<input type="tel" name="phone" maxlength="30" autocomplete="tel" dir="ltr" placeholder="+964 750 123 4567" value="<?= $value('phone') ?>" required></label>
  </div>
  <div class="guest-field-pair">
   <label>Specialty<select name="specialty" required><option value="">Select specialty</option><?php foreach(\Ismile\Registrations::SPECIALTY_NAMES as $key=>$label): ?><option value="<?= $e($key) ?>"<?= ($values['specialty']??'')===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
   <label>Sponsoring company<input name="sponsoring_company" maxlength="160" placeholder="Company name (optional)" value="<?= $value('sponsoring_company') ?>"></label>
  </div>
  <label class="guest-reason-field">Reason for complimentary ticket<textarea name="complimentary_reason" maxlength="500" required rows="3" placeholder="For example, invited speaker or sponsor guest"><?= $value('complimentary_reason') ?></textarea><small>A brief reason helps your team keep a clear record.</small></label>
  <div class="guest-delivery-notice"><?= Page::navIcon('arrow') ?><div><strong>Delivery: Email + WhatsApp</strong><p>By issuing, you confirm the guest agreed to WhatsApp ticket messages.</p></div></div>
  <footer class="guest-details-footer"><span>Guest details stay private.</span><a href="registrations.php">Cancel</a></footer>
 </section>
 <aside class="guest-ticket-sidebar" aria-label="Guest ticket preview">
  <section class="guest-pass" aria-labelledby="guest-pass-title">
   <header class="guest-pass-header"><div class="guest-pass-brand"><b>iSmile</b><span><?= Page::navIcon('ticket') ?></span></div><h2 id="guest-pass-title">Guest ticket</h2><span class="guest-pass-badge"><?= Page::navIcon('check') ?>Complimentary</span></header>
   <div class="guest-pass-person"><span>Prepared for</span><strong data-guest-preview-name>Your guest</strong><p>iSmile 2026 &middot; 1 admission</p></div>
   <div class="guest-pass-total"><span>Total amount</span><strong>0 <small>IQD</small></strong><p>No payment required</p><span class="sr-only">Total: 0 IQD</span></div>
  </section>
  <button class="btn guest-issue-button" type="submit"<?= !GuestOrders::enabled()?' disabled title="PSOOLA complimentary issuance is not enabled yet"':'' ?> data-guest-issue><?= Page::navIcon('ticket') ?><span><?= $e($t('issue')) ?></span></button>
  <div class="guest-delivery-channels"><span><?= Page::navIcon('mail') ?>Email</span><span><?= Page::navIcon('phone') ?>WhatsApp</span></div>
  <p class="sr-only" data-guest-review aria-live="polite"></p><p data-guest-form-error role="status" aria-live="polite"></p>
 </aside>
</form>
<?php endif; ?>
<section class="guest-history"><h2><?= $e($t('history')) ?></h2><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Sponsoring company / reason</th><th>Issued by</th><th>Status</th><th>Total</th><th>Action</th></tr></thead><tbody>
<?php $history=GuestOrders::history($user);foreach($history as $row):$guest=json_decode($row['guest_json'],true,512,JSON_THROW_ON_ERROR); ?>
<tr><td data-label="Guest"><b><?= $e($guest['buyer_name']??$guest['email']) ?></b><small><?= $e($row['event_name']??'Historical order') ?> &middot; <?= (int)$row['ticket_quantity'] ?> ticket(s)</small></td><td data-label="Sponsoring company / reason"><?= $e($guest['sponsoring_company']??'') ?><small><?= $e($row['complimentary_reason']??'') ?></small></td><td data-label="Issued by"><?= $e($row['staff_name']) ?></td><td data-label="Status"><?= $e((int)$row['is_complimentary']?($row['status']==='issued'?$t('complimentary'):($row['status']==='rejected'?$t('rejected'):$t('pending'))):$t('legacy')) ?></td><td data-label="Total"><?= $row['provider_amount']===null?'&mdash;':$e(Page::money((int)$row['provider_amount'],$row['provider_currency']??'IQD')) ?></td><td data-label="Action"><a href="<?= $e($rootUrl.'&order='.(int)$row['id']) ?>"><?= $e($t('details')) ?></a></td></tr>
<?php endforeach;if(!$history): ?><tr class="empty-row"><td colspan="6"><?= Page::navIcon('ticket') ?>Issued guest tickets will appear here.</td></tr><?php endif; ?></tbody></table></div></section>
<script type="application/json" id="guest-ticket-data"><?= json_encode(['words'=>$words,'enabled'=>GuestOrders::enabled(),'endpoint'=>$rootUrl,'csrf'=>\Ismile\Auth::csrf()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</div>
<?php Page::bottom();
