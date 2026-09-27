<?php
// Sign in: email and password, then (Owner and Finance) the 6-digit code
// from the phone app.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Totp;
use Ismile\UserError;

Page::headers();
Auth::startSession();

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
// Only our own admin pages may be the destination after signing in.
// A path on this site only: one leading slash, plain folder names, never
// "//" or a backslash (those would send the browser to another website).
if (!preg_match('#^(/[A-Za-z0-9_-]+)*/admin/[a-z-]+\.php(\?[A-Za-z0-9=&%_.-]*)?$#', $next)) {
    $next = '';
}
$error = '';
$askCode = false;

if (Auth::user() !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    Page::redirect(Page::home(Auth::user()));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Auth::checkCsrf();
        if (($_POST['step'] ?? '') === 'code') {
            $pending = (int) ($_SESSION['pending_uid'] ?? 0);
            if ($pending === 0 || time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) {
                unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
                throw new UserError('That took too long. Sign in again.');
            }
            $user = Db::one('SELECT * FROM admin_users WHERE id = ? AND disabled_at IS NULL', [$pending]);
            // A code already used (even seconds ago) is refused: someone who saw it cannot reuse it.
            $step = $user === null ? null : Totp::matchStep((string) $user['totp_secret'], (string) ($_POST['code'] ?? ''), (int) ($user['totp_last_step'] ?? -1));
            if ($step === null) {
                $askCode = true;
                if ($user) {
                    Db::run('UPDATE admin_users SET failed_logins = failed_logins + 1 WHERE id = ?', [$user['id']]);
                    Audit::log((int) $user['id'], 'login.bad_code', 'admin_user', (int) $user['id']);
                    if ((int) $user['failed_logins'] + 1 >= 5) {
                        Db::run('UPDATE admin_users SET failed_logins = 0, locked_until = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 900), $user['id']]);
                        unset($_SESSION['pending_uid']);
                        $askCode = false;
                        throw new UserError('This account is locked for 15 minutes after too many wrong codes.');
                    }
                }
                throw new UserError('That code is not right. Use the newest code in the app.');
            }
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            Db::update('admin_users', ['totp_last_step' => $step], 'id = ?', [$user['id']]);
            Auth::completeLogin($user);
            Page::redirect($next !== '' ? $next : Page::home($user));
        }

        $user = Auth::checkPassword((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (Auth::needsTwoFactor($user) && (int) $user['totp_enabled'] === 1) {
            $_SESSION['pending_uid'] = (int) $user['id'];
            $_SESSION['pending_at'] = time();
            $askCode = true;
        } else {
            Auth::completeLogin($user);
            Page::redirect($next !== '' ? $next : Page::home($user));
        }
    } catch (UserError $failure) {
        $error = $failure->key;
    }
}

$noAccounts = (int) Db::value('SELECT COUNT(*) FROM admin_users') === 0;
Page::$user = [];
Page::top('Sign in', '');
$e = [Page::class, 'e'];
?>
<div class="card narrow">
  <?php if ($error !== ''): ?><div class="flash err"><?= $e($error) ?></div><?php endif; ?>
  <?php if ($noAccounts): ?>
    <p>No admin account exists yet. On the server, run:<br><code>php ismile-backend/tools/install.php --owner you@example.com "Your Name"</code></p>
  <?php elseif ($askCode): ?>
    <form method="post" class="stack">
      <?= Page::csrfField() ?>
      <input type="hidden" name="step" value="code">
      <input type="hidden" name="next" value="<?= $e($next) ?>">
      <label>6-digit code from your phone app<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" required autofocus></label>
      <button class="btn">Sign in</button>
    </form>
  <?php else: ?>
    <form method="post" class="stack">
      <?= Page::csrfField() ?>
      <input type="hidden" name="next" value="<?= $e($next) ?>">
      <label>Email<input type="email" name="email" autocomplete="username" required autofocus></label>
      <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn">Continue</button>
    </form>
    <p class="muted small">Only sign in at this address. Never sign in from a link in a message.</p>
  <?php endif; ?>
</div>
<?php Page::bottom();
