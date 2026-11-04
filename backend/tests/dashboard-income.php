<?php
// Run only against a fresh isolated ismile_income_test_* database.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Db;
use Ismile\Admin\DashboardIncome;

if (App::isLive() || App::config('payments.gateway') !== 'fake' || !str_starts_with((string) App::config('db.name'), 'ismile_income_test_')) {
    throw new RuntimeException('Use a fresh isolated ismile_income_test_* database with fake payments.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    echo 'PASS: ' . $name . "\n";
    $passed++;
};
$check('empty database has no income', DashboardIncome::totals() === ['tickets'=>[], 'workshops'=>[], 'total'=>[]]);
$guest = static function (string $status = 'paid'): int {
    return Db::insert('registrations', [
        'ref'=>'DI'.strtoupper(bin2hex(random_bytes(5))), 'first_name'=>'Income', 'father_name'=>'Test', 'grandfather_name'=>'Guest',
        'email'=>'income@example.invalid', 'phone'=>'+9647501234567', 'city'=>'Test', 'specialty'=>'gp', 'ticket_type'=>'professional',
        'status'=>$status, 'comp_reason'=>$status === 'complimentary' ? 'Test' : null,
        'view_nonce'=>bin2hex(random_bytes(16)), 'created_at'=>App::now(), 'updated_at'=>App::now(), 'paid_at'=>App::now(),
    ]);
};
$payment = static function (int $guest, int $amount, string $currency = 'IQD', string $status = 'paid', string $method = 'visa'): void {
    Db::insert('payments', ['registration_id'=>$guest, 'gateway'=>'fake', 'method'=>$method, 'amount_expected'=>$amount,
        'amount_confirmed'=>$status === 'paid' ? $amount : null, 'currency'=>$currency, 'status'=>$status,
        'created_at'=>App::now(), 'updated_at'=>App::now()]);
};
$booking = static function (int $guest, int $amount, string $currency = 'IQD', string $status = 'paid', bool $removed = false, string $method = 'cash'): int {
    $workshop = 'income-' . bin2hex(random_bytes(5));
    Db::insert('workshops', ['id'=>$workshop, 'title_en'=>'Income fixture', 'currency'=>$currency, 'price'=>$amount, 'total_seats'=>10,
        'status'=>'hidden', 'created_at'=>App::now(), 'updated_at'=>App::now()]);
    return Db::insert('workshop_bookings', ['registration_id'=>$guest, 'workshop_id'=>$workshop, 'price_agreed'=>$amount,
        'payment_status'=>$status, 'amount_paid'=>$status === 'paid' ? $amount : null, 'paid_how'=>$status === 'paid' ? $method : null,
        'paid_at'=>$status === 'paid' ? App::now() : null, 'removed_at'=>$removed ? App::now() : null,
        'created_at'=>App::now(), 'updated_at'=>App::now()]);
};
$paid = $guest();
$payment($paid, 40000, method:'cash');
$payment($guest(), 30000);
$payment($guest(), 100, 'USD');
$payment($guest('cancelled'), 999999);
foreach (['waiting', 'failed', 'mismatch', 'duplicate'] as $state) $payment($paid, 999999, status:$state);
$booking($paid, 50000);
$online = $booking($paid, 150000, method:'psoola');
$booking($paid, 60, 'USD', method:'transfer');
$booking($paid, 70000, removed:true);
$booking($paid, 90000, status:'unpaid');
$booking($paid, 100000, status:'complimentary');
$booking($guest('cancelled'), 80000);
$guest('complimentary');
$before = [Db::all('SELECT * FROM payments ORDER BY id'), Db::all('SELECT * FROM workshop_bookings ORDER BY id')];
$totals = DashboardIncome::totals();
$check('cash and online ticket payments are included; cancelled and unconfirmed payments are excluded', $totals['tickets'] === ['IQD'=>70000, 'USD'=>100]);
$check('cash, transfer and online workshop payments count once, including hidden workshops', $totals['workshops'] === ['IQD'=>200000, 'USD'=>60]);
$check('unpaid, complimentary, removed and cancelled workshop bookings add no income', $totals['total'] === ['IQD'=>270000, 'USD'=>160]);
$check('reading income preserves payment and booking records', $before === [Db::all('SELECT * FROM payments ORDER BY id'), Db::all('SELECT * FROM workshop_bookings ORDER BY id')]);
Db::update('workshop_bookings', ['removed_at'=>App::now()], 'id=?', [$online]);
$check('refunded online workshop booking leaves the total immediately', DashboardIncome::totals()['total'] === ['IQD'=>120000, 'USD'=>160]);
echo $passed . " dashboard income checks passed.\n";
