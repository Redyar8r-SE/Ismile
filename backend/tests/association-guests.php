<?php
// Association guests: the Slemani Association registers guests; each one is a
// cash sale (PSOOLA emails the QR at once) and the association pays later.
// ISMILE_CONFIG=/test/config.php php backend/tests/association-guests.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\Admin\Lists;
use Ismile\Admin\PdfReport;
use Ismile\App;
use Ismile\AssociationGuests;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Erase;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\UserError;

if (App::isLive() || App::config('payments.gateway') !== 'fake'
    || !str_starts_with((string) App::config('db.name'), 'ismile_cash_test_')) {
    throw new RuntimeException('Use an isolated ismile_cash_test_* DB and the fake gateway.');
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
// Summit days in the future, so both lunch days are still open here (closing rule: day-closing.php).
Settings::set('event_day1', date('Y-m-d', strtotime('+30 days')));
Settings::set('event_day2', date('Y-m-d', strtotime('+31 days')));
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-$tag@test.invalid", 'Desk ' . $tag, 'registration', bin2hex(random_bytes(12)))]);
$viewer = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-view-$tag@test.invalid", 'Viewer', 'viewer', bin2hex(random_bytes(12)))]);
$volunteer = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-vol-$tag@test.invalid", 'Volunteer', 'volunteer', bin2hex(random_bytes(12)))]);
$form = static fn (string $first, array $more = []): array => $more + ['first_name' => $first, 'father_name' => 'Ali', 'grandfather_name' => 'Omar',
    'phone' => '0750' . random_int(1000000, 9999999), 'email' => strtolower($first) . "-$tag@example.invalid", 'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'professional', 'gender' => 'male', 'age' => '34'];
$before = AssociationGuests::totals();

// ---- who may add ----
$check('registration staff may add association guests', AssociationGuests::canAdd($staff));
$check('a viewer may not add association guests', !AssociationGuests::canAdd($viewer) && $rejects(static fn () => AssociationGuests::add($form('Viewer'), $viewer)));
$check('a volunteer may not add association guests', !AssociationGuests::canAdd($volunteer));
$check('a volunteer cannot open the page', !Auth::can($volunteer, 'association-guests') && !Auth::can($volunteer, 'registrations'));
$check('a viewer sees the page but cannot add or post', Auth::can($viewer, 'association-guests') && !Auth::viewerMayPost($viewer, '/admin/association-guests.php'));
$badges = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-badge-$tag@test.invalid", 'Badges', 'badges', bin2hex(random_bytes(12)))]);
$check('Tickets & Certificates only cannot open the page without the grant', !Auth::can($badges, 'association-guests'));
// ---- the Owner's "Allow Association guests (everything)" grant, for any role ----
foreach (['volunteer' => $volunteer, 'viewer' => $viewer, 'badges' => $badges] as $role => $account) {
    Db::update('admin_users', ['association_guest_access' => 1], 'id = ?', [$account['id']]);
    $granted = Db::one('SELECT * FROM admin_users WHERE id = ?', [$account['id']]);
    $check("with the grant, a $role opens the page, adds guests and posts the form", Auth::can($granted, 'association-guests') && AssociationGuests::canAdd($granted) && Auth::viewerMayPost($granted, '/admin/association-guests.php'));
    $check("the grant gives a $role nothing else", Auth::can($granted, 'edit') === Auth::can($account, 'edit') && Auth::can($granted, 'registrations') === Auth::can($account, 'registrations') && Auth::can($granted, 'payments') === Auth::can($account, 'payments') && !Auth::viewerMayPost($granted, '/admin/registration.php') === !Auth::viewerMayPost($account, '/admin/registration.php'));
}
$check('the grant asks for the phone code (not for a Volunteer)', Auth::needsTwoFactor(Db::one('SELECT * FROM admin_users WHERE id = ?', [$viewer['id']])) && !Auth::needsTwoFactor(Db::one('SELECT * FROM admin_users WHERE id = ?', [$volunteer['id']])));
foreach ([$volunteer, $viewer, $badges] as $account) Db::update('admin_users', ['association_guest_access' => 0], 'id = ?', [$account['id']]);
$check('removing the grant takes the page away again', !Auth::can(Db::one('SELECT * FROM admin_users WHERE id = ?', [$volunteer['id']]), 'association-guests') && !AssociationGuests::canAdd(Db::one('SELECT * FROM admin_users WHERE id = ?', [$viewer['id']])));

// ---- a Dentist: registered at once, tagged, priced ----
// (The test gateway cannot price IQD tickets with USD lunch together; on the
// live site PSOOLA prices everything in IQD, exactly as for Register (cash).)
$added = AssociationGuests::add($form('Hemin'), $staff);
$ref = $added['checkout']['ref'];
$registration = $added['sale']['registration_id'] ? Registrations::find($added['sale']['registration_id']) : null;
$check('the guest is registered at once by the cash sale', $added['sale']['status'] === 'paid' && $registration !== null && $registration['status'] === 'paid' && $registration['ref'] === $ref);
$check('the guest is tagged Slemani Association', AssociationGuests::isGuest($ref) && (AssociationGuests::tags([$ref, 'ISM26-NOPE00'])[$ref] ?? '') === 'Slemani Association' && count(AssociationGuests::tags([$ref, 'ISM26-NOPE00'])) === 1);
$check('the tag records who added the guest', (int) Db::value('SELECT added_by FROM association_guests WHERE ref = ?', [$ref]) === (int) $staff['id']);
$check('the PSOOLA side is a normal cash sale (QR emailed at once)', $registration['pay_method'] === 'cash' && Db::value("SELECT method FROM payments WHERE registration_id = ? AND status = 'paid'", [$registration['id']]) === 'cash');
$check('no WhatsApp tick: only email (no agreement saved)', $registration['whatsapp_consent_at'] === null);
$check('adding is in the activity log', (bool) Db::value("SELECT id FROM audit_log WHERE action = 'association_guest.add' AND details LIKE ?", ['%' . $ref . '%']));

// ---- a VIP and a Student (no student ID photo needed) ----
$vip = AssociationGuests::add($form('Bryar', ['ticket' => 'vip', 'lunch_day1' => '1', 'vip_lunch_day' => '1', 'whatsapp_consent' => '1']), $staff);
$vipPerson = Registrations::find((int) $vip['sale']['registration_id']);
$check('the WhatsApp tick is saved on the sale and stays with the registered guest', $vipPerson['whatsapp_consent_at'] !== null
    && Db::value('SELECT whatsapp_consent_at FROM checkouts WHERE ref = ?', [$vip['checkout']['ref']]) !== null
    && (\Ismile\Payments\PsoolaGateway::whatsappConsent($vipPerson)['acknowledged'] ?? false) === true);
$check('a VIP guest can be added', $vip['sale']['status'] === 'paid' && Registrations::find((int) $vip['sale']['registration_id'])['ticket_type'] === 'vip');
$student = AssociationGuests::add($form('Shno', ['specialty' => 'student', 'ticket' => 'student', 'university' => 'University of Sulaimani', 'stage' => '3', 'ambassador' => 'AMB-X']), $staff);
$check('no ambassador code is saved for association students', Registrations::find((int) $student['sale']['registration_id'])['ambassador_code'] === null && Registrations::find((int) $student['sale']['registration_id'])['id_photo_id'] === null);
$check('a student guest can be added without a student ID photo or ambassador code', $student['sale']['status'] === 'paid' && Registrations::find((int) $student['sale']['registration_id'])['ticket_type'] === 'student');

// ---- the list, the boxes and what the association owes ----
$guests = AssociationGuests::all();
$mine = array_values(array_filter($guests, static fn (array $g): bool => in_array($g['ref'], [$ref, $vip['checkout']['ref'], $student['checkout']['ref']], true)));
$check('the list shows all three guests as registered', count($mine) === 3 && array_reduce($mine, static fn (bool $all, array $g): bool => $all && AssociationGuests::state($g) === 'registered', true));
$totals = AssociationGuests::totals();
$sum = (int) Db::value("SELECT COALESCE(SUM(amount_confirmed),0) FROM payments WHERE status = 'paid' AND registration_id IN (?, ?, ?)", [$registration['id'], $vip['sale']['registration_id'], $student['sale']['registration_id']]);
$check('the boxes count each ticket type', $totals['guests'] === $before['guests'] + 3 && $totals['professional'] === $before['professional'] + 1 && $totals['vip'] === $before['vip'] + 1 && $totals['student'] === $before['student'] + 1);
$check('the association owes the PSOOLA-confirmed prices', ($totals['owed']['IQD'] ?? 0) + ($totals['owed']['USD'] ?? 0) === ($before['owed']['IQD'] ?? 0) + ($before['owed']['USD'] ?? 0) + $sum && $sum > 0);
// ---- the search and filters ----
$f = static fn (array $in): array => AssociationGuests::filters($in);
$found = static fn (array $in): array => array_column(AssociationGuests::filter(AssociationGuests::all(), $f($in)), 'first_name');
$check('typing one letter finds every first name that starts with it', $found(['q' => 'H']) === ['Hemin'] && $found(['q' => 'h']) === ['Hemin'] && in_array('Shno', $found(['q' => 'S']), true) && !in_array('Hemin', $found(['q' => 'S']), true));
$check('one letter never matches inside other names, emails or references', $found(['q' => 'A']) === [] && $found(['q' => 'O']) === []);
$check('more letters narrow the first name', $found(['q' => 'He']) === ['Hemin'] && $found(['q' => 'Hx']) === []);
$check('the full name is matched from its start', $found(['q' => 'hemin ali o']) === ['Hemin'] && $found(['q' => 'Hemin  Ali']) === ['Hemin'] && $found(['q' => 'Ali']) === []);
$check('search finds a phone, a reference and an email', $found(['q' => substr((string) $registration['phone'], -7)]) === ['Hemin'] && $found(['q' => $ref]) === ['Hemin'] && $found(['q' => strtolower(substr($ref, -6))]) === ['Hemin'] && $found(['q' => "hemin-$tag@"]) === ['Hemin']);
$check('search with special characters is safe', $found(['q' => "%' OR 1=1 --"]) === []);
$check('filters: ticket type', $found(['ticket' => 'vip']) === ['Bryar'] && $found(['ticket' => 'student']) === ['Shno']);
$check('filters: lunch', $found(['lunch' => 'day1']) === ['Bryar'] && !in_array('Bryar', $found(['lunch' => 'none']), true));
$check('filters: added by', count($found(['by' => (string) $staff['id']])) === 3 && $found(['by' => '999999']) === []);
$check('filters: registration status and QR email', count($found(['state' => 'registered'])) === 3 && $found(['state' => 'cancelled']) === [] && count($found(['email' => 'pending'])) === 3 && $found(['email' => 'delivered']) === []);
$check('order: name A to Z, Z to A, newest and oldest', $found(['sort' => 'name']) === ['Bryar', 'Hemin', 'Shno'] && $found(['sort' => 'name_desc']) === ['Shno', 'Hemin', 'Bryar'] && $found([]) === ['Shno', 'Bryar', 'Hemin'] && $found(['sort' => 'oldest']) === ['Hemin', 'Bryar', 'Shno']);
$check('unknown filter values are ignored', $f(['ticket' => 'x', 'sort' => 'evil', 'by' => '1 OR 1'])['ticket'] === '' && $f(['sort' => 'evil'])['sort'] === 'newest' && $f(['by' => '1 OR 1'])['by'] === '');
$check('the gender and age are saved', Registrations::find((int) $registration['id'])['gender'] === 'male' && (int) Registrations::find((int) $registration['id'])['age'] === 34);

// ---- the guest list PDF: the same full details as the Lists PDFs ----
$rows = AssociationGuests::reportRows();
$listRows = Lists::reportRows('registered', \Ismile\Admin\PaymentBreakdown::attach(Lists::filter('registered', Lists::rows('registered'), Lists::filters(['sale' => 'association']))), false);
$names = array_column(array_slice($rows, 2), 1);
$expected = array_values(array_filter(array_map(static fn (string $h): string => $h === 'Sponsoring company' ? 'Sponsoring association' : $h, $listRows[0]), static fn (string $h): bool => $h !== 'Ambassador'));
$check('the guest list PDF has the Lists PDF columns, without Ambassador, with Sponsoring association', $rows[1] === $expected && in_array('Full name', $rows[1], true) && in_array('Phone', $rows[1], true) && in_array('Lunch days', $rows[1], true) && in_array('Reference', $rows[1], true) && in_array('Ticket no.', $rows[1], true) && !in_array('Ambassador', $rows[1], true) && !in_array('Sponsoring company', $rows[1], true));
$sponsorColumn = array_search('Sponsoring association', $rows[1], true);
$check('every guest row says Slemani Association as the sponsoring association, and rows keep their width', array_reduce(array_slice($rows, 2), static fn (bool $all, array $r): bool => $all && $r[$sponsorColumn] === 'Slemani Association' && count($r) === count($rows[1]), true));
$check('the guest list PDF has only the registered association guests, A to Z', count($rows) - 2 === $totals['guests'] && in_array('Hemin Ali Omar', $names, true) && !in_array('Plain Ali Omar', $names, true));
$check('the guest list title has no dot separator', !str_contains((string) $rows[0][0], '·') && str_starts_with((string) $rows[0][0], 'Slemani Association, '));
$check('the guest list PDF renders', str_starts_with(PdfReport::make('Slemani Association guests', $rows, true), '%PDF-'));

// ---- Lists: its own Sale type, not counted as cash ----
$check('Lists see the guest as Slemani Association, not Cash', Lists::saleType($registration) === 'association' && Lists::SALE_TYPES['association'] === 'Slemani Association');
$plain = \Ismile\Checkouts::createForCash($form('Plain'), $staff);
\Ismile\Payments\Payments::sellForCash($plain, $staff);
$check('an ordinary cash sale stays Cash', Lists::saleType(Registrations::findByRef($plain['ref'])) === 'cash' && !AssociationGuests::isGuest($plain['ref']));

// ---- a refused sale leaves no tag behind ----
Settings::set('registration_open', '0');
$countBefore = (int) Db::value('SELECT COUNT(*) FROM association_guests');
$check('nothing is sold while registration is closed', $rejects(static fn () => AssociationGuests::add($form('Closed'), $staff)));
$check('and no association tag is left behind', (int) Db::value('SELECT COUNT(*) FROM association_guests') === $countBefore);
Settings::set('registration_open', '1');
$check('bad details are refused before anything is saved', $rejects(static fn () => AssociationGuests::add($form('X'), $staff)) && (int) Db::value('SELECT COUNT(*) FROM association_guests') === $countBefore);

// ---- a cancelled guest no longer counts ----
Db::run("UPDATE registrations SET status = 'cancelled' WHERE id = ?", [$student['sale']['registration_id']]);
$afterCancel = AssociationGuests::totals();
$studentRow = array_values(array_filter(AssociationGuests::all(), static fn (array $g): bool => $g['ref'] === $student['checkout']['ref']))[0];
$check('a cancelled guest shows as cancelled and is not owed', AssociationGuests::state($studentRow) === 'cancelled' && $afterCancel['guests'] === $totals['guests'] - 1 && $afterCancel['student'] === $totals['student'] - 1);

// ---- delete for good removes the tag (and keeps it in the copy) ----
$owner = Db::one('SELECT * FROM admin_users WHERE email = ?', [Erase::MAIN_OWNER])
    ?? Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser(Erase::MAIN_OWNER, 'Main Owner', 'owner', bin2hex(random_bytes(12)))]);
Erase::registration((int) $vip['sale']['registration_id'], $vip['checkout']['ref'], $owner);
$check('deleting the guest for good removes the association tag', !AssociationGuests::isGuest($vip['checkout']['ref']) && Registrations::findByRef($vip['checkout']['ref']) === null);
$check('the deleted copy keeps the association tag', str_contains((string) Db::value('SELECT data FROM deleted_records WHERE ref = ? ORDER BY id DESC LIMIT 1', [$vip['checkout']['ref']]), '"association_guests"'));
$check('a deleted guest is gone from the list', !in_array($vip['checkout']['ref'], array_column(array_filter(AssociationGuests::all(), static fn (array $g): bool => AssociationGuests::state($g) !== 'none'), 'ref'), true));

// ---- email status never calls PSOOLA on the test gateway ----
$check('email status is not asked from PSOOLA on the test site', AssociationGuests::emailStatuses([$ref]) === []);
$check('owed text formats every currency', AssociationGuests::owedText([]) === '0 IQD' && AssociationGuests::owedText(['IQD' => 1030000]) === '1,030,000 IQD');

// ---- a granted volunteer really adds a guest (last, so the name checks above stay exact) ----
Db::update('admin_users', ['association_guest_access' => 1], 'id = ?', [$volunteer['id']]);
$byVolunteer = AssociationGuests::add($form('Volgranted'), Db::one('SELECT * FROM admin_users WHERE id = ?', [$volunteer['id']]));
$check('a granted volunteer really adds a guest', $byVolunteer['sale']['status'] === 'paid' && AssociationGuests::isGuest($byVolunteer['checkout']['ref']));

// Gender and age are not asked on the database forms any more (owner, 19 Nov 2026). Last, so the lists above stay as they are.
$noGender = AssociationGuests::add(array_diff_key($form('Nogender'), ['gender' => 1, 'age' => 1]), $staff);
$check('gender and age are not asked: a guest without them is added', (bool) $noGender && Registrations::find((int) ($noGender['sale']['registration_id'] ?? 0)) !== null);

echo "$passed association guest checks passed.\n";
