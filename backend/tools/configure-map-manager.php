<?php
// CLI-only, explicitly selected existing account. No email is printed in logs.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;

$email = strtolower(trim((string) getenv('MAP_EDITOR_EMAIL')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Supply a valid existing account email privately.');
Db::transaction(static function () use ($email): void {
    $user = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL FOR UPDATE', [$email]);
    if (!$user) throw new RuntimeException('The designated account was not found or is disabled.');
    if ($user['role'] === 'map_viewer') throw new RuntimeException('Map viewer accounts cannot receive booking access. Choose a staff role first.');
    $ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
    if (!$ownerId) throw new RuntimeException('An enabled Owner is required.');
    foreach (Db::all('SELECT id FROM admin_users WHERE map_booking_access=1 AND id<>? FOR UPDATE', [$user['id']]) as $previous) {
        Db::update('admin_users', ['map_booking_access' => 0], 'id=?', [$previous['id']]);
        Auth::endOtherSessions((int) $previous['id']);
        Audit::log($ownerId, 'user.map_booking_revoke', 'admin_user', (int) $previous['id']);
    }
    Db::update('admin_users', ['map_booking_access' => 1], 'id=?', [$user['id']]);
    Audit::log($ownerId, 'user.map_booking_grant', 'admin_user', (int) $user['id'], ['exclusive_non_owner' => true]);
});
echo "Designated account configured. Only Owner and the designated account can manage sponsor map bookings.\n";
