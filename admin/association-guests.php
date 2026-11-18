<?php
// Association guests: people the Slemani Association registers for the
// summit. The form is the Register (cash) form: PSOOLA records a cash sale,
// issues the ticket and emails the QR at once. The association pays iSmile
// later. The guest list (with the full guest list PDF); ?tab=add is the "Add by association" form.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\PdfReport;
use Ismile\AssociationGuests;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\UserError;

$user = Page::guard('association-guests');
$canAdd = AssociationGuests::canAdd($user) && Auth::viewerMayPost($user, '/admin/association-guests.php');
$canPdf = Auth::can($user, 'export') || Auth::canManageAssociationGuests($user);
$canOpenGuest = Auth::can($user, 'registrations');
$tab = (Page::query('tab') === 'add' && $canAdd) ? 'add' : 'guests';

// ---------- Guest list PDF (the full details, like the Lists PDFs) ----------
if (Page::query('pdf') === 'list') {
    if (!$canPdf) {
        Page::redirect('association-guests.php');
    }
    $rows = AssociationGuests::reportRows();
    Audit::log((int) $user['id'], 'export', null, null, ['what' => 'association-guests', 'rows' => count($rows) - 2]);
    // The amounts are shown: they are what the association owes iSmile.
    $pdf = PdfReport::make(AssociationGuests::NAME . ' guests', $rows, true);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="ismile-slemani-association-guests-' . date('Y-m-d-Hi') . '.pdf"');
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}

// ---------- Add by association ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tab = 'add';
    try {
        Auth::checkCsrf();
        Page::useOnce();
        if (!$canAdd) {
            throw new UserError('Your account cannot add association guests.');
        }
        if (($_POST['do'] ?? '') !== 'add') {
            throw new UserError('Press Add by association.');
        }
        \Ismile\Registrations::requireWhatsappConsent($_POST);
        ['checkout' => $checkout, 'sale' => $sale] = AssociationGuests::add($_POST, $user);
        $price = number_format($sale['amount']) . ' ' . $sale['currency'];
        if ($sale['status'] === 'paid' && $sale['registration_id']) {
            Page::flash('ok', 'Added ' . Registrations::fullName($checkout) . ' (' . $checkout['ref'] . ') for the ' . AssociationGuests::NAME . '. PSOOLA is emailing the ticket with the QR to ' . $checkout['email'] . '. The association owes ' . $price . ' for this guest.');
        } else {
            Page::flash('ok', 'PSOOLA made the sale (' . $price . ') for ' . Registrations::fullName($checkout) . '. The registration of ' . $checkout['ref'] . ' finishes within 5 minutes, when PSOOLA confirms it again.');
        }
        Page::redirect('association-guests.php');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
    } catch (\Ismile\Payments\GatewayNotReady $error) {
        \Ismile\App::log('error', 'Add by association failed', ['error' => $error->getMessage()]);
        Page::flash('error', 'The payment company is not ready, so nothing was sold or sent. (The detail is in the server log.)');
    }
}

$e = [Page::class, 'e'];
$actions = '';
if ($canAdd && $tab !== 'add') {
    $actions .= '<a class="btn" href="association-guests.php?tab=add">+ Add by association</a>';
} elseif ($tab === 'add') {
    $actions .= '<a class="btn ghost" href="association-guests.php">&larr; Association guests</a>';
}
Page::top('Association guests', 'association-guests', $actions);
?>
<?php

if ($tab === 'guests') {
    $filters = AssociationGuests::filters($_GET);
    $everyone = AssociationGuests::all();
    $totals = AssociationGuests::totals($everyone);
    $registeredRefs = array_values(array_map(static fn (array $g): string => (string) $g['ref'], array_filter($everyone, static fn (array $g): bool => AssociationGuests::state($g) === 'registered')));
    $mail = AssociationGuests::emailStatuses($registeredRefs);
    $guests = AssociationGuests::filter($everyone, $filters, $mail ?? []);
    $mailNames = ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'delayed' => 'Delayed', 'bounced' => 'Bounced', 'complained' => 'Complained', 'failed' => 'Failed'];
    $adders = [];
    foreach ($everyone as $guest) $adders[(string) $guest['added_by']] = (string) ($guest['added_by_name'] ?? 'Removed account');
    asort($adders);
    $filtered = array_filter(array_diff_key($filters, ['sort' => true]), static fn (string $v): bool => $v !== '') !== [];
    $select = static function (string $name, string $label, array $options, string $current): string {
        $html = '<label class="filter-field"><span>' . Page::e($label) . '</span><select name="' . $name . '">';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . Page::e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . Page::e($text) . '</option>';
        }
        return $html . '</select></label>';
    };
    ?>
<div data-live-region="association-guest-totals">
<section class="card association-summary" aria-label="<?= $e(AssociationGuests::NAME) ?>">
  <div class="association-summary-head">
    <span class="association-guest-logo" aria-hidden="true">SA</span>
    <div><span class="attendance-label">Association</span><h2><?= $e(AssociationGuests::NAME) ?></h2></div>
    <span class="association-paylater"><?= Page::navIcon('clock') ?>All guests pay later</span>
  </div>
  <div class="association-stats">
    <div class="association-stat teal"><span>Guests</span><strong><?= number_format($totals['guests']) ?></strong><small>Added by the association</small></div>
    <div class="association-stat red"><span>Owes iSmile</span><strong class="association-money"><?php foreach ($totals['owed'] ?: ['IQD' => 0] as $currency => $amount): ?><b><?= number_format($amount) ?><i><?= $e($currency) ?></i></b><?php endforeach; ?></strong><small>Paid later by the association</small></div>
    <div class="association-stat blue"><span>Dentists</span><strong><?= number_format($totals['professional']) ?></strong><small>Dentist tickets</small></div>
    <div class="association-stat violet"><span>Students</span><strong><?= number_format($totals['student']) ?></strong><small>Student tickets</small></div>
    <div class="association-stat gold"><span>VIP</span><strong><?= number_format($totals['vip']) ?></strong><small>VIP tickets</small></div>
    <div class="association-stat grey"><span>Waiting for PSOOLA</span><strong><?= number_format($totals['waiting']) ?></strong><small>Sale not confirmed yet</small></div>
  </div>
</section>
</div>

<?php
$segments = static function (string $name, string $label, array $choices, string $current): string {
    $html = '<fieldset class="filter-seg"><legend>' . Page::e($label) . '</legend><div>';
    foreach ($choices as $value => $text) {
        $html .= '<label><input type="radio" name="' . Page::e($name) . '" value="' . Page::e((string) $value) . '"' . ((string) $value === $current ? ' checked' : '') . '><span>' . Page::e($text) . '</span></label>';
    }
    return $html . '</div></fieldset>';
};
$moreOpen = $filters['state'] !== '' || $filters['by'] !== '';
?>
<form class="card registration-filter-card association-filter-card" method="get" action="association-guests.php" data-instant-search data-search-regions="association-guest-directory">
  <div class="registration-search-row">
    <label class="filter-search registration-search"><?= Page::navIcon('search') ?><span class="sr-only">Name, phone, email or reference</span><input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="Search name, phone, email or reference" autofocus></label>
    <?= $select('sort', 'Order', AssociationGuests::SORTS, $filters['sort']) ?>
  </div>
  <div class="registration-quick">
    <?= $segments('ticket', 'Ticket', ['' => 'All'] + Registrations::TICKET_NAMES, $filters['ticket']) ?>
    <?= $segments('lunch', 'Lunch booked', ['' => 'Any', 'day1' => 'Day 1', 'day2' => 'Day 2', 'both' => 'Both', 'none' => 'None'], $filters['lunch']) ?>
    <?= $segments('email', 'QR email', ['' => 'Any', 'delivered' => 'Delivered', 'pending' => 'Not yet', 'problem' => 'Problem'], $filters['email']) ?>
  </div>
  <div class="registration-filter-footer"><details class="registration-advanced-filters"<?= $moreOpen ? ' open' : '' ?>><summary><?= Page::navIcon('settings') ?><span>More filters: registration and added by</span></summary><div class="registration-filter-grid">
    <?= $select('state', 'Registration', ['' => 'Everyone', 'registered' => 'Registered', 'waiting' => 'Waiting for PSOOLA', 'cancelled' => 'Cancelled'], $filters['state']) ?>
    <?= $select('by', 'Added by', ['' => 'Anyone'] + $adders, $filters['by']) ?>
  </div></details>
  <div class="registration-filter-actions"><div><a class="btn ghost" href="association-guests.php">Clear filters</a><button class="btn">Search</button></div></div></div>
</form>

<div data-live-region="association-guest-directory">
<section class="card directory-panel association-directory"><div class="panel-top"><div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('association-guests') ?></span><div><h2>Guest list <span class="result-count"><?= count($guests) ?></span></h2><p><?= $filtered ? 'Guests that match your search and filters.' : 'Every guest added by the ' . $e(AssociationGuests::NAME) . '.' ?> <?= $e(AssociationGuests::SORTS[$filters['sort']]) ?>.<?= $mail === null ? ' PSOOLA could not be reached for the QR email status just now.' : '' ?></p></div></div><?php if ($canPdf): ?><div class="panel-actions"><a class="btn association-pdf" href="association-guests.php?pdf=list"><?= Page::navIcon('download') ?><span>Download guest list (PDF)</span></a></div><?php endif; ?></div>
<div class="table-wrap association-guest-scroll">
<table class="association-guest-table">
  <tr><th>Reference</th><th>Name</th><th>Phone</th><th>Ticket</th><th>Lunch</th><th>Price</th><th>QR email</th><th>Added by</th><th>Added</th></tr>
  <?php foreach ($guests as $guest):
      $state = AssociationGuests::state($guest);
      $link = $guest['registration_id'] !== null && $canOpenGuest ? 'registration.php?id=' . (int) $guest['registration_id'] : null;
      $status = $mail[$guest['ref']] ?? null; ?>
  <tr>
    <td><?php if ($link): ?><a href="<?= $e($link) ?>"><code><?= $e($guest['ref']) ?></code></a><?php else: ?><code><?= $e($guest['ref']) ?></code><?php endif; ?></td>
    <td><div class="guest-cell"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $guest['first_name'], 0, 1))) ?></span><div>
      <?php if ($link): ?><a href="<?= $e($link) ?>"><b><?= $e(Registrations::fullName($guest)) ?></b></a><?php else: ?><b><?= $e(Registrations::fullName($guest)) ?></b><?php endif; ?>
      <small><span class="added-by-tag association"><?= $e($guest['association']) ?></span>
      <?= match ($state) { 'waiting' => ' <span class="pill gold">Waiting for PSOOLA</span>', 'review' => ' <span class="pill red">Check in Payments</span>', 'cancelled' => ' ' . Page::pill('cancelled'), default => '' } ?></small>
    </div></div></td>
    <td dir="ltr"><?= $e((string) $guest['phone']) ?></td>
    <td><?php if ($guest['ticket_type'] === 'vip'): ?><span class="pill gold">VIP</span><?php else: ?><?= $e(Registrations::ticketName($guest['ticket_type'])) ?><?php endif; ?></td>
    <td><?= $guest['lunch_day1'] ? 'D1 ' : '' ?><?= $guest['lunch_day2'] ? 'D2' : '' ?><?= !$guest['lunch_day1'] && !$guest['lunch_day2'] ? '–' : '' ?></td>
    <td class="num"><?= $guest['amount'] !== null && $state !== 'cancelled' ? $e(Page::money((int) $guest['amount'], (string) ($guest['currency'] ?: 'IQD'))) : '–' ?></td>
    <td><?php if ($state !== 'registered'): ?><span class="muted">–</span><?php elseif ($status === null || $status === ''): ?><span class="muted">Not checked</span><?php else: ?><span class="psoola-mail<?= in_array($status, ['bounced', 'complained', 'failed', 'delayed'], true) ? ' bad' : '' ?>"><?= $e($mailNames[$status] ?? ucfirst($status)) ?></span><?php endif; ?></td>
    <td><b><?= $e($guest['added_by_name'] ?? 'Removed account') ?></b></td>
    <td><?= Page::when($guest['added_at']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$guests): ?><tr class="empty-row"><td colspan="9"><?php if ($filtered): ?><div class="empty-state"><span class="empty-icon"><?= Page::navIcon('search') ?></span><strong>No association guest matches</strong><p>Nobody matches this search and these filters. Try other letters or clear the filters.</p><a class="btn ghost small" href="association-guests.php">Clear filters</a></div><?php else: ?><div class="empty-state arrivals-empty"><span class="empty-icon"><?= Page::navIcon('association-guests') ?></span><strong>No association guests yet</strong><p><?= $canAdd ? 'Add the first guest the ' . $e(AssociationGuests::NAME) . ' registers. PSOOLA emails their QR ticket at once, and the price is added to what the association owes.' : 'Guests the association registers appear here.' ?></p><?php if ($canAdd): ?><a class="btn small" href="association-guests.php?tab=add">+ Add by association</a><?php endif; ?></div><?php endif; ?></td></tr><?php endif; ?>
</table>
</div>
</section>
</div>
<?php
    Page::bottom();
    exit;
}

// ---------- The Add by association form (the Register (cash) form) ----------
$value = static fn (string $key): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
$selected = static fn (string $key, string $option, string $default = ''): string => ($_POST[$key] ?? $default) === $option ? ' selected' : '';
$vip = ($_POST['ticket'] ?? '') === 'vip';
$student = !$vip && (($_POST['specialty'] ?? '') === 'student' || ($_POST['ticket'] ?? '') === 'student');
// PSOOLA's IQD prices when it answers (cached five minutes); the amount the
// association owes is always the total PSOOLA returns for the sale.
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
$vipUsd = $psoola && $site['vipCurrency'] !== $psoola['currency'] ? $label($site['vip'], $site['vipCurrency']) . ', ' : '';
$lunchUsd = static fn (int $day): string => $psoola && $site['lunchCurrency'] !== $psoola['currency'] ? $label($site['lunchDay' . $day], $site['lunchCurrency']) . ' / day' : 'per day';
$priceData = $psoola ? json_encode([
    'currency' => $psoola['currency'],
    'professional' => $psoola['ticketTypes']['professional'], 'student' => $psoola['ticketTypes']['student'], 'vip' => $psoola['ticketTypes']['vip'],
    'lunch1' => $psoola['lunch']['day1'], 'lunch2' => $psoola['lunch']['day2'],
]) : '';
$association = AssociationGuests::NAME;
?>
<div class="cash-layout">
<div class="cash-main">
<form method="post" action="association-guests.php" class="card stack caller-form" id="caller-form" enctype="multipart/form-data" data-prices="<?= $e((string) $priceData) ?>"
  data-pay-label="Add by association"
  data-received-text="<?= $e('I checked the details. The ' . $association . ' will pay iSmile {amount} later.') ?>"
  data-received-plain="<?= $e('I checked the details. The ' . $association . ' will pay iSmile later.') ?>">
  <?= Page::csrfField() ?><?= Page::onceField() ?>
  <p class="muted">For a guest the <b><?= $e($association) ?></b> registers: enter their details and press <b>Add by association</b>. PSOOLA issues the ticket at once and emails the QR. The association pays iSmile later.</p>
  <div class="association-guest-banner"><span class="added-by-tag association"><?= $e($association) ?></span><span>This guest is saved as added by the <?= $e($association) ?>, pays later.</span></div>
  <fieldset class="form-section">
    <legend>1. Guest's details</legend>
    <div class="row3"><label>First name<input name="first_name" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="off" required></label><label>Second name<input name="father_name" maxlength="60" value="<?= $value('father_name') ?>" required></label><label>Last name<input name="grandfather_name" maxlength="60" value="<?= $value('grandfather_name') ?>" required></label></div>
    <div class="row3"><label>Gender<select name="gender" required><option value="">Choose gender</option><?php foreach (['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'prefer-not' => 'Prefer not to say'] as $genderKey => $genderName): ?><option value="<?= $genderKey ?>"<?= $selected('gender', $genderKey) ?>><?= $genderName ?></option><?php endforeach; ?></select></label><label>Age<input type="number" name="age" inputmode="numeric" min="16" max="120" step="1" value="<?= $value('age') ?>" placeholder="28" required></label></div>
    <div class="row3"><label>Phone<input type="tel" name="phone" dir="ltr" autocomplete="off" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email<input type="email" name="email" dir="ltr" autocomplete="off" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<?= \Ismile\Cities::select($_POST['city'] ?? '') ?></label></div>
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
    <p class="muted small" id="caller-ticket-note">Same prices as everyone. Dental students in any stage (1st to 5th) receive a student ticket.</p>
    <div class="row3" id="caller-student-fields">
      <label>University (required for students)<input name="university" id="caller-university" maxlength="160" value="<?= $value('university') ?>"<?= $student ? ' required' : '' ?>></label>
      <label>Stage<select name="stage" id="caller-stage"<?= $student ? ' required' : '' ?>><option value="">Choose the stage</option><?php foreach (range(\Ismile\Checkouts::FIRST_STUDENT_STAGE, \Ismile\Checkouts::LAST_STUDENT_STAGE) as $stage): ?><option value="<?= $stage ?>"<?= $selected('stage', (string) $stage) ?>><?= $e(Registrations::stageName($stage)) ?></option><?php endforeach; ?></select></label>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend>3. Lunch <span class="legend-note">optional, per day</span></legend>
    <label id="caller-vip-lunch"<?= !$vip ? ' hidden' : '' ?>>VIP included lunch<select name="vip_lunch_day"<?= !$vip ? ' disabled' : '' ?>><option value="1"<?= $selected('vip_lunch_day', '1', '1') ?>>Day 1, 19 November</option><option value="2"<?= $selected('vip_lunch_day', '2', '1') ?>>Day 2, 20 November</option></select><small><?= \Ismile\VipPackages::enabled() ? 'VIP includes one lunch and one selected workshop.' : 'VIP includes one lunch. The other day is optional.' ?></small></label>
    <?php if (\Ismile\VipPackages::enabled()): ?><label id="caller-vip-workshop"<?= !$vip ? ' hidden' : '' ?>>VIP included workshop<select name="vip_workshop"<?= !$vip ? ' disabled' : '' ?>><option value="">No workshop (optional)</option><?php foreach (\Ismile\VipPackages::WORKSHOPS as $workshopId): $vipWorkshop = \Ismile\Workshops::find($workshopId); if (!$vipWorkshop) continue; ?><option value="<?= $e($workshopId) ?>"<?= $selected('vip_workshop', $workshopId, '') ?>><?= $e(\Ismile\Workshops::name($vipWorkshop)) ?></option><?php endforeach; ?></select><small>Saved with the VIP registration. No extra workshop payment.</small></label><?php endif; ?>
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
  <label class="cash-confirm-check whatsapp-consent"><input type="checkbox" name="whatsapp_consent" value="1"<?= ($_POST['whatsapp_consent'] ?? '') === '1' ? ' checked' : '' ?> required><span>The guest agreed to get the ticket QR code by <b>email and WhatsApp</b>.</span></label>
  <div class="form-actions"><a class="btn ghost" href="association-guests.php">Cancel</a><button class="btn cash-pay" name="do" value="add" id="caller-pay">Add by association</button></div>
  <p class="muted small">The guest is registered and tagged <?= $e($association) ?>. PSOOLA records it as a cash sale, and the price is added to what the association owes iSmile.</p>
</form>
<!-- "Add this guest?": opened by Add by association, filled from the form (admin.js). -->
<dialog class="cash-confirm" id="cash-confirm" aria-labelledby="cash-confirm-title">
  <span class="cash-confirm-icon"><?= Page::navIcon('association-guests') ?></span>
  <h2 id="cash-confirm-title">Add this guest for the <?= $e($association) ?>?</h2>
  <p class="cash-confirm-sub">Check the details before you add. PSOOLA issues the ticket at once. This cannot be undone here.</p>
  <dl class="cash-confirm-details">
    <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/></svg>Guest</dt><dd data-confirm-field="name"></dd></div>
    <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2"/></svg>Phone</dt><dd data-confirm-field="phone" dir="ltr"></dd></div>
    <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>Ticket email</dt><dd data-confirm-field="email" dir="ltr"></dd></div>
    <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8a2 2 0 0 0 0 4v0a2 2 0 0 0 0 4v1a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-1a2 2 0 0 1 0-4 2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z"/><path d="M14 6v12" stroke-dasharray="2 2"/></svg>Ticket</dt><dd data-confirm-field="ticket"></dd></div>
    <div><dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10M17 21V3c-2 1-3 4-3 7h3"/></svg>Lunch</dt><dd data-confirm-field="lunch"></dd></div>
  </dl>
  <div class="cash-confirm-amount"><span>The association will owe</span><b data-confirm-field="total"></b></div>
  <label class="cash-confirm-check"><input type="checkbox" id="cash-confirm-received"><span data-confirm-field="received">I checked the details. The <?= $e($association) ?> will pay iSmile later.</span></label>
  <p class="cash-confirm-info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>PSOOLA issues the ticket at once and emails the QR to the email above.</p>
  <div class="cash-confirm-actions">
    <button type="button" class="btn ghost" data-confirm-back>No, go back</button>
    <button type="button" class="btn cash-pay" id="cash-confirm-yes" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg>Yes, add and send QR</button>
  </div>
</dialog>
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
    <p class="cash-total-label">This guest</p>
    <ul class="cash-lines" id="cash-lines"></ul>
    <p class="cash-total-label">The association will owe</p>
    <p class="cash-total-amount<?= $psoola ? '' : ' is-waiting' ?>" id="cash-total"><?= $psoola ? '–' : 'After the sale' ?></p>
    <p class="cash-total-note small"><?= $psoola
        ? 'PSOOLA confirms the final amount when you press Add by association. Prices refresh from PSOOLA every 5 minutes.'
        : 'PSOOLA cannot be reached for prices right now, so the amount is shown after the sale.' ?></p>
  </section>
</aside>
</div>
<?php
Page::bottom();
