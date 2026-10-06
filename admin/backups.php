<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Audit;
use Ismile\Backup;
use Ismile\UserError;

$user = Page::guard('owner');
Page::action(static function () use ($user): string {
    if (($_POST['do'] ?? '') !== 'backup') throw new UserError('Unknown action.');
    $name = Backup::run();
    Audit::log((int)$user['id'],'backup.created',null,null,['file'=>$name]);
    return 'Database backup completed. It is ready to download.';
},'backups.php');
$backups = Backup::available();
$latest = $backups[0] ?? null;
$fresh = $latest !== null && $latest['created_at'] >= time() - 36 * 3600;
$totalSize = array_sum(array_column($backups,'size'));
$size = static fn (int $bytes): string => $bytes >= 1024 * 1024 ? number_format($bytes / (1024 * 1024),1) . ' MB' : number_format($bytes / 1024,1) . ' KB';
$e = [Page::class,'e'];
$download = static fn (string $name): string => 'backup-download.php?file=' . rawurlencode($name);
Page::top('Backups','backups','<form method="post">' . Page::csrfField() . '<input type="hidden" name="do" value="backup"><button class="btn ghost">' . Page::navIcon('backups') . '<span>Create backup</span></button></form>');
?>
<?= Page::stats([
    ['Latest successful backup',$latest ? date('j M',$latest['created_at']) : 'None yet',$latest ? date('Y · H:i',$latest['created_at']) : 'Create your first database copy','calendar','teal'],
    ['Available backups',number_format(count($backups)),'Completed database copies','backups','blue'],
    ['Total size',$size($totalSize),'Compressed backup files','download','violet'],
    ['Retention',Backup::KEEP_COUNT . ' backups','The newest successful copies are kept','clock','gold'],
]) ?>
<div class="backup-workspace">
<section class="card latest-backup">
  <?= Page::panelHeading('Your latest successful backup','A completed copy of your database, ready for safekeeping.','backups') ?>
  <?php if ($latest): ?>
  <div class="backup-feature"><span class="backup-feature-icon"><?= Page::navIcon('backups') ?></span><div><span class="pill <?= $fresh ? 'green':'gold' ?>"><?= $fresh ? 'Up to date' : 'More than 36 hours old' ?></span><h2><?= $e(date('j F Y',$latest['created_at'])) ?></h2><p>Completed at <?= $e(date('H:i',$latest['created_at'])) ?> · <?= $e($size($latest['size'])) ?></p></div></div>
  <div class="backup-filename"><span>Backup file</span><code><?= $e($latest['name']) ?></code></div>
  <div class="form-actions"><a class="btn" href="<?= $e($download($latest['name'])) ?>"><?= Page::navIcon('download') ?><span>Download latest backup</span></a></div>
  <?php else: ?>
  <?= Page::emptyState('No successful backup yet','Use Create backup to make a copy of your current database.','backups') ?>
  <?php endif; ?>
</section>
<section class="card backup-guide"><?= Page::panelHeading('A copy you can keep','Available only to the Owner.','security') ?><ul><li><span class="role-guide-icon"><?= Page::navIcon('security') ?></span><div><b>Owner access</b><p>Only the Owner can create or download these copies. Each action is recorded in the activity log.</p></div></li><li><span class="role-guide-icon"><?= Page::navIcon('registrations') ?></span><div><b>Event records included</b><p>The database export includes guests, payments, bookings, email records and database-stored ID photos.</p></div></li><li><span class="role-guide-icon"><?= Page::navIcon('clock') ?></span><div><b>Scheduled and manual copies</b><p>The nightly job makes scheduled backups when configured. Create backup makes a new copy now.</p></div></li></ul><p class="configuration-note">Keep downloaded copies in a private place. Website files and photos outside the database need their own backup.</p></section>
</div>
<section class="card backup-history"><div class="panel-top"><?= Page::panelHeading('Backup history','Completed copies, with the newest first.','clock') ?><span class="pill grey"><?= count($backups) ?> copies</span></div>
<?php if (!$backups): ?><?= Page::emptyState('Your backup history starts here','Completed database copies will appear in this list.','download') ?><?php else: ?>
<div class="table-wrap"><table><thead><tr><th scope="col">Completed</th><th scope="col">File</th><th scope="col">Size</th><th scope="col" class="table-action-heading">Download</th></tr></thead><tbody>
<?php foreach ($backups as $index=>$backup): ?><tr><td><b><?= $e(date('j M Y, H:i',$backup['created_at'])) ?></b><?php if ($index===0): ?> <span class="pill green">Latest</span><?php endif; ?></td><td><code><?= $e($backup['name']) ?></code></td><td><?= $e($size($backup['size'])) ?></td><td class="table-action-cell"><a class="btn ghost small" href="<?= $e($download($backup['name'])) ?>" aria-label="<?= $e('Download backup from ' . date('j M Y, H:i',$backup['created_at'])) ?>"><?= Page::navIcon('download') ?><span>Download</span></a></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php Page::bottom();
