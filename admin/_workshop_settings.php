<?php
use Ismile\Admin\Page;
use Ismile\Db;
use Ismile\SiteData;
use Ismile\Workshops;
if (!isset($user, $e)) { http_response_code(404); exit; }
?>
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
    $organizerOptions = static function (string $current): string {
        $html = '';
        foreach (['ismile' => 'iSmile (register online with the summit email)', 'company' => 'A company (register by phone call)'] as $code => $label) {
            $html .= '<option value="' . $code . '"' . ($code === $current ? ' selected' : '') . '>' . $label . '</option>';
        }
        return $html;
    };
    $siteUrl = static fn (string $path): string => rtrim((string) \Ismile\App::config('site_url'), '/') . '/' . ltrim($path, '/');
    $fieldsFor = static function (?array $w) use ($e, $iconSelect, $currencyOptions, $organizerOptions, $siteUrl): string {
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
            . '<h3 class="form-subhead">Organized by</h3>'
            . '<p class="muted small"><b>iSmile</b>: people register on the website with the email they registered for the summit with. <b>A company</b>: the website shows "Organized by" the company name and its phone number, and people call the company to register (no online registration).</p>'
            . '<div class="row3">'
            . '<label>Organized by<select name="organizer">' . $organizerOptions((string) ($w['organizer'] ?? 'ismile')) . '</select></label>'
            . '<label>Company phone (people call it to register)<input name="company_phone" dir="ltr" inputmode="tel" maxlength="40" placeholder="+964 750 123 4567" value="' . $e((string) ($w['companyPhone'] ?? '')) . '"></label>'
            . '<label>Second company phone (optional)<input name="company_phone_secondary" dir="ltr" inputmode="tel" maxlength="40" value="'.$e((string)($w['companyPhones'][1]??'')).'"></label>'
            . '</div>'
            . '<div class="row3">'
            . '<label>Company name (English)<input name="company_en" value="' . $v('company', 'en') . '"></label>'
            . '<label>Company (Arabic)<input name="company_ar" dir="rtl" value="' . $v('company', 'ar') . '"></label>'
            . '<label>Company (Kurdish)<input name="company_ku" dir="rtl" value="' . $v('company', 'ku') . '"></label></div>'
            . '<div class="row3">'
            . '<label>Price per seat (0 = not set yet)<input name="price" inputmode="numeric" value="' . (int) ($w['price'] ?? 0) . '"></label>'
            . '<label>Currency<select name="currency">' . $currencyOptions((string) ($w['currency'] ?? 'IQD')) . '</select></label>'
            . '<label>Seats (the limit)<input type="number" min="1" max="2000" name="total_seats" value="' . (int) ($w['totalSeats'] ?? 20) . '" required></label></div>'
            . '<h3 class="form-subhead">Workshop schedule (Iraq time)</h3><div class="row3">'
            . '<label>Date<input type="date" name="scheduled_date" value="'.$e((string)($w['scheduledDate']??'')).'"></label>'
            . '<label>Start time<input type="time" name="start_time" value="'.$e((string)($w['startTime']??'')).'"></label>'
            . '<label>End time<input type="time" name="end_time" value="'.$e((string)($w['endTime']??'')).'"></label></div>'
            . '<input type="hidden" name="photo_style" value="'.$e((string)($w['photoStyle']??'photo')).'">'
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
<div class="ws-settings-content" id="manage">
  <?= Page::panelHeading('Workshop settings', 'Names, prices, visibility and seat limits. Owner access.', 'settings') ?>
  <p class="muted">Add a new workshop, change its name, details, photo, price or seats, or hide it. The website's workshop cards and details pages change at once. The seats are the limit: nobody can be booked past it, and the seats can never be fewer than the people already booked.</p>
  <div class="table-wrap"><table>
    <tr><th>Workshop</th><th>On the website</th><th>Seats (limit)</th><th>Booked</th><th class="table-action-heading">Actions</th></tr>
    <?php foreach ($all as $w): ?>
    <tr>
      <td><b><?= $e(SiteData::workshopName($w)) ?></b><br><small class="muted"><?= $e($w['id']) ?> · <?= $w['price'] > 0 ? $e(Workshops::money($w['price'], $w['currency'])) : 'price not set' ?></small><br><?= $w['organizer'] === 'company' ? '<span class="pill gold org">By ' . $e((string) (array_values(array_filter((array) $w['company']))[0] ?? 'a company')) . ' · call <span dir="ltr">' . $e((string) $w['companyPhone']) . '</span></span>' : '<span class="pill green org">By iSmile · online</span>' ?></td>
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

