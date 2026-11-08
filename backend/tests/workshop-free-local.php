<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Db;use Ismile\WorkshopCheckout;use Ismile\WorkshopCatalog;use Ismile\UserError;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_workshop_test_online_')||App::config('payments.psoola.api_base')!=='http://127.0.0.1:8832')throw new RuntimeException('Isolated local workshop database required.');
$passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo 'PASS: '.$name.PHP_EOL;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(UserError){return true;}};
$row=json_decode((string)file_get_contents(__DIR__.'/../assets/subperiosteal-workshop-2026.json'),true);$row['id']='local-free-workshop';$row['status']='active';$row['total_seats']=2;
Db::insert('workshops',$row+['sort_order'=>6,'created_at'=>App::now(),'updated_at'=>App::now()]);$id=$row['id'];
$before=json_decode((string)file_get_contents('http://127.0.0.1:8832/fixture'),true);
$person=Db::one("SELECT * FROM registrations WHERE email='online-paid@example.invalid'");if(!$person)throw new RuntimeException('Run online workshop checks first.');
$check('Native free registration retains summit eligibility',$reject(fn()=>WorkshopCheckout::start($id,'unknown-native@example.invalid'))&&$reject(fn()=>WorkshopCheckout::start($id,'online-free@example.invalid')));
$result=WorkshopCheckout::start($id,$person['email']);$attempt=Db::one('SELECT * FROM workshop_checkouts WHERE workshop_id=? AND registration_id=?',[$id,$person['id']]);$booking=Db::one('SELECT * FROM workshop_bookings WHERE id=?',[$attempt['booking_id']]);
$check('Native free registration confirms a zero-amount booking',(int)$booking['price_agreed']===0&&(int)$booking['amount_paid']===0&&$booking['paid_how']==='free'&&$attempt['provider_session_id']===null&&WorkshopCheckout::status($attempt['public_token'])['confirmed']);
$check('Confirmation uses the existing private result token',str_contains($result['redirect'],'workshop.html?id='.$id.'&payment='.$attempt['public_token']));
$check('Duplicate registration is refused',$reject(fn()=>WorkshopCheckout::start($id,$person['email'])));
$check('Local inventory decreases after free registration',WorkshopCatalog::one($id)['seatsLeft']===1);
foreach(['second','full'] as $suffix){$personId=Db::insert('registrations',['ref'=>'NF'.strtoupper(bin2hex(random_bytes(5))),'first_name'=>'Free','father_name'=>'Workshop','grandfather_name'=>$suffix,'email'=>'native-free-'.$suffix.'@example.invalid','phone'=>'+9647501234567','city'=>'Sulaimani','specialty'=>'gp','ticket_type'=>'professional','status'=>'paid','view_nonce'=>bin2hex(random_bytes(16)),'created_at'=>App::now(),'updated_at'=>App::now(),'paid_at'=>App::now()]);if($suffix==='second')WorkshopCheckout::start($id,'native-free-'.$suffix.'@example.invalid');else $check('Full workshop refuses another registration',$reject(fn()=>WorkshopCheckout::start($id,'native-free-'.$suffix.'@example.invalid')));}
$check('Capacity cannot be exceeded',(int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id=? AND removed_at IS NULL',[$id])===2&&WorkshopCatalog::one($id)['seatsLeft']===0);
$after=json_decode((string)file_get_contents('http://127.0.0.1:8832/fixture'),true);$check('Free registration makes no provider requests or orders',$after['creations']===$before['creations']&&$after['direct']===$before['direct']&&$after['reads']===$before['reads']);
echo $passed.' native free registration checks passed.'.PHP_EOL;
