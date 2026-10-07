<?php
// The plan's checklist (sections 10.6 and 12), run for real against a TEST
// copy of the site: registrations, payments, webhooks, tickets, emails,
// students, capacity, security. Every line must pass before going live.
//
//   php backend/tests/scenarios.php
//
// It talks to the site over HTTP (site_url in config.php) exactly like a
// visitor and like Psoola would, then checks the database. It refuses to run
// on the live site or with real payments, and it changes test data.

declare(strict_types=1);

use Ismile\App;
use Ismile\Ambassadors;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Backup;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\EmailTemplates;
use Ismile\Links;
use Ismile\Office;
use Ismile\Outbox;
use Ismile\Payments\FakeGateway;
use Ismile\Payments\Payments;
use Ismile\Registrations;
use Ismile\Security;
use Ismile\Settings;
use Ismile\Sponsors;
use Ismile\Tickets;
use Ismile\Totp;
use Ismile\UserError;
use Ismile\Mail\MailerFactory;

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';
if (App::isLive() || App::config('payments.gateway') !== 'fake') {
    fwrite(STDERR, "Refusing to run: this is for the TEST site with the fake gateway only.\n");
    exit(1);
}

$base = App::config('site_url');
$passed = 0;
$failed = [];
$saved = Settings::all();

// The staff member the checks act as: a disabled test account, so nobody can
// sign in with it, but every action is recorded against a real admin row.
$adminId = (int) Db::value("SELECT id FROM admin_users WHERE email = 'scenarios@test.invalid'");
if ($adminId === 0) {
    $adminId = Db::insert('admin_users', [
        'email' => 'scenarios@test.invalid', 'name' => 'Automatic checks', 'role' => 'owner',
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'disabled_at' => App::now(), 'created_at' => App::now(),
    ]);
}

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  \033[32m✓\033[0m $what\n";
    } else {
        $failed[] = $what;
        echo "  \033[31m✗ $what\033[0m" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

/** @return array{status: int, body: string, json: ?array, location: ?string, headers: array} */
function http(string $method, string $url, array|string|null $body = null, array $headers = []): array
{
    $curl = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $text = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $json = json_decode($text, true);
    return ['status' => $status, 'body' => $text, 'json' => is_array($json) ? $json : null, 'location' => $responseHeaders['location'] ?? null, 'headers' => $responseHeaders];
}

function form(array $overrides = []): array
{
    static $n = 0;
    $n++;
    $stamp = substr((string) (time() + $n), -4);
    return array_merge([
        'lang' => 'en', 'first_name' => 'Test', 'father_name' => 'Person', 'grandfather_name' => 'Number' . $n,
        'phone' => '0750 9' . str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT) . ' ' . $stamp, 'email' => "test$n.$stamp@example.com",
        'city' => 'Sulaimani', 'gender' => 'female', 'age' => '30', 'specialty' => 'gp', 'ticket' => 'professional',
        'pay' => 'visa', 'terms' => '1',
    ], $overrides);
}

function register(array $fields): array
{
    global $base;
    return http('POST', "$base/api/register.php", $fields, ['Origin: ' . $base]);
}

/** Acts as the visitor on the pretend payment page. */
function fakePay(string $redirect, string $choice): void
{
    $query = [];
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    $url = strtok($redirect, '?');
    http('POST', $url, http_build_query(['id' => $query['id'], 's' => $query['s'], 'choice' => $choice]));
}

function registrationByRef(string $ref): array
{
    return Registrations::findByRef($ref) ?? [];
}

/** The form waiting for payment (not a registration). */
function checkoutByRef(string $ref): array
{
    return Checkouts::findByRef($ref) ?? [];
}

function lastPaymentOf(array $checkout): array
{
    return Db::one('SELECT * FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$checkout['id'] ?? 0]) ?? [];
}

function webhook(string $body, array $headers): array
{
    global $base;
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $lines[] = 'Content-Type: application/json';
    return http('POST', "$base/api/webhook.php", $body, $lines);
}

// A small valid JPEG for the student tests
$jpeg = App::storage('tmp/test-id.jpg');
$image = imagecreatetruecolor(400, 250);
imagefill($image, 0, 0, imagecolorallocate($image, 200, 220, 240));
imagestring($image, 5, 20, 20, 'STUDENT ID TEST', imagecolorallocate($image, 0, 0, 0));
imagejpeg($image, $jpeg);
imagedestroy($image);

Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');
Settings::set('lunch_capacity_day1', '0');
Settings::set('lunch_capacity_day2', '0');
Settings::set('email_test_mode', '0');

try {
    // ------------------------------------------------------------------
    section('Opening rules');
    $prices = \Ismile\SiteData::prices();
    check('prices are set on the test site (needed for the rest)', $prices['professional'] > 0 && $prices['student'] > 0, json_encode($prices));
    Settings::set('registration_open', '0');
    $state = http('GET', "$base/api/config.php?lang=ku")['json'];
    check('closed: the page is told "closed" with the Kurdish message', $state && $state['open'] === false && $state['reason'] === 'closed' && $state['message'] === Settings::closedMessage('ku'));
    $refused = register(form());
    check('closed: a registration sent anyway is refused (409 reg_closed)', $refused['status'] === 409 && ($refused['json']['error'] ?? '') === 'reg_closed');
    Settings::set('registration_open', '1');
    check('open: the page is told "open"', (http('GET', "$base/api/config.php")['json']['open'] ?? false) === true);

    // ------------------------------------------------------------------
    section('Only people who paid are registered');
    $answer = register(form(['lunch_day1' => '1', 'pay' => 'fib']));
    $ref = (string) ($answer['json']['ref'] ?? '');
    $form = checkoutByRef($ref);
    check('the form is kept only while paying, with a server-made reference', $answer['status'] === 200 && preg_match('/^ISM26-[A-Z0-9]{6}$/', $ref) === 1 && ($form['status'] ?? '') === 'open');
    check('NOT registered before paying (not in the registrations at all)', Registrations::findByRef($ref) === null);
    $sent = array_values(array_filter(\Ismile\Admin\Lists::rows('forms'), fn ($row) => $row['ref'] === $ref));
    check('the unpaid form shows at once in Lists → All forms sent, as "waiting"', count($sent) === 1 && $sent[0]['form_status'] === 'waiting' && $sent[0]['id'] === null);
    check('… and in the database list 13_forms_sent', Db::value('SELECT `Payment` FROM `13_forms_sent` WHERE `Reference` = ?', [$ref]) === 'Not paid yet');
    $payment = lastPaymentOf($form);
    check('price worked out by the server (ticket + lunch day 1)', (int) $payment['amount_expected'] === $prices['professional'] + $prices['lunchDay1']);
    check('visitor is sent to the payment page', str_contains((string) ($answer['json']['redirect'] ?? ''), 'fake-psoola.php'));
    check('the method they chose is recorded', $payment['method'] === 'fib');
    $early = http('GET', "$base/api/status.php?r=$ref&k=" . Links::viewToken($form))['json'];
    check('their page says "not paid yet", no ticket', ($early['status'] ?? '') === 'unpaid' && ($early['ticketNo'] ?? null) === null);
    fakePay((string) $answer['json']['redirect'], 'pay');
    $reg = registrationByRef($ref);
    $ticket = Tickets::forRegistration((int) ($reg['id'] ?? 0));
    check('paid → NOW registered, with the same reference', ($reg['status'] ?? '') === 'paid' && lastPaymentOf($form)['status'] === 'paid' && (int) lastPaymentOf($form)['registration_id'] === (int) $reg['id']);
    check('…and every detail of the form was carried over', $reg['first_name'] === $form['first_name'] && $reg['email'] === $form['email'] && (int) $reg['lunch_day1'] === 1 && $reg['pay_method'] === 'fib');
    check('ticket made with a number', $ticket !== null && preg_match('/^T26-\d{5}$/', $ticket['ticket_no']) === 1);
    check('ticket email queued', (int) Db::value("SELECT COUNT(*) FROM emails WHERE registration_id = ? AND kind = 'ticket'", [$reg['id']]) === 1);
    $status = http('GET', "$base/api/status.php?r=$ref&k=" . Links::viewToken($reg))['json'];
    check('the same personal link now shows paid, the ticket number and QR', ($status['status'] ?? '') === 'paid' && ($status['ticketNo'] ?? '') === $ticket['ticket_no'] && !empty($status['qr']));
    $qr = http('GET', "$base/api/qr.php?r=$ref&k=" . Links::viewToken($reg));
    check('QR image is served on the personal link', $qr['status'] === 200 && str_starts_with($qr['body'], "\x89PNG"));

    // ------------------------------------------------------------------
    section('Webhooks: repeats, fakes, unknown');
    $paidPayment = lastPaymentOf($form);
    $message = FakeGateway::webhookFor((string) $paidPayment['provider_payment_id'], 'paid');
    $again = webhook($message['body'], $message['headers']);
    check('the same webhook twice changes nothing', $again['status'] === 200 && str_starts_with((string) ($again['json']['outcome'] ?? ''), 'no change'));
    check('still exactly one registration and one ticket', (int) Db::value('SELECT COUNT(*) FROM registrations WHERE ref = ?', [$ref]) === 1 && (int) Db::value('SELECT COUNT(*) FROM tickets WHERE registration_id = ?', [$reg['id']]) === 1);
    $fake = webhook(json_encode(['payment_id' => $paidPayment['provider_payment_id'], 'status' => 'paid']), ['x-fake-signature' => 'forged']);
    check('an unsigned / forged webhook is rejected (401)', $fake['status'] === 401);
    check('…and logged as REJECTED', (int) Db::value("SELECT COUNT(*) FROM webhook_log WHERE outcome = 'REJECTED: signature'") >= 1);
    $unknown = FakeGateway::webhookFor('FAKE-000000000000', 'paid');
    check('a genuine webhook about an unknown payment registers nobody', str_starts_with((string) (webhook($unknown['body'], $unknown['headers'])['json']['outcome'] ?? ''), 'unknown payment'));

    // ------------------------------------------------------------------
    section('Money problems: never registered without the exact amount');
    $answer = register(form());
    $less = checkoutByRef((string) $answer['json']['ref']);
    fakePay((string) $answer['json']['redirect'], 'pay-less');
    check('a smaller confirmed amount: NOT registered (held as mismatch)', Registrations::findByRef($less['ref']) === null && lastPaymentOf($less)['status'] === 'mismatch');
    check('…and Finance is alerted', (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind = 'alert' AND data LIKE '%does not match%'") >= 1);

    $answer = register(form());
    $failedForm = checkoutByRef((string) $answer['json']['ref']);
    fakePay((string) $answer['json']['redirect'], 'fail');
    check('failed payment: NOT registered, no ticket, no email', Registrations::findByRef($failedForm['ref']) === null && (int) Db::value('SELECT COUNT(*) FROM emails WHERE checkout_id = ?', [$failedForm['id']]) === 0);
    $retryLink = Links::payUrl($failedForm);
    $retry = http('GET', $retryLink);
    check('"Try again" starts a fresh payment (no new form)', $retry['status'] === 303 && str_contains((string) $retry['location'], 'fake-psoola.php'));
    check("someone else's link is refused", str_contains((string) http('GET', "$base/api/pay.php?r={$failedForm['ref']}&k=wrong")['location'], 'e=link'));

    $answer = register(form());
    $silent = checkoutByRef((string) $answer['json']['ref']);
    fakePay((string) $answer['json']['redirect'], 'pay-silent');
    check('paid but the webhook is lost: not registered yet', Registrations::findByRef($silent['ref']) === null);
    Db::run('UPDATE payments SET created_at = ? WHERE checkout_id = ?', [date('Y-m-d H:i:s', time() - 600), $silent['id']]);
    Payments::checkWaiting();
    check('the 5-minute job asks Psoola, finds it paid: registered, ticket made', (registrationByRef($silent['ref'])['status'] ?? '') === 'paid' && Tickets::forRegistration((int) registrationByRef($silent['ref'])['id']) !== null);

    // The card was declined, then the person paid on the same company page
    $answer = register(form());
    $late = checkoutByRef((string) $answer['json']['ref']);
    fakePay((string) $answer['json']['redirect'], 'fail');
    $latePayment = lastPaymentOf($late);
    $state = FakeGateway::load((string) $latePayment['provider_payment_id']);
    $state['status'] = 'paid';
    $state['paid'] = $state['amount'];
    FakeGateway::save((string) $latePayment['provider_payment_id'], $state);
    $message = FakeGateway::webhookFor((string) $latePayment['provider_payment_id'], 'paid');
    webhook($message['body'], $message['headers']);
    check('paid AFTER being marked failed: the money is not lost, registered', (registrationByRef($late['ref'])['status'] ?? '') === 'paid' && lastPaymentOf($late)['status'] === 'paid');

    Settings::set('registration_open', '0');
    check('registration switched off: "Try again" cannot start a payment', str_contains((string) http('GET', Links::payUrl($failedForm))['location'], 'e=reg_closed'));
    Settings::set('registration_open', '1');

    // Paid twice: two attempts open at once, both paid
    $answer = register(form());
    $twice = checkoutByRef((string) $answer['json']['ref']);
    check('clicking "Pay" again within minutes reuses the same attempt', Payments::start($twice)['redirect'] === $answer['json']['redirect'] && (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$twice['id']]) === 1);
    Db::run('UPDATE payments SET created_at = ? WHERE checkout_id = ?', [date('Y-m-d H:i:s', time() - (Payments::REUSE_MINUTES + 1) * 60), $twice['id']]);
    $second = Payments::start($twice);
    fakePay((string) $answer['json']['redirect'], 'pay');
    fakePay((string) $second['redirect'], 'pay');
    $statuses = array_column(Db::all('SELECT status FROM payments WHERE checkout_id = ? ORDER BY id', [$twice['id']]), 'status');
    $twiceReg = registrationByRef($twice['ref']);
    check('paid twice: ONE registration, one ticket; the second payment is held for Finance', $statuses === ['paid', 'duplicate'] && (int) Db::value('SELECT COUNT(*) FROM registrations WHERE ref = ?', [$twice['ref']]) === 1
        && (int) Db::value('SELECT COUNT(*) FROM tickets WHERE registration_id = ?', [$twiceReg['id']]) === 1, implode(',', $statuses));

    // ------------------------------------------------------------------
    section('Students: send the ID photo, pay, registered (no approval step)');
    $bad = App::storage('tmp/virus.jpg');
    file_put_contents($bad, "MZ\x90\x00 this is really a program " . str_repeat('x', 500));
    $answer = register(form(['specialty' => 'student', 'university' => 'Uni of Sulaimani', 'student_id' => new CURLFile($bad, 'image/jpeg', 'id.jpg')]));
    check('a renamed program as "ID photo" is refused', $answer['status'] === 422 && ($answer['json']['error'] ?? '') === 'err_student_id_type' && ($answer['json']['field'] ?? '') === 'p_student_id');
    $answer = register(form(['specialty' => 'student', 'university' => 'Uni of Sulaimani']));
    check('a student without a photo is refused', ($answer['json']['error'] ?? '') === 'err_student_id');
    $answer = register(form(['specialty' => 'student', 'ticket' => 'professional', 'university' => 'Uni of Sulaimani', 'ambassador' => 'AMB-7', 'student_id' => new CURLFile($jpeg, 'image/jpeg', 'id.jpg')]));
    $studentForm = checkoutByRef((string) ($answer['json']['ref'] ?? ''));
    check('"dental student" always gets the student ticket', ($studentForm['ticket_type'] ?? '') === 'student');
    check('a student goes straight to payment, like everyone', str_contains((string) ($answer['json']['redirect'] ?? ''), 'fake-psoola.php') && (int) lastPaymentOf($studentForm)['amount_expected'] === $prices['student']);
    $photoRow = Db::one('SELECT * FROM student_id_photos WHERE id = ?', [(int) ($studentForm['id_photo_id'] ?? 0)]);
    check('the ID photo is stored IN the database (a real JPEG, with its size and fingerprint)', $photoRow !== null && str_starts_with($photoRow['image'], "\xFF\xD8")
        && (int) $photoRow['bytes'] === strlen($photoRow['image']) && $photoRow['sha256'] === hash('sha256', $photoRow['image']) && $photoRow['original_name'] === 'id.jpg');
    check('no loose photo file is kept on the server', !is_dir(App::storage('id-photos')) || count(glob(App::storage('id-photos/*')) ?: []) === 0);
    check('NOT registered before paying', Registrations::findByRef($studentForm['ref']) === null);
    fakePay((string) $answer['json']['redirect'], 'pay');
    $student = registrationByRef($studentForm['ref']);
    check('paid → registered, with university, code and the same ID photo', ($student['status'] ?? '') === 'paid' && $student['university'] === 'Uni of Sulaimani' && $student['ambassador_code'] === 'AMB-7'
        && (int) $student['id_photo_id'] === (int) $studentForm['id_photo_id'] && Checkouts::find((int) $studentForm['id'])['id_photo_id'] === null);
    $photoPage = http('GET', "$base/admin/photo.php?id={$student['id']}");
    check('the admin photo page needs a sign-in', $photoPage['status'] === 303 && str_contains((string) $photoPage['location'], 'login.php'));

    // ------------------------------------------------------------------
    section('Forms not paid are deleted');
    $answer = register(form(['specialty' => 'student', 'university' => 'Uni of Duhok', 'student_id' => new CURLFile($jpeg, 'image/jpeg', 'id.jpg')]));
    $gone = checkoutByRef((string) $answer['json']['ref']);
    fakePay((string) $answer['json']['redirect'], 'fail');
    Db::run('UPDATE checkouts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $gone['id']]);
    check('after its time, the form cannot be paid any more', str_contains((string) http('GET', Links::payUrl($gone))['location'], 'e=pay_expired'));
    check('…and its page says "not completed, fill in the form again"', (http('GET', "$base/api/status.php?r={$gone['ref']}&k=" . Links::viewToken($gone))['json']['status'] ?? '') === 'expired');
    Db::run('UPDATE checkouts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - (Checkouts::DELETE_AFTER_HOURS + 1) * 3600), $gone['id']]);
    $cleaned = Checkouts::cleanUp();
    check('two days later it is deleted, with its failed payment and its photo', Checkouts::find((int) $gone['id']) === null
        && Db::value('SELECT id FROM student_id_photos WHERE id = ?', [(int) $gone['id_photo_id']]) === null
        && (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$gone['id']]) === 0, json_encode($cleaned));
    check('…and it never was a registration', Registrations::findByRef($gone['ref']) === null);
    Db::run('UPDATE checkouts SET expires_at = ?, created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - (Checkouts::DELETE_AFTER_HOURS + 1) * 3600), date('Y-m-d H:i:s', time() - (Checkouts::DELETE_AFTER_HOURS + 1) * 3600), $form['id']]);
    Checkouts::cleanUp();
    check('a PAID form is cleaned up too, the registration stays complete', Checkouts::find((int) $form['id']) === null && (registrationByRef($ref)['status'] ?? '') === 'paid'
        && (int) Db::value("SELECT COUNT(*) FROM payments WHERE registration_id = ? AND status = 'paid'", [$reg['id']]) === 1);
    check('the personal link still shows the ticket after that', (http('GET', "$base/api/status.php?r=$ref&k=" . Links::viewToken($reg))['json']['status'] ?? '') === 'paid');
    // A paid form with a second attempt still open is not cleaned up; once it is,
    // a late payment of that attempt is recognised as "paid twice".
    $answer = register(form());
    $two = checkoutByRef((string) $answer['json']['ref']);
    Db::run('UPDATE payments SET created_at = ? WHERE checkout_id = ?', [date('Y-m-d H:i:s', time() - (Payments::REUSE_MINUTES + 1) * 60), $two['id']]);
    $secondTry = Payments::start($two);
    fakePay((string) $answer['json']['redirect'], 'pay');
    Db::run('UPDATE checkouts SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - (Checkouts::DELETE_AFTER_HOURS + 1) * 3600), $two['id']]);
    Checkouts::cleanUp();
    check('a paid form with another payment still open is kept', Checkouts::find((int) $two['id']) !== null);
    fakePay((string) $secondTry['redirect'], 'pay');
    check('…and the late second payment is "paid twice" for Finance, linked to the person', lastPaymentOf($two)['status'] === 'duplicate' && (int) lastPaymentOf($two)['registration_id'] === (int) registrationByRef($two['ref'])['id']);

    // ------------------------------------------------------------------
    section('Registered by phone, or a free ticket (Owner)');
    $staff = ['id' => $adminId, 'role' => 'registration', 'name' => 'Checks'];
    $owner = ['id' => $adminId, 'role' => 'owner', 'name' => 'Checks'];
    $phoneForm = Checkouts::createFromOffice(['first_name' => 'Phone', 'father_name' => 'Caller', 'grandfather_name' => 'Test', 'phone' => '0751 234 5678', 'email' => 'caller' . time() . '@example.com', 'ticket' => 'professional', 'specialty' => 'omfs', 'lang' => 'ku'], $staff);
    check('a caller gets a "Pay now" email, and is NOT registered yet', (int) Db::value("SELECT COUNT(*) FROM emails WHERE checkout_id = ? AND kind = 'pay_now'", [$phoneForm['id']]) === 1 && Registrations::findByRef($phoneForm['ref']) === null);
    $link = http('GET', Links::payUrl($phoneForm));
    fakePay((string) $link['location'], 'pay');
    check('…the caller pays with the link: registered', (registrationByRef($phoneForm['ref'])['status'] ?? '') === 'paid');
    check('the caller\'s chosen specialty survives payment', (registrationByRef($phoneForm['ref'])['specialty'] ?? '') === 'omfs');
    $fails = static function (callable $work): bool {
        try {
            $work();
            return false;
        } catch (UserError) {
            return true;
        }
    };
    $guest = ['first_name' => 'Guest', 'father_name' => 'Of', 'grandfather_name' => 'Honour', 'phone' => '0770 111 2233', 'email' => 'guest' . time() . '@example.com', 'ticket' => 'professional', 'specialty' => 'acad', 'comp_reason' => 'Keynote guest'];
    check('only the Owner can give a free ticket', $fails(fn () => Office::createComplimentary($staff, $guest)));
    check('a free ticket needs a reason', $fails(fn () => Office::createComplimentary($owner, ['comp_reason' => ''] + $guest)));
    $free = Office::createComplimentary($owner, $guest);
    check('the Owner registers a guest with a free ticket: registered, ticket, logged', $free['status'] === 'complimentary' && $free['pay_method'] === null && Tickets::forRegistration((int) $free['id']) !== null
        && (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'ticket.complimentary' AND target_id = ?", [$free['id']]) === 1);
    check('a free ticket keeps the selected specialty', $free['specialty'] === 'acad');

    section('Ambassador code management');
    $code = 'TEST-' . strtoupper(bin2hex(random_bytes(6)));
    $ambassadorInput = ['code' => $code, 'owner_name' => 'Test Ambassador', 'university' => 'Test University'];
    check('registration staff cannot create ambassador codes', $fails(fn () => Ambassadors::save($ambassadorInput, $staff)));
    Ambassadors::save($ambassadorInput, $owner);
    $ambassadorId = (int) Db::value('SELECT id FROM ambassadors WHERE code = ?', [$code]);
    Ambassadors::save(['owner_name' => 'Updated Ambassador'] + $ambassadorInput, $owner);
    check('saving an existing code updates its ambassador', Db::value('SELECT owner_name FROM ambassadors WHERE id = ?', [$ambassadorId]) === 'Updated Ambassador');
    check('registration staff cannot delete ambassador codes', $fails(fn () => Ambassadors::delete($ambassadorId, $staff)) && Db::value('SELECT id FROM ambassadors WHERE id = ?', [$ambassadorId]) !== null);
    Db::update('registrations', ['ambassador_code' => $code], 'id = ?', [$student['id']]);
    Ambassadors::delete($ambassadorId, $owner);
    check('the Owner can delete a used code without changing registrations', Db::value('SELECT id FROM ambassadors WHERE id = ?', [$ambassadorId]) === null
        && Registrations::find((int) $student['id'])['ambassador_code'] === $code);
    check('deleting a code records the ambassador and code in the audit log', str_contains((string) Db::value("SELECT details FROM audit_log WHERE action = 'ambassador.delete' AND target_id = ?", [$ambassadorId]), $code));
    check('deleting a missing code reports an error', $fails(fn () => Ambassadors::delete($ambassadorId, $owner)));
    Db::update('registrations', ['ambassador_code' => $student['ambassador_code']], 'id = ?', [$student['id']]);

    // ------------------------------------------------------------------
    section('Personal links cannot be guessed');
    check("status with another person's token: refused", http('GET', "$base/api/status.php?r=$ref&k=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")['status'] === 404);
    check("QR with another person's token: refused", http('GET', "$base/api/qr.php?r=$ref&k=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")['status'] === 404);
    check('a registered person cannot start another payment by their link', !str_contains((string) http('GET', "$base/api/pay.php?r=$ref&k=" . Links::viewToken($reg))['location'], 'fake-psoola'));

    // ------------------------------------------------------------------
    section('Tickets at the door');
    $scan = Tickets::readScan(Tickets::qrPayload($ticket));
    check('a genuine QR is accepted', isset($scan['ticket']) && $scan['ticket']['ticket_no'] === $ticket['ticket_no']);
    check('a forged QR is refused', (Tickets::readScan('ISM26:' . $ticket['ticket_no'] . ':1:forgedsignature00')['error'] ?? '') === 'forged');
    $oldPayload = Tickets::qrPayload($ticket);
    $message = Office::editContact(Registrations::find((int) $reg['id']), $owner, ['first_name' => 'Renamed', 'father_name' => $reg['father_name'], 'grandfather_name' => $reg['grandfather_name'], 'email' => $reg['email'], 'phone' => $reg['phone'], 'city' => $reg['city']]);
    check('a name change reissues the ticket and emails it again', str_contains($message, 'reissued') && (int) Tickets::forRegistration((int) $reg['id'])['version'] === 2);
    check('…and the OLD QR stops working at the door', (Tickets::readScan($oldPayload)['error'] ?? '') === 'old_version');

    // ------------------------------------------------------------------
    section('Capacity');
    $taken = Registrations::ticketsTaken();
    Settings::set('lunch_capacity_day1', (string) Registrations::lunchTaken(1));
    $state = http('GET', "$base/api/config.php")['json'];
    check('lunch day 1 full: that day disappears, day 2 still works', ($state['lunch']['day1'] ?? true) === false && ($state['lunch']['day2'] ?? false) === true);
    check('choosing the full lunch day is refused', (register(form(['lunch_day1' => '1']))['json']['error'] ?? '') === 'err_lunch_full');
    Settings::set('lunch_capacity_day1', '0');
    $lunchForm = register(form(['lunch_day1' => '1']));
    $lunchHolder = checkoutByRef((string) $lunchForm['json']['ref']);
    Settings::set('lunch_capacity_day1', (string) (Registrations::lunchTaken(1) + 1));
    $lunchLate = register(form(['lunch_day1' => '1']));
    check('the last lunch is held for the person paying: the next one is told "lunch full" (not "event full")', ($lunchLate['json']['payError'] ?? '') === 'lunch_full');
    Db::run("UPDATE payments SET status = 'failed' WHERE checkout_id = ?", [$lunchHolder['id']]);
    Settings::set('lunch_capacity_day1', '0');
    Settings::set('ticket_capacity', (string) $taken);
    check('tickets full: the form closes with "full"', (http('GET', "$base/api/config.php")['json']['reason'] ?? '') === 'full');
    check('a form sent anyway is refused (reg_full)', (register(form())['json']['error'] ?? '') === 'reg_full');
    Settings::set('ticket_capacity', '0');
    $answer = register(form());
    $holder = checkoutByRef((string) $answer['json']['ref']);
    $answer = register(form());
    $other = checkoutByRef((string) $answer['json']['ref']);
    Settings::set('ticket_capacity', (string) (Registrations::ticketsTaken() + 1));
    Db::run('UPDATE payments SET created_at = ? WHERE checkout_id = ?', [date('Y-m-d H:i:s', time() - (Payments::REUSE_MINUTES + 1) * 60), $other['id']]);
    check('the last place is held for the person paying: a second payer is refused', str_contains((string) http('GET', Links::payUrl($other))['location'], 'e=reg_full'));
    $late = register(form());
    check('a form sent while the last place is held: told "full" on its page, not registered', $late['status'] === 200 && ($late['json']['ok'] ?? false) === true
        && ($late['json']['payError'] ?? '') === 'reg_full' && Registrations::findByRef((string) $late['json']['ref']) === null);
    fakePay((string) lastPaymentOf($holder)['redirect_url'], 'pay');
    check('…and the first one pays and is registered', (registrationByRef($holder['ref'])['status'] ?? '') === 'paid');
    Settings::set('ticket_capacity', '0');

    // ------------------------------------------------------------------
    section('Typed-in text stays text');
    $answer = register(form(['first_name' => '<script>alert(1)</script>', 'city' => "Erbil'; DROP TABLE registrations; --"]));
    fakePay((string) $answer['json']['redirect'], 'pay');
    $xss = registrationByRef((string) ($answer['json']['ref'] ?? ''));
    check('a name with code in it is saved as plain text', ($xss['first_name'] ?? '') === '<script>alert(1)</script>');
    check('the "SQL" in a field did nothing (table still there)', (int) Db::value('SELECT COUNT(*) FROM registrations') > 0);
    $email = EmailTemplates::build(Db::one("SELECT * FROM emails WHERE registration_id = ? AND kind = 'ticket'", [$xss['id']]));
    check('the email shows it escaped, never as code', !str_contains($email['html'], '<script>') && str_contains($email['html'], '&lt;script&gt;'));
    [$where, $params] = \Ismile\Admin\RegistrationQuery::where(['q' => "' OR 1=1 --"]);
    check('an injection attempt in the admin search finds nothing', (int) Db::value('SELECT COUNT(*) FROM ' . \Ismile\Admin\RegistrationQuery::from() . " WHERE $where", $params) === 0);

    // ------------------------------------------------------------------
    section('Admin protection');
    $login = http('POST', "$base/admin/login.php", ['email' => 'owner@example.com', 'password' => 'x']);
    check('a sign-in form without its one-time token is refused', str_contains($login['body'], 'This form has expired'));
    foreach (['index.php', 'registrations.php', 'payments.php', 'settings.php', 'export.php?what=registrations'] as $pageName) {
        $r = http('GET', "$base/admin/$pageName");
        check("admin/$pageName needs a sign-in", $r['status'] === 303 && str_contains((string) $r['location'], 'login.php'));
    }
    foreach (['//evil.example/admin/login.php', '/\evil.example/admin/index.php', 'https://evil.example/admin/index.php'] as $bad) {
        check("sign-in never sends you to another site ($bad)", !str_contains(http('GET', "$base/admin/login.php?next=" . rawurlencode($bad))['body'], 'evil.example'));
    }
    check('a list where text is expected does not crash a page', http('GET', "$base/admin/login.php?next[]=x")['status'] === 200 && http('GET', "$base/api/pay.php?r[]=x")['status'] === 303);
    $headers = http('GET', "$base/admin/login.php")['headers'];
    check('admin pages cannot be framed by other sites', ($headers['x-frame-options'] ?? '') === 'DENY' && str_contains($headers['content-security-policy'] ?? '', "frame-ancestors 'none'"));

    // ------------------------------------------------------------------
    section('Sign-in and sessions');
    $loginEmail = 'scenarios-login@test.invalid';
    $loginPassword = 'Checks-' . bin2hex(random_bytes(8));
    $loginSecret = Totp::newSecret();
    $loginId = (int) Db::value('SELECT id FROM admin_users WHERE email = ?', [$loginEmail]);
    if ($loginId === 0) {
        $loginId = Db::insert('admin_users', ['email' => $loginEmail, 'name' => 'Sign-in check', 'role' => 'finance', 'password_hash' => '-', 'created_at' => App::now()]);
    }
    Db::update('admin_users', ['password_hash' => password_hash($loginPassword, PASSWORD_DEFAULT), 'totp_secret' => $loginSecret, 'totp_enabled' => 1,
        'totp_last_step' => null, 'failed_logins' => 0, 'locked_until' => null, 'disabled_at' => null], 'id = ?', [$loginId]);
    Db::run("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");

    // Signs in like a browser (cookies kept between requests).
    $signIn = static function (string $code) use ($base, $loginEmail, $loginPassword): array {
        $jar = (string) tempnam(sys_get_temp_dir(), 'ck');
        $get = static function (string $method, string $path, ?array $fields = null) use ($base, $jar): array {
            $curl = curl_init("$base/admin/$path");
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADER => true]);
            if ($fields !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
            }
            $text = (string) curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            preg_match('/^location:\s*(\S+)/mi', $text, $m);
            preg_match('/name="csrf" value="([^"]+)"/', $text, $c);
            return ['status' => $status, 'location' => $m[1] ?? '', 'csrf' => $c[1] ?? ''];
        };
        $page = $get('GET', 'login.php');
        $page = $get('POST', 'login.php', ['csrf' => $page['csrf'], 'email' => $loginEmail, 'password' => $loginPassword]);
        $done = $get('POST', 'login.php', ['csrf' => $page['csrf'], 'step' => 'code', 'code' => $code]);
        return ['in' => $done['status'] === 303 && !str_contains($done['location'], 'login.php'), 'get' => $get];
    };
    $step = intdiv(time(), 30);
    $first = $signIn(Totp::code($loginSecret, $step));
    check('Finance signs in with password + phone code', $first['in']);
    $again = $signIn(Totp::code($loginSecret, $step));
    check('the same phone code cannot be used a second time', !$again['in']);
    check('the signed-in session opens the dashboard', ($first['get'])('GET', 'index.php')['status'] === 200);
    Auth::endOtherSessions($loginId);
    check('after a password change, the old session is signed out', str_contains(($first['get'])('GET', 'index.php')['location'], 'login.php'));
    Db::update('admin_users', ['role' => 'registration', 'totp_last_step' => null], 'id = ?', [$loginId]);
    $optionalStep = intdiv(time(), 30);
    $optional = $signIn(Totp::code($loginSecret, $optionalStep));
    check('Registration staff with a phone code enabled must use it to sign in', $optional['in']);
    check('optional phone codes cannot be reused', !$signIn(Totp::code($loginSecret, $optionalStep))['in']);
    Db::update('admin_users', ['disabled_at' => App::now()], 'id = ?', [$loginId]);

    // ------------------------------------------------------------------
    section('The database itself refuses bad data');
    $refuses = static function (callable $write): bool {
        try {
            $write();
            return false;
        } catch (\PDOException) {
            return true;
        }
    };
    $sample = registrationByRef($ref);
    $now = App::now();
    check('an UNPAID registration (the status does not even exist)', $refuses(fn () => Db::run("UPDATE registrations SET status = 'unpaid' WHERE id = ?", [$sample['id']])));
    check('a "paid" payment without its registration or with the wrong amount', $refuses(fn () => Db::insert('payments', ['gateway' => 'fake', 'amount_expected' => 1000, 'amount_confirmed' => 999, 'status' => 'paid', 'created_at' => $now, 'updated_at' => $now])));
    check('a payment for a registration that does not exist', $refuses(fn () => Db::insert('payments', ['registration_id' => 999999999, 'gateway' => 'fake', 'amount_expected' => 1, 'status' => 'created', 'created_at' => $now, 'updated_at' => $now])));
    check('a payment of 0 dinars', $refuses(fn () => Db::insert('payments', ['registration_id' => $sample['id'], 'gateway' => 'fake', 'amount_expected' => 0, 'status' => 'created', 'created_at' => $now, 'updated_at' => $now])));
    check('a free ticket without a reason', $refuses(fn () => Db::run("UPDATE registrations SET status = 'complimentary', comp_reason = NULL WHERE id = ?", [$sample['id']])));
    check('an unknown payment status', $refuses(fn () => Db::run("UPDATE payments SET status = 'maybe' WHERE registration_id = ?", [$sample['id']])));
    check('an unknown language', $refuses(fn () => Db::run("UPDATE registrations SET lang = 'fr' WHERE id = ?", [$sample['id']])));
    check('a "dental student" with a professional ticket', $refuses(fn () => Db::run("UPDATE registrations SET specialty = 'student', ticket_type = 'professional' WHERE id = ?", [$sample['id']])));
    check('a second ticket for the same person', $refuses(fn () => Db::insert('tickets', ['registration_id' => $sample['id'], 'ticket_no' => 'X-' . bin2hex(random_bytes(3)), 'source' => 'complimentary', 'created_at' => $now])));
    check('an action by an admin who does not exist', $refuses(fn () => Audit::log(999999999, 'test')));
    $simpleLists = array_column(Db::all('SELECT table_name AS name FROM information_schema.views WHERE table_schema = DATABASE() ORDER BY table_name'), 'name');
    check('all 14 numbered database lists exist, including attendance and certificates', $simpleLists === ['01_registered_people', '02_lunch_day_1', '03_lunch_day_2', '04_students', '05_workshops', '06_workshop_people', '07_sponsors', '08_exhibition', '09_sponsor_calls', '10_sponsor_packages', '11_payments', '12_money_per_day', '13_forms_sent', '14_attendance_and_certificates'], implode(',', $simpleLists));
    $listsWork = true;
    foreach ($simpleLists as $list) {
        try {
            Db::all("SELECT * FROM `$list` LIMIT 3");
        } catch (\Throwable $error) {
            $listsWork = false;
            echo "    $list: " . $error->getMessage() . "\n";
        }
    }
    check('every simple list opens', $listsWork);
    $first = Db::one('SELECT * FROM `01_registered_people` LIMIT 1') ?? [];
    check('the lists use plain column names', array_key_exists('Full name', $first) && array_key_exists('Paid (IQD)', $first) && array_key_exists('Lunch day 1', $first));

    // ------------------------------------------------------------------
    section('Sponsors and exhibition: packages, calls, money');
    $owner = ['id' => $adminId, 'role' => 'owner', 'name' => 'Checks'];
    $staff = ['id' => $adminId, 'role' => 'registration'];
    $fails = static function (callable $work): bool {
        try {
            $work();
            return false;
        } catch (UserError) {
            return true;
        }
    };
    $packageIds = array_column(\Ismile\SponsorPackages::all(), 'id');
    check('the four sponsor tiers and the single Standard booth type are installed', count(array_intersect(['platinum','gold','silver','bronze'],$packageIds)) === 4 && in_array('booth-standard', $packageIds, true));
    $spn = http('POST', "$base/api/sponsor.php", json_encode(['lang' => 'ar', 'kind' => 'sponsor', 'package' => 'platinum', 'company' => 'Test Co', 'contact' => 'Ali Hasan', 'phone' => '0750 111 2233', 'email' => 'ali@testco.example']), ['Content-Type: application/json', 'Origin: ' . $base]);
    $request = Db::one('SELECT * FROM sponsor_requests WHERE ref = ?', [$spn['json']['ref'] ?? '']);
    check('a sponsor request is saved with a reference and its package', $request !== null && $request['status'] === 'new' && $request['package_id'] === 'platinum');
    check('the company and the team are emailed', (int) Db::value('SELECT COUNT(*) FROM emails WHERE sponsor_request_id = ?', [$request['id']]) >= 2);
    $odd = http('POST', "$base/api/sponsor.php", json_encode(['lang' => 'en', 'kind' => 'booth', 'package' => 'platinum', 'company' => 'Booth Co', 'contact' => 'Sara Ahmed', 'phone' => '0750 111 4455', 'email' => 'sara@boothco.example']), ['Content-Type: application/json', 'Origin: ' . $base]);
    $booth = Db::one('SELECT * FROM sponsor_requests WHERE ref = ?', [$odd['json']['ref'] ?? '']);
    check('a booth request always receives Standard instead of a sponsor package', $booth !== null && $booth['kind'] === 'booth' && $booth['package_id'] === 'booth-standard');
    check('cannot be Confirmed before Paid', $fails(fn () => Sponsors::changeStatus($request, 'confirmed', $staff)));
    check('cannot be made Agreed without an agreed amount', $fails(fn () => Sponsors::changeStatus($request, 'agreed', $staff)));
    check('the database refuses Paid without the money recorded', $refuses(fn () => Db::run("UPDATE sponsor_requests SET status = 'paid' WHERE id = ?", [$request['id']])));
    check('the database refuses Agreed without an amount', $refuses(fn () => Db::run("UPDATE sponsor_requests SET status = 'agreed', amount_agreed = NULL WHERE id = ?", [$request['id']])));
    check('the database refuses a package that does not exist', $refuses(fn () => Db::run("UPDATE sponsor_requests SET package_id = 'nothing' WHERE id = ?", [$request['id']])));
    $id = (int) $request['id'];
    Sponsors::logCall($id, ['outcome' => 'no_answer', 'next_call_at' => date('Y-m-d\TH:i', time() - 3600)], $staff);
    $after = Sponsors::find($id);
    check('a "no answer" call is saved, the status stays New', $after['status'] === 'new' && $after['last_call_at'] !== null && count(Sponsors::calls($id)) === 1);
    check('a call that is due shows up (dashboard "calls due")', Sponsors::callsDue() >= 1);
    Sponsors::logCall($id, ['outcome' => 'interested', 'amount' => '6,000,000', 'note' => 'wants the main stage'], $staff);
    $after = Sponsors::find($id);
    check('talking to them makes it Contacted, keeps the price told and who handles it', $after['status'] === 'contacted' && (int) $after['price_quoted'] === 6000000 && (int) $after['assigned_to'] === $adminId && $after['next_call_at'] === null);
    check('"Agreed" without the amount is refused', $fails(fn () => Sponsors::logCall($id, ['outcome' => 'agreed'], $staff)));
    Sponsors::logCall($id, ['outcome' => 'agreed', 'amount' => '5000000'], $staff);
    $after = Sponsors::find($id);
    check('an "Agreed" call makes it Agreed with the amount', $after['status'] === 'agreed' && (int) $after['amount_agreed'] === 5000000);
    check('a payment of a different amount is refused', $fails(fn () => Sponsors::recordPayment($id, ['amount_paid' => '4000000', 'paid_how' => 'cash'], $staff)));
    Sponsors::recordPayment($id, ['amount_paid' => '5,000,000', 'paid_how' => 'transfer'], $staff);
    $after = Sponsors::find($id);
    check('exactly the agreed amount is recorded as Paid', $after['status'] === 'paid' && (int) $after['amount_paid'] === 5000000 && $after['paid_how'] === 'transfer' && $after['paid_at'] !== null);
    check('after paying, a call cannot decline them or change the amount', $fails(fn () => Sponsors::logCall($id, ['outcome' => 'declined'], $staff)) && $fails(fn () => Sponsors::logCall($id, ['outcome' => 'agreed', 'amount' => '1'], $staff)));
    check('after paying, staff cannot move them back (Owner only)', $fails(fn () => Sponsors::changeStatus(Sponsors::find($id), 'contacted', $staff)));
    check('staff cannot undo a payment (Owner only)', $fails(fn () => Sponsors::undoPayment($id, $staff)));
    \Ismile\SponsorPackages::update('platinum', ['name_en' => 'Platinum', 'price' => '5000000', 'places' => '1', 'style' => 'tc-dia'], $owner);
    Sponsors::changeStatus(Sponsors::find($id), 'confirmed', $staff);
    check('a paid sponsor is Confirmed', Sponsors::find($id)['status'] === 'confirmed');
    Sponsors::saveDetails((int) $booth['id'], ['package_id' => 'platinum'], $staff);
    check('staff cannot switch a booth from Standard to a sponsor tier', Sponsors::find((int)$booth['id'])['package_id'] === 'booth-standard');
    check('exhibition booths cannot reserve a sponsor map position', $fails(fn () => Sponsors::saveDetails((int)$booth['id'], ['booth_number'=>'12'], $staff)));
    Sponsors::saveDetails((int) $booth['id'], ['package_id' => 'booth-standard', 'assigned_to' => (string) $adminId], $staff);
    $boothAfter = Sponsors::find((int) $booth['id']);
    check('a booth keeps Standard and its assigned staff without a map number', $boothAfter['package_id'] === 'booth-standard' && $boothAfter['booth_number'] === null && (int)$boothAfter['assigned_to'] === $adminId);
    // A second Platinum sponsor, while the only Platinum place is taken.
    $second = Db::insert('sponsor_requests', ['ref' => 'SPN26-T' . strtoupper(bin2hex(random_bytes(2))), 'kind' => 'sponsor', 'package_id' => 'platinum', 'company' => 'Second Co', 'contact_name' => 'B', 'phone' => '+9647501112299', 'email' => 'b@second.example', 'lang' => 'en', 'status' => 'new', 'created_at' => App::now(), 'updated_at' => App::now()]);
    Sponsors::logCall($second, ['outcome' => 'agreed', 'amount' => '5000000'], $staff);
    Sponsors::recordPayment($second, ['amount_paid' => '5000000', 'paid_how' => 'cash'], $staff);
    check('a package cannot have more Confirmed companies than places', $fails(fn () => Sponsors::changeStatus(Sponsors::find($second), 'confirmed', $staff)));
    Sponsors::changeStatus(Sponsors::find($second), 'confirmed', $owner, true);
    check('the Owner can override a full package (logged)', Sponsors::find($second)['status'] === 'confirmed'
        && (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'sponsor.status' AND target_id = ? AND details LIKE '%\"override\":true%'", [$second]) === 1);
    check('with 2 confirmed, the places cannot be set to 1', $fails(fn () => \Ismile\SponsorPackages::update('platinum', ['name_en' => 'Platinum', 'places' => '1'], $owner)));
    check('staff cannot change packages or prices', $fails(fn () => \Ismile\SponsorPackages::create(['name_en' => 'Bronze'], $staff)));
    check('a package that companies chose cannot be deleted', $fails(fn () => \Ismile\SponsorPackages::delete('platinum', $owner)));
    $bronze = \Ismile\SponsorPackages::create(['name_en' => 'Bronze', 'name_ku' => 'برۆنز', 'price' => '1,000,000', 'places' => '4', 'style' => 'tc-bronze'], $owner);
    $site = json_decode((string) file_get_contents(App::siteFile('data/sponsors.json')), true);
    $tierIds = array_column($site['tiers'] ?? [], 'id');
    check('a new package appears on the website at once (without its price)', in_array($bronze, $tierIds, true) && !str_contains((string) json_encode($site['tiers']), 'price') && !in_array('booth-silver', $tierIds, true));
    check('the website file keeps its logos and contact details', array_key_exists('sponsors', $site) && array_key_exists('enquiry', $site));
    \Ismile\SponsorPackages::setStatus($bronze, 'hidden', $owner);
    $site = json_decode((string) file_get_contents(App::siteFile('data/sponsors.json')), true);
    check('a hidden package leaves the website', !in_array($bronze, array_column($site['tiers'], 'id'), true));
    \Ismile\SponsorPackages::delete($bronze, $owner);
    check('an unused package can be deleted', \Ismile\SponsorPackages::find($bronze) === null);
    $sponsorList = Db::one('SELECT * FROM `07_sponsors` WHERE `Company` = ?', ['Test Co']);
    check('the simple Sponsors list shows the package and the money', $sponsorList !== null && $sponsorList['Package'] === 'Platinum' && (int) $sponsorList['Paid (IQD)'] === 5000000);
    check('the simple Calls list shows every call', (int) Db::value("SELECT COUNT(*) FROM `09_sponsor_calls` WHERE `Company` = 'Test Co'") === 3);
    // Put Platinum back as it is on the website, so the test changes nothing there.
    Db::run("UPDATE sponsor_requests SET status = 'declined', amount_paid = NULL, paid_how = NULL, paid_at = NULL WHERE id IN (?, ?)", [$id, $second]);
    \Ismile\SponsorPackages::update('platinum', ['name_en' => 'Platinum', 'name_ar' => 'الماسي', 'name_ku' => 'ئەڵماس', 'subtitle_en' => 'Headline partners', 'subtitle_ar' => 'الشركاء الرئيسيون', 'subtitle_ku' => 'هاوبەشە سەرەکییەکان', 'price' => '0', 'places' => '2', 'style' => 'tc-dia'], $owner);
    $crossSite = http('POST', "$base/api/sponsor.php", json_encode(['company' => 'X']), ['Content-Type: application/json', 'Origin: https://evil.example']);
    check('a form posted from another website is refused', $crossSite['status'] === 403);
    check('the sponsor page needs a sign-in', str_contains((string) http('GET', "$base/admin/sponsors.php")['location'], 'login.php'));

    // ------------------------------------------------------------------
    section('Workshops booked by phone');
    $workshop = \Ismile\SiteData::workshops()[1] ?? null;
    if ($workshop) {
        $staff = ['id' => $adminId, 'role' => 'registration'];
        $owner = ['id' => $adminId, 'role' => 'owner', 'name' => 'Checks'];
        $fails = static function (callable $work): bool {
            try {
                $work();
                return false;
            } catch (UserError) {
                return true;
            }
        };
        $seatsLeftOnWebsite = static function () use ($workshop): ?int {
            foreach (json_decode((string) file_get_contents(App::siteFile('data/workshops.json')), true) as $item) {
                if ($item['id'] === $workshop['id']) {
                    return $item['seatsLeft'];
                }
            }
            return null;
        };
        $person = registrationByRef($ref);                     // registered: paid the event ticket
        $originalPrice = (int) Db::value('SELECT price FROM workshops WHERE id = ?', [$workshop['id']]);
        Db::run('UPDATE workshops SET price = 0 WHERE id = ?', [$workshop['id']]);
        check('staff cannot book a workshop that has no price yet', $fails(fn () => Office::addWorkshop($person, $staff, ['workshop' => $workshop['id']])));
        Db::run('UPDATE workshops SET price = 50000 WHERE id = ?', [$workshop['id']]);
        $workshop = \Ismile\SiteData::workshop($workshop['id']);
        $answer = register(form());
        check('someone who has NOT paid the event ticket cannot even be found to book', Registrations::findByRef((string) $answer['json']['ref']) === null);
        check('"paid" with the wrong amount is refused (must be exact)', $fails(fn () => Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '50000', 'payment_status' => 'paid', 'amount_paid' => '40000', 'paid_how' => 'cash'])));
        check('"paid" without saying how is refused', $fails(fn () => Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '50000', 'payment_status' => 'paid', 'amount_paid' => '50000'])));
        check('only the Owner can give a workshop for free', $fails(fn () => Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '50000', 'payment_status' => 'complimentary'])));

        Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '1', 'payment_status' => 'paid', 'amount_paid' => '50,000', 'paid_how' => 'cash']);
        $booking = Db::one('SELECT * FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$person['id'], $workshop['id']]);
        check('staff cannot change the price (typed 1 IQD, the workshop price was used)', $booking && (int) $booking['price_agreed'] === 50000);
        check('a mistyped huge price is refused, not saved', $fails(fn () => Office::addWorkshop(registrationByRef($ref), $owner, ['workshop' => $workshop['id'], 'price' => '5000025000'])));
        check('a paid caller is booked with the exact amount, how, when and by whom', $booking && $booking['payment_status'] === 'paid' && (int) $booking['amount_paid'] === 50000
            && $booking['paid_how'] === 'cash' && $booking['paid_at'] !== null && (int) $booking['paid_recorded_by'] === $adminId);
        check('adding a workshop lowers "seats left" on the website', $seatsLeftOnWebsite() === (int) $workshop['totalSeats'] - Office::bookedCount($workshop['id']));
        check('the same person cannot be booked twice on one workshop', $fails(fn () => Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '50000'])));
        check('staff cannot undo a paid workshop (no refunds)', $fails(fn () => Office::changeWorkshop((int) $booking['id'], $staff, ['payment_status' => 'unpaid'])));
        check('staff cannot remove a paid workshop (no refunds)', $fails(fn () => Office::changeWorkshop((int) $booking['id'], $staff, ['remove' => '1'])));
        Office::changeWorkshop((int) $booking['id'], $owner, ['remove' => '1']);
        check('the Owner can correct a mistake; the seat is free again', $seatsLeftOnWebsite() === (int) $workshop['totalSeats'] - Office::bookedCount($workshop['id'])
            && Db::value('SELECT removed_at FROM workshop_bookings WHERE id = ?', [$booking['id']]) !== null);

        Office::addWorkshop($person, $staff, ['workshop' => $workshop['id'], 'price' => '', 'payment_status' => 'unpaid']);
        $booked = Db::one('SELECT * FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$person['id'], $workshop['id']]);
        check('an empty price box keeps the workshop\'s own price; booked as NOT paid', (int) $booked['price_agreed'] === (int) ($workshop['price'] ?? 0) && $booked['payment_status'] === 'unpaid');
        Db::run('UPDATE workshop_bookings SET price_agreed = 30000 WHERE id = ?', [$booked['id']]);
        check('marking it paid later needs the exact amount', $fails(fn () => Office::changeWorkshop((int) $booked['id'], $staff, ['payment_status' => 'paid', 'amount_paid' => '3000', 'paid_how' => 'cash'])));
        Office::changeWorkshop((int) $booked['id'], $staff, ['payment_status' => 'paid', 'amount_paid' => '30000', 'paid_how' => 'transfer']);
        check('…and then it is PAID', Db::value('SELECT payment_status FROM workshop_bookings WHERE id = ?', [$booked['id']]) === 'paid');

        $refusesDb = static function (callable $write): bool {
            try {
                $write();
                return false;
            } catch (\PDOException) {
                return true;
            }
        };
        check('the database itself refuses a "paid" workshop with the wrong amount', $refusesDb(fn () => Db::run('UPDATE workshop_bookings SET amount_paid = 1 WHERE id = ?', [$booked['id']])));
        check('the database itself refuses a second active booking of the same person', $refusesDb(fn () => Db::insert('workshop_bookings', ['registration_id' => $person['id'], 'workshop_id' => $workshop['id'], 'created_at' => App::now(), 'updated_at' => App::now()])));

        $seatsBefore = Office::bookedCount($workshop['id']);
        check('staff cannot cancel someone with a PAID workshop (no refunds; Owner only)', $fails(fn () => Office::cancel(Registrations::find((int) $person['id']), $staff, 'Test')));
        Office::cancel(Registrations::find((int) $person['id']), $owner, 'Test: cancelled by the checks');
        check('cancelling a registration frees their workshop seat', Office::bookedCount($workshop['id']) === $seatsBefore - 1);
        Db::run('UPDATE workshops SET price = ? WHERE id = ?', [$originalPrice, $workshop['id']]);
    }

    // ------------------------------------------------------------------
    section('Managing workshops (Owner)');
    $owner = ['id' => $adminId, 'role' => 'owner', 'name' => 'Checks'];
    $staff = ['id' => $adminId, 'role' => 'registration', 'name' => 'Checks'];
    $fails = static function (callable $work): bool {
        try {
            $work();
            return false;
        } catch (UserError) {
            return true;
        }
    };
    $websiteCards = static fn (): array => array_column(json_decode((string) file_get_contents(App::siteFile('data/workshops.json')), true) ?: [], null, 'id');
    check('only the Owner can add a workshop', $fails(fn () => \Ismile\Workshops::create(['title_en' => 'Nope', 'total_seats' => '5'], $staff)));
    $newId = \Ismile\Workshops::create(['title_en' => 'Digital smile design (advanced)', 'title_ku' => 'دیزاینی زەردەخەنە', 'price' => '60,000', 'total_seats' => '2', 'icon' => 'smile'], $owner);
    $cards = $websiteCards();
    check('a new workshop is saved in the database', (\Ismile\Workshops::find($newId)['totalSeats'] ?? 0) === 2 && (\Ismile\Workshops::find($newId)['price'] ?? 0) === 60000);
    check('…and appears on the website at once', isset($cards[$newId]) && $cards[$newId]['seatsLeft'] === 2 && ($cards[$newId]['title']['ku'] ?? '') === 'دیزاینی زەردەخەنە');
    $people = array_values(array_filter(\Ismile\Admin\Lists::rows('registered'), fn ($r) => $r['status'] === 'paid'));
    for ($k = 0; $k < 2; $k++) {
        Office::addWorkshop(Registrations::find((int) $people[$k]['id']), $staff, ['workshop' => $newId, 'payment_status' => 'unpaid']);
    }
    check('the seat limit holds: a third person cannot be booked on 2 seats', $fails(fn () => Office::addWorkshop(Registrations::find((int) $people[2]['id']), $staff, ['workshop' => $newId])));
    check('the seats cannot be lowered below the people booked', $fails(fn () => \Ismile\Workshops::changeSeats($newId, -1, $owner)));
    \Ismile\Workshops::changeSeats($newId, 1, $owner);
    check('+1 seat: now a third person can be booked, and the website shows it', ($websiteCards()[$newId]['totalSeats'] ?? 0) === 3
        && str_contains(Office::addWorkshop(Registrations::find((int) $people[2]['id']), $staff, ['workshop' => $newId]), 'is booked on'));
    check('a workshop people were booked on cannot be deleted (history stays)', $fails(fn () => \Ismile\Workshops::delete($newId, $owner)));
    \Ismile\Workshops::setStatus($newId, 'hidden', $owner);
    check('hidden: gone from the website, and no new bookings', !isset($websiteCards()[$newId])
        && $fails(fn () => Office::addWorkshop(Registrations::find((int) $people[3]['id']), $staff, ['workshop' => $newId])));
    \Ismile\Workshops::setStatus($newId, 'active', $owner);
    $emptyId = \Ismile\Workshops::create(['title_en' => 'Temporary', 'total_seats' => '5'], $owner);
    \Ismile\Workshops::delete($emptyId, $owner);
    check('a workshop nobody booked can be deleted', \Ismile\Workshops::find($emptyId) === null && !isset($websiteCards()[$emptyId]));
    check('the database refuses a booking for a workshop that does not exist', (static function (): bool {
        try {
            Db::insert('workshop_bookings', ['registration_id' => 1, 'workshop_id' => 'no-such-workshop', 'created_at' => App::now(), 'updated_at' => App::now()]);
            return false;
        } catch (\PDOException) {
            return true;
        }
    })());

    // ------------------------------------------------------------------
    section('Name lists');
    $lists = \Ismile\Admin\Lists::counts();
    $registeredRows = \Ismile\Admin\Lists::rows('registered');
    check('"Registered" lists only people who paid (or got a free ticket)', $registeredRows !== [] && count(array_filter($registeredRows, fn ($r) => !in_array($r['status'], ['paid', 'complimentary'], true))) === 0);
    check('forms waiting for payment are on no list', count(array_filter($registeredRows, fn ($r) => Checkouts::findByRef($r['ref']) !== null && Checkouts::findByRef($r['ref'])['status'] === 'open')) === 0);
    foreach ([1, 2] as $day) {
        $lunch = \Ismile\Admin\Lists::rows('lunch' . $day);
        check("lunch day $day list: only registered people who chose day $day", count(array_filter($lunch, fn ($r) => !in_array($r['status'], ['paid', 'complimentary'], true) || (int) $r['lunch_day' . $day] !== 1)) === 0
            && count($lunch) === Registrations::lunchTaken($day));
    }
    $studentRows = \Ismile\Admin\Lists::rows('students');
    check('students list: registered students with their university', $studentRows !== [] && count(array_filter($studentRows, fn ($r) => $r['ticket_type'] !== 'student' || $r['university'] === null)) === 0);
    check('the tab counts match the lists', $lists['registered'] === count($registeredRows) && $lists['students'] === count($studentRows));
    $groups = \Ismile\Admin\Lists::workshopGroups();
    $newGroup = array_values(array_filter($groups, fn ($g) => $g['workshop']['id'] === $newId))[0] ?? null;
    check('Workshops list: each workshop with exactly the people booked on it', $newGroup !== null && count($newGroup['people']) === 3
        && count(array_filter($groups, fn ($g) => count($g['people']) !== \Ismile\Workshops::booked($g['workshop']['id']))) === 0);
    check('Student IDs list: every registered student, with the photo found in the database', count(array_filter(\Ismile\Admin\Lists::rows('studentids'), fn ($r) => $r['id_photo'] === 'stored')) >= 1);
    $sponsorRows = \Ismile\Admin\Lists::rows('sponsors');
    $boothRows = \Ismile\Admin\Lists::rows('exhibition');
    check('Sponsors and Exhibition (booths) are separate lists', count(array_filter($sponsorRows, fn ($r) => $r['kind'] !== 'sponsor')) === 0 && count(array_filter($boothRows, fn ($r) => $r['kind'] !== 'booth')) === 0
        && count($sponsorRows) + count($boothRows) === (int) Db::value('SELECT COUNT(*) FROM sponsor_requests'));
    check('the lists page needs a sign-in', str_contains((string) http('GET', "$base/admin/lists.php")['location'], 'login.php'));

    // ------------------------------------------------------------------
    section('Timed jobs: emails, clean-up, backup');
    $counts = Outbox::process(MailerFactory::make(), 500);
    check('queued emails are sent', $counts['sent'] > 0 && $counts['failed'] === 0, json_encode($counts));
    check('no email is left pending', (int) Db::value("SELECT COUNT(*) FROM emails WHERE status = 'pending' AND next_attempt_at <= NOW()") === 0);
    Db::run('UPDATE checkouts SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $failedForm['id']]);
    exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg((string)php_ini_loaded_file()) . ' ' . escapeshellarg(__DIR__ . '/../cron/run.php') . ' five', $output, $code);
    check('the 5-minute job closes forms not paid in time', $code === 0 && (Checkouts::find((int) $failedForm['id'])['status'] ?? '') === 'expired', implode(' ', $output));
    check('nobody who did not pay gets reminder emails', (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind NOT IN ('ticket','pay_now','alert','sponsor_received','sponsor_notify')") === 0);
    $file = Backup::run();
    check('database backup written', is_file(App::storage('backups/' . $file)) && filesize(App::storage('backups/' . $file)) > 1000);
    $backupText = (string) gzdecode((string) file_get_contents(App::storage('backups/' . $file)));
    check('the backup contains the registrations', str_contains($backupText, 'INSERT INTO `registrations`'));
    check('the backup keeps the views, without the account name', str_contains($backupText, '`01_registered_people`') && !str_contains($backupText, 'DEFINER='));
    check('the backup contains the ID photos (as exact bytes)', str_contains($backupText, 'INSERT INTO `student_id_photos`') && str_contains($backupText, ',0xffd8'));
    preg_match('/INSERT INTO `workshop_bookings` \(([^)]*)\)/', $backupText, $bookingColumns);
    check('the backup can be restored (no calculated columns written)', isset($bookingColumns[1]) && !str_contains($bookingColumns[1], '`active`'));
    $pricesFile = App::siteFile('data/tickets.json');
    $pricesBefore = (string) file_get_contents($pricesFile);
    $runJob = static fn () => shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg((string) php_ini_loaded_file()) . ' ' . escapeshellarg(__DIR__ . '/../cron/run.php') . ' five');
    $runJob();
    $changed = json_decode($pricesBefore, true);
    $changed['professional'] += 1000;
    file_put_contents($pricesFile, json_encode($changed, JSON_PRETTY_PRINT));
    $runJob();
    file_put_contents($pricesFile, $pricesBefore);
    $runJob();
    check('a price change is noticed: Owner alerted and written in the audit log', (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'prices.changed' AND created_at > ?", [date('Y-m-d H:i:s', time() - 120)]) >= 1
        && (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind = 'alert' AND data LIKE '%prices were changed%' AND created_at > ?", [date('Y-m-d H:i:s', time() - 120)]) >= 1);

    // ------------------------------------------------------------------
    section('The ticket email');
    $student = Db::one("SELECT * FROM registrations WHERE ticket_type = 'student' AND status = 'paid' ORDER BY id DESC LIMIT 1");
    Db::run("UPDATE registrations SET lang = 'ku', lunch_day1 = 1, lunch_day2 = 0 WHERE id = ?", [$student['id']]);
    $build = static fn (): ?array => EmailTemplates::build(['kind' => 'ticket', 'registration_id' => $student['id'], 'data' => null, 'to_email' => '', 'checkout_id' => null, 'sponsor_request_id' => null]);
    Settings::set('ticket_qr_in_email', '0');
    $mail = $build();
    check('the ticket email is in English, even for someone who registered in Kurdish', $mail !== null && str_starts_with($mail['subject'], "🎉 You're in!") && str_contains($mail['html'], 'lang="en"'));
    check('it congratulates them by first name', str_contains($mail['html'], 'Congratulations, ' . Security::e($student['first_name'])));
    check('it has their reference, ticket number and amount paid', str_contains($mail['html'], $student['ref']) && str_contains($mail['html'], 'T26-') && (bool) preg_match('/[0-9],[0-9]{3} IQD/', $mail['html']));
    check('it names the lunch day they chose (Day 1 only)', str_contains($mail['html'], '✓ Day 1') && !str_contains($mail['html'], '✓ Day 2'));
    check('students are asked to bring their student ID', str_contains($mail['html'], 'bring your student ID'));
    check('it shows the venue and a map link from the website', str_contains($mail['html'], 'Grand Millennium') && str_contains($mail['html'], 'google.com/maps'));
    check('QR code and PDF are always included, even with the obsolete off setting', count($mail['attachments']) === 1 && str_contains($mail['html'], 'api/qr.php'));
    check('the plain-text version reads "Label: value"', str_contains($mail['text'], 'Your reference: ' . $student['ref']));
    Settings::set('ticket_qr_in_email', '1');
    $mail = $build();
    check('the emailed QR remains included with the legacy setting on', count($mail['attachments']) === 1 && str_contains($mail['html'], 'api/qr.php'));
    check('the email explains two daily admissions', str_contains($mail['text'], 'once on Day 1 and once on Day 2'));
    Settings::set('ticket_qr_in_email', '0');
    try {
        Settings::set('email_language', 'auto');
        check('there is no "email language" setting any more', false);
    } catch (\InvalidArgumentException) {
        check('there is no "email language" setting any more', true);
    }
    // An old value left in a database changes nothing.
    Db::run("INSERT INTO settings (k, v, updated_at) VALUES ('email_language', 'auto', ?) ON DUPLICATE KEY UPDATE v = 'auto'", [App::now()]);
    $mail = $build();
    check('there is no switch for another language: still English, left to right', str_starts_with($mail['subject'], "🎉 You're in!") && str_contains($mail['html'], 'lang="en" dir="ltr"') && !preg_match('/[\x{0600}-\x{06FF}]/u', $mail['subject'] . $mail['text']));
    Db::run("DELETE FROM settings WHERE k = 'email_language'");
    check('the email queue keeps no language column', !Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'emails' AND column_name = 'lang'"));
    try {
        (new \Ismile\Mail\ResendMailer())->send('x@example.com', 'x', 'x', 'x');
        check('Resend refuses to run without its key', (string) App::config('mail.resend_key', '') !== '');
    } catch (\RuntimeException $error) {
        check('Resend refuses to run without its key', str_contains($error->getMessage(), 'not configured'));
    }

    // ------------------------------------------------------------------
    section('The "Close registration" switch (website admin)');
    $ticketsFile = App::siteFile('data/tickets.json');
    $ticketsBefore = (string) file_get_contents($ticketsFile);
    $switched = static function (bool $closed) use ($ticketsFile, $ticketsBefore): void {
        $data = json_decode($ticketsBefore, true);
        $data['registrationClosed'] = $closed;
        file_put_contents($ticketsFile, json_encode($data, JSON_PRETTY_PRINT));
    };
    try {
        Settings::set('registration_open', '1');
        $switched(true);
        check('switched ON: the website says registration is closed', Registrations::publicState('en')['reason'] === 'closed' && !Registrations::isOpen());
        check('switched ON: a form sent anyway is refused', (register(['lang' => 'en'])['json']['error'] ?? '') === 'reg_closed');
        $switched(false);
        check('switched OFF: registration is open again', Registrations::publicState('en')['open'] === true);
    } finally {
        file_put_contents($ticketsFile, $ticketsBefore);
    }

    // ------------------------------------------------------------------
    section('Rate limits');
    $blocked = false;
    for ($i = 0; $i < 70 && !$blocked; $i++) {
        $blocked = register(['terms' => '0'])['status'] === 429;
    }
    check('many form submissions from one address are blocked (429)', $blocked);
} finally {
    foreach (['registration_open', 'ticket_capacity', 'lunch_capacity_day1', 'lunch_capacity_day2', 'email_test_mode', 'ticket_qr_in_email'] as $key) {
        Settings::set($key, $saved[$key]);
    }
    Db::run("DELETE FROM rate_limits WHERE bucket LIKE 'register:%'");
}

echo "\n\033[1m$passed passed, " . count($failed) . " failed\033[0m\n";
foreach ($failed as $name) {
    echo "  FAILED: $name\n";
}
exit($failed ? 1 : 0);
