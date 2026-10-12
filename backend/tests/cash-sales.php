<?php
// Cash sales (PSOOLA POST /orders) and PSOOLA's gate scans (GET /check-ins).
// ISMILE_CONFIG=/test/config.php php backend/tests/cash-sales.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\Tickets;
use Ismile\UserError;
use Ismile\Payments\Payments;
use Ismile\Payments\PsoolaCheckIns;
use Ismile\Payments\PsoolaGateway;

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
$staff = Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser("desk-$tag@test.invalid", 'Desk', 'registration', bin2hex(random_bytes(12)))]);
$form = ['first_name' => 'Karwan', 'father_name' => 'Ali', 'grandfather_name' => 'Omar', 'phone' => '0750' . random_int(1000000, 9999999),
    'email' => "cash-$tag@example.invalid", 'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'professional'];

// ---- a cash sale: registered at once, as paid by cash ----
$emails = (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind = 'ticket'");
$checkout = Checkouts::createForCash($form, $staff);
$check('the cash form is marked cash and made by the desk', $checkout['pay_method'] === 'cash' && (int) $checkout['created_by'] === (int) $staff['id']);
$sale = Payments::sellForCash($checkout, $staff);
$registration = $sale['registration_id'] ? Registrations::find($sale['registration_id']) : null;
$check('the cash sale registers the person at once', $sale['status'] === 'paid' && $registration !== null && $registration['status'] === 'paid');
$check('the registration says paid by cash', $registration['pay_method'] === 'cash' && $registration['ref'] === $checkout['ref']);
$payment = Db::one('SELECT * FROM payments WHERE checkout_id = ?', [$checkout['id']]);
$check('the payment is a confirmed cash payment for the full price', $payment['method'] === 'cash' && $payment['status'] === 'paid'
    && (int) $payment['amount_confirmed'] === (int) $payment['amount_expected'] && $sale['amount'] === (int) $payment['amount_expected']);
$ticket = Tickets::forRegistration((int) $registration['id']);
$check('a ticket is issued from the payment', $ticket !== null && $ticket['source'] === 'payment' && (int) $ticket['payment_id'] === (int) $payment['id']);
$check('the test gateway (not PSOOLA) queues the iSmile ticket email', (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind = 'ticket'") === $emails + 1);
$check('the sale is in the activity log with the amount', (bool) Db::value("SELECT id FROM audit_log WHERE action = 'payment.cash' AND target_id = ?", [$payment['id']]));

// ---- never sold twice ----
$check('the same form cannot be sold a second time', $rejects(static fn () => Payments::sellForCash($checkout, $staff)));
$check('still exactly one payment for the form', (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$checkout['id']]) === 1);

// ---- the Settings switch stops cash sales too ----
Settings::set('registration_open', '0');
$closed = Checkouts::createForCash(['email' => "closed-$tag@example.invalid", 'phone' => '0751' . random_int(1000000, 9999999)] + $form, $staff);
$check('registration closed: a cash sale is refused', $rejects(static fn () => Payments::sellForCash($closed, $staff)));
$check('registration closed: nothing was sold', (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$closed['id']]) === 0);
Settings::set('registration_open', '1');

// ---- PSOOLA's answers: ticket links are never kept, refusals are explained ----
$stored = PsoolaGateway::withoutLinks('{"id":"ord_1","status":"paid","ticketsUrl":"https://psoola.example/t/secret","order":{"totalAmount":40000}}');
$check('the ticket link is removed before an answer is stored', !str_contains($stored, 'secret') && str_contains($stored, '"ord_1"') && str_contains($stored, '40000'));
$check('an answer without a ticket link is kept as it is', PsoolaGateway::withoutLinks('{"id":"x"}') === '{"id":"x"}');
$refusal = PsoolaGateway::friendlyError(['status' => 422, 'json' => ['error' => ['code' => 'LIMIT_EXCEEDED', 'message' => 'Too many'], 'requestId' => 'req1']]);
$check('a refused cash sale is explained in plain words', str_contains($refusal, 'most tickets allowed') && str_contains($refusal, 'Nothing was sold') && str_contains($refusal, 'req1'));

// ---- PSOOLA gate scans become attendance and certificates ----
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
Settings::set('event_day1', $today);
Settings::set('event_day2', $tomorrow);
$nowMs = time() * 1000;
$scan = ['ticketCode' => 'RH8G4VB6', 'firstScannedAt' => $nowMs, 'lastScannedAt' => $nowMs, 'order' => ['id' => (string) $payment['provider_payment_id'], 'partnerReference' => $registration['ref']]];
$counts = PsoolaCheckIns::record([$scan]);
$arrival = Db::one('SELECT * FROM ticket_attendance WHERE ticket_id = ? AND event_day = 1', [$ticket['id']]);
$check('a PSOOLA scan today is recorded as Day 1 attendance', $counts['added'] === 1 && $arrival !== null && $arrival['checkin_method'] === 'psoola' && $arrival['checked_in_by'] === null);
$check('the arrival keeps the scan time (Iraq time)', $arrival['checked_in_at'] === date('Y-m-d H:i:s', intdiv($nowMs, 1000)));
$check('the attended guest gets a certificate', (bool) Db::value('SELECT id FROM certificates WHERE registration_id = ?', [$registration['id']]));
$check('copying the same scan again changes nothing', PsoolaCheckIns::record([$scan])['added'] === 0
    && (int) Db::value('SELECT COUNT(*) FROM ticket_attendance WHERE ticket_id = ?', [$ticket['id']]) === 1);
$dayTwo = ['lastScannedAt' => strtotime($tomorrow . ' 09:30:00') * 1000] + $scan;
$check('a last scan on the second day adds Day 2', PsoolaCheckIns::record([$dayTwo])['added'] === 1
    && (bool) Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ? AND event_day = 2', [$ticket['id']]));
$byOrder = ['order' => ['id' => (string) $payment['provider_payment_id'], 'partnerReference' => '']] + $scan;
$check('a scan is matched by PSOOLA\'s order id when the reference is missing', PsoolaCheckIns::record([$byOrder])['unmatched'] === 0);
$stranger = ['order' => ['id' => 'ord_unknown', 'partnerReference' => 'ISM26-NOBODY']] + $scan;
$check('a scan for a sale we do not know is counted, not recorded', PsoolaCheckIns::record([$stranger]) === ['seen' => 1, 'added' => 0, 'unmatched' => 1, 'outside_event_days' => 0]);
$early = ['firstScannedAt' => strtotime('-10 days') * 1000, 'lastScannedAt' => strtotime('-10 days') * 1000] + $scan;
$check('a scan outside the two event days is not attendance', PsoolaCheckIns::record([$early])['outside_event_days'] === 1);
$check('the scan days are worked out from first and last scan', PsoolaCheckIns::days($dayTwo) === [1 => date('Y-m-d H:i:s', intdiv($nowMs, 1000)), 2 => $tomorrow . ' 09:30:00']);

echo "\n$passed checks passed.\n";
