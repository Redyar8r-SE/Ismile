<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Auth;use Ismile\Db;use Ismile\ApprovedWorkshops;use Ismile\WorkshopCatalog;use Ismile\WorkshopCheckout;
if(App::isLive() || !str_starts_with((string)App::config('db.name'),'ismile_workshop_test_online_') || App::config('payments.psoola.api_base')!=='http://127.0.0.1:8832')throw new RuntimeException('Isolated local online fixture only.');
$passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);echo "PASS: $name\n";$passed++;};
$fixture=static fn(string $query):array=>json_decode((string)file_get_contents('http://127.0.0.1:8832/fixture/'.$query),true);
$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('seat-owner@example.invalid','Owner','owner',bin2hex(random_bytes(16)))]);ApprovedWorkshops::publish($owner);
$id='simplified-direct-veneers';$fixture('stock?workshop='.$id.'&available=25');
$person=Db::insert('registrations',['ref'=>'ST'.strtoupper(bin2hex(random_bytes(5))),'first_name'=>'Seat','father_name'=>'Fixture','grandfather_name'=>'Guest','email'=>'seat-fixture@example.invalid','phone'=>'+9647501234567','city'=>'Sulaimani','specialty'=>'gp','ticket_type'=>'professional','status'=>'paid','view_nonce'=>bin2hex(random_bytes(16)),'paid_at'=>App::now(),'created_at'=>App::now(),'updated_at'=>App::now()]);
$w=WorkshopCatalog::one($id,true);$check('no checkout means 25 available and zero registered',$w['seatsLeft']===25&&$w['paidRegistrations']===0&&$w['pendingPayments']===0);
WorkshopCheckout::start($id,'seat-fixture@example.invalid');$attempt=Db::one('SELECT * FROM workshop_checkouts WHERE registration_id=? ORDER BY id DESC LIMIT 1',[$person]);$w=WorkshopCatalog::one($id,true);
$check('unpaid checkout is clearly separated from paid registrations',$w['seatsLeft']===24&&$w['paidRegistrations']===0&&$w['pendingPayments']===1&&(int)Db::value('SELECT COUNT(*) FROM workshop_bookings')===0);
$fixture('expire?id='.$attempt['provider_session_id']);WorkshopCheckout::reconcile($attempt['provider_session_id']);$w=WorkshopCatalog::one($id,true);
$check('expired unpaid hold returns to 25 without registering a person',$w['seatsLeft']===25&&$w['paidRegistrations']===0&&$w['pendingPayments']===0);
WorkshopCheckout::start($id,'seat-fixture@example.invalid');$next=Db::one('SELECT * FROM workshop_checkouts WHERE registration_id=? ORDER BY id DESC LIMIT 1',[$person]);$fixture('pay?id='.$next['provider_session_id']);WorkshopCheckout::reconcile($next['provider_session_id']);$w=WorkshopCatalog::one($id,true);
$check('paid checkout is one registered person and no pending payment',$w['seatsLeft']===24&&$w['paidRegistrations']===1&&$w['pendingPayments']===0&&(int)Db::value('SELECT COUNT(*) FROM workshop_bookings')===1);
echo $passed." workshop seat accounting checks passed.\n";
