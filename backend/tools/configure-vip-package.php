<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../bootstrap.php';
use Ismile\{App,Audit,Db,Settings,VipPackages,WorkshopCatalog,Workshops};
use Ismile\Payments\PsoolaPrices;
$action=$argv[1]??'--verify';
if(!in_array($action,['--prepare','--verify','--open'],true))throw new RuntimeException('Use --prepare, --verify or --open.');
if(!(bool)Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='vip_package_choices'"))throw new RuntimeException('Install the VIP package migration first.');
foreach(VipPackages::WORKSHOPS as $id){$w=Workshops::find($id);if(!$w||$w['organizer']!=='ismile'||$w['status']!=='active'||!$w['providerEventId']||!$w['providerShowId']||!$w['providerTicketTypeId'])throw new RuntimeException('An included workshop mapping is unavailable: '.$id);}
if($action==='--prepare'){
 Db::transaction(static function(){Db::one("SELECT k FROM settings WHERE k='registration_open' FOR UPDATE");Settings::set('vip_package_sales_open','0');Settings::set('vip_package_enabled','1');Settings::set('vip_registration_limit','0');Audit::log(null,'vip.package_prepared','setting',null,['usd'=>200,'sales_open'=>false,'website_limit'=>0,'workshops'=>VipPackages::WORKSHOPS]);});
}
@unlink(App::storage('tmp/psoola-prices.json'));
if($action==='--open'){
 if(!VipPackages::enabled()||Settings::int('vip_registration_limit')!==0)throw new RuntimeException('Prepare the package first.');
 // The explicit open is permitted only after the server verifies the actual provider price.
 Db::transaction(static function(){
  Db::one("SELECT k FROM settings WHERE k='registration_open' FOR UPDATE");
  Settings::set('vip_package_sales_open','1');
  if(!Settings::bool('registration_open')||!VipPackages::ready())throw new RuntimeException('PSOOLA has not confirmed the $200 package or registration is closed. Sales remain closed.');
  $available=false;foreach(VipPackages::WORKSHOPS as $id){$w=WorkshopCatalog::one($id,true);$available=$available||($w&&$w['availabilityVerified']&&$w['seatsLeft']>0);}
  if(!$available)throw new RuntimeException('Neither included workshop has an available verified place.');
  Audit::log(null,'vip.package_opened','setting',null,['usd'=>200,'provider_quote'=>VipPackages::expectedPrice()]);
 });
}
$prices=PsoolaPrices::get();
echo json_encode(['enabled'=>VipPackages::enabled(),'salesOpen'=>Settings::bool('vip_package_sales_open'),'checkoutReady'=>VipPackages::ready(),'websiteVipLimit'=>Settings::int('vip_registration_limit'),'usd'=>200,'expectedProviderQuote'=>VipPackages::expectedPrice(),'actualProviderVip'=>isset($prices['ticketTypes']['vip'])?['amount'=>$prices['ticketTypes']['vip'],'currency'=>$prices['currency']]:null,'workshops'=>VipPackages::WORKSHOPS,'savedChoices'=>(int)Db::value('SELECT COUNT(*) FROM vip_package_choices')],JSON_UNESCAPED_SLASHES).PHP_EOL;
