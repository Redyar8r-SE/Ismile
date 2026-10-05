<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\CommunicationQuery as Queue;
use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;

$user = Page::guard('communications');
$in = [];
foreach (['status','kind','lang','q'] as $key) $in[$key] = Page::query($key);
[$where,$params] = Queue::where($user,$in);
[$scope,$scopeParams] = Queue::where($user);
$counts = array_column(Db::all('SELECT (' . Queue::STATE_SQL . ") AS state, COUNT(*) AS n FROM emails e WHERE $scope GROUP BY state",$scopeParams),'n','state');
$total = (int) Db::value('SELECT COUNT(*) FROM ' . Queue::from() . " WHERE $where",$params);
$perPage = 30;
$pageCount = max(1,(int)ceil($total / $perPage));
$pageNo = min($pageCount,max(1,(int)($_GET['page'] ?? 1)));
$offset = ($pageNo - 1) * $perPage;
$rows = Db::all('SELECT e.*, (' . Queue::STATE_SQL . ') AS delivery_state, COALESCE(r.ref,c.ref,s.ref) AS reference,
    COALESCE(r.first_name,c.first_name) AS first_name, COALESCE(r.father_name,c.father_name) AS father_name,
    COALESCE(r.grandfather_name,c.grandfather_name) AS grandfather_name, s.company
    FROM ' . Queue::from() . " WHERE $where ORDER BY e.id DESC LIMIT $perPage OFFSET $offset",$params);
$e = [Page::class,'e'];
$url = static fn (array $changes): string => 'communications.php?' . http_build_query(array_merge($in,$changes));
$localMail = App::config('mail.driver') === 'log';
Page::top('Communication center','communications','<a class="btn ghost" href="' . $e($url(['page'=>$pageNo])) . '">' . Page::navIcon('clock') . '<span>Refresh status</span></a>');
?>
<?= Page::stats([
    ['Sent',number_format($counts['sent'] ?? 0),$localMail ? 'Includes emails saved on this computer' : 'Accepted by the email service','mail','green'],
    ['Pending',number_format($counts['pending'] ?? 0),'Waiting to send or retry','clock','gold'],
    ['Failed',number_format($counts['failed'] ?? 0),'Emails that need your attention','close','red'],
    ['Skipped',number_format($counts['skipped'] ?? 0),'No longer needed; no email sent','check','blue'],
]) ?>
<div class="communication-mode icon-label"><?= Page::navIcon('mail') ?><div><b><?= $localMail ? 'Emails are saved locally' : (Settings::bool('email_test_mode') ? 'Email test mode is enabled' : 'Event email delivery') ?></b><p><?= $localMail ? 'This preview writes email files on this computer. It does not send them to guest inboxes.' : (Settings::bool('email_test_mode') ? 'Guest emails are redirected to your configured test address.' : 'Sent means the email service accepted the message. Inbox delivery is not tracked here.') ?></p></div></div>
<form method="get" class="card filters filter-panel">
  <?= Page::panelHeading('Find a message','Search by recipient, guest name, company or reference.','search') ?>
  <label class="filter-field filter-search"><span>Recipient, name or reference</span><input type="search" name="q" value="<?= $e($in['q']) ?>" placeholder="Search your event emails…"></label>
  <label class="filter-field"><span>Status</span><select name="status"><option value="">All statuses</option><?php foreach (Queue::STATES as $key=>$label): ?><option value="<?= $key ?>"<?= $in['status']===$key ? ' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label class="filter-field"><span>Message type</span><select name="kind"><option value="">All types</option><?php foreach (Queue::KINDS as $key=>$label): if ($key==='alert' && $user['role']!=='owner') continue; ?><option value="<?= $key ?>"<?= $in['kind']===$key ? ' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label class="filter-field"><span>Language</span><select name="lang"><option value="">All languages</option><?php foreach (['en'=>'English','ar'=>'Arabic','ku'=>'Kurdish'] as $key=>$label): ?><option value="<?= $key ?>"<?= $in['lang']===$key ? ' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <div class="form-actions filter-actions"><a class="btn ghost" href="communications.php">Clear filters</a><button class="btn">Search messages</button></div>
</form>
<section class="card message-directory"><div class="panel-top"><?= Page::panelHeading('Email activity','Your event emails and their latest queue status.','communications') ?><span class="pill grey"><?= number_format($total) ?> messages</span></div>
<?php if (!$rows): ?><?= Page::emptyState($in['q']!=='' || $in['status']!=='' || $in['kind']!=='' || $in['lang']!=='' ? 'No messages match these filters' : 'Your messages will appear here','Tickets, phone payment links and sponsor messages appear here when they are queued.','mail') ?><?php else: ?>
<div class="table-wrap"><table class="message-table"><thead><tr><th scope="col">Recipient & message</th><th scope="col">Status</th><th scope="col">Timeline</th><th scope="col">Related record</th><th scope="col">Details</th></tr></thead><tbody>
<?php foreach ($rows as $row): $state=$row['delivery_state']; $saved=$state==='sent' && str_starts_with((string)$row['provider_message_id'],'log:'); ?>
<tr><td><div class="message-recipient"><span class="message-avatar"><?= Page::navIcon('mail') ?></span><div><b dir="ltr"><?= $e($row['to_email']) ?></b><small><?= $e(Queue::KINDS[$row['kind']] ?? str_replace('_',' ',$row['kind'])) ?> · <?= $e(['en'=>'English','ar'=>'Arabic','ku'=>'Kurdish'][$row['lang']] ?? $row['lang']) ?></small></div></div></td>
<td><?= Page::pill($state) ?><?php if ($saved): ?><small class="field-hint">Saved locally</small><?php elseif ($state==='pending' && $row['attempts']>0): ?><small class="field-hint">Retry scheduled</small><?php elseif ($state==='skipped'): ?><small class="field-hint">No message sent</small><?php endif; ?></td>
<td><small class="message-time">Queued <?= Page::when($row['created_at']) ?></small><small class="message-time"><?= $state==='pending' ? 'Next attempt ' . Page::when($row['next_attempt_at']) : ($row['sent_at'] ? ($state==='skipped' ? 'Processed ' : ($saved ? 'Saved ' : 'Sent ')) . Page::when($row['sent_at']) : 'Attempts: ' . (int)$row['attempts']) ?></small></td>
<td><?php if ($row['registration_id']): ?><a class="message-record" href="registration.php?id=<?= (int)$row['registration_id'] ?>"><?= $e($row['reference']) ?></a><small class="field-hint"><?= $e(Registrations::fullName($row)) ?></small><?php elseif ($row['checkout_id']): ?><b class="message-record"><?= $e($row['reference']) ?></b><small class="field-hint">Phone payment form</small><?php elseif ($row['sponsor_request_id']): ?><a class="message-record" href="sponsors.php?id=<?= (int)$row['sponsor_request_id'] ?>"><?= $e($row['reference']) ?></a><small class="field-hint"><?= $e($row['company']) ?></small><?php else: ?><span class="muted small"><?= $row['kind']==='alert' ? 'Team alert' : 'No linked record' ?></span><?php endif; ?></td>
<td><details class="message-details"><summary>Delivery details</summary><dl><dt>Message</dt><dd>#<?= (int)$row['id'] ?></dd><dt>Attempts</dt><dd><?= (int)$row['attempts'] ?></dd><?php if ($row['provider_message_id']): ?><dt><?= $state==='skipped' ? 'Outcome' : 'Service reference' ?></dt><dd><?= $e($row['provider_message_id']) ?></dd><?php endif; ?></dl><?php if ($row['last_error']): ?><p class="message-error"><b>Latest error</b><?= $e($row['last_error']) ?></p><?php else: ?><p class="field-hint"><?= $state==='pending' ? 'Waiting for the scheduled email job.' : 'No delivery error recorded.' ?></p><?php endif; ?></details></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($pageCount>1): ?><nav class="message-pagination" aria-label="Email pages"><span>Page <?= $pageNo ?> of <?= $pageCount ?></span><div><?php if ($pageNo>1): ?><a class="btn small ghost" href="<?= $e($url(['page'=>$pageNo-1])) ?>">Previous</a><?php endif; ?><?php if ($pageNo<$pageCount): ?><a class="btn small ghost" href="<?= $e($url(['page'=>$pageNo+1])) ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
<?php endif; ?></section>
<?php Page::bottom();
