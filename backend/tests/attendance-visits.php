<?php
// ISMILE_CONFIG=/isolated/config.php php backend/tests/attendance-visits.php
// Requires an isolated ismile_visits_test_* database with the current schema.
//
// Every visit (owner, 20 Nov 2026): in at 10:00, out at 10:30, back in at
// 12:00 → the time inside is 30 min, and from 12:00 it counts on from 30 min.
// The summit ends on Day 2 at 19:45: no check-in or check-out from then on.
// Both days end at 19:45 for the time inside: 19 Nov, 20 Nov and both days.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Attendance;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Office;
use Ismile\Settings;
use Ismile\Tickets;
use Ismile\UserError;
use Ismile\Payments\PsoolaCheckIns;
use Ismile\Payments\PsoolaWorkshopCheckIns;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_visits_test_')) {
    throw new RuntimeException('Use an isolated ismile_visits_test_* database, never a real event database.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$rejects = static function (callable $fn, string $has = ''): bool {
    try { $fn(); return false; } catch (UserError $e) { return $has === '' || str_contains($e->getMessage(), $has); }
};
$ms = static fn (string $at): int => strtotime($at) * 1000;

Settings::set('event_day1', '2026-11-19');
Settings::set('event_day2', '2026-11-20');

// ---- The end of the summit: Day 2, 19:45:00 ----
$check('the summit ends on Day 2 at 19:45:00', Attendance::closesAt() === '2026-11-20 19:45:00');
$check('19:44:59 on Day 2 is still open', !Attendance::closed('2026-11-20 19:44:59'));
$check('19:45:00 on Day 2 is closed', Attendance::closed('2026-11-20 19:45:00'));
$check('later that night and the next day are closed', Attendance::closed('2026-11-20 23:59:59') && Attendance::closed('2026-11-21 00:00:01'));
$check('Day 1 evening is not affected', !Attendance::closed('2026-11-19 21:00:00'));

$id = Auth::createUser('visits-' . bin2hex(random_bytes(4)) . '@test.invalid', 'Visits test', 'owner', bin2hex(random_bytes(14)));
$owner = ['id' => $id, 'role' => 'owner'];
$staff = ['id' => $id, 'role' => 'checkin'];
$make = static fn (string $first): array => Office::createComplimentary($owner, ['first_name' => $first, 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan',
    'email' => 'v-' . bin2hex(random_bytes(4)) . '@test.invalid', 'phone' => '07501112233', 'city' => 'Sulaimani', 'gender' => 'female',
    'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Visits fixture', 'whatsapp_agreement' => '1', 'agree' => '1']);
$guest = $make('Visit');
$ticket = Tickets::forRegistration((int) $guest['id']);
$tid = (int) $ticket['id'];
// What PSOOLA's GET /check-ins says about this ticket at one moment.
$scan = static fn (string $first, string $last, bool $inside): array => ['ticketCode' => 'VIS1', 'order' => ['partnerReference' => $guest['ref']],
    'firstScannedAt' => $ms($first), 'lastScannedAt' => $ms($last), 'inside' => $inside];
$visits = static fn (): array => Attendance::visits($tid)[2] ?? [];

// ---- The owner's example, as the 10-second copy sees it ----
PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 10:00:00', true)]);
$check('scanned in at 10:00: visit 1 starts', $visits() === [['entered_at' => '2026-11-20 10:00:00', 'left_at' => null]]);
$check('at 10:20 the time inside is 20 min 0 s and counting', Attendance::timeInside($visits(), 2, '2026-11-20 10:20:00') === ['seconds' => 1200, 'open' => true, 'closed_at_end' => false, 'unknown' => false, 'lengths' => [1200]]);
PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 10:30:00', false)]);
$check('scanned out at 10:30: visit 1 ends', $visits() === [['entered_at' => '2026-11-20 10:00:00', 'left_at' => '2026-11-20 10:30:00']]);
$check('while outside (11:30) the time stays at 30 min', Attendance::timeInside($visits(), 2, '2026-11-20 11:30:00')['seconds'] === 1800);
PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 12:00:00', true)]);
$check('scanned back in at 12:00: visit 2 starts', count($visits()) === 2 && $visits()[1] === ['entered_at' => '2026-11-20 12:00:00', 'left_at' => null]);
$check('at 12:10 the time counts on from 30 min: 40 min (the 1 h 30 min outside is not counted)', Attendance::timeInside($visits(), 2, '2026-11-20 12:10:00')['seconds'] === 2400);
PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 13:15:20', false)]);
$check('scanned out at 13:15:20: total 1 h 45 min 20 s over 2 visits', Attendance::timeInside($visits(), 2, '2026-11-20 14:00:00')['seconds'] === 6320
    && Attendance::durationText(6320) === '1 h 45 min 20 s');
$check('the same scans copied again change nothing', PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 13:15:20', false)])['left'] === 0
    && PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 13:15:20', false)])['back_in'] === 0 && count($visits()) === 2);
$check('the Registration page still shows the last exit time', Db::value('SELECT checked_out_at FROM ticket_attendance WHERE ticket_id = ? AND event_day = 2', [$tid]) === '2026-11-20 13:15:20');

// ---- The end of the summit ----
PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 19:00:00', true)]);
$check('back in at 19:00: visit 3 starts', count($visits()) === 3);
$check('scanned out at 19:45:00: NOT recorded (the summit is over)', PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 19:45:00', false)])['left'] === 0
    && $visits()[2]['left_at'] === null);
$check('scanned out at 20:30: NOT recorded', PsoolaCheckIns::record([$scan('2026-11-20 10:00:00', '2026-11-20 20:30:00', false)])['left'] === 0 && $visits()[2]['left_at'] === null);
$check('a visit with no exit scan stops counting at 19:45, even at 23:00', Attendance::timeInside($visits(), 2, '2026-11-20 23:00:00') === ['seconds' => 6320 + 2700, 'open' => false, 'closed_at_end' => true, 'unknown' => false, 'lengths' => [1800, 4520, 2700]]);
$check('and the next day too', Attendance::timeInside($visits(), 2, '2026-11-21 09:00:00')['seconds'] === 6320 + 2700);

$late = $make('Late');
$lt = Tickets::forRegistration((int) $late['id']);
$lateScan = static fn (string $at, bool $inside): array => ['ticketCode' => 'VIS2', 'order' => ['partnerReference' => $late['ref']],
    'firstScannedAt' => $ms($at), 'lastScannedAt' => $ms($at), 'inside' => $inside];
$check('a first scan at 19:45:00 is NOT an arrival', PsoolaCheckIns::record([$lateScan('2026-11-20 19:45:00', true)])['added'] === 0
    && !Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ?', [$lt['id']]) && !Db::value('SELECT id FROM attendance_visits WHERE ticket_id = ?', [$lt['id']]));
$check('a first scan at 19:44:59 IS an arrival with its visit', PsoolaCheckIns::record([$lateScan('2026-11-20 19:44:59', true)])['added'] === 1
    && (Attendance::visits((int) $lt['id'])[2][0]['entered_at'] ?? '') === '2026-11-20 19:44:59');

// ---- Day 1 also ends at 19:45 (owner, 20 Nov 2026) ----
$day1 = $make('DayOne');
$d1 = Tickets::forRegistration((int) $day1['id']);
PsoolaCheckIns::record([['ticketCode' => 'VIS3', 'order' => ['partnerReference' => $day1['ref']], 'firstScannedAt' => $ms('2026-11-19 09:00:00'), 'lastScannedAt' => $ms('2026-11-19 09:00:00'), 'inside' => true]]);
$check('Day 1 ends at 19:45 on 19 Nov', Attendance::dayEndsAt(1) === '2026-11-19 19:45:00');
$check('Day 1 visit without an exit scan is counted until 19:45 that day: 10 h 45 min', Attendance::timeInside(Attendance::visits((int) $d1['id'])[1], 1, '2026-11-20 10:00:00')
    === ['seconds' => 38700, 'open' => false, 'closed_at_end' => true, 'unknown' => false, 'lengths' => [38700]]);
$check('Day 1 visit going on is counted up to now', Attendance::timeInside(Attendance::visits((int) $d1['id'])[1], 1, '2026-11-19 12:00:00')['seconds'] === 10800);
$check('a Day 1 exit scan after 19:45 counts only up to 19:45', Attendance::timeInside([['entered_at' => '2026-11-19 18:00:00', 'left_at' => '2026-11-19 21:00:00']], 1, '2026-11-20 10:00:00')['seconds'] === 6300);
$check('19 Nov + 20 Nov = the time for both days', Attendance::timeInside(Attendance::visits((int) $d1['id'])[1], 1, '2026-11-20 23:00:00')['seconds'] + Attendance::timeInside($visits(), 2, '2026-11-20 23:00:00')['seconds'] === 38700 + 9020);
$check('the time is written exactly', Attendance::durationText(38700) === '10 h 45 min 0 s' && Attendance::durationText(65) === '1 min 5 s' && Attendance::durationText(9) === '9 s');

// ---- An arrival from before visits were kept: the first exit makes visit 1 from it ----
$old = $make('Before');
$ot = Tickets::forRegistration((int) $old['id']);
Db::insert('ticket_attendance', ['ticket_id' => $ot['id'], 'event_day' => 2, 'checked_in_at' => '2026-11-20 09:00:00', 'checked_in_by' => null, 'checkin_method' => 'psoola']);
PsoolaCheckIns::record([['ticketCode' => 'VIS4', 'order' => ['partnerReference' => $old['ref']], 'firstScannedAt' => $ms('2026-11-20 09:00:00'), 'lastScannedAt' => $ms('2026-11-20 11:00:00'), 'inside' => false]]);
$check('an older arrival without visits: the exit makes visit 1 from the arrival', Attendance::visits((int) $ot['id'])[2] === [['entered_at' => '2026-11-20 09:00:00', 'left_at' => '2026-11-20 11:00:00']]);

// ---- The upgrade copies what was known before ----
$sql = (string) file_get_contents(__DIR__ . '/../database/migrations/2026-11-20-attendance-visits.sql');
$copied = $make('Copied');
$ct = Tickets::forRegistration((int) $copied['id']);
Db::insert('ticket_attendance', ['ticket_id' => $ct['id'], 'event_day' => 1, 'checked_in_at' => '2026-11-19 09:30:00', 'checked_out_at' => '2026-11-19 15:00:00', 'checked_in_by' => null, 'checkin_method' => 'psoola']);
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', (string) preg_replace('/^\s*--.*$/m', '', $sql)))) as $statement) App::db()->exec($statement);
$check('the upgrade copies earlier arrivals and exits as visits, once', Attendance::visits((int) $ct['id'])[1] === [['entered_at' => '2026-11-19 09:30:00', 'left_at' => '2026-11-19 15:00:00']]
    && count($visits()) === 3);

// ---- Workshop doors after the end ----
Db::insert('workshops', ['id' => 'ws-visits', 'title_en' => 'Visits', 'organizer' => 'ismile', 'total_seats' => 5, 'provider_event_id' => 'evt_visits', 'scheduled_date' => '2026-11-20', 'created_at' => App::now(), 'updated_at' => App::now()]);
$b = Db::insert('workshop_bookings', ['registration_id' => $guest['id'], 'workshop_id' => 'ws-visits', 'price_agreed' => 0, 'payment_status' => 'complimentary', 'created_at' => App::now(), 'updated_at' => App::now()]);
Db::insert('workshop_ticket_orders', ['booking_id' => $b, 'registration_id' => $guest['id'], 'workshop_id' => 'ws-visits', 'idempotency_key' => bin2hex(random_bytes(16)), 'partner_reference' => 'WS-VISITS', 'request_json' => '{}', 'provider_session_id' => 'ws_visits', 'provider_status' => 'paid', 'created_at' => App::now(), 'updated_at' => App::now()]);
$ws = static fn (string $first, string $last, bool $inside): array => ['ticketCode' => 'W1', 'order' => ['id' => 'ws_visits'], 'firstScannedAt' => $ms($first), 'lastScannedAt' => $ms($last), 'inside' => $inside];
$bk = static fn (): array => Db::one('SELECT checked_in_at, checked_out_at FROM workshop_bookings WHERE id = ?', [$b]);
$check('a workshop scan at 19:45 is not an arrival', PsoolaWorkshopCheckIns::record('ws-visits', [$ws('2026-11-20 19:45:00', '2026-11-20 19:45:00', true)])['arrived'] === 0 && $bk()['checked_in_at'] === null);
$check('a workshop exit at 19:45 is not recorded', PsoolaWorkshopCheckIns::record('ws-visits', [$ws('2026-11-20 18:00:00', '2026-11-20 19:45:00', false)])['left'] === 0
    && $bk() === ['checked_in_at' => '2026-11-20 18:00:00', 'checked_out_at' => null]);

// ---- The Registration list uses the same time inside (owner, 20 Nov 2026) ----
// In 10:00, out 10:30, back in 12:00, out 12:30: 1 h inside, not 2 h 30 min.
$list = $make('Listy');
$listScan = static fn (string $last, bool $inside): array => ['ticketCode' => 'VIS5', 'order' => ['partnerReference' => $list['ref']],
    'firstScannedAt' => $ms('2026-11-20 10:00:00'), 'lastScannedAt' => $ms("2026-11-20 $last"), 'inside' => $inside];
foreach ([['10:00:00', true], ['10:30:00', false], ['12:00:00', true], ['12:29:59', false]] as [$at, $in]) PsoolaCheckIns::record([$listScan($at, $in)]);
$stayRows = static fn (string $which, string $attendance = 'day2'): int => count(\Ismile\Admin\CheckinWorkspace::rows(['attendance' => $attendance, 'stay' => $which, 'q' => $list['ref']]));
$row = \Ismile\Admin\CheckinWorkspace::rows(['attendance' => 'day2', 'q' => $list['ref']])[0];
$check('the list tag shows the time inside: Stayed 59 min 59 s (not 2 h 29 min)', \Ismile\Admin\CheckinWorkspace::insideText($row['inside2']) === 'Stayed 59 min 59 s');
$check('59 min 59 s inside is "Under 1 hour", although first in to last out is 2 h 29 min', $stayRows('short') === 1 && $stayRows('long') === 0);
PsoolaCheckIns::record([$listScan('12:30:00', false)]);
Db::run("UPDATE attendance_visits v JOIN tickets t ON t.id = v.ticket_id SET v.left_at = '2026-11-20 12:30:00' WHERE t.registration_id = ? AND v.entered_at = '2026-11-20 12:00:00'", [$list['id']]);
$row = \Ismile\Admin\CheckinWorkspace::rows(['attendance' => 'day2', 'q' => $list['ref']])[0];
$check('your example: in 10:00, out 10:30, in 12:00, out 12:30 = Stayed 1 h 0 min 0 s', \Ismile\Admin\CheckinWorkspace::insideText($row['inside2']) === 'Stayed 1 h 0 min 0 s');
$check('exactly 1 hour inside is "1 hour or more"', $stayRows('long') === 1 && $stayRows('short') === 0);
$check('with no day chosen the filter works on either day', $stayRows('long', 'attended') === 1 && $stayRows('short', 'attended') === 0);
$check('the database count and the tag agree (same rule)', (int) Db::value('SELECT ' . \Ismile\Admin\CheckinWorkspace::insideSql(2)[0] . ' FROM tickets t WHERE t.registration_id = ?', [...\Ismile\Admin\CheckinWorkspace::insideSql(2)[1], $list['id']]) === $row['inside2']['seconds']);
$check('Day 1 with no exit scan: the list counts it until 19:45 on 19 Nov', \Ismile\Admin\CheckinWorkspace::insideText(\Ismile\Admin\CheckinWorkspace::rows(['attendance' => 'day1', 'q' => $day1['ref']])[0]['inside1']) === 'Stayed 10 h 45 min 0 s');

// ---- The desk (live): refused at and after the end time, a visit before ----
$config = require getenv('ISMILE_CONFIG');
App::boot(['env' => 'live'] + $config);
$cache = new ReflectionProperty(Settings::class, 'cache');
$saved = Settings::all();
$desk = $make('Desk');
$dt = Tickets::forRegistration((int) $desk['id']);
$cache->setValue(null, ['event_day1' => date('Y-m-d', strtotime('yesterday')), 'event_day2' => date('Y-m-d'), 'event_end_time' => date('H:i')] + $saved);
$check('after the end time the desk check-in is refused: "Check-in is closed"', $rejects(static fn () => Attendance::checkInManually((int) $dt['id'], (int) $dt['version'], 2, $staff, true), 'Check-in is closed'));
$check('after the end time a QR check-in is refused', $rejects(static fn () => Attendance::checkIn(Tickets::qrPayload($dt), 2, $staff), 'Check-in is closed'));
$check('nothing was recorded', !Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ?', [$dt['id']]));
$cache->setValue(null, ['event_day1' => date('Y-m-d', strtotime('yesterday')), 'event_day2' => date('Y-m-d'), 'event_end_time' => '23:59'] + $saved);
$admitted = Attendance::checkInManually((int) $dt['id'], (int) $dt['version'], 2, $staff, true);
$check('before the end time the desk check-in works and starts a visit', !$admitted['duplicate'] && count(Attendance::visits((int) $dt['id'])[2] ?? []) === 1);
$cache->setValue(null, $saved);

echo "$passed attendance visit checks passed\n";
