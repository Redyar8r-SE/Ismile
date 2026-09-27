<?php
// Settings (Owner): registration open or closed, capacity, email test mode,
// where sponsor requests are announced, ambassador codes, and the audit log.
// Prices stay in Site content → Ticket prices. Secret keys stay in config.php
// on the server and are never shown here.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\UserError;
use Ismile\Validate;

$user = Page::guard('owner');

Page::action(static function () use ($user): string {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'settings') {
        $before = Settings::all();
        $values = [
            'registration_open'    => ($_POST['registration_open'] ?? '') === '1' ? '1' : '0',
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
        $code = Validate::text($_POST['code'] ?? '', 40);
        $name = Validate::text($_POST['owner_name'] ?? '', 120);
        if ($code === '' || $name === '') {
            throw new UserError('Code and name are needed.');
        }
        Db::run('INSERT INTO ambassadors (code, owner_name, university, active, created_at) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name), university = VALUES(university), active = 1',
            [$code, $name, Validate::text($_POST['university'] ?? '', 160) ?: null, App::now()]);
        Audit::log((int) $user['id'], 'ambassador.save', null, null, ['code' => $code]);
        return "Ambassador code $code saved.";
    }
    throw new UserError('Unknown action.');
}, 'settings.php');

$s = Settings::all();
$ambassadors = Db::all("SELECT a.*, (SELECT COUNT(*) FROM registrations r WHERE LOWER(r.ambassador_code) = LOWER(a.code) AND r.status <> 'cancelled') AS uses FROM ambassadors a ORDER BY uses DESC, a.code");
$unknownCodes = Db::all("SELECT r.ambassador_code AS code, COUNT(*) AS uses FROM registrations r LEFT JOIN ambassadors a ON LOWER(a.code) = LOWER(r.ambassador_code) WHERE r.ambassador_code IS NOT NULL AND r.status <> 'cancelled' AND a.id IS NULL GROUP BY r.ambassador_code ORDER BY uses DESC LIMIT 30");
$audit = Db::all('SELECT a.*, u.name FROM audit_log a LEFT JOIN admin_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 80');
$prices = SiteData::prices();
$e = [Page::class, 'e'];
$checked = static fn (string $key): string => ($s[$key] ?? '') === '1' ? ' checked' : '';

Page::top('Settings', 'settings');
?>
<form method="post" class="card stack">
  <?= Page::csrfField() ?><input type="hidden" name="do" value="settings">
  <h2>Registration</h2>
  <label class="switch"><input type="checkbox" name="registration_open" value="1"<?= $checked('registration_open') ?>> <b>Registration is open</b> <span class="muted">(untick in an emergency: payments stop, nothing else changes)</span></label>
  <p>Prices now (Site content → Ticket prices): professional <b><?= Page::money($prices['professional'], $prices['currency']) ?></b>, student <b><?= Page::money($prices['student'], $prices['currency']) ?></b>, lunch day 1 <b><?= Page::money($prices['lunchDay1'], $prices['currency']) ?></b>, day 2 <b><?= Page::money($prices['lunchDay2'], $prices['currency']) ?></b>. A price of 0 cannot be paid.</p>
  <div class="row3">
    <label>Message while closed (English)<input name="closed_message_en" value="<?= $e($s['closed_message_en']) ?>"></label>
    <label>(Arabic)<input name="closed_message_ar" dir="rtl" value="<?= $e($s['closed_message_ar']) ?>"></label>
    <label>(Kurdish)<input name="closed_message_ku" dir="rtl" value="<?= $e($s['closed_message_ku']) ?>"></label>
  </div>
  <h2>Lunch limits</h2>
  <p class="muted">The event itself has <b>no seat limit</b>: everyone who pays can join. Only lunch is limited (by the caterer), and each workshop has its own seats on the Workshops page.</p>
  <div class="row3">
    <label>Lunch, day 1 (from the caterer; 0 = no limit)<input type="number" min="0" name="lunch_capacity_day1" value="<?= $e($s['lunch_capacity_day1']) ?>"></label>
    <label>Lunch, day 2<input type="number" min="0" name="lunch_capacity_day2" value="<?= $e($s['lunch_capacity_day2']) ?>"></label>
  </div>
  <h2>Emails</h2>
  <label class="switch"><input type="checkbox" name="email_test_mode" value="1"<?= $checked('email_test_mode') ?>> <b>Test mode</b>: every email goes to the test address below, not to the people (until launch)</label>
  <div class="row3">
    <label>Test address<input type="email" name="email_test_address" value="<?= $e($s['email_test_address']) ?>"></label>
    <label>New sponsor requests are announced to<input type="email" name="sponsor_notify_email" value="<?= $e($s['sponsor_notify_email']) ?>" placeholder="sponsors@ismile.krd"></label>
    <label>Phone "Pay now" links work for (days); unpaid forms are then deleted<input type="number" min="1" max="30" name="pay_link_days" value="<?= $e($s['pay_link_days']) ?>"></label>
  </div>
  <p class="muted small">Payments: <b><?= $e((string) App::config('payments.gateway')) ?></b> on this <?= App::isLive() ? 'live' : 'test' ?> site. Switching test/live and the Psoola keys are in config.php on the server, never on this page.</p>
  <button class="btn">Save settings</button>
</form>

<div class="grid2">
  <div class="card">
    <h2>Ambassador codes</h2>
    <table><tr><th>Code</th><th>Ambassador</th><th>Students</th></tr>
      <?php foreach ($ambassadors as $row): ?><tr><td><code><?= $e($row['code']) ?></code></td><td><?= $e($row['owner_name']) ?><br><small class="muted"><?= $e($row['university'] ?? '') ?></small></td><td><b><?= (int) $row['uses'] ?></b></td></tr><?php endforeach; ?>
      <?php if (!$ambassadors): ?><tr><td colspan="3" class="muted">No codes yet.</td></tr><?php endif; ?>
    </table>
    <?php if ($unknownCodes): ?><p class="muted small">Typed but not in the list: <?php foreach ($unknownCodes as $row): ?><code><?= $e($row['code']) ?></code> (<?= (int) $row['uses'] ?>) <?php endforeach; ?></p><?php endif; ?>
    <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="ambassador">
      <input name="code" placeholder="Code" required><input name="owner_name" placeholder="Ambassador name" required><input name="university" placeholder="University"><button class="btn small">Add</button></form>
  </div>
  <div class="card">
    <h2>Audit log (latest)</h2>
    <ul class="history">
      <?php foreach ($audit as $item): ?><li><b><?= Page::when($item['created_at']) ?></b> · <?= $e($item['name'] ?? 'system') ?> · <?= $e($item['action']) ?><?= $item['target_type'] ? ' ' . $e($item['target_type']) . ' #' . (int) $item['target_id'] : '' ?> <span class="muted small"><?= $e(mb_substr((string) $item['details'], 0, 160)) ?></span></li><?php endforeach; ?>
    </ul>
  </div>
</div>
<?php Page::bottom();
