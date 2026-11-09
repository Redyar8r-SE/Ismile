<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
use Ismile\App;use Ismile\Cities;use Ismile\Db;use Ismile\Auth;use Ismile\GuestOrders;use Ismile\Settings;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_sponsored_test_')||App::config('payments.psoola.api_base')!=='http://127.0.0.1:8862')throw new RuntimeException('Isolated simulator required');
$checks=0;$check=static function(string $name,bool $ok)use(&$checks):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$checks++;};
$expected=['Amarah','Baghdad','Baqubah','Basra','Diwaniyah','Duhok','Erbil','Halabja','Hillah','Karbala','Kirkuk','Kut','Mosul','Najaf','Nasiriyah','Ramadi','Samawah','Sulaimani','Tikrit'];
$cities=Cities::all();$check('All governorate capitals appear once',array_column($cities,'value')===$expected&&count(array_unique(array_column($cities,'governorate')))===19);
foreach(['en','ar','ckb'] as $locale){$html=Cities::select('Halabja',$locale);$check('Shared translated choices: '.$locale,substr_count($html,'<option ')===20&&str_contains($html,'value="Halabja" selected'));}
$check('Existing cities remain selected',str_contains(Cities::select('Existing town'),'value="Existing town" selected'));
$check('Existing city markup is escaped',!str_contains(Cities::select('<script>alert(1)</script>'),'<script>'));
$base=['buyer_name'=>'City Guest Test','email'=>'city-'.bin2hex(random_bytes(4)).'@example.invalid','phone'=>'+964750'.random_int(1000000,9999999),'complimentary_reason'=>'Local city test'];
$legacy=GuestOrders::guest($base);$check('Absent city keeps the legacy request unchanged',!array_key_exists('city',$legacy)&&GuestOrders::guest(['city'=>'']+$base)===$legacy);
$check('Selected city is retained',GuestOrders::guest(['city'=>'Halabja']+$base)['city']==='Halabja');
Settings::set('registration_open','1');Settings::set('ticket_capacity','0');
$owner=Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if(!$owner){$id=Auth::createUser('city-owner-'.bin2hex(random_bytes(4)).'@example.invalid','City Test Owner','owner',bin2hex(random_bytes(20)));$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[$id]);}
foreach(['gp'=>'Halabja','student'=>'Erbil'] as $specialty=>$city){
 $input=['attempt'=>bin2hex(random_bytes(16)),'first_name'=>'City','father_name'=>'Test','grandfather_name'=>'Guest','specialty'=>$specialty,'email'=>'city-'.$specialty.'-'.bin2hex(random_bytes(4)).'@example.invalid','phone'=>'+964750'.random_int(1000000,9999999),'city'=>$city,'complimentary_reason'=>'Local city persistence test','delivery_confirmation'=>'email-whatsapp-v1'];
 $order=GuestOrders::issueSingle($owner,$input);$check('Guest city is saved for '.$specialty,Db::value('SELECT city FROM registrations WHERE id=?',[$order['registration_id']])===$city);
 $retry=GuestOrders::issueSingle($owner,$input);$check('City selection keeps retry idempotent for '.$specialty,$retry['id']===$order['id']&&$retry['registration_id']===$order['registration_id']);
}
$order=GuestOrders::issue($owner,['attempt'=>bin2hex(random_bytes(16)),'buyer_name'=>'Options City Guest','email'=>'city-options-'.bin2hex(random_bytes(4)).'@example.invalid','phone'=>'+964750'.random_int(1000000,9999999),'city'=>'Baghdad','complimentary_reason'=>'Local options city test','event_id'=>'event-test','show_id'=>'show-test','ticket_type_id'=>'professional-test','quantity'=>1,'locale'=>'en']);
$check('Selectable guest ticket city is saved',Db::value('SELECT city FROM registrations WHERE id=?',[$order['registration_id']])==='Baghdad');
echo json_encode(['verified'=>true,'checks'=>$checks,'capitals'=>19,'guestCitySaved'=>true,'legacyRequestsPreserved'=>true]).PHP_EOL;
