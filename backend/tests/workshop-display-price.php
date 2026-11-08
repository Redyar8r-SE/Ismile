<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Db;use Ismile\WorkshopCatalog;use Ismile\Workshops;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_workshop_test_online_'))throw new RuntimeException('Use an isolated workshop test database.');
$passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo 'PASS: '.$name.PHP_EOL;};
$owner=Db::one("SELECT * FROM admin_users WHERE role='owner' LIMIT 1");
$id='usd-display-workshop';$input=['title_en'=>'Pediatric test','price'=>'252750','currency'=>'IQD','total_seats'=>'25','organizer'=>'ismile'];
Db::insert('workshops',['id'=>$id,'title_en'=>'Pediatric test','price'=>252750,'currency'=>'IQD','display_price'=>150,'display_currency'=>'USD','total_seats'=>25,'status'=>'active','created_at'=>App::now(),'updated_at'=>App::now()]);
$workshop=Workshops::find($id);$check('Advertised USD price preserves IQD accounting units',$workshop['price']===252750&&$workshop['currency']==='IQD'&&$workshop['displayPrice']===['amount'=>150,'currency'=>'USD']);
$catalog=WorkshopCatalog::one($id,true);$check('Public catalog carries both advertised price and settlement price',$catalog['displayPrice']['amount']===150&&$catalog['price']===252750&&$catalog['currency']==='IQD');
Workshops::syncWebsite();$cards=json_decode((string)file_get_contents(App::siteFile('data/workshops.json')),true,512,JSON_THROW_ON_ERROR);$card=array_values(array_filter($cards,static fn($w)=>$w['id']===$id))[0];$check('Website export keeps the USD display price separate',$card['displayPrice']['currency']==='USD'&&$card['price']===252750&&$card['currency']==='IQD');
Workshops::update($id,$input+['overview_en'=>'Updated workshop description'],$owner);$check('Editing workshop copy preserves the advertised quote',Workshops::find($id)['displayPrice']['amount']===150);
$input['price']='260000';Workshops::update($id,$input,$owner);$check('Changing accounting price clears an outdated advertised quote',Workshops::find($id)['displayPrice']===null&&Workshops::find($id)['price']===260000);
$rejected=false;try{Db::update('workshops',['display_price'=>150,'display_currency'=>null],'id=?',[$id]);}catch(PDOException){$rejected=true;}$check('Incomplete advertised currency is rejected by the database',$rejected);
echo $passed.' workshop display-price checks passed.'.PHP_EOL;
