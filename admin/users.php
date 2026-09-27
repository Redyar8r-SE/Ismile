<?php
// Users (Owner): who can sign in, with which role. Accounts are disabled, never
// deleted, so their history stays.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;
use Ismile\UserError;

$user = Page::guard('owner');

Page::action(static function () use ($user): string {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'add') {
        $email = strtolower(Page::post('email', 190));
        if (Db::value('SELECT id FROM admin_users WHERE email = ?', [$email])) {
            throw new UserError('An account with that email already exists.');
        }
        $id = Auth::createUser($email, Page::post('name', 120) ?: $email, (string) ($_POST['role'] ?? ''), (string) ($_POST['password'] ?? ''));
        Audit::log((int) $user['id'], 'user.add', 'admin_user', $id, ['email' => $email, 'role' => $_POST['role'] ?? '']);
        return "Account created for $email. Give them the password in person, not in a chat.";
    }
    $target = Db::one('SELECT * FROM admin_users WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($target === null) {
        throw new UserError('Account not found.');
    }
    if ((int) $target['id'] === (int) $user['id'] && in_array($do, ['disable', 'role'], true)) {
        throw new UserError('You cannot disable or change your own account.');
    }
    switch ($do) {
        case 'disable':
            Db::run('UPDATE admin_users SET disabled_at = NOW() WHERE id = ?', [$target['id']]);
            Audit::log((int) $user['id'], 'user.disable', 'admin_user', (int) $target['id']);
            return "{$target['email']} can no longer sign in.";
        case 'enable':
            Db::run('UPDATE admin_users SET disabled_at = NULL, failed_logins = 0, locked_until = NULL WHERE id = ?', [$target['id']]);
            Audit::log((int) $user['id'], 'user.enable', 'admin_user', (int) $target['id']);
            return "{$target['email']} can sign in again.";
        case 'role':
            $role = (string) ($_POST['role'] ?? '');
            if (!in_array($role, Auth::ROLES, true)) {
                throw new UserError('Unknown role.');
            }
            Db::run('UPDATE admin_users SET role = ? WHERE id = ?', [$role, $target['id']]);
            Audit::log((int) $user['id'], 'user.role', 'admin_user', (int) $target['id'], ['from' => $target['role'], 'to' => $role]);
            return "Role changed to $role.";
        case 'password':
            $password = (string) ($_POST['password'] ?? '');
            if (!Auth::validPassword($password)) {
                throw new UserError('The password needs at least 12 characters.');
            }
            Db::run('UPDATE admin_users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $target['id']]);
            Auth::endOtherSessions((int) $target['id']);
            Audit::log((int) $user['id'], 'user.password', 'admin_user', (int) $target['id']);
            return "New password set for {$target['email']}. They are signed out everywhere else.";
        case 'reset2fa':
            Db::run('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = NULL WHERE id = ?', [$target['id']]);
            Auth::endOtherSessions((int) $target['id']);
            Audit::log((int) $user['id'], 'user.reset_2fa', 'admin_user', (int) $target['id']);
            return "Phone code reset for {$target['email']}. They set it up again at their next sign-in.";
    }
    throw new UserError('Unknown action.');
}, 'users.php');

$users = Db::all('SELECT * FROM admin_users ORDER BY disabled_at IS NOT NULL, role, name');
$e = [Page::class, 'e'];
Page::top('Users', 'users');
?>
<div class="card table-wrap">
<table>
  <tr><th>Name</th><th>Email</th><th>Role</th><th>Phone code</th><th>Last sign-in</th><th>Actions</th></tr>
  <?php foreach ($users as $row): ?>
  <tr class="<?= $row['disabled_at'] ? 'row-grey' : '' ?>">
    <td><b><?= $e($row['name']) ?></b><?= $row['disabled_at'] ? ' ' . Page::pill('declined') : '' ?></td>
    <td dir="ltr"><?= $e($row['email']) ?></td>
    <td><form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <select name="role"><?php foreach (Auth::ROLES as $role): ?><option value="<?= $role ?>"<?= $role === $row['role'] ? ' selected' : '' ?>><?= $role ?></option><?php endforeach; ?></select><button class="btn small ghost">Set</button></form></td>
    <td><?= (int) $row['totp_enabled'] ? Page::pill('confirmed') : (in_array($row['role'], Auth::NEEDS_TWO_FACTOR, true) ? Page::pill('pending') . ' <small>needed</small>' : '–') ?></td>
    <td><?= Page::when($row['last_login_at']) ?></td>
    <td class="actions">
      <form method="post" class="inline-form"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="password"><input type="password" name="password" placeholder="New password (12+)" minlength="12" autocomplete="new-password"><button class="btn small ghost">Set password</button></form>
      <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="reset2fa"><button class="btn small ghost" data-confirm="Reset this person's phone code?">Reset phone code</button></form>
      <?php if ($row['disabled_at']): ?>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="enable"><button class="btn small green">Enable</button></form>
      <?php else: ?>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="disable"><button class="btn small red" data-confirm="Disable this account? Their history stays.">Disable</button></form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<form method="post" class="card stack narrow">
  <?= Page::csrfField() ?><input type="hidden" name="do" value="add">
  <h2>Add a person</h2>
  <label>Name<input name="name" required></label>
  <label>Email<input type="email" name="email" required></label>
  <label>Role<select name="role"><?php foreach (Auth::ROLE_NAMES as $role => $label): ?><option value="<?= $role ?>"><?= $e($label) ?></option><?php endforeach; ?></select></label>
  <label>Password (12+ characters; they can be given a new one later)<input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
  <button class="btn">Create account</button>
  <p class="muted small">No shared accounts: one person, one account. Owner and Finance must set up the phone code at their first sign-in.</p>
</form>
<?php Page::bottom();
