<?php
// ISMILE_CONFIG=/isolated/config.php php backend/tests/sponsored-tickets.php
// Requires backend/tests/sponsored-provider.mjs on localhost. No live tickets or messages.
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App; use Ismile\Auth; use Ismile\Db; use Ismile\Settings; use Ismile\SponsoredTickets;
use Ismile\Tickets; use Ismile\UserError; use Ismile\Association; use Ismile\Admin\PaymentBreakdown;
use Ismile\Admin\DashboardIncome; use Ismile\Payments\PsoolaCheckIns; use Ismile\Payments\Payments;
if (App::isLive() || !str_starts_with((string)App::config('db.name'),'ismile_sponsored_test_')
    || App::config('payments.psoola.api_base') !== 'http://127.0.0.1:8862'
    || App::config('payments.psoola.api_key') !== 'sponsored-local-test-key') throw new RuntimeException('Use only the isolated local sponsored-ticket fixture.');
if (($argv[1] ?? '') === '--worker') {
    $payload=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    try { echo json_encode(['id'=>SponsoredTickets::issue($payload['user'],$payload['form'])['id']]); }
    catch(UserError $error) { echo json_encode(['rejected'=>$error->key]); }
    exit;
}
$passed=0;
$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo "PASS: $name\n";};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(UserError){return true;}};
$fixture=static fn():array=>json_decode((string)file_get_contents('http://127.0.0.1:8862/fixture'),true,512,JSON_THROW_ON_ERROR);
$tag=bin2hex(random_bytes(4));
$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('sponsored-owner-'.$tag.'@example.invalid','Sponsored Test Owner','owner',bin2hex(random_bytes(16)))]);
$manager=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser('sponsored-manager-'.$tag.'@example.invalid','Sponsored Test Manager','viewer',bin2hex(random_bytes(16)))]);
Db::update('admin_users',['registration_manage_access'=>1],'id=?',[$manager['id']]);
$manager=Db::one('SELECT * FROM admin_users WHERE id=?',[$manager['id']]);
Settings::set('registration_open','1'); Settings::set('ticket_capacity','0');
$form=static fn(string $prefix='guest'):array=>['attempt'=>bin2hex(random_bytes(16)), 'first_name'=>'Sara','father_name'=>'Ahmed','grandfather_name'=>'Hassan',
    'specialty'=>'gp','email'=>$prefix.'-'.bin2hex(random_bytes(4)).'@example.invalid','phone'=>'07501234567','sponsoring_company'=>'Example Dental Company'];
$input=$form();
$before=$fixture();
$check('ordinary staff cannot issue sponsored tickets',$reject(fn()=>SponsoredTickets::issue(['id'=>$owner['id'],'role'=>'registration'],$input)) && $fixture()['posts']===$before['posts']);
foreach(['first_name','father_name','grandfather_name','specialty','email','phone','sponsoring_company'] as $field) {
    $check('missing '.$field.' is rejected before contacting the provider',$reject(fn()=>SponsoredTickets::issue($owner,[$field=>'']+$input)) && $fixture()['posts']===$before['posts']);
}
$check('oversized company and invalid attempt are rejected',$reject(fn()=>SponsoredTickets::issue($owner,['sponsoring_company'=>str_repeat('x',161)]+$input)) && $reject(fn()=>SponsoredTickets::issue($owner,['attempt'=>'bad']+$input)));
$income=DashboardIncome::totals();$balance=Association::revenueBreakdown();$payments=(int)Db::value('SELECT COUNT(*) FROM payments');$emails=(int)Db::value('SELECT COUNT(*) FROM emails');
$guest=SponsoredTickets::issue($owner,$input);
$attempt=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$input['attempt']]);
$check('the guest is complimentary with company, names and normalized WhatsApp',$guest['status']==='complimentary' && $guest['sponsoring_company']===$input['sponsoring_company'] && $guest['phone']==='+9647501234567' && $guest['first_name']==='Sara' && $guest['father_name']==='Ahmed' && $guest['grandfather_name']==='Hassan');
$check('provider price is retained separately without inventing a guest payment',(int)$attempt['provider_amount']===30000 && $attempt['provider_currency']==='IQD' && (int)Db::value('SELECT COUNT(*) FROM payments')===$payments && DashboardIncome::totals()===$income && Association::revenueBreakdown()===$balance);
$check('the registered guest contributes zero to Association',Association::preview([(int)$guest['id']])['total']===0 && PaymentBreakdown::describe($guest,null)['finance_total']==='0 IQD (complimentary)');
$check('PSOOLA owns ticket delivery without a duplicate iSmile email',Tickets::issuedByPsoola(Tickets::forRegistration((int)$guest['id'])) && (int)Db::value('SELECT COUNT(*) FROM emails')===$emails && $attempt['email_status']==='queued');
$request=json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR);
$check('no WhatsApp consent is invented',!isset($request['buyer']['whatsappConsent']) && $attempt['whatsapp_status']===null);
$check('private ticket URLs are not saved',!str_contains($attempt['raw_response'],'private-token') && !str_contains($attempt['raw_response'],'ticketsUrl'));
$sent=$fixture();$again=SponsoredTickets::issue($owner,$input);
$check('repeated submission returns the existing guest without another API call',$again['id']===$guest['id'] && $fixture()['posts']===$sent['posts']);
$check('reusing a saved key with altered details is refused',$reject(fn()=>SponsoredTickets::issue($owner,['sponsoring_company'=>'Different company']+$input)) && $fixture()['posts']===$sent['posts']);
$check('another staff account cannot replay this request',$reject(fn()=>SponsoredTickets::issue($manager,$input)));
$check('a new key cannot issue another ticket to the same guest',$reject(fn()=>SponsoredTickets::issue($owner,['attempt'=>bin2hex(random_bytes(16))]+$input)) && $fixture()['posts']===$sent['posts']);
$consented=$form('consented');$consented['whatsapp_consent']='1';$consentGuest=SponsoredTickets::issue($manager,$consented);
$saved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE registration_id=?',[$consentGuest['id']]);$body=json_decode($saved['request_json'],true);$consent=$body['buyer']['whatsappConsent'];
$check('delegated staff can issue and save real consent evidence',$consent['acknowledged']===true && $consent['receivedAt']>0 && $consent['receivedAt']<=(int)floor(microtime(true)*1000) && $consent['categories']===['utility'] && $consent['reference']==='ismile-sponsored-consent-'.$consented['attempt'] && $saved['whatsapp_status']==='pending');
$student=$form('student');$student['specialty']='student';$studentGuest=SponsoredTickets::issue($owner,$student);
$check('sponsored dental students get student tickets without paid-discount documents',$studentGuest['specialty']==='student' && $studentGuest['ticket_type']==='student' && $studentGuest['university']===null && $studentGuest['id_photo_id']===null);
$check('paid student eligibility remains protected by the database',(function()use($studentGuest):bool{try{Db::update('registrations',['status'=>'paid'],'id=?',[$studentGuest['id']]);return false;}catch(PDOException){return true;}})());
$lost=$form('lost');$lost['whatsapp_consent']='1';$beforeLost=$fixture();$names=(int)Db::value('SELECT COUNT(*) FROM registrations');
$check('a lost response does not register an unconfirmed guest',$reject(fn()=>SponsoredTickets::issue($owner,$lost)) && (int)Db::value('SELECT COUNT(*) FROM registrations')===$names);
$lostSaved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$lost['attempt']]);$lostBody=$lostSaved['request_json'];
$lostGuest=SponsoredTickets::issue($owner,$lost);$lostAfter=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$lost['attempt']]);
$check('retry reuses exactly the same provider order, body, key and consent time',$fixture()['creations']===$beforeLost['creations']+1 && $lostAfter['status']==='issued' && $lostAfter['request_json']===$lostBody && $lostAfter['provider_order_id']!==null);
foreach(['wrong','items','waiting'] as $prefix){$bad=$form($prefix);$names=(int)Db::value('SELECT COUNT(*) FROM registrations');$check($prefix.' provider response cannot create a registration',$reject(fn()=>SponsoredTickets::issue($owner,$bad)) && (int)Db::value('SELECT COUNT(*) FROM registrations')===$names);}
$bad=$form('rejected');$names=(int)Db::value('SELECT COUNT(*) FROM registrations');
$check('a definitive provider rejection leaves no guest',$reject(fn()=>SponsoredTickets::issue($owner,$bad)) && Db::value('SELECT status FROM sponsored_ticket_orders WHERE attempt_key=?',[$bad['attempt']])==='rejected' && (int)Db::value('SELECT COUNT(*) FROM registrations')===$names);
$sent=$fixture();$check('a rejected request is not blindly retried',$reject(fn()=>SponsoredTickets::issue($owner,$bad)) && $fixture()['posts']===$sent['posts']);
$webhook=$form('webhook');$check('webhook fixture starts with an uncertain response',$reject(fn()=>SponsoredTickets::issue($owner,$webhook)));
$pending=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$webhook['attempt']]);
$session=array_values(array_filter($fixture()['sessions'],static fn($s)=>$s['partnerReference']===$pending['partner_reference']))[0];
$body=json_encode(['id'=>'sponsored-event-'.$tag,'type'=>'order.paid','data'=>['sessionId'=>$session['id'],'partnerReference'=>$pending['partner_reference']]]);
$timestamp=time();$signature='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$body,(string)App::config('payments.psoola.webhook_secret'));
$check('unsigned webhook cannot recover a sponsored guest',Payments::handleWebhook('psoola',['X-Psoola-Signature'=>'bad'],$body)['status']===401);
$response=Payments::handleWebhook('psoola',['X-Psoola-Signature'=>$signature],$body);
$check('signed webhook verifies with PSOOLA and recovers the lost request',$response['status']===200 && $response['outcome']==='sponsored ticket verified' && Db::value('SELECT status FROM sponsored_ticket_orders WHERE id=?',[$pending['id']])==='issued');
$names=(int)Db::value('SELECT COUNT(*) FROM registrations');Payments::handleWebhook('psoola',['X-Psoola-Signature'=>$signature],$body);
$check('repeated verified webhook creates no extra registration',(int)Db::value('SELECT COUNT(*) FROM registrations')===$names);
Settings::set('event_day1',date('Y-m-d'));Settings::set('event_day2',date('Y-m-d',strtotime('+1 day')));
$scan=['ticketCode'=>'SPONSORQR','firstScannedAt'=>time()*1000,'order'=>['id'=>$attempt['provider_order_id']]];
$check('provider scan matches a sponsored order even without its reference',PsoolaCheckIns::record([$scan])['added']===1);
$check('sponsored guest receives one certificate after attendance',(int)Db::value('SELECT COUNT(*) FROM certificates WHERE registration_id=?',[$guest['id']])===1 && PsoolaCheckIns::record([$scan])['added']===0);
file_get_contents('http://127.0.0.1:8862/fixture/delivery?id='.rawurlencode($saved['provider_order_id']));
SponsoredTickets::reconcile($saved['provider_order_id'],$saved['partner_reference']);
$updated=Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$saved['id']]);
$check('verified provider delivery updates do not create another ticket',$updated['email_status']==='sent' && $updated['whatsapp_status']==='delivered' && $updated['registration_id']===$saved['registration_id']);
file_get_contents('http://127.0.0.1:8862/fixture/tamper?id='.rawurlencode($saved['provider_order_id']));
$check('a changed provider ticket cannot overwrite a confirmed record',(function()use($saved):bool{try{SponsoredTickets::reconcile($saved['provider_order_id'],$saved['partner_reference']);return false;}catch(RuntimeException){return true;}})() && Db::value('SELECT email_status FROM sponsored_ticket_orders WHERE id=?',[$saved['id']])==='sent');
Settings::set('registration_open','0');$sent=$fixture();$check('closed registration prevents new issuance',$reject(fn()=>SponsoredTickets::issue($owner,$form('closed'))) && $fixture()['posts']===$sent['posts']);Settings::set('registration_open','1');
Settings::set('ticket_capacity','1');$sent=$fixture();$check('full capacity prevents new issuance',$reject(fn()=>SponsoredTickets::issue($owner,$form('full'))) && $fixture()['posts']===$sent['posts']);Settings::set('ticket_capacity','0');
$race=static function(array $forms,array $users):array {
    $children=[];
    foreach($forms as $index=>$input){
        $file=App::storage('tmp/sponsored-race-'.bin2hex(random_bytes(6)).'.json');
        file_put_contents($file,json_encode(['user'=>$users[$index],'form'=>$input],JSON_THROW_ON_ERROR));
        $command=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($command,'-c',$ini);array_push($command,__FILE__,'--worker',$file);
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker could not start.');
        fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $results=[];
    foreach($children as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Worker failed: '.$err.$out);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    return $results;
};
$same=$form('concurrent');$beforeRace=$fixture();$results=$race([$same,$same],[$owner,$owner]);
$check('concurrent identical submissions issue exactly one provider ticket and guest',isset($results[0]['id'],$results[1]['id']) && $results[0]['id']===$results[1]['id'] && $fixture()['creations']===$beforeRace['creations']+1);
$same=$form('different-keys');$second=['attempt'=>bin2hex(random_bytes(16))]+$same;$beforeRace=$fixture();$results=$race([$same,$second],[$owner,$manager]);
$check('two staff cannot issue the same guest with different request keys',count(array_filter($results,static fn($r)=>isset($r['id'])))===1 && count(array_filter($results,static fn($r)=>isset($r['rejected'])))===1 && $fixture()['creations']===$beforeRace['creations']+1);
$names=(int)Db::value('SELECT COUNT(*) FROM registrations');
$main=Db::one('SELECT * FROM admin_users WHERE email=?',[\Ismile\Erase::MAIN_OWNER]);
if(!$main)$main=Db::one('SELECT * FROM admin_users WHERE id=?',[Auth::createUser(\Ismile\Erase::MAIN_OWNER,'Test main Owner','owner',bin2hex(random_bytes(16)))]);
\Ismile\Erase::registration((int)$lostGuest['id'],$lostGuest['ref'],$main);
$check('archiving a sponsored guest keeps provider history without allowing an accidental replay',(int)Db::value('SELECT COUNT(*) FROM registrations')===$names-1 && Db::value('SELECT registration_id FROM sponsored_ticket_orders WHERE attempt_key=?',[$lost['attempt']])===null && $reject(fn()=>SponsoredTickets::issue($owner,$lost)));
echo "$passed sponsored ticket checks passed.\n";
