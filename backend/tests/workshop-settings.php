<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Auth;use Ismile\Db;use Ismile\UserError;use Ismile\Workshops;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_sponsored_test_')||App::config('payments.psoola.api_base')!=='http://127.0.0.1:8862')throw new RuntimeException('Isolated local workshop database required.');
$passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);echo "PASS: $name\n";$passed++;};
$reject=static function(callable $work):bool{try{$work();return false;}catch(UserError){return true;}};
$tag=bin2hex(random_bytes(5));$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('settings-'.$tag.'@example.invalid','Settings test Owner','owner',bin2hex(random_bytes(16)))]);
$delegate=['id'=>$owner['id'],'role'=>'viewer','registration_manage_access'=>1,'workshop_booking_access'=>1];
$base=['title_en'=>'Settings '.$tag,'title_ar'=>'ورشة','title_ku'=>'وۆرکشۆپ','speaker_en'=>'Trainer','credentials_en'=>'BDS','duration_en'=>'4 hours','overview_en'=>'Workshop overview','organizer'=>'ismile','total_seats'=>5,'price'=>100000,'currency'=>'IQD','website_status'=>'hidden','scheduled_date'=>'2026-11-20','start_time'=>'13:00','end_time'=>'17:00'];
$id=Workshops::create($base,$owner);$check('Owner can create a workshop hidden until ready',Workshops::find($id)['status']==='hidden');
$visible=static fn(string $id):bool=>in_array($id,array_column(json_decode(file_get_contents(App::siteFile('data/workshops.json')),true),'id'),true);
$check('hidden creation does not appear in website listings',!$visible($id));
$changed=$base;$changed['website_status']='active';$changed['title_en']='Published '.$tag;Workshops::update($id,$changed,$owner);
$check('name and visibility are saved together',Workshops::find($id)['status']==='active'&&Workshops::find($id)['title']['en']===$changed['title_en']&&$visible($id));
$check('translated and collapsed details are retained',Workshops::find($id)['title']['ar']===$base['title_ar']&&Workshops::find($id)['title']['ku']===$base['title_ku']&&Workshops::find($id)['credentials']['en']==='BDS'&&Workshops::find($id)['duration']['en']==='4 hours');
$legacy=$changed;unset($legacy['website_status']);Workshops::setStatus($id,'hidden',$owner);Workshops::update($id,$legacy,$owner);$check('legacy updates preserve existing visibility when omitted',Workshops::find($id)['status']==='hidden');
$legacyId=Workshops::create($legacy,$owner);$check('legacy creation remains active by default',Workshops::find($legacyId)['status']==='active');
$snapshot=static fn():string=>hash('sha256',json_encode(Db::one('SELECT * FROM workshops WHERE id=?',[$id])));
$before=$snapshot();foreach(['unknown',false,['active']] as $bad){$in=$changed;$in['website_status']=$bad;$check('invalid website visibility is rejected',$reject(fn()=>Workshops::update($id,$in,$owner))&&$snapshot()===$before);}
$check('workshop delegation does not grant Owner configuration rights',$reject(fn()=>Workshops::update($id,$changed,$delegate))&&$snapshot()===$before);
Workshops::update($id,$changed,$owner);
for($n=0;$n<2;$n++){$rid=Db::insert('registrations',['ref'=>'SS'.strtoupper(bin2hex(random_bytes(5))),'first_name'=>'Settings','father_name'=>'Fixture','grandfather_name'=>'Guest','email'=>'settings-'.$tag.'-'.$n.'@example.invalid','phone'=>'+964750'.random_int(1000000,9999999),'city'=>'Erbil','specialty'=>'gp','ticket_type'=>'professional','status'=>'paid','view_nonce'=>bin2hex(random_bytes(16)),'paid_at'=>App::now(),'created_at'=>App::now(),'updated_at'=>App::now()]);Db::insert('workshop_bookings',['registration_id'=>$rid,'workshop_id'=>$id,'price_agreed'=>100000,'payment_status'=>'unpaid','booked_by'=>$owner['id'],'created_at'=>App::now(),'updated_at'=>App::now()]);}
$before=$snapshot();$invalid=$changed;$invalid['title_en']='Must roll back';$invalid['website_status']='hidden';$invalid['price']=200000;$invalid['total_seats']=1;
$check('insufficient capacity rejects all edited fields atomically',$reject(fn()=>Workshops::update($id,$invalid,$owner))&&$snapshot()===$before&&$visible($id));
$invalid=$changed;$invalid['website_status']='hidden';$invalid['currency']='USD';$check('existing booking currency restrictions also protect visibility',$reject(fn()=>Workshops::update($id,$invalid,$owner))&&$snapshot()===$before);
$company=$changed;$company['organizer']='company';$company['company_en']='Preview Company';$company['company_phone']='+9647501234567';$company['company_phone_secondary']='+9647701234567';Workshops::update($id,$company,$owner);
$check('company organization and both contact numbers remain available',Workshops::find($id)['organizer']==='company'&&count(Workshops::find($id)['companyPhones'])===2);
$check('deletion is blocked for a workshop with booking history',$reject(fn()=>Workshops::delete($id,$owner)));
Db::update('workshop_bookings',['removed_at'=>App::now()],'workshop_id=?',[$id]);$check('removed bookings still protect the workshop from permanent deletion',$reject(fn()=>Workshops::delete($id,$owner)));
Workshops::delete($legacyId,$owner);$check('workshop without booking history can still be deleted',Workshops::find($legacyId)===null);
echo "$passed simplified settings checks passed.\n";
