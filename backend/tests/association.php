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
$seed=static function(int $ticket=30000,int $lunch=0,string $status='paid',string $currency='IQD',bool $receipt=true)use($ownerId):int {
    $now=App::now();
    $id=Db::insert('registrations',[
        'ref'=>'AT'.strtoupper(bin2hex(random_bytes(5))), 'first_name'=>'Example', 'father_name'=>'Association', 'grandfather_name'=>'Guest',
        'phone'=>'+9647501234567','email'=>'association@example.invalid','city'=>'Association Test City','gender'=>'female','specialty'=>'gp','ticket_type'=>'professional',
        'lunch_day1'=>$lunch?1:0,'pay_method'=>$status==='complimentary'?null:'cash','status'=>$status,'comp_reason'=>$status==='complimentary'?'Association test':null,
        'view_nonce'=>bin2hex(random_bytes(16)),'created_by'=>$ownerId,'created_at'=>$now,'paid_at'=>$now,'updated_at'=>$now,
    ]);
    if($status!=='complimentary') {
        $raw=json_encode(['channel'=>'direct','order'=>['currency'=>$currency,'totalAmount'=>$ticket+$lunch,'items'=>[['quantity'=>1,'unitPrice'=>$ticket,'addOns'=>$lunch?[['addOnId'=>'association_lunch1','quantity'=>1,'unitPrice'=>$lunch]]:[]]]]]);
        $raw=PaymentBreakdown::archive($raw,['ticket_type'=>'professional','lunch_day1'=>$lunch?1:0,'lunch_day2'=>0],[],['lunchDay1'=>'association_lunch1']);
        Db::insert('payments',['registration_id'=>$id,'gateway'=>'fake','method'=>'cash','amount_expected'=>$ticket+$lunch,'currency'=>$currency,'amount_confirmed'=>$ticket+$lunch,'status'=>'paid','raw_create'=>$receipt?$raw:'{}','raw_status'=>$receipt?$raw:'{}','created_at'=>$now,'updated_at'=>$now,'confirmed_at'=>$now]);
    }
    return $id;
};
$remaining=static function():int {foreach(Association::revenue() as $r)if($r['currency']==='IQD')return (int)$r['total'];return 0;};
$a=$seed(30000);$b=$seed(40000,67200);$c=$seed(25000);$free=$seed(0,0,'complimentary');
$gross=$remaining();$draft=Association::preview([$a,$b]);
$check('selection and review do not deduct money',$draft['total']===70000 && $remaining()===$gross && (int)Association::totals()['guests']===0);
$check('lunch is retained in the receipt but excluded from the handover',$draft['items'][1]['total_paid']===107200 && $draft['items'][1]['ticket_amount']===40000);
$check('viewer cannot confirm a handover',$reject(fn()=>Association::confirm($viewer,$draft,bin2hex(random_bytes(32)))));
$token=bin2hex(random_bytes(32));$id=Association::confirm($finance,$draft+['note'=>'Receipt EXAMPLE-001'],$token);
$check('confirmed batch deducts the exact ticket amounts once',$remaining()===$gross-70000 && (int)Association::totals()['guests']===2 && count(Association::items($id))===2);
$check('repeated submission returns the existing batch',Association::confirm($finance,$draft,$token)===$id && $remaining()===$gross-70000);
$check('already sent names cannot enter another batch',$reject(fn()=>Association::preview([$a])));
$count=(int)Db::value('SELECT COUNT(*) FROM association_batches');
$check('mixed eligible and already sent selection is rejected entirely',$reject(fn()=>Association::preview([$b,$c])) && (int)Db::value('SELECT COUNT(*) FROM association_batches')===$count);
$stale=Association::preview([$c]);Db::update('registrations',['first_name'=>'Changed'],'id=?',[$c]);
$check('changed registration requires a fresh review',$reject(fn()=>Association::confirm($owner,$stale,bin2hex(random_bytes(32)))) && (int)Db::value('SELECT COUNT(*) FROM association_batches')===$count);
$stale=Association::preview([$c]);$payment=Db::one('SELECT * FROM payments WHERE registration_id=?',[$c]);
Db::update('payments',['raw_status'=>'{}','raw_create'=>'{}'],'id=?',[$payment['id']]);
$check('changed or missing payment breakdown prevents confirmation',$reject(fn()=>Association::confirm($owner,$stale,bin2hex(random_bytes(32)))));
Db::update('payments',['raw_status'=>$payment['raw_status'],'raw_create'=>$payment['raw_create']],'id=?',[$payment['id']]);
Db::update('registrations',['first_name'=>'Edited after handover'],'id=?',[$a]);
$snapshot=Association::items($id);
$check('saved batch keeps the original name and money',$snapshot[0]['full_name']==='Example Association Guest' && (int)$snapshot[0]['ticket_amount']===30000);
$sheet=Association::report(Association::batch($id),$snapshot);$pdf=PdfReport::make('Association '.Association::label($id),$sheet);
$check('batch PDF uses saved items and includes guest count and total',str_starts_with($pdf,'%PDF-') && strlen($pdf)>5000 && str_contains($sheet[0][0],'70,000 IQD') && str_contains($sheet[0][0],'2 guests') && count($sheet)===4);
file_put_contents(App::storage('tmp/association-example.pdf'),$pdf);
$check('viewer cannot reverse a handover',$reject(fn()=>Association::reverse($viewer,$id,'Example correction')));
$check('reversal requires a reason',$reject(fn()=>Association::reverse($owner,$id,'')));
Association::reverse($owner,$id,'Example correction');
$check('reversal restores money and guests without deleting history',$remaining()===$gross && (int)Association::totals()['guests']===0 && Association::batch($id)['status']==='reversed' && count(Association::items($id))===2 && Association::preview([$a])['total']===30000);
$check('batch cannot be reversed twice',$reject(fn()=>Association::reverse($owner,$id,'Again')));
$next=Association::confirm($owner,Association::preview([$a,$free]),bin2hex(random_bytes(32)));
$check('reversed guests can be sent again and free tickets contribute zero',(int)Association::batch($next)['guest_count']===2 && (int)Association::batch($next)['total_amount']===30000 && $remaining()===$gross-30000);
$cancelled=$seed(30000,0,'cancelled');$unknown=$seed(30000,0,'paid','IQD',false);$usd=$seed(30,0,'paid','USD');
$check('cancelled guests are ineligible',$reject(fn()=>Association::preview([$cancelled])));
$check('missing receipt amounts are never guessed',$reject(fn()=>Association::preview([$unknown])));
$check('different currencies are not silently converted',$reject(fn()=>Association::preview([$usd])));
$duplicate=$seed();$p=Db::one('SELECT * FROM payments WHERE registration_id=?',[$duplicate]);unset($p['id']);Db::insert('payments',$p);
$check('multiple confirmed payments require review',$reject(fn()=>Association::preview([$duplicate])));
$check('selection parser deduplicates and validates IDs',Association::ids('['.$a.','.$a.']')===[$a] && $reject(fn()=>Association::ids('["1"]')) && $reject(fn()=>Association::ids('[]')));
foreach(Auth::ROLES as $role) {
    $u=['role'=>$role];
    $check($role.' has correct Association permissions',Auth::can($u,'association')===in_array($role,['owner','finance','viewer','sponsors'],true) && Association::canManage($u)===in_array($role,['owner','finance'],true));
}
// Two independent database connections submit the same selection at the same time.
$race=$seed();$raceDraft=Association::preview([$race]);
$runRace=static function(array $tokens)use($owner,$raceDraft):array {
    $children=[];
    foreach($tokens as $token) {
        $payload=App::storage('tmp/association-race-'.$token.'.json');
        file_put_contents($payload,json_encode(['user'=>$owner,'draft'=>$raceDraft,'token'=>$token],JSON_THROW_ON_ERROR));
        $cmd=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($cmd,'-c',$ini);array_push($cmd,__FILE__,'--worker',$payload);
        $process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start race worker');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $results=[];
    foreach($children as [$process,$pipes]) {$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Race failed: '.$err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    return $results;
};
$raceToken=bin2hex(random_bytes(32));$results=$runRace([$raceToken,$raceToken]);
$check('concurrent repeat submissions create one batch',isset($results[0]['id'],$results[1]['id']) && $results[0]['id']===$results[1]['id']);
Association::reverse($owner,$results[0]['id'],'Race reset');
$results=$runRace([bin2hex(random_bytes(32)),bin2hex(random_bytes(32))]);
$check('concurrent different batches cannot send the same guest twice',count(array_filter($results,static fn($r)=>isset($r['id'])))===1 && count(array_filter($results,static fn($r)=>isset($r['rejected'])))===1);
$check('database ledger totals agree with active batch headers',(int)Db::value('SELECT COALESCE(SUM(ticket_amount),0) FROM association_batch_items WHERE active=1')===(int)Association::totals()['amount']);
$check('confirmation and reversal have audit entries',(int)Db::value("SELECT COUNT(*) FROM audit_log WHERE action IN ('association.sent','association.reversed')")>=6);
echo "$passed Association checks passed.\n";
