<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;use Ismile\Auth;use Ismile\Db;use Ismile\GuestOrders;use Ismile\PsoolaTickets;use Ismile\Tickets;use Ismile\UserError;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_sponsored_test_')||App::config('payments.psoola.api_base')!=='http://127.0.0.1:8862')throw new RuntimeException('Local simulator and isolated database required.');
putenv('PSOOLA_API_BASE_URL=http://127.0.0.1:8862/api/partner/v1');putenv('PSOOLA_API_KEY=sponsored-local-test-key');
$passed=0;$check=static function(string $label,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$label);$passed++;echo "PASS: $label\n";};
$reject=static function(callable $fn,string $contains=''):bool{try{$fn();return false;}catch(UserError $e){return !$contains||str_contains($e->key,$contains);}};
$fixture=static fn():array=>json_decode(file_get_contents('http://127.0.0.1:8862/fixture'),true,512,JSON_THROW_ON_ERROR);
$seed=static function(array $session,?string $lookup=null):void{$context=stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/json','content'=>json_encode(['session'=>$session,'lookupId'=>$lookup])]]);file_get_contents('http://127.0.0.1:8862/fixture/seed-order',false,$context);};
$tag=bin2hex(random_bytes(5));
$account=static fn(string $role):array=>Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('ticket-'.$role.'-'.$tag.'@example.invalid','Ticket Access '.ucfirst($role),$role,bin2hex(random_bytes(16)))]);
$owner=$account('owner');$registrar=$account('registration');$viewer=$account('viewer');$manager=$account('sponsors');
Db::update('admin_users',['registration_manage_access'=>1,'workshop_booking_access'=>1],'id=?',[$manager['id']]);$manager=Db::one('SELECT * FROM admin_users WHERE id=?',[$manager['id']]);
$person=static function(string $method='visa',?int $createdBy=null)use($owner,$tag):array{
    $id=Db::insert('registrations',['ref'=>'PT'.strtoupper(bin2hex(random_bytes(5))),'first_name'=>'API','father_name'=>'Ticket','grandfather_name'=>'Person','phone'=>'+964750'.random_int(1000000,9999999),'email'=>'ticket-person-'.bin2hex(random_bytes(4)).'-'.$tag.'@example.invalid','city'=>'Erbil','specialty'=>'gp','ticket_type'=>'professional','status'=>'paid','pay_method'=>$method,'view_nonce'=>bin2hex(random_bytes(16)),'created_by'=>$createdBy??$owner['id'],'paid_at'=>App::now(),'created_at'=>App::now(),'updated_at'=>App::now()]);
    return Db::one('SELECT * FROM registrations WHERE id=?',[$id]);
};
$pay=static function(array $r,string $gateway='psoola')use($owner,$tag,$seed):array{
    $provider='ticket-page-'.$tag.'-'.$r['id'];$payment=Db::insert('payments',['registration_id'=>$r['id'],'gateway'=>$gateway,'provider_payment_id'=>$provider,'method'=>$r['pay_method'],'amount_expected'=>40000,'amount_confirmed'=>40000,'status'=>'paid','currency'=>'IQD','created_at'=>App::now(),'updated_at'=>App::now()]);Tickets::issue((int)$r['id'],'payment',$payment,$owner['id']);
    $session=['id'=>$provider,'partnerReference'=>$r['ref'],'status'=>'paid','isComplimentary'=>false,'ticketsUrl'=>'https://psoola.com/t/mock-private-'.$tag.'-'.$r['id'],'order'=>['totalAmount'=>40000,'currency'=>'IQD']];$seed($session);return $session;
};
$web=$person();$webSession=$pay($web);$cash=$person('cash');$cashSession=$pay($cash);
$before=$fixture();$snapshot=hash('sha256',json_encode(Db::all('SELECT * FROM payments WHERE registration_id IN (?,?)',[$web['id'],$cash['id']])));
$check('paid website ticket has a provider button',PsoolaTickets::available($owner,$web));
$check('website ticket page comes from the verified provider order',PsoolaTickets::link($owner,(int)$web['id'])['ticketsUrl']===$webSession['ticketsUrl']);
$check('cash ticket page comes from the verified provider order',PsoolaTickets::link($owner,(int)$cash['id'])['ticketsUrl']===$cashSession['ticketsUrl']);
$check('delegated workshop staff can view another person\'s ticket',PsoolaTickets::link($manager,(int)$web['id'])['ticketsUrl']===$webSession['ticketsUrl']);
$check('viewer and non-owning cash staff cannot access provider link',$reject(fn()=>PsoolaTickets::link($viewer,(int)$web['id']),'cannot view')&&$reject(fn()=>PsoolaTickets::link($registrar,(int)$web['id']),'cannot view'));
$own=$person('cash',(int)$registrar['id']);$ownSession=$pay($own);$check('cash staff can view their own attendee ticket',PsoolaTickets::link($registrar,(int)$own['id'])['ticketsUrl']===$ownSession['ticketsUrl']);
$check('provider access route permits delegated staff but not ordinary viewers',Auth::viewerMayPost($manager,'psoola-ticket.php')&&!Auth::viewerMayPost($viewer,'psoola-ticket.php'));
$local=$person();$pay($local,'fake');$check('local-only ticket is not presented as a PSOOLA order',!PsoolaTickets::available($owner,$local)&&$reject(fn()=>PsoolaTickets::link($owner,(int)$local['id']),'No issued PSOOLA order'));
foreach(['id','partnerReference','status']as$key){$changed=$webSession;$changed[$key]=$key==='status'?'refunded':'different';$seed($changed,$webSession['id']);$check('reject mismatched provider '.$key,$reject(fn()=>PsoolaTickets::link($owner,(int)$web['id'])));}
$changed=$webSession;$changed['order']['totalAmount']=1;$seed($changed);$check('reject mismatched confirmed amount',$reject(fn()=>PsoolaTickets::link($owner,(int)$web['id']),'different order details'));
$changed=$webSession;$changed['order']['currency']='USD';$seed($changed);$check('reject mismatched currency',$reject(fn()=>PsoolaTickets::link($owner,(int)$web['id'])));
$changed=$webSession;$changed['ticketsUrl']=null;$seed($changed);$check('missing link is a retrieval error, never another issuance',$reject(fn()=>PsoolaTickets::link($owner,(int)$web['id']),'private ticket-page link'));
foreach(['javascript:alert(1)','http://psoola.com/t/private','https://psoola.com.evil.test/t/private','https://user@psoola.com/t/private','https://psoola.com:444/t/private',"https://psoola.com/t/private\n"]as$url)$check('unsafe ticket page rejected: '.parse_url($url,PHP_URL_SCHEME),!PsoolaTickets::safeUrl($url));
$check('official HTTPS ticket-page hosts accepted',PsoolaTickets::safeUrl('https://psoola.com/t/private')&&PsoolaTickets::safeUrl('https://tickets.psoola.com/t/private'));
$seed($webSession);Db::update('registrations',['status'=>'cancelled'],'id=?',[$web['id']]);$check('cancelled registration cannot retrieve ticket',$reject(fn()=>PsoolaTickets::link($owner,(int)$web['id']),'not active'));
$gift=GuestOrders::issueSingle($owner,['attempt'=>bin2hex(random_bytes(16)),'first_name'=>'Ticket','father_name'=>'Complimentary','grandfather_name'=>'Guest','specialty'=>'gp','email'=>'ticket-guest-'.$tag.'@example.invalid','phone'=>'+964750'.random_int(1000000,9999999),'complimentary_reason'=>'Invited speaker','delivery_confirmation'=>'email-whatsapp-v1']);
$check('complimentary admission uses existing verified private PSOOLA page',str_starts_with(PsoolaTickets::link($manager,(int)$gift['registration_id'])['ticketsUrl'],'https://psoola.com/t/private-'));
$giftBefore=$fixture();PsoolaTickets::link($owner,(int)$gift['registration_id']);$check('opening complimentary ticket never issues another order',$fixture()['posts']===$giftBefore['posts']);
file_get_contents('http://127.0.0.1:8862/fixture/tamper?id='.rawurlencode($gift['provider_order_id']));$check('complimentary zero-total validation remains enforced',$reject(fn()=>PsoolaTickets::link($owner,(int)$gift['registration_id']),'verify this complimentary'));
$check('paid ticket lookup does not change local payment records',hash('sha256',json_encode(Db::all('SELECT * FROM payments WHERE registration_id IN (?,?)',[$web['id'],$cash['id']])))===$snapshot);
$check('paid ticket lookups do not create paid orders',$fixture()['unsafePosts']===$before['unsafePosts']&&$fixture()['checkoutPosts']===$before['checkoutPosts']);
echo "$passed PSOOLA ticket-access checks passed.\n";
