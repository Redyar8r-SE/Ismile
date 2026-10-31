<?php
// Integration check: run only against .local/setup-map-39.php's isolated database.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\BoothPlan;
use Ismile\Db;
use Ismile\Sponsors;
use Ismile\UserError;

if (App::isLive() || !str_starts_with((string)App::config('db.name'), 'ismile_booth_test_map39_')) throw new RuntimeException('Use the isolated map-renumbering test database only.');
$passed=0;
$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo 'PASS: '.$name."\n";};
$rejects=static function(callable $fn):bool{try{$fn();return false;}catch(UserError){return true;}};
$folder=dirname((string)App::config('site_root'));
$before=json_decode((string)file_get_contents($folder.'/before-bookings.json'),true,512,JSON_THROW_ON_ERROR);
$existingIds=array_column($before,'id');
$callsBefore=json_decode((string)file_get_contents($folder.'/before-calls.json'),true,512,JSON_THROW_ON_ERROR);
$existing=static fn():array=>Db::all('SELECT * FROM sponsor_requests WHERE id IN ('.implode(',',array_fill(0,count($existingIds),'?')).') ORDER BY id',$existingIds);
$check('upgrade preserves every field of all 12 paid sponsor records', $existing()===$before);
$check('all 12 original call agreements are preserved',count($callsBefore)===12 && Db::all('SELECT * FROM sponsor_calls ORDER BY id')===$callsBefore);
BoothPlan::assertDatabaseMatches();
$positions=Db::all('SELECT * FROM sponsor_map_positions ORDER BY display_number');
$ids=[1,2,3,5,6,7,8,9,10,11,12,15,16,17,18,19,20,21,22,23,24,25,26,27,28,30,32,33,34,35,36,37,38,39,40,41,42,43,44];
$check('database has exactly 39 distinct consecutive display numbers',array_map('intval',array_column($positions,'display_number'))===range(1,39));
$check('every displayed number maps to the approved original position ID',array_map('intval',array_column($positions,'position_id'))===$ids);
$check('public availability retains all original booked position IDs',Sponsors::bookedBooths()===[1,5,7,15,16,17,18,19,20,23,43,44]);
$expectedBooked=[1=>1,5=>4,7=>6,15=>12,16=>13,17=>14,18=>15,19=>16,20=>17,23=>20,43=>38,44=>39];
foreach($expectedBooked as $id=>$label){
    $row=Db::one('SELECT * FROM `15_sponsor_map` WHERE `Map position`=?',[$label]);
    $check('existing position '.$id.' is still held by the same company at visible booth '.$label,$row['Company']==='Existing company '.$id && (int)$row['Internal position ID']===$id && BoothPlan::label($id)===(string)$label);
}
foreach([4,13,14,29,31] as $id)$check('removed position '.$id.' is absent from database map',!BoothPlan::active($id)&&!Db::value('SELECT position_id FROM sponsor_map_positions WHERE position_id=?',[$id]));
$owner=Db::one("SELECT * FROM admin_users WHERE email='map39-owner@test.invalid'");
$check('fixture owner can manage the map',Auth::canManageMap($owner));
$emails=(int)Db::value('SELECT COUNT(*) FROM emails');
$created=[];
foreach($positions as $p){
    $id=(int)$p['position_id'];
    $input=['kind'=>'sponsor','package_id'=>$p['tier'],'booth_number'=>(string)$id,'company'=>'New booth '.$p['display_number'],'contact'=>'New contact','phone'=>'+9647501114455','email'=>''];
    if(!isset($expectedBooked[$id])){
        $booking=Sponsors::createByStaff($input,$owner);
        $created[]=(int)$booking['id'];
        $check('new visible booth '.$p['display_number'].' reserves exactly position '.$id,$booking['booth_number']===(string)$id && BoothPlan::label($booking['booth_number'])===(string)$p['display_number']);
    }
    $check('visible booth '.$p['display_number'].' cannot be double booked',$rejects(static fn()=>Sponsors::createByStaff($input,$owner)));
}
$check('every one of the 39 physical positions is represented once when fully booked',count(Sponsors::bookedBooths())===39 && (int)Db::value('SELECT COUNT(*) FROM `15_sponsor_map` WHERE `Company` IS NOT NULL')===39);
foreach($created as $id)Sponsors::cancel($id,'End of isolated map test.',$owner);
$check('releasing the new test bookings preserves all original reservations',Sponsors::bookedBooths()===[1,5,7,15,16,17,18,19,20,23,43,44]);
$check('all original companies, contacts, prices, payments and history remain unchanged',$existing()===$before);
$check('existing call notes, agreed amounts and staff history remain unchanged',Db::all('SELECT * FROM sponsor_calls ORDER BY id')===$callsBefore);
$check('map operations do not send or queue emails',(int)Db::value('SELECT COUNT(*) FROM emails')===$emails);

// All assignment paths must refuse removed positions, including reactivation.
foreach([4,13,14,29,31] as $id){
    $input=['kind'=>'sponsor','package_id'=>'silver','booth_number'=>(string)$id,'company'=>'Removed space test','contact'=>'Test contact','phone'=>'+9647501114455'];
    $check('new booking refuses removed original ID '.$id,$rejects(static fn()=>Sponsors::createByStaff($input,$owner)));
}
$legacy=(int)(Db::value("SELECT id FROM sponsor_requests WHERE ref='SPN26-X0004'") ?? Db::insert('sponsor_requests',['ref'=>'SPN26-X0004','kind'=>'sponsor','package_id'=>'silver','company'=>'Historical removed booking','contact_name'=>'Test contact','phone'=>'+9647501114455','email'=>'','status'=>'waiting_list','booth_number'=>'4','created_at'=>App::now(),'updated_at'=>App::now()]));
Sponsors::saveDetails($legacy,['package_id'=>'silver','booth_number'=>'4','notes'=>'Keep original history.'],$owner);
$check('editing an inactive removed booking preserves its historical position ID',Sponsors::find($legacy)['booth_number']==='4' && Sponsors::find($legacy)['status']==='waiting_list');
$check('status reactivation cannot reserve a deleted space',$rejects(static fn()=>Sponsors::changeStatus(Sponsors::find($legacy),'new',$owner)));
$check('an agreed call cannot reactivate a deleted space',$rejects(static fn()=>Sponsors::logCall($legacy,['outcome'=>'agreed','amount'=>'500000'],$owner)));
$check('failed reactivation preserves the historical booking',Sponsors::find($legacy)['status']==='waiting_list' && Sponsors::find($legacy)['booth_number']==='4');

// Simulate a reservation made just before cutover: abort rather than dropping it.
Db::run("UPDATE sponsor_requests SET status='new' WHERE id=?",[$legacy]);
$directoryBefore=Db::all('SELECT * FROM sponsor_map_positions ORDER BY position_id');
$blocked=false;
try{BoothPlan::syncDatabase();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'Cannot remove reserved map position 4');}
$check('upgrade refuses to remove any newly reserved booth',$blocked);
$check('failed upgrade rolls back the whole map directory',Db::all('SELECT * FROM sponsor_map_positions ORDER BY position_id')===$directoryBefore);
Db::run("UPDATE sponsor_requests SET status='waiting_list' WHERE id=?",[$legacy]);
BoothPlan::syncDatabase();
BoothPlan::syncDatabase();
$check('repeated map synchronization preserves every existing booking',$existing()===$before);
$check('database sponsor directory exposes new numbers',Db::value('SELECT `Map position` FROM `07_sponsors` WHERE `Reference`=?',[$before[1]['ref']])===4);
$check('all map numbers stay in sync after repeat deployments',BoothPlan::count()===39 && count(Db::all('SELECT * FROM `15_sponsor_map`'))===39);
foreach(['platinum'=>6,'gold'=>7,'silver'=>17,'bronze'=>9] as $tier=>$count){
    $check($tier.' package capacity matches its new map count',(int)Db::value('SELECT places FROM sponsor_packages WHERE id=?',[$tier])===$count);
}
echo $passed." map-renumbering checks passed.\n";
