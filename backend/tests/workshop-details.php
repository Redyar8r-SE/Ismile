<?php
// Workshop details page: trainer photo and details, prices in IQD or USD,
// online "Register for workshop" with the summit email, workshops organized
// by a company (register by phone), and the one-time example workshops for
// the video.
// ISMILE_CONFIG=/test/config.php php backend/tests/workshop-details.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Office;
use Ismile\UserError;
use Ismile\Workshops;

if (App::isLive() || App::config('payments.gateway') !== 'fake'
    || !str_starts_with((string) App::config('db.name'), 'ismile_workshop_test_')) {
    throw new RuntimeException('Use an isolated ismile_workshop_test_* DB and the fake gateway.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$errorKey = static function (callable $work): ?string {
    try { $work(); return null; } catch (UserError $error) { return $error->key; }
};
$tag = bin2hex(random_bytes(4));
$owner = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("owner-$tag@test.invalid", 'Owner', 'owner', bin2hex(random_bytes(12)))]);
$website = static fn (): array => json_decode((string) file_get_contents(App::siteFile('data/workshops.json')), true) ?: [];

// ---- a workshop with details, priced in dollars ----
$id = Workshops::create([
    'title_en' => "Composite $tag", 'speaker_en' => 'Dr. Alan Karim', 'credentials_en' => 'BDS, MSc, PhD',
    'format_en' => 'Hands-on Clinical Workshop', 'duration_en' => '3 Hours', 'level_en' => 'Intermediate to Advanced',
    'overview_en' => "First line.\n\nSecond paragraph.", 'price' => '100', 'currency' => 'USD', 'total_seats' => '2',
], $owner);
$workshop = Workshops::find($id);
$check('the details are saved in English', $workshop['credentials']['en'] === 'BDS, MSc, PhD' && $workshop['format']['en'] === 'Hands-on Clinical Workshop'
    && $workshop['duration']['en'] === '3 Hours' && $workshop['level']['en'] === 'Intermediate to Advanced');
$check('the overview keeps its paragraphs', $workshop['overview']['en'] === "First line.\n\nSecond paragraph.");
$check('the price is in dollars', $workshop['price'] === 100 && $workshop['currency'] === 'USD');
$check('an unknown currency becomes IQD', Workshops::find(Workshops::create(['title_en' => "Other $tag", 'price' => '5000', 'currency' => 'EUR', 'total_seats' => '5'], $owner))['currency'] === 'IQD');
$check('money shows $ for dollars and IQD for dinars', Workshops::money(100, 'USD') === '$100' && Workshops::money(75000, 'IQD') === '75,000 IQD');
$card = array_values(array_filter($website(), static fn (array $w): bool => $w['id'] === $id))[0] ?? null;
$check('the website card carries the details, currency and photo', $card !== null && $card['currency'] === 'USD' && $card['overview']['en'] === "First line.\n\nSecond paragraph."
    && $card['duration']['en'] === '3 Hours' && array_key_exists('photo', $card) && $card['photo'] === null);
$check('a photo that is not an uploaded file is refused', $errorKey(static fn () => Workshops::update($id, ['title_en' => "Composite $tag", 'total_seats' => '2', 'currency' => 'USD', 'price' => '100'], $owner,
    ['name' => 'x.jpg', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10])) !== null);
$check('only the Owner changes workshops', $errorKey(static fn () => Workshops::create(['title_en' => 'X', 'total_seats' => '5'], ['id' => 0, 'role' => 'registration'])) !== null);

// ---- online registration with the summit email ----
$form = ['first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan', 'phone' => '0750' . random_int(1000000, 9999999),
    'email' => "Sara-$tag@Example.invalid", 'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Test'];
$person = Office::createComplimentary($owner, $form);
$check('a wrong email is refused', $errorKey(static fn () => Workshops::registerOnline($id, 'not-an-email')) === 'wd_err_email');
$check('an email that is not registered for the summit is refused', $errorKey(static fn () => Workshops::registerOnline($id, "nobody-$tag@example.invalid")) === 'wd_err_not_registered');
$check('an unknown workshop is refused', $errorKey(static fn () => Workshops::registerOnline('no-such-workshop', $person['email'])) === 'wd_err_missing');
Workshops::registerOnline($id, "  SARA-$tag@example.INVALID ");
$booking = Db::one('SELECT * FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ?', [$person['id'], $id]);
$check('a registered person gets a seat, whatever the email case', $booking !== null && $booking['removed_at'] === null);
$check('the online seat is NOT PAID at the workshop price, booked by nobody (the website)', $booking['payment_status'] === 'unpaid' && (int) $booking['price_agreed'] === 100
    && $booking['booked_by'] === null && $booking['notes'] === 'Registered online on the website');
$check('the activity log records the online registration', (bool) Db::value("SELECT id FROM audit_log WHERE action = 'workshop.online' AND target_id = ?", [$person['id']]));
$card = array_values(array_filter($website(), static fn (array $w): bool => $w['id'] === $id))[0];
$check('the website shows one seat fewer at once', $card['seatsLeft'] === 1);
$check('the same person cannot register twice', $errorKey(static fn () => Workshops::registerOnline($id, $person['email'])) === 'wd_err_already');
$second = Office::createComplimentary($owner, ['email' => "second-$tag@example.invalid", 'phone' => '0751' . random_int(1000000, 9999999)] + $form);
Workshops::registerOnline($id, $second['email']);
$third = Office::createComplimentary($owner, ['email' => "third-$tag@example.invalid", 'phone' => '0752' . random_int(1000000, 9999999)] + $form);
$check('a full workshop takes nobody more', $errorKey(static fn () => Workshops::registerOnline($id, $third['email'])) === 'wd_err_full');
$check('still exactly 2 people on the 2 seats', Workshops::booked($id) === 2);
Office::cancel($third, $owner, 'Test');
$check('a cancelled person is not registered', $errorKey(static fn () => Workshops::registerOnline(Workshops::create(['title_en' => "Open $tag", 'total_seats' => '5'], $owner), $third['email'])) === 'wd_err_not_registered');
Workshops::setStatus($id, 'hidden', $owner);
$check('a hidden workshop takes no online registration', $errorKey(static fn () => Workshops::registerOnline($id, $third['email'])) === 'wd_err_missing');
Workshops::setStatus($id, 'active', $owner);

// ---- organized by a company: people call the company ----
$check('a company workshop needs the company name', $errorKey(static fn () => Workshops::create(['title_en' => "Co $tag", 'total_seats' => '5', 'organizer' => 'company', 'company_phone' => '+964 750 123 4567'], $owner)) !== null);
$check('a company workshop needs the phone', $errorKey(static fn () => Workshops::create(['title_en' => "Co $tag", 'total_seats' => '5', 'organizer' => 'company', 'company_en' => 'Dental Co'], $owner)) !== null);
$check('a phone that is not a number is refused', $errorKey(static fn () => Workshops::create(['title_en' => "Co $tag", 'total_seats' => '5', 'organizer' => 'company', 'company_en' => 'Dental Co', 'company_phone' => 'call us'], $owner)) !== null);
$companyId = Workshops::create(['title_en' => "Co $tag", 'total_seats' => '5', 'organizer' => 'company', 'company_en' => 'Dental Co', 'company_phone' => '+964 750 123 4567'], $owner);
$company = Workshops::find($companyId);
$check('the organizer and phone are saved', $company['organizer'] === 'company' && $company['companyPhone'] === '+964 750 123 4567' && $company['company']['en'] === 'Dental Co');
$card = array_values(array_filter($website(), static fn (array $w): bool => $w['id'] === $companyId))[0] ?? null;
$check('the website card says organized by the company, with its phone', $card !== null && $card['organizer'] === 'company' && $card['companyPhone'] === '+964 750 123 4567');
$check('a company workshop takes no online registration', $errorKey(static fn () => Workshops::registerOnline($companyId, $person['email'])) === 'wd_err_by_phone' && Workshops::booked($companyId) === 0);
$check('an unknown organizer becomes iSmile', Workshops::find(Workshops::create(['title_en' => "Ism $tag", 'total_seats' => '5', 'organizer' => 'someone'], $owner))['organizer'] === 'ismile');
$ismCard = array_values(array_filter($website(), static fn (array $w): bool => $w['id'] === $id))[0];
$check("an iSmile workshop's card has no company phone", $ismCard['organizer'] === 'ismile' && $ismCard['companyPhone'] === null);
Workshops::update($companyId, ['title_en' => "Co $tag", 'total_seats' => '5', 'organizer' => 'ismile', 'company_en' => 'Dental Co', 'company_phone' => '+964 750 123 4567'], $owner);
$check('switched back to iSmile, online registration works again', $errorKey(static fn () => Workshops::registerOnline($companyId, $person['email'])) === null && Workshops::booked($companyId) === 1);

// ---- the office side shows dollars ----
$check('the currency cannot change while people are booked', $errorKey(static fn () => Workshops::update($id, ['title_en' => "Composite $tag", 'total_seats' => '2', 'currency' => 'IQD', 'price' => '100'], $owner)) !== null);
$paid = Office::changeWorkshop((int) $booking['id'], $owner, ['payment_status' => 'paid', 'amount_paid' => '100', 'paid_how' => 'cash']);
$check('marking the online seat paid says $100', str_contains($paid, '$100'));
$check('a wrong amount is refused with dollars in the message', str_contains((string) (static function () use ($second, $id, $owner): string {
    $row = Db::one('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ?', [$second['id'], $id]);
    try { Office::changeWorkshop((int) $row['id'], $owner, ['payment_status' => 'paid', 'amount_paid' => '90', 'paid_how' => 'cash']); return ''; } catch (UserError $error) { return $error->key; }
})(), '$90'));

// ---- the one-time example workshops for the video ----
$now = App::now();
foreach (['implant', 'dsd'] as $old) {
    Db::run("INSERT IGNORE INTO workshops (id, icon, price, total_seats, status, sort_order, created_at, updated_at) VALUES (?, 'tools', 0, 20, 'active', 0, ?, ?)", [$old, $now, $now]);
}
Db::insert('workshop_bookings', ['registration_id' => $person['id'], 'workshop_id' => 'dsd', 'price_agreed' => 0, 'payment_status' => 'unpaid', 'created_at' => $now, 'updated_at' => $now]);
$run = static function (): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/video-workshops.php');
    return (string) shell_exec($command);
};
$out = $run();
$check('the tool deletes "Coming soon" workshops nobody was booked on', !Db::value("SELECT id FROM workshops WHERE id = 'implant'") && str_contains($out, 'implant'));
$check('a workshop with a booking is never deleted', (bool) Db::value("SELECT id FROM workshops WHERE id = 'dsd'"));
$soft = Workshops::find('soft-tissue-implants');
$composite = Workshops::find('composite-restorations');
$check('the two example workshops are added at $100 with 30 seats and photos', $soft !== null && $composite !== null && $soft['price'] === 100 && $soft['currency'] === 'USD'
    && $soft['totalSeats'] === 30 && $soft['photo'] === 'assets/workshops/sarah-hassan.jpg' && $composite['photo'] === 'assets/workshops/alan-karim.jpg');
$check('the example photos exist on the website', is_file(dirname(__DIR__, 2) . '/assets/workshops/sarah-hassan.jpg') && is_file(dirname(__DIR__, 2) . '/assets/workshops/alan-karim.jpg'));
$check('the examples are on the website cards', count(array_filter($website(), static fn (array $w): bool => in_array($w['id'], ['soft-tissue-implants', 'composite-restorations'], true))) === 2);
$check("Dr. Sarah Hassan's example is organized by iSmile", $soft['organizer'] === 'ismile' && $soft['companyPhone'] === null);
$check("Dr. Alan Karim's example is organized by a company, with a phone", $composite['organizer'] === 'company' && $composite['company']['en'] === 'Partner Dental Company' && $composite['companyPhone'] === '+964 750 123 4567');
$check('running the tool again changes nothing', str_contains($run(), 'added none') && (int) Db::value("SELECT COUNT(*) FROM workshops WHERE id IN ('soft-tissue-implants','composite-restorations')") === 2);

// The first version of the tool added the composite workshop as iSmile's:
// the tool makes it the company's, but never overwrites the Owner's own company.
Db::run("UPDATE workshops SET organizer = 'ismile', company_en = NULL, company_phone = NULL WHERE id = 'composite-restorations'");
$out = $run();
$composite = Workshops::find('composite-restorations');
$check('an iSmile composite workshop from the first version becomes the company one', str_contains($out, 'now organized by') && $composite['organizer'] === 'company' && $composite['companyPhone'] === '+964 750 123 4567');
Workshops::update('composite-restorations', ['title_en' => 'Composite', 'total_seats' => '30', 'price' => '100', 'currency' => 'USD', 'organizer' => 'company', 'company_en' => 'Real Co', 'company_phone' => '+964 770 000 1111'], $owner);
$run();
$check("the tool never overwrites the Owner's own company", Workshops::find('composite-restorations')['company']['en'] === 'Real Co');

echo "$passed checks passed.\n";
