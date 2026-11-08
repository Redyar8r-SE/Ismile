<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Ismile\WorkshopCatalog;
use Ismile\WorkshopCheckout;
$passed=0;
$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo 'PASS: '.$name.PHP_EOL;};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(RuntimeException){return true;}};
$attempt=['request_json'=>json_encode(['eventId'=>'free-event','showId'=>'free-show','items'=>[['ticketTypeId'=>'free-ticket','quantity'=>1]]]),'partner_reference'=>'free-reference','provider_session_id'=>null,'amount'=>0];
$session=['id'=>'free-session','eventId'=>'free-event','showId'=>'free-show','partnerReference'=>'free-reference','status'=>'paid','order'=>['items'=>[['ticketTypeId'=>'free-ticket','quantity'=>1]],'totalAmount'=>0,'currency'=>'IQD']];
WorkshopCheckout::validate($attempt,$session);$check('A verified zero-price attempt accepts an exact zero-total provider result',true);
foreach([null,100000] as $amount){$other=$attempt;$other['amount']=$amount;$check('A zero-total result is refused without an explicitly zero-price attempt',$reject(fn()=>WorkshopCheckout::validate($other,$session)));}
foreach([-1,0.5,25000] as $amount){$other=$session;$other['order']['totalAmount']=$amount;$check('A free registration refuses a negative, fractional or nonzero total',$reject(fn()=>WorkshopCheckout::validate($attempt,$other)));}
foreach(['currency'=>'USD','items'=>[['ticketTypeId'=>'another-ticket','quantity'=>1]]] as $key=>$value){$other=$session;$other['order'][$key]=$value;$check('Free registration validates its '.$key,$reject(fn()=>WorkshopCheckout::validate($attempt,$other)));}
foreach(['eventId'=>'another-event','showId'=>'another-show','partnerReference'=>'another-reference'] as $key=>$value){$other=$session;$other[$key]=$value;$check('Free registration validates its '.$key,$reject(fn()=>WorkshopCheckout::validate($attempt,$other)));}
$other=$attempt;$other['provider_session_id']='different-session';$check('Free registration validates the saved provider session',$reject(fn()=>WorkshopCheckout::validate($other,$session)));
$workshop=['providerEventId'=>'free-event','providerShowId'=>'free-show','providerTicketTypeId'=>'free-ticket','price'=>0];
$event=['id'=>'free-event','shows'=>[['id'=>'free-show','dateTime'=>1795168800000,'endTime'=>1795176000000,'ticketTypes'=>[['id'=>'free-ticket','price'=>0,'available'=>30]]]]];
$catalog=WorkshopCatalog::fromEvent($event,$workshop);$check('Free inventory preserves zero IQD, 30 seats and Iraq schedule',$catalog['price']===0&&$catalog['seatsLeft']===30&&$catalog['scheduledDate']==='2026-11-20'&&$catalog['startTime']==='13:00'&&$catalog['endTime']==='15:00');
$workshop['price']=100000;$check('A paid workshop does not silently become free on a zero-price provider response',$reject(fn()=>WorkshopCatalog::fromEvent($event,$workshop)));
echo $passed.' free workshop validation checks passed.'.PHP_EOL;
