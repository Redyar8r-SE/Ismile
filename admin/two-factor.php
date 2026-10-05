<?php
// Setting up the 6-digit phone code. Required for Owner and Finance before
// they can use anything else; optional for the other roles.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Totp;
use Ismile\UserError;

$user = Page::guard('self');

Page::action(static function () use ($user): string {
    $secret = (string) ($_SESSION['totp_setup'] ?? '');
    $step = $secret === '' ? null : Totp::matchStep($secret, Page::post('code', 10));
    if ($step === null) {
        throw new UserError('That code is not right. Scan the picture again or type the key, then enter the newest code.');
    }
    Db::update('admin_users', ['totp_secret' => $secret, 'totp_enabled' => 1, 'totp_last_step' => $step], 'id = ?', [$user['id']]);
    unset($_SESSION['totp_setup']);
    Audit::log((int) $user['id'], 'two_factor.enabled', 'admin_user', (int) $user['id']);
    return 'The phone code is on. From now on you sign in with your password and the code.';
}, 'two-factor.php');

$fresh = Db::one('SELECT totp_enabled FROM admin_users WHERE id = ?', [$user['id']]);
Page::top('Phone code (two-step sign-in)', '');

if ((int) $fresh['totp_enabled'] === 1) {
    echo '<div class="card narrow"><p>' . Page::pill('confirmed') . ' The phone code is on for your account.</p>'
        . '<p class="muted">Lost your phone? Ask the Owner to reset it on the Users page.</p>'
        . '<div class="form-actions"><a class="btn" href="' . Page::home($user) . '">Continue</a></div></div>';
    Page::bottom();
    exit;
}

if (empty($_SESSION['totp_setup'])) {
    $_SESSION['totp_setup'] = Totp::newSecret();
}
$secret = (string) $_SESSION['totp_setup'];
$barcode = new \TCPDF2DBarcode(Totp::uri($secret, $user['email']), 'QRCODE,M');
$png = base64_encode((string) $barcode->getBarcodePngData(6, 6, [0, 0, 0]));
?>
<div class="card narrow">
  <?php if (Auth::needsTwoFactor($user)): ?>
    <div class="flash err">Your role (<?= Page::e($user['role']) ?>) must use a phone code. Set it up to continue.</div>
  <?php endif; ?>
  <ol class="steps">
    <li>Install <b>Google Authenticator</b> or <b>Microsoft Authenticator</b> on your phone.</li>
    <li>In the app, add an account and scan this picture:<br>
      <img class="qr" src="data:image/png;base64,<?= $png ?>" alt="Code for the authenticator app" width="220" height="220"><br>
      <span class="muted small">Or type this key: <code><?= Page::e(trim(chunk_split($secret, 4, ' '))) ?></code></span></li>
    <li>Type the 6-digit code the app shows:</li>
  </ol>
  <form method="post" class="stack">
    <?= Page::csrfField() ?>
    <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus></label>
    <div class="form-actions"><button class="btn">Turn on</button></div>
  </form>
  <p class="muted small">Write the key above on paper and keep it in the sealed envelope in the office: it lets you set up a new phone.</p>
</div>
<?php Page::bottom();
