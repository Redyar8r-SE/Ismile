<?php
// Settings (Owner): registration open or closed, capacity, email test mode,
// where sponsor requests are announced, ambassador codes, and the audit log.
// Prices stay in Site content → Ticket prices. Secret keys stay in config.php
// on the server and are never shown here.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Ambassadors;
use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\UserError;
use Ismile\Validate;

$user = Page::guard('owner');
Settings::migrateWebsiteControls();

Page::action(static function () use ($user): string {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'settings') {
        $before = Settings::all();
        $values = [
            'registration_open'    => ($_POST['registration_closed'] ?? '') === '1' ? '0' : '1',
            'program_hidden'       => ($_POST['program_hidden'] ?? '') === '1' ? '1' : '0',
            'closed_message_en'    => Page::post('closed_message_en', 300),
            'closed_message_ar'    => Page::post('closed_message_ar', 300),
            'closed_message_ku'    => Page::post('closed_message_ku', 300),
            'lunch_capacity_day1'  => (string) max(0, (int) ($_POST['lunch_capacity_day1'] ?? 0)),
            'lunch_capacity_day2'  => (string) max(0, (int) ($_POST['lunch_capacity_day2'] ?? 0)),
            'email_test_mode'      => ($_POST['email_test_mode'] ?? '') === '1' ? '1' : '0',
            'email_test_address'   => strtolower(Page::post('email_test_address', 190)),
            'sponsor_notify_email' => strtolower(Page::post('sponsor_notify_email', 190)),
            'pay_link_days'        => (string) min(30, max(1, (int) ($_POST['pay_link_days'] ?? 7))),
        ];
        foreach (['email_test_address', 'sponsor_notify_email'] as $key) {
            if ($values[$key] !== '' && !Validate::email($values[$key])) {
                throw new UserError("That email address is not valid: {$values[$key]}");
            }
        }
        if ($values['email_test_mode'] === '1' && $values['email_test_address'] === '') {
            throw new UserError('Test mode needs a test address, or nothing can be sent.');
        }
        if ($values['registration_open'] === '1') {
            $prices = SiteData::prices();
            if ($prices['professional'] <= 0 || $prices['student'] <= 0) {
                throw new UserError('Set the ticket prices first (Site content → Ticket prices). Registration cannot open with a price of 0.');
            }
        }
        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? '') !== $value) {
                Settings::set($key, $value);
                $changed[$key] = ['from' => $before[$key] ?? '', 'to' => $value];
            }
        }
        Audit::log((int) $user['id'], 'settings', null, null, $changed);
        return $changed ? 'Settings saved.' : 'Nothing changed.';
    }
    if ($do === 'ambassador') {
        return Ambassadors::save($_POST, $user);
    }
    if ($do === 'ambassador_delete') {
        return Ambassadors::delete((int) ($_POST['ambassador_id'] ?? 0), $user);
    }
    throw new UserError('Unknown action.');
}, in_array($_POST['do'] ?? '', ['ambassador', 'ambassador_delete'], true) ? 'settings.php#ambassadors' : 'settings.php');

$s = Settings::all();
$ambassadors = Db::all("SELECT a.*, (SELECT COUNT(*) FROM registrations r WHERE LOWER(r.ambassador_code) = LOWER(a.code) AND r.status <> 'cancelled') AS uses FROM ambassadors a ORDER BY uses DESC, a.code");
$unknownCodes = Db::all("SELECT r.ambassador_code AS code, COUNT(*) AS uses FROM registrations r LEFT JOIN ambassadors a ON LOWER(a.code) = LOWER(r.ambassador_code) WHERE r.ambassador_code IS NOT NULL AND r.status <> 'cancelled' AND a.id IS NULL GROUP BY r.ambassador_code ORDER BY uses DESC LIMIT 30");
$audit = Db::all('SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 80');
$prices = SiteData::prices();
$e = [Page::class, 'e'];
$checked = static fn (string $key): string => ($s[$key] ?? '') === '1' ? ' checked' : '';

Page::top('Settings', 'settings', '<a class="btn ghost" href="#ambassadors">' . Page::navIcon('users') . '<span>Ambassador codes</span></a>');
?>
<?= Page::stats([
    ['Registration', ($s['registration_open'] ?? '') === '1' ? 'Open' : 'Closed', 'Saved in the database', 'ticket', 'teal'],
    ['Ambassador codes', number_format(count($ambassadors)), 'Codes available to your students', 'users', 'blue'],
    ['Email delivery', ($s['email_test_mode'] ?? '') === '1' ? 'Test mode' : 'Guest emails', 'Where your event emails are sent', 'mail', 'violet'],
    ['Payment links', (int) $s['pay_link_days'] . ' days', 'Time to complete a phone booking', 'clock', 'gold'],
]) ?>
<div class="configuration-layout">
<aside class="configuration-nav no-print"><span class="section-eyebrow">On this page</span><nav aria-label="Settings sections">
  <?php foreach ([['registration-settings','ticket','Registration'],['program-settings','calendar','Program visibility'],['lunch-settings','lunch','Lunch capacity'],['email-settings','mail','Emails & payments'],['ambassadors','users','Ambassador codes'],['audit-log','clock','Activity log']] as [$anchor,$icon,$label]): ?>
  <a href="#<?= $anchor ?>"><?= Page::navIcon($icon) ?><span><?= $label ?></span><?= Page::navIcon('arrow') ?></a>
  <?php endforeach; ?>
</nav><div class="configuration-tip"><?= Page::navIcon('settings') ?><b>Your event, your settings</b><p>Keep registration, guest communication and your ambassador list in one place.</p></div></aside>
<div class="configuration-content">
<form method="post" class="configuration-form" id="event-settings-form">
  <?= Page::csrfField() ?><input type="hidden" name="do" value="settings">
  <section class="card stack configuration-section" id="registration-settings">
  <?= Page::panelHeading('Registration', 'Choose when guests can register and what they see while registration is closed.', 'ticket') ?>
  <label class="configuration-switch"><input type="checkbox" name="registration_closed" value="1"<?= ($s['registration_open'] ?? '0') !== '1' ? ' checked' : '' ?>><span class="switch-track" aria-hidden="true"></span><span><b>Close registration</b><small>Switch on to close registration and show “Registration opens soon”. Switch off to accept registrations. Press Save settings.</small></span></label>
  <div class="price-overview"><?php foreach ([['Professional',$prices['professional'],$prices['currency']],['Student',$prices['student'],$prices['currency']],['VIP · one lunch included',$prices['vip'],$prices['vipCurrency']],['Lunch · day 1',$prices['lunchDay1'],$prices['lunchCurrency']],['Lunch · day 2',$prices['lunchDay2'],$prices['lunchCurrency']]] as [$label,$amount,$priceCurrency]): ?><div><span><?= $label ?></span><b><?= Page::money($amount, $priceCurrency) ?></b></div><?php endforeach; ?></div>
  <p class="configuration-note">Edit prices in <a href="<?= $e(Page::contentUrl()) ?>">Site content</a> → Registration → Ticket and lunch prices. Prices must be above 0 to accept payments.</p>
  <div class="row3">
    <label>Message while closed (English)<input name="closed_message_en" value="<?= $e($s['closed_message_en']) ?>"></label>
    <label>Message while closed (Arabic)<input name="closed_message_ar" dir="rtl" value="<?= $e($s['closed_message_ar']) ?>"></label>
    <label>Message while closed (Kurdish)<input name="closed_message_ku" dir="rtl" value="<?= $e($s['closed_message_ku']) ?>"></label>
  </div>
  </section>
  <section class="card stack configuration-section" id="program-settings">
  <?= Page::panelHeading('Program visibility', 'Choose whether visitors see the sessions for Day 1 and Day 2.', 'calendar') ?>
  <label class="configuration-switch"><input type="checkbox" name="program_hidden" value="1"<?= $checked('program_hidden') ?>><span class="switch-track" aria-hidden="true"></span><span><b>Hide program: To be announced</b><small>Switch on to show “To be announced” for both days. Switch off to show the saved sessions. Press Save settings.</small></span></label>
  <p class="configuration-note">Registration and program visibility are saved in the database and apply to the public website. Edit days and sessions in <a href="<?= $e(Page::contentUrl()) ?>">Site content</a>.</p>
  </section>
  <section class="card stack configuration-section" id="lunch-settings">
  <?= Page::panelHeading('Lunch capacity', 'Set the number of meals your caterer can provide for each day.', 'lunch') ?>
  <div class="row3">
    <label>Day 1 · meal capacity<input type="number" min="0" name="lunch_capacity_day1" value="<?= $e($s['lunch_capacity_day1']) ?>"><small class="field-hint">0 means no limit.</small></label>
    <label>Day 2 · meal capacity<input type="number" min="0" name="lunch_capacity_day2" value="<?= $e($s['lunch_capacity_day2']) ?>"><small class="field-hint">0 means no limit.</small></label>
  </div>
  <p class="configuration-note">Event admission has no seat limit. Manage individual workshop seats on the <a href="workshops.php">Workshops page</a>.</p>
  </section>
  <section class="card stack configuration-section" id="email-settings">
  <?= Page::panelHeading('Emails & payment links', 'Manage guest communication, sponsor notifications and payment deadlines.', 'mail') ?>
  <label class="configuration-switch"><input type="checkbox" name="email_test_mode" value="1"<?= $checked('email_test_mode') ?>><span class="switch-track" aria-hidden="true"></span><span><b>Send emails to a test address</b><small>When enabled, every email goes to the test address below.</small></span></label>
  <div class="row3">
    <label>Test address<input type="email" name="email_test_address" value="<?= $e($s['email_test_address']) ?>"></label>
    <label>Sponsor notification email<input type="email" name="sponsor_notify_email" value="<?= $e($s['sponsor_notify_email']) ?>" placeholder="sponsors@ismile.krd"></label>
    <label>Payment link expiry (days)<input type="number" min="1" max="30" name="pay_link_days" value="<?= $e($s['pay_link_days']) ?>"><small class="field-hint">1–30 days. Unpaid phone forms are deleted after expiry.</small></label>
  </div>
  <div class="configuration-note icon-label"><?= Page::navIcon('payments') ?><span>Payment provider: <b><?= $e((string) App::config('payments.gateway')) ?></b> · <?= App::isLive() ? 'Live environment' : 'Test environment' ?></span></div>
  </section>
  <div class="card form-actions settings-save"><span class="action-hint"><b>Ready to save?</b><small>Apply your registration, lunch and email settings together.</small></span><button class="btn"><?= Page::navIcon('check') ?><span>Save settings</span></button></div>
</form>

<section class="card" id="ambassadors" aria-labelledby="ambassadors-heading">
    <div class="panel-top">
      <div class="panel-heading"><span class="panel-icon"><?= Page::navIcon('users') ?></span><div><h2 id="ambassadors-heading">Ambassador codes</h2><p>Give each ambassador a personal code and follow their student registrations.</p></div></div>
      <span class="pill grey"><?= count($ambassadors) ?> codes</span>
    </div>
    <form method="post" class="stack ambassador-form inset-form">
      <?= Page::csrfField() ?><input type="hidden" name="do" value="ambassador">
      <div class="row3">
        <label>Code<input name="code" maxlength="40" placeholder="e.g. AMB-SARA" required></label>
        <label>Ambassador name<input name="owner_name" maxlength="120" placeholder="Full name" required></label>
        <label>University (optional)<input name="university" maxlength="160"></label>
      </div>
      <div class="form-actions"><span class="muted small action-hint">Saving an existing code updates its name and university.</span><button class="btn">Save ambassador code</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><th scope="col">Code</th><th scope="col">Ambassador</th><th scope="col">Students</th><th scope="col" class="table-action-heading">Action</th></tr></thead><tbody>
      <?php foreach ($ambassadors as $row): ?>
      <tr>
        <td><code><?= $e($row['code']) ?></code></td>
        <td><b><?= $e($row['owner_name']) ?></b><br><small class="muted"><?= $e($row['university'] ?? '') ?></small></td>
        <td><b><?= (int) $row['uses'] ?></b></td>
        <td class="table-action-cell"><form method="post" class="inline-form">
          <?= Page::csrfField() ?><input type="hidden" name="do" value="ambassador_delete"><input type="hidden" name="ambassador_id" value="<?= (int) $row['id'] ?>">
          <button class="btn red small" aria-label="<?= $e('Delete ambassador code ' . $row['code']) ?>" data-confirm="<?= $e('Delete code ' . $row['code'] . ' for ' . $row['owner_name'] . '? Existing registrations keep their code. This deletion is recorded in the activity log.') ?>"><?= Page::navIcon('close') ?><span>Delete</span></button>
        </form></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$ambassadors): ?><tr><td colspan="4" class="muted">No codes yet. Add the first ambassador above.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($unknownCodes): ?><p class="muted small">Recorded on registrations, but not in the current code list (including deleted codes): <?php foreach ($unknownCodes as $row): ?><code><?= $e($row['code']) ?></code> (<?= (int) $row['uses'] ?>) <?php endforeach; ?></p><?php endif; ?>
    <p class="configuration-note">Deleting a code keeps its history on existing registrations and records the change in the activity log.</p>
</section>
  <section class="card configuration-section" id="audit-log">
    <?= Page::panelHeading('Activity log', 'The latest 80 actions, with who made each change and when.', 'clock') ?>
    <?php if (!$audit): ?><?= Page::emptyState('Your activity starts here', 'Changes made by your team will appear in this log.', 'clock') ?><?php else: ?>
    <ul class="activity-timeline">
      <?php foreach ($audit as $item): ?><li><span class="activity-marker" aria-hidden="true"></span><div><b><?= $e(str_replace(['.', '_'], ' ', $item['action'])) ?></b><p><?= $e($item['name'] ?? 'System') ?><?= $item['target_type'] ? ' · ' . $e(str_replace('_',' ',$item['target_type'])) . ' #' . (int) $item['target_id'] : '' ?></p><?php if ($item['details']): ?><small><?= $e(mb_substr((string) $item['details'], 0, 160)) ?></small><?php endif; ?></div><time><?= Page::when($item['created_at']) ?></time></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div></div>
<?php Page::bottom();
