<?php
// One registered (paid) person: every detail, the payment, ticket, emails,
// workshops and history, and the buttons the office needs. ?new=1 registers
// someone who paid in cash at the desk (PSOOLA issues and emails the ticket
// at once), or gives a free ticket (Owner only).

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\CheckinWorkspace;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Erase;
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

// ---------- Register (cash) ----------
// A cash sale that PSOOLA confirms at once. Only the Owner can give a free
// ticket. (Website visitors pay online themselves; no payment links are sent.)
if (isset($_GET['new'])) {
    if (!$canEdit) {
        Page::redirect('registrations.php');
    }
    if (Page::query('new') === 'free') {
        require __DIR__ . '/_guest_tickets.php';
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            \Ismile\Auth::checkCsrf();
            Page::useOnce();
            if (($_POST['do'] ?? '') === 'cash') {
                $checkout = \Ismile\Checkouts::createForCash($_POST, $user, $_FILES['student_id'] ?? null);
                $sale = \Ismile\Payments\Payments::sellForCash($checkout, $user);
                $price = number_format($sale['amount']) . ' ' . $sale['currency'];
                if ($sale['status'] === 'paid' && $sale['registration_id']) {
                    Page::flash('ok', 'Sold for cash: ' . $price . '. Collect this amount. ' . Registrations::fullName($checkout) . ' is registered (' . $checkout['ref'] . ') and Psoola is emailing the ticket with the QR to ' . $checkout['email'] . '.');
                    Page::redirect('registration.php?id=' . $sale['registration_id'] . $workspaceQuery);
                }
                Page::flash('ok', 'Psoola made the cash sale (' . $price . '). Collect this amount. The registration of ' . $checkout['ref'] . ' finishes within 5 minutes, when Psoola confirms it again.');
                Page::redirect('registrations.php');
            }
            if (($_POST['do'] ?? '') === 'free') {
                $created = Office::createComplimentary($user, $_POST, $_FILES['student_id'] ?? null);
                Page::flash('ok', 'Registered ' . $created['ref'] . ' with a free ticket. The ticket is on its way by email.');
                Page::redirect('registration.php?id=' . $created['id'] . $workspaceQuery);
            }
            throw new UserError('Choose Paid by cash.');
        } catch (UserError $error) {
            Page::flash('error', $error->key);
        } catch (\Ismile\Payments\GatewayNotReady $error) {
            \Ismile\App::log('error', 'Register by phone or cash failed', ['error' => $error->getMessage()]);
            Page::flash('error', 'The payment company is not ready, so nothing was sold or sent. (The detail is in the server log.)');
        }
    }
    $e = [Page::class, 'e'];
    $value = static fn (string $key): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
    $selected = static fn (string $key, string $option, string $default = ''): string => ($_POST[$key] ?? $default) === $option ? ' selected' : '';
    $vip = ($_POST['ticket'] ?? '') === 'vip';
    $student = !$vip && (($_POST['specialty'] ?? '') === 'student' || ($_POST['ticket'] ?? '') === 'student');
    // Prices for the "Packages & prices" panel and the running total. PSOOLA's
    // IQD prices when it answers (cached five minutes); the total is a preview,
    // the amount charged is always the total PSOOLA returns for the sale.
    $psoola = \Ismile\Payments\PsoolaPrices::get();
    $site = SiteData::prices();
    $label = static fn (int $amount, string $currency): string => $amount <= 0 ? '–' : ($currency === 'USD' ? '$' . number_format($amount) : number_format($amount) . ' ' . $currency);
    $shown = [
        'professional' => $psoola ? $label($psoola['ticketTypes']['professional'], $psoola['currency']) : $label($site['professional'], $site['currency']),
        'student'      => $psoola ? $label($psoola['ticketTypes']['student'], $psoola['currency']) : $label($site['student'], $site['currency']),
        'vip'          => $psoola ? $label($psoola['ticketTypes']['vip'], $psoola['currency']) : $label($site['vip'], $site['vipCurrency']),
        'lunch1'       => $psoola ? $label($psoola['lunch']['day1'], $psoola['currency']) : $label($site['lunchDay1'], $site['lunchCurrency']),
        'lunch2'       => $psoola ? $label($psoola['lunch']['day2'], $psoola['currency']) : $label($site['lunchDay2'], $site['lunchCurrency']),
    ];
    // The dollar price under PSOOLA's dinar price (the site's own price list).
    $vipUsd = $psoola && $site['vipCurrency'] !== $psoola['currency'] ? $label($site['vip'], $site['vipCurrency']) . ' · ' : '';
    $lunchUsd = static fn (int $day): string => $psoola && $site['lunchCurrency'] !== $psoola['currency'] ? $label($site['lunchDay' . $day], $site['lunchCurrency']) . ' / day' : 'per day';
    $priceData = $psoola ? json_encode([
        'currency' => $psoola['currency'],
        'professional' => $psoola['ticketTypes']['professional'], 'student' => $psoola['ticketTypes']['student'], 'vip' => $psoola['ticketTypes']['vip'],
        'lunch1' => $psoola['lunch']['day1'], 'lunch2' => $psoola['lunch']['day2'],
    ]) : '';
    Page::top($inCheckin ? 'Check-in · Register' : 'Register (cash)', $inCheckin ? 'checkin' : 'registrations', '<a class="btn ghost" href="' . Page::e($listUrl) . '">&larr; ' . ($inCheckin ? 'Guest list' : 'All registrations') . '</a>');
    if ($inCheckin) echo CheckinWorkspace::navigation('register', $user, $workspaceDay);
    ?>
    <div class="cash-layout">
    <div class="cash-main">
    <form method="post" class="card stack caller-form" id="caller-form" enctype="multipart/form-data" data-prices="<?= $e((string) $priceData) ?>">
      <?= Page::csrfField() ?><?= Page::onceField() ?>
      <p class="muted">For a person who pays you in <b>cash</b>: enter their details and press <b>Paid by cash</b>. Psoola issues the ticket at once and emails the QR. Fields marked * are required.</p>
      <fieldset class="form-section">
        <legend>1. Person's details</legend>
        <div class="row3"><label>First name *<input name="first_name" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="given-name" required></label><label>Second name *<input name="father_name" maxlength="60" value="<?= $value('father_name') ?>" required></label><label>Last name *<input name="grandfather_name" maxlength="60" value="<?= $value('grandfather_name') ?>" required></label></div>
        <div class="row3"><label>Phone *<input type="tel" name="phone" dir="ltr" autocomplete="tel" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email *<input type="email" name="email" dir="ltr" autocomplete="email" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<?= \Ismile\Cities::select($_POST['city'] ?? '') ?></label></div>
      </fieldset>
      <fieldset class="form-section">
        <legend>2. Specialty &amp; ticket</legend>
        <div class="row3">
          <label>What is your specialty? *<select name="specialty" id="caller-specialty" required>
            <option value="">Choose specialty</option>
            <?php foreach (Registrations::SPECIALTY_NAMES as $key => $label): ?><option value="<?= $e($key) ?>"<?= $selected('specialty', $key) ?>><?= $e($label) ?></option><?php endforeach; ?>
          </select></label>
          <label>Ticket *<select name="ticket" id="caller-ticket"><option value="professional"<?= !$student && !$vip ? ' selected' : '' ?>>Dentist</option><option value="student"<?= $student ? ' selected' : '' ?>>Student</option><option value="vip"<?= $vip ? ' selected' : '' ?>>VIP</option></select></label>
        </div>
        <p class="muted small" id="caller-ticket-note">Dental students in any stage (1st to 5th) receive a student ticket. For student tickets, record the university and stage after checking the ID.</p>
        <div class="row3" id="caller-student-fields">
          <label>University (required for students)<input name="university" id="caller-university" maxlength="160" value="<?= $value('university') ?>"<?= $student ? ' required' : '' ?>></label>
          <label>Stage<select name="stage" id="caller-stage"<?= $student ? ' required' : '' ?>><option value="">Choose the stage</option><?php foreach (range(\Ismile\Checkouts::FIRST_STUDENT_STAGE, \Ismile\Checkouts::LAST_STUDENT_STAGE) as $stage): ?><option value="<?= $stage ?>"<?= $selected('stage', (string) $stage) ?>><?= $e(\Ismile\Registrations::stageName($stage)) ?></option><?php endforeach; ?></select></label>
          <label>Ambassador code (optional)<input name="ambassador" maxlength="40" value="<?= $value('ambassador') ?>" placeholder="e.g. AMB-SARA" autocomplete="off"></label>
          <label class="id-upload" id="caller-id-upload">
            <input type="file" name="student_id" id="caller-student-id" accept="image/jpeg,image/png,image/webp"<?= $student ? ' required' : '' ?> aria-describedby="caller-id-hint">
            <span class="id-upload-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16.2c.6-1.6 1.8-2.4 3.2-2.4s2.6.8 3.2 2.4M14.5 10h4M14.5 13.5h3"/></svg></span>
            <span class="id-upload-text">
              <b>Student ID photo <em>Required for students</em></b>
              <small id="caller-id-hint">A clear photo of the student card · JPG, PNG or WebP, 8 MB at most. Ask the student to send it on WhatsApp.</small>
              <span class="id-upload-file" data-empty="No photo chosen yet. Click here or drop the photo on this box.">No photo chosen yet. Click here or drop the photo on this box.</span>
            </span>
            <span class="id-upload-preview" hidden><img alt="The chosen student ID photo"></span>
            <span class="btn ghost id-upload-button" aria-hidden="true">Choose photo</span>
          </label>
        </div>
      </fieldset>
      <fieldset class="form-section">
        <legend>3. Lunch <span class="legend-note">· optional, per day</span></legend>
        <label id="caller-vip-lunch"<?= !$vip ? ' hidden' : '' ?>>VIP included lunch<select name="vip_lunch_day"<?= !$vip ? ' disabled' : '' ?>><option value="1"<?= $selected('vip_lunch_day','1','1') ?>>Day 1 · 19 November</option><option value="2"<?= $selected('vip_lunch_day','2','1') ?>>Day 2 · 20 November</option></select><small><?= \Ismile\VipPackages::enabled()?'VIP is $200 with one lunch and one selected workshop included.':'VIP includes one lunch. The other day is optional.' ?> Call the guest to arrange home badge delivery.</small></label>
        <?php if(\Ismile\VipPackages::enabled()): ?><label id="caller-vip-workshop"<?= !$vip?' hidden':'' ?>>VIP included workshop<select name="vip_workshop"<?= !$vip?' disabled':'' ?> required><option value="">Choose one included workshop</option><?php foreach(\Ismile\VipPackages::WORKSHOPS as $workshopId): $vipWorkshop=\Ismile\Workshops::find($workshopId);if(!$vipWorkshop)continue;?><option value="<?= $e($workshopId) ?>"<?= $selected('vip_workshop',$workshopId,'') ?>><?= $e(\Ismile\Workshops::name($vipWorkshop)) ?></option><?php endforeach; ?></select><small>Saved with the VIP registration. No extra workshop payment is collected.</small></label><?php endif; ?>
        <div class="lunch-cards">
          <?php foreach ([1 => 'Thursday 19 Nov', 2 => 'Friday 20 Nov'] as $day => $date): ?>
          <label class="lunch-card"><input type="checkbox" name="lunch_day<?= $day ?>" value="1"<?= ($_POST['lunch_day' . $day] ?? '') === '1' ? ' checked' : '' ?>>
            <span class="lunch-card-text"><b>Lunch day <?= $day ?></b><small><?= $e($date) ?></small></span>
            <span class="lunch-card-price"><b data-lunch-price="<?= $day ?>"><?= $e($shown['lunch' . $day]) ?></b><small data-lunch-sub="<?= $day ?>"><?= $e($lunchUsd($day)) ?></small></span>
          </label>
          <?php endforeach; ?>
        </div>
        <p class="lunch-vip-note small"><span aria-hidden="true">♛</span> VIP tickets include one lunch day free. That day shows “Included with VIP” instead of a price.</p>
      </fieldset>
      <div class="form-actions"><a class="btn ghost" href="<?= $e($listUrl) ?>">Cancel</a><button class="btn cash-pay" name="do" value="cash" id="caller-pay">Paid by cash</button></div>
      <p class="muted small">The person is registered as paid by cash, and the money appears in Payments and Psoola orders.</p>
    </form>
    <!-- "Did you receive the cash?": opened by Paid by cash, filled from the form (admin.js). -->
    <dialog class="cash-confirm" id="cash-confirm" aria-labelledby="cash-confirm-title">
      <span class="cash-confirm-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="6" width="19" height="12" rx="2.5"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/></svg></span>
      <h2 id="cash-confirm-title">Did you receive the cash?</h2>
      <p class="cash-confirm-sub">Check the details with the guest before you sell. This cannot be undone here.</p>
      <dl class="cash-confirm-details">
        <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/></svg>Guest</dt><dd data-confirm-field="name"></dd></div>
        <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2"/></svg>Phone</dt><dd data-confirm-field="phone" dir="ltr"></dd></div>
        <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>Ticket email</dt><dd data-confirm-field="email" dir="ltr"></dd></div>
        <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8a2 2 0 0 0 0 4v0a2 2 0 0 0 0 4v1a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-1a2 2 0 0 1 0-4 2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z"/><path d="M14 6v12" stroke-dasharray="2 2"/></svg>Ticket</dt><dd data-confirm-field="ticket"></dd></div>
        <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 21V3c-2 1-3 4-3 7h3"/></svg>Lunch</dt><dd data-confirm-field="lunch"></dd></div>
      </dl>
      <div class="cash-confirm-amount"><span>Collect in cash</span><b data-confirm-field="total"></b></div>
      <label class="cash-confirm-check"><input type="checkbox" id="cash-confirm-received"><span data-confirm-field="received">I have received the cash from this guest.</span></label>
      <p class="cash-confirm-info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>Psoola issues the ticket at once and emails the QR to the email above.</p>
      <div class="cash-confirm-actions">
        <button type="button" class="btn ghost" data-confirm-back>No, go back</button>
        <button type="button" class="btn cash-pay" id="cash-confirm-yes" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>Yes, sell the ticket</button>
      </div>
    </dialog>
      <?php if (Auth::canIssueComplimentary($user)): ?>
        <details class="card more owner-ticket"<?= ($_POST['do'] ?? '') === 'free' ? ' open' : '' ?>>
          <summary>Issue a complimentary ticket</summary>
          <label>Reason (required for a free ticket)<input name="comp_reason" form="caller-form" maxlength="255" value="<?= $value('comp_reason') ?>" placeholder="e.g. Diamond sponsor staff"></label>
          <div class="form-actions">
          <button class="btn violet" form="caller-form" name="do" value="free"<?= Page::confirm('violet', 'gift', 'Give a free ticket?', 'This person is registered with a FREE ticket now, and the ticket is emailed.', [], ['form' => 'caller', 'note' => 'Logged with your name and the reason.', 'yes' => 'Yes, give free ticket', 'no' => 'No, go back']) ?>>Register with a free ticket</button>
          </div>
        </details>
      <?php endif; ?>
    </div>
    <aside class="cash-panel" aria-label="Packages, prices and total">
      <section class="card cash-prices">
        <div class="cash-prices-head"><h2>Packages &amp; prices</h2><?php if ($psoola): ?><span class="cash-live"><i aria-hidden="true"></i>Live from Psoola</span><?php endif; ?></div>
        <p class="muted small">Click a package to choose it as the ticket.</p>
        <?php foreach ([
            ['professional', 'Dentist', 'Professionals', 'teal', '<path d="M11 2v2M5 2v2M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>'],
            ['student', 'Student', 'Every stage, 1st to 5th', 'blue', '<path d="M21.42 10.92a1 1 0 0 0-.02-1.84L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.83l8.57 3.91a2 2 0 0 0 1.66 0z"/><path d="M22 10v6M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>'],
            ['vip', 'VIP', $vipUsd . '1 lunch free', 'gold', '<path d="M11.56 3.27a.5.5 0 0 1 .88 0l2.95 5.61a1 1 0 0 0 1.52.29l4.28-3.67a.5.5 0 0 1 .8.52l-2.83 10.25a1 1 0 0 1-.96.73H5.81a1 1 0 0 1-.96-.73L2.01 6.02a.5.5 0 0 1 .8-.52l4.28 3.67a1 1 0 0 0 1.52-.29z"/><path d="M5 21h14"/>'],
        ] as [$key, $name, $sub, $tone, $icon]): ?>
        <button type="button" class="cash-package tone-<?= $tone ?>" data-pick-ticket="<?= $key ?>" aria-pressed="false">
          <span class="cash-package-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icon ?></svg></span>
          <span class="cash-package-text"><b><?= $e($name) ?> <em>Selected</em></b><small><?= $e($sub) ?></small></span>
          <strong><?= $e($shown[$key]) ?></strong>
        </button>
        <?php endforeach; ?>
        <div class="cash-package tone-lunch is-static">
          <span class="cash-package-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"/></svg></span>
          <span class="cash-package-text"><b>Lunch</b><small><?= $e($lunchUsd(1)) ?></small></span>
          <strong><?= $e($shown['lunch1'] === $shown['lunch2'] ? $shown['lunch1'] : $shown['lunch1'] . ' / ' . $shown['lunch2']) ?></strong>
        </div>
      </section>
      <section class="card cash-total" aria-live="polite">
        <p class="cash-total-label">This sale</p>
        <ul class="cash-lines" id="cash-lines"></ul>
        <p class="cash-total-label">Total to collect</p>
        <p class="cash-total-amount<?= $psoola ? '' : ' is-waiting' ?>" id="cash-total"><?= $psoola ? '–' : 'After the sale' ?></p>
        <p class="cash-total-note small"><?= $psoola
            ? 'Psoola confirms the final amount when you press Paid by cash. Prices refresh from Psoola every 5 minutes.'
            : 'Psoola cannot be reached for prices right now, so the amount to collect is shown after the sale.' ?></p>
      </section>
    </aside>
    </div>
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
// The Owner changes anyone; Registration staff only the people they added.
$canChange = Office::canChange($user, $registration);
$addedBy = $registration['created_by'] !== null ? Db::value('SELECT name FROM admin_users WHERE id = ?', [$registration['created_by']]) : null;
$back = 'registration.php?id=' . $id . $workspaceQuery;

// ---------- Delete for good (main Owner only) ----------
// The page is gone afterwards, so this returns to the list instead.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'erase') {
    try {
        Auth::checkCsrf();
        Page::flash('ok', Erase::registration($id, (string) ($_POST['confirm_ref'] ?? ''), $user));
        Page::redirect($listUrl);
    } catch (UserError $error) {
        Page::flash('error', $error->key);
        Page::redirect($back . '#deleteRecord');
    }
}

// ---------- Actions ----------
Page::action(static function () use ($registration, $user, $canChange): string {
    $do = (string) ($_POST['do'] ?? '');
    $needsEdit = ['edit', 'notes', 'resend_ticket', 'resend_email'];
    if ($do === 'cancel' && $user['role'] !== 'owner') {
        throw new UserError('Only the Owner can cancel a registration.');
    }
    if (in_array($do, ['workshop_add', 'workshop_change', 'guest_workshop', 'guest_workshop_retry'], true) && !Auth::canBookWorkshops($user)) {
        throw new UserError('Your account cannot book workshops.');
    }
    if (in_array($do, $needsEdit, true) && !$canChange) {
        throw new UserError('You can change only the people you added yourself.');
    }
    $fresh = Registrations::find((int) $registration['id']);
    return match ($do) {
        'edit'            => Office::editContact($fresh, $user, $_POST),
        'resend_ticket'   => Office::resendTicket($fresh, $user),
        'cancel'          => Office::cancel($fresh, $user, Page::post('reason')),
        'notes'           => Office::saveNotes($fresh, $user, Page::post('notes', 3000)),
        'workshop_add'    => Office::addWorkshop($fresh, $user, $_POST),
        'workshop_change' => Office::changeWorkshop((int) ($_POST['booking'] ?? 0), $user, $_POST),
        'guest_workshop' => (static function () use ($user, $fresh): string {
            \Ismile\GuestWorkshops::start($user, $fresh, $_POST);
            return 'Guest workshop booked. Complimentary ticket issued. Total: 0 IQD.';
        })(),
        'guest_workshop_retry' => (static function () use ($user, $fresh): string {
            \Ismile\GuestWorkshops::resume($user, $fresh, (int)($_POST['request_id'] ?? 0));
            return 'Guest workshop booked. Complimentary ticket issued. Total: 0 IQD.';
        })(),
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

require __DIR__ . '/_registration_details.php';
