<?php
// Closing what is over (owner, 19 Nov 2026): a lunch day closes at 17:00 on its
// own date and a workshop closes once its date and finishing time have passed.
// Nobody can add either after that, by any route. People who already have
// them keep them. A future lunch day and a future workshop stay open.
//
//   ISMILE_CONFIG=... php backend/tests/day-closing.php
//
// Needs an empty test database (installed with backend/tools/install.php).
// Nothing here reaches PSOOLA: every refusal comes before any provider call.

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\GuestWorkshops;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SpeakerTickets;
use Ismile\UserError;
use Ismile\VipPackages;
use Ismile\WorkshopCash;
use Ismile\WorkshopCashSale;
use Ismile\WorkshopCatalog;
use Ismile\WorkshopCheckout;
use Ismile\Workshops;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_dayclose_test')) {
    throw new RuntimeException('Run this only on an ismile_dayclose_test* database.');
}

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    echo 'PASS: ' . $name . PHP_EOL;
    $passed++;
};
/** The UserError key (or message) the call threw, or null when it did not throw one. */
$error = static function (callable $fn): ?string {
    try { $fn(); } catch (UserError $e) { return $e->key; }
    return null;
};

$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow = date('Y-m-d', strtotime('+1 day'));

// ---------------- lunch ----------------
Settings::set('event_day1', $yesterday);
Settings::set('event_day2', $tomorrow);
Settings::set('lunch_capacity_day1', '0');
Settings::set('lunch_capacity_day2', '0');
$check('a lunch day whose date has passed is closed', Registrations::lunchClosed(1));
$check('a lunch day still to come is open', !Registrations::lunchClosed(2));
Settings::set('event_day1', date('Y-m-d'));
$check('today\'s lunch closes at 17:00 Iraq time', Registrations::lunchClosed(1) === (App::now() >= date('Y-m-d') . ' 17:00:00'));
Settings::set('event_day1', $yesterday);

$caller = static fn (array $more = []): array => $more + ['first_name' => 'Late', 'father_name' => 'Lunch', 'grandfather_name' => 'Guest',
    'phone' => '0750' . random_int(1000000, 9999999), 'email' => 'late-' . bin2hex(random_bytes(4)) . '@example.invalid', 'specialty' => 'gp', 'ticket' => 'professional'];
$check('desk, phone and free-ticket forms refuse a closed lunch day',
    $error(fn () => Checkouts::officeRow($caller(['lunch_day1' => '1']))) === Registrations::lunchClosedMessage(1));
$row = Checkouts::officeRow($caller(['lunch_day2' => '1']));
$check('the same forms still take a lunch day that is open', $row['lunch_day2'] === 1 && $row['lunch_day1'] === 0);
$check('VIP cannot have the closed day as its included lunch',
    $error(fn () => Checkouts::officeRow($caller(['ticket' => 'vip', 'lunch_day1' => '1', 'vip_lunch_day' => '1']))) === Registrations::lunchClosedMessage(1));
$vip = Checkouts::officeRow($caller(['ticket' => 'vip', 'lunch_day2' => '1', 'vip_lunch_day' => '2']));
$check('VIP with the open day as its included lunch is fine', $vip['vip_lunch_day'] === 2);
$check('an unpaid form holding the closed lunch day cannot be paid', Registrations::lunchFullFor(['id' => 0, 'lunch_day1' => 1, 'lunch_day2' => 0]));
$check('an unpaid form with only the open lunch day can be paid', !Registrations::lunchFullFor(['id' => 0, 'lunch_day1' => 0, 'lunch_day2' => 1]));
$state = Registrations::publicState('en');
$check('the website is told the closed lunch day is not available', $state['lunch']['day1'] === false);
$lunchAddOns = new ReflectionMethod(SpeakerTickets::class, 'lunchAddOns');
$check('a speaker\'s free lunch cannot be the closed day',
    $error(fn () => $lunchAddOns->invoke(null, ['lunch_day1' => '1'])) === Registrations::lunchClosedMessage(1));

// ---------------- workshops ----------------
$now = App::now();
$past = date('Y-m-d', strtotime('-1 day'));
$future = date('Y-m-d', strtotime('+1 day'));
$workshop = static function (string $id, string $date, string $organizer = 'ismile', int $price = 0) use ($now): array {
    Db::insert('workshops', ['id' => $id, 'title_en' => 'Day closing ' . $id, 'speaker_en' => 'Dr. Test', 'organizer' => $organizer,
        'company_phone' => $organizer === 'company' ? '+9647501234567' : null, 'total_seats' => 20, 'price' => $price, 'currency' => 'IQD',
        'scheduled_date' => $date, 'start_time' => '13:00:00', 'end_time' => '15:00:00', 'status' => 'active',
        'provider_event_id' => 'evt-' . $id, 'provider_show_id' => 'show-' . $id, 'provider_ticket_type_id' => 'tt-' . $id,
        'created_at' => $now, 'updated_at' => $now]);
    return Workshops::find($id);
};
$over = $workshop('dc-over', $past);
$coming = $workshop('dc-coming', $future);
$company = $workshop('dc-company-over', $past, 'company');
$check('a workshop whose date and end time have passed is over', Workshops::ended($over) && Workshops::ended(Db::one('SELECT * FROM workshops WHERE id = ?', ['dc-over'])));
$check('a workshop still to come is not over', !Workshops::ended($coming));
$check('a workshop with no date is never treated as over', !Workshops::ended(['scheduledDate' => null, 'endTime' => null]));
$today = Workshops::ended(['scheduledDate' => date('Y-m-d'), 'endTime' => '15:00']);
$check('today\'s workshop closes at its own finishing time', $today === (App::now() >= date('Y-m-d') . ' 15:00:00'));

$person = Db::one('SELECT * FROM registrations WHERE id = ?', [Db::insert('registrations', ['ref' => 'DC' . strtoupper(bin2hex(random_bytes(4))),
    'first_name' => 'Late', 'father_name' => 'Workshop', 'grandfather_name' => 'Guest', 'email' => 'late-workshop@example.invalid', 'phone' => '+9647501234567',
    'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket_type' => 'professional', 'status' => 'paid', 'view_nonce' => bin2hex(random_bytes(16)),
    'created_at' => $now, 'updated_at' => $now, 'paid_at' => $now])]);
$owner = ['id' => 1, 'role' => 'owner', 'workshop_booking_access' => 1, 'registration_manage_access' => 1];

$check('website online registration refuses a workshop that is over', $error(fn () => WorkshopCheckout::start('dc-over', $person['email'])) === 'wd_err_full');
$check('website cash request refuses a workshop that is over', $error(fn () => WorkshopCash::request('dc-over', $person['email'])) === 'wd_err_full');
$check('the older online route refuses a workshop that is over', $error(fn () => Workshops::registerOnline('dc-over', $person['email'])) === 'wd_err_full');
$check('booking from the person page refuses a workshop that is over', $error(fn () => Office::addWorkshop($person, $owner, ['workshop' => 'dc-over'])) === Workshops::ENDED_MESSAGE);
$check('a guest workshop ticket refuses a workshop that is over',
    $error(fn () => GuestWorkshops::start($owner, $person, ['delivery_confirmation' => 'email-whatsapp-v1', 'attempt' => bin2hex(random_bytes(16)), 'workshop' => 'dc-over'])) === Workshops::ENDED_MESSAGE);
$check('a desk cash sale refuses a workshop that is over',
    $error(fn () => WorkshopCashSale::register($person, $owner, ['cash_received' => '1', 'cash_currency' => 'IQD', 'cash_amount' => '50000', 'workshop' => 'dc-over'])) === Workshops::ENDED_MESSAGE);
$check('a free desk registration refuses a workshop that is over',
    $error(fn () => WorkshopCashSale::register($person, $owner, ['free_registration' => '1', 'cash_amount' => '0', 'cash_currency' => 'IQD', 'workshop' => 'dc-over'])) === Workshops::ENDED_MESSAGE);
$check('a company workshop that is over still tells people to call the company', $error(fn () => WorkshopCheckout::start('dc-company-over', $person['email'])) === 'wd_err_by_phone');

// The same routes with a workshop still to come get past the "over" rule (they
// may stop later for another reason, such as PSOOLA not being reachable here).
foreach ([
    'website online registration' => fn () => WorkshopCheckout::start('dc-coming', $person['email']),
    'website cash request' => fn () => WorkshopCash::request('dc-coming', $person['email']),
    'person page booking' => fn () => Office::addWorkshop($person, $owner, ['workshop' => 'dc-coming']),
    'guest workshop ticket' => fn () => GuestWorkshops::start($owner, $person, ['delivery_confirmation' => 'email-whatsapp-v1', 'attempt' => bin2hex(random_bytes(16)), 'workshop' => 'dc-coming']),
    'desk cash sale' => fn () => WorkshopCashSale::register($person, $owner, ['cash_received' => '1', 'cash_currency' => 'IQD', 'cash_amount' => '50000', 'workshop' => 'dc-coming']),
] as $route => $call) {
    try { $key = $error($call); } catch (Throwable) { $key = 'other'; }
    $check("$route is still open for a workshop to come", $key !== Workshops::ENDED_MESSAGE && $key !== 'wd_err_full');
}

// VIP included workshop: only the two VIP workshops; one that is over cannot be chosen or paid for.
foreach (VipPackages::WORKSHOPS as $i => $id) {
    Db::run('DELETE FROM workshops WHERE id = ?', [$id]);   // install.php copies the website's workshops
    Db::insert('workshops', ['id' => $id, 'title_en' => 'VIP ' . $id, 'organizer' => 'ismile', 'total_seats' => 20, 'price' => 0, 'currency' => 'IQD',
        'scheduled_date' => $i === 0 ? $past : $future, 'start_time' => '13:00:00', 'end_time' => '17:00:00', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
}
$check('a VIP included workshop that is over cannot be chosen', $error(fn () => Db::transaction(fn () => VipPackages::requireWorkshop(VipPackages::WORKSHOPS[0]))) === 'vip_workshop_full');
$check('a VIP included workshop still to come can be chosen', $error(fn () => Db::transaction(fn () => VipPackages::requireWorkshop(VipPackages::WORKSHOPS[1]))) === null);

// The website's list: a workshop that is over shows no seats, one to come keeps its seats.
$list = array_column(array_filter(WorkshopCatalog::forWebsite(), 'is_array'), null, 'id');
$check('the website shows a workshop that is over with no seats left', ($list['dc-over']['seatsLeft'] ?? null) === 0);
$check('the website shows a workshop still to come with its seats', ($list[VipPackages::WORKSHOPS[1]]['seatsLeft'] ?? null) === 20);
$check('nobody was booked on a workshop that is over', !Db::value("SELECT id FROM workshop_bookings WHERE workshop_id IN ('dc-over','dc-company-over')"));

echo "$passed day-closing checks passed\n";
