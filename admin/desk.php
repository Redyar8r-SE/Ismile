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
            // Gender and age are asked here, as on the website.
            if (!in_array($_POST['gender'] ?? '', Registrations::GENDERS, true)) throw new UserError('Choose the gender.');
            if (trim((string) ($_POST['age'] ?? '')) === '') throw new UserError('Enter the age.');
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
            if (!$person || !in_array($person['status'], ['paid', 'complimentary'], true)) throw new UserError('This person has no active summit ticket. Register them first in the Register tab.');
            // The amount is PSOOLA's current price for the chosen currency; the volunteer confirms receiving it.
            $in = ['workshop' => (string) ($_POST['workshop'] ?? ''), 'cash_received' => (string) ($_POST['cash_received'] ?? ''), 'cash_currency' => 'IQD'];
            $sold = Workshops::find($in['workshop']);
            if (!$sold || $sold['organizer'] !== 'ismile') throw new UserError('Choose an iSmile workshop.');
            $quote = WorkshopCashSale::quote($sold, WorkshopCatalog::one($sold['id'], true));
            if ($quote['iqd'] === 0) {
                $in = ['free_registration' => '1', 'cash_amount' => '0', 'cash_currency' => 'IQD'] + $in;   // a free workshop: no cash
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
        // Every iSmile workshop is shown (full ones too). Free ones are registered with no cash.
        $free = ($quote && $quote['iqd'] === 0) || (!$quote && (int) $w['price'] === 0);
        $seats = (int) ($catalog['seatsLeft'] ?? 0);
        $workshops[$w['id']] = ['w' => $w, 'quote' => $quote, 'seats' => $seats, 'free' => $free,
            'blocked' => !$linked ? 'Not on sale through Psoola. Ask the team.' : (!$quote ? 'Price not available from Psoola right now. Try again in a minute.' : ($seats < 1 ? 'Sold out.' : ''))];
    }
}
$ws = $workshops[Page::query('ws')] ?? null;
// Finding the person: ONE box for name, WhatsApp number or email.
// A name is found letter by letter, by position: the 1st word is the start of the
// FIRST name ("r", "re", "redyar"), after a space the 2nd word is the start of the
// SECOND name ("redyar n"), and a 3rd word the start of the last name.
// Digits find the phone (any format); text with "@" finds the email.
// Paid and guest summit tickets can be chosen; cancelled ones show the reason.
$findPeople = static function (string $q): array {
    $q = trim(preg_replace('/\s+/', ' ', $q));
    if ($q === '') return [];
    $lower = mb_strtolower($q);
    $like = static fn (string $text): string => addcslashes($text, '%_\\');
    if (str_contains($q, '@') || str_contains($q, '.')) {   // an email, even partly typed
        $where = 'LOWER(email) LIKE ?';
        $args = ['%' . $like($lower) . '%'];
    } elseif (preg_match('/^[\d\s+()\-]+$/', $q)) {
        $digits = (string) preg_replace('/^(00964|964|0)/', '', (string) preg_replace('/\D+/', '', $q));   // 0750…, +964 750…, 750… are the same number
        if ($digits === '') return [];
        $where = 'phone LIKE ?';
        $args = ['%' . $digits . '%'];
    } else {
        $parts = ['first_name', 'father_name', 'grandfather_name'];
        $conditions = [];
        $args = [];
        foreach (array_slice(explode(' ', $lower), 0, 3) as $i => $word) {
            $conditions[] = 'LOWER(' . $parts[$i] . ') LIKE ?';
            $args[] = $like($word) . '%';
        }
        $where = implode(' AND ', $conditions);
    }
    return Db::all("SELECT * FROM registrations WHERE status IN ('paid','complimentary','cancelled') AND ($where)
        ORDER BY status = 'cancelled', first_name, father_name, grandfather_name LIMIT 1000", $args);   // everyone who matches, A to Z
};
$findQ = mb_substr(trim(Page::query('q', 120)), 0, 120);
$searched = $findQ !== '';
$found = $tab === 'workshop' && $searched ? $findPeople($findQ) : [];
$keep = $findQ !== '' ? ['q' => $findQ] : [];
$whyNot = static fn (array $r): string => match ($r['status']) {
    'cancelled' => 'Summit ticket cancelled. Ask the team.',
    default => '',
};
// Live results while typing (the page's own script asks this; same access as the page).
if ($tab === 'workshop' && Page::query('live') === '1') {
    $wsId = Page::query('ws');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['q' => $findQ, 'short' => $findQ === '', 'count' => count($found), 'people' => array_map(static fn (array $r): array => [
        'name' => Registrations::fullName($r), 'email' => (string) $r['email'], 'phone' => 'ends in ' . substr((string) preg_replace('/\D+/', '', (string) $r['phone']), -4),
        'ticket' => Registrations::ticketName((string) $r['ticket_type']), 'guest' => $r['status'] === 'complimentary', 'why' => $whyNot($r),
        'url' => $whyNot($r) === '' ? 'desk.php?' . http_build_query(['tab' => 'workshop', 'ws' => $wsId, 'q' => $findQ, 'rid' => (int) $r['id']]) . '#pay' : null,
    ], $found)], JSON_UNESCAPED_UNICODE);
    exit;
}
$person = null;
if ($tab === 'workshop' && (int) Page::query('rid') > 0) {
    $person = Registrations::find((int) Page::query('rid'));
    if ($person && !in_array($person['status'], ['paid', 'complimentary'], true)) $person = null;   // any active summit ticket: paid (Dentist, Student, VIP) or guest
}
$has = $ws && $person && (bool) Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$person['id'], $ws['w']['id']]);
$money = static fn (array $quote): string => number_format($quote['iqd']) . ' IQD';
$trainer = static function (array $w): string {
    $speaker = $w['speaker'] ?? null;
    return is_array($speaker) ? (string) ($speaker['en'] ?? '') : (string) $speaker;
};
$localPhone = static function (string $phone): string {
    $digits = (string) preg_replace('/\D+/', '', $phone);
    if (str_starts_with($digits, '964') && strlen($digits) === 13) $digits = '0' . substr($digits, 3);
    return strlen($digits) === 11 ? substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7) : $phone;
};
$masked = static fn (string $phone): string => 'ends in ' . substr(preg_replace('/\D+/', '', $phone), -4);
$when = static function (array $w): string {
    $parts = array_filter([$w['scheduledDate'] ? date('D j M', strtotime((string) $w['scheduledDate'])) : '', trim(($w['startTime'] ?? '') . ($w['endTime'] ? '–' . $w['endTime'] : ''))]);
    return implode(', ', $parts);
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
    <div class="row3"><label>First name<input name="first_name" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="off" required></label><label>Second name<input name="father_name" maxlength="60" value="<?= $value('father_name') ?>" autocomplete="off" required></label><label>Last name<input name="grandfather_name" maxlength="60" value="<?= $value('grandfather_name') ?>" autocomplete="off" required></label></div>
    <div class="row3"><label>WhatsApp phone number<input type="tel" name="phone" dir="ltr" autocomplete="off" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email <small class="dk-note">The ticket goes here</small><input type="email" name="email" dir="ltr" autocomplete="off" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<?= \Ismile\Cities::select($_POST['city'] ?? '') ?></label></div>
    <div class="row3"><label>Gender<select name="gender" required><option value="">Choose gender</option><?php foreach (["female" => "Female", "male" => "Male", "other" => "Other", "prefer-not" => "Prefer not to say"] as $gKey => $gName): ?><option value="<?= $gKey ?>"<?= $selected("gender", $gKey) ?>><?= $gName ?></option><?php endforeach; ?></select></label>
      <label>Age<span class="dk-age" data-age><button type="button" class="dk-age-btn" data-age-step="-1" aria-label="Younger">−</button><input type="number" name="age" inputmode="numeric" min="16" max="120" step="1" value="<?= $value("age") ?>" placeholder="28" required aria-label="Age"><button type="button" class="dk-age-btn" data-age-step="1" aria-label="Older">+</button></span></label></div>
  </fieldset>
  <fieldset class="form-section">
    <legend>2. Specialty &amp; ticket</legend>
    <div class="row3">
      <label>Specialty<select name="specialty" id="caller-specialty" required>
        <option value="">Choose specialty</option>
        <?php foreach (Registrations::SPECIALTY_NAMES as $key => $name): ?><option value="<?= $e($key) ?>"<?= $selected('specialty', $key) ?>><?= $e($name) ?></option><?php endforeach; ?>
      </select></label>
      <label>Ticket<select name="ticket" id="caller-ticket"><option value="professional"<?= !$student && !$vip ? ' selected' : '' ?>>Dentist</option><option value="student"<?= $student ? ' selected' : '' ?>>Student</option><option value="vip"<?= $vip ? ' selected' : '' ?>>VIP</option></select></label>
    </div>
    <div class="row3" id="caller-student-fields">
      <label>University<input name="university" id="caller-university" maxlength="160" value="<?= $value('university') ?>"<?= $student ? ' required' : '' ?>></label>
      <label>Stage<select name="stage" id="caller-stage"<?= $student ? ' required' : '' ?>><option value="">Choose the stage</option><?php foreach (range(\Ismile\Checkouts::FIRST_STUDENT_STAGE, \Ismile\Checkouts::LAST_STUDENT_STAGE) as $stage): ?><option value="<?= $stage ?>"<?= $selected('stage', (string) $stage) ?>><?= $e(Registrations::stageName($stage)) ?></option><?php endforeach; ?></select></label>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend>3. Lunch <span class="dk-optional">Optional</span></legend>
    <label id="caller-vip-lunch"<?= !$vip ? ' hidden' : '' ?>>VIP included lunch<select name="vip_lunch_day"<?= !$vip ? ' disabled' : '' ?>><option value="1"<?= ($_POST['vip_lunch_day'] ?? '1') === '1' ? ' selected' : '' ?>>Day 1, 19 November</option><option value="2"<?= ($_POST['vip_lunch_day'] ?? '') === '2' ? ' selected' : '' ?>>Day 2, 20 November</option></select></label>
    <?php if (\Ismile\VipPackages::enabled()): ?><label id="caller-vip-workshop"<?= !$vip ? ' hidden' : '' ?>>VIP included workshop<select name="vip_workshop"<?= !$vip ? ' disabled' : '' ?>><option value="">No workshop (optional)</option><?php foreach (\Ismile\VipPackages::WORKSHOPS as $workshopId): $vipWorkshop = Workshops::find($workshopId); if (!$vipWorkshop) continue; ?><option value="<?= $e($workshopId) ?>"<?= $selected('vip_workshop', $workshopId) ?>><?= $e(Workshops::name($vipWorkshop)) ?></option><?php endforeach; ?></select></label><?php endif; ?>
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
  <h2 id="cash-confirm-title">Are you sure?</h2>
  <p class="cash-confirm-sub">Check these details with the person and take the cash before you sell. This cannot be undone.</p>
  <dl class="cash-confirm-details">
    <div><dt>Name</dt><dd data-confirm-field="name"></dd></div>
    <div><dt>WhatsApp number</dt><dd data-confirm-field="phone" dir="ltr"></dd></div>
    <div><dt>Email</dt><dd data-confirm-field="email" dir="ltr"></dd></div>
    <div><dt>Gender and age</dt><dd data-desk-field="gender-age"></dd></div>
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
<?php
// Workshop cards in the website's design (workshops page), with the cash payment on each.
$words = static fn (mixed $v): string => is_array($v) ? (string) (($v['en'] ?? '') ?: (array_values(array_filter($v))[0] ?? '')) : (string) $v;
$clock = static function (?string $t): string { if (!$t) return ''; $s = strtotime('2000-01-01 ' . $t); return $s ? strtolower(date('g:i a', $s)) : $t; };
$photo = static function (array $w): string {
    $path = (string) ($w['photo'] ?? '');
    return preg_match('~^assets/(?:workshops|uploads/workshops)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g)$~i', $path) && !str_contains($path, '..') ? '../' . $path : '';
};
$usd = static fn (array $q): string => $q['usdMinor'] ? '$' . rtrim(rtrim(number_format($q['usdMinor'] / 100, 2), '0'), '.') : '';
$icon = static fn (string $d): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<div class="dk-grid">
<?php if (!$workshops): ?><?= Page::emptyState('No iSmile workshops', 'No iSmile workshop is active right now.', 'workshops') ?><?php endif; ?>
<?php foreach ($workshops as $id => ['w' => $w, 'quote' => $quote, 'seats' => $seats, 'blocked' => $blocked, 'free' => $isFree]):
    $img = $photo($w);
    $on = $ws && $ws['w']['id'] === $id;
    $full = $blocked === 'Sold out.';
    $freeOpen = $blocked === 'Free';   // a free workshop with seats: shown, not sold here
    $date = $w['scheduledDate'] ? date('j F Y', strtotime((string) $w['scheduledDate'])) : '';
    $time = implode('–', array_filter([$clock($w['startTime'] ?? null), $clock($w['endTime'] ?? null)])); ?>
  <article class="dk-card<?= ($w['photoStyle'] ?? '') === 'cutout' ? ' is-cutout' : '' ?><?= $on ? ' is-on' : '' ?><?= $full ? ' is-full' : '' ?><?= $blocked !== '' && !$full && !$freeOpen ? ' is-blocked' : '' ?><?= $isFree ? ' is-free' : '' ?>" data-workshop-id="<?= $e($id) ?>">
    <div class="dk-media<?= $img === '' ? ' no-photo' : '' ?>"><span class="dk-badge">Organized by iSmile</span><?php if ($img !== ''): ?><img src="<?= $e($img) ?>" alt="<?= $e($words($w['speaker'] ?? '')) ?>" loading="lazy"><?php endif; ?></div>
    <div class="dk-body">
      <h3><?= $e(Workshops::name($w)) ?></h3>
      <?php if ($words($w['speaker'] ?? '') !== ''): ?><p class="dk-trainer"><?= $e($words($w['speaker'])) ?></p><?php endif; ?>
      <?php if ($date . $time !== ''): ?><p class="dk-schedule"><?= $e($date) ?><?= $date !== '' && $time !== '' ? '<br>' : '' ?><?= $e($time) ?></p><?php endif; ?>
      <div class="dk-chips">
        <?php if ($words($w['duration'] ?? '') !== ''): ?><span class="dk-chip"><?= $icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>') ?><?= $e($words($w['duration'])) ?></span><?php endif; ?>
        <?php if ($words($w['level'] ?? '') !== ''): ?><span class="dk-chip"><?= $icon('<path d="M6 20v-4M12 20V10M18 20V4"/>') ?><?= $e($words($w['level'])) ?></span><?php endif; ?>
      </div>
      <div class="dk-pay"><span><?= $isFree ? 'Payment' : 'Payment (cash)' ?></span><b><?= $isFree ? 'Free' : ($quote ? $e(number_format($quote['iqd'])) . ' IQD' : '–') ?></b><?php if ($quote && $usd($quote) !== ''): ?><small>or <?= $e($usd($quote)) ?></small><?php endif; ?></div>
      <div class="dk-foot">
        <div class="dk-seats"><span class="dk-seats-ic"><?= $icon('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>') ?></span><div><b><?= $full ? 'Fully booked' : ($blocked !== '' && !$freeOpen ? 'Not available' : 'Available') ?></b><?php if ($quote && ($blocked === '' || $freeOpen)): ?><small><?= $seats ?> seat<?= $seats === 1 ? '' : 's' ?> left</small><?php elseif ($full): ?><small>No seats left</small><?php endif; ?></div></div>
        <?php if ($blocked === ''): ?><a class="dk-cta" href="<?= $e($deskUrl('workshop', ['ws' => $id] + ($person ? ['rid' => (int) $person['id']] : []))) ?>#register"><?= $isFree ? $icon('<path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/>') : $icon('<rect x="2.5" y="6" width="19" height="12" rx="2.5"/><circle cx="12" cy="12" r="2.6"/>') ?><?= $isFree ? 'Register (free)' : 'Register (cash)' ?><?= $icon('<path d="M5 12h14M13 6l6 6-6 6"/>') ?></a><?php endif; ?>
      </div>
      <?php if ($blocked !== '' && !$full): ?><p class="desk-blocked"><?= $e($blocked) ?></p><?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php if ($ws && $ws['blocked'] === ''): $quote = $ws['quote']; $wid = $ws['w']['id']; $img = $photo($ws['w']); $wsFree = $quote['iqd'] === 0; ?>
<section class="dk-panel" id="register">
  <a class="dk-close" href="<?= $e($deskUrl('workshop')) ?>" aria-label="Close" title="Close">&times;</a>
  <header class="dk-panel-head">
    <?php if ($img !== ''): ?><span class="dk-panel-photo"><img src="<?= $e($img) ?>" alt=""></span><?php endif; ?>
    <div><small>Register for this workshop <span class="dk-tag"><?= $wsFree ? 'Free, no cash' : 'Paid in cash' ?></span></small><h2><?= $e(Workshops::name($ws['w'])) ?></h2><p><?= $e($words($ws['w']['speaker'] ?? '')) ?></p></div>
    <?php if ($wsFree): ?><div class="dk-panel-price"><small>Payment</small><b>Free</b><span>No cash</span></div>
    <?php else: ?><div class="dk-panel-price"><small>Collect</small><b><?= $e(number_format($quote['iqd'])) ?> IQD</b><?php if ($usd($quote) !== ''): ?><span>or <?= $e($usd($quote)) ?></span><?php endif; ?></div><?php endif; ?>
  </header>
  <form method="get" action="desk.php#register" class="dk-find">
    <input type="hidden" name="tab" value="workshop"><input type="hidden" name="ws" value="<?= $e($wid) ?>">
    <p class="dk-step">Find the person: type the first name letter by letter, then a space and the second name. Or type their WhatsApp number or email.</p>
    <div class="dk-search">
      <span class="dk-search-ic" aria-hidden="true"><?= Page::navIcon('search') ?></span>
      <input type="search" name="q" value="<?= $e($findQ) ?>" placeholder="Name, WhatsApp number or email" aria-label="Find the person by name, phone or email" autocomplete="off" autofocus data-desk-find="<?= $e($wid) ?>">
      <button class="dk-cta dk-find-btn">Find</button>
    </div>
  </form>
  <div data-desk-results>
  <?php if ($searched && !$found): ?>
    <p class="desk-empty">Nobody found for “<?= $e($findQ) ?>”. Try only the first name, or the last 4 digits of the phone. Not registered yet? <a href="<?= $e($deskUrl('register')) ?>"><b>Register them first</b></a> in the Register tab.</p>
  <?php elseif ($found): ?>
  <p class="dk-count"><?= count($found) ?> <?= count($found) === 1 ? 'person' : 'people' ?> found</p>
  <div class="dk-people">
    <?php foreach ($found as $row): $chosen = $person && (int) $person['id'] === (int) $row['id']; $why = $whyNot($row); ?>
    <<?= $why === '' ? 'a href="' . $e($deskUrl('workshop', ['ws' => $wid] + $keep + ['rid' => (int) $row['id']])) . '#pay"' : 'div' ?> class="dk-person<?= $chosen ? ' is-on' : '' ?><?= $why !== '' ? ' is-off' : '' ?>">
      <span class="dk-avatar"><?= $e(mb_strtoupper(mb_substr((string) $row['first_name'], 0, 1) . mb_substr((string) $row['father_name'], 0, 1))) ?></span>
      <span class="dk-person-text"><b><?= $e(Registrations::fullName($row)) ?></b><span class="dk-chips-line"><span class="dk-mini" dir="ltr"><?= $e((string) $row['email']) ?></span><span class="dk-mini is-phone">WhatsApp <?= $e($masked((string) $row['phone'])) ?></span><span class="dk-mini is-ticket"><?= $e(Registrations::ticketName((string) $row['ticket_type'])) ?></span><?php if ($row['status'] === 'complimentary'): ?><span class="dk-mini is-guest">Guest ticket</span><?php endif; ?></span><?php if ($why !== ''): ?><small class="dk-why"><?= $e($why) ?></small><?php endif; ?></span>
      <?php if ($why === ''): ?><em><?= $chosen ? 'Chosen' : 'Choose' ?></em><?php endif; ?>
    </<?= $why === '' ? 'a' : 'div' ?>>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  </div>

  <?php if ($person): ?>
  <div class="dk-pay-step" id="pay">
    <p class="dk-step"><?= $wsFree ? 'Register' : 'Take the cash from' ?> <strong><?= $e(Registrations::fullName($person)) ?></strong><?= $wsFree ? ' for free (no cash)' : '' ?>. Psoola emails the workshop ticket to <span dir="ltr"><?= $e((string) $person['email']) ?></span>.</p>
    <?php if ($has): ?>
      <p class="desk-blocked">Already registered for this workshop.</p>
    <?php else: ?>
    <form method="post" action="<?= $e($deskUrl('workshop', ['ws' => $wid] + $keep + ['rid' => (int) $person['id']])) ?>#pay" class="stack" data-ws-confirm data-name="<?= $e(Registrations::fullName($person)) ?>" data-email="<?= $e((string) $person['email']) ?>" data-phone="<?= $e($localPhone((string) $person['phone'])) ?>" data-workshop="<?= $e(Workshops::name($ws['w'])) ?>">
      <?= Page::csrfField() ?><?= Page::onceField() ?>
      <input type="hidden" name="do" value="workshop_cash_sale"><input type="hidden" name="rid" value="<?= (int) $person['id'] ?>"><input type="hidden" name="workshop" value="<?= $e($wid) ?>">
      <?php if ($wsFree): ?>
      <div class="dk-pay-buttons"><button class="dk-cta dk-big" data-amount="Free, no cash">Register for free <span class="dk-go">Send ticket</span></button></div>
      <?php else: ?>
      <label class="desk-received"><input type="checkbox" name="cash_received" value="1" required> I have received the cash for this workshop.</label>
      <div class="dk-pay-buttons">
        <button class="dk-cta dk-big" name="cash_currency" value="IQD" data-amount="<?= $e(number_format($quote['iqd'])) ?> IQD in cash">Received <b><?= $e(number_format($quote['iqd'])) ?> IQD</b> <span class="dk-go">Register</span></button>
        <?php if ($usd($quote) !== ''): ?><button class="dk-cta dk-big" name="cash_currency" value="USD" data-amount="<?= $e($usd($quote)) ?> in cash">Received <b><?= $e($usd($quote)) ?></b> <span class="dk-go">Register</span></button><?php endif; ?>
      </div>
      <?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<dialog class="cash-confirm dk-confirm" id="ws-confirm" aria-labelledby="ws-confirm-title">
  <h2 id="ws-confirm-title">Are you sure?</h2>
  <p class="cash-confirm-sub">Check these details with the person before you register.</p>
  <dl class="cash-confirm-details">
    <div><dt>Name</dt><dd data-ws-field="name"></dd></div>
    <div><dt>Email</dt><dd data-ws-field="email" dir="ltr"></dd></div>
    <div><dt>WhatsApp number</dt><dd data-ws-field="phone" dir="ltr"></dd></div>
    <div><dt>Workshop</dt><dd data-ws-field="workshop"></dd></div>
  </dl>
  <div class="cash-confirm-amount"><span>Payment</span><b data-ws-field="amount"></b></div>
  <p class="cash-confirm-info">Psoola emails the workshop ticket to the email above.</p>
  <div class="cash-confirm-actions">
    <button type="button" class="btn ghost" data-ws-back>No, go back</button>
    <button type="button" class="btn cash-pay" data-ws-yes>Yes, register</button>
  </div>
</dialog>
</section>
<?php endif; ?>
<p class="muted small">Company workshops are not sold here: the person calls the company.</p>
<?php endif;
Page::bottom();
