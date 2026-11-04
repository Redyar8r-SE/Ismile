<?php
// CLI-only grant to the explicitly selected existing account; no account data in logs.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;

$email = strtolower(trim((string) getenv('REGISTRATION_EDITOR_EMAIL')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Supply the existing account email privately.');
Db::transaction(static function () use ($email): void {
    $user = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL FOR UPDATE', [$email]);
    if (!$user) throw new RuntimeException('The designated account was not found or is disabled.');
    $ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
    if (!$ownerId) throw new RuntimeException('An enabled Owner is required.');
    if ((int) $user['registration_manage_access'] !== 1) {
        Db::update('admin_users', ['registration_manage_access' => 1], 'id=?', [$user['id']]);
        Audit::log($ownerId, 'user.registration_manage_grant', 'admin_user', (int) $user['id']);
    }
    $after = Db::one('SELECT * FROM admin_users WHERE id=?', [$user['id']]);
    $expected = $user; $expected['registration_manage_access'] = $after['registration_manage_access'];
    if ($after !== $expected || !Auth::can($after, 'registrations') || !Auth::can($after, 'edit')
        || !Auth::canIssueComplimentary($after) || !Auth::viewerMayPost($after, '/admin/registration.php') || !Auth::needsTwoFactor($after)) {
        throw new RuntimeException('Registration grant verification failed; no changes saved.');
    }
});
echo "Paid and complimentary registration access granted and verified. Account role and other permissions preserved.\n";
