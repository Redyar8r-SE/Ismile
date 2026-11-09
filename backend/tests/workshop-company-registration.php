<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Db;use Ismile\Office;use Ismile\WorkshopCashSale;use Ismile\GuestWorkshops;use Ismile\UserError;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_workshop_test_real_'))throw new RuntimeException('Isolated workshop database required.');
$company=\Ismile\Workshops::find((string)Db::value("SELECT id FROM workshops WHERE organizer='company' AND status='active' LIMIT 1"));
$person=Db::one("SELECT * FROM registrations WHERE status='paid' LIMIT 1");
if(!$company||!$person)throw new RuntimeException('Company and paid attendee fixtures required.');
$staff=['id'=>0,'role'=>'registration'];$before=(int)Db::value('SELECT COUNT(*) FROM workshop_bookings');$passed=0;
foreach(['office'=>static fn()=>Office::addWorkshop($person,$staff,['workshop'=>$company['id'],'payment_status'=>'paid','paid_how'=>'cash','amount_paid'=>(string)$company['price']]),'cash sale'=>static fn()=>WorkshopCashSale::register($person,$staff,['workshop'=>$company['id'],'cash_received'=>'1','cash_currency'=>'IQD','cash_amount'=>'100000'])]as$name=>$action){
    try{$action();throw new RuntimeException('Company '.$name.' registration was allowed.');}catch(UserError){$passed++;echo 'PASS: Company '.$name.' registration is rejected.'.PHP_EOL;}
}
if(GuestWorkshops::allowed($company))throw new RuntimeException('Company guest registration was allowed.');$passed++;
if((int)Db::value('SELECT COUNT(*) FROM workshop_bookings')!==$before)throw new RuntimeException('Rejected company registration changed bookings.');$passed++;
echo $passed.' company workshop access checks passed.'.PHP_EOL;
