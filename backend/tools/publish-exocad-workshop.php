<?php
// Run by every deploy: creates the free iSmile exocad workshop (Dt. Bijan
// Iranduost, 19 Nov 2026 1:00-3:00 PM, 30 seats) in PSOOLA through the
// management API (hidden event, one session, a free "Workshop Admission"
// ticket), links it to the database and switches it on. Progress is saved,
// so a failed run resumes on the next deploy without making a second event.
// Done once: later deploys leave it alone, and after the Owner deletes it on
// the Workshops page it is never made again.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';
use Ismile\App;use Ismile\Audit;use Ismile\Db;use Ismile\WorkshopCatalog;use Ismile\WorkshopCheckout;use Ismile\Workshops;use Ismile\Payments\PsoolaGateway;
$id='exocad-digital-implant-design';
$file=App::storage('tmp/exocad-digital-implant-design-psoola-management.json');
if(is_file($file)&&!empty(json_decode((string)file_get_contents($file),true)['completedAt'])){echo "The exocad workshop is already in PSOOLA and on the website: left as it is.
";exit(0);}
if(!is_file($file)&&Db::value("SELECT COUNT(*) FROM audit_log WHERE action='workshop.psoola_publication' AND details LIKE ?",['%"'.$id.'"%'])){echo "The exocad workshop was published before: left as it is.
";exit(0);}
$lock=fopen($file.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Creation is already running.');
$def=json_decode((string)file_get_contents(__DIR__.'/../assets/exocad-workshop-2026.json'),true,512,JSON_THROW_ON_ERROR);
if($def['id']!==$id||$def['organizer']!=='ismile'||$def['price']!==0||$def['total_seats']!==30||$def['scheduled_date']!=='2026-11-19'||$def['start_time']!=='13:00'||$def['end_time']!=='15:00')throw new RuntimeException('Definition differs from the approved details.');
if(!is_file(App::siteFile($def['photo'])))throw new RuntimeException('Portrait not deployed yet.');
if(!Db::value('SELECT id FROM workshops WHERE id=?',[$id])){if(is_file($file))throw new RuntimeException('The workshop row was deleted during creation; check PSOOLA before running again.');Db::insert('workshops',$def+['sort_order'=>(int)Db::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM workshops'),'created_at'=>App::now(),'updated_at'=>App::now()]);}
$row=Db::one('SELECT * FROM workshops WHERE id=?',[$id]);
$owner=Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");if(!$owner)throw new RuntimeException('An enabled Owner is required.');
$state=is_file($file)?json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR):['originalWorkshop'=>$row,'requests'=>[],'responses'=>[]];
$save=static function()use(&$state,$file):void{$json=json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($file.'.writing',$json,LOCK_EX)===false)throw new RuntimeException('Cannot persist progress.');chmod($file.'.writing',0600);rename($file.'.writing',$file);};
$save();
$gateway=new PsoolaGateway();
$request=static function(string $step,string $method,string $path,?array $body=null)use(&$state,$save,$gateway):array{
 $key='ismile-ws-exocad-digital-implant-design-'.$step;if(strlen($key)>64)throw new RuntimeException('Idempotency key is too long.');
 if($method!=='GET'){$record=['method'=>$method,'path'=>$path,'body'=>$body,'key'=>$key];if(isset($state['requests'][$step])&&$state['requests'][$step]!==$record)throw new RuntimeException('Saved request changed: '.$step);$state['requests'][$step]=$record;$save();if(isset($state['responses'][$step]))return $state['responses'][$step];}
 for($attempt=0;$attempt<2;$attempt++){
  $result=$gateway->partnerRequest($method,'manage/'.$path,$body,$method==='GET'?[]:['Idempotency-Key: '.$key]);
  $json=$result['json']??[];$requestId=$json['requestId']??null;
  App::log($result['status']>=400?'error':'info','PSOOLA workshop management',['workshop'=>'exocad-digital-implant-design','method'=>$method,'path'=>'manage/'.$path,'status'=>$result['status'],'requestId'=>$requestId]);
  echo 'MANAGEMENT_CALL='.json_encode(['step'=>$step,'method'=>$method,'path'=>'manage/'.$path,'status'=>$result['status'],'requestId'=>$requestId]).PHP_EOL;
  if($result['status']!==429)break;sleep(5);
 }
 if(!in_array($result['status'],[200,201],true))throw new RuntimeException('PSOOLA '.$step.': HTTP '.$result['status'].' '.($json['error']['code']??'').' '.($json['error']['message']??'Request failed').' requestId='.($requestId??'none'));
 if($method!=='GET'){$state['responses'][$step]=$json;$save();}
 return $json;
};
$start=(new DateTimeImmutable('2026-11-19T13:00:00+03:00'))->getTimestamp()*1000;
$end=(new DateTimeImmutable('2026-11-19T15:00:00+03:00'))->getTimestamp()*1000;
if($start!==1795082400000||$end!==1795089600000)throw new RuntimeException('Iraq schedule conversion failed.');
$description="iSmile 2026 hands-on workshop with Dt. Bijan Iranduost.

Your First Digital Implant Design in exocad. Design your first implant case in exocad, step by step.

19 November 2026, 1:00–3:00 PM at Grand Millennium Sulaimani. All times are local to Iraq (Asia/Baghdad). Limited to 30 attendees. Free workshop registration. Suitable for all experience levels.

A paid iSmile summit ticket is required. Register online using the same summit email. No workshop payment is required.";
$ticketDescription="Dt. Bijan Iranduost. A paid iSmile summit ticket is required. Register online with your summit email. This workshop is free. Suitable for all experience levels.";
$category='td785em4z4zsgk26cencfv067587a1xd';$venue='r571e95gwse9zqj368qhqdv54h8g22kq';
if(!isset($state['eventId'])){
 $catalog=$request('managed-list','GET','events');
 foreach($catalog['events']??[] as $event)if(($event['title']??'')===$row['title_en'])throw new RuntimeException('A matching provider event already exists; verify it before creating another.');
 $result=$request('create','POST','events',['categoryId'=>$category,'event'=>[
  'title'=>$row['title_en'],'titleI18n'=>['en'=>$row['title_en']],
  'description'=>$description,'locationId'=>'n17btw319q1ffp2gnqkbefgvr987bq43',
  'startDate'=>$start,'endDate'=>$end,'showEndDateTime'=>true,'tags'=>['ismile-2026','workshop','dentistry']
 ]]);
 $state['eventId']=$result['event']['_id']??null;
 if(!$state['eventId']||($result['event']['showOnPsoola']??null)!==false)throw new RuntimeException('Creation did not return a hidden provider event.');
 $save();
}
$eventId=$state['eventId'];
if(!empty($row['provider_event_id'])&&$row['provider_event_id']!==$eventId)throw new RuntimeException('Workshop is already linked to another provider event.');
Db::update('workshops',['provider_event_id'=>$eventId,'updated_at'=>App::now()],'id=?',[$id]);
if(!isset($state['showId'])){
 $result=$request('show','POST','events/'.$eventId.'/shows',['venueId'=>$venue,'dateTime'=>$start,'endTime'=>$end]);
 $shows=array_values(array_filter($result['shows']??[],static fn($s)=>(int)($s['dateTime']??0)===$start&&(int)($s['endTime']??0)===$end&&($s['venueId']??'')===$venue));
 if(count($shows)!==1)throw new RuntimeException('Expected one matching workshop session.');
 $state['showId']=$shows[0]['_id'];$save();
}
$showId=$state['showId'];
Db::update('workshops',['provider_show_id'=>$showId,'updated_at'=>App::now()],'id=?',[$id]);
if(!isset($state['ticketTypeId'])){
 $result=$request('ticket','POST','events/'.$eventId.'/ticket-types',['showId'=>$showId,'ticket'=>['name'=>'Workshop Admission','description'=>$ticketDescription,'price'=>0,'quantity'=>30]]);
 $tickets=array_values(array_filter($result['ticketTypes']??[],static fn($t)=>($t['showId']??'')===$showId&&($t['name']??'')==='Workshop Admission'&&(int)($t['price']??0)===0&&(int)($t['quantity']??0)===30));
 if(count($tickets)!==1)throw new RuntimeException('Expected one matching admission ticket.');
 $state['ticketTypeId']=$tickets[0]['_id'];$save();
}
$ticketId=$state['ticketTypeId'];
Db::update('workshops',['provider_ticket_type_id'=>$ticketId,'updated_at'=>App::now()],'id=?',[$id]);
$artwork=[
 'coverImageWeb'=>['r2Key'=>'events/ismile-2026/web-3qta010q.jpg','url'=>'https://assets.psoola.com/events/ismile-2026/web-3qta010q.jpg','width'=>1200,'height'=>1500,'mimeType'=>'image/jpeg'],
 'coverImageMobile'=>['r2Key'=>'events/ismile-2026/mobile-dvgbspt1.jpg','url'=>'https://assets.psoola.com/events/ismile-2026/mobile-dvgbspt1.jpg','width'=>1300,'height'=>900,'mimeType'=>'image/jpeg'],
 'heroImageDesktop'=>['r2Key'=>'events/ismile-2026/heroDesktop-9bs8nfyv.jpg','url'=>'https://assets.psoola.com/events/ismile-2026/heroDesktop-9bs8nfyv.jpg','width'=>1920,'height'=>1080,'mimeType'=>'image/jpeg'],
 'heroImagePhone'=>['r2Key'=>'events/ismile-2026/heroPhone-y90cw3b5.jpg','url'=>'https://assets.psoola.com/events/ismile-2026/heroPhone-y90cw3b5.jpg','width'=>1080,'height'=>1350,'mimeType'=>'image/jpeg']
];
$request('artwork','PATCH','events/'.$eventId,['event'=>$artwork]);
$request('on-sale','PATCH','events/'.$eventId.'/shows/'.$showId,['status'=>'on_sale']);
$request('publish','POST','events/'.$eventId.'/status',['status'=>'published']);
$managed=$request('verify','GET','events/'.$eventId);
$event=$managed['event']??[];
$shows=array_values(array_filter($managed['shows']??[],static fn($s)=>($s['_id']??'')===$showId));
$tickets=array_values(array_filter($managed['ticketTypes']??[],static fn($t)=>($t['_id']??'')===$ticketId));
if(($event['status']??'')!=='published'||($event['showOnPsoola']??null)!==false||count($shows)!==1||count($tickets)!==1||$shows[0]['status']!=='on_sale'||(int)$shows[0]['dateTime']!==$start||(int)$shows[0]['endTime']!==$end||(int)$tickets[0]['price']!==0||(int)$tickets[0]['quantity']!==30||$tickets[0]['currency']!=='IQD')throw new RuntimeException('Provider verification failed.');
echo 'PSOOLA_TICKET='.json_encode(['eventId'=>$eventId,'showId'=>$showId,'ticketTypeId'=>$ticketId,'maxPerOrder'=>$tickets[0]['maxPerOrder']??null]).PHP_EOL;
$sales=$gateway->partnerRequest('GET','events/'.$eventId);
if($sales['status']!==200)throw new RuntimeException('Published workshop is not available through the sales API.');
$catalog=WorkshopCatalog::fromEvent($sales['json']['event'],Workshops::find($id));
if($catalog['price']!==0||$catalog['scheduledDate']!=='2026-11-19'||$catalog['startTime']!=='13:00'||$catalog['endTime']!=='15:00'||$catalog['seatsLeft']<0||$catalog['seatsLeft']>30)throw new RuntimeException('Sales catalog does not match the workshop.');
Db::transaction(static function()use($id,$row,$owner,$eventId,$showId,$ticketId):void{
 $current=Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$id]);
 foreach(['title_en','organizer','price','total_seats','scheduled_date','start_time','end_time','photo'] as $key)if($current[$key]!==$row[$key])throw new RuntimeException('Workshop changed during provider creation: '.$key);
 Db::update('workshops',['status'=>'active','updated_at'=>App::now()],'id=?',[$id]);
 Audit::log((int)$owner['id'],'workshop.psoola_publication','workshop',null,['id'=>$id,'provider_event_id'=>$eventId,'provider_show_id'=>$showId,'provider_ticket_type_id'=>$ticketId,'price'=>0,'total_seats'=>30,'showOnPsoola'=>false]);
});
Workshops::syncWebsite();@unlink(App::storage('tmp/workshop-catalog-'.$id.'.json'));
$verified=WorkshopCatalog::one($id,true);
if(!$verified||!$verified['availabilityVerified']||$verified['status']!=='active'||$verified['price']!==0||$verified['organizer']!=='ismile')throw new RuntimeException('iSmile catalog verification failed.');
$probe='exocad-workshop-check@example.invalid';
if(!Db::value('SELECT id FROM registrations WHERE LOWER(email)=?',[$probe])){try{WorkshopCheckout::start($id,$probe);throw new RuntimeException('Unregistered email was accepted.');}catch(\Ismile\UserError $e){if($e->getMessage()!=='wd_err_not_registered')throw $e;}}
$state['completedAt']=App::now();$state['verifiedCatalog']=$verified;$save();
echo 'WORKSHOPS_VERIFIED='.json_encode(['workshop'=>$verified,'eventId'=>$eventId,'showId'=>$showId,'ticketTypeId'=>$ticketId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
