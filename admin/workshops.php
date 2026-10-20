<?php
// Workshops: seats total, booked, paid and left for each workshop, and the
// people in it with their phone and payment. Callers are booked right here:
// search their name, see if their event ticket is paid, book, and record the
// workshop payment (exact amount) now or later. Export per workshop gives
// the trainer's sign-in sheet. The website's "seats left" follows these numbers.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Db;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\UserError;
use Ismile\Validate;
use Ismile\Workshops;

$user = Page::guard('workshops');
$workshops = SiteData::workshops();
$open = Page::query('id');
$q = Page::query('q');
$back = 'workshops.php?' . http_build_query(array_filter(['id' => $open, 'q' => $q]));

Page::action(static function () use ($user): string {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'workshop_add') {
        $registration = Registrations::find((int) ($_POST['rid'] ?? 0));
        if ($registration === null) {
            throw new UserError('Person not found.');
        }
        return Office::addWorkshop($registration, $user, $_POST);
    }
    if ($do === 'workshop_change') {
        return Office::changeWorkshop((int) ($_POST['booking'] ?? 0), $user, $_POST);
    }
    // ---- the workshops themselves (Owner) ----
    $id = (string) ($_POST['ws'] ?? '');
    return match ($do) {
        'ws_create' => 'Workshop added: ' . SiteData::workshopName(Workshops::find(Workshops::create($_POST, $user, $_FILES['photo'] ?? null))) . '. It is on the website now.',
        'ws_update' => (static function () use ($id, $user): string {
            Workshops::update($id, $_POST, $user, $_FILES['photo'] ?? null);
            return 'Workshop saved. The website shows the change.';
        })(),
        'ws_seats'  => 'Seats now: ' . Workshops::changeSeats($id, (int) ($_POST['delta'] ?? 0), $user) . '.',
        'ws_hide'   => (static function () use ($id, $user): string {
            Workshops::setStatus($id, 'hidden', $user);
            return 'Hidden: no longer on the website and no new bookings. The people already booked stay.';
        })(),
        'ws_show'   => (static function () use ($id, $user): string {
            Workshops::setStatus($id, 'active', $user);
            return 'Shown on the website again.';
        })(),
        'ws_delete' => (static function () use ($id, $user): string {
            Workshops::delete($id, $user);
            return 'Workshop deleted.';
        })(),
        default     => throw new UserError('Unknown action.'),
    };
}, $back);

// ---- "Book a caller": find the person by name, phone or reference ----
$found = [];
if ($q !== '') {
    $phone = Validate::phone($q);
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $found = Db::all(
        "SELECT r.* FROM registrations r
         WHERE r.status IN ('paid','complimentary') AND (CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ? OR r.ref = ?" . ($phone ? ' OR r.phone = ?' : '') . ')
         ORDER BY r.status IN (\'paid\',\'complimentary\') DESC, r.first_name LIMIT 15',
        $phone ? [$like, strtoupper($q), $phone] : [$like, strtoupper($q)]
    );
}

$workshopCounts = array_column(Db::all("SELECT workshop_id, COUNT(*) AS booked, COALESCE(SUM(payment_status='paid'),0) AS paid, COALESCE(SUM(payment_status='complimentary'),0) AS free, COALESCE(SUM(payment_status='unpaid'),0) AS unpaid, COALESCE(SUM(amount_paid),0) AS money FROM workshop_bookings WHERE removed_at IS NULL GROUP BY workshop_id"), null, 'workshop_id');
$availableTotal = $bookingTotal = $unpaidTotal = 0;
foreach ($workshops as $workshop) {
    $availableTotal += max(0,(int) ($workshop['totalSeats'] ?? 0) - (int) ($workshopCounts[$workshop['id']]['booked'] ?? 0));
    $bookingTotal += (int) ($workshopCounts[$workshop['id']]['booked'] ?? 0);
    $unpaidTotal += (int) ($workshopCounts[$workshop['id']]['unpaid'] ?? 0);
}
Page::top('Workshops', 'workshops', ($user['role']==='owner' ? '<a class="btn ghost" href="#manage">Workshop settings</a>' : '') . '<a class="btn" href="#book">Book a guest ' . Page::navIcon('arrow') . '</a>');
$e = [Page::class, 'e'];
?>
<?= Page::stats([
    ['Active workshops', number_format(count($workshops)), 'Workshops available for booking', 'workshops', 'blue'],
    ['Seats available', number_format($availableTotal), 'Across all active workshops', 'ticket', 'teal'],
    ['Workshop bookings', number_format($bookingTotal), 'Seats reserved in active workshops', 'users', 'violet'],
    ['Payment to collect', number_format($unpaidTotal), 'Unpaid workshop bookings', 'payments', 'gold'],
]) ?>
<div class="workshop-grid" id="workshop-grid">
<?php foreach ($workshops as $i => $workshop):
    $counts = $workshopCounts[$workshop['id']] ?? ['booked'=>0,'paid'=>0,'free'=>0,'unpaid'=>0,'money'=>0];
    $total = (int) ($workshop['totalSeats'] ?? 0);
    $left = max(0,$total-(int)$counts['booked']);
    $speaker = is_array($workshop['speaker'] ?? null) ? ($workshop['speaker']['en'] ?? '') : '';
?>
  <article class="card workshop-card<?= $left===0 ? ' is-full' : '' ?>">
    <div class="workshop-card-top"><span class="workshop-card-icon"><?= Page::navIcon('workshops') ?></span><span class="pill <?= $left>0 ? 'green':'red' ?>"><?= $left>0 ? $left.' seats left':'Full' ?></span></div>
    <span class="workshop-number">WORKSHOP <?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span>
    <h2><?= $e(SiteData::workshopName($workshop)) ?></h2><p class="workshop-speaker"><?= $speaker!=='' ? $e($speaker) : 'Trainer to be announced' ?></p>
    <div class="workshop-price"><strong><?= (int)($workshop['price'] ?? 0)>0 ? $e(Workshops::money((int)$workshop['price'], $workshop['currency'])) : 'Price to be set' ?></strong><span>per seat</span></div>
    <div class="capacity-label"><span>Seat availability</span><b><?= (int)$counts['booked'] ?> / <?= $total ?> booked</b></div>
    <progress value="<?= min((int)$counts['booked'],max(1,$total)) ?>" max="<?= max(1,$total) ?>" aria-label="<?= $e(SiteData::workshopName($workshop).' booked seats') ?>"></progress>
    <div class="workshop-payment"><span><i class="dot teal"></i><?= (int)$counts['paid'] ?> paid<?= (int)$counts['free'] ? ' · '.(int)$counts['free'].' free':'' ?></span><span><?= (int)$counts['unpaid'] ?> unpaid</span></div>
    <div class="workshop-collected"><span>Collected</span><b><?= $e(Workshops::money((int)$counts['money'], $workshop['currency'])) ?></b></div>
    <div class="workshop-card-actions"><a class="btn ghost" href="export.php?what=workshop&amp;id=<?= rawurlencode($workshop['id']) ?>&amp;format=pdf">PDF sign-in sheet</a><a class="btn" href="workshops.php?id=<?= rawurlencode($workshop['id']) ?>#people">View people</a><a class="btn ghost" href="export.php?what=workshop&amp;id=<?= rawurlencode($workshop['id']) ?>" aria-label="<?= $e('Export sign-in sheet for '.SiteData::workshopName($workshop)) ?>"><?= Page::navIcon('download') ?> Sign-in sheet</a></div>
  </article>
<?php endforeach; ?>
</div>
<?php if (!$workshops): ?><div class="card"><?= Page::emptyState('Your workshops start here', 'Add an active workshop to begin taking bookings.', 'workshops') ?></div><?php endif; ?>

<div class="card book-caller" id="book">
  <?= Page::panelHeading('Book a caller', 'Find a registered guest and reserve their workshop seat.', 'registrations') ?>
  <p class="muted">Type the caller's name, phone or ISM26-… reference. Only <b>registered</b> people (they paid the event ticket) can take a workshop seat. Not registered yet? <a href="registration.php?new=1">Register them by phone</a>: they get a "Pay now" link and appear here once they have paid.</p>
  <form method="get" class="filters" action="workshops.php#book">
    <?php if ($open !== ''): ?><input type="hidden" name="id" value="<?= $e($open) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Name, phone or ISM26-…" aria-label="Find a registered guest for a workshop">
    <div class="search-actions"><?php if ($q !== ''): ?><a class="btn ghost" href="workshops.php">Clear</a><?php endif; ?><button class="btn">Find</button></div>
  </form>
  <?php foreach ($found as $person):
      $booked = Db::all('SELECT wb.*, wb.workshop_id FROM workshop_bookings wb WHERE wb.registration_id = ? AND wb.removed_at IS NULL', [$person['id']]); ?>
  <div class="person-row">
    <div class="person-head">
      <b class="big-name"><?= $e(Registrations::fullName($person)) ?></b> <?= Page::paidBadge($person['status']) ?>
      <span class="muted" dir="ltr"><?= $e($person['phone']) ?></span> · <code><?= $e($person['ref']) ?></code> · <?= $e(\Ismile\Registrations::ticketName($person['ticket_type'])) ?>
      <a href="registration.php?id=<?= (int) $person['id'] ?>">open</a>
    </div>
    <?php if ($booked): ?>
      <p class="small">Already booked:
        <?php foreach ($booked as $booking): $workshop = SiteData::workshop($booking['workshop_id']); ?>
          <span class="chip"><?= $e($workshop ? SiteData::workshopName($workshop) : $booking['workshop_id']) ?> <?= Page::paidBadge($booking['payment_status']) ?></span>
        <?php endforeach; ?></p>
    <?php endif; ?>
    <?= Page::workshopAddForm($user, $person, $back . '#book') ?>
  </div>
  <?php endforeach; ?>
  <?php if ($q !== '' && !$found): ?><p class="flash warn">Nobody registered found for “<?= $e($q) ?>”. Only people who paid are registered. <a href="registration.php?new=1">Register them by phone</a> first.</p><?php endif; ?>
</div>

<?php if ($user['role'] === 'owner'):
    $all = Workshops::all(true);
    $iconSelect = static function (string $current) use ($e): string {
        $html = '<select name="icon">';
        foreach (Workshops::ICONS as $icon) {
            $html .= '<option value="' . $icon . '"' . ($icon === $current ? ' selected' : '') . '>' . $icon . '</option>';
        }
        return $html . '</select>';
    };
    $currencyOptions = static function (string $current): string {
        $html = '';
        foreach (['IQD' => 'IQD (dinar)', 'USD' => 'USD (dollar, $)'] as $code => $label) {
            $html .= '<option value="' . $code . '"' . ($code === $current ? ' selected' : '') . '>' . $label . '</option>';
        }
        return $html;
    };
    $siteUrl = static fn (string $path): string => rtrim((string) \Ismile\App::config('site_url'), '/') . '/' . ltrim($path, '/');
    $fieldsFor = static function (?array $w) use ($e, $iconSelect, $currencyOptions, $siteUrl): string {
        $v = static fn (string $field, string $lang) => $e((string) ($w[$field][$lang] ?? ''));
        $row3 = static fn (string $field, string $label, string $example) => '<div class="row3">'
            . '<label>' . $e($label) . ' (English)<input name="' . $field . '_en" placeholder="' . $e($example) . '" value="' . $v($field, 'en') . '"></label>'
            . '<label>' . $e($label) . ' (Arabic)<input name="' . $field . '_ar" dir="rtl" value="' . $v($field, 'ar') . '"></label>'
            . '<label>' . $e($label) . ' (Kurdish)<input name="' . $field . '_ku" dir="rtl" value="' . $v($field, 'ku') . '"></label></div>';
        return '<div class="row3">'
            . '<label>Name (English)<input name="title_en" value="' . $v('title', 'en') . '"></label>'
            . '<label>Name (Arabic)<input name="title_ar" dir="rtl" value="' . $v('title', 'ar') . '"></label>'
            . '<label>Name (Kurdish)<input name="title_ku" dir="rtl" value="' . $v('title', 'ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Trainer (English, optional)<input name="speaker_en" value="' . $v('speaker', 'en') . '"></label>'
            . '<label>Trainer (Arabic)<input name="speaker_ar" dir="rtl" value="' . $v('speaker', 'ar') . '"></label>'
            . '<label>Trainer (Kurdish)<input name="speaker_ku" dir="rtl" value="' . $v('speaker', 'ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Company (English, optional)<input name="company_en" value="' . $v('company', 'en') . '"></label>'
            . '<label>Company (Arabic)<input name="company_ar" dir="rtl" value="' . $v('company', 'ar') . '"></label>'
            . '<label>Company (Kurdish)<input name="company_ku" dir="rtl" value="' . $v('company', 'ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Price per seat (0 = not set yet)<input name="price" inputmode="numeric" value="' . (int) ($w['price'] ?? 0) . '"></label>'
            . '<label>Currency<select name="currency">' . $currencyOptions((string) ($w['currency'] ?? 'IQD')) . '</select></label>'
            . '<label>Seats (the limit)<input type="number" min="1" max="2000" name="total_seats" value="' . (int) ($w['totalSeats'] ?? 20) . '" required></label></div>'
            . '<h3 class="form-subhead">Trainer photo</h3>'
            . '<div class="workshop-photo-field">'
            . ($w !== null && !empty($w['photo']) ? '<img class="workshop-photo-preview" src="' . $e($siteUrl($w['photo'])) . '" alt="Current photo of the trainer" width="120" height="120">' : '')
            . '<label>Photo of the trainer (JPEG, PNG or WebP, up to 5 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>'
            . ($w !== null && !empty($w['photo']) ? '<label class="inline"><input type="checkbox" name="remove_photo" value="1"> Remove the photo</label>' : '')
            . '<label>Picture when there is no photo' . $iconSelect((string) ($w['icon'] ?? 'tools')) . '</label></div>'
            . '<h3 class="form-subhead">Details page</h3>'
            . $row3('credentials', "Trainer's degrees", 'e.g. BDS, MSc, PhD in Restorative Dentistry')
            . $row3('format', 'Format', 'e.g. Hands-on Clinical Workshop')
            . $row3('duration', 'Duration', 'e.g. 3 Hours')
            . $row3('level', 'Level', 'e.g. Intermediate to Advanced')
            . '<div class="row3">'
            . '<label>Overview (English)<textarea name="overview_en" rows="4">' . $v('overview', 'en') . '</textarea></label>'
            . '<label>Overview (Arabic)<textarea name="overview_ar" rows="4" dir="rtl">' . $v('overview', 'ar') . '</textarea></label>'
            . '<label>Overview (Kurdish)<textarea name="overview_ku" rows="4" dir="rtl">' . $v('overview', 'ku') . '</textarea></label></div>';
    }; ?>
<div class="card" id="manage">
  <?= Page::panelHeading('Workshop settings', 'Names, prices, visibility and seat limits. Owner access.', 'settings') ?>
  <p class="muted">Add a new workshop, change its name, details, photo, price or seats, or hide it. The website's workshop cards and details pages change at once. The seats are the limit: nobody can be booked past it, and the seats can never be fewer than the people already booked.</p>
  <div class="table-wrap"><table>
    <tr><th>Workshop</th><th>On the website</th><th>Seats (limit)</th><th>Booked</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($all as $w): ?>
    <tr>
      <td><b><?= $e(SiteData::workshopName($w)) ?></b><br><small class="muted"><?= $e($w['id']) ?> · <?= $w['price'] > 0 ? $e(Workshops::money($w['price'], $w['currency'])) : 'price not set' ?></small></td>
      <td><?= $w['status'] === 'active' ? '<span class="pill green">Shown</span>' : '<span class="pill grey">Hidden</span>' ?></td>
      <td>
        <div class="seat-buttons">
          <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_seats"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>"><input type="hidden" name="delta" value="-1"><button class="btn small ghost" title="One seat fewer">−</button></form>
          <b class="seat-count"><?= (int) $w['totalSeats'] ?></b>
          <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_seats"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>"><input type="hidden" name="delta" value="1"><button class="btn small ghost" title="One seat more">+</button></form>
        </div>
      </td>
      <td><b><?= (int) $w['booked'] ?></b> <small class="muted">· <?= (int) $w['seatsLeft'] ?> left</small></td>
      <td class="table-action-cell"><div class="row-actions">
        <details class="edit"><summary class="btn small ghost">Edit</summary>
          <form method="post" class="stack" enctype="multipart/form-data"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_update"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>">
            <?= $fieldsFor($w) ?><div class="form-actions"><button class="btn">Save workshop</button></div></form>
        </details>
        <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="ws" value="<?= $e($w['id']) ?>">
          <?php if ($w['status'] === 'active'): ?><button class="btn small gold" name="do" value="ws_hide"<?= Page::confirm('gold', 'eye-off', 'Hide this workshop?', 'It leaves the website and takes no new bookings.', [['calendar', SiteData::workshopName($w)], ['users', 'People already booked stay (' . (int) $w['booked'] . ')']], ['yes' => 'Yes, hide it', 'no' => 'No, keep it visible']) ?>>Hide</button>
          <?php else: ?><button class="btn small green" name="do" value="ws_show">Show again</button><?php endif; ?>
          <?php if ((int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ?', [$w['id']]) === 0): ?>
            <div class="row-danger-actions"><button class="btn small red" name="do" value="ws_delete"<?= Page::confirm('red', 'trash', 'Delete this workshop for good?', 'It is removed from the database and the website. This cannot be undone.', [['calendar', SiteData::workshopName($w)], ['users', 'No one is booked on it']], ['yes' => 'Yes, delete', 'no' => 'No, keep it']) ?>>Delete</button></div>
          <?php endif; ?>
        </form>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <details class="add-new" <?= $all ? '' : 'open' ?>>
    <summary class="btn green">+ Add a new workshop</summary>
    <form method="post" class="stack" enctype="multipart/form-data"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_create">
      <?= $fieldsFor(null) ?>
      <p class="muted small">Leave the names empty only if the title is not announced yet: the website then shows "Coming soon".</p>
      <div class="form-actions"><button class="btn green">Add the workshop</button></div>
    </form>
  </details>
</div>
<?php endif; ?>

<?php if ($open !== '' && ($workshop = SiteData::workshop($open))):
    $people = Db::all('SELECT wb.*, r.id AS rid, r.ref, r.first_name, r.father_name, r.grandfather_name, r.phone, r.status AS reg_status
                       FROM workshop_bookings wb JOIN registrations r ON r.id = wb.registration_id
                       WHERE wb.workshop_id = ? AND wb.removed_at IS NULL ORDER BY wb.payment_status = \'unpaid\' DESC, r.first_name', [$workshop['id']]); ?>
<div class="card" id="people">
  <h2><?= $e(SiteData::workshopName($workshop)) ?>: <?= count($people) ?> people</h2>
  <p class="muted">Not paid first. Workshop money is not refunded.</p>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Workshop paid?</th><th>Phone</th><th>Event ticket</th><th>Price</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($people as $person): ?>
    <tr>
      <td><a href="registration.php?id=<?= (int) $person['rid'] ?>"><b><?= $e(Registrations::fullName($person)) ?></b></a></td>
      <td><?= Page::paidBadge($person['payment_status']) ?><?= $person['payment_status'] === 'paid' ? '<br><small class="muted">' . $e((string) $person['paid_how']) . ', ' . Page::when($person['paid_at']) . '</small>' : '' ?></td>
      <td dir="ltr"><?= $e($person['phone']) ?></td>
      <td><?= Page::paidBadge($person['reg_status']) ?></td>
      <td><?= $e(Workshops::money((int) $person['price_agreed'], $workshop['currency'])) ?></td>
      <td class="table-action-cell"><?= Page::workshopPaymentForm($person, $user, $back . '#people') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$people): ?><tr><td colspan="6" class="muted">Nobody booked yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php endif; ?>
<?php Page::bottom();
