<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\{App,Auth,Db,Settings,VipCapacity,VipPackages,Checkouts,Registrations,GuestWorkshops,WorkshopCatalog,UserError};
use Ismile\Payments\Payments;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_vippkg_test_')||App::config('payments.psoola.api_base')!=='http://127.0.0.1:8838')throw new RuntimeException('Isolated VIP package fixture required');
$form=static fn($id='simplified-direct-veneers')=>['attempt'=>bin2hex(random_bytes(16)),'first_name'=>'Package','father_name'=>'VIP','grandfather_name'=>'Guest','phone'=>'0750'.random_int(1000000,9999999),'email'=>'vip-'.bin2hex(random_bytes(5)).'@example.invalid','city'=>'Sulaimani','gender'=>'female','age'=>'28','specialty'=>'gp','lang'=>'en','ticket'=>'vip','lunch_day1'=>'1','vip_lunch_day'=>'1','terms'=>'1','vip_workshop'=>$id];
if(($argv[1]??'')==='--worker'){try{Checkouts::createFromForm($form('crown-vs-veneer'),null);echo 'accepted';}catch(UserError $e){echo $e->key;}exit;}
$passed=0;$check=static function(string $label,bool $ok)use(&$passed){if(!$ok)throw new RuntimeException('FAILED: '.$label);echo 'PASS: '.$label.PHP_EOL;$passed++;};
$reject=static function(callable $fn,string $key):bool{try{$fn();return false;}catch(UserError $e){return $e->key===$key;}};
$fixture=static fn($path='')=>json_decode(file_get_contents('http://127.0.0.1:8838/fixture'.$path),true);
$refresh=static function(){@unlink(App::storage('tmp/psoola-prices.json'));};
$fixture('/price?amount=169000');$refresh();
foreach(['registration_open'=>'1','ticket_capacity'=>'0','lunch_capacity_day1'=>'0','lunch_capacity_day2'=>'0','vip_registration_limit'=>'0','vip_package_enabled'=>'1','vip_package_sales_open'=>'0'] as $key=>$value)Settings::set($key,$value);
$ownerId=Auth::createUser('vip-owner@example.invalid','VIP Owner','owner',bin2hex(random_bytes(16)));$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[$ownerId]);
$check('Separate sales switch keeps checkout closed',!VipPackages::ready()&&$reject(fn()=>Checkouts::createFromForm($form(),null),'vip_package_not_ready'));
Settings::set('vip_package_sales_open','1');$check('Old provider price cannot sell the $200 package',!VipPackages::ready()&&$reject(fn()=>Checkouts::createFromForm($form(),null),'vip_package_not_ready'));
$fixture('/price?amount=338000');$refresh();$check('Verified official $200 IQD quote permits checkout',VipPackages::ready()&&VipPackages::expectedPrice()===['amount'=>338000,'currency'=>'IQD']);
$check('Missing workshop rejected',$reject(fn()=>Checkouts::createFromForm(array_diff_key($form(),['vip_workshop'=>1]),null),'vip_workshop_required'));
$check('Other workshops cannot be injected',$reject(fn()=>Checkouts::createFromForm($form('pediatric-dentistry'),null),'vip_workshop_required'));
$both=Checkouts::createFromForm(array_replace($form(),['lunch_day2'=>'1']),null);
$check('Optional second lunch remains available with one included day',(int)$both['lunch_day1']===1&&(int)$both['lunch_day2']===1&&(int)$both['vip_lunch_day']===1);
VipPackages::assertPrice($both,['amount'=>405600,'currency'=>'IQD']);
$check('Optional extra lunch uses provider price on top of package',true);
try{VipPackages::assertPrice($both,['amount'=>338000,'currency'=>'IQD']);$bad=false;}catch(\RuntimeException){$bad=true;}$check('Extra lunch cannot be silently included in base price',$bad);
Db::update('checkouts',['status'=>'expired'],'id=?',[$both['id']]);
$input=$form();$checkout=Checkouts::createFromForm($input,null);$choice=VipPackages::forCheckout((int)$checkout['id']);
$check('Workshop saved before payment',$choice['workshop_id']===$input['vip_workshop']&&$choice['state']==='held'&&$choice['registration_id']===null);
$check('Repeated submission keeps original choice and request key',Checkouts::createFromForm($input,null)['id']===$checkout['id']&&VipPackages::forCheckout((int)$checkout['id'])['request_key']===$choice['request_key']);
$inventory=WorkshopCatalog::one($input['vip_workshop'],true);$check('Local inventory subtracts VIP holds',VipPackages::held($input['vip_workshop'])===1&&$inventory['seatsLeft']===49);
$started=Payments::start($checkout);$payment=Db::one('SELECT * FROM payments WHERE checkout_id=?',[$checkout['id']]);
$check('Provider checkout charges exact package only',$started['redirect']!==null&&(int)$payment['amount_expected']===338000&&$payment['currency']==='IQD');
$sessions=$fixture()['sessions'];$request=$sessions[0]['request'];$check('Included lunch is not charged as a separate addon',$request['items'][0]['ticketTypeId']==='vip'&&empty($request['items'][0]['addOnIds']));
$check('Unpaid checkout issues no workshop ticket',(int)Db::value('SELECT COUNT(*) FROM workshop_bookings')===0);
$fixture('/pay?id='.$payment['provider_payment_id']);$check('Verified payment confirms registration',Payments::check($payment)==='paid');
$choice=VipPackages::forCheckout((int)$checkout['id']);$person=Registrations::find((int)$choice['registration_id']);$booking=Db::one('SELECT * FROM workshop_bookings WHERE id=?',[$choice['booking_id']]);
$check('Same chosen workshop linked to paid VIP',$person['status']==='paid'&&$person['ticket_type']==='vip'&&$choice['state']==='issued'&&$booking['workshop_id']===$input['vip_workshop']&&(int)$booking['registration_id']===(int)$person['id']);
$check('Included ticket has no separate workshop revenue',(int)$booking['price_agreed']===0&&$booking['payment_status']==='complimentary'&&str_starts_with($booking['notes'],'Included in paid VIP package'));
$orders=array_filter($fixture()['sessions'],fn($s)=>isset($s['isComplimentary']));$order=array_values($orders)[0];
$check('Provider issues one complimentary workshop ticket',$order['order']['totalAmount']===0&&$order['request']['issuanceType']==='complimentary'&&$order['order']['ticketCount']===1);
$check('Email fulfillment does not forge WhatsApp permission',!isset($order['request']['buyer']['whatsappConsent']));
$before=count($fixture()['sessions']);Payments::check(Payments::find((int)$payment['id']));VipPackages::process();$check('Repeated payment and cron never duplicate ticket',count($fixture()['sessions'])===$before&&(int)Db::value('SELECT COUNT(*) FROM workshop_bookings')===1);
$check('Registration details retrieve the persisted workshop',VipPackages::forRegistration((int)$person['id'])['workshop_name']!=='');
$check('Included issuance rejects an invented entitlement',$reject(fn()=>GuestWorkshops::startIncludedVip($owner,$person,['attempt'=>bin2hex(random_bytes(16)),'workshop'=>'crown-vs-veneer']),'No paid VIP workshop entitlement exists.'));
Db::run('DELETE FROM checkouts WHERE id=?',[$checkout['id']]);$check('Choice survives checkout cleanup',VipPackages::forRegistration((int)$person['id'])['workshop_id']===$input['vip_workshop']);
// A paid entitlement survives a provider outage and is retried with its saved key.
$drift=Checkouts::createFromForm($form(),null);$fixture('/price?amount=169000');$answer=Payments::start($drift);
$check('Provider price drift returns no chargeable checkout link',$answer['redirect']===null&&Db::value('SELECT status FROM payments WHERE checkout_id=?',[$drift['id']])==='failed');
Db::update('checkouts',['status'=>'expired'],'id=?',[$drift['id']]);$fixture('/price?amount=338000');$refresh();
$noStock=Checkouts::createFromForm($form('crown-vs-veneer'),null);$fixture('/stock?id=crown-vs-veneer&available=0');
$check('Own local hold cannot bypass depleted provider inventory',$reject(fn()=>Payments::start($noStock),'vip_workshop_full'));
Db::update('checkouts',['status'=>'expired'],'id=?',[$noStock['id']]);$fixture('/stock?id=crown-vs-veneer&available=50');
$check('Expired unpaid checkouts release workshop holds',VipPackages::held('crown-vs-veneer')===0);
$fixture('/guest-failure?on=1');$pending=Checkouts::createFromForm($form('crown-vs-veneer'),null);Payments::start($pending);$p=Db::one('SELECT * FROM payments WHERE checkout_id=?',[$pending['id']]);$fixture('/pay?id='.$p['provider_payment_id']);Payments::check($p);$saved=VipPackages::forCheckout((int)$pending['id']);
$check('Provider outage retains paid workshop selection for review',$saved['state']==='review'&&$saved['registration_id']!==null&&$saved['booking_id']===null&&VipPackages::held('crown-vs-veneer')===1);
Db::update('registrations',['email'=>'edited-contact@example.invalid'],'id=?',[$saved['registration_id']]);
$fixture('/guest-failure?on=0');VipPackages::process();$after=VipPackages::forCheckout((int)$pending['id']);$check('Retry fulfills original selection with same request key',$after['state']==='issued'&&$after['request_key']===$saved['request_key']);
$check('Contact edits cannot change an already saved provider request',json_decode(Db::value('SELECT payload_json FROM guest_workshop_orders WHERE id=?',[$after['guest_request_id']]),true)['email']!=='edited-contact@example.invalid');
// A lost successful response is reconciled by partner reference, not reissued.
$lostInput=$form();$lostInput['email']='vip-lost-response@example.invalid';$lost=Checkouts::createFromForm($lostInput,null);Payments::start($lost);$p=Db::one('SELECT * FROM payments WHERE checkout_id=?',[$lost['id']]);$fixture('/pay?id='.$p['provider_payment_id']);Payments::check($p);$check('Lost response recovers exactly one workshop order',VipPackages::forCheckout((int)$lost['id'])['state']==='issued'&&(int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE registration_id=?',[VipPackages::forCheckout((int)$lost['id'])['registration_id']])===1);
// No fixed six-person website cap: physical workshop stock still applies.
for($i=0;$i<4;$i++){$c=Checkouts::createFromForm($form(),null);Payments::start($c);$p=Db::one('SELECT * FROM payments WHERE checkout_id=?',[$c['id']]);$fixture('/pay?id='.$p['provider_payment_id']);Payments::check($p);}
$check('More than six VIPs can register without a website cap',(int)Db::value("SELECT COUNT(*) FROM registrations WHERE ticket_type='vip' AND status='paid'")===7&&!VipCapacity::full());
$fixture('/stock?id=crown-vs-veneer&available=1');$children=[];foreach([1,2] as $_){$process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$process,$pipes];}$results=[];foreach($children as [$process,$pipes]){$results[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);}
$check('Concurrent last workshop seat held only once',count(array_filter($results,fn($r)=>$r==='accepted'))===1&&in_array('vip_workshop_full',$results,true));
$check('Database enforces one choice per registration',(int)Db::value('SELECT COUNT(*) FROM vip_package_choices WHERE registration_id=?',[$person['id']])===1);
Settings::set('vip_package_sales_open','0');$check('Prepared package stays closed until activation',!VipPackages::ready()&&!Registrations::publicState('en')['vip']['available']);
echo json_encode(['verified'=>true,'checks'=>$passed,'savedWorkshop'=>true,'idempotentTicket'=>true,'extraWorkshopCharge'=>0,'unlimitedVip'=>true,'salesOpen'=>false]).PHP_EOL;
