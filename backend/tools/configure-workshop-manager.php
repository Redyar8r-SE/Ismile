<?php
// CLI grant for one explicitly designated existing account. Never logs account data.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../bootstrap.php';

use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;

$email = strtolower(trim((string)getenv('WORKSHOP_EDITOR_EMAIL')));
if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Supply the existing account email privately.');
Db::transaction(static function () use ($email): void {
    $user = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL FOR UPDATE',[$email]);
    if (!$user) throw new RuntimeException('The designated account was not found or is disabled.');
    $ownerId = (int)Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
    if (!$ownerId) throw new RuntimeException('An enabled Owner is required.');
    if ((int)$user['registration_manage_access'] !== 1 || (int)$user['workshop_booking_access'] !== 1) {
        Db::update('admin_users',['registration_manage_access'=>1,'workshop_booking_access'=>1],'id=?',[$user['id']]);
        Audit::log($ownerId,'user.workshop_guest_manage_grant','admin_user',(int)$user['id']);
    }
    $after = Db::one('SELECT * FROM admin_users WHERE id=?',[$user['id']]);
    $expected = array_replace($user,['registration_manage_access'=>1,'workshop_booking_access'=>1]);
    if ($after !== $expected || !Auth::canBookWorkshops($after) || !Auth::canIssueComplimentary($after)
        || !Auth::can($after,'registrations') || !Auth::can($after,'workshops') || !Auth::needsTwoFactor($after)
        || !Auth::viewerMayPost($after,'registration.php') || !Auth::viewerMayPost($after,'workshops.php')) {
        throw new RuntimeException('Workshop and guest grant verification failed; no changes saved.');
    }
});
echo "Workshop booking for all attendees and guest registration access granted and verified. Role and other permissions preserved.\n";
