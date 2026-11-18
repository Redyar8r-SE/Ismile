<?php
// People deleted earlier whose PSOOLA ticket may still work (owner, 19 Nov
// 2026). Only the main Owner opens this page; each person is cancelled in
// PSOOLA only when the Owner presses that person's button.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Auth;
use Ismile\Erase;
use Ismile\PsoolaCancel;
use Ismile\UserError;

$user = Page::guard('owner');
$e = [Page::class, 'e'];
if (!Erase::isMainOwner($user)) {
    http_response_code(403);
    Page::top('Not allowed', 'registrations');
    echo '<div class="card"><h2>Not allowed</h2><p>Only the main Owner can open this page.</p></div>';
    Page::bottom();
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'cancel') {
    try {
        Auth::checkCsrf();
        Page::flash('ok', 'Cancelled in PSOOLA: ' . PsoolaCancel::cancelDeleted((int) ($_POST['record'] ?? 0), $user));
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (\Throwable $error) {
        App::log('error', 'Cancel deleted person in PSOOLA failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
        Page::flash('error', 'Something went wrong. Nothing more was cancelled. Try again in a minute.');
    }
    Page::redirect('deleted-psoola.php');
}

$problem = null;
$people = [];
if (!PsoolaCancel::active()) {
    $problem = 'PSOOLA is not the payment gateway on this server, so there is nothing to check.';
} else {
    try {
        $people = PsoolaCancel::deletedStillActive();
    } catch (\Throwable $error) {
        App::log('error', 'Deleted people PSOOLA check failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
        $problem = 'PSOOLA could not be checked right now. Reload the page in a minute.';
    }
}
$statusText = static function (array $order): string {
    if ($order['shared']) return 'Kept: another person in the database uses this order';
    if ($order['error'] !== null) return 'Not checked: ' . $order['error'];
    $look = $order['look'];
    if (!$look || !$look['found']) return 'PSOOLA does not know this order';
    if (PsoolaCancel::isCancelled($look)) return 'Already cancelled';
    return 'ACTIVE in PSOOLA (order ' . $look['status'] . ', ticket ' . implode(', ', $look['tickets']) . ')';
};

Page::top('Deleted people in PSOOLA', 'registrations');
?>
<div data-no-live>
<section class="card">
  <h2>Deleted people whose PSOOLA ticket still works</h2>
  <p class="muted">These people were deleted from the database earlier, but their PSOOLA ticket was not cancelled then. Press <b>Cancel in PSOOLA</b> for each one you want stopped: the QR stops working and the seat comes back. Nothing happens until you press the button. Only you can see this page.</p>
  <p><a class="btn ghost" href="registrations.php">Back to Registrations</a> <a class="btn ghost" href="deleted-psoola.php">Check again</a></p>
  <?php if ($problem): ?><p class="flash err"><?= $e($problem) ?></p><?php endif; ?>
  <?php if (!$problem && !$people): ?><p><b>All clear.</b> No deleted person has an active PSOOLA ticket.</p><?php endif; ?>
  <?php if ($people): ?>
  <div class="table-wrap"><table>
    <tr><th>Deleted person</th><th>Reference</th><th>Deleted</th><th>PSOOLA</th><th></th></tr>
    <?php foreach ($people as $person): $record = $person['record']; ?>
    <tr>
      <td><b><?= $e($record['label']) ?></b></td>
      <td><code><?= $e($record['ref']) ?></code></td>
      <td><?= Page::when($record['deleted_at']) ?></td>
      <td><?php foreach ($person['orders'] as $order): ?><div><?= $e($order['what']) ?>: <?= $e($statusText($order)) ?></div><?php endforeach; ?></td>
      <td><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="record" value="<?= (int) $record['id'] ?>">
        <button class="btn red"<?= Page::confirm('red', 'ticket', 'Cancel this ticket in PSOOLA?', 'The QR stops working at the gate and the seat comes back. PSOOLA has no undo.',
          [['user', (string) $record['label']], ['hash', (string) $record['ref']]], ['yes' => 'Yes, cancel in PSOOLA', 'no' => 'No, keep it']) ?>>Cancel in PSOOLA</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>
<?php Page::bottom();
