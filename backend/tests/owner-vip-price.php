<?php
// VIP $100 (OwnerVipPrice): only the main Owner can sell a VIP ticket for $100.
// The VIP price in PSOOLA is lowered for that one cash sale and always put back.
// Runs against a local stand-in for PSOOLA that keeps the VIP price.
//
//   ISMILE_CONFIG=/isolated/config.php php backend/tests/owner-vip-price.php
//   (an isolated ismile_ownervip_test_* database, already installed)

declare(strict_types=1);

use Ismile\App;
use Ismile\Auth;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Erase;
use Ismile\OwnerVipPrice;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\UserError;
use Ismile\Payments\Payments;

if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../vendor/autoload.php';

$work = sys_get_temp_dir() . '/ismile-ownervip-' . bin2hex(random_bytes(4));
mkdir($work . '/stub', 0700, true);
register_shutdown_function(static function () use ($work): void { exec('rm -rf ' . escapeshellarg($work)); });

// ---- the stand-in for PSOOLA: VIP price, PATCH, cash orders ----
$port = (static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) strrchr((string) $name, ':'), 1);
})();
file_put_contents($work . '/stub/router.php', <<<'PHP'
<?php
$file = __DIR__ . '/state.json';
$s = json_decode((string) file_get_contents($file), true);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
$save = static function () use (&$s, $file): void { file_put_contents($file, json_encode($s)); };
$reply = static function (int $status, array $json): void { http_response_code($status); header('Content-Type: application/json'); echo json_encode($json); };
$lunch = [['id' => 'ao_lunch1', 'price' => 68440, 'originalPrice' => ['amount' => 40, 'currency' => 'USD']], ['id' => 'ao_lunch2', 'price' => 68440, 'originalPrice' => ['amount' => 40, 'currency' => 'USD']]];
$types = [
    ['id' => 'tt_pro', 'name' => 'Professional', 'price' => 40000, 'currency' => 'IQD', 'usdPrice' => null, 'isActive' => true, 'addOns' => $lunch],
    ['id' => 'tt_student', 'name' => 'Student', 'price' => 30000, 'currency' => 'IQD', 'usdPrice' => null, 'isActive' => true, 'addOns' => $lunch],
    ['id' => 'tt_vip', 'name' => 'VIP', 'price' => $s['vip']['price'], 'currency' => 'IQD', 'usdPrice' => $s['vip']['usdPrice'], 'isActive' => true, 'addOns' => $lunch],
];
if ($method === 'GET' && $path === '/api/partner/v1/events/evt_ismile') {
    $reply(200, ['event' => ['id' => 'evt_ismile', 'currency' => 'IQD', 'shows' => [['id' => 'show_ismile', 'ticketTypes' => $types]]]]);
    return;
}
if ($method === 'GET' && preg_match('#^/api/partner/v1/events/([^/]+)$#', $path, $m) && isset($s['workshopEvents'][$m[1]])) {
    $reply(200, ['event' => $s['workshopEvents'][$m[1]]]);
    return;
}
if ($method === 'GET' && $path === '/api/partner/v1/events/evt_ismile/ticket-types') {
    $reply(200, ['ticketTypes' => array_map(static fn ($t) => array_diff_key($t, ['addOns' => 1]), $types)]);
    return;
}
if ($method === 'PATCH' && $path === '/api/partner/v1/events/evt_ismile/ticket-types/tt_vip') {
    $s['patches'][] = $body;
    // As the real PSOOLA (live check 17 Nov 2026): either price or usdPrice, never both;
    // a dollar price sets the dinar price at PSOOLA's VIP rate (1,711 IQD per dollar).
    if (array_key_exists('price', $body) && array_key_exists('usdPrice', $body)) { $save(); $reply(422, ['error' => ['code' => 'INVALID_REQUEST', 'message' => 'Send either price (whole IQD) or usdPrice, not both.']]); return; }
    $newPrice = array_key_exists('usdPrice', $body) ? (int) round($body['usdPrice'] * 1711) : ($body['price'] ?? null);
    $restoring = $newPrice === $s['normal'];
    if ($s['mode'] === 'refuse-lower' && !$restoring || $s['mode'] === 'refuse-restore' && $restoring) { $save(); $reply(400, ['error' => ['code' => 'INVALID_REQUEST', 'message' => 'refused']]); return; }
    if (!($s['mode'] === 'ignore' && !$restoring)) {
        $s['vip'] = array_key_exists('usdPrice', $body) ? ['price' => $newPrice, 'usdPrice' => $body['usdPrice']] : ['price' => $newPrice, 'usdPrice' => null];
    }
    $save();
    $reply(200, ['ticketType' => ['id' => 'tt_vip'] + $s['vip']]);
    return;
}
if ($method === 'POST' && $path === '/api/partner/v1/orders') {
    $total = 0;
    foreach ($body['items'] as $item) {
        foreach ($types as $t) if ($t['id'] === $item['ticketTypeId']) $total += $t['price'];
        foreach ($item['addOnIds'] ?? [] as $addOn) $total += 68440;
    }
    if ($s['mode'] === 'overcharge') $total += 1000;
    $id = 'ord_' . count($s['orders']);
    $order = ['id' => $id, 'status' => 'paid', 'channel' => 'direct', 'partnerReference' => $body['partnerReference'] ?? null, 'paidAt' => time() * 1000,
        'order' => ['totalAmount' => $total, 'currency' => 'IQD', 'ticketCount' => 1, 'items' => $body['items']]];
    $s['orders'][$id] = $order + ['vipPriceAtSale' => $s['vip']['price']];
    $save();
    $reply(201, $order);
    return;
}
if ($method === 'GET' && preg_match('#^/api/partner/v1/checkout-sessions/(ord_\d+)$#', $path, $m) && isset($s['orders'][$m[1]])) {
    $reply(200, array_diff_key($s['orders'][$m[1]], ['vipPriceAtSale' => 1]));
    return;
}
$reply(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found']]);
PHP);
$state = static fn (): array => json_decode((string) file_get_contents($work . '/stub/state.json'), true);
$setState = static function (array $change) use ($work, $state): void {
    file_put_contents($work . '/stub/state.json', json_encode(array_replace($state(), $change)));
};
file_put_contents($work . '/stub/state.json', json_encode(['vip' => ['price' => 342200, 'usdPrice' => 200], 'normal' => 342200, 'mode' => 'ok', 'patches' => [], 'orders' => new stdClass()]));
$nowhere = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $work . '/stub/router.php'], [1 => ['file', $nowhere, 'w'], 2 => ['file', $nowhere, 'w']], $pipes);
register_shutdown_function(static function () use ($stub): void { proc_terminate($stub); });
for ($try = 0; $try < 50 && !@fsockopen('127.0.0.1', $port); $try++) usleep(100_000);

// ---- configuration: an isolated database, PSOOLA = the stand-in ----
$configFile = getenv('ISMILE_CONFIG') ?: '';
if ($configFile === '' || !is_file($configFile)) throw new RuntimeException('Needs ISMILE_CONFIG=/isolated/config.php.');
$config = require $configFile;
if (($config['env'] ?? '') === 'live' || !str_starts_with((string) ($config['db']['name'] ?? ''), 'ismile_ownervip_test_')) {
    throw new RuntimeException('Use an isolated ismile_ownervip_test_* database, never live.');
}
$config['payments'] = ['gateway' => 'psoola', 'psoola' => [
    'api_base' => "http://127.0.0.1:$port/", 'api_key' => 'psk_test_ownervip', 'webhook_secret' => 'whsec_test',
    'event_id' => 'evt_ismile', 'show_id' => 'show_ismile', 'complimentary_enabled' => true,
    'ticket_types' => ['professional' => 'tt_pro', 'student' => 'tt_student', 'vip' => 'tt_vip'],
    'add_ons' => ['lunchDay1' => 'ao_lunch1', 'lunchDay2' => 'ao_lunch2'],
]];
$config['mail']['driver'] = 'log';
$config['alerts_to'] = [];
App::boot($config);
@mkdir(App::storage('tmp'), 0700, true);
@unlink(App::storage('tmp/psoola-prices.json'));
@unlink(App::storage('tmp/owner-vip-price.json'));
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');
Settings::set('vip_registration_limit', '0');

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$refused = static function (callable $work, string $text = ''): bool {
    try { $work(); return false; } catch (UserError $error) { return $text === '' || str_contains($error->key, $text); }
};
$tag = bin2hex(random_bytes(3));
$account = static fn (string $email, string $role): array => Db::one('SELECT * FROM admin_users WHERE id = ?', [Auth::createUser($email, 'Test ' . $role, $role, bin2hex(random_bytes(12)))]);
$main = Db::one('SELECT * FROM admin_users WHERE email = ?', [Erase::MAIN_OWNER]) ?: $account(Erase::MAIN_OWNER, 'owner');
$otherOwner = $account("owner-$tag@test.invalid", 'owner');
$staff = $account("desk-$tag@test.invalid", 'registration');
$viewer = $account("viewer-$tag@test.invalid", 'viewer');
$volunteer = $account("volunteer-$tag@test.invalid", 'volunteer');
$form = static fn (array $more = []): array => $more + ['first_name' => 'Karwan', 'father_name' => 'Ali', 'grandfather_name' => 'Omar',
    'phone' => '0750' . random_int(1000000, 9999999), 'email' => 'vip-' . bin2hex(random_bytes(3)) . '@example.invalid',
    'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'vip100', 'vip_lunch_day' => '1', 'lunch_day1' => '1'];
$vipNow = static fn (): array => $state()['vip'];
$patches = static fn (): int => count($state()['patches']);
$orders = static fn (): int => count($state()['orders']);

// ---- only the main Owner ----
$check('the main Owner may sell VIP $100', OwnerVipPrice::allowed($main));
foreach (['another Owner' => $otherOwner, 'registration staff' => $staff, 'a Viewer' => $viewer, 'a Volunteer' => $volunteer] as $who => $user) {
    $check("$who may not sell VIP \$100", !OwnerVipPrice::allowed($user)
        && $refused(static fn () => OwnerVipPrice::sell($form(), $user), 'Only the main Owner'));
}
$check('a disabled main Owner account may not sell VIP $100', !OwnerVipPrice::allowed(['disabled_at' => '2026-11-17 10:00:00'] + $main));
$check('the main Owner account with another role may not sell VIP $100', !OwnerVipPrice::allowed(['role' => 'registration'] + $main));
$check('a cash form at the $100 price is refused for another Owner', $refused(static fn () => Checkouts::createForCash($form(['ticket' => 'vip']), $otherOwner, null, true, true), 'Only the main Owner'));
$plain = Checkouts::createForCash($form(['ticket' => 'vip']), $staff);
$check('a cash sale at the $100 price is refused for staff', $refused(static fn () => Payments::sellForCash($plain, $staff, ['amount' => 171100, 'currency' => 'IQD']), 'Only the main Owner'));
$check('nobody else changed the PSOOLA price or sold anything', $patches() === 0 && $orders() === 0 && $vipNow()['price'] === 342200);

// ---- the main Owner sells VIP $100 (lunch day 1 included, day 2 extra) ----
$sold = OwnerVipPrice::sell($form(['lunch_day2' => '1']), $main);
$sale = $sold['sale'];
$person = $sale['registration_id'] ? Registrations::find($sale['registration_id']) : null;
$order = $state()['orders']['ord_0'] ?? [];
$check('the guest is registered at once as a VIP, paid by cash', $sale['status'] === 'paid' && $person && $person['ticket_type'] === 'vip' && $person['pay_method'] === 'cash' && $person['status'] === 'paid');
$check('PSOOLA sold the ticket while VIP was $100 (171,100 IQD)', ($order['vipPriceAtSale'] ?? null) === 171100);
$check('the amount is $100 VIP + the extra lunch day only', $sale['amount'] === 171100 + 68440 && $sale['currency'] === 'IQD'
    && (int) Db::value("SELECT amount_confirmed FROM payments WHERE registration_id = ? AND status = 'paid'", [$person['id']]) === 239540);
$check('VIP is back to $200 (342,200 IQD) right after the sale', $sold['restored'] && $vipNow() === ['price' => 342200, 'usdPrice' => 200]);
$check('two price changes only: $100, then back to $200', $state()['patches'] === [['usdPrice' => 100], ['usdPrice' => 200]]);
$check('nothing is left waiting to be put back', !is_file(App::storage('tmp/owner-vip-price.json')));
$check('the sale is in the activity log', (bool) Db::value("SELECT id FROM audit_log WHERE action = 'vip.owner_price_sale' AND user_id = ? AND details LIKE ?", [$main['id'], '%' . $sold['checkout']['ref'] . '%']));
$check('VIP $100 has no included workshop chosen', !Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vip_package_choices'")
    || !Db::value('SELECT id FROM vip_package_choices WHERE checkout_id = ?', [$sold['checkout']['id']]));

// ---- a normal VIP is still $200 ----
$before = $patches();
$normal = Payments::sellForCash(Checkouts::createForCash($form(['ticket' => 'vip']), $staff), $staff);
$check('a normal VIP sale by staff still costs 342,200 IQD and changes no price', $normal['status'] === 'paid' && $normal['amount'] === 342200 && $patches() === $before);

// ---- PSOOLA refuses the lower price: nothing is sold ----
$setState(['mode' => 'refuse-lower']);
$before = $orders();
$check('when PSOOLA refuses $100, nothing is sold', $refused(static fn () => OwnerVipPrice::sell($form(), $main), 'refused the VIP price') && $orders() === $before);
$check('and VIP stays $200', $vipNow()['price'] === 342200 && !is_file(App::storage('tmp/owner-vip-price.json')));

// ---- PSOOLA answers OK but keeps the old price: nothing is sold ----
$setState(['mode' => 'ignore']);
$check('when PSOOLA keeps $200, nothing is sold at $200', $refused(static fn () => OwnerVipPrice::sell($form(), $main), 'did not take') && $orders() === $before && $vipNow()['price'] === 342200);

// ---- PSOOLA charges a different amount: held for review, price put back ----
$setState(['mode' => 'overcharge']);
$check('a different PSOOLA amount is refused and saved for review', $refused(static fn () => OwnerVipPrice::sell($form(), $main), 'different amount')
    && Db::value("SELECT status FROM payments ORDER BY id DESC LIMIT 1") === 'mismatch');
$check('and VIP is back to $200', $vipNow()['price'] === 342200 && !is_file(App::storage('tmp/owner-vip-price.json')));

// ---- VIP is not $200 when the sale starts: refused, nothing touched ----
$setState(['mode' => 'ok', 'vip' => ['price' => 256650, 'usdPrice' => 150], 'normal' => 256650]);
$before = $patches();
$check('when VIP is not $200 in PSOOLA, nothing is changed or sold', $refused(static fn () => OwnerVipPrice::sell($form(), $main), 'not $200') && $patches() === $before);
$setState(['vip' => ['price' => 342200, 'usdPrice' => 200], 'normal' => 342200]);

// ---- a dental student cannot get VIP ----
$check('VIP $100 is not for dental students', $refused(static fn () => OwnerVipPrice::sell($form(['specialty' => 'student']), $main), 'not for dental students'));

// ---- one sale at a time ----
$db = $config['db'];
$other = new PDO('mysql:host=' . ($db['host'] ?? '127.0.0.1') . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['name'], $db['user'], $db['pass']);
$other->query("SELECT GET_LOCK('ismile-owner-vip-price', 0)");
$before = $patches();
$check('a second VIP $100 sale waits for the first one', $refused(static fn () => OwnerVipPrice::sell($form(), $main), 'Another VIP $100 sale') && $patches() === $before);
$check('the minute job leaves a running sale alone', OwnerVipPrice::repair() === 'ok');
$other->query("SELECT RELEASE_LOCK('ismile-owner-vip-price')");

// ---- the price cannot be put back at once: the minute job puts it back ----
$setState(['mode' => 'refuse-restore']);
$stuck = OwnerVipPrice::sell($form(), $main);
$check('the sale is still made when the put-back fails', $stuck['sale']['status'] === 'paid' && $stuck['restored'] === false);
$check('the normal price is remembered while VIP is still $100', $vipNow()['price'] === 171100 && is_file(App::storage('tmp/owner-vip-price.json')));
$setState(['mode' => 'ok']);
$check('the minute job puts VIP back to $200', OwnerVipPrice::repair() === 'restored' && $vipNow() === ['price' => 342200, 'usdPrice' => 200]
    && !is_file(App::storage('tmp/owner-vip-price.json')));
$check('the next minute has nothing to do', OwnerVipPrice::repair() === 'ok');

// ---- VIP package on: VIP $100 has the same included workshop as VIP $200 ----
$events = [];
foreach (json_decode((string) file_get_contents(__DIR__ . '/../assets/real-workshops-2026.json'), true) as $row) {
    if (!in_array($row['id'], \Ismile\VipPackages::WORKSHOPS, true)) continue;
    Db::update('workshops', ['provider_event_id' => $row['provider_event_id'], 'provider_show_id' => $row['provider_show_id'], 'provider_ticket_type_id' => $row['provider_ticket_type_id'],
        'scheduled_date' => $row['scheduled_date'], 'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'status' => 'active'], 'id = ?', [$row['id']]);
    $events[$row['provider_event_id']] = ['id' => $row['provider_event_id'], 'currency' => 'IQD', 'shows' => [['id' => $row['provider_show_id'],
        'dateTime' => strtotime($row['scheduled_date'] . ' ' . $row['start_time'] . ' Asia/Baghdad') * 1000, 'endTime' => strtotime($row['scheduled_date'] . ' ' . $row['end_time'] . ' Asia/Baghdad') * 1000,
        'ticketTypes' => [['id' => $row['provider_ticket_type_id'], 'price' => (int) $row['price'], 'available' => 25]]]]];
}
$setState(['workshopEvents' => $events]);
foreach (\Ismile\VipPackages::WORKSHOPS as $id) @unlink(App::storage('tmp/workshop-catalog-' . $id . '.json'));
@unlink(App::storage('tmp/psoola-prices.json'));
Settings::set('vip_package_enabled', '1');
Settings::set('vip_package_sales_open', '1');
$check('the $200 VIP package is open (test setup)', \Ismile\VipPackages::ready());
$plain200 = Payments::sellForCash(Checkouts::createForCash($form(['ticket' => 'vip']), $staff), $staff);
$check('a VIP $200 cash sale without a workshop is sold at 342,200 IQD (the workshop is optional)', $plain200['status'] === 'paid' && $plain200['amount'] === 342200
    && !Db::value('SELECT v.id FROM vip_package_choices v JOIN checkouts c ON c.id = v.checkout_id WHERE c.registration_id = ?', [$plain200['registration_id']]));
$check('a workshop that is not included is refused for VIP $200', $refused(static fn () => Checkouts::createForCash($form(['ticket' => 'vip', 'vip_workshop' => 'pediatric-dentistry']), $staff), 'vip_workshop_required'));
Settings::set('vip_package_sales_open', '0');
$check('a VIP $200 cash sale without a workshop still needs the VIP package open', $refused(static fn () => Payments::sellForCash(Checkouts::createForCash($form(['ticket' => 'vip']), $staff), $staff), 'vip_package_not_ready'));
Settings::set('vip_package_sales_open', '1');
$before = $patches();
$check('a workshop that is not included is refused for VIP $100 before any price change',
    $refused(static fn () => OwnerVipPrice::sell($form(['vip_workshop' => 'pediatric-dentistry']), $main), 'vip_workshop_required') && $patches() === $before);
$without = OwnerVipPrice::sell($form(), $main);
$check('VIP $100 without a workshop is sold at $100 (the workshop is optional)', $without['sale']['status'] === 'paid' && $without['sale']['amount'] === 171100 && $without['restored']
    && !Db::value('SELECT id FROM vip_package_choices WHERE checkout_id = ?', [$without['checkout']['id']]));
$withWorkshop = OwnerVipPrice::sell($form(['vip_workshop' => 'crown-vs-veneer']), $main);
$choice = Db::one('SELECT * FROM vip_package_choices WHERE checkout_id = ?', [$withWorkshop['checkout']['id']]);
$check('VIP $100 with the package is sold at $100', $withWorkshop['sale']['status'] === 'paid' && $withWorkshop['sale']['amount'] === 171100 && $withWorkshop['restored']);
$check('its included workshop is saved for the registration, as for VIP $200', $choice && $choice['workshop_id'] === 'crown-vs-veneer'
    && (int) $choice['registration_id'] === (int) $withWorkshop['sale']['registration_id'] && in_array($choice['state'], ['pending', 'review', 'issued'], true));
$check('VIP is back to $200 after the package sale', $vipNow() === ['price' => 342200, 'usdPrice' => 200]);
$check('the $200 VIP package still works for staff', Payments::sellForCash(Checkouts::createForCash($form(['ticket' => 'vip', 'vip_workshop' => 'simplified-direct-veneers']), $staff), $staff)['amount'] === 342200);
Settings::set('vip_package_enabled', '0');

// ---- the form preview ----
$check('the form shows VIP $100 as 171,100 IQD', OwnerVipPrice::previewAmount(['ticketTypes' => ['vip' => 342200], 'vipUsd' => 200.0]) === 171100
    && OwnerVipPrice::previewAmount(['ticketTypes' => ['vip' => 342200]]) === 171100);

echo "$passed checks passed\n";
