<?php
// One registered (paid) person: every detail, the payment, ticket, emails,
// workshops and history, and the buttons the office needs. ?new=1 registers
// a caller by phone (a "Pay now" link, or a free ticket from the Owner).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Links;
use Ismile\Office;
use Ismile\Outbox;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;

$user = Page::guard('registrations');
$canEdit = Auth::can($user, 'edit');

// ---------- A caller registers by phone ----------
// They are registered only after paying: they get a "Pay now" email. Only the
// Owner can register someone directly, with a free ticket.
if (isset($_GET['new'])) {
    if (!$canEdit) {
        Page::redirect('registrations.php');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            \Ismile\Auth::checkCsrf();
            if (($_POST['do'] ?? '') === 'free') {
                $created = Office::createComplimentary($user, $_POST);
                Page::flash('ok', 'Registered ' . $created['ref'] . ' with a free ticket. The ticket is on its way by email.');
                Page::redirect('registration.php?id=' . $created['id']);
            }
            $checkout = \Ismile\Checkouts::createFromOffice($_POST, $user);
            Page::flash('ok', 'A "Pay now" email is on its way to ' . $checkout['email'] . ' (' . $checkout['ref'] . '). ' . Registrations::fullName($checkout)
                . ' is registered as soon as they pay; nothing is saved as a registration before that. The link works for ' . max(1, \Ismile\Settings::int('pay_link_days')) . ' day(s).');
            Page::redirect('registration.php?new=1');
        } catch (UserError $error) {
            Page::flash('error', $error->key);
            Page::redirect('registration.php?new=1');
        }
    }
    Page::top('Register a caller (phone)', 'registrations');
    ?>
    <form method="post" class="card stack narrow">
      <?= Page::csrfField() ?>
      <p class="muted">For someone who calls the office. They get a <b>"Pay now"</b> email and are <b>registered only after paying</b>. Students: write the university (the office has seen the ID).</p>
      <div class="row3"><label>First name<input name="first_name" required></label><label>Father's name<input name="father_name" required></label><label>Grandfather's name<input name="grandfather_name" required></label></div>
      <div class="row3"><label>Phone<input name="phone" dir="ltr" required placeholder="0750 123 4567"></label><label>Email<input type="email" name="email" dir="ltr" required></label><label>City<input name="city"></label></div>
      <div class="row3">
        <label>Ticket<select name="ticket"><option value="professional">Professional</option><option value="student">Student</option></select></label>
        <label>University (students)<input name="university"></label>
        <label>Email language<select name="lang"><option value="ku">Kurdish</option><option value="ar">Arabic</option><option value="en">English</option></select></label>
      </div>
      <div class="checks"><label><input type="checkbox" name="lunch_day1" value="1"> Lunch day 1</label><label><input type="checkbox" name="lunch_day2" value="1"> Lunch day 2</label></div>
      <button class="btn" name="do" value="pay_link">Send the "Pay now" link</button>
      <?php if ($user['role'] === 'owner'): ?>
        <fieldset class="card danger">
          <legend><b>Owner: free ticket instead</b></legend>
          <label>Reason (required, logged)<input name="comp_reason" placeholder="e.g. Diamond sponsor staff"></label>
          <button class="btn violet" name="do" value="free" data-confirm="Register this person with a FREE ticket now? It is logged with your name and the reason.">Register with a free ticket</button>
        </fieldset>
      <?php endif; ?>
    </form>
    <?php
    Page::bottom();
    exit;
}

$registration = Registrations::find((int) ($_GET['id'] ?? 0));
if ($registration === null) {
    Page::top('Registration not found', 'registrations');
    echo '<p><a class="btn" href="registrations.php">Back to the list</a></p>';
    Page::bottom();
    exit;
}
$id = (int) $registration['id'];
$back = 'registration.php?id=' . $id;

// ---------- Actions ----------
Page::action(static function () use ($registration, $user, $canEdit): string {
    $do = (string) ($_POST['do'] ?? '');
    $needsEdit = ['edit', 'cancel', 'notes', 'workshop_add', 'workshop_change', 'resend_ticket', 'resend_email'];
    if (in_array($do, $needsEdit, true) && !$canEdit) {
        throw new UserError('Your role cannot change registrations.');
    }
    $fresh = Registrations::find((int) $registration['id']);
    return match ($do) {
        'edit'            => Office::editContact($fresh, $user, $_POST),
        'resend_ticket'   => Office::resendTicket($fresh, $user),
        'cancel'          => Office::cancel($fresh, $user, Page::post('reason')),
        'notes'           => Office::saveNotes($fresh, $user, Page::post('notes', 3000)),
        'workshop_add'    => Office::addWorkshop($fresh, $user, $_POST),
        'workshop_change' => Office::changeWorkshop((int) ($_POST['booking'] ?? 0), $user, $_POST),
        'resend_email'    => (static function () use ($fresh): string {
            $email = Db::one('SELECT * FROM emails WHERE id = ? AND registration_id = ?', [(int) ($_POST['email_id'] ?? 0), $fresh['id']]);
            if ($email === null) {
                throw new UserError('Email not found.');
            }
            Outbox::queue($email['kind'], $fresh, $email['data'] ? (json_decode((string) $email['data'], true) ?: []) + ['resent' => time()] : ['resent' => time()]);
            return 'Queued again, to ' . $fresh['email'] . '.';
        })(),
        default           => throw new UserError('Unknown action.'),
    };
}, $back);

$ticket = Tickets::forRegistration($id);
$payments = Db::all('SELECT * FROM payments WHERE registration_id = ? ORDER BY id DESC', [$id]);
$emails = Db::all('SELECT * FROM emails WHERE registration_id = ? ORDER BY id DESC', [$id]);
$bookings = Db::all('SELECT wb.*, u.name AS booked_by_name FROM workshop_bookings wb LEFT JOIN admin_users u ON u.id = wb.booked_by WHERE wb.registration_id = ? AND wb.removed_at IS NULL ORDER BY wb.id', [$id]);
$history = Db::all('SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id WHERE a.target_type = ? AND a.target_id = ? ORDER BY a.id DESC LIMIT 50', ['registration', $id]);
$twins = Db::all("SELECT id, ref, first_name, father_name, grandfather_name, status FROM registrations WHERE id <> ? AND (email = ? OR phone = ?) AND status <> 'cancelled'", [$id, $registration['email'], $registration['phone']]);
$prices = SiteData::prices();
$paidAmount = ($value = Db::value("SELECT amount_confirmed FROM payments WHERE registration_id = ? AND status = 'paid' LIMIT 1", [$id])) !== null ? (int) $value : null;
$e = [Page::class, 'e'];

Page::top(Registrations::fullName($registration), 'registrations');
?>
<div class="toolbar">
  <a class="btn ghost" href="registrations.php">← All registrations</a>
  <span><code class="big"><?= $e($registration['ref']) ?></code> <?= Page::pill($registration['status']) ?>
  <?= $registration['comp_reason'] ? '<span class="muted">(' . $e($registration['comp_reason']) . ')</span>' : '' ?></span>
</div>

<?php if ($twins): ?>
<div class="flash warn">Possible duplicate: same email or phone as
  <?php foreach ($twins as $twin): ?><a href="registration.php?id=<?= (int) $twin['id'] ?>"><?= $e($twin['ref']) ?></a> (<?= $e(Registrations::fullName($twin)) ?>, <?= $e($twin['status']) ?>) <?php endforeach; ?>.
  Families may share a phone; check before changing anything.</div>
<?php endif; ?>

<div class="grid2">
  <div class="card">
    <h2>Details</h2>
    <dl class="facts">
      <dt>Name</dt><dd><b><?= $e(Registrations::fullName($registration)) ?></b></dd>
      <dt>Phone</dt><dd dir="ltr"><?= $e($registration['phone']) ?></dd>
      <dt>Email</dt><dd dir="ltr"><?= $e($registration['email']) ?></dd>
      <dt>City</dt><dd><?= $e($registration['city']) ?></dd>
      <dt>Gender / age</dt><dd><?= $e($registration['gender']) ?> / <?= $e($registration['age'] ?? '–') ?></dd>
      <dt>Specialty</dt><dd><?= $e($registration['specialty']) ?></dd>
      <dt>Ticket</dt><dd><?= $e($registration['ticket_type']) ?></dd>
      <dt>Lunch</dt><dd><?= $registration['lunch_day1'] ? 'Day 1 ' : '' ?><?= $registration['lunch_day2'] ? 'Day 2' : '' ?><?= !$registration['lunch_day1'] && !$registration['lunch_day2'] ? 'None' : '' ?></dd>
      <dt>Paid</dt><dd><?= Page::paidBadge($registration['status']) ?> <?= $paidAmount !== null ? Page::money($paidAmount, $prices['currency']) : '' ?> <?= $registration['pay_method'] ? $e(strtoupper($registration['pay_method'])) : '' ?> · <?= Page::when($registration['paid_at']) ?></dd>
      <dt>Language</dt><dd><?= $e($registration['lang']) ?></dd>
      <dt>Form sent</dt><dd><?= Page::when($registration['created_at']) ?></dd>
    </dl>
    <?php if ($canEdit): ?>
    <details class="edit">
      <summary class="btn ghost small">Edit name, email or phone</summary>
      <form method="post" class="stack">
        <?= Page::csrfField() ?><input type="hidden" name="do" value="edit">
        <div class="row3"><label>First<input name="first_name" value="<?= $e($registration['first_name']) ?>" required></label><label>Father<input name="father_name" value="<?= $e($registration['father_name']) ?>" required></label><label>Grandfather<input name="grandfather_name" value="<?= $e($registration['grandfather_name']) ?>" required></label></div>
        <div class="row3"><label>Email<input name="email" dir="ltr" value="<?= $e($registration['email']) ?>" required></label><label>Phone<input name="phone" dir="ltr" value="<?= $e($registration['phone']) ?>" required></label><label>City<input name="city" value="<?= $e($registration['city']) ?>"></label></div>
        <p class="muted small">Changing the name reissues the ticket and emails it again (the name goes on the certificate). Every change is logged.</p>
        <button class="btn">Save</button>
      </form>
    </details>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Ticket</h2>
    <?php if ($ticket && $ticket['cancelled_at'] === null): ?>
      <p><code class="big"><?= $e($ticket['ticket_no']) ?></code> version <?= (int) $ticket['version'] ?> · <?= $e($ticket['source']) ?></p>
      <p><?= $ticket['checked_in_at'] ? Page::pill('arrived') . ' checked in ' . Page::when($ticket['checked_in_at']) : 'Not checked in yet.' ?></p>
      <?php if ($canEdit): ?>
      <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="resend_ticket"><button class="btn">Resend ticket</button></form>
      <?php endif; ?>
    <?php elseif ($ticket): ?>
      <p><?= Page::pill('cancelled') ?> Ticket <?= $e($ticket['ticket_no']) ?> was cancelled.</p>
    <?php else: ?>
      <p class="muted">No ticket: a ticket is made only when the payment is confirmed.</p>
    <?php endif; ?>

    <p class="small"><a href="<?= $e(Links::statusUrl($registration)) ?>" target="_blank" rel="noopener">Their ticket page</a> <span class="muted">(personal link: do not share)</span></p>

    <?php if ($registration['ticket_type'] === 'student'): ?>
      <h2>Student</h2>
      <p>University: <b><?= $e($registration['university'] ?? '') ?></b><?= $registration['ambassador_code'] ? ' · ambassador code <code>' . $e($registration['ambassador_code']) . '</code>' : '' ?></p>
      <?php if ($registration['id_photo_id'] && Auth::can($user, 'photos')): $photoInfo = \Ismile\IdPhotos::info((int) $registration['id_photo_id']); ?>
        <p class="muted small">Student ID photo, stored in the database (sent with the form <?= Page::when($photoInfo['uploaded_at'] ?? null) ?>, <?= $photoInfo ? round($photoInfo['bytes'] / 1024) . ' KB' : '' ?>). Accepted automatically when they paid; click to see it full size:</p>
        <a href="photo.php?id=<?= $id ?>" target="_blank" rel="noopener"><img class="idphoto" src="photo.php?id=<?= $id ?>" alt="Student ID photo"></a>
      <?php elseif ($registration['created_by'] === null && !$registration['id_photo_deleted_at'] && $registration['created_ip'] !== 'office'): ?>
        <p class="flash warn">No ID photo is stored for this student.</p>
      <?php elseif ($registration['id_photo_deleted_at']): ?>
        <p class="muted">Photo deleted <?= Page::when($registration['id_photo_deleted_at']) ?> (after the summit, as planned).</p>
      <?php elseif ($registration['created_ip'] === 'office'): ?>
        <p class="muted">Registered by the office (ID seen by phone / in person).</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Workshops (booked by phone)</h2>
  <div class="table-wrap"><table>
    <tr><th>Workshop</th><th>Price</th><th>Paid?</th><th>Booked by</th><th></th></tr>
    <?php foreach ($bookings as $booking): $workshop = SiteData::workshop($booking['workshop_id']); ?>
    <tr>
      <td><?= $e($workshop ? SiteData::workshopName($workshop) : $booking['workshop_id']) ?><?= $booking['over_capacity'] ? ' ' . Page::pill('waiting_list') . '<small> over capacity</small>' : '' ?></td>
      <td><?= Page::money((int) $booking['price_agreed'], $prices['currency']) ?></td>
      <td><?= Page::paidBadge($booking['payment_status']) ?><?= $booking['payment_status'] === 'paid' ? '<br><small class="muted">' . $e((string) $booking['paid_how']) . ', ' . Page::when($booking['paid_at']) . '</small>' : '' ?></td>
      <td><?= $e($booking['booked_by_name'] ?? '') ?> <?= Page::when($booking['created_at']) ?></td>
      <td>
        <?php if ($canEdit): ?>
          <?= Page::workshopPaymentForm($booking, $user, 'registration.php?id=' . $id) ?>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$bookings): ?><tr><td colspan="5" class="muted">No workshops.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($canEdit && $registration['status'] !== 'cancelled'): ?>
  <?= Page::workshopAddForm($user, $registration) ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Payments</h2>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Method</th><th>Expected</th><th>Confirmed</th><th>Status</th><th>Company id</th><th>Started</th></tr>
    <?php foreach ($payments as $payment): ?>
    <tr><td><?= (int) $payment['id'] ?></td><td><?= $e(strtoupper((string) $payment['method'])) ?></td><td><?= Page::money((int) $payment['amount_expected'], $payment['currency']) ?></td>
      <td><?= Page::money($payment['amount_confirmed'] === null ? null : (int) $payment['amount_confirmed'], $payment['currency']) ?></td>
      <td><?= Page::pill($payment['status']) ?><?= $payment['last_error'] ? '<br><small class="muted">' . $e($payment['last_error']) . '</small>' : '' ?></td>
      <td><code><?= $e($payment['provider_payment_id'] ?? '–') ?></code></td><td><?= Page::when($payment['created_at']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?><tr><td colspan="7" class="muted">No payment attempts.</td></tr><?php endif; ?>
  </table></div>
</div>

<div class="card">
  <h2>Emails</h2>
  <div class="table-wrap"><table>
    <tr><th>Email</th><th>To</th><th>Status</th><th>Tries</th><th>Sent</th><th></th></tr>
    <?php foreach ($emails as $email): ?>
    <tr><td><?= $e($email['kind']) ?></td><td dir="ltr"><?= $e($email['to_email']) ?></td>
      <td><?= Page::pill($email['status']) ?><?= $email['last_error'] ? '<br><small class="muted">' . $e($email['last_error']) . '</small>' : '' ?></td>
      <td><?= (int) $email['attempts'] ?></td><td><?= Page::when($email['sent_at']) ?></td>
      <td><?php if ($canEdit): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="resend_email"><input type="hidden" name="email_id" value="<?= (int) $email['id'] ?>"><button class="btn small ghost">Send again</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$emails): ?><tr><td colspan="6" class="muted">No emails.</td></tr><?php endif; ?>
  </table></div>
  <p class="muted small">Emails always go to the current address above. Fix a wrong address with "Edit", then "Send again".</p>
</div>

<div class="grid2">
  <div class="card">
    <h2>Notes</h2>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="notes">
      <textarea name="notes" rows="4" <?= $canEdit ? '' : 'readonly' ?>><?= $e($registration['notes'] ?? '') ?></textarea>
      <?php if ($canEdit): ?><button class="btn small">Save notes</button><?php endif; ?>
    </form>
  </div>
  <div class="card danger">
    <h2>Cancel</h2>
    <?php if ($canEdit && $registration['status'] !== 'cancelled'): ?>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel">
      <label>Reason for cancelling<input name="reason" required></label>
      <button class="btn red" data-confirm="Cancel this registration? The ticket will stop working at the door. Tickets are non-refundable.">Cancel registration</button>
    </form>
    <?php endif; ?>
    <p class="muted small">Tickets are non-refundable: cancelling stops the ticket at the door and frees workshop seats; no money is returned.</p>
  </div>
</div>

<div class="card">
  <h2>History</h2>
  <ul class="history">
    <?php foreach ($history as $item): ?>
      <li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'system') ?> · <?= $e($item['action']) ?> <span class="muted small"><?= $e(mb_substr((string) $item['details'], 0, 300)) ?></span></li>
    <?php endforeach; ?>
    <?php if (!$history): ?><li class="muted">No changes yet.</li><?php endif; ?>
  </ul>
</div>
<?php Page::bottom();
