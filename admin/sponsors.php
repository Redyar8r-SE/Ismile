<?php
// Sponsors & exhibition booths: requests handled by a person.
//   New → Contacted (called) → Agreed (amount agreed on a call) → Paid (exact
//   amount) → Confirmed, or Declined / Waiting list.
// Every call is saved with its result and the amount told. A company cannot be
// Confirmed before it paid, and a package cannot take more companies than its
// places (the Owner can override the places, and it is logged).
// The Owner manages the packages and their prices at the bottom of the list.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Auth;
use Ismile\BoothPlan;
use Ismile\Db;
use Ismile\SponsorPackages;
use Ismile\Sponsors;
use Ismile\UserError;

$user = Page::guard('sponsors');
$isOwner = $user['role'] === 'owner';
$id = (int) ($_GET['id'] ?? 0);
$kind = $bookingKind ?? (Page::query('kind')==='booth'?'booth':'sponsor');
if ($id>0 && ($existing= Sponsors::find($id))) $kind=$existing['kind'];
$pageFile=$kind==='booth'?'booths.php':'sponsors.php';
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET' && basename($_SERVER['SCRIPT_NAME']??$pageFile)!==$pageFile) {
    $query=$_GET; unset($query['kind']);
    Page::redirect($pageFile.($query?'?'.http_build_query($query):''));
}

// Office bookings can be created without a company submitting the website form.
if (Page::query('new') === '1') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            Auth::checkCsrf();
            if (($_POST['do'] ?? '') !== 'create_booking') {
                throw new UserError('Unknown action.');
            }
            $created = Sponsors::createByStaff(array_replace($_POST, ['kind' => $kind]), $user);
            Page::flash('ok', 'Booking saved: ' . $created['company'] . ' (' . $created['ref'] . ').'
                . ($created['booth_number'] ? ' Booth ' . $created['booth_number'] . ' is reserved and appears red on the public map.' : '')
                . ($created['status'] === 'paid' ? ' Payment recorded; press Confirm to finish.' : ''));
            Page::redirect($pageFile . '?id=' . $created['id']);
        } catch (UserError $error) {
            Page::flash('error', $error->key);
        } catch (\Throwable $error) {
            App::log('error', 'Staff sponsor booking failed', ['error' => $error->getMessage()]);
            Page::flash('error', 'The booking could not be saved. Please try again.');
        }
    }
    $e = [Page::class, 'e'];
    $value = static fn (string $key): string => Page::e((string) ($_POST[$key] ?? ''));
    $packages = SponsorPackages::all($kind, false);
    $bookedBooths = Sponsors::bookedBooths();
    $title = $kind === 'booth' ? 'Book exhibition booth' : 'Add sponsor booking';
    Page::top($title, $kind==='booth'?'booths':'sponsors', '<a class="btn ghost" href="'.$pageFile.'">&larr; Back to '.($kind==='booth'?'booths':'sponsors').'</a>');
    ?>
    <form method="post" class="card stack" id="staffBookingForm"<?= $kind==='sponsor'?' data-booth-picker':'' ?>>
      <?= Page::csrfField() ?><input type="hidden" name="do" value="create_booking">
      <?= Page::panelHeading($title, 'Record a company booking made by phone or directly with your team.', 'sponsors') ?>
      <div class="row3">
        <label>Company name<input name="company" required minlength="2" maxlength="160" autocomplete="organization" value="<?= $value('company') ?>"></label>
        <label>Contact person<input name="contact" required minlength="2" maxlength="120" autocomplete="name" value="<?= $value('contact') ?>"></label>
        <label>WhatsApp / phone<input name="phone" type="tel" required maxlength="30" autocomplete="tel" placeholder="0750 123 4567" value="<?= $value('phone') ?>"></label>
      </div>
      <div class="row3">
        <label>Email (optional)<input name="email" type="email" maxlength="190" autocomplete="email" value="<?= $value('email') ?>"></label>
        <label>Position (optional)<input name="role" maxlength="120" autocomplete="organization-title" value="<?= $value('role') ?>"></label>
        <label>City (optional)<input name="city" maxlength="80" autocomplete="address-level2" value="<?= $value('city') ?>"></label>
      </div>
      <div class="row3">
        <?php if($kind==='booth'): ?><label>Booth type<input value="Standard booth" readonly><input type="hidden" name="package_id" value="booth-standard"></label><?php else: ?>
        <label>Sponsorship package<select name="package_id" required><option value="">Choose a package</option>
          <?php foreach ($packages as $package): ?><option value="<?= $e($package['id']) ?>" data-booths="<?= $e(json_encode(BoothPlan::numbers($package['booth_tier']))) ?>"<?= ($_POST['package_id'] ?? '') === $package['id'] ? ' selected' : '' ?>><?= $e($package['name_en']) ?><?= $package['price'] > 0 ? ' · ' . number_format((int) $package['price']) . ' IQD' : '' ?></option><?php endforeach; ?>
        </select></label>
        <?php endif; ?>
        <?php if($kind==='sponsor'): ?>
        <label><?= $kind === 'booth' ? 'Reserve booth number' : 'Reserve booth number (optional)' ?><select name="booth_number" data-booked="<?= $e(json_encode($bookedBooths)) ?>"<?= $kind === 'booth' ? ' required' : '' ?>><option value=""><?= $kind === 'booth' ? 'Choose a booth' : 'No booth required' ?></option>
          <?php $chosenPackage = SponsorPackages::find((string) ($_POST['package_id'] ?? '')); foreach (BoothPlan::numbers($chosenPackage['booth_tier'] ?? null) as $booth): $taken = in_array($booth, $bookedBooths, true); ?><option value="<?= $booth ?>"<?= (string) ($_POST['booth_number'] ?? '') === (string) $booth ? ' selected' : '' ?><?= $taken ? ' disabled' : '' ?>>Booth <?= $booth ?><?= $taken ? ' — Booked' : '' ?></option><?php endforeach; ?>
        </select></label>
        <?php endif; ?>
        <label>Agreed price in IQD (optional)<input name="amount_agreed" type="number" min="0" max="1000000000" step="1" inputmode="numeric" placeholder="e.g. 6000000" value="<?= $value('amount_agreed') ?>"></label>
      </div>
      <p class="muted small"><?= $kind==='sponsor'?'Saving reserves any selected map position immediately. Company and contact details stay private.':'All exhibition bookings use Standard booth. No sponsorship tier or map position is needed.' ?> Leave the price empty if it has not been agreed yet.</p>
      <div class="row3">
        <label>Payment received<select name="paid_how"><option value="">Not paid yet</option>
          <?php foreach (Sponsors::PAID_HOW as $method => $label): ?><option value="<?= $e($method) ?>"<?= ($_POST['paid_how'] ?? '') === $method ? ' selected' : '' ?>><?= $e($label) ?> — full agreed amount received</option><?php endforeach; ?>
        </select></label>
        <label>Contact language<select name="lang"><?php foreach (['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'] as $language => $label): ?><option value="<?= $language ?>"<?= ($_POST['lang'] ?? 'en') === $language ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>Website / social page (optional)<input name="website" maxlength="190" value="<?= $value('website') ?>"></label>
      </div>
      <label>Notes (optional)<textarea name="notes" rows="3" maxlength="5000"><?= $value('notes') ?></textarea></label>
      <?php if (!$packages): ?><p class="flash warn">No active <?= $kind === 'booth' ? 'Standard booth type' : 'sponsorship packages' ?> are configured. The Owner can enable booking from the prices section.</p><?php endif; ?>
      <div class="form-actions"><button class="btn green big-btn"<?= !$packages ? ' disabled' : '' ?>><?= $kind === 'booth' ? 'Save booth booking' : 'Save sponsor booking' ?></button><a class="btn ghost" href="<?= $pageFile ?>">Cancel</a></div>
    </form>
    <?php
    Page::bottom();
    exit;
}
$back = ($id > 0 ? $pageFile.'?id=' . $id : $pageFile)
    . (str_starts_with((string) ($_POST['do'] ?? ''), 'pk_') ? '#packages' : '');

$detailsInput = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'details') {
    $detailsInput = $_POST;
    try {
        Auth::checkCsrf();
        Sponsors::saveDetails($id, $_POST, $user);
        Page::flash('ok', $kind==='sponsor' ? 'Saved. Booking details and the sponsor map updated.' : 'Saved. Standard booth booking details updated.');
        Page::redirect($back . '#bookingDetails');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (\Throwable $error) {
        App::log('error', 'Sponsor details failed', ['error' => $error->getMessage()]);
        Page::flash('error', 'The details could not be saved. Please try again.');
    }
}
if ($detailsInput === null) {
Page::action(static function () use ($user, $id, $kind): string {
    $do = (string) ($_POST['do'] ?? '');
    // ---- the packages and prices (Owner) ----
    if (str_starts_with($do, 'pk_')) {
        $package = (string) ($_POST['package'] ?? '');
        if($do!=='pk_create' && (SponsorPackages::find($package)['kind']??null)!==$kind) throw new UserError('Choose a package from this category.');
        return match ($do) {
            'pk_create' => 'Package added: ' . SponsorPackages::name(SponsorPackages::find(SponsorPackages::create(array_replace($_POST,['kind'=>$kind]), $user))) . '.',
            'pk_update' => (static function () use ($package, $user): string {
                SponsorPackages::update($package, $_POST, $user);
                return 'Package saved.';
            })(),
            'pk_hide'   => (static function () use ($package, $user): string {
                SponsorPackages::setStatus($package, 'hidden', $user);
                return 'Hidden: no longer on the website or the form. Companies already on it stay.';
            })(),
            'pk_show'   => (static function () use ($package, $user): string {
                SponsorPackages::setStatus($package, 'active', $user);
                return 'Shown again.';
            })(),
            'pk_delete' => (static function () use ($package, $user): string {
                SponsorPackages::delete($package, $user);
                return 'Package deleted.';
            })(),
            default     => throw new UserError('Unknown action.'),
        };
    }
    // ---- one request ----
    $request = Sponsors::find($id) ?? throw new UserError('Request not found.');
    switch ($do) {
        case 'cancel':
            return Sponsors::cancel($id,Page::post('reason',500),$user);
        case 'call':
            return Sponsors::logCall($id, $_POST, $user);
        case 'pay':
            Sponsors::recordPayment($id, $_POST, $user);
            return 'Payment recorded. Last step: press the green Confirm button.';
        case 'unpay':
            Sponsors::undoPayment($id, $user);
            return 'Last payment removed. Earlier payments stay recorded; the remaining balance is due.';
        case 'status':
            Sponsors::changeStatus($request, (string) ($_POST['status'] ?? ''), $user, ($_POST['override'] ?? '') === '1');
            return 'Status changed.';
    }
    throw new UserError('Unknown action.');
}, $back);
}

$e = [Page::class, 'e'];
$money = static fn ($amount): string => $amount === null || $amount === '' ? '–' : Page::money((int) $amount);
$inputTime = static fn (?string $at): string => $at ? date('Y-m-d\TH:i', (int) strtotime($at)) : '';
$now = App::now();
$staff = Db::all("SELECT id, name FROM admin_users WHERE disabled_at IS NULL AND role IN ('owner','registration','finance') ORDER BY name");

// =========================== one request ===========================
if ($id > 0 && ($request = Sponsors::find($id))) {
    $package = SponsorPackages::find($request['package_id']);
    $packages = $request['kind']==='booth' ? array_filter(SponsorPackages::all('booth'),static fn($p)=>$p['id']==='booth-standard') : SponsorPackages::all('sponsor');
    $calls = Sponsors::calls($id);
    $history = Db::all("SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id WHERE target_type = 'sponsor_request' AND target_id = ? ORDER BY a.id DESC LIMIT 50", [$id]);
    $isBooth = $request['kind'] === 'booth';
    $balance = max(0, (int) $request['amount_agreed'] - (int) $request['amount_paid']);
    $edit = $request;
    foreach (['company' => 'company', 'contact' => 'contact_name', 'phone' => 'phone', 'email' => 'email', 'role' => 'contact_role', 'city' => 'city', 'website' => 'website', 'lang' => 'lang', 'package_id' => 'package_id', 'booth_number' => 'booth_number', 'amount_agreed' => 'amount_agreed', 'assigned_to' => 'assigned_to', 'notes' => 'notes'] as $field => $column) {
        if ($detailsInput !== null && array_key_exists($field, $detailsInput)) {
            $edit[$column] = (string) $detailsInput[$field];
        }
    }
    $editPackage = SponsorPackages::find((string) ($edit['package_id'] ?? ''));
    $due = $request['next_call_at'] && $request['next_call_at'] <= $now && !in_array($request['status'], ['confirmed', 'declined', 'cancelled'], true);
    Page::top($request['company'], $isBooth?'booths':'sponsors', '<a class="btn ghost" href="'.$pageFile.'">&larr; All '.($isBooth?'booth bookings':'sponsor bookings').'</a>');
    ?>
    <div class="toolbar"><span><code class="big"><?= $e($request['ref']) ?></code> <?= Page::pill($request['status']) ?></span><a class="btn" href="#bookingDetails">Edit details</a></div>
    <?php
    // ---- the progress bar: where this company is ----
    $stepOf = ['new' => 1, 'contacted' => 2, 'waiting_list' => 2, 'agreed' => 3, 'paid' => 4, 'confirmed' => 5, 'declined' => 0, 'cancelled'=>0];
    $current = $stepOf[$request['status']] ?? 1;
    $stepNames = [1 => 'New request', 2 => 'Called', 3 => 'Price agreed', 4 => 'Paid', 5 => 'Confirmed'];
    $agreed = $request['amount_agreed'] !== null ? Page::money((int) $request['amount_agreed']) : '';
    $callForm = static function (bool $first) use ($e, $package): string {
        $choices = $first
            ? ['agreed' => ['check', 'They agreed a price'], 'interested' => ['thumb', 'Interested, not decided'], 'call_back' => ['clock', 'Call back later'], 'no_answer' => ['phone-off', 'No answer'], 'declined' => ['close', 'Not interested']]
            : array_map(static fn ($label) => ['', $label], Sponsors::OUTCOMES);
        $tiles = '';
        foreach ($choices as $key => [$icon, $label]) {
            $tiles .= '<label class="choice"><input type="radio" name="outcome" value="' . $key . '" required><span>' . ($icon !== '' ? '<i class="choice-icon">' . Page::navIcon($icon) . '</i>' : '') . '<span class="choice-label">' . $e($label) . '</span></span></label>';
        }
        $hint = $package && $package['price'] > 0 ? ' (list price ' . number_format((int) $package['price']) . ')' : '';
        return '<form method="post" class="stack">' . Page::csrfField() . '<input type="hidden" name="do" value="call">'
            . '<div class="choices">' . $tiles . '</div>'
            . '<div class="row3">'
            . '<label>Price in IQD' . $e($hint) . '<input name="amount" inputmode="numeric" placeholder="e.g. 6000000"></label>'
            . '<label>Call again on (optional)<input type="datetime-local" name="next_call_at"></label>'
            . '<label>Note (optional)<input name="note" maxlength="500" placeholder="what they said"></label>'
            . '</div><p class="muted small">"They agreed a price" needs the price.</p>'
            . '<div class="form-actions"><button class="btn green big-btn">Save call</button></div></form>';
    };
    ?>
    <?php if ($current > 0): ?>
    <ol class="tracker">
      <?php foreach ($stepNames as $n => $name): ?>
        <li class="<?= $n < $current || $current === 5 ? 'done' : ($n === $current ? 'now' : '') ?>"><b><?= $n < $current || $current === 5 ? Page::navIcon('check') : $n ?></b><span><?= $e($name) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <div class="card next-step">
      <?php if ($request['status'] === 'new' || $request['status'] === 'contacted' || $request['status'] === 'waiting_list'): ?>
        <h2 class="icon-label"><?= Page::navIcon('phone') ?><span>What to do now: call them and agree on a price</span></h2>
        <p class="lead-line">Call <b><?= $e($request['contact_name']) ?></b> from <b><?= $e($request['company']) ?></b>
          <a class="btn green" href="tel:<?= $e($request['phone']) ?>" dir="ltr"><?= Page::navIcon('phone') ?><span><?= $e($request['phone']) ?></span></a></p>
        <p>They want: <b><?= $package ? $e(SponsorPackages::name($package)) : 'not sure yet' ?></b><?= $package && $package['price'] > 0 ? ', list price <b>' . Page::money((int) $package['price']) . '</b>' : '' ?>.
          <?= $request['status'] === 'waiting_list' ? '<span class="pill violet">on the waiting list</span>' : '' ?></p>
        <h3>After the call, what happened?</h3>
        <?= $callForm(true) ?>
      <?php elseif ($request['status'] === 'agreed'): ?>
        <h2 class="icon-label"><?= Page::navIcon('payments') ?><span><?= $request['amount_paid'] !== null ? 'Upgrade balance: collect the remaining payment' : 'What to do now: wait for the money, then record it' ?></span></h2>
        <p class="lead-line">Agreed total: <b><?= $agreed ?></b>.<?= $request['amount_paid'] !== null ? ' Already received: <b>' . $money($request['amount_paid']) . '</b>.' : '' ?> Remaining balance: <b class="big-money"><?= Page::money($balance) ?></b>. Record it when received:</p>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pay">
          <input type="hidden" name="amount_paid" value="<?= $balance ?>">
          <div class="choices">
            <?php foreach (Sponsors::PAID_HOW as $key => $label): ?><label class="choice"><input type="radio" name="paid_how" value="<?= $key ?>" required><span><i class="choice-icon"><?= Page::navIcon(['cash' => 'cash', 'transfer' => 'bank', 'psoola' => 'payments', 'other' => 'content'][$key]) ?></i><span class="choice-label"><?= $e($label) ?></span></span></label><?php endforeach; ?>
          </div>
          <div class="form-actions"><button class="btn green big-btn"><?= Page::navIcon('cash') ?><span>They paid <?= Page::money($balance) ?></span></button></div>
        </form>
        <details class="more"><summary>They want to pay a different amount?</summary>
          <p class="muted"><a href="#bookingDetails">Edit details below</a> to update the agreed total, then record the remaining balance.</p>
        </details>
      <?php elseif ($request['status'] === 'paid'): ?>
        <h2 class="icon-label"><?= Page::navIcon('check') ?><span>Last step: confirm them</span></h2>
        <p class="lead-line">They paid <b class="big-money"><?= Page::money((int) $request['amount_paid']) ?></b> (<?= $e(Sponsors::PAID_HOW[$request['paid_how']] ?? '') ?>, <?= Page::when($request['paid_at']) ?>).</p>
        <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="confirmed">
          <?php $spot = $package ? (Sponsors::spots()[$package['id']] ?? null) : null; $full = $spot && $spot['spots'] > 0 && $spot['confirmed'] >= $spot['spots']; ?>
          <?php if ($full): ?><p class="flash warn">All <?= (int) $spot['spots'] ?> <?= $e($spot['name']) ?> places are taken.<?= $isOwner ? ' Tick the box to confirm anyway.' : ' Only the Owner can confirm more.' ?></p>
            <?php if ($isOwner): ?><label class="inline"><input type="checkbox" name="override" value="1"> Confirm anyway (Owner)</label><?php endif; ?><?php endif; ?>
          <div class="form-actions"><button class="btn green big-btn"><?= Page::navIcon('check') ?><span>Confirm <?= $e($request['company']) ?></span></button></div>
        </form>
        <?php if ($isOwner): ?><form method="post" class="form-actions danger-actions"><?= Page::csrfField() ?><input type="hidden" name="do" value="unpay"><button class="btn small ghost" data-confirm="Remove this payment? Only if it was recorded by mistake.">Payment recorded by mistake? Undo it (Owner)</button></form><?php endif; ?>
      <?php elseif ($request['status'] === 'confirmed'): ?>
        <h2 class="icon-label"><?= Page::navIcon('check') ?><span>Done: <?= $e($request['company']) ?> is confirmed</span></h2>
        <p class="lead-line">Paid <b><?= Page::money((int) $request['amount_paid']) ?></b>.</p>
        <ul>
          <?php if ($isBooth): ?><li>Their Standard booth booking is confirmed.</li>
          <?php else: ?><li>Add their <b>logo</b> to the website: Site content → Sponsors & partners → Sponsors with a logo.</li><?php endif; ?>
        </ul>
      <?php elseif($request['status']==='cancelled'): ?>
        <h2 class="icon-label"><?= Page::navIcon('close') ?><span>Booking cancelled</span></h2><p><?= $e($request['cancellation_reason']) ?></p><p class="small muted">Cancelled <?= Page::when($request['cancelled_at']) ?>. Agreed amounts and payment history are retained.</p>
        <?php if($request['amount_paid']===null): ?><form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="new"><button class="btn ghost">Reopen booking</button></form><?php endif; ?>
      <?php else: ?>
        <h2 class="icon-label"><?= Page::navIcon('close') ?><span>Declined</span></h2>
        <p class="lead-line">This company said no, or was declined.</p>
        <form method="post" class="form-actions"><?= Page::csrfField() ?><input type="hidden" name="do" value="status"><input type="hidden" name="status" value="new"><button class="btn">They came back: start again</button></form>
      <?php endif; ?>
    </div>

    <?php if(!in_array($request['status'],['declined','cancelled'],true) && ($request['amount_paid']===null || $isOwner)): ?>
    <section class="card booking-cancel-card" id="cancelBooking"><div><?= Page::panelHeading('Cancel booking','If the company changes its mind, cancel the booking and keep its history.','close') ?></div><form method="post" class="booking-cancel-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="cancel"><label>Cancellation reason<textarea name="reason" required maxlength="500" rows="2" placeholder="For example: company withdrew after agreeing the amount"></textarea></label><button class="btn red" data-confirm="Cancel this booking? Any sponsor map reservation will be released. The record and payment history will be kept.">Cancel booking</button></form></section>
    <?php endif; ?>

    <?php if ($due): ?><div class="flash warn icon-label"><?= Page::navIcon('phone') ?><span>A call is due since <?= Page::when($request['next_call_at']) ?>.</span></div><?php endif; ?>
    <div class="grid2">
      <div class="card">
        <h2 class="icon-label"><?= Page::navIcon('sponsors') ?><span><?= $isBooth ? 'Exhibition booth' : 'Sponsorship' ?>: <?= $package ? $e(SponsorPackages::name($package)) : '<span class="muted">package not chosen yet</span>' ?></span></h2>
        <dl class="facts">
          <dt>Company</dt><dd><b><?= $e($request['company']) ?></b></dd>
          <dt>Contact</dt><dd><?= $e($request['contact_name']) ?><?= $request['contact_role'] ? ', ' . $e($request['contact_role']) : '' ?></dd>
          <dt>Phone</dt><dd dir="ltr"><a href="tel:<?= $e($request['phone']) ?>"><?= $e($request['phone']) ?></a></dd>
          <dt>Email</dt><dd dir="ltr"><a href="mailto:<?= $e($request['email']) ?>"><?= $e($request['email']) ?></a></dd>
          <dt>Website</dt><dd dir="ltr"><?= $e($request['website'] ?? '–') ?></dd>
          <dt>City</dt><dd><?= $e($request['city'] ?? '–') ?></dd>
          <dt>Speaks</dt><dd><?= $e(['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'][$request['lang']] ?? $request['lang']) ?></dd>
          <?php if (!$isBooth && $request['booth_number']): ?><dt>Map position</dt><dd><b><?= $e($request['booth_number']) ?></b></dd><?php endif; ?>
          <dt>Received</dt><dd><?= Page::when($request['created_at']) ?></dd>
          <dt>Price told</dt><dd><?= $money($request['price_quoted']) ?></dd>
          <dt>Agreed</dt><dd><b><?= $money($request['amount_agreed']) ?></b></dd>
          <dt>Paid</dt><dd><?= $request['amount_paid'] !== null ? '<b class="txt-ok">' . $money($request['amount_paid']) . '</b> · ' . $e(Sponsors::PAID_HOW[$request['paid_how']] ?? '') . ', ' . Page::when($request['paid_at']) : 'not paid' ?></dd>
          <dt><?= $request['status']==='cancelled' ? 'Unpaid agreed amount (history)' : 'Remaining balance' ?></dt><dd><b><?= Page::money($balance) ?></b></dd>
        </dl>
        <?php if ($request['message']): ?><h3>Their message</h3><p class="message"><?= nl2br($e($request['message'])) ?></p><?php endif; ?>
      </div>
      <div class="card" id="bookingDetails">
        <h2>Edit booking details</h2>
        <?php if (!$isBooth && $request['booth_number'] && !in_array((int) $request['booth_number'], BoothPlan::numbers($package['booth_tier'] ?? null), true)): ?>
          <p class="flash warn">The existing booth <?= $e($request['booth_number']) ?> does not belong to this package. Choose its matching package and booth number before saving, or choose “No booth reserved” to release it.</p>
        <?php endif; ?>
        <p class="muted"><?= $isBooth?'Standard booth is the only exhibition category. Update the company, agreed price and payment details here.':'Assign a numbered map position to this sponsor. Declined, waiting-list and cancelled bookings do not reserve the map.' ?></p>
        <form method="post" class="stack" id="bookingDetailsForm"<?= !$isBooth?' data-booth-picker':'' ?>><?= Page::csrfField() ?><input type="hidden" name="do" value="details">
          <div class="row3">
            <label>Company name<input name="company" required minlength="2" maxlength="160" value="<?= $e($edit['company']) ?>"></label>
            <label>Contact person<input name="contact" required minlength="2" maxlength="120" value="<?= $e($edit['contact_name']) ?>"></label>
            <label>WhatsApp / phone<input name="phone" type="tel" required maxlength="30" value="<?= $e($edit['phone']) ?>"></label>
          </div>
          <div class="row3">
            <label>Email (optional)<input name="email" type="email" maxlength="190" value="<?= $e($edit['email']) ?>"></label>
            <label>Position (optional)<input name="role" maxlength="120" value="<?= $e($edit['contact_role'] ?? '') ?>"></label>
            <label>City (optional)<input name="city" maxlength="80" value="<?= $e($edit['city'] ?? '') ?>"></label>
          </div>
          <div class="row3">
            <label>Contact language<select name="lang"><?php foreach (['en' => 'English', 'ar' => 'Arabic', 'ku' => 'Kurdish'] as $language => $label): ?><option value="<?= $language ?>"<?= $edit['lang'] === $language ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
            <label>Website / social page (optional)<input name="website" maxlength="190" value="<?= $e($edit['website'] ?? '') ?>"></label>
            <label>Agreed total in IQD<input name="amount_agreed" type="number" min="0" max="1000000000" step="1" inputmode="numeric" value="<?= $e((string) ($edit['amount_agreed'] ?? '')) ?>"<?= $request['amount_agreed'] !== null || $request['amount_paid'] !== null ? ' required' : '' ?>></label>
          </div>
          <?php if($isBooth): ?><label>Booth type<input value="Standard booth" readonly><input type="hidden" name="package_id" value="booth-standard"></label><?php else: ?>
          <p class="muted small">For Gold to Platinum, choose Platinum, choose one of its available map positions, and enter the new agreed total. Money already received stays recorded.</p>
          <label>Package<select name="package_id"><option value="">Not chosen yet</option>
            <?php foreach ($packages as $option): ?><option value="<?= $e($option['id']) ?>" data-booths="<?= $e(json_encode(BoothPlan::numbers($option['booth_tier']))) ?>"<?= $option['id'] === $edit['package_id'] ? ' selected' : '' ?>><?= $e($option['name_en']) ?><?= $option['price'] > 0 ? ' · ' . number_format((int) $option['price']) . ' IQD' : '' ?><?= $option['status'] === 'hidden' ? ' (hidden)' : '' ?></option><?php endforeach; ?></select></label>
          <?php endif; ?>
          <div class="row3">
            <?php if(!$isBooth): ?>
            <label>Reserve booth<select name="booth_number" data-current="<?= $e($request['booth_number'] ?? '') ?>" data-current-active="<?= in_array($request['status'], ['declined','waiting_list','cancelled'], true) ? '0' : '1' ?>" data-booked="<?= $e(json_encode(Sponsors::bookedBooths())) ?>"><option value="">No booth reserved</option>
              <?php $bookedBooths = Sponsors::bookedBooths(); $currentBooth = (string) ($edit['booth_number'] ?? ''); ?>
              <?php if ($currentBooth !== '' && (!ctype_digit($currentBooth) || (int) $currentBooth < 1 || (int) $currentBooth > 44)): ?><option value="<?= $e($currentBooth) ?>" selected><?= $e($currentBooth) ?> (previous label)</option><?php endif; ?>
              <?php foreach (BoothPlan::numbers($editPackage['booth_tier'] ?? null) as $booth): $own = ctype_digit($currentBooth) && (int) $currentBooth === $booth; $taken = in_array($booth, $bookedBooths, true) && ((string) $request['booth_number'] !== (string) $booth || in_array($request['status'], ['declined', 'waiting_list','cancelled'], true)); ?>
                <option value="<?= $booth ?>"<?= $own ? ' selected' : '' ?><?= $taken && !$own ? ' disabled' : '' ?>>Booth <?= $booth ?><?= $taken ? ' — Booked' : '' ?></option>
              <?php endforeach; ?>
            </select></label>
            <?php endif; ?>
            <label>Handled by<select name="assigned_to"><option value="0">Nobody yet</option>
              <?php foreach ($staff as $person): ?><option value="<?= (int) $person['id'] ?>"<?= (int) $edit['assigned_to'] === (int) $person['id'] ? ' selected' : '' ?>><?= $e($person['name']) ?></option><?php endforeach; ?></select></label>
            <label>Next call<input type="datetime-local" name="next_call_at" value="<?= $e($detailsInput !== null ? (string) ($detailsInput['next_call_at'] ?? '') : $inputTime($request['next_call_at'])) ?>"></label>
          </div>
          <label>Notes<textarea name="notes" rows="4" maxlength="5000"><?= $e($edit['notes'] ?? '') ?></textarea></label>
          <div class="form-actions"><button class="btn">Save details</button></div>
          <?php if(!$isBooth): ?><a class="btn ghost" href="<?= $e(App::url('sponsor.html#exhibition')) ?>" target="_blank" rel="noopener">View sponsor map</a><?php endif; ?>
        </form>
      </div>
    </div>
    <?php if($request['status']!=='cancelled'): ?><details class="card more">
      <summary>More actions: save another call, waiting list, decline…</summary>
      <?php if (!in_array($request['status'], ['new', 'contacted', 'waiting_list'], true)): ?><h3>Save a call</h3><?= $callForm(false) ?><?php endif; ?>
      <h3>Move by hand</h3>
      <form method="post" class="status-buttons"><?= Page::csrfField() ?><input type="hidden" name="do" value="status">
        <?php foreach (['new' => 'Back to New', 'contacted' => 'Called', 'waiting_list' => 'Waiting list', 'declined' => 'Declined'] as $status => $label): ?>
          <?php if ($status !== $request['status']): ?><button class="btn small <?= $status==='declined' ? 'red':'ghost' ?>" name="status" value="<?= $status ?>"><?= $e($label) ?></button><?php endif; ?>
        <?php endforeach; ?>
      </form>
      <p class="muted small">Once a company has paid, only the Owner can move it back or decline it (no refunds).</p>
    </details><?php endif; ?>
    <div class="card"><h2 class="icon-label"><?= Page::navIcon('phone') ?><span>Calls (<?= count($calls) ?>)</span></h2><div class="table-wrap"><table>
      <tr><th>When</th><th>Who called</th><th>Result</th><th>Amount told</th><th>Note</th><th>Call again</th></tr>
      <?php foreach ($calls as $call): ?>
      <tr><td><?= Page::when($call['called_at']) ?></td><td><?= $e($call['caller'] ?? '–') ?></td><td><b><?= $e(Sponsors::OUTCOMES[$call['outcome']] ?? $call['outcome']) ?></b></td>
        <td><?= $money($call['amount_quoted']) ?></td><td><?= $e($call['note'] ?? '') ?></td><td><?= $call['next_call_at'] ? Page::when($call['next_call_at']) : '–' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$calls): ?><tr><td colspan="6" class="muted">Nobody has called them yet.</td></tr><?php endif; ?>
    </table></div></div>
    <div class="card"><h2>History</h2><ul class="history">
      <?php foreach ($history as $item): ?><li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'system') ?> · <?= $e($item['action']) ?> <span class="muted small"><?= $e((string) $item['details']) ?></span></li><?php endforeach; ?>
      <?php if (!$history): ?><li class="muted">No changes yet.</li><?php endif; ?>
    </ul></div>
    <?php
    Page::bottom();
    exit;
}

// =========================== the list ===========================
$filter = Page::query('status');
$dueOnly = Page::query('due') === '1';
$where = ['s.kind = ?'];
$params = [$kind];
if ($filter !== '' && in_array($filter, Sponsors::STATUSES, true)) {
    $where[] = 's.status = ?';
    $params[] = $filter;
}
if ($dueOnly) {
    $where[] = "s.next_call_at IS NOT NULL AND s.next_call_at <= ? AND s.status NOT IN ('confirmed','declined','cancelled')";
    $params[] = $now;
}
$rows = Db::all("SELECT s.*, u.name AS handler, p.name_en AS package_name FROM sponsor_requests s
                 LEFT JOIN admin_users u ON u.id = s.assigned_to LEFT JOIN sponsor_packages p ON p.id = s.package_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY FIELD(s.status, 'new', 'contacted', 'agreed', 'paid', 'waiting_list', 'confirmed', 'declined','cancelled'), s.next_call_at IS NULL, s.next_call_at, s.id DESC LIMIT 500", $params);
$kindCounts = [];
foreach (Db::all('SELECT kind, COUNT(*) AS n FROM sponsor_requests GROUP BY kind') as $row) {
    $kindCounts[$row['kind']] = (int) $row['n'];
}
$callsDue = Sponsors::callsDue($kind);
$packages = SponsorPackages::all($kind);
if($kind==='booth') $packages=array_filter($packages,static fn($p)=>$p['id']==='booth-standard');
$stageCounts = array_column(Db::all('SELECT status,COUNT(*) AS n FROM sponsor_requests WHERE kind=? GROUP BY status',[$kind]),'n','status');
$confirmedMoney = (int) Db::value("SELECT COALESCE(SUM(amount_paid),0) FROM sponsor_requests WHERE kind=?",[$kind]);
Page::top($kind==='booth'?'Booths':'Sponsors', $kind==='booth'?'booths':'sponsors', '<a class="btn green" href="'.$pageFile.'?new=1">+ '.($kind==='booth'?'Book Standard booth':'Add sponsor booking').'</a>');
?>
<div class="toolbar tabs kind-tabs">
  <?php if($kind==='sponsor'): ?><a class="btn ghost" target="_blank" rel="noopener" href="<?= $e(App::url('sponsor.html#exhibition')) ?>"><?= Page::navIcon('external') ?>View sponsor map</a><?php else: ?><span class="booth-category-note">One category: Standard booth</span><?php endif; ?>
  <?php if ($callsDue): ?><a class="btn red" href="<?= $pageFile ?>?due=1"><?= Page::navIcon('phone') ?><span>Calls due</span><span class="count"><?= $callsDue ?></span></a><?php endif; ?>
</div>
<?= Page::liveUpdates() ?>
<div data-live-region="booking-overview">
<?= Page::stats([
    [$kind==='booth' ? 'Booth enquiries':'Sponsor enquiries',number_format($kindCounts[$kind] ?? 0),'All requests in this category','sponsors','blue'],
    ['Confirmed partners',number_format((int)($stageCounts['confirmed'] ?? 0)),'Confirmed requests in this category','checkin','green'],
    ['Follow-up calls due',number_format($callsDue),'Calls scheduled for now or earlier','clock','gold'],
    ['Payments received',Page::money($confirmedMoney),'Recorded receipts, including retained history','payments','teal'],
]) ?>
<section class="card partnership-panel">
  <?= Page::panelHeading('Partnership progress','Choose a stage to see the requests that need your next step.','sponsors') ?>
  <nav class="partner-pipeline" aria-label="Partnership stages"><?php foreach (['new'=>'New enquiry','contacted'=>'Contacted','agreed'=>'Agreed','paid'=>'Paid','confirmed'=>'Confirmed'] as $stage=>$label): ?><a class="pipeline-stage<?= $filter===$stage ? ' on':'' ?>"<?= $filter===$stage ? ' aria-current="page"':'' ?> href="<?= $pageFile ?>?status=<?= $stage ?>"><span><?= $e($label) ?></span><b><?= (int)($stageCounts[$stage] ?? 0) ?></b><?= Page::navIcon('arrow') ?></a><?php endforeach; ?></nav>
</section>
<div class="tiles small package-overview">
  <?php foreach ($packages as $package): if ($package['status'] !== 'active') { continue; } $full = $package['places'] > 0 && $package['confirmed'] >= $package['places']; ?>
    <div class="tile <?= $full ? 'red' : 'teal' ?>"><b><?= (int) $package['confirmed'] ?> / <?= $package['places'] > 0 ? (int) $package['places'] : '∞' ?></b><span><?= $e($package['name_en']) ?> confirmed</span><small><?= $package['price'] > 0 ? Page::money((int) $package['price']) : 'price not set' ?></small></div>
  <?php endforeach; ?>
</div>
</div>
<div class="toolbar tabs segmented-tabs">
  <a class="btn small <?= $filter === '' && !$dueOnly ? '' : 'ghost' ?>" href="<?= $pageFile ?>">All</a>
  <?php foreach (Sponsors::STATUSES as $status): ?><a class="btn small <?= $filter === $status ? '' : 'ghost' ?>" href="<?= $pageFile ?>?status=<?= $status ?>"><?= $e(str_replace('_', ' ', ucfirst($status))) ?></a><?php endforeach; ?>
</div>
<section class="card partner-directory" data-live-region="booking-directory"><div class="panel-top"><?= Page::panelHeading($kind==='booth' ? 'Booth enquiries':'Sponsor enquiries','Your contacts, agreed amounts and upcoming calls, in one place.','sponsors') ?><div class="panel-actions"><a class="btn ghost" href="export.php?what=list&amp;list=<?= $kind === 'booth' ? 'exhibition' : 'sponsors' ?>&amp;format=pdf">Download PDF</a><a class="btn green" href="export.php?what=list&amp;list=<?= $kind === 'booth' ? 'exhibition' : 'sponsors' ?>">Export to Excel</a></div></div><div class="table-wrap"><table>
  <tr><th>Company</th><th><?= $kind === 'booth' ? 'Booth' : 'Package' ?></th><th>Status</th><th>Agreed / paid</th><th>Next call</th><th>Handled by</th><th>Received</th><th>Actions</th></tr>
  <?php foreach ($rows as $row): $due = $row['next_call_at'] && $row['next_call_at'] <= $now && !in_array($row['status'], ['confirmed', 'declined','cancelled'], true); ?>
  <tr><td><a href="<?= $pageFile ?>?id=<?= (int) $row['id'] ?>"><b><?= $e($row['company']) ?></b></a><br><small><?= $e($row['contact_name']) ?> · <span dir="ltr"><?= $e($row['phone']) ?></span></small></td>
    <td><?= $row['package_name'] ? $e($row['package_name']) : '<span class="muted">not chosen</span>' ?><?= $kind==='sponsor' && $row['booth_number'] ? '<br><small>Map position ' . $e($row['booth_number']) . '</small>' : '' ?></td>
    <td><?= Page::pill($row['status']) ?></td>
    <td><?= $money($row['amount_agreed']) ?><?= $row['amount_paid'] !== null ? '<br><small class="txt-ok">paid ' . $money($row['amount_paid']) . '</small>' : '' ?><?= $row['status'] !== 'cancelled' && $row['amount_paid'] !== null && (int) $row['amount_agreed'] > (int) $row['amount_paid'] ? '<br><small>balance ' . $money((int) $row['amount_agreed'] - (int) $row['amount_paid']) . '</small>' : '' ?></td>
    <td><?= $row['next_call_at'] ? ($due ? '<b class="txt-bad icon-label">' . Page::navIcon('phone') . '<span>' . Page::when($row['next_call_at']) . '</span></b>' : Page::when($row['next_call_at'])) : '–' ?></td>
    <td><?= $e($row['handler'] ?? '–') ?></td><td><?= Page::when($row['created_at']) ?></td><td><div class="booking-row-actions"><a class="btn small ghost" href="<?= $pageFile ?>?id=<?= (int) $row['id'] ?>#bookingDetails">Edit details</a><?php if(!in_array($row['status'],['declined','cancelled'],true) && ($row['amount_paid']===null || $isOwner)): ?><a class="btn small ghost booking-cancel-link" href="<?= $pageFile ?>?id=<?= (int)$row['id'] ?>#cancelBooking">Cancel booking</a><?php endif; ?></div></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted"><?= Page::emptyState('No bookings in this view','Use the booking button above to add a company, or receive a request from the website.','sponsors') ?></td></tr><?php endif; ?>
</table></div></section>

<?php if ($isOwner):
    $fieldsFor = static function (?array $p) use ($e,$kind): string {
        $v = static fn (string $key): string => $e((string) ($p[$key] ?? ''));
        if($kind==='booth') return '<input type="hidden" name="name_en" value="Standard booth"><div class="row3"><label>Booth type<input value="Standard booth" readonly></label><label>Price (IQD)<input name="price" inputmode="numeric" value="'.($p && $p['price']>0?(int)$p['price']:'').'"></label><label>Places (0 = no limit)<input type="number" min="0" max="500" name="places" value="'.(int)($p['places']??0).'"></label></div>';
        $styles = '';
        foreach (SponsorPackages::STYLES as $key => $label) {
            $styles .= '<option value="' . $key . '"' . (($p['style'] ?? 'tc-silver') === $key ? ' selected' : '') . '>' . $e($label) . '</option>';
        }
        $tierOptions = '<option value="">No numbered booth allocation</option>';
        foreach (BoothPlan::TIERS as $tier => $name) {
            $tierOptions .= '<option value="' . $tier . '"' . (($p['booth_tier'] ?? '') === $tier ? ' selected' : '') . '>' . $name . '</option>';
        }
        return '<div class="row3">'
            . '<label>Name (English)<input name="name_en" required maxlength="80" value="' . $v('name_en') . '"></label>'
            . '<label>Name (Arabic)<input name="name_ar" dir="rtl" maxlength="80" value="' . $v('name_ar') . '"></label>'
            . '<label>Name (Kurdish)<input name="name_ku" dir="rtl" maxlength="80" value="' . $v('name_ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Subtitle (English, optional)<input name="subtitle_en" maxlength="160" value="' . $v('subtitle_en') . '"></label>'
            . '<label>Subtitle (Arabic)<input name="subtitle_ar" dir="rtl" maxlength="160" value="' . $v('subtitle_ar') . '"></label>'
            . '<label>Subtitle (Kurdish)<input name="subtitle_ku" dir="rtl" maxlength="160" value="' . $v('subtitle_ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Price (IQD; empty = not set yet)<input name="price" inputmode="numeric" placeholder="e.g. 5000000" value="' . ($p && $p['price'] > 0 ? (int) $p['price'] : '') . '"></label>'
            . '<label>Places (empty = no limit)<input type="number" min="0" max="500" name="places" placeholder="no limit" value="' . ($p && $p['places'] > 0 ? (int) $p['places'] : '') . '"></label>'
            . '<label>Colour on the website<select name="style">' . $styles . '</select></label></div>'
            . '<label>Floor-plan tier<select name="booth_tier">' . $tierOptions . '</select></label>';
    }; ?>
<div class="card" id="packages">
  <?= Page::panelHeading($kind==='booth' ? 'Standard booth price & availability':'Sponsor packages & prices','Manage availability and the offers your team shares with companies. Owner access.','settings') ?>
  <p class="muted">The price is what the team tells companies on the phone; it is never shown on the website. <?= $kind === 'sponsor' ? 'The website\'s sponsor cards (name, subtitle, places, colour) change at once.' : 'All exhibition bookings use this one Standard booth type. Sponsorship tiers and the map belong to Sponsors.' ?></p>
  <div class="table-wrap"><table>
    <tr><th>Package</th><th>Price</th><th>Places</th><th>Confirmed</th><th>Shown</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($packages as $p): ?>
    <tr>
      <td><b><?= $e($p['name_en']) ?></b><br><small class="muted"><?= $e($p['id']) ?><?= $p['subtitle_en'] ? ' · ' . $e($p['subtitle_en']) : '' ?></small></td>
      <td><?= $p['price'] > 0 ? Page::money((int) $p['price']) : '<span class="txt-bad">not set</span>' ?></td>
      <td><?= $p['places'] > 0 ? (int) $p['places'] : 'no limit' ?></td>
      <td><?= (int) $p['confirmed'] ?> <small class="muted">· <?= (int) $p['requests'] ?> asked</small></td>
      <td><?= $p['status'] === 'active' ? '<span class="pill green">Shown</span>' : '<span class="pill grey">Hidden</span>' ?></td>
      <td class="table-action-cell"><div class="row-actions">
        <details class="edit"><summary class="btn small ghost">Edit</summary>
          <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pk_update"><input type="hidden" name="package" value="<?= $e($p['id']) ?>">
            <?= $fieldsFor($p) ?><div class="form-actions"><button class="btn">Save package</button></div></form>
        </details>
        <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="package" value="<?= $e($p['id']) ?>">
          <?php if ($p['status'] === 'active'): ?><button class="btn small gold" name="do" value="pk_hide" data-confirm="Hide this package? It leaves the website and the form. Companies already on it stay.">Hide</button>
          <?php else: ?><button class="btn small green" name="do" value="pk_show">Show again</button><?php endif; ?>
          <?php if ((int) $p['requests'] === 0): ?><div class="row-danger-actions"><button class="btn small red" name="do" value="pk_delete" data-confirm="Delete this package for good?">Delete</button></div><?php endif; ?>
        </form>
      </div></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$packages): ?><tr><td colspan="6" class="muted">No packages yet.</td></tr><?php endif; ?>
  </table></div>
  <?php if($kind==='sponsor' || !$packages): ?><details class="add-new" <?= $packages ? '' : 'open' ?>>
    <summary class="btn green">+ Add a <?= $kind === 'booth' ? 'booth type' : 'sponsor package' ?></summary>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="pk_create"><input type="hidden" name="kind" value="<?= $kind ?>">
      <?= $fieldsFor(null) ?>
      <div class="form-actions"><button class="btn green">Add package</button></div>
    </form>
  </details><?php endif; ?>
</div>
<?php endif; ?>
<?php Page::bottom();
