<?php
// Speakers page: free speaker tickets through the local PSOOLA simulator
// (backend/tests/sponsored-provider.mjs on 127.0.0.1:8862). Needs an
// ismile_speaker_test_* database and payments.psoola with event-test,
// show-test, ticket_types.professional = professional-test and add_ons
// lunchDay1 = lunch1-test, lunchDay2 = lunch2-test.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SpeakerGuests;
use Ismile\UserError;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_speaker_test_')
    || App::config('payments.psoola.api_base') !== 'http://127.0.0.1:8862'
    || App::config('payments.psoola.ticket_types.professional') !== 'professional-test'
    || App::config('payments.psoola.add_ons.lunchDay1') !== 'lunch1-test') {
    throw new RuntimeException('Isolated local simulator only.');
}
putenv('PSOOLA_API_BASE_URL=http://127.0.0.1:8862/api/partner/v1');
putenv('PSOOLA_API_KEY=sponsored-local-test-key');
putenv('PSOOLA_COMPLIMENTARY_ENABLED=1');

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$reject = static function (callable $fn, string $text = ''): bool {
    try { $fn(); return false; } catch (UserError $e) { return $text === '' || str_contains($e->key, $text); }
};
$fixture = static fn (string $path = ''): array => json_decode((string) file_get_contents('http://127.0.0.1:8862/fixture' . $path), true, 512, JSON_THROW_ON_ERROR);
$tag = bin2hex(random_bytes(4));
$account = static fn (string $role): array => Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser($role . '-' . $tag . '@example.invalid', 'Speaker Test ' . ucfirst($role), $role, bin2hex(random_bytes(16)))]);
$owner = $account('owner');
$staff = $account('registration');
Db::update('admin_users', ['registration_manage_access' => 1], 'id = ?', [$staff['id']]);
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [$staff['id']]);
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');

$website = SpeakerGuests::website();
$check('the website speaker list is read (English names, topics)', count($website) >= 2 && reset($website)['topic'] !== '');
$names = array_keys($website);
$form = static fn (string $speaker, array $extra = []): array => $extra + [
    'attempt' => bin2hex(random_bytes(16)), 'speaker_name' => $speaker, 'ticket_name' => '',
    'email' => 'speaker-' . bin2hex(random_bytes(4)) . '@example.invalid', 'phone' => '0750' . random_int(1000000, 9999999),
    'lunch_day1' => '1', 'lunch_day2' => '1', 'whatsapp' => '1',
];

$before = $fixture()['posts'];
$check('only the Owner can register speakers (registration staff refused before PSOOLA)',
    $reject(fn () => SpeakerGuests::register($staff, $form($names[0])), 'Only the Owner') && $fixture()['posts'] === $before);
$check('a speaker name is required', $reject(fn () => SpeakerGuests::register($owner, $form(''))));
$check('a bad email is refused before PSOOLA', $reject(fn () => SpeakerGuests::register($owner, $form($names[0], ['email' => 'not-an-email']))) && $fixture()['posts'] === $before);

// 1. A website speaker with lunch on both days and WhatsApp.
$first = $form($names[0]);
$order = SpeakerGuests::register($owner, $first);
$request = json_decode((string) $order['request_json'], true);
$check('the free ticket is issued by PSOOLA', $order['status'] === 'issued' && (int) $order['is_complimentary'] === 1 && (int) $order['provider_amount'] === 0);
$check('the order asks for the Dentist ticket with lunch on Day 1 and Day 2', $request['items'][0]['ticketTypeId'] === 'professional-test'
    && $request['items'][0]['addOnIds'] === ['lunch1-test', 'lunch2-test'] && (int) $request['items'][0]['quantity'] === 1);
$check('the order is complimentary with the speaker reason', $request['issuanceType'] === 'complimentary' && $request['complimentaryReason'] === SpeakerGuests::REASON);
$check('WhatsApp consent is sent so PSOOLA sends the QR on WhatsApp', ($request['buyer']['whatsappConsent']['acknowledged'] ?? false) === true);
$check('the order is tagged with the speaker', Db::value('SELECT speaker_name FROM speaker_guests WHERE ref = ?', [$order['partner_reference']]) === $names[0]);
$registration = Db::one('SELECT * FROM registrations WHERE id = ?', [$order['registration_id']]);
$check('a guest record is made with both lunch days', $registration && (int) $registration['lunch_day1'] === 1 && (int) $registration['lunch_day2'] === 1 && $registration['status'] === 'complimentary');
$check('the badge and Tickets list say Speaker', Registrations::badgeName($registration) === 'Speaker');
$check('an ordinary guest badge still says the ticket', Registrations::badgeName(['ticket_type' => 'professional', 'comp_reason' => 'Invited guest']) === 'Dentist');
$check('the speaker list shows the ticket as sent', SpeakerGuests::state(SpeakerGuests::all()[0]) === 'issued' && SpeakerGuests::lunch(SpeakerGuests::all()[0]) === ['day1' => true, 'day2' => true]);

// 2. The same form again (same attempt) never makes a second ticket.
$posts = $fixture()['posts'];
$again = SpeakerGuests::register($owner, $first);
$check('pressing Register twice returns the same order, no second ticket', (int) $again['id'] === (int) $order['id'] && $fixture()['posts'] === $posts
    && (int) Db::value('SELECT COUNT(*) FROM speaker_guests WHERE speaker_name = ?', [$names[0]]) === 1);
$check('a new form for the same speaker is refused', $reject(fn () => SpeakerGuests::register($owner, $form($names[0])), 'already has a speaker ticket') && $fixture()['posts'] === $posts);

// 3. No lunch and no WhatsApp; a different name on the badge.
$second = SpeakerGuests::register($owner, $form($names[1], ['lunch_day1' => '0', 'lunch_day2' => '', 'whatsapp' => '0', 'ticket_name' => 'Prof. Badge Name']));
$request = json_decode((string) $second['request_json'], true);
$registration = Db::one('SELECT * FROM registrations WHERE id = ?', [$second['registration_id']]);
$check('no lunch: no add-ons on the order', !isset($request['items'][0]['addOnIds']) && (int) $registration['lunch_day1'] === 0 && (int) $registration['lunch_day2'] === 0);
$check('no WhatsApp: no WhatsApp consent on the order', !isset($request['buyer']['whatsappConsent']));
$check('the badge name can differ from the website name', $request['buyer']['name'] === 'Prof. Badge Name' && Db::value('SELECT speaker_name FROM speaker_guests WHERE ref = ?', [$second['partner_reference']]) === $names[1]);

// 4. A speaker who is not on the website.
$extra = SpeakerGuests::register($owner, $form('Dr. Guest Speaker ' . $tag, ['lunch_day2' => '0']));
$check('a speaker not on the website can be registered', $extra['status'] === 'issued'
    && json_decode((string) $extra['request_json'], true)['items'][0]['addOnIds'] === ['lunch1-test']);

// 5. PSOOLA refuses: the speaker is tagged, shown as refused, and can be registered again.
file_get_contents('http://127.0.0.1:8862/fixture/permission?enabled=0');
$refusedForm = $form($names[2]);
$check('a refusal from PSOOLA is shown as an error', $reject(fn () => SpeakerGuests::register($owner, $refusedForm)));
file_get_contents('http://127.0.0.1:8862/fixture/permission?enabled=1');
$refusedRow = array_values(array_filter(SpeakerGuests::all(), static fn (array $row): bool => $row['speaker_name'] === $names[2]))[0] ?? null;
$check('the refused order still shows in the list for that speaker', $refusedRow !== null && in_array(SpeakerGuests::state($refusedRow), ['refused', 'waiting'], true));
if ($refusedRow && SpeakerGuests::state($refusedRow) === 'waiting') {
    $retried = SpeakerGuests::retry($owner, (string) $refusedRow['ref']);
    $check('Ask PSOOLA again finishes a waiting order with the same request', $retried['status'] === 'issued');
} else {
    $fresh = SpeakerGuests::register($owner, $form($names[2]));
    $check('after a refusal the speaker can be registered again', $fresh['status'] === 'issued');
}
$check('Ask PSOOLA again is Owner only', $reject(fn () => SpeakerGuests::retry($staff, (string) $order['partner_reference']), 'Only the Owner'));

echo "$passed speaker checks passed.\n";
