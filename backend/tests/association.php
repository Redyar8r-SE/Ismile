<?php
// Financial handover lifecycle. Requires an isolated database; never run on production.
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;
use Ismile\Auth;
use Ismile\Association;
use Ismile\Db;
use Ismile\UserError;
use Ismile\Admin\PaymentBreakdown;
use Ismile\Admin\PdfReport;

if(App::isLive() || App::config('payments.gateway')!=='fake' || !str_starts_with((string)App::config('db.name'),'ismile_assoc_test_')) throw new RuntimeException('Use an isolated ismile_assoc_test_* database with fake payments.');
if(($argv[1]??'')==='--worker') {
    $payload=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    try { echo json_encode(['id'=>Association::confirm($payload['user'],$payload['draft'],$payload['token'])]); }
    catch(UserError $e) { echo json_encode(['rejected'=>$e->key]); }
    exit;
}
$passed=0;
$check=static function(string $name,bool $ok)use(&$passed):void {if(!$ok)throw new RuntimeException('FAILED: '.$name);$passed++;echo "PASS: $name\n";};
$reject=static function(callable $fn):bool {try{$fn();return false;}catch(UserError){return true;}};
$ownerId=Auth::createUser('association-'.bin2hex(random_bytes(4)).'@example.invalid','Association Test Owner','owner',bin2hex(random_bytes(16)));
Db::update('admin_users',['totp_enabled'=>1],'id=?',[$ownerId]);
$owner=Db::one('SELECT * FROM admin_users WHERE id=?',[$ownerId]);
$finance=['role'=>'finance']+$owner;$viewer=['role'=>'viewer']+$owner;
$seed=static function(int $ticket=30000,int $lunch=0,string $status='paid',string $currency='IQD',bool $receipt=true,bool $cash=true)use($ownerId):int {
    $now=App::now();
    $id=Db::insert('registrations',[
        'ref'=>'AT'.strtoupper(bin2hex(random_bytes(5))), 'first_name'=>'Example', 'father_name'=>'Association', 'grandfather_name'=>'Guest',
        'phone'=>'+9647501234567','email'=>'association@example.invalid','city'=>'Association Test City','gender'=>'female','specialty'=>'gp','ticket_type'=>'professional',
        'lunch_day1'=>$lunch?1:0,'pay_method'=>$status==='complimentary'?null:($cash?'cash':'visa'),'status'=>$status,'comp_reason'=>$status==='complimentary'?'Association test':null,
        'view_nonce'=>bin2hex(random_bytes(16)),'created_by'=>$ownerId,'created_at'=>$now,'paid_at'=>$now,'updated_at'=>$now,
    ]);
    if($status!=='complimentary') {
        $raw=json_encode(['channel'=>$cash?'direct':'checkout','order'=>['currency'=>$currency,'totalAmount'=>$ticket+$lunch,'items'=>[['quantity'=>1,'unitPrice'=>$ticket,'addOns'=>$lunch?[['addOnId'=>'association_lunch1','quantity'=>1,'unitPrice'=>$lunch]]:[]]]]]);
        $raw=PaymentBreakdown::archive($raw,['ticket_type'=>'professional','lunch_day1'=>$lunch?1:0,'lunch_day2'=>0],[],['lunchDay1'=>'association_lunch1']);
        Db::insert('payments',['registration_id'=>$id,'gateway'=>$cash?'fake':'psoola','method'=>$cash?'cash':'visa','amount_expected'=>$ticket+$lunch,'currency'=>$currency,'amount_confirmed'=>$ticket+$lunch,'status'=>'paid','raw_create'=>$receipt?$raw:'{}','raw_status'=>$receipt?$raw:'{}','created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>$now]);
    }
    return $id;
};
$remaining=static function():int {foreach(Association::revenue() as $r)if($r['currency']==='IQD')return (int)$r['total'];return 0;};
// Reproduce the old three reversed records that consumed internal IDs 001-003.
for($legacy=0;$legacy<3;$legacy++) {
    $legacyGuest=$seed();
    $legacyId=Association::confirm($owner,Association::preview([$legacyGuest]),bin2hex(random_bytes(32)));
    Association::reverse($owner,$legacyId,'Legacy reversed test batch');
    Db::update('association_batches',['batch_number'=>null],'id=?',[$legacyId]);
}
Db::update('settings',['v'=>'1'],"k='association_next_batch_number'");
Association::initializeBatchNumbers();
$a=$seed(30000);$b=$seed(40000,67200,cash:false);$c=$seed(25000);$free=$seed(0,0,'complimentary');
Db::update('registrations',['ambassador_code'=>'assoc26'],'id=?',[$b]);
$gross=$remaining();$draft=Association::preview([$a,$b]);
$check('selection includes website ticket and lunch without deducting money',$draft['total']===107200 && $remaining()===$gross && (int)Association::totals()['guests']===0);
$check('cash stays in the guest list but contributes zero to the handover',$draft['items'][0]['total_paid']===30000 && Association::preview([$a])['total']===0 && $draft['items'][1]['total_paid']===107200 && $draft['items'][1]['ticket_amount']===40000);
$check('viewer cannot confirm a handover',$reject(fn()=>Association::confirm($viewer,$draft,bin2hex(random_bytes(32)))));
$token=bin2hex(random_bytes(32));$id=Association::confirm($finance,$draft+['note'=>'Receipt EXAMPLE-001'],$token);
$check('first real batch displays 001 after three archived reversals',$id>3 && Association::label($id)==='Batch 001' && (int)Association::batch($id)['batch_number']===1);
$check('confirmed batch deducts tickets and lunch while excluding cash',$remaining()===$gross-107200 && (int)Association::totals()['guests']===2 && (int)Association::totals()['amount']===107200 && count(Association::items($id))===2);
$check('repeated submission returns the existing batch',Association::confirm($finance,$draft,$token)===$id && $remaining()===$gross-107200);
$check('repeat submissions do not consume batch numbers',(int)Db::value("SELECT v FROM settings WHERE k='association_next_batch_number'")===2);
$beforeNumbering=$remaining();
Db::update('association_batches',['batch_number'=>null],'id=?',[$id]);
Db::update('settings',['v'=>'1'],"k='association_next_batch_number'");
$check('existing active batches are numbered from 001 without changing money',Association::initializeBatchNumbers()===1 && Association::label($id)==='Batch 001' && $remaining()===$beforeNumbering && Association::initializeBatchNumbers()===0);
// Existing production headers contain the old ticket-only total; receipts remain authoritative.
Db::update('association_batches',['total_amount'=>70000],'id=?',[$id]);
$savedBatch=Association::batch($id);$savedHistory=Association::history();
$check('existing batch history and detail recalculate the amount without rewriting the saved header',(int)$savedBatch['total_amount']===70000 && (int)$savedBatch['receipt_amount']===107200 && (int)$savedBatch['active_amount']===107200 && (int)$savedHistory[0]['active_amount']===107200 && $remaining()===$gross-107200);
$beforeBackfill=Association::items($id);$beforePayments=Db::all('SELECT * FROM payments ORDER BY id');
Db::update('association_batch_items',['report_snapshot'=>null],'batch_id=? AND registration_id=?',[$id,$b]);
$check('existing batch report details are backfilled once without changing payments or balances',Association::backfillReportSnapshots()===1 && Association::backfillReportSnapshots()===0 && $beforePayments===Db::all('SELECT * FROM payments ORDER BY id') && $remaining()===$gross-107200);
Db::update('registrations',['ambassador_code'=>'changed-code','lunch_day1'=>0],'id=?',[$b]);
$check('already sent names cannot enter another batch',$reject(fn()=>Association::preview([$a])));
$count=(int)Db::value('SELECT COUNT(*) FROM association_batches');
$check('mixed eligible and already sent selection is rejected entirely',$reject(fn()=>Association::preview([$b,$c])) && (int)Db::value('SELECT COUNT(*) FROM association_batches')===$count);
$oldDraft=Association::preview([$c]);$oldDraft['total']=25000;$oldDraft['fingerprint']=hash('sha256',json_encode($oldDraft['items'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
$check('review opened under the previous amount rules must be reviewed again',$reject(fn()=>Association::confirm($owner,$oldDraft,bin2hex(random_bytes(32)))) && (int)Db::value('SELECT COUNT(*) FROM association_batches')===$count);
$stale=Association::preview([$c]);Db::update('registrations',['first_name'=>'Changed'],'id=?',[$c]);
$check('changed registration requires a fresh review',$reject(fn()=>Association::confirm($owner,$stale,bin2hex(random_bytes(32)))) && (int)Db::value('SELECT COUNT(*) FROM association_batches')===$count);
$stale=Association::preview([$c]);$payment=Db::one('SELECT * FROM payments WHERE registration_id=?',[$c]);
Db::update('payments',['raw_status'=>'{}','raw_create'=>'{}'],'id=?',[$payment['id']]);
$check('changed or missing payment breakdown prevents confirmation',$reject(fn()=>Association::confirm($owner,$stale,bin2hex(random_bytes(32)))));
Db::update('payments',['raw_status'=>$payment['raw_status'],'raw_create'=>$payment['raw_create']],'id=?',[$payment['id']]);
Db::update('registrations',['first_name'=>'Edited after handover'],'id=?',[$a]);
$snapshot=Association::items($id);
$check('saved batch keeps the original name and money',$snapshot[0]['full_name']==='Example Association Guest' && (int)$snapshot[0]['ticket_amount']===30000);
$sheet=Association::report(Association::batch($id),$snapshot);$pdf=PdfReport::make('Association '.Association::label($id),$sheet,true);
$expectedColumns=['#','Full name','Total paid','Phone','Ticket type','Lunch days','Lunch quantity','Ticket paid','Lunch day 1 paid','Lunch day 2 paid','Ticket no.','Ambassador','Reference','Payment date (Iraq)','Cash / website','Status'];
$check('Association PDF uses exactly the requested columns in the requested order',$sheet[1]===$expectedColumns && count($sheet[2])===16 && count($sheet[3])===16);
$detail=array_combine($sheet[1],$sheet[3]);
$check('Association PDF keeps saved lunch amounts, quantity and ambassador after edits',$detail['Total paid']==='107,200 IQD' && $detail['Ticket paid']==='40,000 IQD' && $detail['Lunch days']==='Day 1' && $detail['Lunch quantity']==='1' && $detail['Lunch day 1 paid']==='67,200 IQD' && $detail['Lunch day 2 paid']==='Not booked' && $detail['Ambassador']==='ASSOC26' && $detail['Status']==='Paid');
$check('batch PDF total includes saved website ticket and lunch but excludes cash',str_starts_with($pdf,'%PDF-') && strlen($pdf)>5000 && str_contains($sheet[0][0],'Total: 107,200 IQD') && str_contains($sheet[0][0],'Currently sent: 2 guests, 107,200 IQD') && str_contains($sheet[0][0],'Tickets and lunch included; cash excluded.') && !str_contains($sheet[0][0],'lunch excluded') && count($sheet)===4);
$cashDetail=array_combine($sheet[1],$sheet[2]);
$check('cash guest and their saved amounts remain visible in the PDF',$cashDetail['Cash / website']==='Cash' && $cashDetail['Total paid']==='30,000 IQD' && $cashDetail['Ticket paid']==='30,000 IQD' && $detail['Cash / website']==='Website (Psoola)');
file_put_contents(App::storage('tmp/association-example.pdf'),$pdf);
$check('viewer cannot reverse a handover',$reject(fn()=>Association::reverse($viewer,$id,'Example correction')));
$check('reversal requires a reason',$reject(fn()=>Association::reverse($owner,$id,'')));
Association::reverse($owner,$id,'Example correction');
$check('reversal restores money and guests without deleting history',$remaining()===$gross && (int)Association::totals()['guests']===0 && Association::batch($id)['status']==='reversed' && count(Association::items($id))===2 && Association::preview([$a])['total']===0);
$reversedReport=Association::report(Association::batch($id),Association::items($id));
$check('reversed PDF keeps the original noncash total and clears the currently sent amount',str_contains($reversedReport[0][0],'Total: 107,200 IQD') && str_contains($reversedReport[0][0],'Currently sent: 0 guests, 0 IQD'));
$check('batch cannot be reversed twice',$reject(fn()=>Association::reverse($owner,$id,'Again')));
$next=Association::confirm($owner,Association::preview([$a,$free]),bin2hex(random_bytes(32)));
$check('cash and free guests can be sent without reducing remaining revenue',(int)Association::batch($next)['guest_count']===2 && (int)Association::batch($next)['total_amount']===0 && $remaining()===$gross);
$cashReport=Association::report(Association::batch($next),Association::items($next));
$check('cash and complimentary only batch has zero PDF total while retaining both guests',str_contains($cashReport[0][0],'Total: 0 IQD') && str_contains($cashReport[0][0],'Currently sent: 2 guests, 0 IQD') && count($cashReport)===4);
$cancelled=$seed(30000,0,'cancelled');$unknown=$seed(30000,0,'paid','IQD',false);$usd=$seed(30,0,'paid','USD');
$check('cancelled guests are ineligible',$reject(fn()=>Association::preview([$cancelled])));
$check('missing receipt amounts are never guessed',$reject(fn()=>Association::preview([$unknown])));
$check('different currencies are not silently converted',$reject(fn()=>Association::preview([$usd])));
$duplicate=$seed();$p=Db::one('SELECT * FROM payments WHERE registration_id=?',[$duplicate]);unset($p['id']);Db::insert('payments',$p);
$check('multiple confirmed payments require review',$reject(fn()=>Association::preview([$duplicate])));
$check('selection parser deduplicates and validates IDs',Association::ids('['.$a.','.$a.']')===[$a] && $reject(fn()=>Association::ids('["1"]')) && $reject(fn()=>Association::ids('[]')));
$managerEmail='association-manager-'.bin2hex(random_bytes(4)).'@example.invalid';
$managerId=Auth::createUser($managerEmail,'Association Test Manager','viewer',bin2hex(random_bytes(16)));
putenv('ASSOCIATION_EDITOR_EMAIL='.$managerEmail);
$grantCommand=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($grantCommand,'-c',$ini);$grantCommand[]=__DIR__.'/../tools/configure-association-manager.php';
$grant = static function()use($grantCommand):string {
    $process=proc_open($grantCommand,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start grant tool');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('Grant tool failed: '.$err);return $out;
};
$beforeGrant=Db::all('SELECT * FROM admin_users ORDER BY id');
$grant();$grant();
$afterGrant=Db::all('SELECT * FROM admin_users ORDER BY id');
foreach($beforeGrant as &$account)if((int)$account['id']===$managerId)$account['association_manage_access']=1;unset($account);
$check('CLI grant changes only the designated account permission and is idempotent',$beforeGrant===$afterGrant && (int)Db::value("SELECT COUNT(*) FROM audit_log WHERE action='user.association_manage_grant' AND target_id=?",[$managerId])===1);
$manager=Db::one('SELECT * FROM admin_users WHERE id=?',[$managerId]);
$managerGuest=$seed();$managerBatch=Association::confirm($manager,Association::preview([$managerGuest]),bin2hex(random_bytes(32)));
$check('designated viewer can confirm a batch without changing role',$manager['role']==='viewer' && (int)Association::batch($managerBatch)['created_by']===$managerId);
Association::reverse($manager,$managerBatch,'Designated manager correction');
$check('designated viewer can reverse a batch',Association::batch($managerBatch)['status']==='reversed');
$partialA=$seed(30000,10000,cash:false);$partialB=$seed(40000,20000,cash:false);$partialC=$seed(25000,30000);
$partialId=Association::confirm($manager,Association::preview([$partialA,$partialB,$partialC]),bin2hex(random_bytes(32)));
$beforePartial=$remaining();
Association::reverse($manager,$partialId,'Reverse one guest',[$partialA]);
$partialBatch=Association::batch($partialId);
$check('single guest reversal restores their ticket and lunch and keeps the batch active',$remaining()===$beforePartial+40000 && $partialBatch['status']==='sent' && (int)$partialBatch['active_guests']===2 && (int)$partialBatch['active_amount']===60000 && (int)$partialBatch['total_amount']===100000);
$check('single guest is released while the other guests remain sent',Association::preview([$partialA])['total']===40000 && $reject(fn()=>Association::preview([$partialB])));
$check('partially reversed batch remains in history with the active amounts',in_array($partialId,array_map('intval',array_column(Association::history(),'id')),true));
$check('already reversed or unrelated guests cannot be reversed again',$reject(fn()=>Association::reverse($manager,$partialId,'Duplicate',[$partialA])) && $reject(fn()=>Association::reverse($manager,$partialId,'Wrong batch',[$c])));
$partialReport=Association::report($partialBatch,Association::items($partialId));
$check('partially reversed PDF includes lunch and excludes cash from original and active totals',str_contains($partialReport[0][0],'Total: 100,000 IQD') && str_contains($partialReport[0][0],'Currently sent: 2 guests, 60,000 IQD') && str_contains($partialReport[2][15],'Reversed'));
Association::reverse($manager,$partialId,'Reverse cash guest',[$partialC]);
$check('reversing a cash guest changes the count without changing the amount or remaining revenue',(int)Association::batch($partialId)['active_guests']===1 && (int)Association::batch($partialId)['active_amount']===60000 && $remaining()===$beforePartial+40000);
$cashAudit=Db::one("SELECT details FROM audit_log WHERE action='association.guests_reversed' AND target_id=? ORDER BY id DESC LIMIT 1",[$partialId]);
$check('cash guest reversal audit records zero money restored',(int)json_decode($cashAudit['details'],true)['amount']===0);
Association::reverse($manager,$partialId,'Reverse remaining batch');
$check('whole batch reversal after a partial reversal restores only the remaining amount',$remaining()===$beforePartial+100000 && Association::batch($partialId)['status']==='reversed');
$check('fully reversed batches disappear from history without removing their saved items',!in_array($partialId,array_map('intval',array_column(Association::history(),'id')),true) && count(Association::items($partialId))===3);
$lastA=$seed(30000);$lastB=$seed(40000);$lastId=Association::confirm($manager,Association::preview([$lastA,$lastB]),bin2hex(random_bytes(32)));
Association::reverse($manager,$lastId,'Reverse first',[$lastA]);Association::reverse($manager,$lastId,'Reverse last',[$lastB]);
$check('reversing guests one by one removes the batch when the last guest is reversed',Association::batch($lastId)['status']==='reversed' && !in_array($lastId,array_map('intval',array_column(Association::history(),'id')),true));
foreach(Auth::ROLES as $role) {
    $u=['role'=>$role];
    $check($role.' has correct Association permissions',Auth::can($u,'association')===in_array($role,['owner','finance','viewer','sponsors'],true) && Association::canManage($u)===in_array($role,['owner','finance'],true));
}
// Two independent database connections submit the same selection at the same time.
$race=$seed(30000,10000,cash:false);$raceDraft=Association::preview([$race]);
$runRace=static function(array $tokens,?array $drafts=null)use($owner,$raceDraft):array {
    $children=[];
    foreach($tokens as $index=>$token) {
        $payload=App::storage('tmp/association-race-'.$token.'.json');
        file_put_contents($payload,json_encode(['user'=>$owner,'draft'=>$drafts[$index]??$raceDraft,'token'=>$token],JSON_THROW_ON_ERROR));
        $cmd=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($cmd,'-c',$ini);array_push($cmd,__FILE__,'--worker',$payload);
        $process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start race worker');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $results=[];
    foreach($children as [$process,$pipes]) {$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Race failed: '.$err.$out);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    return $results;
};
$raceToken=bin2hex(random_bytes(32));$results=$runRace([$raceToken,$raceToken]);
$check('concurrent repeat submissions create one batch',isset($results[0]['id'],$results[1]['id']) && $results[0]['id']===$results[1]['id']);
Association::reverse($owner,$results[0]['id'],'Race reset');
$results=$runRace([bin2hex(random_bytes(32)),bin2hex(random_bytes(32))]);
$check('concurrent different batches cannot send the same guest twice',count(array_filter($results,static fn($r)=>isset($r['id'])))===1 && count(array_filter($results,static fn($r)=>isset($r['rejected'])))===1);
$newGuestA=$seed(30000,cash:false);$newGuestB=$seed();
$separate=$runRace([bin2hex(random_bytes(32)),bin2hex(random_bytes(32))],[Association::preview([$newGuestA]),Association::preview([$newGuestB])]);
$numbers=array_map(static fn($r)=>(int)Association::batch($r['id'])['batch_number'],$separate);sort($numbers);
$check('simultaneous handovers receive distinct consecutive visible batch numbers',$numbers[1]===$numbers[0]+1);
$check('database receipt totals agree with active batches and exclude cash',(int)Db::value("SELECT COALESCE(SUM(total_paid),0) FROM association_batch_items WHERE active=1 AND payment_channel<>'Cash'")===(int)Association::totals()['amount'] && array_sum(array_column(Association::history(),'active_amount'))===(int)Association::totals()['amount']);
$check('confirmation and reversal have audit entries',(int)Db::value("SELECT COUNT(*) FROM audit_log WHERE action IN ('association.sent','association.reversed')")>=6);
echo "$passed Association checks passed.\n";
