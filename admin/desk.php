<?php
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\UserError;
use Ismile\WorkshopCashSale;
use Ismile\WorkshopCatalog;
use Ismile\Workshops;

// Workshop & Register: the cash desk for volunteers. Two tabs:
//   Register  - a summit ticket for cash (the same sale as Registrations > Register).
//   Workshop  - a workshop for cash, for someone who already has a summit ticket.
// Every sale goes to PSOOLA, and PSOOLA emails the ticket to the person.
$user = Page::guard('desk');
$canRegister = Auth::canDeskRegister($user);
$canWorkshop = Auth::canDeskWorkshop($user);
$tab = Page::query('tab') === 'workshop' && $canWorkshop ? 'workshop' : ($canRegister ? 'register' : 'workshop');
$e = [Page::class, 'e'];
$deskUrl = static fn (string $tab, array $more = []): string => 'desk.php?' . http_build_query(['tab' => $tab] + $more);

// ---------- the two sales ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    try {
        Auth::checkCsrf();
        if ($do === 'cash') {
            if (!$canRegister) throw new UserError('Your account cannot register people.', null, 403);
            Page::useOnce();
            $checkout = \Ismile\Checkouts::createForCash($_POST, $user, null, false);   // no student card photo at the desk
            $sale = \Ismile\Payments\Payments::sellForCash($checkout, $user);
            $price = number_format($sale['amount']) . ' ' . $sale['currency'];
            if ($sale['status'] === 'paid' && $sale['registration_id']) {
                Page::flash('ok', 'Done! Collect ' . $price . '. ' . Registrations::fullName($checkout) . ' is registered (' . $checkout['ref'] . '). Psoola is emailing the ticket with the QR to ' . $checkout['email'] . '.');
                Page::redirect($deskUrl('register', ['done' => (int) $sale['registration_id']]));
            }
            Page::flash('ok', 'Psoola made the sale (' . $price . '). Collect this amount. The registration of ' . $checkout['ref'] . ' finishes within 5 minutes.');
            Page::redirect($deskUrl('register'));
        }
        if ($do === 'workshop_cash_sale') {
            if (!$canWorkshop) throw new UserError('Your account cannot register workshops.', null, 403);
            Page::useOnce();
            $person = Registrations::find((int) ($_POST['rid'] ?? 0));
            if (!$person || $person['status'] !== 'paid') throw new UserError('This person has no paid summit ticket. Register them first in the Register tab.');
            // The amount is PSOOLA's current price for the chosen currency; the volunteer confirms receiving it.
            $in = ['workshop' => (string) ($_POST['workshop'] ?? ''), 'cash_received' => (string) ($_POST['cash_received'] ?? ''), 'cash_currency' => 'IQD'];
            $sold = Workshops::find($in['workshop']);
            if (!$sold || $sold['organizer'] !== 'ismile') throw new UserError('Choose an iSmile workshop.');
            $quote = WorkshopCashSale::quote($sold, WorkshopCatalog::one($sold['id'], true));
            if ($quote['iqd'] === 0) {
                throw new UserError('Free workshops are not registered here. Ask the team.');
            } elseif (($_POST['cash_currency'] ?? 'IQD') === 'USD' && $quote['usdMinor']) {
                $in = ['cash_currency' => 'USD', 'cash_amount' => number_format($quote['usdMinor'] / 100, 2, '.', '')] + $in;
            } else {
                $in['cash_amount'] = (string) $quote['iqd'];
            }
            $message = WorkshopCashSale::register($person, $user, $in);
            Page::flash('ok', 'Done! ' . Registrations::fullName($person) . ': ' . $message);
            Page::redirect($deskUrl('workshop', ['ws' => $in['workshop']]));
        }
        throw new UserError('Choose what to do.');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (\Ismile\Payments\GatewayNotReady $error) {
        \Ismile\App::log('error', 'Workshop & Register sale failed', ['error' => $error->getMessage()]);
        Page::flash('error', 'Psoola is not ready, so nothing was sold. Do not take the money. Try again in a minute or ask the team.');
    }
}

// ---------- Workshop tab: every iSmile workshop, then the person ----------
// All active iSmile workshops, each with PSOOLA's current price and seats.
// Company workshops are booked by phone with the company, so they are not here.
$workshops = [];
if ($tab === 'workshop') {
    foreach (Workshops::all() as $w) {
        if ($w['organizer'] !== 'ismile') continue;
        $linked = !empty($w['providerEventId']) && $w['currency'] === 'IQD';
        $catalog = $linked ? WorkshopCatalog::one($w['id']) : null;
        $quote = null;
        if ($linked) { try { $quote = WorkshopCashSale::quote($w, $catalog); } catch (UserError) {} }
        // No free workshops at the desk: volunteers only take cash.
        if (($quote && $quote['iqd'] === 0) || (!$quote && (int) $w['price'] === 0)) continue;
        $seats = (int) ($catalog['seatsLeft'] ?? 0);
        $workshops[$w['id']] = ['w' => $w, 'quote' => $quote, 'seats' => $seats,
            'blocked' => !$linked ? 'Not on sale through Psoola. Ask the team.' : (!$quote ? 'Price not available from Psoola right now. Try again in a minute.' : ($seats < 1 ? 'Sold out.' : ''))];
    }
}
$ws = $workshops[Page::query('ws')] ?? null;
$search = mb_substr(trim(Page::query('q', 100)), 0, 100);
$found = [];
if ($tab === 'workshop' && mb_strlen($search) >= 2) {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $digits = preg_replace('/\D+/', '', $search);
    $found = Db::all("SELECT * FROM registrations WHERE status = 'paid' AND (CONCAT_WS(' ', first_name, father_name, grandfather_name) LIKE ? OR ref LIKE ?"
        . (strlen((string) $digits) >= 4 ? ' OR phone LIKE ?' : '') . ') ORDER BY first_name, father_name LIMIT 10',
        strlen((string) $digits) >= 4 ? [$like, $like, '%' . substr((string) $digits, -9) . '%'] : [$like, $like]);
}
$person = null;
if ($tab === 'workshop' && (int) Page::query('rid') > 0) {
    $person = Registrations::find((int) Page::query('rid'));
    if ($person && $person['status'] !== 'paid') $person = null;
}
$has = $ws && $person && (bool) Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$person['id'], $ws['w']['id']]);
$money = static fn (array $quote): string => number_format($quote['iqd']) . ' IQD';
$trainer = static function (array $w): string {
    $speaker = $w['speaker'] ?? null;
    return is_array($speaker) ? (string) ($speaker['en'] ?? '') : (string) $speaker;
};
$masked = static fn (string $phone): string => '•••• ' . substr(preg_replace('/\D+/', '', $phone), -4);
$when = static function (array $w): string {
    $parts = array_filter([$w['scheduledDate'] ? date('D j M', strtotime((string) $w['scheduledDate'])) : '', trim(($w['startTime'] ?? '') . ($w['endTime'] ? '–' . $w['endTime'] : ''))]);
    return implode(' · ', $parts);
};

Page::top('Workshop & Register', 'desk');
?>
<nav class="checkin-tabs ticket-tabs no-print desk-tabs" aria-label="Workshop and Register">
  <?php foreach (['register' => ['Register', 'registrations', $canRegister], 'workshop' => ['Workshop', 'workshops', $canWorkshop]] as $key => [$label, $icon, $allowed]): if (!$allowed) continue; ?>
  <a class="checkin-tab<?= $tab === $key ? ' on' : '' ?>" href="<?= $e($deskUrl($key)) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= Page::navIcon($icon) ?><span><?= $e($label) ?></span></a>
  <?php endforeach; ?>
</nav>


<?php if ($tab === 'register'):
    $value = static fn (string $key): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
    $selected = static fn (string $key, string $option): string => ($_POST[$key] ?? '') === $option ? ' selected' : '';
    $vip = ($_POST['ticket'] ?? '') === 'vip';
    $student = !$vip && (($_POST['specialty'] ?? '') === 'student' || ($_POST['ticket'] ?? '') === 'student');
    $psoola = \Ismile\Payments\PsoolaPrices::get();
    $site = \Ismile\SiteData::prices();
    $label = static fn (int $amount, string $currency): string => $amount <= 0 ? '–' : ($currency === 'USD' ? '$' . number_format($amount) : number_format($amount) . ' ' . $currency);
    $shown = [
        'professional' => $psoola ? $label($psoola['ticketTypes']['professional'], $psoola['currency']) : $label($site['professional'], $site['currency']),
        'student'      => $psoola ? $label($psoola['ticketTypes']['student'], $psoola['currency']) : $label($site['student'], $site['currency']),
        'vip'          => $psoola ? $label($psoola['ticketTypes']['vip'], $psoola['currency']) : $label($site['vip'], $site['vipCurrency']),
        'lunch1'       => $psoola ? $label($psoola['lunch']['day1'], $psoola['currency']) : $label($site['lunchDay1'], $site['lunchCurrency']),
        'lunch2'       => $psoola ? $label($psoola['lunch']['day2'], $psoola['currency']) : $label($site['lunchDay2'], $site['lunchCurrency']),
    ];
    $priceData = $psoola ? json_encode(['currency' => $psoola['currency'], 'professional' => $psoola['ticketTypes']['professional'], 'student' => $psoola['ticketTypes']['student'],
        'vip' => $psoola['ticketTypes']['vip'], 'lunch1' => $psoola['lunch']['day1'], 'lunch2' => $psoola['lunch']['day2']]) : '';
    $done = (int) Page::query('done') > 0 ? Registrations::find((int) Page::query('done')) : null;
?>
<?php if ($done && $canWorkshop): ?>
<section class="card desk-next">
  <p><b><?= $e(Registrations::fullName($done)) ?></b> is registered. Do they also want a workshop?</p>
  <a class="btn green" href="<?= $e($deskUrl('workshop', ['rid' => (int) $done['id']])) ?>">Yes, add a workshop</a>
  <a class="btn ghost" href="<?= $e($deskUrl('register')) ?>">No, register the next person</a>
</section>
<?php endif; ?>
<div class="cash-layout">
<div class="cash-main">
<form method="post" action="<?= $e($deskUrl('register')) ?>" class="card stack caller-form" id="caller-form" enctype="multipart/form-data" data-prices="<?= $e((string) $priceData) ?>">
  <?= Page::csrfField() ?><?= Page::onceField() ?>
  <fieldset class="form-section">
    <legend>1. Person's details</legend>
    <div class="row3"><label>First name *<input name="first_name" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="off" required></label><label>Second name *<input name="father_name" maxlength="60" value="<?= $value('father_name') ?>" autocomplete="off" required></label><label>Last name *<input name="grandfather_name" maxlength="60" value="<?= $value('grandfather_name') ?>" autocomplete="off" required></label></div>
    <div class="row3"><label>Phone *<input type="tel" name="phone" dir="ltr" autocomplete="off" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email * <small>(the ticket goes here)</small><input type="email" name="email" dir="ltr" autocomplete="off" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<?= \Ismile\Cities::select($_POST['city'] ?? '') ?></label></div>
  </fieldset>
  <fieldset class="form-section">
    <legend>2. Specialty &amp; ticket</legend>
    <div class="row3">
      <label>Specialty *<select name="specialty" id="caller-specialty" required>
        <option value="">Choose specialty</option>
        <?php foreach (Registrations::SPECIALTY_NAMES as $key => $name): ?><option value="<?= $e($key) ?>"<?= $selected('specialty', $key) ?>><?= $e($name) ?></option><?php endforeach; ?>
      </select></label>
      <label>Ticket *<select name="ticket" id="caller-ticket"><option value="professional"<?= !$student && !$vip ? ' selected' : '' ?>>Dentist</option><option value="student"<?= $student ? ' selected' : '' ?>>Student</option><option value="vip"<?= $vip ? ' selected' : '' ?>>VIP</option></select></label>
    </div>
    <div class="row3" id="caller-student-fields">
      <label>University *<input name="university" id="caller-university" maxlength="160" value="<?= $value('university') ?>"<?= $student ? ' required' : '' ?>></label>
      <label>Stage *<select name="stage" id="caller-stage"<?= $student ? ' required' : '' ?>><option value="">Choose the stage</option><?php foreach (range(\Ismile\Checkouts::FIRST_STUDENT_STAGE, \Ismile\Checkouts::LAST_STUDENT_STAGE) as $stage): ?><option value="<?= $stage ?>"<?= $selected('stage', (string) $stage) ?>><?= $e(Registrations::stageName($stage)) ?></option><?php endforeach; ?></select></label>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend>3. Lunch <span class="legend-note">· optional</span></legend>
    <label id="caller-vip-lunch"<?= !$vip ? ' hidden' : '' ?>>VIP included lunch<select name="vip_lunch_day"<?= !$vip ? ' disabled' : '' ?>><option value="1"<?= ($_POST['vip_lunch_day'] ?? '1') === '1' ? ' selected' : '' ?>>Day 1 · 19 November</option><option value="2"<?= ($_POST['vip_lunch_day'] ?? '') === '2' ? ' selected' : '' ?>>Day 2 · 20 November</option></select></label>
    <?php if (\Ismile\VipPackages::enabled()): ?><label id="caller-vip-workshop"<?= !$vip ? ' hidden' : '' ?>>VIP included workshop<select name="vip_workshop"<?= !$vip ? ' disabled' : '' ?> required><option value="">Choose one included workshop</option><?php foreach (\Ismile\VipPackages::WORKSHOPS as $workshopId): $vipWorkshop = Workshops::find($workshopId); if (!$vipWorkshop) continue; ?><option value="<?= $e($workshopId) ?>"<?= $selected('vip_workshop', $workshopId) ?>><?= $e(Workshops::name($vipWorkshop)) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <div class="lunch-cards">
      <?php foreach ([1 => 'Thursday 19 Nov', 2 => 'Friday 20 Nov'] as $day => $date): ?>
      <label class="lunch-card"><input type="checkbox" name="lunch_day<?= $day ?>" value="1"<?= ($_POST['lunch_day' . $day] ?? '') === '1' ? ' checked' : '' ?>>
        <span class="lunch-card-text"><b>Lunch day <?= $day ?></b><small><?= $e($date) ?></small></span>
        <span class="lunch-card-price"><b data-lunch-price="<?= $day ?>"><?= $e($shown['lunch' . $day]) ?></b></span>
      </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <div class="form-actions"><button class="btn cash-pay desk-big" name="do" value="cash" id="caller-pay">Paid by cash</button></div>
</form>
<dialog class="cash-confirm" id="cash-confirm" aria-labelledby="cash-confirm-title">
  <h2 id="cash-confirm-title">Did you receive the cash?</h2>
  <p class="cash-confirm-sub">Check the details with the person before you sell. This cannot be undone.</p>
  <dl class="cash-confirm-details">
    <div><dt>Person</dt><dd data-confirm-field="name"></dd></div>
    <div><dt>Phone</dt><dd data-confirm-field="phone" dir="ltr"></dd></div>
    <div><dt>Ticket email</dt><dd data-confirm-field="email" dir="ltr"></dd></div>
    <div><dt>Ticket</dt><dd data-confirm-field="ticket"></dd></div>
    <div><dt>Lunch</dt><dd data-confirm-field="lunch"></dd></div>
  </dl>
  <div class="cash-confirm-amount"><span>Collect in cash</span><b data-confirm-field="total"></b></div>
  <label class="cash-confirm-check"><input type="checkbox" id="cash-confirm-received"><span data-confirm-field="received">I have received the cash from this person.</span></label>
  <p class="cash-confirm-info">Psoola issues the ticket at once and emails the QR to the email above.</p>
  <div class="cash-confirm-actions">
    <button type="button" class="btn ghost" data-confirm-back>No, go back</button>
    <button type="button" class="btn cash-pay" id="cash-confirm-yes" disabled>Yes, sell the ticket</button>
  </div>
</dialog>
</div>
<aside class="cash-panel" aria-label="Prices and total">
  <section class="card cash-prices">
    <div class="cash-prices-head"><h2>Prices</h2></div>
    <?php foreach ([['professional', 'Dentist', 'Professionals', 'teal'], ['student', 'Student', 'Every stage, 1st to 5th', 'blue'], ['vip', 'VIP', 'One lunch included', 'gold']] as [$key, $name, $sub, $tone]): ?>
    <button type="button" class="cash-package tone-<?= $tone ?>" data-pick-ticket="<?= $key ?>" aria-pressed="false">
      <span class="cash-package-text"><b><?= $e($name) ?> <em>Selected</em></b><small><?= $e($sub) ?></small></span>
      <strong><?= $e($shown[$key]) ?></strong>
    </button>
    <?php endforeach; ?>
  </section>
  <section class="card cash-total" aria-live="polite">
    <p class="cash-total-label">This sale</p>
    <ul class="cash-lines" id="cash-lines"></ul>
    <p class="cash-total-label">Total to collect</p>
    <p class="cash-total-amount<?= $psoola ? '' : ' is-waiting' ?>" id="cash-total"><?= $psoola ? '–' : 'After the sale' ?></p>
  </section>
</aside>
</div>

<?php else: ?>
<section class="card stack">
  <?= Page::panelHeading('iSmile workshops', 'Press Register (cash) on the workshop the person wants. The payment to collect is shown on each workshop.', 'workshops') ?>
  <?php if (!$workshops): ?><?= Page::emptyState('No iSmile workshops', 'No iSmile workshop is active right now.', 'workshops') ?><?php endif; ?>
  <div class="desk-workshops">
  <?php foreach ($workshops as $id => ['w' => $w, 'quote' => $quote, 'seats' => $seats, 'blocked' => $blocked]): $on = $ws && $ws['w']['id'] === $id; ?>
    <article class="desk-ws<?= $blocked !== '' ? ' is-blocked' : '' ?><?= $on ? ' is-on' : '' ?>">
      <h3><?= $e(Workshops::name($w)) ?></h3>
      <?php if ($trainer($w) !== ''): ?><p class="desk-trainer"><?= $e($trainer($w)) ?></p><?php endif; ?>
      <p class="muted small"><?= $e(implode(' · ', array_filter([$when($w), $quote ? $seats . ' seat' . ($seats === 1 ? '' : 's') . ' left' : '']))) ?></p>
      <div class="desk-pay"><span>Payment</span><b><?= $quote ? $e($money($quote)) : '–' ?></b><?php if ($quote && $quote['usdMinor']): ?><small>or $<?= $e(rtrim(rtrim(number_format($quote['usdMinor'] / 100, 2), '0'), '.')) ?></small><?php endif; ?></div>
      <?php if ($blocked !== ''): ?>
        <p class="desk-blocked"><?= $e($blocked) ?></p>
      <?php else: ?>
        <a class="btn green desk-big" href="<?= $e($deskUrl('workshop', array_filter(['ws' => $id, 'rid' => $person ? (int) $person['id'] : null]))) ?>#step2">Register (cash)</a>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
  <p class="muted small">Company workshops are not sold here: the person calls the company.</p>
</section>

<?php if ($ws && $ws['blocked'] === ''): $quote = $ws['quote']; $wid = $ws['w']['id']; ?>
<section class="card stack desk-find" id="step2">
  <?= Page::panelHeading('Register for: ' . Workshops::name($ws['w']), 'Find the person. They must already have a summit ticket. Search by name, phone or reference.', 'search') ?>
  <form method="get" action="desk.php#step2" class="desk-search" role="search">
    <input type="hidden" name="tab" value="workshop"><input type="hidden" name="ws" value="<?= $e($wid) ?>">
    <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Name, phone or reference…" aria-label="Find the person" autocomplete="off" minlength="2" required>
    <button class="btn">Search</button>
  </form>
  <?php if ($search !== '' && !$found): ?>
    <p class="desk-empty">Nobody with a paid summit ticket matches “<?= $e($search) ?>”. Check the spelling, or <a href="<?= $e($deskUrl('register')) ?>"><b>register them first</b></a> in the Register tab.</p>
  <?php elseif ($found): ?>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Reference</th><th>Phone</th><th>Ticket</th><th></th></tr></thead><tbody>
    <?php foreach ($found as $row): ?><tr<?= $person && (int) $person['id'] === (int) $row['id'] ? ' class="is-on"' : '' ?>>
      <td><b><?= $e(Registrations::fullName($row)) ?></b></td><td><code><?= $e($row['ref']) ?></code></td><td dir="ltr"><?= $e($masked((string) $row['phone'])) ?></td>
      <td><?= $e(Registrations::ticketName((string) $row['ticket_type'])) ?></td>
      <td><a class="btn small green" href="<?= $e($deskUrl('workshop', ['ws' => $wid, 'q' => $search, 'rid' => (int) $row['id']])) ?>#step3">Choose</a></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<?php if ($person): ?>
<section class="card stack desk-confirm" id="step3">
  <?= Page::panelHeading('Take the payment', Registrations::fullName($person) . ' (' . $person['ref'] . ', ' . $masked((string) $person['phone']) . '). Psoola emails the workshop ticket to ' . $person['email'] . '.', 'workshops') ?>
  <div class="desk-pay desk-pay-big"><span><?= $e(Workshops::name($ws['w'])) ?></span><b><?= $e($money($quote)) ?></b><?php if ($quote['usdMinor']): ?><small>or $<?= $e(rtrim(rtrim(number_format($quote['usdMinor'] / 100, 2), '0'), '.')) ?></small><?php endif; ?></div>
  <?php if ($has): ?>
    <p class="desk-blocked">Already registered for this workshop.</p>
  <?php else: ?>
  <form method="post" action="<?= $e($deskUrl('workshop', ['ws' => $wid, 'q' => $search, 'rid' => (int) $person['id']])) ?>#step3" class="stack">
    <?= Page::csrfField() ?><?= Page::onceField() ?>
    <input type="hidden" name="do" value="workshop_cash_sale"><input type="hidden" name="rid" value="<?= (int) $person['id'] ?>"><input type="hidden" name="workshop" value="<?= $e($wid) ?>">
      <label class="inline desk-received"><input type="checkbox" name="cash_received" value="1" required> I have received the cash for this workshop.</label>
      <div class="form-actions">
        <button class="btn green desk-big" name="cash_currency" value="IQD">Received <?= $e(number_format($quote['iqd'])) ?> IQD · Register</button>
        <?php if ($quote['usdMinor']): ?><button class="btn green desk-big" name="cash_currency" value="USD">Received $<?= $e(rtrim(rtrim(number_format($quote['usdMinor'] / 100, 2), '0'), '.')) ?> · Register</button><?php endif; ?>
      </div>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif;
Page::bottom();
