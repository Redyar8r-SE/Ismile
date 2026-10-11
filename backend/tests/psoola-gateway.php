<?php
// PSOOLA sales-partner gateway checks.
//
//   php backend/tests/psoola-gateway.php
//       No configuration or database: item mapping (ticket + lunch add-ons),
//       PSOOLA's price list and its cache, locale, status mapping, webhook
//       signatures, and the real HTTP calls against a local stand-in for
//       PSOOLA (amount taken from PSOOLA, item check, errors, headers).
//
//   ISMILE_CONFIG=/isolated/config.php php backend/tests/psoola-gateway.php --database
//       Also a full paid registration through the PSOOLA gateway on an
//       isolated ismile_psoola_test_* database: the registration and ticket
//       record are made, the attempt's amount is PSOOLA's total, a different
//       confirmed amount is held, and iSmile queues and sends no ticket email.

declare(strict_types=1);

use Ismile\App;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\EmailTemplates;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;
use Ismile\Payments\GatewayNotReady;
use Ismile\Payments\Payments;
use Ismile\Payments\PsoolaGateway;
use Ismile\Payments\PsoolaPrices;

if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

$database = in_array('--database', $argv, true);
$work = sys_get_temp_dir() . '/ismile-psoola-test-' . bin2hex(random_bytes(4));
mkdir($work . '/site/data', 0700, true);
mkdir($work . '/stub', 0700, true);
register_shutdown_function(static function () use ($work): void {
    exec('rm -rf ' . escapeshellarg($work));
});

// ---- a local stand-in for PSOOLA's API ----
$port = (static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) strrchr((string) $name, ':'), 1);
})();
file_put_contents($work . '/stub/router.php', <<<'PHP'
<?php
$dir = __DIR__;
file_put_contents($dir . '/last.json', json_encode([
    'method'  => $_SERVER['REQUEST_METHOD'],
    'uri'     => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body'    => file_get_contents('php://input'),
]));
if (str_starts_with($_SERVER['REQUEST_URI'], '/api/partner/v1/events/')) {
    file_put_contents($dir . '/event-calls', '.', FILE_APPEND);
}
$routes = json_decode((string) @file_get_contents($dir . '/next.json'), true) ?: [];
$route = $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$next = $routes[$route] ?? $routes['default'] ?? ['status' => 500, 'body' => '{}'];
http_response_code((int) $next['status']);
header('Content-Type: application/json');
echo $next['body'];
PHP);
$stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $work . '/stub/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(static function () use ($stub): void {
    proc_terminate($stub);
});
for ($try = 0; $try < 50 && !@fsockopen('127.0.0.1', $port); $try++) {
    usleep(100_000);
}
// The answer for every request, except the event list ($respondEvent).
$respond = static function (int $status, array|string $body) use ($work): void {
    $routes = json_decode((string) @file_get_contents($work . '/stub/next.json'), true) ?: [];
    $routes['default'] = ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    file_put_contents($work . '/stub/next.json', json_encode($routes));
};
$respondEvent = static function (int $status, array|string $body) use ($work): void {
    $routes = json_decode((string) @file_get_contents($work . '/stub/next.json'), true) ?: [];
    $routes['GET /api/partner/v1/events/evt_ismile'] = ['status' => $status, 'body' => is_string($body) ? $body : json_encode($body)];
    file_put_contents($work . '/stub/next.json', json_encode($routes));
};
$eventCalls = static fn (): int => strlen((string) @file_get_contents($work . '/stub/event-calls'));
$lastRequest = static function () use ($work): array {
    return json_decode((string) file_get_contents($work . '/stub/last.json'), true);
};

// ---- configuration ----
$ticketTypes = ['professional' => 'tt_pro', 'student' => 'tt_student', 'vip' => 'tt_vip'];
$addOns = ['lunchDay1' => 'ao_lunch1', 'lunchDay2' => 'ao_lunch2'];
$psoola = [
    'api_base' => "http://127.0.0.1:$port/", 'api_key' => 'psk_test_key1_secretvalue',
    'webhook_secret' => 'whsec_test_' . bin2hex(random_bytes(8)),
    'event_id' => 'evt_ismile', 'show_id' => 'show_ismile', 'ticket_types' => $ticketTypes, 'add_ons' => $addOns,
];
if ($database) {
    $configFile = getenv('ISMILE_CONFIG') ?: '';
    if ($configFile === '' || !is_file($configFile)) {
        throw new RuntimeException('Database mode needs ISMILE_CONFIG=/isolated/config.php.');
    }
    $config = require $configFile;
    if (($config['env'] ?? '') === 'live' || !str_starts_with((string) ($config['db']['name'] ?? ''), 'ismile_psoola_test_')) {
        throw new RuntimeException('Use an isolated ismile_psoola_test_* database, never live or the local event database.');
    }
    $config['payments'] = ['gateway' => 'psoola', 'psoola' => $psoola] + ($config['payments'] ?? []);
    $config['mail']['driver'] = 'log';
} else {
    $config = [
        'env' => 'test', 'site_url' => 'http://127.0.0.1', 'site_root' => $work . '/site', 'storage' => $work . '/storage',
        'db' => ['name' => 'not-used'], 'secret' => bin2hex(random_bytes(32)),
        'payments' => ['gateway' => 'psoola', 'psoola' => $psoola],
    ];
    file_put_contents($work . '/site/data/tickets.json', json_encode([
        'currency' => 'IQD', 'professional' => 40000, 'student' => 30000, 'vip' => 150000,
        'vipCurrency' => 'IQD', 'lunchCurrency' => 'IQD', 'lunchDay1' => 12000, 'lunchDay2' => 13000,
    ]));
}
App::boot($config);

// PSOOLA's event as GET /api/partner/v1/events/{id} lists it: IQD prices, lunch
// as a per-ticket add-on (42 USD at PSOOLA's rate).
$lunchAddOns = [
    ['id' => 'ao_lunch1', 'name' => 'Lunch – Day 1', 'price' => 55020, 'originalPrice' => ['amount' => 42, 'currency' => 'USD'], 'included' => false, 'available' => 500],
    ['id' => 'ao_lunch2', 'name' => 'Lunch – Day 2', 'price' => 55020, 'originalPrice' => ['amount' => 42, 'currency' => 'USD'], 'included' => false, 'available' => 500],
];
$event = ['id' => 'evt_ismile', 'currency' => 'IQD', 'shows' => [
    ['id' => 'show_other', 'ticketTypes' => [['id' => 'tt_pro', 'price' => 1, 'currency' => 'IQD', 'addOns' => []]]],
    ['id' => 'show_ismile', 'ticketTypes' => [
        ['id' => 'tt_pro', 'name' => 'Professional', 'price' => 40000, 'currency' => 'IQD', 'addOns' => $lunchAddOns],
        ['id' => 'tt_student', 'name' => 'Student', 'price' => 30000, 'currency' => 'IQD', 'addOns' => $lunchAddOns],
        ['id' => 'tt_vip', 'name' => 'VIP', 'price' => 131000, 'currency' => 'IQD', 'addOns' => $lunchAddOns],
        ['id' => 'tt_unrelated', 'name' => 'Other', 'price' => 5, 'currency' => 'IQD', 'addOns' => []],
    ]],
]];
$respondEvent(200, ['event' => $event]);

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException("FAILED: $name");
    }
    $passed++;
    echo "PASS: $name\n";
};
$throws = static function (callable $call, string $class = GatewayNotReady::class, string $contains = ''): bool {
    try {
        $call();
        return false;
    } catch (\Throwable $error) {
        return $error instanceof $class && ($contains === '' || str_contains($error->getMessage(), $contains));
    }
};

// ---------------- item mapping ----------------
$form = static fn (array $over): array => $over + ['ticket_type' => 'professional', 'lunch_day1' => 0, 'lunch_day2' => 0, 'vip_lunch_day' => null];

$item = static fn (array $row, ?array $types = null, ?array $lunches = null): array => PsoolaGateway::items($row, $types ?? $ticketTypes, $lunches ?? $addOns);
$check('professional without lunch is one ticket, no add-ons', $item($form([])) === [['ticketTypeId' => 'tt_pro', 'quantity' => 1]]);
$check('both lunch days are add-ons on the one ticket', $item($form(['lunch_day1' => 1, 'lunch_day2' => 1])) === [['ticketTypeId' => 'tt_pro', 'quantity' => 1, 'addOnIds' => ['ao_lunch1', 'ao_lunch2']]]);
$check('student with day-2 lunch', $item($form(['ticket_type' => 'student', 'lunch_day2' => 1])) === [['ticketTypeId' => 'tt_student', 'quantity' => 1, 'addOnIds' => ['ao_lunch2']]]);
$check("VIP's included lunch day is not sent", $item($form(['ticket_type' => 'vip', 'lunch_day1' => 1, 'vip_lunch_day' => 1])) === [['ticketTypeId' => 'tt_vip', 'quantity' => 1]]);
$check('VIP sends only the extra paid lunch day', $item($form(['ticket_type' => 'vip', 'lunch_day1' => 1, 'lunch_day2' => 1, 'vip_lunch_day' => 2])) === [['ticketTypeId' => 'tt_vip', 'quantity' => 1, 'addOnIds' => ['ao_lunch1']]]);
$check('a missing ticket mapping stops the payment', $throws(fn () => $item($form(['ticket_type' => 'student']), ['student' => ''] + $ticketTypes), GatewayNotReady::class, 'ticket_types.student'));
$check('a missing add-on mapping stops the payment when that lunch is chosen', $throws(fn () => $item($form(['lunch_day2' => 1]), null, ['lunchDay1' => 'ao_lunch1']), GatewayNotReady::class, 'add_ons.lunchDay2'));
$check('an unused add-on mapping is not required', $item($form([]), null, []) === [['ticketTypeId' => 'tt_pro', 'quantity' => 1]]);
$check('an unknown ticket type is refused', $throws(fn () => $item($form(['ticket_type' => 'gold']))));

// What PSOOLA priced must be what was asked for.
$sent = [['ticketTypeId' => 'tt_pro', 'quantity' => 1, 'addOnIds' => ['ao_lunch1', 'ao_lunch2']]];
$ordered = static fn (array $addOnIds, string $type = 'tt_pro', int $quantity = 1): array => [['ticketTypeId' => $type, 'name' => 'Professional', 'quantity' => $quantity, 'unitPrice' => 40000,
    'addOns' => array_map(static fn ($id) => ['addOnId' => $id, 'name' => 'Lunch', 'quantity' => $quantity, 'unitPrice' => 55020], $addOnIds)]];
$check('an order with the same ticket and add-ons matches', PsoolaGateway::sameItems($sent, $ordered(['ao_lunch2', 'ao_lunch1'])));
$check('an order missing a lunch add-on does not match', !PsoolaGateway::sameItems($sent, $ordered(['ao_lunch1'])));
$check('an order with an extra add-on does not match', !PsoolaGateway::sameItems([['ticketTypeId' => 'tt_pro', 'quantity' => 1]], $ordered(['ao_lunch1'])));
$check('an order for another ticket type does not match', !PsoolaGateway::sameItems($sent, $ordered(['ao_lunch1', 'ao_lunch2'], 'tt_vip')));
$check('an order for two tickets does not match', !PsoolaGateway::sameItems($sent, $ordered(['ao_lunch1', 'ao_lunch2'], 'tt_pro', 2)));
$check('a malformed order does not match', !PsoolaGateway::sameItems($sent, 'nope') && !PsoolaGateway::sameItems($sent, [['quantity' => 1]]));

// ---------------- PSOOLA's price list ----------------
$parsed = PsoolaPrices::fromEvent($event, 'show_ismile', $ticketTypes, $addOns);
$check("PSOOLA's IQD prices are read for the configured show only", $parsed === ['currency' => 'IQD',
    'ticketTypes' => ['professional' => 40000, 'student' => 30000, 'vip' => 131000], 'lunch' => ['day1' => 55020, 'day2' => 55020]]);
$check('an unknown show is refused', $throws(fn () => PsoolaPrices::fromEvent($event, 'show_missing', $ticketTypes, $addOns)));
$check('a missing ticket type is refused', $throws(fn () => PsoolaPrices::fromEvent($event, 'show_ismile', ['vip' => 'tt_gone'] + $ticketTypes, $addOns)));
$check('a missing lunch add-on is refused', $throws(fn () => PsoolaPrices::fromEvent($event, 'show_ismile', $ticketTypes, ['lunchDay2' => 'ao_gone'] + $addOns)));
$mixed = $event;
$mixed['shows'][1]['ticketTypes'][2]['currency'] = 'USD';
$check('mixed currencies are refused', $throws(fn () => PsoolaPrices::fromEvent($mixed, 'show_ismile', $ticketTypes, $addOns)));
$check('asSitePrices gives the registration page one currency', PsoolaPrices::asSitePrices($parsed) === ['currency' => 'IQD', 'professional' => 40000, 'student' => 30000,
    'vip' => 131000, 'vipCurrency' => 'IQD', 'lunchCurrency' => 'IQD', 'lunchDay1' => 55020, 'lunchDay2' => 55020]);

$cacheFile = App::storage('tmp/psoola-prices.json');
$age = static function (int $checkedAgo, int $fetchedAgo) use ($cacheFile): void {
    $cache = json_decode((string) file_get_contents($cacheFile), true);
    file_put_contents($cacheFile, json_encode(['checked_at' => $cache['checked_at'] - $checkedAgo, 'fetched_at' => $cache['fetched_at'] - $fetchedAgo] + $cache));
};
@unlink($cacheFile);
$before = $eventCalls();
$check('get() reads the price list from PSOOLA', PsoolaPrices::get() === $parsed && $eventCalls() === $before + 1);
$check('the API key is sent for the price list', ($lastRequest()['headers']['authorization'] ?? '') === 'Bearer psk_test_key1_secretvalue');
$changed = $event;
$changed['shows'][1]['ticketTypes'][0]['price'] = 45000;
$respondEvent(200, ['event' => $changed]);
$check('within five minutes the cached list is used, PSOOLA is not asked', PsoolaPrices::get()['ticketTypes']['professional'] === 40000 && $eventCalls() === $before + 1);
$age(301, 301);
$check('after five minutes the list is read again', PsoolaPrices::get()['ticketTypes']['professional'] === 45000 && $eventCalls() === $before + 2);
$respondEvent(500, 'down');
$age(301, 301);
$check('when PSOOLA is down the last good list is used (up to an hour)', PsoolaPrices::get()['ticketTypes']['professional'] === 45000 && $eventCalls() === $before + 3);
$check('after a failure PSOOLA is not asked again for 30 seconds', PsoolaPrices::get() !== null && $eventCalls() === $before + 3);
$age(31, 3600);
$check('after an hour without PSOOLA there are no PSOOLA prices', PsoolaPrices::get() === null);
$respondEvent(200, ['event' => $event]);
@unlink($cacheFile);

// The provisional amount and the items sent use the same VIP lunch rule.
$addOnPrice = ['ao_lunch1' => 55020, 'ao_lunch2' => 55020];
$agree = true;
foreach (['professional', 'student', 'vip'] as $type) {
    foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$l1, $l2]) {
        foreach ($type === 'vip' ? [1, 2] : [null] as $vipDay) {
            if ($vipDay !== null && ($vipDay === 1 ? !$l1 : !$l2)) {
                continue;
            }
            $row = $form(['ticket_type' => $type, 'lunch_day1' => $l1, 'lunch_day2' => $l2, 'vip_lunch_day' => $vipDay]);
            $sentItem = $item($row)[0];
            $cost = $parsed['ticketTypes'][$type] + array_sum(array_map(static fn ($id) => $addOnPrice[$id], $sentItem['addOnIds'] ?? []));
            $agree = $agree && PsoolaPrices::estimate($row) === ['amount' => $cost, 'currency' => 'IQD'];
        }
    }
}
$check('the provisional amount is what the sent items cost on PSOOLA, for every combination', $agree);
$check('professional with both lunches is 150,040 IQD on PSOOLA', PsoolaPrices::estimate($form(['lunch_day1' => 1, 'lunch_day2' => 1])) === ['amount' => 150040, 'currency' => 'IQD']);

// ---------------- locale and status ----------------
$check("iSmile 'ku' is PSOOLA 'ckb'", PsoolaGateway::locale('ku') === 'ckb');
$check('ar and en pass through, anything else is en', PsoolaGateway::locale('ar') === 'ar' && PsoolaGateway::locale('en') === 'en' && PsoolaGateway::locale('xx') === 'en');
$session = static fn (string $status): array => ['id' => 'ses_1', 'status' => $status, 'order' => ['totalAmount' => 40000, 'currency' => 'iqd', 'ticketCount' => 1]];
foreach (['awaiting_payment' => 'waiting', 'paid' => 'paid', 'expired' => 'expired', 'cancelled' => 'failed', 'refunded' => 'failed'] as $theirs => $ours) {
    $check("status $theirs becomes $ours", PsoolaGateway::mapStatus($session($theirs))['status'] === $ours);
}
$check('an unknown status is asked again, never decided', PsoolaGateway::mapStatus($session('something_new'))['status'] === 'waiting');
$check('amount and currency come from the order', PsoolaGateway::mapStatus($session('paid')) === ['status' => 'paid', 'amount' => 40000, 'currency' => 'IQD']);
$check('a non-integer amount is not trusted', PsoolaGateway::mapStatus(['status' => 'paid', 'order' => ['totalAmount' => '40000', 'currency' => 'IQD']])['amount'] === null);

// ---------------- webhook signatures ----------------
$secret = $psoola['webhook_secret'];
$now = time();
$body = json_encode(['id' => 'evt_1', 'type' => 'order.paid', 'createdAt' => $now * 1000,
    'data' => ['sessionId' => 'ses_abc', 'partnerReference' => 'ISM26-ABC123', 'status' => 'paid', 'totalAmount' => 40000, 'currency' => 'IQD', 'ticketCount' => 1]]);
$sign = static fn (int $t, string $raw, string $key = '') => "t=$t,v1=" . hash_hmac('sha256', "$t.$raw", $key !== '' ? $key : $secret);

$check('a correct signature verifies', PsoolaGateway::signatureValid($sign($now, $body), $body, $secret, $now));
$check('upper-case hex and spaces are accepted', PsoolaGateway::signatureValid('t=' . $now . ', v1=' . strtoupper(hash_hmac('sha256', "$now.$body", $secret)), $body, $secret, $now));
$check('a changed body is refused', !PsoolaGateway::signatureValid($sign($now, $body), str_replace('40000', '1', $body), $secret, $now));
$check('another secret is refused', !PsoolaGateway::signatureValid($sign($now, $body, 'other-secret'), $body, $secret, $now));
$check('a signature 299 s old is accepted', PsoolaGateway::signatureValid($sign($now - 299, $body), $body, $secret, $now));
$check('a signature over 300 s old is refused (replay)', !PsoolaGateway::signatureValid($sign($now - 301, $body), $body, $secret, $now));
$check('a signature over 300 s in the future is refused', !PsoolaGateway::signatureValid($sign($now + 301, $body), $body, $secret, $now));
$check('the timestamp is part of what is signed', !PsoolaGateway::signatureValid('t=' . ($now - 1) . ',v1=' . hash_hmac('sha256', "$now.$body", $secret), $body, $secret, $now));
$check('one valid v1 among several is accepted (secret rotation)', PsoolaGateway::signatureValid("t=$now,v1=" . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', "$now.$body", $secret), $body, $secret, $now));
$check('missing parts are refused', !PsoolaGateway::signatureValid("t=$now", $body, $secret, $now) && !PsoolaGateway::signatureValid('v1=abc', $body, $secret, $now) && !PsoolaGateway::signatureValid('', $body, $secret, $now));
$check('an empty secret never verifies', !PsoolaGateway::signatureValid($sign($now, $body, 'x'), $body, '', $now));

$gateway = new PsoolaGateway();
$check('readWebhook returns the session id (header name in any case)', $gateway->readWebhook(['X-Psoola-Signature' => $sign(time(), $body)], $body) === 'ses_abc');
$check('readWebhook works with the lower-cased headers Payments passes', $gateway->readWebhook(['x-psoola-signature' => $sign(time(), $body)], $body) === 'ses_abc');
$check('readWebhook refuses a bad signature', $gateway->readWebhook(['x-psoola-signature' => $sign(time(), $body, 'forged')], $body) === null);
$check('readWebhook refuses a missing signature', $gateway->readWebhook([], $body) === null);
$noSession = json_encode(['id' => 'evt_2', 'type' => 'order.paid', 'data' => ['status' => 'paid']]);
$check('readWebhook accepts a signed message without a session id as nothing to do', $gateway->readWebhook(['x-psoola-signature' => $sign(time(), $noSession)], $noSession) === '');

// ---------------- creating a checkout session ----------------
$registration = [
    'ref' => 'ISM26-ABC123', 'first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan',
    'email' => 'sara@example.invalid', 'phone' => '+9647501234567', 'lang' => 'ku',
    'ticket_type' => 'professional', 'lunch_day1' => 1, 'lunch_day2' => 0, 'vip_lunch_day' => null,
];
// A provisional amount on the attempt: PSOOLA's total replaces it.
$payment = ['id' => 77, 'amount_expected' => 1, 'currency' => 'IQD', 'method' => null,
    '_return_url' => 'http://127.0.0.1/payment.html?r=ISM26-ABC123&k=key&p=77'];
$created = static fn (int $total, string $currency = 'IQD', int $status = 201, ?array $items = null, string $session = 'ses_new'): array => [$status, [
    'id' => $session, 'status' => 'awaiting_payment', 'checkoutUrl' => "https://psoola.test/checkout/$session.token", 'expiresAt' => (time() + 900) * 1000,
    'order' => ['totalAmount' => $total, 'currency' => $currency, 'items' => $items ?? $ordered(['ao_lunch1'])],
]];

$respond(...$created(95020));
$result = $gateway->createPayment($payment, $registration);
$sent = $lastRequest();
$json = json_decode($sent['body'], true);
$check('a session returns its id and checkout address', $result['id'] === 'ses_new' && $result['redirect_url'] === 'https://psoola.test/checkout/ses_new.token' && json_decode($result['raw'], true)['id'] === 'ses_new');
$check("the amount is PSOOLA's total, not iSmile's figure", $result['amount'] === 95020 && $result['currency'] === 'IQD');
$check('POST to /api/partner/v1/checkout-sessions', $sent['method'] === 'POST' && $sent['uri'] === '/api/partner/v1/checkout-sessions');
$check('the API key is sent as a Bearer token', ($sent['headers']['authorization'] ?? '') === 'Bearer psk_test_key1_secretvalue');
$check('the Idempotency-Key is tied to the payment attempt', ($sent['headers']['idempotency-key'] ?? '') === 'ismile-payment-77');
$check('event, show and one item with the lunch add-on are sent', $json['eventId'] === 'evt_ismile' && $json['showId'] === 'show_ismile'
    && $json['items'] === [['ticketTypeId' => 'tt_pro', 'quantity' => 1, 'addOnIds' => ['ao_lunch1']]]);
$check('the buyer is sent with E.164 phone and full name', $json['buyer'] === ['email' => 'sara@example.invalid', 'phone' => '+9647501234567', 'name' => 'Sara Ahmed Hassan']);
$check('locale, reference and return address are sent', $json['locale'] === 'ckb' && $json['partnerReference'] === 'ISM26-ABC123' && $json['returnUrl'] === $payment['_return_url']);
$respond(...$created(95020, 'IQD', 200));
$check('an idempotent replay (200) is accepted', $gateway->createPayment($payment, $registration)['id'] === 'ses_new');

$respond(...$created(95020, 'IQD', 201, $ordered([])));
$check('a session without the chosen lunch is refused', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'does not contain'));
$respond(...$created(95020, 'IQD', 201, $ordered(['ao_lunch1'], 'tt_vip')));
$check('a session for another ticket type is refused', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'does not contain'));
$respond(201, json_encode(['id' => 'ses_new', 'checkoutUrl' => 'https://psoola.test/c', 'order' => ['totalAmount' => '95020', 'currency' => 'IQD']]));
$check('an amount sent as text is refused', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'valid total'));
$respond(...$created(0));
$check('a zero total is refused', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'valid total'));
$respond(...$created(95020, ''));
$check('a total without a currency is refused', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'valid total'));
$respond(201, json_encode(['id' => 'ses_new', 'order' => ['totalAmount' => 95020, 'currency' => 'IQD']]));
$check('a session without a checkout address is refused', $throws(fn () => $gateway->createPayment($payment, $registration)));
$respond(409, ['error' => ['code' => 'INSUFFICIENT_INVENTORY', 'message' => 'Sold out'], 'requestId' => 'req_9']);
$check('a PSOOLA error is reported with its code and request id', $throws(fn () => $gateway->createPayment($payment, $registration), GatewayNotReady::class, 'HTTP 409 INSUFFICIENT_INVENTORY Sold out (request req_9)'));
$check('a missing event id stops before calling PSOOLA', $throws(function () use ($config, $payment, $registration): void {
    App::boot(['payments' => ['psoola' => ['event_id' => ''] + $config['payments']['psoola']] + $config['payments']] + $config);
    try {
        (new PsoolaGateway())->createPayment($payment, $registration);
    } finally {
        App::boot($config);
    }
}, GatewayNotReady::class, 'event_id'));

// ---------------- asking for the status ----------------
$respond(200, ['id' => 'ses/odd id', 'status' => 'paid', 'partnerReference' => 'ISM26-ABC123', 'paidAt' => time() * 1000, 'order' => ['totalAmount' => 52000, 'currency' => 'IQD', 'ticketCount' => 2]]);
$status = $gateway->fetchStatus('ses/odd id');
$sent = $lastRequest();
$check('GET /api/partner/v1/checkout-sessions/{id}, id encoded', $sent['method'] === 'GET' && $sent['uri'] === '/api/partner/v1/checkout-sessions/ses%2Fodd%20id');
$check('a paid session is paid with its amount', $status['status'] === 'paid' && $status['amount'] === 52000 && $status['currency'] === 'IQD' && $status['raw'] !== '');
$respond(200, ['id' => 'ses_other', 'status' => 'paid', 'order' => ['totalAmount' => 52000, 'currency' => 'IQD']]);
$check('an answer about another session is not believed', $throws(fn () => $gateway->fetchStatus('ses_mine')));
$respond(500, 'oops');
$check('an error decides nothing (asked again later)', $throws(fn () => $gateway->fetchStatus('ses_mine')));
$respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'No session'], 'requestId' => 'req_4']);
$check('an unknown session decides nothing either', $throws(fn () => $gateway->fetchStatus('ses_mine'), GatewayNotReady::class, 'NOT_FOUND'));

// ---------------- a full paid registration (isolated database) ----------------
if ($database) {
    Settings::set('registration_open', '1');
    Settings::set('ticket_capacity', '0');
    Settings::set('lunch_capacity_day1', '0');
    Settings::set('lunch_capacity_day2', '0');
    $newForm = static fn (array $over = []): array => Checkouts::createFromForm($over + ['first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan',
        'phone' => '+9647' . random_int(100000000, 999999999), 'email' => 'psoola-' . bin2hex(random_bytes(3)) . '@example.invalid', 'city' => 'Sulaimani',
        'gender' => 'female', 'age' => '30', 'specialty' => 'gp', 'lang' => 'ku', 'ticket' => 'professional', 'terms' => '1'], null);
    @unlink(App::storage('tmp/psoola-prices.json'));
    $state = Registrations::publicState('en');
    $check('the registration page is given PSOOLA prices in IQD', $state['prices']['vip'] === 131000 && $state['prices']['vipCurrency'] === 'IQD'
        && $state['prices']['lunchCurrency'] === 'IQD' && $state['prices']['lunchDay1'] === 55020);

    // Professional with both lunches: iSmile's own quote mixes IQD and USD.
    $checkout = $newForm(['lunch_day1' => '1', 'lunch_day2' => '1']);
    $check("iSmile's own quote cannot be charged (mixed currencies)", SiteData::quoteFor($checkout)['amount'] === null);
    $respond(...$created(150040, 'IQD', 201, $ordered(['ao_lunch1', 'ao_lunch2'])));
    $started = Payments::start($checkout);
    $row = Db::one('SELECT * FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$checkout['id']]);
    $check('Payments::start sends the visitor to PSOOLA anyway', $started['redirect'] === 'https://psoola.test/checkout/ses_new.token' && $row['gateway'] === 'psoola' && $row['status'] === 'waiting');
    $check("the attempt's amount is PSOOLA's total in IQD", (int) $row['amount_expected'] === 150040 && $row['currency'] === 'IQD');
    $check('the attempt id is the Idempotency-Key', ($lastRequest()['headers']['idempotency-key'] ?? '') === 'ismile-payment-' . $row['id']);
    $check('a repeated click reuses the session', Payments::start($checkout)['redirect'] === $started['redirect']
        && (int) Db::value('SELECT COUNT(*) FROM payments WHERE checkout_id = ?', [$checkout['id']]) === 1);

    $respond(200, ['id' => 'ses_new', 'status' => 'paid', 'partnerReference' => $checkout['ref'], 'paidAt' => time() * 1000,
        'order' => ['totalAmount' => 150040, 'currency' => 'IQD', 'ticketCount' => 1]]);
    $webhook = json_encode(['id' => 'evt_9', 'type' => 'order.paid', 'createdAt' => time() * 1000, 'data' => ['sessionId' => 'ses_new', 'partnerReference' => $checkout['ref'], 'status' => 'paid']]);
    $outcome = Payments::handleWebhook('psoola', ['X-Psoola-Signature' => $sign(time(), $webhook)], $webhook);
    $registrationRow = Registrations::findByRef($checkout['ref']);
    $ticket = $registrationRow ? Tickets::forRegistration((int) $registrationRow['id']) : null;
    $check('a signed order.paid webhook for that exact amount registers the person', $outcome['status'] === 200 && $registrationRow !== null && $registrationRow['status'] === 'paid'
        && (int) Db::value('SELECT amount_confirmed FROM payments WHERE id = ?', [$row['id']]) === 150040);
    $check('the ticket record is kept for the admin and check-in', $ticket !== null && Tickets::issuedByPsoola($ticket));
    $check('iSmile queues no ticket email: PSOOLA sends the ticket', (int) Db::value("SELECT COUNT(*) FROM emails WHERE kind = 'ticket' AND registration_id = ?", [$registrationRow['id']]) === 0);
    $check('a ticket email queued later is skipped, not sent', EmailTemplates::build(['kind' => 'ticket', 'data' => null, 'registration_id' => $registrationRow['id']]) === null);
    $check('the admin cannot resend an iSmile ticket for it', $throws(fn () => Office::resendTicket($registrationRow, ['id' => 0]), UserError::class, 'PSOOLA'));

    // A confirmed amount different from PSOOLA's own session total is held.
    $short = $newForm();
    $respond(...$created(40000, 'IQD', 201, [['ticketTypeId' => 'tt_pro', 'quantity' => 1, 'addOns' => []]], 'ses_short'));
    Payments::start($short);
    $shortRow = Db::one('SELECT * FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$short['id']]);
    $respond(200, ['id' => 'ses_short', 'status' => 'paid', 'order' => ['totalAmount' => 39000, 'currency' => 'IQD', 'ticketCount' => 1]]);
    $check('a different confirmed amount is held for Finance, nobody registered', Payments::check($shortRow) === 'mismatch' && Registrations::findByRef($short['ref']) === null);

    // A session PSOOLA prices for the wrong items is never shown.
    $wrong = $newForm(['lunch_day1' => '1']);
    $respond(...$created(40000, 'IQD', 201, [['ticketTypeId' => 'tt_pro', 'quantity' => 1, 'addOns' => []]], 'ses_wrong'));
    $check('a session missing the chosen lunch is not shown to the visitor', Payments::start($wrong) === ['redirect' => null, 'error' => 'pay_start_failed']
        && Db::value('SELECT status FROM payments WHERE checkout_id = ? ORDER BY id DESC LIMIT 1', [$wrong['id']]) === 'failed');
    $bad = Payments::handleWebhook('psoola', ['X-Psoola-Signature' => $sign(time(), $webhook, 'forged')], $webhook);
    $check('a forged webhook is refused', $bad['status'] === 401);
}

echo "$passed checks passed.\n";
