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
$active = array_filter($users, static fn (array $row): bool => !$row['disabled_at']);
$secured = count(array_filter($active, static fn (array $row): bool => (bool) $row['totp_enabled']));
$roleGuide = [
    'owner' => ['settings', 'Owner', 'Full access to the event, settings, team and website.'],
    'registration' => ['registrations', 'Registration', 'Guests, student approvals, workshop bookings and lists.'],
    'finance' => ['payments', 'Finance', 'Payments, money checks and financial exports.'],
    'content' => ['content', 'Content', 'Website wording, photos and content.'],
    'checkin' => ['checkin', 'Check-in', 'Find tickets, scan QR codes and welcome guests.'],
];
Page::top('Users', 'users', '<a class="btn" href="#add-person">' . Page::navIcon('users') . '<span>Add a person</span></a>');
?>
<?= Page::stats([
    ['Active accounts', number_format(count($active)), 'People who can sign in', 'users', 'blue'],
    ['Phone code enabled', number_format($secured), 'Active accounts with two-step sign-in', 'security', 'teal'],
    ['Team roles', number_format(count(array_unique(array_column($active, 'role')))), 'Different roles across your active team', 'settings', 'violet'],
    ['Disabled accounts', number_format(count($users) - count($active)), 'History retained for your records', 'close', 'gold'],
]) ?>
<section class="card team-directory">
<div class="panel-top"><?= Page::panelHeading('Your team', 'A personal account for every person helping run your event.', 'users') ?><span class="pill grey"><?= count($users) ?> accounts</span></div>
<div class="table-wrap">
<table>
  <thead><tr><th scope="col">Team member</th><th scope="col">Access & role</th><th scope="col">Sign-in security</th><th scope="col">Last sign-in</th><th scope="col" class="table-action-heading">Account actions</th></tr></thead><tbody>
  <?php foreach ($users as $row): ?>
  <tr class="<?= $row['disabled_at'] ? 'row-grey' : '' ?>">
    <td><div class="team-member"><span class="team-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($row['name'],0,1))) ?></span><div><b><?= $e($row['name']) ?></b><small dir="ltr"><?= $e($row['email']) ?></small><span class="pill <?= $row['disabled_at'] ? 'grey':'green' ?>"><?= $row['disabled_at'] ? 'Disabled' : 'Active' ?></span><?php if ((int)$row['id']===(int)$user['id']): ?><span class="team-you">You</span><?php endif; ?></div></div></td>
    <td><?php if ((int)$row['id']===(int)$user['id']): ?><span class="team-role"><?= Page::navIcon($roleGuide[$row['role']][0]) ?><?= $e($roleGuide[$row['role']][1]) ?></span><small class="field-hint">Your own role stays protected.</small><?php else: ?><form method="post" class="inline-form team-role-form"><?= Page::csrfField() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <select name="role" aria-label="<?= $e('Role for ' . $row['name']) ?>"><?php foreach (Auth::ROLES as $role): ?><option value="<?= $role ?>"<?= $role === $row['role'] ? ' selected' : '' ?>><?= $e($roleGuide[$role][1]) ?></option><?php endforeach; ?></select><button class="btn small ghost">Set role</button></form><?php endif; ?></td>
    <td><span class="security-status <?= (int)$row['totp_enabled'] ? 'enabled':'pending' ?>"><?= Page::navIcon('security') ?><span><?= (int)$row['totp_enabled'] ? 'Phone code on' : 'Phone code off' ?></span></span><small class="field-hint"><?= in_array($row['role'], Auth::NEEDS_TWO_FACTOR, true) ? 'Required for this role' : 'Optional for this role' ?></small></td>
    <td><span class="team-last-login"><?= $row['last_login_at'] ? Page::when($row['last_login_at']) : 'Not signed in yet' ?></span></td>
    <td class="table-action-cell"><div class="row-actions">
      <details class="edit account-menu"><summary class="btn small ghost"><?= Page::navIcon('settings') ?><span>Manage account</span></summary><form method="post" class="stack"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="password"><label>New password<input type="password" name="password" placeholder="12 or more characters" minlength="12" required autocomplete="new-password"></label><div class="form-actions compact"><button class="btn small">Set password</button></div></form>
      <form method="post" class="account-security-action"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="reset2fa"><button class="btn small ghost" data-confirm="Reset this person's phone code?">Reset phone code</button></form>
      <?php if ((int)$row['id']!==(int)$user['id']): ?><div class="row-danger-actions"><?php if ($row['disabled_at']): ?>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="enable"><button class="btn small green">Enable</button></form>
      <?php else: ?>
        <form method="post"><?= Page::csrfField() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="do" value="disable"><button class="btn small red" data-confirm="Disable this account? Their history stays.">Disable</button></form>
      <?php endif; ?></div><?php endif; ?></details>
    </div></td>
  </tr>
  <?php endforeach; ?>
</tbody></table>
</div>
</section>
<div class="team-setup-grid">
<form method="post" class="card stack" id="add-person">
  <?= Page::csrfField() ?><input type="hidden" name="do" value="add">
  <?= Page::panelHeading('Add a person', 'Welcome someone to the team and give them the right access.', 'users') ?>
  <div class="row3">
  <label>Name<input name="name" required></label>
  <label>Email<input type="email" name="email" required></label>
  </div>
  <label>Role<select name="role"><?php foreach (Auth::ROLE_NAMES as $role => $label): ?><option value="<?= $role ?>"><?= $e($label) ?></option><?php endforeach; ?></select></label>
  <label>Initial password<input type="password" name="password" minlength="12" required autocomplete="new-password"><small class="field-hint">At least 12 characters. Share the password with them in person.</small></label>
  <p class="configuration-note">One person, one account. Owner and Finance set up their phone code at first sign-in.</p>
  <div class="form-actions"><button class="btn"><?= Page::navIcon('check') ?><span>Create account</span></button></div>
</form>
<section class="card role-guide"><?= Page::panelHeading('Choose the right role', 'Keep each person’s access matched to their work.', 'security') ?><div class="role-guide-list"><?php foreach ($roleGuide as [$icon,$label,$description]): ?><div><span class="role-guide-icon"><?= Page::navIcon($icon) ?></span><div><b><?= $e($label) ?></b><p><?= $e($description) ?></p></div></div><?php endforeach; ?></div></section>
</div>
<?php Page::bottom();
