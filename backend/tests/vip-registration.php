<?php
// Exercise VIP registration and currency handling against an isolated test DB.
// ISMILE_CONFIG=/test/config.php php backend/tests/vip-registration.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\EmailTemplates;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\UserError;
use Ismile\Payments\FakeGateway;
use Ismile\Payments\Payments;
use Ismile\Admin\RegistrationQuery;

if (App::isLive() || App::config('payments.gateway') !== 'fake'
    || !str_starts_with((string) App::config('db.name'), 'ismile_vip_test_')) {
    throw new RuntimeException('Use an isolated ismile_vip_test_* DB and the fake gateway.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$rejects = static function (array $form): bool {
    try { Checkouts::createFromForm($form, null); return false; }
    catch (UserError) { return true; }
};
Settings::set('registration_open', '1');
Settings::set('ticket_capacity', '0');
Settings::set('lunch_capacity_day1', '0');
Settings::set('lunch_capacity_day2', '0');
$base = ['first_name'=>'Sara','father_name'=>'Ahmed','grandfather_name'=>'Hassan',
    'phone'=>'07501234567','email'=>'vip-test@example.invalid','city'=>'Sulaimani',
    'gender'=>'female','age'=>'28','specialty'=>'gp','lang'=>'ku','ticket'=>'vip',
    'lunch_day1'=>'1','lunch_day2'=>'0','vip_lunch_day'=>'1','pay'=>'visa','terms'=>'1'];

$check('missing VIP lunch day is rejected', $rejects(['vip_lunch_day'=>null]+$base));
$check('out-of-range VIP lunch day is rejected', $rejects(['vip_lunch_day'=>'3']+$base));
$check('included day must have a lunch reservation', $rejects(['vip_lunch_day'=>'2']+$base));
$check('VIP still requires consent', $rejects(['terms'=>'0']+$base));
$check('student ticket still requires university and ID', $rejects(['ticket'=>'student','specialty'=>'student']+$base));

$providerForm = $base;
unset($providerForm['pay']);
$providerCheckout = Checkouts::createFromForm($providerForm, null);
$check('provider may choose the payment method', $providerCheckout['pay_method'] === null);
$providerStarted = Payments::start($providerCheckout);
$providerPayment = Db::one('SELECT * FROM payments WHERE checkout_id=? ORDER BY id DESC LIMIT 1', [$providerCheckout['id']]);
$check('no Visa preset is sent to the provider', $providerStarted['redirect'] !== null && $providerPayment['method'] === null);

foreach ([1,2] as $included) {
    foreach ([false,true] as $extra) {
        $form=['vip_lunch_day'=>(string)$included,'lunch_day1'=>($included===1||$extra)?'1':'0',
            'lunch_day2'=>($included===2||$extra)?'1':'0','amount'=>'1','currency'=>'IQD']+$base;
        $checkout=Checkouts::createFromForm($form,null);
        $quote=SiteData::quoteFor($checkout);
        $expected=$extra?142:100;
        $check("day $included / extra " . (int)$extra . ' keeps VIP and its choice', $checkout['ticket_type']==='vip' && (int)$checkout['vip_lunch_day']===$included);
        $check('server ignores a tampered client price and currency', $quote['amount']===$expected && $quote['currency']==='USD' && SiteData::amountFor($checkout)===$expected);
        $started=Payments::start($checkout);
        $payment=Db::one('SELECT * FROM payments WHERE checkout_id=? ORDER BY id DESC LIMIT 1',[$checkout['id']]);
        $check('provider attempt stores the actual USD amount', $started['redirect']!==null && (int)$payment['amount_expected']===$expected && $payment['currency']==='USD');
        $check('repeated click reuses a matching amount and currency', Payments::start($checkout)['redirect']===$started['redirect']);
        $state=FakeGateway::load($payment['provider_payment_id']);
        FakeGateway::save($payment['provider_payment_id'],array_replace($state,['status'=>'paid','paid'=>$expected]));
        $check('verified payment makes one paid registration', Payments::check($payment)==='paid');
        $registration=Registrations::findByRef($checkout['ref']);
        $check('included day survives checkout to registration', $registration['ticket_type']==='vip' && (int)$registration['vip_lunch_day']===$included);
        $email=EmailTemplates::build(Db::one("SELECT * FROM emails WHERE registration_id=? AND kind='ticket' ORDER BY id DESC LIMIT 1",[$registration['id']]));
        $check('ticket email labels VIP and paid USD correctly', str_contains($email['text'],'VIP') && str_contains($email['text'],"$expected USD") && !str_contains($email['text'],"$expected IQD"));
    }
}
$studentVip=Checkouts::createFromForm(['specialty'=>'student']+$base,null);
$check('a dental student may choose VIP without a student-ID field', $studentVip['ticket_type']==='vip' && $studentVip['id_photo_id']===null);
$office=Checkouts::officeRow(['specialty'=>'student']+$base);
$check('phone registration preserves a student guest choosing VIP', $office['ticket_type']==='vip' && $office['vip_lunch_day']===1);

$standard=Checkouts::createFromForm(['ticket'=>'professional','vip_lunch_day'=>'2']+$base,null);
$quote=SiteData::quoteFor($standard);
$check('standard ticket and USD lunch remain separate', $quote['totals']===['IQD'=>40000,'USD'=>42] && $quote['amount']===null && $quote['currency']===null);
$check('standard tickets cannot claim an included VIP lunch', $standard['vip_lunch_day']===null);
$check('mixed-currency cart is never sent as a converted charge', Payments::start($standard)['redirect']===null && !Db::value('SELECT id FROM payments WHERE checkout_id=?',[$standard['id']]));
$normal=Checkouts::createFromForm(['ticket'=>'professional','lunch_day1'=>'0']+$base,null);
$check('standard ticket with no lunch keeps its original IQD price', SiteData::quoteFor($normal)['totals']===['IQD'=>40000]);

$wrong=Checkouts::createFromForm($base,null);
Payments::start($wrong);
$payment=Db::one('SELECT * FROM payments WHERE checkout_id=? ORDER BY id DESC LIMIT 1',[$wrong['id']]);
$state=FakeGateway::load($payment['provider_payment_id']);
FakeGateway::save($payment['provider_payment_id'],array_replace($state,['status'=>'paid','paid'=>100,'currency'=>'IQD']));
$check('correct number in the wrong currency cannot issue a VIP ticket', Payments::check($payment)==='mismatch' && Registrations::findByRef($wrong['ref'])===null);

try {
    Db::run('UPDATE checkouts SET vip_lunch_day=NULL WHERE id=?',[$wrong['id']]);
    $check('DB rejects a VIP row with no included lunch',false);
} catch (PDOException) { $check('DB rejects a VIP row with no included lunch',true); }
[$where,$params]=RegistrationQuery::where(['type'=>'vip']);
$check('admin VIP filter includes only VIP guests', in_array('vip',$params,true) && str_contains($where,'r.ticket_type = ?'));
$view=Db::one('SELECT * FROM `01_registered_people` WHERE `Reference`=?',[$registration['ref']]);
$check('database export view labels VIP correctly', $view['Ticket']==='VIP');
$paymentView=Db::one('SELECT * FROM `11_payments` WHERE `Reference`=?',[$registration['ref']]);
$check('payment export retains USD instead of labeling it IQD', $paymentView['Currency']==='USD' && (int)$paymentView['Confirmed']===142);
$daily=Db::all('SELECT * FROM `12_money_per_day`');
$dayCurrencies=array_map(static fn(array $row):string=>$row['Day'].':'.$row['Currency'],$daily);
$check('daily finance export separates currencies', count(array_unique($dayCurrencies))===count($daily) && in_array('USD',array_column($daily,'Currency'),true));
echo "$passed VIP checks passed.\n";
