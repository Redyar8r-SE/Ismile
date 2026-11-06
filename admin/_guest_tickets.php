<?php
declare(strict_types=1);
if (!isset($user)||!\Ismile\Auth::canIssueComplimentary($user)) {http_response_code(403);exit;}
use Ismile\Admin\Page;use Ismile\Admin\GuestTicketText;use Ismile\GuestOrders;use Ismile\UserError;
$ui='en';$words=GuestTicketText::all($ui);$t=static fn(string $key):string=>$words[$key]??$key;
$e=[Page::class,'e'];$rootUrl='registration.php?new=free';
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=$method==='POST'?($_POST['do']??'issue'):($_GET['do']??'');
$privateHeaders=static function():void{header('Cache-Control: no-store, private, max-age=0');header('Pragma: no-cache');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');};
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
        $issued=$action==='retry'?GuestOrders::retry($user,is_string($_POST['attempt']??null)?$_POST['attempt']:''):GuestOrders::issueSingle($user,$_POST);
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
    // Deliberate correction starts a new request. Permission must be obtained again.
}catch(UserError $error){Page::flash('error',$error->key);}
$privateHeaders();
Page::top($t('title'),'registrations','<a class="btn ghost" href="registrations.php">'.$e($t('back')).'</a>');
$value=static fn(string $key):string=>Page::e(is_string($values[$key]??null)?$values[$key]:'');
$attempt=is_string($values['attempt']??null)&&preg_match('/^[a-f0-9]{32}$/D',$values['attempt'])?$values['attempt']:bin2hex(random_bytes(16));
$dir=in_array($ui,['ar','ckb'],true)?'rtl':'ltr';
?>
<div data-guest-tickets lang="<?= $e($ui) ?>" dir="<?= $dir ?>">
<?php if($order):
 $guest=json_decode($order['guest_json'],true,512,JSON_THROW_ON_ERROR);$body=json_decode($order['request_json'],true,512,JSON_THROW_ON_ERROR);
 $verified=(int)$order['is_complimentary']===1&&$order['status']==='issued'&&(int)$order['provider_amount']===0&&$order['provider_currency']==='IQD';
 $guestName=$guest['buyer_name']??trim(implode(' ',array_map(static fn($key)=>$guest[$key]??'', ['first_name','father_name','grandfather_name'])));
 $deliveryText=static function(?string $status)use($words,$t):string{$key=$status==='pending'?'pending_delivery':($status?:'unknown');return $words[$key]??$t('unknown');};
 $statusText=$verified?$t('issued'):($order['status']==='rejected'?$t('rejected'):$t('pending'));
?>
<section class="card caller-form" data-guest-order="<?= (int)$order['id'] ?>">
 <h2><?= $e($statusText) ?></h2><p class="pill <?= $verified?'green':'gold' ?>"><?= $e($verified?$t('complimentary'):(!(int)$order['is_complimentary']?$t('legacy'):$statusText)) ?></p>
 <dl class="facts"><dt><?= $e($t('name')) ?></dt><dd><?= $e($guestName) ?></dd><dt><?= $e($t('email')) ?></dt><dd dir="ltr"><?= $e($guest['email']) ?></dd><dt><?= $e($t('phone')) ?></dt><dd dir="ltr"><?= $e($guest['phone']) ?></dd>
 <dt><?= $e($t('event')) ?></dt><dd><?= $e($order['event_name']??$body['eventId']) ?></dd><dt><?= $e($t('show')) ?></dt><dd><?= $e($order['show_name']??$body['showId']) ?></dd><dt><?= $e($t('quantity')) ?></dt><dd><?= (int)$order['ticket_quantity'] ?></dd>
 <dt><?= $e($t('reason')) ?></dt><dd><?= $e($order['complimentary_reason']??$guest['sponsoring_company']??'') ?></dd><dt><?= $e($t('reference')) ?></dt><dd><code><?= $e($order['partner_reference']) ?></code></dd>
 <dt><?= $e($t('staff')) ?></dt><dd><?= $e($order['staff_name']??\Ismile\Db::value('SELECT name FROM admin_users WHERE id=?',[$order['created_by']])) ?></dd>
 <?php if($verified): ?><dt><?= $e($t('discount')) ?></dt><dd><?= $e(Page::money((int)$order['discount_amount'],'IQD')) ?></dd><?php endif; ?>
 <dt><?= $e($t('email_status')) ?></dt><dd data-guest-email-status><?= $e($deliveryText($order['email_status'])) ?></dd>
 <dt><?= $e($t('whatsapp_status')) ?></dt><dd data-guest-whatsapp-status><?= $e($deliveryText($order['whatsapp_status'])) ?></dd></dl>
 <?php if($verified): ?><p class="guest-zero-total"><strong><?= $e($t('total')) ?></strong></p><p class="muted small"><?= $e($t('delivery_note')) ?></p>
 <div class="form-actions"><button class="btn violet" type="button" data-guest-ticket-link="view" data-order="<?= (int)$order['id'] ?>"><?= $e($t('view')) ?></button><button class="btn ghost" type="button" data-guest-ticket-link="copy" data-order="<?= (int)$order['id'] ?>"><?= $e($t('copy')) ?></button></div>
 <p data-guest-link-result role="status" aria-live="polite"></p>
 <?php elseif(!(int)$order['is_complimentary']): ?><p class="flash warn"><?= $e($t('legacy')) ?> · <?= $e(Page::money((int)$order['provider_amount'],$order['provider_currency']??'IQD')) ?></p>
 <?php else: ?><p class="muted"><?= $e($t('saved')) ?></p><?php if($order['last_error']): ?><p class="flash warn"><?= $e($order['last_error']) ?></p><?php endif; ?>
 <?php if($order['support_request_id']): ?><p><?= $e($t('support')) ?>: <code><?= $e($order['support_request_id']) ?></code></p><?php endif; ?>
 <?php if($order['retry_after']): ?><p><?= $e($t('retry_after')) ?>: <?= $e($order['retry_after']) ?></p><?php endif; ?>
 <?php if($order['status']==='rejected'): ?><a class="btn ghost" href="<?= $e($rootUrl.'&copy='.$order['attempt_key']) ?>"><?= $e($t('correct')) ?></a>
 <?php else: ?><form method="post" action="<?= $e($rootUrl) ?>" data-guest-retry><?= Page::csrfField() ?><input type="hidden" name="do" value="retry"><input type="hidden" name="attempt" value="<?= $e($order['attempt_key']) ?>"><button class="btn violet"<?= !GuestOrders::enabled()||($order['retry_after']&&strtotime($order['retry_after'])>time())?' disabled':'' ?>><?= $e($t('retry')) ?></button></form><?php endif; ?>
 <?php endif; ?>
</section>
<?php else: ?>
<form method="post" action="<?= $e($rootUrl) ?>" class="card stack caller-form" id="free-ticket-form" data-guest-ticket-form>
 <?= Page::csrfField() ?><input type="hidden" name="do" value="issue"><input type="hidden" name="attempt" value="<?= $e($attempt) ?>">
 <p class="muted"><?= $e($t('intro').' '.$t('required')) ?></p>
 <?php if(!GuestOrders::enabled()): ?><p class="flash warn"><?= $e($t('setup_required')) ?></p><?php endif; ?>
 <fieldset class="form-section"><legend><?= $e($t('guest')) ?></legend><div class="row3">
 <label>First name *<input name="first_name" minlength="2" maxlength="60" autocomplete="given-name" value="<?= $value('first_name') ?>" required></label>
 <label>Second name *<input name="father_name" minlength="2" maxlength="60" autocomplete="additional-name" value="<?= $value('father_name') ?>" required></label>
 <label>Third name *<input name="grandfather_name" minlength="2" maxlength="60" autocomplete="family-name" value="<?= $value('grandfather_name') ?>" required></label>
 <label>Specialty *<select name="specialty" required><option value="">Choose specialty</option><?php foreach(\Ismile\Registrations::SPECIALTY_NAMES as $key=>$label): ?><option value="<?= $e($key) ?>"<?= ($values['specialty']??'')===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
 <label><?= $e($t('email')) ?> *<input type="email" name="email" maxlength="190" autocomplete="email" dir="ltr" value="<?= $value('email') ?>" required></label>
 <label>WhatsApp number *<input type="tel" name="phone" maxlength="30" autocomplete="tel" dir="ltr" placeholder="+9647501234567" value="<?= $value('phone') ?>" required></label>
 <label><?= $e($t('company')) ?><input name="sponsoring_company" maxlength="160" value="<?= $value('sponsoring_company') ?>"></label>
 </div><label><?= $e($t('reason')) ?> *<textarea name="complimentary_reason" maxlength="500" required rows="3"><?= $value('complimentary_reason') ?></textarea></label></fieldset>
 <label class="free-ticket-consent"><input type="checkbox" name="whatsapp_consent" value="1"<?= ($values['whatsapp_consent']??'')==='1'?' checked':'' ?>><span><?= $e($t('consent')) ?><small class="muted"><?= $e($t('consent_note')) ?></small></span></label>
 <section class="guest-review" aria-labelledby="guest-review-title"><h2 id="guest-review-title"><?= $e($t('review')) ?></h2><p data-guest-review></p><strong class="guest-zero-total"><?= $e($t('total')) ?></strong></section>
 <div class="form-actions"><a class="btn ghost" href="registrations.php"><?= $e($t('cancel')) ?></a><button class="btn violet" type="submit"<?= !GuestOrders::enabled()?' disabled':'' ?> data-guest-issue><?= $e($t('issue')) ?></button></div><p data-guest-form-error role="status" aria-live="polite"></p>
</form>
<?php endif; ?>
<section class="card guest-history"><h2><?= $e($t('history')) ?></h2><div class="table-wrap"><table><thead><tr><th><?= $e($t('name')) ?></th><th><?= $e($t('event')) ?></th><th><?= $e($t('reason')) ?></th><th><?= $e($t('staff')) ?></th><th><?= $e($t('quantity')) ?></th><th><?= $e($t('status')) ?></th><th><?= $e($t('total_label')) ?></th><th><?= $e($t('action')) ?></th></tr></thead><tbody>
<?php $history=GuestOrders::history($user);foreach($history as $row):$guest=json_decode($row['guest_json'],true,512,JSON_THROW_ON_ERROR); ?>
<tr><td><?= $e($guest['buyer_name']??$guest['email']) ?></td><td><?= $e($row['event_name']??'—') ?></td><td><?= $e($row['complimentary_reason']??'—') ?></td><td><?= $e($row['staff_name']) ?></td><td><?= (int)$row['ticket_quantity'] ?></td><td><?= $e((int)$row['is_complimentary']?($row['status']==='issued'?$t('complimentary'):($row['status']==='rejected'?$t('rejected'):$t('pending'))):$t('legacy')) ?></td><td><?= $row['provider_amount']===null?'—':$e(Page::money((int)$row['provider_amount'],$row['provider_currency']??'IQD')) ?></td><td><a href="<?= $e($rootUrl.'&order='.(int)$row['id']) ?>"><?= $e($t('details')) ?></a></td></tr>
<?php endforeach;if(!$history): ?><tr><td colspan="8"><?= $e($t('empty')) ?></td></tr><?php endif; ?></tbody></table></div></section>
<script type="application/json" id="guest-ticket-data"><?= json_encode(['words'=>$words,'enabled'=>GuestOrders::enabled(),'endpoint'=>$rootUrl,'csrf'=>\Ismile\Auth::csrf()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
</div>
<?php Page::bottom();
