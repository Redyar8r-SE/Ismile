<?php
// php backend/tests/booth-reservations.php [--database]
// Database mode requires an isolated ismile_booth_test_* database, never live.
declare(strict_types=1);

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Sponsors;
use Ismile\UserError;

$database = in_array('--database', $argv, true);
if ($database) {
    require __DIR__ . '/../bootstrap.php';
    if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_booth_test_')) {
        throw new RuntimeException('Use an isolated ismile_booth_test_* database, never live or the preview database.');
    }
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Ismile\\')) {
            require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        }
    });
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
$check('empty assignment releases the booth', Sponsors::boothNumber(' ') === null);
$check('leading zeros cannot create a second booth 8', Sponsors::boothNumber(' 008 ') === '8');
$check('first and last floor-plan booths are valid', Sponsors::boothNumber('1') === '1' && Sponsors::boothNumber('44') === '44');
foreach (['0', '-1', '45', '8,9', '8.0', 'B12', '<script>', '99999999999999999999'] as $invalid) {
    $check('rejects new assignment ' . $invalid, $rejects(static fn () => Sponsors::boothNumber($invalid)));
}
$check('preserves an unchanged legacy label', Sponsors::boothNumber('B12', 'B12') === 'B12');

if ($database) {
    $ownerId = Auth::createUser('booth-owner-' . bin2hex(random_bytes(4)) . '@test.invalid', 'Booth test owner', 'owner', bin2hex(random_bytes(12)));
    $owner = ['id' => $ownerId, 'role' => 'owner'];
    $make = static function (string $kind, string $company): int {
        return Db::insert('sponsor_requests', [
            'ref' => 'SPN26-' . strtoupper(bin2hex(random_bytes(2))), 'kind' => $kind,
            'company' => $company, 'contact_name' => 'Private contact', 'phone' => '+9647501112233',
            'email' => 'private@test.invalid', 'status' => 'new', 'lang' => 'en',
            'created_at' => App::now(), 'updated_at' => App::now(),
        ]);
    };
    $first = $make('sponsor', 'Private sponsor one');
    $second = $make('sponsor', 'Private sponsor');
    $save = static fn (int $id, string $number) => Sponsors::saveDetails($id, ['booth_number' => $number, 'package_id' => 'silver'], $owner);
    $save($first, '08');
    $check('assignment reserves immediately while request is New', Sponsors::find($first)['status'] === 'new' && Sponsors::bookedBooths() === [8]);
    $check('normalizes the stored booth number', Sponsors::find($first)['booth_number'] === '8');
    $public = json_encode(['ok' => true, 'booked' => Sponsors::bookedBooths()]);
    $check('public state contains only booked numbers', $public === '{"ok":true,"booked":[8]}');
    $check('two sponsors cannot occupy the same map position', $rejects(static fn () => $save($second, '8')));
    $check('failed assignment leaves second request unchanged', Sponsors::find($second)['booth_number'] === null);
    $check('database also rejects bypassing staff validation', (static function () use ($second): bool {
        try { Db::run('UPDATE sponsor_requests SET booth_number = ? WHERE id = ?', ['008', $second]); return false; }
        catch (PDOException $error) { return ($error->errorInfo[1] ?? 0) === 1062; }
    })());
    $save($first, '9');
    $check('moving a reservation releases the old booth', Sponsors::bookedBooths() === [9]);
    $save($second, '8');
    $check('released booth can be reserved by another company', Sponsors::bookedBooths() === [8, 9]);
    $save($first, '');
    $check('clearing the number releases immediately', Sponsors::bookedBooths() === [8]);
    Sponsors::changeStatus(Sponsors::find($second), 'waiting_list', $owner);
    $check('waiting list releases the booth while keeping history', Sponsors::bookedBooths() === [] && Sponsors::find($second)['booth_number'] === '8');
    $save($first, '8');
    $check('reactivation cannot steal a booth', $rejects(static fn () => Sponsors::changeStatus(Sponsors::find($second), 'new', $owner)));
    $check('failed reactivation keeps the old status', Sponsors::find($second)['status'] === 'waiting_list');
    $check('an agreed call cannot steal a booth from waiting list', $rejects(static fn () => Sponsors::logCall($second, ['outcome' => 'agreed', 'amount' => '100000'], $owner)));
    $check('failed call rolls back its history', Sponsors::calls($second) === []);
    Sponsors::logCall($first, ['outcome' => 'declined'], $owner);
    $check('declining by phone releases the booth', Sponsors::bookedBooths() === []);
    Sponsors::changeStatus(Sponsors::find($second), 'new', $owner);
    $check('reactivation reserves again if the booth is free', Sponsors::bookedBooths() === [8]);
    Sponsors::changeStatus(Sponsors::find($second), 'declined', $owner);
    $check('declining by status releases the booth', Sponsors::bookedBooths() === []);
    $check('reservation changes are audited', (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'sponsor.details'") >= 5);

    $staffInput = [
        'kind' => 'sponsor', 'package_id' => 'gold', 'company' => 'Office company',
        'contact' => 'Office contact', 'phone' => '0750 111 2233', 'email' => '',
        'booth_number' => '44', 'amount_agreed' => '6000000', 'notes' => 'Booked directly with the office.',
    ];
    $emailCount = (int) Db::value('SELECT COUNT(*) FROM emails');
    $manual = Sponsors::createByStaff($staffInput, $owner);
    $check('staff creates a sponsor booking without a website request', $manual['kind'] === 'sponsor' && $manual['company'] === 'Office company');
    $check('staff booking reserves its booth immediately', Sponsors::bookedBooths() === [44]);
    $check('agreed price is stored without claiming payment', $manual['status'] === 'agreed' && (int) $manual['amount_agreed'] === 6000000 && $manual['amount_paid'] === null);
    $check('email is optional for an office booking', $manual['email'] === '');
    $check('staff booking stores handler, normalized phone and private notes', (int) $manual['assigned_to'] === $ownerId && $manual['phone'] === '+9647501112233' && $manual['notes'] === $staffInput['notes']);
    $before = (int) Db::value('SELECT COUNT(*) FROM sponsor_requests');
    $check('duplicate staff booth booking is rejected', $rejects(static fn () => Sponsors::createByStaff($staffInput, $owner)));
    $check('failed staff booking leaves no orphan company', (int) Db::value('SELECT COUNT(*) FROM sponsor_requests') === $before);
    $check('staff creation is audited against the company', (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'sponsor.office_create' AND target_id = ?", [$manual['id']]) === 1);
    $check('office booking does not enqueue unsolicited emails', (int) Db::value('SELECT COUNT(*) FROM emails') === $emailCount);

    foreach ([
        'wrong package kind' => ['package_id' => 'booth-standard'],
        'wrong tier booth number' => ['package_id' => 'platinum', 'booth_number' => '8'],
        'missing company' => ['company' => ''],
        'invalid phone' => ['phone' => '123'],
        'invalid email' => ['email' => 'invalid'],
        'payment without agreed amount' => ['amount_agreed' => '', 'paid_how' => 'cash'],
        'negative agreed amount' => ['amount_agreed' => '-100'],
        'fractional agreed amount' => ['amount_agreed' => '1.5'],
        'invalid payment method' => ['paid_how' => 'invented'],
    ] as $name => $changes) {
        $check('staff booking rejects ' . $name, $rejects(static fn () => Sponsors::createByStaff(array_replace($staffInput, $changes), $owner)));
    }
    foreach (['content', 'checkin'] as $role) {
        $check($role . ' cannot create staff bookings', $rejects(static fn () => Sponsors::createByStaff($staffInput, ['id' => $ownerId, 'role' => $role])));
    }
    $sponsorInput = array_replace($staffInput, ['kind' => 'sponsor', 'package_id' => 'gold', 'company' => 'Office sponsor', 'booth_number' => '43', 'paid_how' => 'cash']);
    $manualSponsor = Sponsors::createByStaff($sponsorInput, ['id' => $ownerId, 'role' => 'registration']);
    $check('Registration can add a sponsorship with a booth', $manualSponsor['kind'] === 'sponsor' && $manualSponsor['package_id'] === 'gold' && Sponsors::bookedBooths() === [43, 44]);
    $check('full received payment starts at Paid with exact amount and method', $manualSponsor['status'] === 'paid' && (int) $manualSponsor['amount_paid'] === 6000000 && $manualSponsor['paid_how'] === 'cash' && $manualSponsor['paid_at'] !== null);
    $noStand = Sponsors::createByStaff(array_replace($sponsorInput, ['company' => 'Office sponsor without a stand', 'booth_number' => '', 'paid_how' => '', 'amount_agreed' => '']), ['id' => $ownerId, 'role' => 'finance']);
    $check('Finance can add a sponsor without a booth or agreed amount', $noStand['status'] === 'new' && $noStand['booth_number'] === null && $noStand['amount_agreed'] === null);
    $expected = [
        'platinum' => [37,38,39,40,41,42], 'gold' => [1,6,24,30,36,43,44],
        'silver' => [2,3,4,5,7,8,9,10,11,12,13,14,25,26,27,28,31,32,33,34,35],
        'bronze' => [15,16,17,18,19,20,21,22,23,29],
    ];
    foreach ($expected as $tier => $numbers) {
        $check($tier . ' matches the screenshot numbers', Ismile\BoothPlan::numbers($tier) === $numbers);
        foreach ([$tier] as $packageId) {
            $check($packageId . ' has the same tier and number count', Ismile\SponsorPackages::find($packageId)['booth_tier'] === $tier && (int) Ismile\SponsorPackages::find($packageId)['places'] === count($numbers));
        }
    }
    $check('existing booking cannot move to a wrong-tier booth', $rejects(static fn () => Sponsors::saveDetails((int) $manualSponsor['id'], ['package_id' => 'platinum', 'booth_number' => '8'], $owner)));
    $check('failed tier change preserves the original booking', Sponsors::find((int) $manualSponsor['id'])['package_id'] === 'gold' && Sponsors::find((int) $manualSponsor['id'])['booth_number'] === '43');
    $upgradeId = (int) $manualSponsor['id'];
    $registration = ['id' => $ownerId, 'role' => 'registration'];
    $finance = ['id' => $ownerId, 'role' => 'finance'];
    $upgrade = [
        'package_id' => 'platinum', 'booth_number' => '37', 'amount_agreed' => '9000000',
        'company' => 'Updated company', 'contact' => 'Updated contact', 'phone' => '0770 111 2233',
        'email' => 'updated@test.invalid', 'role' => 'Manager', 'city' => 'Erbil',
        'lang' => 'ku', 'website' => 'https://example.test', 'notes' => 'Upgraded from Gold.',
    ];
    Sponsors::saveDetails($upgradeId, $upgrade, $registration);
    $upgraded = Sponsors::find($upgradeId);
    $check('paid Gold upgrades to Platinum and keeps its original money record', $upgraded['package_id'] === 'platinum' && $upgraded['status'] === 'agreed' && (int) $upgraded['amount_agreed'] === 9000000 && (int) $upgraded['amount_paid'] === 6000000 && $upgraded['paid_how'] === 'cash' && $upgraded['paid_at'] === $manualSponsor['paid_at']);
    $check('upgrading releases Gold and reserves the Platinum booth together', Sponsors::bookedBooths() === [37,44]);
    $check('company and contact details are editable', $upgraded['company'] === 'Updated company' && $upgraded['contact_name'] === 'Updated contact' && $upgraded['phone'] === '+9647701112233' && $upgraded['email'] === 'updated@test.invalid' && $upgraded['contact_role'] === 'Manager' && $upgraded['city'] === 'Erbil' && $upgraded['lang'] === 'ku' && $upgraded['website'] === 'https://example.test');
    $editHistory = json_decode(Db::value("SELECT details FROM audit_log WHERE target_id=? AND action='sponsor.details' ORDER BY id DESC LIMIT 1", [$upgradeId]), true);
    $check('edit history keeps the old and new packages and agreed totals', $editHistory['before']['package_id'] === 'gold' && $editHistory['after']['package_id'] === 'platinum' && (int) $editHistory['before']['amount_agreed'] === 6000000 && (int) $editHistory['after']['amount_agreed'] === 9000000);
    foreach (['missing total' => ['amount_agreed' => ''], 'below received money' => ['amount_agreed' => '5000000'], 'negative total' => ['amount_agreed' => '-5'], 'fractional total' => ['amount_agreed' => '1.5'], 'invalid company' => ['company' => ''], 'invalid contact' => ['contact' => ''], 'invalid phone' => ['phone' => '123'], 'invalid email' => ['email' => 'bad'], 'invalid language' => ['lang' => 'bad'], 'missing paid package' => ['package_id' => '', 'booth_number' => '']] as $name => $changes) {
        $check('edit rejects ' . $name, $rejects(static fn () => Sponsors::saveDetails($upgradeId, array_replace($upgrade, $changes), $registration)));
    }
    $check('invalid edits preserve details and earlier payment', Sponsors::find($upgradeId)['company'] === 'Updated company' && (int) Sponsors::find($upgradeId)['amount_paid'] === 6000000 && Sponsors::bookedBooths() === [37,44]);
    $check('upgrade cannot be confirmed before balance is received', $rejects(static fn () => Sponsors::changeStatus(Sponsors::find($upgradeId), 'confirmed', $owner)));
    $check('a call cannot overwrite an existing partial payment agreement', $rejects(static fn () => Sponsors::logCall($upgradeId, ['outcome' => 'agreed', 'amount' => '1'], $registration)));
    $check('staff cannot decline a partially paid upgrade', $rejects(static fn () => Sponsors::changeStatus(Sponsors::find($upgradeId), 'declined', $registration)));
    $check('recording the full new total would double-charge and is rejected', $rejects(static fn () => Sponsors::recordPayment($upgradeId, ['amount_paid' => '9000000', 'paid_how' => 'transfer'], $finance)));
    Sponsors::recordPayment($upgradeId, ['amount_paid' => '3000000', 'paid_how' => 'transfer'], $finance);
    $check('only the remaining balance is added to earlier money', Sponsors::find($upgradeId)['status'] === 'paid' && (int) Sponsors::find($upgradeId)['amount_paid'] === 9000000);
    $check('submitting the same balance twice is rejected', $rejects(static fn () => Sponsors::recordPayment($upgradeId, ['amount_paid' => '3000000', 'paid_how' => 'transfer'], $finance)));
    Sponsors::undoPayment($upgradeId, $owner);
    $check('undoing the upgrade payment restores the original Gold payment', Sponsors::find($upgradeId)['status'] === 'agreed' && (int) Sponsors::find($upgradeId)['amount_paid'] === 6000000 && Sponsors::find($upgradeId)['paid_how'] === 'cash' && Sponsors::find($upgradeId)['paid_at'] === $manualSponsor['paid_at']);
    Sponsors::recordPayment($upgradeId, ['amount_paid' => '3000000', 'paid_how' => 'transfer'], $finance);
    Sponsors::changeStatus(Sponsors::find($upgradeId), 'confirmed', $registration);
    $check('upgraded booking can be confirmed after its balance is received', Sponsors::find($upgradeId)['status'] === 'confirmed');
    Sponsors::saveDetails($upgradeId, array_replace($upgrade, ['booth_number' => '38']), $registration);
    $check('a paid contact or booth edit keeps confirmation and payment', Sponsors::find($upgradeId)['status'] === 'confirmed' && (int) Sponsors::find($upgradeId)['amount_paid'] === 9000000 && Sponsors::bookedBooths() === [38,44]);
    Sponsors::saveDetails($upgradeId, array_replace($upgrade, ['booth_number' => '38', 'amount_agreed' => '10000000']), $finance);
    $check('confirmed upgrade with a higher total waits for additional balance', Sponsors::find($upgradeId)['status'] === 'agreed' && (int) Sponsors::find($upgradeId)['amount_paid'] === 9000000);
    $check('failed occupied booth edit rolls back package, price and contact details', $rejects(static fn () => Sponsors::saveDetails($upgradeId, array_replace($upgrade, ['package_id' => 'gold', 'booth_number' => '44', 'amount_agreed' => '11000000', 'company' => 'Should roll back']), $owner)) && Sponsors::find($upgradeId)['package_id'] === 'platinum' && (int) Sponsors::find($upgradeId)['amount_agreed'] === 10000000 && Sponsors::find($upgradeId)['company'] === 'Updated company');
    foreach (['content', 'checkin'] as $role) {
        $check($role . ' cannot edit bookings', $rejects(static fn () => Sponsors::saveDetails($upgradeId, $upgrade, ['id' => $ownerId, 'role' => $role])));
        $check($role . ' cannot record balance payments', $rejects(static fn () => Sponsors::recordPayment($upgradeId, ['amount_paid' => '1000000', 'paid_how' => 'cash'], ['id' => $ownerId, 'role' => $role])));
    }
    Sponsors::saveDetails((int) $manual['id'], ['package_id' => 'platinum', 'booth_number' => '42', 'amount_agreed' => '7000000'], $registration);
    $check('sponsor bookings can change their tier and agreed total', Sponsors::find((int) $manual['id'])['package_id'] === 'platinum' && (int) Sponsors::find((int) $manual['id'])['amount_agreed'] === 7000000 && Sponsors::bookedBooths() === [38,42]);
    Sponsors::changeStatus($manual, 'declined', $owner);
    Sponsors::changeStatus($manualSponsor, 'declined', $owner);

    $standard=Ismile\SponsorPackages::standardBooth(true);
    $check('only Standard booth is offered',count(Ismile\SponsorPackages::all('booth',false))===1 && $standard['name_en']==='Standard booth' && $standard['booth_tier']===null);
    $boothInput=array_replace($staffInput,['kind'=>'booth','company'=>'Standard exhibitor','package_id'=>'booth-platinum','booth_number'=>'']);
    $booth= Sponsors::createByStaff($boothInput,$registration);
    $check('an exhibition booking uses Standard booth without a map position',$booth['package_id']==='booth-standard' && $booth['booth_number']===null && Sponsors::bookedBooths()===[]);
    $check('exhibitors cannot reserve a sponsorship map position',$rejects(static fn()=>Sponsors::createByStaff(array_replace($boothInput,['booth_number'=>'8']),$owner)) && $rejects(static fn()=>Sponsors::saveDetails((int)$booth['id'],['booth_number'=>'8'],$owner)));
    $check('owner cannot create another booth type',$rejects(static fn()=>Ismile\SponsorPackages::create(['kind'=>'booth','name_en'=>'Platinum'],$owner)));
    $check('database also refuses a second active booth type',(static function() use($standard): bool {
        unset($standard['active_booth_type']);$standard['id']='booth-forbidden';
        try{Db::insert('sponsor_packages',$standard);return false;}catch(PDOException){return true;}
    })());
    $cancelled= Sponsors::createByStaff(array_replace($staffInput,['company'=>'Withdrawn sponsor','package_id'=>'silver','booth_number'=>'8','amount_agreed'=>'1000000']),$registration);
    $cancelledId=(int)$cancelled['id'];
    Sponsors::logCall($cancelledId,['outcome'=>'reached','note'=>'They discussed their withdrawal.','next_call_at'=>'2026-10-01T09:00'],$registration);
    $check('cancellation requires a reason',$rejects(static fn()=>Sponsors::cancel($cancelledId,' ',$registration)));
    $check('check-in staff cannot cancel a company booking',$rejects(static fn()=>Sponsors::cancel($cancelledId,'Withdrew',['id'=>$ownerId,'role'=>'checkin'])));
    Sponsors::cancel($cancelledId,'Company withdrew after agreeing the amount.',$registration);
    $withdrawn= Sponsors::find($cancelledId);
    $check('agreed sponsor can be cancelled with staff, time and reason',$withdrawn['status']==='cancelled' && (int)$withdrawn['cancelled_by']===$ownerId && $withdrawn['cancelled_at']!==null && $withdrawn['cancellation_reason']==='Company withdrew after agreeing the amount.');
    $check('cancellation preserves agreed price and call history',(int)$withdrawn['amount_agreed']===1000000 && count(Sponsors::calls($cancelledId))===1);
    $check('cancellation releases map position and clears follow-up calls',Sponsors::bookedBooths()===[] && $withdrawn['reserved_booth']===null && $withdrawn['booth_number']==='8' && $withdrawn['next_call_at']===null);
    Sponsors::cancel($cancelledId,'Second click',$registration);
    $check('duplicate cancellation is harmless and audited once',(int)Db::value("SELECT COUNT(*) FROM audit_log WHERE action='sponsor.cancel' AND target_id=?",[$cancelledId])===1);
    $check('cancelled bookings cannot be paid or silently reactivated by calls',$rejects(static fn()=>Sponsors::recordPayment($cancelledId,['amount_paid'=>'1000000','paid_how'=>'cash'],$owner)) && $rejects(static fn()=>Sponsors::logCall($cancelledId,['outcome'=>'agreed','amount'=>'1000000'],$owner)));
    Sponsors::saveDetails($cancelledId,['package_id'=>'silver','booth_number'=>'8','amount_agreed'=>'1200000','next_call_at'=>'2026-10-01T09:00'],$owner);
    $check('editing details cannot reactivate a cancelled booking',Sponsors::find($cancelledId)['status']==='cancelled' && Sponsors::find($cancelledId)['next_call_at']===null && Sponsors::bookedBooths()===[]);
    $newHolder= Sponsors::createByStaff(array_replace($staffInput,['company'=>'New sponsor holder','package_id'=>'silver','booth_number'=>'8']),$owner);
    Sponsors::changeStatus(Sponsors::find($cancelledId),'new',$registration);
    $check('reopening does not steal a map position already reused',Sponsors::find($cancelledId)['status']==='new' && Sponsors::find($cancelledId)['booth_number']===null && Sponsors::bookedBooths()===[8]);
    Sponsors::cancel((int)$booth['id'],'Exhibitor withdrew',$finance);
    $check('agreed Standard booth bookings can also be cancelled',Sponsors::find((int)$booth['id'])['status']==='cancelled');
    $paid= Sponsors::createByStaff(array_replace($boothInput,['company'=>'Paid exhibitor','paid_how'=>'cash']),$owner);
    $check('only the owner can cancel a booking with money received',$rejects(static fn()=>Sponsors::cancel((int)$paid['id'],'Withdrew',$finance)));
    Sponsors::cancel((int)$paid['id'],'Owner approved cancellation',$owner);
    $check('paid cancellation retains money and payment method',Sponsors::find((int)$paid['id'])['status']==='cancelled' && (int)Sponsors::find((int)$paid['id'])['amount_paid']===6000000 && Sponsors::find((int)$paid['id'])['paid_how']==='cash');
    $check('paid cancellation cannot reopen or remove its money',$rejects(static fn()=>Sponsors::changeStatus(Sponsors::find((int)$paid['id']),'new',$owner)));
}
echo "$passed checks passed.\n";
