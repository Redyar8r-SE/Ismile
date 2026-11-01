<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Association;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\UserError;

$user = Page::guard('association');
$manage = Association::canManage($user);
Auth::startSession();
// Keep reviews in the signed-in session, separate for each tab and staff member.
foreach (($_SESSION['association_reviews'] ?? []) as $key => $draft) {
    if ((int) ($draft['expires'] ?? 0) < time()) unset($_SESSION['association_reviews'][$key]);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = 'association.php';
    try {
        Auth::checkCsrf();
        if (!$manage) throw new UserError('Your account cannot save Association handovers.');
        $action = Page::post('do');
        if ($action === 'review') {
            $json = Page::post('selection_json', 24001);
            if ($json === '') {
                $ids = [];
                foreach ($_POST as $key => $value) if (preg_match('/^chosen_(\d+)$/D', $key, $match) && $value === '1') $ids[] = (int) $match[1];
                $json = json_encode($ids, JSON_THROW_ON_ERROR);
            }
            $draft = Association::preview(Association::ids($json));
            $note = trim(Page::post('note', 1001));
            if (mb_strlen($note) > 1000) throw new UserError('Notes must be 1,000 characters or fewer.');
            $token = bin2hex(random_bytes(32));
            if (count($_SESSION['association_reviews'] ?? []) >= 10) array_shift($_SESSION['association_reviews']);
            $_SESSION['association_reviews'][$token] = $draft + ['note'=>$note, 'expires'=>time()+1800, 'user_id'=>(int)$user['id']];
            $back .= '?review=' . $token;
        } elseif ($action === 'confirm') {
            $token = Page::post('review_token');
            $draft = $_SESSION['association_reviews'][$token] ?? null;
            if (!$draft || (int)$draft['user_id'] !== (int)$user['id']) throw new UserError('This review expired. Select your guests and review again.');
            $id = Association::confirm($user, $draft, $token);
            $back .= '?batch=' . $id;
            Page::flash('ok', Association::label($id) . ' saved as sent to Association.');
        } elseif ($action === 'reverse') {
            $id = (int)Page::post('batch_id');
            $back .= '?batch=' . $id;
            $scope = Page::post('reverse_scope');
            $ids = null;
            if ($scope === 'selected') {
                $json = Page::post('reverse_selection_json', 24001);
                if ($json !== '') $ids = Association::ids($json);
                else {
                    $ids = [];
                    foreach ($_POST as $key=>$value) if (preg_match('/^reverse_guest_(\d+)$/D', $key, $match) && $value === '1') $ids[] = (int)$match[1];
                }
            } elseif ($scope !== 'all') throw new UserError('Choose selected guests or the whole batch.');
            Association::reverse($user, $id, Page::post('reason', 1001), $ids);
            if (Association::batch($id)['status'] === 'reversed') {
                $back = 'association.php';
                Page::flash('ok', 'Batch reversed and removed from batch history. Its amount is restored and its guests can be selected again.');
            } else Page::flash('ok', 'Selected guests reversed. Their amount is restored and they can be selected again.');
        } else throw new UserError('Unknown Association action.');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (Throwable $error) {
        \Ismile\App::log('error', 'Association action failed', ['error'=>$error->getMessage()]);
        Page::flash('error', 'The handover could not be saved. Refresh and review the selection again.');
    }
    Page::redirect($back);
}

$e = [Page::class, 'e'];
$reviewToken = Page::query('review');
$review = $manage ? ($_SESSION['association_reviews'][$reviewToken] ?? null) : null;
$batchId = max(0, (int)Page::query('batch'));
$batch = $batchId ? Association::batch($batchId) : null;
if (($batchId && !$batch) || ($reviewToken !== '' && !$review)) {
    http_response_code(404);
    Page::top('Association', 'association');
    echo '<section class="card"><h2>Batch or review unavailable</h2><p>The review may have expired.</p><a class="btn" href="association.php">Back to Association</a></section>';
    Page::bottom(); exit;
}
Page::top('Association', 'association', '<a class="btn ghost" href="association.php#batch-history">Batch history</a>');
$totals = Association::totals();
$revenue = Association::revenue();
$previewAmount = (int)($review['total'] ?? 0);
$previewRevenue = $revenue;
$hasIqd = false;
foreach ($previewRevenue as &$value) if ($value['currency']==='IQD') { $value['total']=(int)$value['total']-$previewAmount; $hasIqd=true; }
unset($value);
if (!$hasIqd && $previewAmount) $previewRevenue[]=['currency'=>'IQD','total'=>-$previewAmount];
$remaining = $previewRevenue ? implode(' + ', array_map(static fn($r)=>Page::money((int)$r['total'],$r['currency']), $previewRevenue)) : '0 IQD';
echo '<div data-live-region="association-totals"><div data-association-balance data-revenue-values="'.$e(json_encode($revenue)).'">' . Page::stats([
    ['Ticket revenue remaining', $remaining, $previewAmount ? 'Preview after confirming this batch. Confirm to save.' : 'Confirmed payments less Association handovers; cancelled excluded', 'payments','blue'],
    ['Sent to Association', Page::money((int)$totals['amount'],'IQD'), 'Confirmed handovers; lunch excluded', 'association','teal'],
    ['Guests sent', number_format((int)$totals['guests']), number_format((int)$totals['batches']).' confirmed batches', 'users','violet'],
    ['Not sent yet', number_format((int)$totals['waiting']), 'Registered guests without an active handover', 'registrations','gold'],
]) . '</div></div>';
?>
<div data-association-workspace data-user-id="<?= (int)$user['id'] ?>" data-review-total="<?= $previewAmount ?>" data-review-guests="<?= count($review['items'] ?? []) ?>">
<?php if ($review || $batch):
    $items = $batch ? Association::items($batchId) : $review['items'];
    $amount = $batch ? (int)$batch['active_amount'] : $review['total'];
    $reversible = $batch && $manage && $batch['status']==='sent';
?>
<section class="card directory-panel">
  <div class="toolbar list-head"><div><h2><?= $batch ? $e(Association::label($batchId)) : 'Review your handover' ?></h2>
  <p class="muted"><?= $batch ? (int)$batch['active_guests'] : count($items) ?> guests<?= $batch?' currently sent':'' ?> &middot; <b><?= $e(Page::money($amount,'IQD')) ?></b> &middot; Ticket amounts only; lunch excluded.</p></div>
  <div class="panel-actions"><a class="btn ghost" href="association.php">Back to guests</a>
  <?php if ($batch && (Auth::can($user,'export') || $manage)): ?><a class="btn green" href="export.php?what=association&amp;id=<?= $batchId ?>&amp;format=pdf">Download batch PDF</a><?php endif; ?></div></div>
  <?php if ($batch): ?><p><b><?= $e(strtoupper($batch['status'])) ?></b> &middot; <?= $e($batch['sent_at']) ?> (Iraq) &middot; Recorded by <?= $e($batch['created_by_name']) ?></p><?php endif; ?>
  <?php if ($batch && (int)$batch['active_guests'] !== (int)$batch['guest_count']): ?><p class="muted small">Original batch: <?= (int)$batch['guest_count'] ?> guests &middot; <?= $e(Page::money((int)$batch['total_amount'],'IQD')) ?>. Reversed guests are marked below.</p><?php endif; ?>
  <?php $note = $batch ? $batch['note'] : $review['note']; if ($note !== ''): ?><p><b>Note:</b> <?= $e($note) ?></p><?php endif; ?>
  <?php if ($batch && $batch['status']==='reversed'): ?><p class="muted"><b>Reversed <?= $e($batch['reversed_at']) ?>:</b> <?= $e($batch['reversal_reason']) ?></p><?php endif; ?>
  <?php if ($reversible): ?><form method="post" data-association-reversal-form><?= Page::csrfField() ?><input type="hidden" name="do" value="reverse"><input type="hidden" name="batch_id" value="<?= $batchId ?>"><input type="hidden" name="reverse_selection_json" value=""><?php endif; ?>
  <div class="table-wrap"><table><thead><tr><?php if ($reversible): ?><th><input type="checkbox" data-association-reverse-all aria-label="Select all active guests to reverse"></th><?php endif; ?><th>#</th><th>Full name</th><th>Phone</th><th>Ticket</th><th>Lunch</th><th>Total paid</th><th>Association amount</th><th>Reference</th><?php if($batch): ?><th>Association status</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($items as $i=>$r): ?><tr><?php if ($reversible): ?><td><?php if(!empty($r['active'])): ?><input type="checkbox" name="reverse_guest_<?= (int)$r['registration_id'] ?>" value="1" data-association-reverse-guest aria-label="<?= $e('Reverse '.$r['full_name']) ?>"><?php endif; ?></td><?php endif; ?><td><?= $i+1 ?></td><td><b><?= $e($r['full_name']) ?></b></td><td dir="ltr"><?= $e($r['phone']) ?></td><td><?= $e($r['ticket_type']) ?></td><td><?= $e($r['lunch']) ?></td><td><?= $e(Page::money((int)$r['total_paid'],'IQD')) ?></td><td><b><?= $e(Page::money($batch && empty($r['active'])?0:(int)$r['ticket_amount'],'IQD')) ?></b></td><td><code><?= $e($r['ref']) ?></code></td><?php if($batch): ?><td><?= !empty($r['active'])?'Sent':'Reversed' ?><?php if(empty($r['active'])): ?><br><small class="muted"><?= $e($r['reversed_at']) ?><?= !empty($r['reversal_reason'])?'<br>'.$e($r['reversal_reason']):'' ?></small><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($review): ?>
  <p>Confirm after handing over the money. This records the handover and deducts <?= $e(Page::money($amount,'IQD')) ?> from the remaining revenue.</p>
  <form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="do" value="confirm"><input type="hidden" name="review_token" value="<?= $e($reviewToken) ?>"><button class="btn green">Confirm sent to Association</button><a class="btn ghost" href="association.php">Back to selection</a></form>
  <?php elseif ($reversible): ?>
  <span data-association-consumed="<?= $e(json_encode(array_map('intval',array_column(array_filter($items,static fn($r)=>!empty($r['active'])),'registration_id')))) ?>" hidden></span>
  <details class="association-reversal"><summary>Reverse guests or the whole batch</summary><p>Select one or several guests above to reverse their amounts, or reverse the whole batch. Fully reversed batches are removed from batch history.</p><label>Reason for reversal<textarea name="reason" maxlength="1000" required></textarea></label><div class="form-actions"><button class="btn ghost" name="reverse_scope" value="selected">Reverse selected guests</button><button class="btn ghost" name="reverse_scope" value="all">Reverse whole batch</button></div></details></form>
  <?php endif; ?>
</section>
<?php else:
    $filters = ['q'=>Page::query('q'),'type'=>Page::query('type'),'sent'=>Page::query('sent'),'status'=>Page::query('status'),'lunch'=>Page::query('lunch'),'sale'=>Page::query('sale')];
    $where = []; $params = [];
    if ($filters['status']==='cancelled') $where[]="r.status='cancelled'";
    else { $where[]="r.status IN ('paid','complimentary')"; if (in_array($filters['status'],['paid','complimentary'],true)) { $where[]='r.status=?'; $params[]=$filters['status']; } }
    if ($filters['q']!=='') { $where[]="(CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ? OR r.phone LIKE ? OR r.ref LIKE ? OR r.email LIKE ?)"; for($i=0;$i<4;$i++) $params[]='%'.$filters['q'].'%'; }
    if (isset(Registrations::TICKET_NAMES[$filters['type']])) { $where[]='r.ticket_type=?'; $params[]=$filters['type']; }
    if ($filters['sent']==='yes') $where[]='ai.id IS NOT NULL';
    if ($filters['sent']==='no') $where[]='ai.id IS NULL';
    if ($filters['lunch']==='none') $where[]='r.lunch_day1=0 AND r.lunch_day2=0';
    if ($filters['lunch']==='day1') $where[]='r.lunch_day1=1';
    if ($filters['lunch']==='day2') $where[]='r.lunch_day2=1';
    if ($filters['sale']==='cash') $where[]="r.pay_method='cash'";
    if ($filters['sale']==='website') $where[]="r.status<>'complimentary' AND (r.pay_method IS NULL OR r.pay_method<>'cash')";
    $sql = Association::selectSql().' WHERE '.implode(' AND ',$where);
    $count = (int)Db::value('SELECT COUNT(*) FROM ('.$sql.') matched', $params);
    $pages = max(1,(int)ceil($count/100)); $page = min($pages,max(1,(int)Page::query('page')));
    $people = Association::attach(Db::all($sql.' ORDER BY r.first_name,r.father_name,r.id LIMIT 100 OFFSET '.(($page-1)*100),$params));
    $select = static function(string $key,string $label,array $options) use($filters,$e):void { echo '<label class="filter-field"><span>'.$e($label).'</span><select name="'.$key.'">'; foreach($options as $value=>$text) echo '<option value="'.$e($value).'"'.($filters[$key]===(string)$value?' selected':'').'>'.$e($text).'</option>'; echo '</select></label>'; };
?>
<form class="filters card filter-panel" method="get" data-instant-search data-search-regions="association-directory">
<?= Page::panelHeading('Choose guests for Association','Find guests, select their names and review the handover total.','search') ?>
<label class="filter-field filter-search"><span>Name, phone, email or reference</span><input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="Search guests"></label>
<?php $select('type','Ticket type',[''=>'Any ticket']+Registrations::TICKET_NAMES); $select('sale','Payment method',[''=>'All payments','cash'=>'Cash','website'=>'Website']); $select('sent','Association status',[''=>'Everyone','no'=>'Not sent yet','yes'=>'Already sent']); $select('status','Registration status',[''=>'Registered (paid + free)','paid'=>'Paid','complimentary'=>'Free ticket','cancelled'=>'Cancelled']); $select('lunch','Lunch',[''=>'Any lunch','none'=>'No lunch','day1'=>'Lunch day 1','day2'=>'Lunch day 2']); ?>
<div class="form-actions filter-actions"><a class="btn ghost" href="association.php">Clear filters</a><button class="btn">Search</button></div>
</form>
<form method="post" data-association-selection>
<?= Page::csrfField() ?><input type="hidden" name="do" value="review"><input type="hidden" name="selection_json" value="">
<?php if ($manage): ?><section class="card association-selection-bar"><div><b data-association-summary role="status" aria-live="polite">No guests selected</b><p class="muted small">Selection stays while you search or change pages. Ticket amounts only; lunch excluded.</p></div><div class="panel-actions"><button type="button" class="btn ghost" data-association-clear>Clear selection</button><button class="btn green" data-association-review>Review selected guests</button></div><label class="association-note">Handover note (optional)<input name="note" maxlength="1000" placeholder="Receipt number, recipient or other handover details"></label></section><?php endif; ?>
<div data-live-region="association-directory"><section class="card directory-panel"><div class="toolbar list-head"><div><h2>Guest directory <span class="result-count"><?= number_format($count) ?></span></h2><p class="muted small">Already sent guests link to their saved batch. Amounts come from confirmed receipts.</p></div></div>
<div class="table-wrap"><table><thead><tr><?php if($manage): ?><th><input type="checkbox" data-association-all aria-label="Select eligible guests on this page"></th><?php endif; ?><th>Full name</th><th>Phone</th><th>Ticket</th><th>Lunch</th><th>Total paid</th><th>Association amount</th><th>Association status</th><th>Reference</th></tr></thead><tbody>
<?php foreach($people as $r): $eligible=$r['association_reason']===''; ?>
<tr data-association-guest><?php if($manage): ?><td><input type="checkbox" name="chosen_<?= (int)$r['id'] ?>" value="1" data-association-choice data-id="<?= (int)$r['id'] ?>" data-amount="<?= (int)($r['receipt_ticket_amount']??0) ?>" aria-label="<?= $e('Select '.Registrations::fullName($r)) ?>"<?= $eligible?'':' disabled' ?>></td><?php endif; ?>
<td><a href="registration.php?id=<?= (int)$r['id'] ?>"><b><?= $e(Registrations::fullName($r)) ?></b></a><br><?= Page::paidBadge($r['status']) ?></td><td dir="ltr"><?= $e($r['phone']) ?></td><td><?= $e(Registrations::ticketName($r['ticket_type'])) ?></td><td><?= $e(\Ismile\Admin\Lists::exportValue('lunch',$r)) ?></td><td><?= $e($r['finance_total']) ?></td><td><?= $r['receipt_ticket_amount']===null?'Not recorded':$e(Page::money((int)$r['receipt_ticket_amount'],$r['receipt_currency'])) ?></td>
<td><?php if($r['association_batch_id']): ?><a class="pill teal" href="association.php?batch=<?= (int)$r['association_batch_id'] ?>">Sent &middot; <?= $e(Association::label((int)$r['association_batch_id'])) ?></a><?php elseif(!$eligible): ?><span class="muted small"><?= $e($r['association_reason']) ?></span><?php else: ?><span class="pill gold">Not sent</span><?php endif; ?></td><td><code><?= $e($r['ref']) ?></code></td></tr>
<?php endforeach; ?>
<?php if(!$people): ?><tr><td colspan="<?= $manage?9:8 ?>"><?= Page::emptyState('No guests match these filters','Try another search or clear your filters.','registrations') ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if($pages>1): ?><div class="toolbar"><?php if($page>1): ?><a class="btn ghost" href="?<?= $e(http_build_query($filters+['page'=>$page-1])) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if($page<$pages): ?><a class="btn ghost" href="?<?= $e(http_build_query($filters+['page'=>$page+1])) ?>">Next</a><?php endif; ?></div><?php endif; ?>
</div></form>
<?php endif; ?>
</div>
<section class="card directory-panel" id="batch-history" data-live-region="association-history"><div class="toolbar list-head"><div><h2>Batch history</h2><p class="muted small">Active handovers. Fully reversed batches are removed from this list.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Batch</th><th>Sent (Iraq)</th><th>Guests</th><th>Amount sent</th><th>Recorded by</th><th>Status</th><th>Note</th><th>PDF</th></tr></thead><tbody>
<?php $history=Association::history(); foreach($history as $h): ?><tr><td><a href="association.php?batch=<?= (int)$h['id'] ?>"><b><?= $e(Association::label((int)$h['id'])) ?></b></a></td><td><?= $e($h['sent_at']) ?></td><td><?= (int)$h['active_guests'] ?></td><td><?= $e(Page::money((int)$h['active_amount'],'IQD')) ?></td><td><?= $e($h['created_by_name']) ?></td><td><?= (int)$h['active_guests'] < (int)$h['guest_count']?'Partially reversed':'Sent' ?></td><td><?= $e($h['note']) ?></td><td><?php if(Auth::can($user,'export') || $manage): ?><a class="btn small ghost" href="export.php?what=association&amp;id=<?= (int)$h['id'] ?>&amp;format=pdf">PDF</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$history): ?><tr><td colspan="8"><?= Page::emptyState('No handovers recorded yet','Select guests and confirm your first batch after handing over the money.','association') ?></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php Page::bottom();
