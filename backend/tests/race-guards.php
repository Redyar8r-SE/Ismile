<?php
// Repeats and near-simultaneous actions make one result, never two.
// ISMILE_CONFIG=/test/config.php php backend/tests/race-guards.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Erase;
use Ismile\Office;
use Ismile\Settings;
use Ismile\Sponsors;
use Ismile\UserError;
use Ismile\Payments\Payments;

if (App::isLive() || App::config('payments.gateway') !== 'fake'
    || !str_starts_with((string) App::config('db.name'), 'ismile_race_test_')) {
    throw new RuntimeException('Use an isolated ismile_race_test_* DB and the fake gateway.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$rejects = static function (callable $work): bool {
    try { $work(); return false; } catch (UserError) { return true; }
};
$tag = bin2hex(random_bytes(4));
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');

// ---- the website form sent twice (a dropped connection) ----
$form = ['first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan', 'phone' => '07501234567',
    'email' => "race-$tag@example.invalid", 'city' => 'Erbil', 'gender' => 'female', 'age' => '28', 'specialty' => 'gp',
    'lang' => 'en', 'ticket' => 'professional', 'lunch_day1' => '0', 'lunch_day2' => '0', 'terms' => '1', 'attempt' => "attempt-$tag-0001"];
$forms = (int) Db::value('SELECT COUNT(*) FROM checkouts');
$first = Checkouts::createFromForm($form, null);
$again = Checkouts::createFromForm($form, null);
$check('the same form sent twice is kept once', (int) $first['id'] === (int) $again['id'] && (int) Db::value('SELECT COUNT(*) FROM checkouts') === $forms + 1);
$other = Checkouts::createFromForm(['attempt' => "attempt-$tag-0002"] + $form, null);
$check('a changed form (new key) is a new form', (int) $other['id'] !== (int) $first['id']);
$check('a malformed key is ignored, not trusted', Checkouts::attemptKey("x'; DROP") === null && Checkouts::attemptKey('short') === null);

// ---- "Pay" pressed twice: one payment page ----
$one = Payments::start($first);
$two = Payments::start($first);
$check('pressing Pay twice reuses the same payment page', $one['redirect'] !== null && $one['redirect'] === $two['redirect']
    && (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$first['id']]) === 1);

// ---- the sponsor form sent twice ----
$sponsor = ['kind' => 'sponsor', 'company' => 'Race Dental', 'contact' => 'Karwan Ali', 'phone' => '07501234568', 'email' => "sp-$tag@example.invalid", 'attempt' => "attempt-$tag-0003"];
$emails = (int) Db::value('SELECT COUNT(*) FROM emails');
$a = Sponsors::createFromForm($sponsor);
$emailsAfterFirst = (int) Db::value('SELECT COUNT(*) FROM emails');
$b = Sponsors::createFromForm($sponsor);
$check('the same sponsor form sent twice is saved once, with one set of emails', (int) $a['id'] === (int) $b['id']
    && (int) Db::value('SELECT COUNT(*) FROM emails') === $emailsAfterFirst && $emailsAfterFirst > $emails);

// ---- sign-in lockout counts every wrong guess ----
$staffId = Auth::createUser("race-$tag@test.invalid", 'Race test', 'registration', bin2hex(random_bytes(12)));
$locked = false;
for ($i = 1; $i <= 5; $i++) $locked = Auth::recordFailure($staffId);
$row = Db::one('SELECT failed_logins, locked_until FROM admin_users WHERE id = ?', [$staffId]);
$check('five wrong guesses lock the account', $locked && $row['locked_until'] !== null && (int) $row['failed_logins'] === 0);

// ---- a removed workshop booking cannot then be marked paid ----
$owner = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser(Erase::MAIN_OWNER, 'Owner', 'owner', bin2hex(random_bytes(12)))]);
$guest = Office::createComplimentary($owner, ['first_name' => 'Nadia', 'father_name' => 'Ali', 'grandfather_name' => 'Omar',
    'email' => "guest-$tag@test.invalid", 'phone' => '07501112233', 'city' => 'Erbil', 'gender' => 'female',
    'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Race test fixture']);
$workshop = Db::one("SELECT * FROM workshops WHERE status = 'active' ORDER BY id LIMIT 1");
if ($workshop) {
    Db::run('UPDATE workshops SET price = 50000, total_seats = 20 WHERE id = ?', [$workshop['id']]);
    \Ismile\SiteData::syncWorkshopSeats();
    Office::addWorkshop(\Ismile\Registrations::find((int) $guest['id']), $owner, ['workshop' => $workshop['id'], 'payment_status' => 'unpaid']);
    $booking = (int) Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND removed_at IS NULL', [$guest['id']]);
    Office::changeWorkshop($booking, $owner, ['remove' => '1']);
    $check('a removed booking cannot be marked paid afterwards', $rejects(static fn () => Office::changeWorkshop($booking, $owner, ['payment_status' => 'paid', 'amount_paid' => '50000', 'paid_how' => 'cash'])));
}

// ---- cancelling twice is one cancel ----
$registration = \Ismile\Registrations::find((int) $guest['id']);
Office::cancel($registration, $owner, 'Race test');
$check('cancelling twice is one cancel', Office::cancel($registration, $owner, 'Race test') === 'Already cancelled.'
    && (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'registration.cancel' AND target_id = ?", [$guest['id']]) === 1);

// ---- a deleted account is never brought back by the deploy's owner step ----
$extra = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("old-owner-$tag@test.invalid", 'Old owner', 'owner', bin2hex(random_bytes(12)))]);
Erase::account((int) $extra['id'], $extra['email'], $owner);
$check('the deploy knows a deleted owner must not come back', Erase::wasDeleted($extra['email']));

// ---- students added by the office need their ID photo, as on the website ----
$student = ['first_name' => 'Lana', 'father_name' => 'Kamal', 'grandfather_name' => 'Aziz', 'phone' => '07501239876',
    'email' => "student-$tag@test.invalid", 'specialty' => 'student', 'ticket' => 'student', 'university' => 'University of Salahaddin', 'stage' => '4', 'lang' => 'en'];
$forms = (int) Db::value('SELECT COUNT(*) FROM checkouts');
$message = '';
try { Checkouts::createFromOffice($student, $owner); } catch (UserError $error) { $message = $error->key; }
$check('a phone student without an ID photo is refused, in plain words', str_contains($message, 'ID photo') && (int) Db::value('SELECT COUNT(*) FROM checkouts') === $forms);
$check('a free student ticket also needs the ID photo', $rejects(static fn () => Office::createComplimentary($owner, $student + ['comp_reason' => 'Test'])));
$check('a photo that was not really uploaded is refused', $rejects(static fn () => Checkouts::createFromOffice($student, $owner, ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'size' => 10, 'name' => 'x.jpg', 'type' => 'image/jpeg'])));
$check('a 2nd-stage student is refused by the office too', $rejects(static fn () => Checkouts::createFromOffice(['stage' => '2'] + $student, $owner)));
$check('non-students need no photo', Checkouts::officePhoto(['ticket_type' => 'professional'], null) === null);

// ---- "Site content": a one-time pass opens the editor signed in ----
$pass = \Ismile\EditorHandoff::issue($owner);
$check('the database pass names its owner', \Ismile\EditorHandoff::redeem($pass) === $owner['email']);
$check('the same pass never works twice', \Ismile\EditorHandoff::redeem($pass) === null);
$forged = \Ismile\EditorHandoff::issue($owner);
$check('a changed pass is refused', \Ismile\EditorHandoff::redeem(substr($forged, 0, -2) . 'xx') === null && \Ismile\EditorHandoff::redeem('nonsense') === null);
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [$staffId]);
$check('staff without website access get no pass', $rejects(static fn () => \Ismile\EditorHandoff::issue($staff)));
$late = \Ismile\EditorHandoff::issue($owner);
Db::run("UPDATE admin_users SET role = 'checkin' WHERE id = ?", [$owner['id']]);
$check('a pass stops working when the person loses website access', \Ismile\EditorHandoff::redeem($late) === null);
Db::run("UPDATE admin_users SET role = 'owner' WHERE id = ?", [$owner['id']]);

echo "$passed checks passed\n";
