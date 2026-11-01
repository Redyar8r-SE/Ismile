<?php
// Read-only live checks: no test guests, handovers, emails or payment changes.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../bootstrap.php';
use Ismile\Association;
use Ismile\Db;
use Ismile\Auth;

$active=(int)Db::value('SELECT COALESCE(SUM(ticket_amount),0) FROM association_batch_items WHERE active=1');
$totals=Association::totals();
if($active!==(int)$totals['amount'])throw new RuntimeException('Association item amounts do not match batch totals.');
if((int)Db::value('SELECT COUNT(*) FROM association_batch_items WHERE active=1')!==(int)$totals['guests'])throw new RuntimeException('Association guest counts do not match batch totals.');
if((int)Db::value("SELECT COUNT(*) FROM association_batch_items i JOIN association_batches b ON b.id=i.batch_id WHERE (i.active=1 AND b.status<>'sent') OR (i.active IS NULL AND b.status<>'reversed')")!==0)throw new RuntimeException('Association active flags disagree with batch statuses.');
foreach(Auth::ROLES as $role)if(Association::canManage(['role'=>$role])!==in_array($role,['owner','finance'],true))throw new RuntimeException('Association permissions failed.');
$history=Association::history();
foreach($history as $batch)if($batch['status']!=='sent'||(int)$batch['active_guests']<1)throw new RuntimeException('Reversed batch remains in visible history.');
echo 'Association visible batches: '.count($history).'; fully reversed batches excluded: '.(int)Db::value("SELECT COUNT(*) FROM association_batches WHERE status='reversed'")."\n";
$people=Association::attach(Db::all(Association::selectSql()." WHERE r.status IN ('paid','complimentary') ORDER BY r.id"));
$eligible=0;$missing=0;$other=0;$eligibleAmount=0;
foreach($people as $r) {
    if($r['association_reason']===''){$eligible++;$eligibleAmount+=(int)$r['receipt_ticket_amount'];}
    elseif(!$r['association_batch_id']){if($r['receipt_ticket_amount']===null)$missing++;else $other++;}
}
echo json_encode(['association_verified'=>true,'registered_guests'=>count($people),'guests_sent'=>(int)$totals['guests'],'amount_sent_iqd'=>(int)$totals['amount'],'confirmed_batches'=>(int)$totals['batches'],'eligible_not_sent'=>$eligible,'eligible_ticket_amount_iqd'=>$eligibleAmount,'missing_breakdown'=>$missing,'other_payment_review'=>$other,'remaining_revenue'=>Association::revenue()],JSON_THROW_ON_ERROR)."\n";
