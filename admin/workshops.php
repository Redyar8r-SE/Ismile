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
        'ws_create' => 'Workshop added: ' . SiteData::workshopName(Workshops::find(Workshops::create($_POST, $user))) . '. It is on the website now.',
        'ws_update' => (static function () use ($id, $user): string {
            Workshops::update($id, $_POST, $user);
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

Page::top('Workshops', 'workshops');
$e = [Page::class, 'e'];
?>
<div class="card book-caller" id="book">
  <h2>📞 Book a caller</h2>
  <p class="muted">Type the caller's name, phone or ISM26-… reference. Only <b>registered</b> people (they paid the event ticket) can take a workshop seat. Not registered yet? <a href="registration.php?new=1">Register them by phone</a>: they get a "Pay now" link and appear here once they have paid.</p>
  <form method="get" class="filters" action="workshops.php#book">
    <?php if ($open !== ''): ?><input type="hidden" name="id" value="<?= $e($open) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Name, phone or ISM26-…" autofocus>
    <button class="btn">Find</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="workshops.php">Clear</a><?php endif; ?>
  </form>
  <?php foreach ($found as $person):
      $booked = Db::all('SELECT wb.*, wb.workshop_id FROM workshop_bookings wb WHERE wb.registration_id = ? AND wb.removed_at IS NULL', [$person['id']]); ?>
  <div class="person-row">
    <div class="person-head">
      <b class="big-name"><?= $e(Registrations::fullName($person)) ?></b> <?= Page::paidBadge($person['status']) ?>
      <span class="muted" dir="ltr"><?= $e($person['phone']) ?></span> · <code><?= $e($person['ref']) ?></code> · <?= $e($person['ticket_type']) ?>
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

<div class="card table-wrap">
<table>
  <tr><th>Workshop</th><th>Price</th><th>Seats</th><th>Booked</th><th>✓ Paid</th><th>✗ Not paid</th><th>Money</th><th>Left</th><th></th></tr>
  <?php foreach ($workshops as $workshop):
      $counts = Db::one("SELECT COUNT(*) AS booked, COALESCE(SUM(payment_status = 'paid'), 0) AS paid, COALESCE(SUM(payment_status = 'complimentary'), 0) AS free,
                                COALESCE(SUM(payment_status = 'unpaid'), 0) AS unpaid, COALESCE(SUM(amount_paid), 0) AS money
                         FROM workshop_bookings WHERE workshop_id = ? AND removed_at IS NULL", [$workshop['id']]);
      $total = (int) ($workshop['totalSeats'] ?? 0);
      $left = $total - (int) $counts['booked']; ?>
  <tr>
    <td><b><?= $e(SiteData::workshopName($workshop)) ?></b><?= empty($workshop['title']) ? ' <span class="muted small">(title not announced yet)</span>' : '' ?></td>
    <td><?= (int) ($workshop['price'] ?? 0) > 0 ? Page::money((int) $workshop['price']) : '<span class="muted">not set</span>' ?></td>
    <td><?= $total ?></td><td><?= (int) $counts['booked'] ?></td>
    <td><b class="green-text"><?= (int) $counts['paid'] ?></b><?= (int) $counts['free'] ? ' <small class="muted">+' . (int) $counts['free'] . ' free</small>' : '' ?></td>
    <td><?= (int) $counts['unpaid'] ? '<b class="red-text">' . (int) $counts['unpaid'] . '</b>' : '0' ?></td>
    <td><?= Page::money((int) $counts['money']) ?></td>
    <td><?= $left <= 0 ? Page::pill('full') : '<b>' . $left . '</b>' ?></td>
    <td><a class="btn small ghost" href="workshops.php?id=<?= $e($workshop['id']) ?>#people">People</a>
        <a class="btn small green" href="export.php?what=workshop&id=<?= $e($workshop['id']) ?>">Sign-in sheet</a></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$workshops): ?><tr><td colspan="9" class="muted">No workshops in data/workshops.json.</td></tr><?php endif; ?>
</table>
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
    $fieldsFor = static function (?array $w) use ($e, $iconSelect): string {
        $v = static fn (string $field, string $lang) => $e((string) ($w[$field][$lang] ?? ''));
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
            . '<label>Price per seat (IQD; 0 = not set yet)<input name="price" inputmode="numeric" value="' . (int) ($w['price'] ?? 0) . '"></label>'
            . '<label>Seats (the limit)<input type="number" min="1" max="2000" name="total_seats" value="' . (int) ($w['totalSeats'] ?? 20) . '" required></label>'
            . '<label>Picture on the website' . $iconSelect((string) ($w['icon'] ?? 'tools')) . '</label></div>';
    }; ?>
<div class="card" id="manage">
  <h2>⚙ Manage the workshops (Owner)</h2>
  <p class="muted">Add a new workshop, change its name, price or seats, or hide it. The website's workshop cards change at once. The seats are the limit: nobody can be booked past it, and the seats can never be fewer than the people already booked.</p>
  <div class="table-wrap"><table>
    <tr><th>Workshop</th><th>On the website</th><th>Seats (limit)</th><th>Booked</th><th></th></tr>
    <?php foreach ($all as $w): ?>
    <tr>
      <td><b><?= $e(SiteData::workshopName($w)) ?></b><br><small class="muted"><?= $e($w['id']) ?> · <?= $w['price'] > 0 ? Page::money($w['price']) : 'price not set' ?></small></td>
      <td><?= $w['status'] === 'active' ? '<span class="pill green">Shown</span>' : '<span class="pill grey">Hidden</span>' ?></td>
      <td>
        <div class="seat-buttons">
          <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_seats"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>"><input type="hidden" name="delta" value="-1"><button class="btn small ghost" title="One seat fewer">−</button></form>
          <b class="seat-count"><?= (int) $w['totalSeats'] ?></b>
          <form method="post"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_seats"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>"><input type="hidden" name="delta" value="1"><button class="btn small ghost" title="One seat more">+</button></form>
        </div>
      </td>
      <td><b><?= (int) $w['booked'] ?></b> <small class="muted">· <?= (int) $w['seatsLeft'] ?> left</small></td>
      <td>
        <details class="edit"><summary class="btn small ghost">Edit</summary>
          <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_update"><input type="hidden" name="ws" value="<?= $e($w['id']) ?>">
            <?= $fieldsFor($w) ?><button class="btn">Save</button></form>
        </details>
        <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="ws" value="<?= $e($w['id']) ?>">
          <?php if ($w['status'] === 'active'): ?><button class="btn small gold" name="do" value="ws_hide" data-confirm="Hide this workshop? It leaves the website and takes no new bookings. People already booked stay.">Hide</button>
          <?php else: ?><button class="btn small green" name="do" value="ws_show">Show again</button><?php endif; ?>
          <?php if ((int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ?', [$w['id']]) === 0): ?>
            <button class="btn small red" name="do" value="ws_delete" data-confirm="Delete this workshop for good?">Delete</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <details class="add-new" <?= $all ? '' : 'open' ?>>
    <summary class="btn green">+ Add a new workshop</summary>
    <form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_create">
      <?= $fieldsFor(null) ?>
      <p class="muted small">Leave the names empty only if the title is not announced yet: the website then shows "Coming soon".</p>
      <button class="btn green">Add the workshop</button>
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
    <tr><th>Name</th><th>Workshop paid?</th><th>Phone</th><th>Event ticket</th><th>Price</th><th></th></tr>
    <?php foreach ($people as $person): ?>
    <tr>
      <td><a href="registration.php?id=<?= (int) $person['rid'] ?>"><b><?= $e(Registrations::fullName($person)) ?></b></a></td>
      <td><?= Page::paidBadge($person['payment_status']) ?><?= $person['payment_status'] === 'paid' ? '<br><small class="muted">' . $e((string) $person['paid_how']) . ', ' . Page::when($person['paid_at']) . '</small>' : '' ?></td>
      <td dir="ltr"><?= $e($person['phone']) ?></td>
      <td><?= Page::paidBadge($person['reg_status']) ?></td>
      <td><?= Page::money((int) $person['price_agreed']) ?></td>
      <td><?= Page::workshopPaymentForm($person, $user, $back . '#people') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$people): ?><tr><td colspan="6" class="muted">Nobody booked yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php endif; ?>
<?php Page::bottom();
