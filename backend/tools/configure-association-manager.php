<?php
// CLI-only grant to an explicitly selected existing account. No account details in logs.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;

$email = strtolower(trim((string) getenv('ASSOCIATION_EDITOR_EMAIL')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Supply the existing account email privately.');
Db::transaction(static function () use ($email): void {
    $user = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL FOR UPDATE', [$email]);
    if (!$user) throw new RuntimeException('The designated account was not found or is disabled.');
    $ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
    if (!$ownerId) throw new RuntimeException('An enabled Owner is required.');
    if ((int) $user['association_manage_access'] !== 1) {
        Db::update('admin_users', ['association_manage_access'=>1], 'id=?', [$user['id']]);
        Audit::log($ownerId, 'user.association_manage_grant', 'admin_user', (int) $user['id']);
    }
    $after = Db::one('SELECT * FROM admin_users WHERE id=?', [$user['id']]);
    $expected = $user; $expected['association_manage_access'] = $after['association_manage_access'];
    if ($after !== $expected || !Auth::canManageAssociation($after)
        || !Auth::can($after, 'association') || !Auth::viewerMayPost($after, '/admin/association.php')) {
        throw new RuntimeException('Association grant verification failed; no changes saved.');
    }
});
echo "Association manager access granted and verified. Account role and other permissions preserved.\n";
