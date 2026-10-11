<?php
// Only the main Owner deletes, one record at a time, and a copy is always kept.
// ISMILE_CONFIG=/test/config.php php backend/tests/owner-delete.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Erase;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\Sponsors;
use Ismile\UserError;
use Ismile\Payments\FakeGateway;
use Ismile\Payments\Payments;

if (App::isLive() || App::config('payments.gateway') !== 'fake'
    || !str_starts_with((string) App::config('db.name'), 'ismile_erase_test_')) {
    throw new RuntimeException('Use an isolated ismile_erase_test_* DB and the fake gateway.');
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
$mainEmail = Erase::MAIN_OWNER;

$account = static fn (string $email, string $role): array => Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser($email, $role, $role, bin2hex(random_bytes(12)))]);
$main = $account($mainEmail, 'owner');
$other = $account("other-$tag@test.invalid", 'owner');
$office = $account("office-$tag@test.invalid", 'registration');

$check('only redyar8r@gmail.com is the main Owner', Erase::MAIN_OWNER === 'redyar8r@gmail.com' && Erase::isMainOwner($main));
$check('capital letters in the address do not matter', Erase::isMainOwner(['email' => ' Redyar8r@Gmail.com '] + $main));
$check('a second Owner is not the main Owner', !Erase::isMainOwner($other) && !Erase::isMainOwnerAccount($other));
$check('a disabled main Owner cannot delete', !Erase::isMainOwner(['disabled_at' => '2026-10-11 00:00:00'] + $main));
$check('a second Owner is told who can delete', str_contains((string) Erase::lockedReason($other), 'redyar8r@gmail.com'));
$check('office staff see no delete box', Erase::lockedReason($office) === null && !Erase::isMainOwner($office));

// A paid registration, through the fake gateway, with a check-in.
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');
$form = ['first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan', 'phone' => '07501234567',
    'email' => "paid-$tag@example.invalid", 'city' => 'Sulaimani', 'gender' => 'female', 'age' => '28', 'specialty' => 'gp',
    'lang' => 'ku', 'ticket' => 'professional', 'lunch_day1' => '0', 'lunch_day2' => '0', 'terms' => '1'];
$checkout = Checkouts::createFromForm($form, null);
Payments::start($checkout);
$payment = Db::one('SELECT * FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$checkout['id']]);
$state = FakeGateway::load($payment['provider_payment_id']);
FakeGateway::save($payment['provider_payment_id'], array_replace($state, ['status' => 'paid', 'paid' => (int) $payment['amount_expected']]));
$check('fixture: the payment makes a registration', Payments::check($payment) === 'paid');
$paid = Registrations::findByRef($checkout['ref']);
$ticket = Db::one('SELECT * FROM tickets WHERE registration_id = ?', [$paid['id']]);
Db::insert('ticket_attendance', ['ticket_id' => $ticket['id'], 'event_day' => 1, 'checked_in_at' => App::now(), 'checked_in_by' => $main['id']]);
$keep = Office::createComplimentary($main, ['first_name' => 'Nadia', 'father_name' => 'Ali', 'grandfather_name' => 'Omar',
    'email' => "keep-$tag@test.invalid", 'phone' => '07501112233', 'city' => 'Erbil', 'gender' => 'female',
    'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Delete test fixture']);
$people = (int) Db::value('SELECT COUNT(*) FROM registrations');

$check('a second Owner cannot delete a registration', $rejects(static fn () => Erase::registration((int) $paid['id'], $paid['ref'], $other)));
$check('office staff cannot delete a registration', $rejects(static fn () => Erase::registration((int) $paid['id'], $paid['ref'], $office)));
$check('the main Owner must type the right reference', $rejects(static fn () => Erase::registration((int) $paid['id'], $keep['ref'], $main)));
$check('an empty confirmation deletes nothing', $rejects(static fn () => Erase::registration((int) $paid['id'], '', $main)));
$check('nothing was deleted by the refused attempts', Registrations::find((int) $paid['id']) !== null && (int) Db::value('SELECT COUNT(*) FROM deleted_records') === 0);

Erase::registration((int) $paid['id'], strtolower($paid['ref']), $main);
$check('the registration is gone', Registrations::find((int) $paid['id']) === null);
$check('its ticket and check-in are gone', !Db::value('SELECT COUNT(*) FROM tickets WHERE id = ?', [$ticket['id']]) && !Db::value('SELECT COUNT(*) FROM ticket_attendance WHERE ticket_id = ?', [$ticket['id']]));
$check('its payment, form and emails are gone', !Db::value('SELECT COUNT(*) FROM payments WHERE id = ?', [$payment['id']])
    && !Db::value('SELECT COUNT(*) FROM checkouts WHERE id = ?', [$checkout['id']])
    && !Db::value('SELECT COUNT(*) FROM emails WHERE registration_id = ? OR checkout_id = ?', [$paid['id'], $checkout['id']]));
$check('only that one person was deleted', (int) Db::value('SELECT COUNT(*) FROM registrations') === $people - 1 && Registrations::find((int) $keep['id']) !== null);
$copy = Db::one("SELECT * FROM deleted_records WHERE kind = 'registration' AND ref = ?", [$paid['ref']]);
$data = json_decode((string) ($copy['data'] ?? ''), true);
$check('a full copy is kept', $copy && (int) $copy['deleted_by'] === (int) $main['id'] && $data['registrations'][0]['email'] === $form['email']
    && count($data['payments']) === 1 && count($data['tickets']) === 1 && count($data['ticket_attendance']) === 1 && count($data['checkouts']) === 1);
$check('the deletion is in the audit log', (bool) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'registration.delete' AND target_id = ?", [$paid['id']]));
$check('a deleted registration cannot be deleted twice', $rejects(static fn () => Erase::registration((int) $paid['id'], $paid['ref'], $main)));

// A sponsor booking with a call, and an exhibition booth booking.
$sponsor = Sponsors::createFromForm(['kind' => 'sponsor', 'company' => 'Delete Test Dental', 'contact' => 'Karwan Ali', 'phone' => '07501234568', 'email' => "sponsor-$tag@example.invalid"]);
Sponsors::logCall((int) $sponsor['id'], ['outcome' => 'no_answer', 'note' => 'fixture'], $main);
$booth = Sponsors::createFromForm(['kind' => 'booth', 'company' => 'Keep Booth Co', 'contact' => 'Shna Omar', 'phone' => '07501234569', 'email' => "booth-$tag@example.invalid"]);
$check('a second Owner cannot delete a booking', $rejects(static fn () => Erase::sponsorRequest((int) $sponsor['id'], $sponsor['ref'], $other)));
$check('a booking needs its own reference typed', $rejects(static fn () => Erase::sponsorRequest((int) $sponsor['id'], $booth['ref'], $main)));
Erase::sponsorRequest((int) $sponsor['id'], $sponsor['ref'], $main);
$check('the sponsor booking, its calls and emails are gone', Sponsors::find((int) $sponsor['id']) === null
    && !Db::value('SELECT COUNT(*) FROM sponsor_calls WHERE request_id = ?', [$sponsor['id']])
    && !Db::value('SELECT COUNT(*) FROM emails WHERE sponsor_request_id = ?', [$sponsor['id']]));
$check('the other booking stays', Sponsors::find((int) $booth['id']) !== null);
$sponsorCopy = json_decode((string) Db::value("SELECT data FROM deleted_records WHERE kind = 'sponsor' AND ref = ?", [$sponsor['ref']]), true);
$check('a copy of the booking and its call is kept', $sponsorCopy['sponsor_requests'][0]['company'] === 'Delete Test Dental' && count($sponsorCopy['sponsor_calls']) === 1);
Erase::sponsorRequest((int) $booth['id'], $booth['ref'], $main);
$check('a booth booking is kept as kind booth', (bool) Db::value("SELECT COUNT(*) FROM deleted_records WHERE kind = 'booth' AND ref = ?", [$booth['ref']]));

// Team accounts.
$arman = $account("arman-$tag@test.invalid", 'owner');
$check('a second Owner cannot delete an account', $rejects(static fn () => Erase::account((int) $arman['id'], $arman['email'], $other)));
$check('an account needs its email typed', $rejects(static fn () => Erase::account((int) $arman['id'], 'wrong@test.invalid', $main)));
$check('the main Owner account can never be deleted', $rejects(static fn () => Erase::account((int) $main['id'], $main['email'], $main)));
Erase::account((int) $arman['id'], strtoupper($arman['email']), $main);
$gone = Db::one('SELECT * FROM admin_users WHERE id = ?', [$arman['id']]);
$check('the account cannot sign in and leaves the list', Erase::isDeletedAccount($gone) && $gone['disabled_at'] !== null && $gone['role'] === 'checkin'
    && !Db::value('SELECT COUNT(*) FROM admin_users WHERE email = ?', [$arman['email']]) && (int) $gone['totp_enabled'] === 0);
$check('its name stays for the history', str_starts_with($gone['name'], 'owner') && str_ends_with($gone['name'], '(deleted)'));
$check('the deploy knows not to make it again', Erase::wasDeleted($arman['email']) && Erase::wasDeleted(' ' . strtoupper($arman['email'])) && !Erase::wasDeleted($other['email']));
$check('a deleted account cannot be deleted twice', $rejects(static fn () => Erase::account((int) $arman['id'], $gone['email'], $main)));
$check('the address can be used for a new account', (bool) Auth::createUser($arman['email'], 'Arman again', 'checkin', bin2hex(random_bytes(12))));

echo "$passed checks passed\n";
