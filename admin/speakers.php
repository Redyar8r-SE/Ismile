<?php
// Speakers (Owner only): every speaker on the website, and a free summit
// ticket for each one. The ticket is a complimentary PSOOLA order: PSOOLA
// emails the QR and sends it on WhatsApp when the speaker agreed. Lunch on
// Day 1 and Day 2 rides on the same free order. The name badge says "Speaker".
// ?speaker=NAME opens the Register form for that speaker; ?speaker= with
// new=1 registers someone who is not on the website yet.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\SpeakerGuests;
use Ismile\UserError;

$user = Page::guard('owner');

// ---------- Register / ask PSOOLA again ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    try {
        Auth::checkCsrf();
        if ($do === 'register') {
            $order = SpeakerGuests::register($user, $_POST);
            $speaker = Page::post('speaker_name', 120);
            $whatsapp = ($_POST['whatsapp'] ?? '') === '1';
            Page::flash('ok', $order['status'] === 'issued'
                ? 'Registered ' . $speaker . ' for free (' . $order['partner_reference'] . '). PSOOLA is emailing the QR ticket' . ($whatsapp ? ' and sending it on WhatsApp.' : '.')
                : 'Saved ' . $speaker . ' (' . $order['partner_reference'] . '). PSOOLA has not confirmed the ticket yet: press "Ask PSOOLA again" in the list in a minute.');
            Page::redirect('speakers.php');
        }
        if ($do === 'retry') {
            $order = SpeakerGuests::retry($user, Page::post('ref', 12));
            Page::flash($order['status'] === 'issued' ? 'ok' : 'error', $order['status'] === 'issued'
                ? 'PSOOLA confirmed the ticket for ' . $order['partner_reference'] . ' and is sending the QR.'
                : 'PSOOLA has still not confirmed ' . $order['partner_reference'] . '. Try again in a minute.');
            Page::redirect('speakers.php');
        }
        throw new UserError('Press Register or Ask PSOOLA again.');
    } catch (UserError $error) {
        Page::flash('error', $error->key);
        if ($do === 'retry') Page::redirect('speakers.php');
    }
}

$e = [Page::class, 'e'];
$website = SpeakerGuests::website();
$rows = SpeakerGuests::all();
$bySpeaker = [];
foreach ($rows as $row) {
    // The newest ticket of each speaker (a refused one is replaced by a new one).
    if (!isset($bySpeaker[$row['speaker_name']])) $bySpeaker[$row['speaker_name']] = $row;
}
$refs = array_values(array_map(static fn (array $row): string => (string) $row['ref'], array_filter($bySpeaker, static fn (array $row): bool => SpeakerGuests::state($row) === 'issued')));
$delivery = SpeakerGuests::deliveries($refs);

// Everyone to list: the website's speakers, then anyone registered who is not on it.
$people = [];
foreach ($website as $name => $speaker) $people[$name] = $speaker + ['ticket' => $bySpeaker[$name] ?? null, 'extra' => false];
foreach ($bySpeaker as $name => $row) if (!isset($people[$name])) $people[$name] = ['name' => $name, 'role' => '', 'topic' => '', 'photo' => null, 'ticket' => $row, 'extra' => true];

$counts = ['all' => count($people), 'issued' => 0, 'waiting' => 0, 'none' => 0, 'email' => 0, 'whatsapp' => 0];
foreach ($people as $person) {
    $state = $person['ticket'] ? SpeakerGuests::state($person['ticket']) : 'none';
    if ($state === 'issued') {
        $counts['issued']++;
        $sent = $delivery[$person['ticket']['ref']] ?? null;
        if (($sent['email'] ?? '') === 'delivered') $counts['email']++;
        if (in_array($sent['whatsapp'] ?? '', ['delivered', 'read'], true)) $counts['whatsapp']++;
    } elseif ($state === 'waiting') {
        $counts['waiting']++;
    } else {
        $counts['none']++;
    }
}

// Search and filter (instant, like Registrations).
$q = Page::query('q', 80);
$show = in_array(Page::query('show'), ['registered', 'not-yet'], true) ? Page::query('show') : '';
$needle = mb_strtolower($q);
$list = array_filter($people, static function (array $person) use ($needle, $show): bool {
    $state = $person['ticket'] ? SpeakerGuests::state($person['ticket']) : 'none';
    if ($show === 'registered' && $state !== 'issued') return false;
    if ($show === 'not-yet' && $state === 'issued') return false;
    if ($needle === '') return true;
    $name = mb_strtolower(preg_replace('/^(dr|dt|prof)\.?\s+/iu', '', $person['name']) ?? $person['name']);
    foreach (preg_split('/\s+/u', $name) ?: [] as $word) if (str_starts_with($word, $needle)) return true;
    return str_contains(mb_strtolower($person['name'] . ' ' . $person['topic']), $needle);
});

// The Register form, for one speaker.
$chosen = Page::query('speaker', 120);
$isNew = Page::query('new') === '1';
$formFor = null;
if ($chosen !== '' && isset($people[$chosen]) && (!$people[$chosen]['ticket'] || SpeakerGuests::state($people[$chosen]['ticket']) === 'refused')) $formFor = $people[$chosen];
if ($isNew) $formFor = ['name' => '', 'role' => '', 'topic' => '', 'photo' => null, 'ticket' => null, 'extra' => true];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'register') {
    $posted = Page::post('speaker_name', 120);
    $formFor = $people[$posted] ?? ['name' => $posted, 'role' => '', 'topic' => '', 'photo' => null, 'ticket' => null, 'extra' => true];
}
$value = static fn (string $key, string $default = ''): string => Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : $default);
// The same attempt again only re-asks PSOOLA for that one order; after PSOOLA
// refused it, a corrected form is a new attempt.
$attempt = is_string($_POST['attempt'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $_POST['attempt'])
    && \Ismile\Db::value("SELECT COUNT(*) FROM sponsored_ticket_orders WHERE attempt_key = ? AND status = 'rejected'", [$_POST['attempt']]) == 0
    ? $_POST['attempt'] : bin2hex(random_bytes(16));
$ticked = static fn (string $key): string => ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST[$key] ?? '') === '1' ? ' checked' : '';
$initials = static fn (string $name): string => mb_strtoupper(mb_substr(preg_replace('/^(dr|dt|prof)\.?\s+/iu', '', $name) ?: $name, 0, 1));

Page::top('Speakers', 'speakers', $formFor ? '<a class="btn ghost" href="speakers.php">&larr; All speakers</a>' : '<a class="btn" href="speakers.php?new=1#speaker-form">+ Speaker not on the website</a>');

if ($formFor): ?>
<section class="card speaker-form-card" id="speaker-form">
  <div class="speaker-form-head">
    <span class="speaker-avatar big" aria-hidden="true"><?= $formFor['name'] !== '' ? $e($initials($formFor['name'])) : Page::navIcon('speakers') ?></span>
    <div>
      <span class="attendance-label">Free speaker ticket</span>
      <h2><?= $formFor['name'] !== '' ? $e($formFor['name']) : 'A speaker not on the website' ?></h2>
      <?php if ($formFor['topic'] !== ''): ?><p class="muted"><?= $e($formFor['topic']) ?></p><?php endif; ?>
    </div>
  </div>
  <form method="post" action="speakers.php" class="speaker-form">
    <?= Page::csrfField() ?>
    <input type="hidden" name="do" value="register">
    <input type="hidden" name="attempt" value="<?= $e($attempt) ?>">
    <?php if ($formFor['extra']): ?>
    <label>Speaker name<input name="speaker_name" maxlength="120" required value="<?= $value('speaker_name', $formFor['name']) ?>" placeholder="Dr. First Last" autocomplete="off"></label>
    <?php else: ?>
    <input type="hidden" name="speaker_name" value="<?= $e($formFor['name']) ?>">
    <?php endif; ?>
    <label>Name on the ticket and badge<input name="ticket_name" maxlength="120" value="<?= $value('ticket_name', $formFor['name']) ?>" placeholder="As the speaker wants it printed" autocomplete="off"><small>The badge prints this name with the word Speaker.</small></label>
    <div class="speaker-form-row">
      <label>Email<input type="email" name="email" maxlength="190" required dir="ltr" value="<?= $value('email') ?>" placeholder="name@example.com" autocomplete="off"><small>PSOOLA emails the QR ticket here.</small></label>
      <label>WhatsApp number<input type="tel" name="phone" maxlength="30" required dir="ltr" value="<?= $value('phone') ?>" placeholder="0750 123 4567 or +90 532 123 4567" autocomplete="off"><small>Iraqi numbers can start with 07. Other countries need the + code.</small></label>
    </div>
    <fieldset class="speaker-choices">
      <legend>Free lunch</legend>
      <label class="speaker-choice"><input type="checkbox" name="lunch_day1" value="1"<?= $ticked('lunch_day1') ?>><span><b>Day 1</b><small>Thursday 19 November</small></span></label>
      <label class="speaker-choice"><input type="checkbox" name="lunch_day2" value="1"<?= $ticked('lunch_day2') ?>><span><b>Day 2</b><small>Friday 20 November</small></span></label>
    </fieldset>
    <label class="speaker-choice wide"><input type="checkbox" name="whatsapp" value="1"<?= $ticked('whatsapp') ?>><span><b>Send the QR on WhatsApp too</b><small>Tick only if the speaker agreed to get the ticket on WhatsApp.</small></span></label>
    <div class="form-actions">
      <button class="btn green" data-confirm="Register this speaker for free?" data-confirm-box='<?= $e(json_encode(['tone' => 'green', 'icon' => 'ticket', 'title' => 'Register this speaker for free?', 'text' => 'PSOOLA issues the ticket at once and sends the QR. This cannot be undone here.', 'form' => 'speaker', 'yes' => 'Yes, register and send QR'], JSON_UNESCAPED_SLASHES)) ?>'>Register free and send QR</button>
      <a class="btn ghost" href="speakers.php">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<div data-live-region="speaker-totals">
<section class="card association-summary speaker-summary" aria-label="Speakers">
  <div class="association-summary-head">
    <span class="association-guest-logo speaker-logo" aria-hidden="true"><?= Page::navIcon('speakers') ?></span>
    <div><span class="attendance-label">iSmile 2026</span><h2>Speakers</h2></div>
    <span class="association-paylater"><?= Page::navIcon('ticket') ?>Free ticket, QR by email and WhatsApp</span>
  </div>
  <div class="association-stats speaker-stats">
    <div class="association-stat teal"><span>Speakers</span><strong><?= number_format($counts['all']) ?></strong><small>On the website and added here</small></div>
    <div class="association-stat green"><span>Registered</span><strong><?= number_format($counts['issued']) ?></strong><small>Free ticket sent</small></div>
    <div class="association-stat grey"><span>Not registered yet</span><strong><?= number_format($counts['none']) ?></strong><small>No ticket yet</small></div>
    <div class="association-stat blue"><span>QR email delivered</span><strong><?= number_format($counts['email']) ?></strong><small><?= $delivery === null ? 'PSOOLA not reachable now' : 'As PSOOLA reports' ?></small></div>
    <div class="association-stat violet"><span>WhatsApp delivered</span><strong><?= number_format($counts['whatsapp']) ?></strong><small><?= $delivery === null ? 'PSOOLA not reachable now' : 'As PSOOLA reports' ?></small></div>
    <div class="association-stat gold"><span>Waiting for PSOOLA</span><strong><?= number_format($counts['waiting']) ?></strong><small>Ask PSOOLA again</small></div>
  </div>
</section>
</div>

<form class="card registration-filter-card speaker-filter-card" method="get" action="speakers.php" data-instant-search data-search-regions="speaker-directory">
  <div class="registration-search-row">
    <label class="filter-search registration-search"><?= Page::navIcon('search') ?><span class="sr-only">Speaker name or topic</span><input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search speaker name or topic"></label>
    <fieldset class="filter-seg speaker-seg"><legend>Show</legend><div>
      <?php foreach (['' => 'Everyone', 'registered' => 'Registered', 'not-yet' => 'Not registered yet'] as $key => $label): ?>
      <label><input type="radio" name="show" value="<?= $e($key) ?>"<?= $show === $key ? ' checked' : '' ?>><span><?= $e($label) ?></span></label>
      <?php endforeach; ?>
    </div></fieldset>
  </div>
</form>

<div data-live-region="speaker-directory">
<section class="card directory-panel speaker-directory"><div class="panel-top"><div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('speakers') ?></span><div><h2>Speaker list <span class="result-count"><?= count($list) ?></span></h2><p>Press Register to send a speaker a free ticket. PSOOLA emails the QR and sends it on WhatsApp.<?= $delivery === null ? ' PSOOLA could not be reached for delivery status just now.' : '' ?></p></div></div></div>
<div class="table-wrap registration-directory-scroll">
<table class="speaker-table">
  <tr><th>Speaker</th><th>Ticket</th><th>QR email</th><th>WhatsApp</th><th>Lunch</th><th></th></tr>
  <?php foreach ($list as $person):
      $ticket = $person['ticket'];
      $state = $ticket ? SpeakerGuests::state($ticket) : 'none';
      $sent = $ticket ? ($delivery[$ticket['ref']] ?? null) : null;
      $guest = $ticket ? SpeakerGuests::guest($ticket) : [];
      $lunch = $ticket ? SpeakerGuests::lunch($ticket) : ['day1' => false, 'day2' => false];
      $deliveryPill = static function (?string $status) use ($e): string {
          if ($status === null || $status === '') return '<span class="muted">Not yet</span>';
          $tone = match ($status) { 'delivered', 'read' => 'green', 'bounced', 'failed', 'complained', 'undeliverable' => 'red', default => 'gold' };
          return '<span class="pill ' . $tone . '">' . $e(ucfirst($status)) . '</span>';
      }; ?>
  <tr>
    <td><div class="guest-cell"><span class="speaker-avatar" aria-hidden="true"><?= $e($initials($person['name'])) ?></span><div>
      <?php if ($ticket && $ticket['registration_id']): ?><a href="registration.php?id=<?= (int) $ticket['registration_id'] ?>"><b><?= $e($person['name']) ?></b></a><?php else: ?><b><?= $e($person['name']) ?></b><?php endif; ?>
      <small><?= $person['topic'] !== '' ? $e(mb_strimwidth($person['topic'], 0, 90, '…')) : ($person['extra'] ? '<span class="added-by-tag">Added here</span>' : '') ?></small>
      <?php if ($ticket && ($guest['email'] ?? '') !== ''): ?><small class="speaker-contact" dir="ltr"><?= $e($guest['email']) ?> <?= $e($guest['phone'] ?? '') ?></small><?php endif; ?>
    </div></div></td>
    <td><?= match ($state) {
        'issued' => '<span class="pill green">Free ticket sent</span> <code>' . $e($ticket['ref']) . '</code>',
        'waiting' => '<span class="pill gold">Waiting for PSOOLA</span>',
        'refused' => '<span class="pill red">PSOOLA refused</span>' . ($ticket['last_error'] ? '<small class="speaker-error">' . $e(mb_strimwidth((string) $ticket['last_error'], 0, 80, '…')) . '</small>' : ''),
        default => '<span class="muted">Not registered</span>',
    } ?></td>
    <td><?= $state === 'issued' ? $deliveryPill($sent['email'] ?? null) : '<span class="muted">–</span>' ?></td>
    <td><?= $state === 'issued' ? (str_contains((string) ($ticket['request_json'] ?? ''), '"whatsappConsent"') ? $deliveryPill($sent['whatsapp'] ?? null) : '<span class="muted">Not asked</span>') : '<span class="muted">–</span>' ?></td>
    <td><?php if (!$ticket): ?><span class="muted">–</span><?php else: ?><?php if ($lunch['day1'] || $lunch['day2']): ?><span class="speaker-lunch"><?= $lunch['day1'] ? '<span class="pill">Day 1</span>' : '' ?><?= $lunch['day2'] ? '<span class="pill">Day 2</span>' : '' ?></span><?php else: ?><span class="muted">No lunch</span><?php endif; ?><?php endif; ?></td>
    <td class="speaker-action"><?php if ($state === 'none' || $state === 'refused'): ?>
      <a class="btn small" href="speakers.php?speaker=<?= $e(rawurlencode($person['name'])) ?>#speaker-form"><?= $state === 'refused' ? 'Register again' : 'Register' ?></a>
    <?php elseif ($state === 'waiting'): ?>
      <form method="post" action="speakers.php"><?= Page::csrfField() ?><input type="hidden" name="do" value="retry"><input type="hidden" name="ref" value="<?= $e($ticket['ref']) ?>"><button class="btn small ghost">Ask PSOOLA again</button></form>
    <?php elseif ($ticket['registration_id']): ?>
      <a class="btn small ghost" href="registration.php?id=<?= (int) $ticket['registration_id'] ?>">Open guest</a>
    <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr class="empty-row"><td colspan="6"><div class="empty-state"><span class="empty-icon"><?= Page::navIcon('search') ?></span><strong>No speaker matches</strong><p>Try other letters, or choose Everyone.</p><a class="btn ghost" href="speakers.php">Clear search</a></div></td></tr><?php endif; ?>
</table>
</div>
</section>
</div>
<?php
Page::bottom();
