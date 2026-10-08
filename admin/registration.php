<?php
// One registered (paid) person: every detail, the payment, ticket, emails,
// workshops and history, and the buttons the office needs. ?new=1 registers
// a caller by phone (a "Pay now" link, or a free ticket from the Owner).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
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
$inCheckin = ($_GET['workspace'] ?? '') === 'checkin';
if ($inCheckin) Page::guard('checkin');
$workspaceDay = isset($_GET['day']) && in_array((int)$_GET['day'], [1,2], true) ? (int)$_GET['day'] : null;
$listUrl = $inCheckin ? CheckinWorkspace::url('guests', $workspaceDay) : 'registrations.php';
$workspaceQuery = $inCheckin ? '&workspace=checkin' . ($workspaceDay ? '&day=' . $workspaceDay : '') : '';
if ($inCheckin && isset($_GET['new'])) Page::redirect('registration.php?new=1');

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
                Page::redirect('registration.php?id=' . $created['id'] . $workspaceQuery);
            }
            $checkout = \Ismile\Checkouts::createFromOffice($_POST, $user);
            Page::flash('ok', 'A "Pay now" email is on its way to ' . $checkout['email'] . ' (' . $checkout['ref'] . '). ' . Registrations::fullName($checkout)
                . ' is registered as soon as they pay; nothing is saved as a registration before that. The link works for ' . max(1, \Ismile\Settings::int('pay_link_days')) . ' day(s).');
            Page::redirect($inCheckin ? CheckinWorkspace::url('register', $workspaceDay) : 'registration.php?new=1');
        } catch (UserError $error) {
            Page::flash('error', $error->key);
        }
    }
    $e = [Page::class, 'e'];
    $value = static fn (string $key): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
    $selected = static fn (string $key, string $option, string $default = ''): string => ($_POST[$key] ?? $default) === $option ? ' selected' : '';
    $vip = ($_POST['ticket'] ?? '') === 'vip';
    $student = !$vip && (($_POST['specialty'] ?? '') === 'student' || ($_POST['ticket'] ?? '') === 'student');
    Page::top($inCheckin ? 'Check-in · Register' : 'Register a caller (phone)', $inCheckin ? 'checkin' : 'registrations', '<a class="btn ghost" href="' . Page::e($listUrl) . '">&larr; ' . ($inCheckin ? 'Guest list' : 'All registrations') . '</a>');
    if ($inCheckin) echo CheckinWorkspace::navigation('register', $user, $workspaceDay);
    ?>
    <form method="post" class="card stack caller-form" id="caller-form">
      <?= Page::csrfField() ?>
      <p class="muted">Enter the caller's details and send a payment link. They receive their ticket after paying. Fields marked * are required.</p>
      <fieldset class="form-section">
        <legend>1. Caller details</legend>
        <div class="row3"><label>First name *<input name="first_name" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="given-name" required></label><label>Father's name *<input name="father_name" maxlength="60" value="<?= $value('father_name') ?>" required></label><label>Grandfather's name *<input name="grandfather_name" maxlength="60" value="<?= $value('grandfather_name') ?>" required></label></div>
        <div class="row3"><label>Phone *<input type="tel" name="phone" dir="ltr" autocomplete="tel" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email *<input type="email" name="email" dir="ltr" autocomplete="email" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<input name="city" maxlength="80" value="<?= $value('city') ?>" autocomplete="address-level2"></label></div>
      </fieldset>
      <fieldset class="form-section">
        <legend>2. Specialty &amp; ticket</legend>
        <div class="row3">
          <label>What is your specialty? *<select name="specialty" id="caller-specialty" required>
            <option value="">Choose specialty</option>
            <?php foreach (Registrations::SPECIALTY_NAMES as $key => $label): ?><option value="<?= $e($key) ?>"<?= $selected('specialty', $key) ?>><?= $e($label) ?></option><?php endforeach; ?>
          </select></label>
          <label>Ticket *<select name="ticket" id="caller-ticket"><option value="professional"<?= !$student && !$vip ? ' selected' : '' ?>>Professional</option><option value="student"<?= $student ? ' selected' : '' ?>>Student</option><option value="vip"<?= $vip ? ' selected' : '' ?>>VIP</option></select></label>
        </div>
        <p class="muted small" id="caller-ticket-note">Dental students receive a student ticket. For student tickets, record the university after checking the ID.</p>
        <div class="row3" id="caller-student-fields">
          <label>University (required for students)<input name="university" id="caller-university" maxlength="160" value="<?= $value('university') ?>"<?= $student ? ' required' : '' ?>></label>
          <label>Ambassador code (optional)<input name="ambassador" maxlength="40" value="<?= $value('ambassador') ?>" placeholder="e.g. AMB-SARA" autocomplete="off"></label>
        </div>
      </fieldset>
      <fieldset class="form-section">
        <legend>3. Lunch</legend>
        <label id="caller-vip-lunch"<?= !$vip ? ' hidden' : '' ?>>VIP included lunch<select name="vip_lunch_day"<?= !$vip ? ' disabled' : '' ?>><option value="1"<?= $selected('vip_lunch_day','1','1') ?>>Day 1 · 20 November</option><option value="2"<?= $selected('vip_lunch_day','2','1') ?>>Day 2 · 21 November</option></select><small>VIP is $100 with one lunch included. The other day is $42. Call the guest to arrange home badge delivery.</small></label>
        <div class="checks"><label class="inline"><input type="checkbox" name="lunch_day1" value="1"<?= ($_POST['lunch_day1'] ?? '') === '1' ? ' checked' : '' ?>> Lunch day 1</label><label class="inline"><input type="checkbox" name="lunch_day2" value="1"<?= ($_POST['lunch_day2'] ?? '') === '1' ? ' checked' : '' ?>> Lunch day 2</label></div>
      </fieldset>
      <div class="form-actions"><a class="btn ghost" href="<?= $e($listUrl) ?>">Cancel</a><button class="btn" name="do" value="pay_link">Send payment link</button></div>
    </form>
      <?php if ($user['role'] === 'owner'): ?>
        <details class="card more owner-ticket"<?= ($_POST['do'] ?? '') === 'free' ? ' open' : '' ?>>
          <summary>Owner: issue a complimentary ticket</summary>
          <label>Reason (required for a free ticket)<input name="comp_reason" form="caller-form" maxlength="255" value="<?= $value('comp_reason') ?>" placeholder="e.g. Diamond sponsor staff"></label>
          <div class="form-actions">
          <button class="btn violet" form="caller-form" name="do" value="free" data-confirm="Register this person with a FREE ticket now? It is logged with your name and the reason.">Register with a free ticket</button>
          </div>
        </details>
      <?php endif; ?>
    <?php
    Page::bottom();
    exit;
}

$registration = Registrations::find((int) ($_GET['id'] ?? 0));
if ($registration === null) {
    Page::top('Registration not found', $inCheckin ? 'checkin' : 'registrations');
    if ($inCheckin) echo CheckinWorkspace::navigation('guests', $user, $workspaceDay);
    echo '<p><a class="btn" href="' . Page::e($listUrl) . '">Back to the list</a></p>';
    Page::bottom();
    exit;
}
$id = (int) $registration['id'];
$back = 'registration.php?id=' . $id . $workspaceQuery;

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
$paidPayment = Db::one("SELECT amount_confirmed, currency FROM payments WHERE registration_id = ? AND status = 'paid' LIMIT 1", [$id]);
$paidAmount = $paidPayment !== null ? (int) $paidPayment['amount_confirmed'] : null;
$e = [Page::class, 'e'];

Page::top(Registrations::fullName($registration), $inCheckin ? 'checkin' : 'registrations', '<a class="btn ghost" href="' . Page::e($listUrl) . '">&larr; ' . ($inCheckin ? 'Guest list' : 'All registrations') . '</a>');
if ($inCheckin) echo CheckinWorkspace::navigation('guests', $user, $workspaceDay);
?>
<div class="toolbar">
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
      <dt>Specialty</dt><dd><?= $e(Registrations::SPECIALTY_NAMES[$registration['specialty']] ?? $registration['specialty']) ?></dd>
      <dt>Ticket</dt><dd><?= $e($registration['ticket_type']) ?></dd>
      <dt>Lunch</dt><dd><?= $registration['lunch_day1'] ? 'Day 1 ' : '' ?><?= $registration['lunch_day2'] ? 'Day 2' : '' ?><?= !$registration['lunch_day1'] && !$registration['lunch_day2'] ? 'None' : '' ?></dd>
      <dt>Paid</dt><dd><?= Page::paidBadge($registration['status']) ?> <?= $paidAmount !== null ? Page::money($paidAmount, $paidPayment['currency']) : '' ?> <?= $registration['pay_method'] ? $e(strtoupper($registration['pay_method'])) : '' ?> · <?= Page::when($registration['paid_at']) ?></dd>
      <?php if ($registration['ticket_type'] === 'vip'): ?><dt>VIP included lunch</dt><dd>Day <?= (int) $registration['vip_lunch_day'] ?> · Call to arrange home badge delivery.</dd><?php endif; ?>
      <dt>No-refund terms</dt><dd><?= $registration['terms_accepted_at'] ? 'accepted on the website, ' . Page::when($registration['terms_accepted_at']) : '<span class="muted">registered by the office</span>' ?></dd>
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
        <div class="form-actions"><button class="btn">Save details</button></div>
      </form>
    </details>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Ticket</h2>
    <?php if ($ticket && $ticket['cancelled_at'] === null): ?>
      <p><code class="big"><?= $e($ticket['ticket_no']) ?></code> version <?= (int) $ticket['version'] ?> · <?= $e($ticket['source']) ?></p>
      <?php $attendance = Db::all('SELECT event_day, checked_in_at FROM ticket_attendance WHERE ticket_id=? ORDER BY event_day', [$ticket['id']]); ?>
      <?php foreach ([1,2] as $eventDay): $arrival = array_values(array_filter($attendance, static fn($a) => (int)$a['event_day']===$eventDay))[0] ?? null; ?><p>Day <?= $eventDay ?>: <?= $arrival ? Page::pill('arrived') . ' ' . Page::when($arrival['checked_in_at']) : 'Not checked in' ?></p><?php endforeach; ?>
      <?php if ($attendance && Auth::can($user, 'certificates')): ?><p><a class="btn green" href="certificate-download.php?id=<?= $id ?>">Download certificate (PDF)</a></p><?php else: ?><p class="muted small">Certificate available after attendance. Payment alone does not qualify.</p><?php endif; ?>
      <?php if ($canEdit): ?>
      <form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="do" value="resend_ticket"><button class="btn">Resend ticket</button></form>
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
    <tr><th>Workshop</th><th>Price</th><th>Paid?</th><th>Booked by</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($bookings as $booking): $workshop = SiteData::workshop($booking['workshop_id']); ?>
    <tr>
      <td><?= $e($workshop ? SiteData::workshopName($workshop) : $booking['workshop_id']) ?><?= $booking['over_capacity'] ? ' ' . Page::pill('waiting_list') . '<small> over capacity</small>' : '' ?></td>
      <td><?= Page::money((int) $booking['price_agreed'], $prices['currency']) ?></td>
      <td><?= Page::paidBadge($booking['payment_status']) ?><?= $booking['payment_status'] === 'paid' ? '<br><small class="muted">' . $e((string) $booking['paid_how']) . ', ' . Page::when($booking['paid_at']) . '</small>' : '' ?></td>
      <td><?= $e($booking['booked_by_name'] ?? '') ?> <?= Page::when($booking['created_at']) ?></td>
      <td class="table-action-cell">
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
    <tr><th>Email</th><th>To</th><th>Status</th><th>Tries</th><th>Sent</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($emails as $email): ?>
    <tr><td><?= $e($email['kind']) ?></td><td dir="ltr"><?= $e($email['to_email']) ?></td>
      <td><?= Page::pill($email['status']) ?><?= $email['last_error'] ? '<br><small class="muted">' . $e($email['last_error']) . '</small>' : '' ?></td>
      <td><?= (int) $email['attempts'] ?></td><td><?= Page::when($email['sent_at']) ?></td>
      <td class="table-action-cell"><?php if ($canEdit): ?><form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="resend_email"><input type="hidden" name="email_id" value="<?= (int) $email['id'] ?>"><button class="btn small ghost">Send again</button></form><?php endif; ?></td></tr>
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
      <?php if ($canEdit): ?><div class="form-actions"><button class="btn">Save notes</button></div><?php endif; ?>
    </form>
  </div>
  <div class="card danger">
    <h2>Cancel</h2>
    <p class="muted small">Tickets are non-refundable: cancelling stops the ticket at the door and frees workshop seats; no money is returned.</p>
    <?php if ($canEdit && $registration['status'] !== 'cancelled'): ?>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel">
      <label>Reason for cancelling<input name="reason" required></label>
      <div class="form-actions danger-actions"><button class="btn red" data-confirm="Cancel this registration? The ticket will stop working at the door. Tickets are non-refundable.">Cancel registration</button></div>
    </form>
    <?php endif; ?>
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
