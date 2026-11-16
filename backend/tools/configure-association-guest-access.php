<?php
// CLI-only, run by the deploy: gives the account the Owner chose everything on
// the Association guests page (the same as the Users button "Allow Association
// guests (everything)"). The repository is public, so the account is named
// only by the SHA-256 of its lower-case email, never by the email itself.
//
// Granted once: after the Owner removes the access on the Users page, a later
// deploy does not give it back. The account's role and other access stay as
// they are. Exit 0 = granted, already granted or left alone; 3 = no enabled
// account matches yet (create it on the Users page, then deploy again or
// press the button).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\AssociationGuests;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;

const ACCOUNT_SHA256 = ['249b7812f9b8aef9c6ef1f31d3f34c8f852a0a90b6a94578737d8f28d3fd8d70'];

$found = 0;
foreach (Db::all("SELECT id, email FROM admin_users WHERE disabled_at IS NULL AND email NOT LIKE '%@deleted.invalid'") as $row) {
    if (!in_array(hash('sha256', strtolower(trim((string) $row['email']))), ACCOUNT_SHA256, true)) {
        continue;
    }
    $found++;
    echo Db::transaction(static function () use ($row): string {
        $user = Db::one('SELECT * FROM admin_users WHERE id = ? FOR UPDATE', [$row['id']]);
        if ($user === null || $user['disabled_at'] !== null) {
            return "The account was disabled meanwhile: nothing changed.\n";
        }
        if ($user['role'] === 'owner') {
            return "The account is an Owner and already has everything.\n";
        }
        if ((int) $user['association_guest_access'] === 1) {
            return "Association guests access is already on for the chosen account.\n";
        }
        // The Owner's own choice on the Users page is never overruled.
        if (Db::value("SELECT COUNT(*) FROM audit_log WHERE action IN ('user.association_guest_grant','user.association_guest_revoke') AND target_type = 'admin_user' AND target_id = ?", [$user['id']])) {
            return "The Owner already set this account's Association guests access on the Users page: left as it is.\n";
        }
        $ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role = 'owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
        if (!$ownerId) {
            throw new RuntimeException('An enabled Owner is required.');
        }
        Db::update('admin_users', ['association_guest_access' => 1], 'id = ?', [$user['id']]);
        Audit::log($ownerId, 'user.association_guest_grant', 'admin_user', (int) $user['id'], ['by' => 'deploy']);
        $after = Db::one('SELECT * FROM admin_users WHERE id = ?', [$user['id']]);
        $expected = $user;
        $expected['association_guest_access'] = $after['association_guest_access'];
        if ($after != $expected || !Auth::can($after, 'association-guests') || !AssociationGuests::canAdd($after)
            || !Auth::viewerMayPost($after, '/admin/association-guests.php')) {
            throw new RuntimeException('Association guests grant verification failed; no changes saved.');
        }
        return "Association guests access granted and verified. Account role and other permissions preserved.\n";
    });
}
if ($found === 0) {
    fwrite(STDERR, "No enabled account matches the chosen email yet. Create it on the Users page, then deploy again or press \"Allow Association guests (everything)\".\n");
    exit(3);
}
