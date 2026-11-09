<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Db;use Ismile\Settings;use Ismile\Checkouts;use Ismile\VipCapacity;use Ismile\Registrations;use Ismile\UserError;use Ismile\Payments\Payments;use Ismile\Payments\FakeGateway;
if(App::isLive()||App::config('payments.gateway')!=='fake'||!str_starts_with((string)App::config('db.name'),'ismile_vipcap_test_'))throw new RuntimeException('Isolated VIP capacity test only');
if(($argv[1]??'')==='--worker'){try{$r=Payments::start(Checkouts::find((int)$argv[2]));echo json_encode(['accepted'=>!!$r['redirect']]);}catch(UserError $e){echo json_encode(['accepted'=>false,'error'=>$e->key]);}exit;}
$passed=0;$check=static function($label,$ok)use(&$passed){if(!$ok)throw new RuntimeException('FAILED: '.$label);$passed++;};
Settings::set('registration_open','1');Settings::set('ticket_capacity','0');Settings::set('lunch_capacity_day1','0');Settings::set('lunch_capacity_day2','0');
$form=static fn()=>['first_name'=>'Capacity','father_name'=>'VIP','grandfather_name'=>'Guest','phone'=>'0750'.random_int(1000000,9999999),'email'=>'vipcap-'.bin2hex(random_bytes(5)).'@example.invalid','city'=>'Sulaimani','gender'=>'female','age'=>'28','specialty'=>'gp','lang'=>'en','ticket'=>'vip','lunch_day1'=>'1','vip_lunch_day'=>'1','pay'=>'visa','terms'=>'1'];
$pay=static function($checkout){Payments::start($checkout);return Db::one('SELECT * FROM payments WHERE checkout_id=? ORDER BY id DESC LIMIT 1',[$checkout['id']]);};
$complete=static function($payment){$state=FakeGateway::load($payment['provider_payment_id']);FakeGateway::save($payment['provider_payment_id'],array_replace($state,['status'=>'paid','paid'=>(int)$payment['amount_expected']]));return Payments::check($payment);};
for($i=0;$i<2;$i++)$check('Existing VIP registers',$complete($pay(Checkouts::createFromForm($form(),null)))==='paid');
$check('Two VIPs keep registration available',!VipCapacity::full());
$late=Checkouts::createFromForm($form(),null);$latePayment=$pay($late);Db::update('payments',['status'=>'expired'],'id=?',[$latePayment['id']]);
$a=Checkouts::createFromForm($form(),null);$b=Checkouts::createFromForm($form(),null);
for($i=0;$i<3;$i++)$check('Next VIP registers',$complete($pay(Checkouts::createFromForm($form(),null)))==='paid');
$check('Five confirmed VIPs still available',!VipCapacity::full());
$children=[];foreach([$a,$b] as $row){$process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--worker',(string)$row['id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$process,$pipes];}
$results=[];foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
$check('Concurrent last-seat requests accept only one',count(array_filter($results,static fn($r)=>$r['accepted']))===1);
$check('Pending last VIP locks availability',VipCapacity::full()&&Registrations::publicState('en')['vip']===['available'=>false]);
$payment=Db::one("SELECT * FROM payments WHERE status='waiting' ORDER BY id DESC LIMIT 1");$check('Sixth VIP confirmation succeeds',$complete($payment)==='paid');
try{Checkouts::createFromForm($form(),null);$refused=false;}catch(UserError $e){$refused=$e->key==='vip_unavailable';}$check('Seventh VIP submission refused',$refused);
$check('Late paid attempt creates no seventh VIP',$complete($latePayment)==='mismatch');
$check('Only six confirmed VIPs exist',(int)Db::value("SELECT COUNT(*) FROM registrations WHERE ticket_type='vip' AND status IN ('paid','complimentary')")===6);
$normal=['ticket'=>'professional','lunch_day1'=>'0','vip_lunch_day'=>'']+$form();$check('Other registration types remain available',Checkouts::createFromForm($normal,null)['ticket_type']==='professional');
$state=Registrations::publicState('en');$check('Public VIP availability exposes no seat count',array_keys($state['vip'])===['available']);
echo json_encode(['verified'=>true,'checks'=>$passed,'limit'=>6,'concurrentLastSeat'=>true,'latePaymentGuard'=>true,'otherTypesUnaffected'=>true,'publicSeatCounts'=>false]).PHP_EOL;
