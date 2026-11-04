<?php
// Registration delegation checks; optional --database requires an isolated fake-gateway DB.
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\Tickets;
use Ismile\UserError;
use Ismile\Payments\Payments;

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++; echo "PASS: $name\n";
};
$rejects = static function (callable $work): bool {
    try { $work(); return false; } catch (UserError) { return true; }
};
foreach (Auth::ROLES as $role) {
    $normal = ['role' => $role];
    $registrar = $normal + ['registration_manage_access' => 1];
    $check("$role keeps its default free-ticket restriction", Auth::canIssueComplimentary($normal) === ($role === 'owner'));
    $check("$role can register paid and free guests with the individual grant", Auth::can($registrar, 'registrations') && Auth::can($registrar, 'edit') && Auth::canIssueComplimentary($registrar)
        && Auth::viewerMayPost($registrar, '/admin/registration.php') && Auth::viewerMayPost($registrar, '/admin/registrations.php') && Auth::needsTwoFactor($registrar));
    foreach (['dashboard', 'payments', 'association', 'workshops', 'sponsors', 'map', 'checkin', 'export', 'certificates', 'photos', 'content', 'communications'] as $area) {
        if (Auth::can($normal, $area) !== Auth::can($registrar, $area)) throw new RuntimeException('Grant widened ' . $role . ': ' . $area);
    }
    foreach (['users', 'settings', 'map', 'sponsors', 'booths', 'association', 'workshops', 'payments', 'certificates', 'communications', 'content', 'backups'] as $page) {
        if (Auth::viewerMayPost($normal, '/admin/' . $page . '.php') !== Auth::viewerMayPost($registrar, '/admin/' . $page . '.php')) throw new RuntimeException('Grant widened changes on ' . $page);
    }
    $check("$role preserves its other permissions", Auth::canManageMap($normal) === Auth::canManageMap($registrar) && Auth::canManageAssociation($normal) === Auth::canManageAssociation($registrar));
}
foreach ([0, 2, -1] as $flag) {
    $user = ['role' => 'viewer', 'registration_manage_access' => $flag];
    $check('invalid or zero flag does not grant registration: ' . $flag, !Auth::can($user, 'edit') && !Auth::canIssueComplimentary($user) && !Auth::viewerMayPost($user, '/admin/registration.php'));
}

if (in_array('--database', $argv, true)) {
    require __DIR__ . '/../bootstrap.php';
    if (App::isLive() || App::config('env') !== 'test' || App::config('payments.gateway') !== 'fake'
        || App::config('mail.driver') !== 'log' || !str_starts_with((string) App::config('db.name'), 'ismile_cash_test_')) {
        throw new RuntimeException('Use an isolated ismile_cash_test_* database with fake payments and logged email.');
    }
    $tag = bin2hex(random_bytes(5));
    $owner = Db::one('SELECT * FROM admin_users WHERE id=?', [Auth::createUser("owner-$tag@example.invalid", 'Owner test', 'owner', bin2hex(random_bytes(16)))]);
    $manager = Db::one('SELECT * FROM admin_users WHERE id=?', [Auth::createUser("registrar-$tag@example.invalid", 'Guest registrar', 'sponsors', bin2hex(random_bytes(16)))]);
    $otherId = Auth::createUser("other-$tag@example.invalid", 'Other viewer', 'viewer', bin2hex(random_bytes(16)));
    Db::update('admin_users', ['map_booking_access' => 1], 'id=?', [$manager['id']]);
    $before = Db::one('SELECT * FROM admin_users WHERE id=?', [$manager['id']]);
    $other = Db::one('SELECT * FROM admin_users WHERE id=?', [$otherId]);
    $form = ['first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan', 'email' => "free-$tag@example.invalid", 'phone' => '07501234567',
        'city' => 'Sulaimani', 'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Invited guest'];
    $check('ungranted viewer cannot issue a complimentary ticket', $rejects(static fn () => Office::createComplimentary($before, $form)));
    putenv('REGISTRATION_EDITOR_EMAIL=' . $before['email']);
    $grant = static function (): string {
        $command = [PHP_BINARY];
        if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
        array_push($command, __DIR__ . '/../tools/configure-registration-manager.php');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Account grant failed: ' . $error);
        return $output;
    };
    $check('CLI grants and verifies the selected account', str_contains($grant(), 'access granted and verified'));
    $manager = Db::one('SELECT * FROM admin_users WHERE id=?', [$manager['id']]);
    $expected = $before; $expected['registration_manage_access'] = 1;
    $check('grant preserves the account role, map access and security fields', $manager === $expected);
    $check('grant leaves other accounts unchanged', Db::one('SELECT * FROM admin_users WHERE id=?', [$otherId]) === $other);
    $grant();
    $check('repeating the grant does not create duplicate audit entries', (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action='user.registration_manage_grant' AND target_id=?", [$manager['id']]) === 1);
    $free = Office::createComplimentary($manager, $form);
    $check('designated registrar saves a free guest with a ticket and automatic email', $free['status'] === 'complimentary' && (int) $free['created_by'] === (int) $manager['id']
        && Tickets::forRegistration((int) $free['id']) !== null && (bool) Db::value("SELECT id FROM emails WHERE registration_id=? AND kind='ticket' AND status='pending'", [$free['id']]));
    $check('free registrations still require a reason', $rejects(static fn () => Office::createComplimentary($manager, ['comp_reason' => ''] + $form)));
    Settings::set('registration_open', '1');
    $checkout = Checkouts::createForCash(['email' => "paid-$tag@example.invalid", 'phone' => '07501234568'] + $form, $manager);
    $sale = Payments::sellForCash($checkout, $manager);
    $paid = Registrations::find((int) $sale['registration_id']);
    $check('designated registrar can register a paid guest', $sale['status'] === 'paid' && $paid['pay_method'] === 'cash' && (int) $paid['created_by'] === (int) $manager['id']);
    $check('registrar can edit their own guests but cannot cancel them or edit another desk', Office::canChange($manager, $free)
        && !Office::canChange($manager, ['created_by' => $owner['id']]) && $rejects(static fn () => Office::cancel($free, $manager, 'Not allowed')));
    $check('revoking the grant removes free registration access', !Auth::canIssueComplimentary(array_replace($manager, ['registration_manage_access' => 0])));
}
echo "$passed registration access checks passed.\n";
