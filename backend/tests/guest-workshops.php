<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\GuestOrders;
use Ismile\GuestWorkshops;
use Ismile\Settings;
use Ismile\UserError;
use Ismile\WorkshopCheckout;
use Ismile\Workshops;

if (App::isLive() || !str_starts_with((string)App::config('db.name'),'ismile_sponsored_test_') || App::config('payments.psoola.api_base') !== 'http://127.0.0.1:8862') throw new RuntimeException('Isolated local simulator required.');
putenv('PSOOLA_API_BASE_URL=http://127.0.0.1:8862/api/partner/v1'); putenv('PSOOLA_API_KEY=sponsored-local-test-key');
if (($argv[1] ?? '') === '--worker') {
    $payload=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    try { echo json_encode(['id'=>GuestWorkshops::start($payload['user'],$payload['person'],$payload['form'])['id']]); }
    catch (UserError $error) { echo json_encode(['rejected'=>$error->key]); }
    exit;
}
$passed=0;
$check=static function(string $label,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$label);$passed++;echo "PASS: $label\n";};
$reject=static function(callable $fn,string $contains=''):bool{try{$fn();return false;}catch(UserError $e){return !$contains||str_contains($e->key,$contains);}};
$fixture=static fn(string $suffix=''):array=>json_decode(file_get_contents('http://127.0.0.1:8862/fixture'.$suffix),true,512,JSON_THROW_ON_ERROR);
$tag=bin2hex(random_bytes(4));
$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('gw-owner-'.$tag.'@example.invalid','Workshop Design Owner','owner',bin2hex(random_bytes(16)))]);
$viewer=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('gw-viewer-'.$tag.'@example.invalid','Workshop Design Viewer','viewer',bin2hex(random_bytes(16)))]);
Settings::set('registration_open','1');
foreach (\Ismile\ApprovedWorkshops::definitions() as $row) Db::update('workshops', ['organizer'=>$row['organizer'],'provider_event_id'=>$row['provider_event_id'],'provider_show_id'=>$row['provider_show_id'],'provider_ticket_type_id'=>$row['provider_ticket_type_id']], 'id=?', [$row['id']]);
foreach (\Ismile\ApprovedWorkshops::definitions() as $row) $fixture('/stock?event='.rawurlencode($row['provider_event_id']).'&available=25');
$person=static function(string $prefix,string $status='complimentary')use($tag,$owner):array{
    $id=Db::insert('registrations',['ref'=>'GW'.strtoupper(bin2hex(random_bytes(5))),'first_name'=>'Design','father_name'=>'Workshop','grandfather_name'=>'Attendee',
        'phone'=>'+964750'.random_int(1000000,9999999),'email'=>$prefix.'-'.$tag.'@example.invalid','city'=>'Erbil','specialty'=>'gp','ticket_type'=>'professional',
        'status'=>$status,'comp_reason'=>$status==='complimentary'?'Invited speaker':null,'view_nonce'=>bin2hex(random_bytes(16)),
        'created_by'=>$owner['id'],'paid_at'=>App::now(),'created_at'=>App::now(),'updated_at'=>App::now()]);
    return Db::one('SELECT * FROM registrations WHERE id=?',[$id]);
};
$form=static fn(string $workshop='simplified-direct-veneers'):array=>['attempt'=>bin2hex(random_bytes(16)),'workshop'=>$workshop,'complimentary_reason'=>'Invited workshop speaker','delivery_confirmation'=>'email-whatsapp-v1'];
$guest=$person('guest');$request=$form();$before=$fixture();$order=GuestWorkshops::start($owner,$guest,$request);
$booking=Db::one('SELECT * FROM workshop_bookings WHERE registration_id=?',[$guest['id']]);
$check('provider verified complimentary order is zero IQD', $order['status']==='issued' && (int)$order['is_complimentary']===1 && (int)$order['provider_amount']===0);
$check('one complimentary local booking with no cash collection', $booking['payment_status']==='complimentary' && (int)$booking['price_agreed']===0 && $booking['amount_paid']===null && $booking['paid_how']===null);
$check('workshop issuance does not create another summit registration', $order['registration_id']===null);
$body=json_decode($order['request_json'],true);
$check('request uses selected existing person and explicit complimentary contract', $body['buyer']['email']===$guest['email'] && $body['buyer']['name']==='Design Workshop Attendee' && $body['issuanceType']==='complimentary' && $body['items'][0]['quantity']===1 && !isset($body['returnUrl'],$body['price'],$body['total']));
$check('request includes actual consent confirmation', $body['buyer']['whatsappConsent']['acknowledged']===true);
$again=GuestWorkshops::start($owner,$guest,$request);
$check('same-key submission returns same order and booking', $again['id']===$order['id'] && $fixture()['creations']===$before['creations']+1 && (int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE registration_id=?',[$guest['id']])===1);
$check('changing the reason on a used key is refused', $reject(fn()=>GuestWorkshops::start($owner,$guest,array_replace($request,['complimentary_reason'=>'Different reason'])),'different details'));
$check('new-key duplicate for same attendee and workshop is refused', $reject(fn()=>GuestWorkshops::start($owner,$guest,$form()),'saved complimentary'));
$check('paid online route refuses existing complimentary order', $reject(fn()=>WorkshopCheckout::assertNoOpenPurchase((int)$guest['id'],'simplified-direct-veneers'),'saved complimentary'));
$check('ordinary viewer cannot book', $reject(fn()=>GuestWorkshops::start($viewer,$person('viewer'),$form()),'cannot issue'));
$delegated=$viewer;$delegated['registration_manage_access']=1;
$check('summit guest permission alone does not grant workshop booking', $reject(fn()=>GuestWorkshops::start($delegated,$person('delegated'),$form()),'cannot issue'));
$check('company workshop is rejected', $reject(fn()=>GuestWorkshops::start($owner,$person('company'),$form('mastering-zirconia-veneers')),'Company workshops'));
$check('missing reason rejected before local attempt', $reject(fn()=>GuestWorkshops::start($owner,$person('reason'),array_replace($form(),['complimentary_reason'=>'  '])),'reason'));
$check('missing consent rejected', $reject(fn()=>GuestWorkshops::start($owner,$person('consent'),array_replace($form(),['delivery_confirmation'=>''])),'permission'));
$invalid=$person('invalid-phone');$invalid['phone']='123';
$check('invalid guest phone rejected', $reject(fn()=>GuestWorkshops::start($owner,$invalid,$form()),'phone'));
$check('cancelled registration rejected', $reject(fn()=>GuestWorkshops::start($owner,$person('cancelled','cancelled'),$form()),'active'));
$fixture('/permission?enabled=0');$denied=$person('denied');
$check('missing permission explains PSOOLA setup', $reject(fn()=>GuestWorkshops::start($owner,$denied,$form()),'Allow complimentary issuance'));
$check('permission failure creates no workshop booking', !(bool)Db::value('SELECT id FROM workshop_bookings WHERE registration_id=?',[$denied['id']]));
$fixture('/permission?enabled=1');
$lost=$person('lost');$before=$fixture();$recovered=GuestWorkshops::start($owner,$lost,$form('crown-vs-veneer'));
$check('lost successful response recovers exactly one order', $recovered['status']==='issued' && $fixture()['creations']===$before['creations']+1 && (int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE registration_id=?',[$lost['id']])===1);
$offline=$person('network');$retryForm=$form();
$check('uncertain outcome stays recoverable', $reject(fn()=>GuestWorkshops::start($owner,$offline,$retryForm),'reconciliation'));
$saved=Db::one('SELECT * FROM guest_workshop_orders WHERE attempt_key=?',[$retryForm['attempt']]);
$check('uncertain request keeps payload, attempt and duplicate lock', $saved['state']==='pending' && $saved['active_pair']!==null && json_decode($saved['payload_json'],true)['attempt']===$retryForm['attempt']);
$check('cannot bypass uncertain request using a new key', $reject(fn()=>GuestWorkshops::start($owner,$offline,$form()),'saved complimentary'));
$mail=$person('mailfail');$before=$fixture();$mailOrder=GuestWorkshops::start($owner,$mail,$form());GuestOrders::ticketLink($owner,(int)$mailOrder['id']);
$check('email failure preserves issuance without another POST', $mailOrder['status']==='issued' && $mailOrder['email_status']==='failed' && $fixture()['creations']===$before['creations']+1);
GuestOrders::reconcile($order['provider_order_id'],$order['partner_reference'],'gw-event-'.$tag);GuestOrders::reconcile($order['provider_order_id'],$order['partner_reference'],'gw-event-'.$tag);
$check('duplicate verified webhook does not create another booking', (int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE registration_id=?',[$guest['id']])===1);
$last=$person('final-seat');$w=Workshops::find('crown-vs-veneer');$fixture('/stock?event='.rawurlencode($w['providerEventId']).'&available=1');$lastOrder=GuestWorkshops::start($owner,$last,$form('crown-vs-veneer'));
$check('last provider seat is mirrored without a second reservation', $lastOrder['status']==='issued' && (bool)Db::value('SELECT id FROM workshop_bookings WHERE registration_id=?',[$last['id']]));
$check('full provider inventory is respected', $reject(fn()=>GuestWorkshops::start($owner,$person('full'),$form('crown-vs-veneer')),'availability'));
$check('guest workshop bookings add no revenue or cash', (int)Db::value("SELECT COALESCE(SUM(COALESCE(b.amount_paid,0)),0) FROM workshop_bookings b JOIN guest_workshop_orders g ON g.booking_id=b.id")===0);
$racePerson=$person('parallel');$raceForm=$form();$before=$fixture();$children=[];
foreach ([$raceForm,$form()] as $formInput) {
    $file=App::storage('tmp/guest-workshop-race-'.bin2hex(random_bytes(5)).'.json');
    file_put_contents($file,json_encode(['user'=>$owner,'person'=>$racePerson,'form'=>$formInput]));
    $command=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($command,'-c',$ini);array_push($command,__FILE__,'--worker',$file);
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$process,$pipes];
}
$results=[];foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($error.$out);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
$check('concurrent distinct keys cannot double-issue one person and workshop',count(array_filter($results,static fn($r)=>isset($r['id'])))===1 && $fixture()['creations']===$before['creations']+1 && (int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE registration_id=?',[$racePerson['id']])===1);
$check('no paid fallback or checkout requests occurred', $fixture()['unsafePosts']===0 && $fixture()['checkoutPosts']===0);
echo "$passed guest workshop checks passed.\n";
