<?php
// Speaker tickets: speakers registered one by one with a free PSOOLA guest
// ticket (0 IQD); the lunch days ticked are then added to that ticket, free.
// PSOOLA sends the QR by email and WhatsApp. Only Owners add speakers; ?tab=add
// is the form.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\PdfReport;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\GuestOrders;
use Ismile\Registrations;
use Ismile\SpeakerTickets;
use Ismile\UserError;

$user = Page::guard('speaker-tickets');
$canAdd = SpeakerTickets::canAdd($user);
$canPdf = Auth::can($user, 'export');
$canOpenGuest = Auth::can($user, 'registrations');
$tab = (Page::query('tab') === 'add' && $canAdd) ? 'add' : 'speakers';

// ---------- Speaker list PDF ----------
if (Page::query('pdf') === 'list') {
    if (!$canPdf) {
        Page::redirect('speaker-tickets.php');
    }
    $rows = SpeakerTickets::reportRows();
    Audit::log((int) $user['id'], 'export', null, null, ['what' => 'speaker-tickets', 'rows' => count($rows) - 2]);
    $pdf = PdfReport::make('Speaker tickets', $rows);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="ismile-speaker-tickets-' . date('Y-m-d-Hi') . '.pdf"');
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}

// "Free lunch day 1 and day 2 added." or why it was not, for the flash message.
$lunchNote = static function (string $key): array {
    $row = \Ismile\Db::one('SELECT st.lunch_day1 AS want1, st.lunch_day2 AS want2, st.lunch_error, r.lunch_day1, r.lunch_day2 FROM speaker_tickets st
        JOIN sponsored_ticket_orders o ON o.attempt_key = st.attempt_key LEFT JOIN registrations r ON r.id = o.registration_id WHERE st.attempt_key = ?', [$key]);
    if (!$row || ((int) $row['want1'] + (int) $row['want2']) === 0) return [true, ''];
    $done = array_values(array_filter([1, 2], static fn (int $d): bool => (int) $row['lunch_day' . $d] === 1));
    if ($row['lunch_error'] === null && count($done) === (int) $row['want1'] + (int) $row['want2']) {
        return [true, ' Free lunch added: day ' . implode(' and day ', $done) . '.'];
    }
    return [false, ' The free lunch was not added yet: ' . rtrim((string) ($row['lunch_error'] ?? 'PSOOLA did not confirm it.'), '.') . '. Use Add lunch again in the speaker list.'];
};

// ---------- Add a speaker, retry one PSOOLA has not confirmed, or add the lunch again ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $tab = $do === 'add' ? 'add' : 'speakers';
    try {
        Auth::checkCsrf();
        if (!$canAdd) {
            throw new UserError('Only an Owner can add speakers.');
        }
        if ($do === 'retry') {
            $key = is_string($_POST['attempt'] ?? null) ? $_POST['attempt'] : '';
            if (!\Ismile\Db::value('SELECT id FROM speaker_tickets WHERE attempt_key = ?', [$key])) {
                throw new UserError('That speaker was not found. Reload the page.');
            }
            $order = GuestOrders::retry($user, $key);
            if ($order['status'] !== 'issued') {
                Page::flash('ok', 'Sent again. PSOOLA has not confirmed it yet.');
                Page::redirect('speaker-tickets.php');
            }
            SpeakerTickets::lunchAfterTicket($key, $user, (int) $order['id']);
            [$fine, $note] = $lunchNote($key);
            Page::flash($fine ? 'ok' : 'error', 'PSOOLA issued the free speaker ticket (' . $order['partner_reference'] . ') and is sending the QR by email and WhatsApp.' . $note);
            Page::redirect('speaker-tickets.php');
        }
        if ($do === 'lunch') {
            $key = is_string($_POST['attempt'] ?? null) ? $_POST['attempt'] : '';
            $result = SpeakerTickets::addLunch($key, $user);
            if ($result['error'] !== null) throw new UserError($result['error']);
            Page::flash('ok', $result['added'] ? 'PSOOLA added the free lunch: day ' . implode(' and day ', $result['added']) . '.' : 'The free lunch is already on this ticket.');
            Page::redirect('speaker-tickets.php');
        }
        if ($do !== 'add') {
            throw new UserError('Press Add speaker.');
        }
        $order = SpeakerTickets::add($_POST, $user);
        $name = trim(implode(' ', array_map(static fn (string $k): string => trim((string) ($_POST[$k] ?? '')), ['first_name', 'father_name', 'grandfather_name'])));
        [$fine, $note] = $order['status'] === 'issued' ? $lunchNote((string) $_POST['attempt']) : [true, ''];
        Page::flash($fine ? 'ok' : 'error', 'Added ' . $name . ' as a speaker (' . $order['partner_reference'] . '). PSOOLA issued the free ticket and is sending the QR by email and WhatsApp.' . $note);
        Page::redirect('speaker-tickets.php');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
        if ($do !== 'add') Page::redirect('speaker-tickets.php');
    } catch (\Throwable $error) {
        \Ismile\App::log('error', 'Speaker ticket failed', ['error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()]);
        Page::flash('error', $do === 'lunch' ? 'Something went wrong, so the lunch was not added. (The error is in the server log.)' : 'Something went wrong, so the speaker was not added. (The error is in the server log.)');
        if ($do !== 'add') Page::redirect('speaker-tickets.php');
    }
}

$e = [Page::class, 'e'];
$actions = '';
if ($canAdd && $tab !== 'add') {
    $actions .= '<a class="btn" href="speaker-tickets.php?tab=add">+ Add speaker</a>';
} elseif ($tab === 'add') {
    $actions .= '<a class="btn ghost" href="speaker-tickets.php">&larr; Speaker tickets</a>';
}
Page::top('Speaker tickets', 'speaker-tickets', $actions);

if ($tab === 'speakers') {
    $filters = SpeakerTickets::filters($_GET);
    $everyone = SpeakerTickets::all();
    $totals = SpeakerTickets::totals($everyone);
    $speakers = SpeakerTickets::filter($everyone, $filters);
    $filtered = array_filter(array_diff_key($filters, ['sort' => true]), static fn (string $v): bool => $v !== '') !== [];
    $sendNames = ['queued' => 'Queued', 'pending' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'delayed' => 'Delayed', 'bounced' => 'Bounced', 'complained' => 'Complained', 'failed' => 'Failed'];
    $send = static function (?string $status) use ($e, $sendNames): string {
        $status = strtolower((string) $status);
        if ($status === '') return '<span class="muted">Not known</span>';
        return '<span class="psoola-mail' . (in_array($status, ['bounced', 'complained', 'failed', 'delayed'], true) ? ' bad' : '') . '">' . $e($sendNames[$status] ?? ucfirst($status)) . '</span>';
    };
    $segments = static function (string $name, string $label, array $choices, string $current): string {
        $html = '<fieldset class="filter-seg"><legend>' . Page::e($label) . '</legend><div>';
        foreach ($choices as $value => $text) {
            $html .= '<label><input type="radio" name="' . Page::e($name) . '" value="' . Page::e((string) $value) . '"' . ((string) $value === $current ? ' checked' : '') . '><span>' . Page::e($text) . '</span></label>';
        }
        return $html . '</div></fieldset>';
    };
    ?>
<section class="card association-summary" aria-label="Speaker tickets">
  <div class="association-summary-head">
    <span class="association-guest-logo" aria-hidden="true"><?= Page::navIcon('speaker-tickets') ?></span>
    <div><span class="attendance-label">Speakers</span><h2>Speaker tickets</h2></div>
    <span class="association-paylater"><?= Page::navIcon('check') ?>Free ticket and free lunch</span>
  </div>
  <div class="association-stats">
    <div class="association-stat teal"><span>Speakers</span><strong><?= number_format($totals['speakers']) ?></strong><small>Free PSOOLA tickets</small></div>
    <div class="association-stat blue"><span>Lunch day 1</span><strong><?= number_format($totals['lunch1']) ?></strong><small>Thursday 19 Nov, free</small></div>
    <div class="association-stat violet"><span>Lunch day 2</span><strong><?= number_format($totals['lunch2']) ?></strong><small>Friday 20 Nov, free</small></div>
    <div class="association-stat gold"><span>QR sent</span><strong><?= number_format($totals['sent']) ?></strong><small>By email or WhatsApp</small></div>
    <div class="association-stat grey"><span>Waiting for PSOOLA</span><strong><?= number_format($totals['waiting']) ?></strong><small>Press Retry</small></div>
  </div>
</section>

<form class="card registration-filter-card association-filter-card" method="get" action="speaker-tickets.php">
  <div class="registration-search-row">
    <label class="filter-search registration-search"><?= Page::navIcon('search') ?><span class="sr-only">Name, phone, email or reference</span><input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="Search name, phone, email or reference"></label>
    <label class="filter-field"><span>Order</span><select name="sort"><?php foreach (SpeakerTickets::SORTS as $value => $text): ?><option value="<?= $e($value) ?>"<?= $value === $filters['sort'] ? ' selected' : '' ?>><?= $e($text) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="registration-quick">
    <?= $segments('ticket', 'Ticket', ['' => 'All', 'professional' => 'Dentist', 'student' => 'Student'], $filters['ticket']) ?>
    <?= $segments('lunch', 'Free lunch', ['' => 'Any', 'day1' => 'Day 1', 'day2' => 'Day 2', 'both' => 'Both', 'none' => 'None'], $filters['lunch']) ?>
    <?= $segments('state', 'Ticket status', ['' => 'Any', 'registered' => 'Issued', 'waiting' => 'Waiting', 'rejected' => 'Refused', 'cancelled' => 'Cancelled'], $filters['state']) ?>
  </div>
  <div class="registration-filter-footer"><div class="registration-filter-actions"><div><a class="btn ghost" href="speaker-tickets.php">Clear filters</a><button class="btn">Search</button></div></div></div>
</form>

<section class="card directory-panel association-directory"><div class="panel-top"><div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('speaker-tickets') ?></span><div><h2>Speaker list <span class="result-count"><?= count($speakers) ?></span></h2><p><?= $filtered ? 'Speakers that match your search and filters.' : 'Every speaker with a free ticket.' ?> <?= $e(SpeakerTickets::SORTS[$filters['sort']]) ?>.</p></div></div><?php if ($canPdf): ?><div class="panel-actions"><a class="btn association-pdf" href="speaker-tickets.php?pdf=list"><?= Page::navIcon('download') ?><span>Download speaker list (PDF)</span></a></div><?php endif; ?></div>
<div class="table-wrap association-guest-scroll">
<table class="association-guest-table">
  <tr><th>Reference</th><th>Name</th><th>Phone</th><th>Ticket</th><th>Free lunch</th><th>QR email</th><th>QR WhatsApp</th><th>Added by</th><th>Added</th></tr>
  <?php foreach ($speakers as $speaker):
      $state = SpeakerTickets::state($speaker);
      $link = $speaker['registration_id'] !== null && $canOpenGuest ? 'registration.php?id=' . (int) $speaker['registration_id'] : null; ?>
  <tr>
    <td><?php if ($link): ?><a href="<?= $e($link) ?>"><code><?= $e($speaker['ref']) ?></code></a><?php else: ?><code><?= $e($speaker['ref']) ?></code><?php endif; ?></td>
    <td><div class="guest-cell"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $speaker['first_name'], 0, 1))) ?></span><div>
      <?php if ($link): ?><a href="<?= $e($link) ?>"><b><?= $e(Registrations::fullName($speaker)) ?></b></a><?php else: ?><b><?= $e(Registrations::fullName($speaker)) ?></b><?php endif; ?>
      <small><span class="added-by-tag association">Speaker</span>
      <?= match ($state) {
          'waiting' => ' <span class="pill gold">Waiting for PSOOLA</span>',
          'rejected' => ' <span class="pill red">Refused</span>',
          'cancelled' => ' ' . Page::pill('cancelled'),
          default => '',
      } ?></small>
      <?php if ($state === 'rejected' && $speaker['last_error']): ?><small class="muted"><?= $e((string) $speaker['last_error']) ?></small><?php endif; ?>
      <?php if ($state === 'waiting' && $canAdd): ?><form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="retry"><input type="hidden" name="attempt" value="<?= $e($speaker['attempt_key']) ?>"><button class="btn small">Retry</button></form><?php endif; ?>
    </div></div></td>
    <td dir="ltr"><?= $e((string) $speaker['phone']) ?></td>
    <td><?= $e(Registrations::ticketName($speaker['ticket_type'])) ?> <span class="pill green">Free</span></td>
    <td><?php $missing = SpeakerTickets::lunchMissing($speaker); ?>
      <?= $e(SpeakerTickets::lunchText($speaker)) ?>
      <?php if ($state === 'waiting' && ((int) $speaker['want_day1'] + (int) $speaker['want_day2']) > 0): ?><small class="muted">Ticked: <?= $e(implode(' and ', array_map(static fn (int $d): string => 'Day ' . $d, array_values(array_filter([1, 2], static fn (int $d): bool => (int) $speaker['want_day' . $d] === 1))))) ?>, added after the ticket</small><?php endif; ?>
      <?php if ($missing): ?><small><span class="pill gold">Not added: <?= $e(implode(' and ', array_map(static fn (int $d): string => 'Day ' . $d, $missing))) ?></span></small>
      <?php if ($speaker['lunch_error']): ?><small class="muted"><?= $e((string) $speaker['lunch_error']) ?></small><?php endif; ?>
      <?php if ($canAdd): ?><form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="lunch"><input type="hidden" name="attempt" value="<?= $e($speaker['attempt_key']) ?>"><button class="btn small">Add lunch again</button></form><?php endif; ?><?php endif; ?>
    </td>
    <td><?= $state === 'registered' ? $send($speaker['email_status']) : '<span class="muted">–</span>' ?></td>
    <td><?= $state === 'registered' ? $send($speaker['whatsapp_status']) : '<span class="muted">–</span>' ?></td>
    <td><b><?= $e($speaker['added_by_name'] ?? 'Removed account') ?></b></td>
    <td><?= Page::when($speaker['added_at']) ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$speakers): ?><tr class="empty-row"><td colspan="9"><?php if ($filtered): ?><div class="empty-state"><span class="empty-icon"><?= Page::navIcon('search') ?></span><strong>No speaker matches</strong><p>Nobody matches this search and these filters.</p><a class="btn ghost small" href="speaker-tickets.php">Clear filters</a></div><?php else: ?><div class="empty-state arrivals-empty"><span class="empty-icon"><?= Page::navIcon('speaker-tickets') ?></span><strong>No speakers yet</strong><p><?= $canAdd ? 'Add the first speaker. PSOOLA issues a free ticket at once and sends the QR by email and WhatsApp.' : 'Speakers an Owner adds appear here.' ?></p><?php if ($canAdd): ?><a class="btn small" href="speaker-tickets.php?tab=add">+ Add speaker</a><?php endif; ?></div><?php endif; ?></td></tr><?php endif; ?>
</table>
</div>
</section>
<?php
    Page::bottom();
    exit;
}

// ---------- The Add speaker form ----------
$posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$value = static fn (string $key): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
$selected = static fn (string $key, string $option): string => ($_POST[$key] ?? '') === $option ? ' selected' : '';
// The same request key after an error, so a speaker PSOOLA already saved is never sent twice.
$attempt = is_string($_POST['attempt'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $_POST['attempt']) ? $_POST['attempt'] : bin2hex(random_bytes(16));
// Lunch days start unticked: the Owner ticks them for each speaker.
$lunch = static fn (int $day): bool => $posted && ($_POST['lunch_day' . $day] ?? '') === '1';
$lunchDays = [1 => ['Thursday 19 Nov', 'Thu 19 Nov'], 2 => ['Friday 20 Nov', 'Fri 20 Nov']];
?>
<div class="cash-layout speaker-layout">
<div class="cash-main">
<form method="post" action="speaker-tickets.php?tab=add" class="card stack caller-form" id="speaker-form">
  <?= Page::csrfField() ?><input type="hidden" name="attempt" value="<?= $e($attempt) ?>">
  <p class="muted">For a speaker: enter their details and press <b>Add speaker</b>. PSOOLA issues a <b>free ticket</b> (0 IQD) at once, with free lunch on the days you tick, and sends the QR by email and WhatsApp.</p>
  <div class="association-guest-banner"><span class="added-by-tag association">Speaker</span><span>Saved as a speaker. The ticket and the lunch are free.</span></div>
  <fieldset class="form-section">
    <legend>1. Speaker's details</legend>
    <div class="row3"><label>First name<input name="first_name" minlength="2" maxlength="60" value="<?= $value('first_name') ?>" autocomplete="off" required></label><label>Second name<input name="father_name" minlength="2" maxlength="60" value="<?= $value('father_name') ?>" required></label><label>Last name<input name="grandfather_name" minlength="2" maxlength="60" value="<?= $value('grandfather_name') ?>" required></label></div>
    <div class="row3"><label>WhatsApp number<input type="tel" name="phone" dir="ltr" autocomplete="off" maxlength="30" value="<?= $value('phone') ?>" required placeholder="0750 123 4567"></label><label>Email<input type="email" name="email" dir="ltr" autocomplete="off" maxlength="190" value="<?= $value('email') ?>" required></label><label>City<?= \Ismile\Cities::select($_POST['city'] ?? '') ?></label></div>
    <div class="row3"><label>Specialty<select name="specialty" required><option value="">Choose specialty</option><?php foreach (Registrations::SPECIALTY_NAMES as $key => $name): ?><option value="<?= $e($key) ?>"<?= $selected('specialty', $key) ?>><?= $e($name) ?></option><?php endforeach; ?></select><small>A dental student gets a free Student ticket, everyone else a free Dentist ticket.</small></label></div>
  </fieldset>
  <fieldset class="form-section">
    <legend>2. Free lunch</legend>
    <div class="lunch-cards">
      <?php foreach (array_map(static fn (array $d): string => $d[0], $lunchDays) as $day => $date): ?>
      <label class="lunch-card"><input type="checkbox" name="lunch_day<?= $day ?>" value="1"<?= $lunch($day) ? ' checked' : '' ?>>
        <span class="lunch-card-text"><b>Lunch day <?= $day ?></b><small><?= $e($date) ?></small></span>
        <span class="lunch-card-price"><b>Free</b><small>sent to PSOOLA</small></span>
      </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <label class="cash-confirm-check"><input type="checkbox" name="consent" value="1"<?= ($_POST['consent'] ?? '') === '1' ? ' checked' : '' ?> required><span>The speaker agreed to get the ticket by email and WhatsApp.</span></label>
  <div class="form-actions"><a class="btn ghost" href="speaker-tickets.php">Cancel</a><button class="btn violet" name="do" value="add"<?= Page::confirm('violet', 'gift', 'Add this speaker?', 'PSOOLA issues a free ticket now and sends the QR by email and WhatsApp. This cannot be undone here.', [['ticket', 'Free ticket, 0 IQD'], ['check', 'Free lunch on the ticked days']], ['yes' => 'Yes, add and send QR', 'no' => 'No, go back']) ?>>Add speaker</button></div>
</form>
</div>
<aside class="cash-panel speaker-panel" aria-label="Speaker pass preview">
  <section class="speaker-pass" aria-live="polite">
    <header class="speaker-pass-top">
      <span class="speaker-pass-mic" aria-hidden="true"><?= Page::navIcon('speaker-tickets') ?></span>
      <div><small>iSmile 2026 Summit</small><b>Speaker pass</b></div>
      <span class="speaker-pass-free"><?= Page::navIcon('check') ?>Free</span>
    </header>
    <div class="speaker-pass-body">
      <span class="speaker-pass-label">Prepared for</span>
      <strong data-speaker-name>Speaker name</strong>
      <p data-speaker-specialty>Choose a specialty</p>
      <span class="speaker-pass-ticket" data-speaker-ticket><?= Page::navIcon('ticket') ?><span>Dentist ticket</span></span>
      <span class="speaker-pass-label">Free lunch</span>
      <div class="speaker-pass-lunch">
        <?php foreach ($lunchDays as $day => [, $short]): ?>
        <span class="speaker-lunch-day<?= $lunch($day) ? ' on' : '' ?>" data-speaker-lunch="<?= $day ?>"><?= Page::navIcon('lunch') ?><b>Day <?= $day ?></b><small><?= $e($short) ?></small></span>
        <?php endforeach; ?>
      </div>
    </div>
    <footer class="speaker-pass-total">
      <div><span>Total</span><strong>0 <small>IQD</small></strong></div>
      <p>Ticket and lunch are free.<br>Nothing to collect.</p>
    </footer>
  </section>
  <section class="card speaker-steps">
    <h3>When you press Add speaker</h3>
    <ol>
      <li><b>PSOOLA issues the free ticket</b><small>0 IQD, a Dentist or Student ticket</small></li>
      <li><b>Free lunch is added</b><small>Right after the ticket, on the days you ticked</small></li>
      <li><b>The QR is sent</b><small>To the speaker's email and WhatsApp</small></li>
    </ol>
    <div class="speaker-send"><span><?= Page::navIcon('mail') ?>Email</span><span><?= Page::navIcon('phone') ?>WhatsApp</span></div>
  </section>
</aside>
</div>
<?php
Page::bottom();
