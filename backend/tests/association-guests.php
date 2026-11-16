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
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-$tag@test.invalid", 'Desk ' . $tag, 'registration', bin2hex(random_bytes(12)))]);
$viewer = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-view-$tag@test.invalid", 'Viewer', 'viewer', bin2hex(random_bytes(12)))]);
$volunteer = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("assoc-vol-$tag@test.invalid", 'Volunteer', 'volunteer', bin2hex(random_bytes(12)))]);
$form = static fn (string $first, array $more = []): array => $more + ['first_name' => $first, 'father_name' => 'Ali', 'grandfather_name' => 'Omar',
    'phone' => '0750' . random_int(1000000, 9999999), 'email' => strtolower($first) . "-$tag@example.invalid", 'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'professional'];
$before = AssociationGuests::totals();

// ---- who may add ----
$check('registration staff may add association guests', AssociationGuests::canAdd($staff));
$check('a viewer may not add association guests', !AssociationGuests::canAdd($viewer) && $rejects(static fn () => AssociationGuests::add($form('Viewer'), $viewer)));
$check('a volunteer may not add association guests', !AssociationGuests::canAdd($volunteer));
$check('a volunteer cannot open the page (registrations area)', !Auth::can($volunteer, 'registrations'));

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
$check('adding is in the activity log', (bool) Db::value("SELECT id FROM audit_log WHERE action = 'association_guest.add' AND details LIKE ?", ['%' . $ref . '%']));

// ---- a VIP and a Student (no student ID photo needed) ----
$vip = AssociationGuests::add($form('Bryar', ['ticket' => 'vip', 'lunch_day1' => '1', 'vip_lunch_day' => '1']), $staff);
$check('a VIP guest can be added', $vip['sale']['status'] === 'paid' && Registrations::find((int) $vip['sale']['registration_id'])['ticket_type'] === 'vip');
$student = AssociationGuests::add($form('Shno', ['specialty' => 'student', 'ticket' => 'student', 'university' => 'University of Sulaimani', 'stage' => '3']), $staff);
$check('a student guest can be added without a student ID photo', $student['sale']['status'] === 'paid' && Registrations::find((int) $student['sale']['registration_id'])['ticket_type'] === 'student');

// ---- the list, the boxes and what the association owes ----
$guests = AssociationGuests::all();
$mine = array_values(array_filter($guests, static fn (array $g): bool => in_array($g['ref'], [$ref, $vip['checkout']['ref'], $student['checkout']['ref']], true)));
$check('the list shows all three guests as registered', count($mine) === 3 && array_reduce($mine, static fn (bool $all, array $g): bool => $all && AssociationGuests::state($g) === 'registered', true));
$totals = AssociationGuests::totals();
$sum = (int) Db::value("SELECT COALESCE(SUM(amount_confirmed),0) FROM payments WHERE status = 'paid' AND registration_id IN (?, ?, ?)", [$registration['id'], $vip['sale']['registration_id'], $student['sale']['registration_id']]);
$check('the boxes count each ticket type', $totals['guests'] === $before['guests'] + 3 && $totals['professional'] === $before['professional'] + 1 && $totals['vip'] === $before['vip'] + 1 && $totals['student'] === $before['student'] + 1);
$check('the association owes the PSOOLA-confirmed prices', ($totals['owed']['IQD'] ?? 0) + ($totals['owed']['USD'] ?? 0) === ($before['owed']['IQD'] ?? 0) + ($before['owed']['USD'] ?? 0) + $sum && $sum > 0);
$check('search finds a guest by name, reference and phone', count(AssociationGuests::all('Hemin')) >= 1 && count(AssociationGuests::all($ref)) === 1
    && count(AssociationGuests::all(substr((string) $registration['phone'], -7))) >= 1);
$check('search with special characters is safe', AssociationGuests::all("%' OR 1=1 --") === []);

// ---- the guest list PDF: the same full details as the Lists PDFs ----
$rows = AssociationGuests::reportRows();
$listRows = Lists::reportRows('registered', \Ismile\Admin\PaymentBreakdown::attach(Lists::filter('registered', Lists::rows('registered'), Lists::filters(['sale' => 'association']))), false);
$names = array_column(array_slice($rows, 2), 1);
$check('the guest list PDF has the same columns as the Lists PDFs', $rows[1] === $listRows[0] && in_array('Full name', $rows[1], true) && in_array('Phone', $rows[1], true) && in_array('Lunch days', $rows[1], true) && in_array('Reference', $rows[1], true) && in_array('Ticket no.', $rows[1], true));
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

echo "$passed association guest checks passed.\n";
