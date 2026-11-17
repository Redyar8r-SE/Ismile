<?php
// Speaker tickets: only Owners add speakers; each is a free PSOOLA guest
// ticket (0 IQD) with free lunch add-ons on the ticked days, QR by email and WhatsApp.
// Needs the local PSOOLA simulator: node backend/tests/sponsored-provider.mjs
// ISMILE_CONFIG=/test/config.php php backend/tests/speaker-tickets.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Erase;
use Ismile\Settings;
use Ismile\SpeakerTickets;
use Ismile\UserError;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_sponsored_test_') || App::config('payments.psoola.api_base') !== 'http://127.0.0.1:8862') {
    throw new RuntimeException('Local PSOOLA simulator and an isolated ismile_sponsored_test_* database only.');
}
putenv('PSOOLA_API_BASE_URL=http://127.0.0.1:8862/api/partner/v1');
putenv('PSOOLA_API_KEY=sponsored-local-test-key');
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$rejects = static function (callable $work, string $text = ''): bool {
    try { $work(); return false; } catch (UserError $e) { return $text === '' || str_contains($e->key, $text); }
};
$fixture = static fn (string $path = ''): array => json_decode((string) file_get_contents('http://127.0.0.1:8862/fixture' . $path), true, 512, JSON_THROW_ON_ERROR);
$tag = bin2hex(random_bytes(4));
$account = static fn (string $email, string $role): array => Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser($email, ucfirst($role) . ' ' . $role, $role, bin2hex(random_bytes(12)))]);
$owner = $account("owner-$tag@example.invalid", 'owner');
$staff = $account("staff-$tag@example.invalid", 'registration');
Db::update('admin_users', ['registration_manage_access' => 1], 'id = ?', [$staff['id']]);
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [$staff['id']]);
$viewer = $account("viewer-$tag@example.invalid", 'viewer');
$volunteer = $account("volunteer-$tag@example.invalid", 'volunteer');
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');

$form = static fn (array $more = []): array => $more + ['attempt' => bin2hex(random_bytes(16)), 'first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Kareem',
    'gender' => 'female', 'age' => '41', 'phone' => '0750' . random_int(1000000, 9999999), 'email' => 'speaker-' . bin2hex(random_bytes(4)) . '@example.invalid',
    'city' => 'Erbil', 'specialty' => 'omfs', 'lunch_day1' => '1', 'lunch_day2' => '1', 'consent' => '1'];
$speakers = static fn (): int => (int) Db::value('SELECT COUNT(*) FROM speaker_tickets');

// ---- who may add, and what the form must have ----
$check('only Owners add speakers', SpeakerTickets::canAdd($owner) && !SpeakerTickets::canAdd($staff) && !SpeakerTickets::canAdd($viewer));
$check('everyone with the guest list sees the page; a volunteer does not', Auth::can($owner, 'speaker-tickets') && Auth::can($staff, 'speaker-tickets') && Auth::can($viewer, 'speaker-tickets') && !Auth::can($volunteer, 'speaker-tickets'));
$before = $fixture()['posts'];
$check('registration staff cannot add a speaker', $rejects(static fn () => SpeakerTickets::add($form(), $staff), 'Only an Owner'));
$check('a missing gender is refused', $rejects(static fn () => SpeakerTickets::add($form(['gender' => '']), $owner), 'gender'));
$check('a missing age is refused', $rejects(static fn () => SpeakerTickets::add($form(['age' => '']), $owner), 'age'));
$check('the WhatsApp agreement must be ticked', $rejects(static fn () => SpeakerTickets::add($form(['consent' => '']), $owner), 'WhatsApp'));
$check('a bad request key is refused', $rejects(static fn () => SpeakerTickets::add($form(['attempt' => 'x']), $owner), 'Reload'));
$check('a bad email is refused', $rejects(static fn () => SpeakerTickets::add($form(['email' => 'nope']), $owner)));
$check('nothing was sent to PSOOLA and no speaker was saved by refused forms', $fixture()['posts'] === $before && $speakers() === 0);

// ---- an Owner adds a speaker with free lunch on both days ----
$in = $form();
$order = SpeakerTickets::add($in, $owner);
$check('PSOOLA issued the free speaker ticket', $order['status'] === 'issued' && (int) $order['provider_amount'] === 0 && (int) $order['is_complimentary'] === 1);
$request = json_decode((string) $order['request_json'], true);
$check('the free lunch for both days was sent to PSOOLA on the same ticket', ($request['items'][0]['addOnIds'] ?? null) === ['lunch1-test', 'lunch2-test'] && $request['items'][0]['ticketTypeId'] === 'professional-test');
$check('the request is a complimentary order with the speaker reason and no price', $request['issuanceType'] === 'complimentary' && $request['complimentaryReason'] === SpeakerTickets::REASON && !isset($request['totalAmount']));
$check('the speaker agreed to WhatsApp, so PSOOLA sends the QR on WhatsApp too', !empty($request['buyer']['whatsappConsent']['acknowledged']));
$person = Db::one('SELECT * FROM registrations WHERE id = ?', [$order['registration_id']]);
$check('the speaker is registered with a free Dentist ticket', $person && $person['status'] === 'complimentary' && $person['ticket_type'] === 'professional' && $person['comp_reason'] === SpeakerTickets::REASON);
$check('the free lunch is on the registration (Day 1 and Day 2)', (int) $person['lunch_day1'] === 1 && (int) $person['lunch_day2'] === 1);
$check('gender, age, city and specialty are saved', $person['gender'] === 'female' && (int) $person['age'] === 41 && $person['city'] === 'Erbil' && $person['specialty'] === 'omfs');
$check('the phone is saved in international form', $person['phone'] === '+964' . substr($in['phone'], 1));
$check('the addition is in the history', (bool) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'speaker_ticket.add' AND target_id = ?", [$order['id']]));
$posts = $fixture()['posts'];
$again = SpeakerTickets::add($in, $owner);
$check('pressing Add speaker twice never makes a second ticket', (int) $again['id'] === (int) $order['id'] && $fixture()['posts'] === $posts && $speakers() === 1);

// ---- a dental student speaker without lunch ----
$student = SpeakerTickets::add($form(['specialty' => 'student', 'first_name' => 'Rawa', 'lunch_day1' => '0', 'lunch_day2' => '0', 'gender' => 'male', 'age' => '22']), $owner);
$studentRequest = json_decode((string) $student['request_json'], true);
$studentPerson = Db::one('SELECT * FROM registrations WHERE id = ?', [$student['registration_id']]);
$check('a student speaker gets a free Student ticket with no lunch', $studentRequest['items'][0]['ticketTypeId'] === 'student-test' && !isset($studentRequest['items'][0]['addOnIds'])
    && $studentPerson['ticket_type'] === 'student' && (int) $studentPerson['lunch_day1'] === 0 && (int) $studentPerson['lunch_day2'] === 0);

// ---- the list, totals and PDF ----
file_get_contents('http://127.0.0.1:8862/fixture/delivery?id=' . rawurlencode((string) $order['provider_order_id']));
$all = SpeakerTickets::all();
$list = SpeakerTickets::filter($all, SpeakerTickets::filters([]));
$check('the speaker list shows both speakers as issued', count($list) === 2 && SpeakerTickets::state($list[0]) === 'registered' && SpeakerTickets::state($list[1]) === 'registered');
$totals = SpeakerTickets::totals($all);
$check('the totals count speakers and free lunches per day', $totals['speakers'] === 2 && $totals['lunch1'] === 1 && $totals['lunch2'] === 1 && $totals['waiting'] === 0);
$check('search by name and the lunch filter work', count(SpeakerTickets::filter($all, SpeakerTickets::filters(['q' => 'Rawa']))) === 1
    && count(SpeakerTickets::filter($all, SpeakerTickets::filters(['lunch' => 'both']))) === 1 && count(SpeakerTickets::filter($all, SpeakerTickets::filters(['ticket' => 'student']))) === 1);
$rows = SpeakerTickets::reportRows();
$check('the PDF rows list every speaker with free lunch and QR status', count($rows) === 4 && $rows[1][4] === 'Lunch (free)' && in_array('Day 1 and Day 2', array_column(array_slice($rows, 2), 4), true));
$check('the speaker list PDF renders', str_starts_with(\Ismile\Admin\PdfReport::make('Speaker tickets', $rows), '%PDF-'));

// ---- PSOOLA refuses: the speaker shows as refused, nothing is invented ----
file_get_contents('http://127.0.0.1:8862/fixture/permission?enabled=0');
$refused = $form(['first_name' => 'Hana']);
$check('a speaker PSOOLA refuses is not registered', $rejects(static fn () => SpeakerTickets::add($refused, $owner)));
file_get_contents('http://127.0.0.1:8862/fixture/permission?enabled=1');
$row = array_values(array_filter(SpeakerTickets::all(), static fn (array $s): bool => $s['attempt_key'] === $refused['attempt']))[0] ?? null;
$check('the refused speaker shows as refused by PSOOLA, with no registration', $row !== null && SpeakerTickets::state($row) === 'rejected' && $row['registration_id'] === null);

// ---- lunch not set up on the server ----
$config = (new ReflectionClass(App::class))->getProperty('config');
$saved = $config->getValue();
$changed = $saved; unset($changed['payments']['psoola']['add_ons']);
$config->setValue(null, $changed);
$posts = $fixture()['posts'];
$check('free lunch without PSOOLA lunch settings is refused before PSOOLA is asked', $rejects(static fn () => SpeakerTickets::add($form(), $owner), 'Lunch day 1') && $fixture()['posts'] === $posts);
$config->setValue(null, $saved);

// ---- deleting the person for good also removes the speaker tag ----
$mainOwner = Db::one('SELECT * FROM admin_users WHERE email = ?', [Erase::MAIN_OWNER]) ?? $account(Erase::MAIN_OWNER, 'owner');
Erase::registration((int) $student['registration_id'], (string) $studentPerson['ref'], $mainOwner);
$check('a deleted speaker leaves the speaker list', !Db::value('SELECT COUNT(*) FROM speaker_tickets WHERE attempt_key = ?', [$student['attempt_key']])
    && count(SpeakerTickets::filter(SpeakerTickets::all(), SpeakerTickets::filters(['state' => 'registered']))) === 1);

echo "$passed speaker ticket checks passed\n";
