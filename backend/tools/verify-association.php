<?php
// Read-only live checks: no test guests, handovers, emails or payment changes.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../bootstrap.php';
use Ismile\Association;
use Ismile\Db;
use Ismile\Auth;

$active=(int)Db::value("SELECT COALESCE(SUM(total_paid),0) FROM association_batch_items WHERE active=1 AND payment_channel<>'Cash'");
$totals=Association::totals();
if($active!==(int)$totals['amount'])throw new RuntimeException('Association item amounts do not match batch totals.');
if((int)Db::value('SELECT COUNT(*) FROM association_batch_items WHERE active=1')!==(int)$totals['guests'])throw new RuntimeException('Association guest counts do not match batch totals.');
if((int)Db::value("SELECT COUNT(*) FROM association_batch_items i JOIN association_batches b ON b.id=i.batch_id WHERE i.active=1 AND b.status<>'sent'")!==0)throw new RuntimeException('Association active flags disagree with batch statuses.');
if((int)Db::value('SELECT COUNT(*) FROM association_batch_items WHERE report_snapshot IS NULL')!==0)throw new RuntimeException('Association report snapshots are missing.');
$sample=Db::one('SELECT id FROM association_batches ORDER BY id DESC LIMIT 1');
if($sample){$report=Association::report(Association::batch((int)$sample['id']),Association::items((int)$sample['id']));if(($report[1]??[])!==Association::REPORT_COLUMNS||count($report[1])!==16)throw new RuntimeException('Association PDF columns do not match.');}
echo "Association PDF columns verified: 16.\n";
foreach(Auth::ROLES as $role)if(Association::canManage(['role'=>$role])!==in_array($role,['owner','finance'],true))throw new RuntimeException('Association permissions failed.');
$history=Association::history();
foreach($history as $batch) {
    $summary=Association::report($batch,Association::items((int)$batch['id']))[0][0];
    $expected=Db::one("SELECT COALESCE(SUM(total_paid),0) AS total,COALESCE(SUM(CASE WHEN active=1 THEN total_paid ELSE 0 END),0) AS active_total FROM association_batch_items WHERE batch_id=? AND payment_channel<>'Cash'",[$batch['id']]);
    if(!str_contains($summary,'Total: '.number_format((int)$expected['total']).' IQD') || !str_contains($summary,'Currently sent: '.$batch['active_guests'].' guests, '.number_format((int)$expected['active_total']).' IQD') || !str_contains($summary,'Tickets and lunch included; cash excluded.')) throw new RuntimeException('Association PDF totals must include tickets and lunch and exclude cash.');
    $detail=Association::batch((int)$batch['id']);
    if((int)$batch['active_amount']!==(int)$expected['active_total'] || (int)$detail['active_amount']!==(int)$expected['active_total'] || (int)$detail['receipt_amount']!==(int)$expected['total']) throw new RuntimeException('Association batch amounts do not match the PDF receipt totals.');
    echo json_encode(['association_batch_amount_verified'=>true,'batch'=>Association::label((int)$batch['id']),'guests'=>(int)$batch['active_guests'],'amount_sent_iqd'=>(int)$batch['active_amount']],JSON_THROW_ON_ERROR)."\n";
}
echo "Association PDF totals verified: tickets and lunch included; cash excluded.\n";
foreach($history as $batch)if($batch['status']!=='sent'||(int)$batch['active_guests']<1)throw new RuntimeException('Reversed batch remains in visible history.');
foreach($history as $batch)if((int)($batch['batch_number']??0)<1)throw new RuntimeException('Active batch has no display number.');
$nextNumber=(int)Db::value("SELECT v FROM settings WHERE k='association_next_batch_number'");
if($nextNumber<= (int)Db::value('SELECT COALESCE(MAX(batch_number),0) FROM association_batches'))throw new RuntimeException('Batch number sequence is invalid.');
$first=Db::one("SELECT id FROM association_batches WHERE status='sent' ORDER BY batch_number LIMIT 1");
echo 'Association numbering verified; first active: '.($first?Association::label((int)$first['id']):'none').'; next: '.str_pad((string)$nextNumber,3,'0',STR_PAD_LEFT)."\n";
echo 'Association visible batches: '.count($history).'; fully reversed batches excluded: '.(int)Db::value("SELECT COUNT(*) FROM association_batches WHERE status='reversed'")."\n";
$people=Association::attach(Db::all(Association::selectSql()." WHERE r.status IN ('paid','complimentary') ORDER BY r.id"));
$eligible=0;$missing=0;$other=0;$eligibleAmount=0;
foreach($people as $r) {
    if($r['association_reason']===''){$eligible++;$eligibleAmount+=(int)$r['association_amount'];}
    elseif(!$r['association_batch_id']){if($r['receipt_ticket_amount']===null)$missing++;else $other++;}
}
echo json_encode(['association_verified'=>true,'registered_guests'=>count($people),'guests_sent'=>(int)$totals['guests'],'amount_sent_iqd'=>(int)$totals['amount'],'confirmed_batches'=>(int)$totals['batches'],'eligible_not_sent'=>$eligible,'eligible_amount_iqd'=>$eligibleAmount,'missing_breakdown'=>$missing,'other_payment_review'=>$other,'remaining_revenue'=>Association::revenue()],JSON_THROW_ON_ERROR)."\n";
