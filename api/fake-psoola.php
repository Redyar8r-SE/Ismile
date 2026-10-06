<?php
// A PRETEND payment page, standing in for Psoola while we wait for their
// documents. It works only when config.php says payments.gateway = 'fake' and
// never on the live site. The tester chooses what happens, so every situation
// in the plan's checklist can be tried on the test site.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\App;
use Ismile\Payments\FakeGateway;
use Ismile\Payments\Payments;
use Ismile\Security;

if (App::isLive() || App::config('payments.gateway') !== 'fake') {
    http_response_code(404);
    exit('Not found');
}

$id = (string) ($_REQUEST['id'] ?? '');
$state = FakeGateway::load($id);
if ($state === null || !Security::verify('fake:' . $id, (string) ($_REQUEST['s'] ?? ''))) {
    http_response_code(404);
    exit('Unknown payment');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $state['status'] === 'waiting') {
    $choice = (string) ($_POST['choice'] ?? '');
    $sendWebhook = true;
    switch ($choice) {
        case 'pay':
            $state['status'] = 'paid';
            $state['paid'] = $state['amount'];
            break;
        case 'pay-silent':             // paid, but the webhook is "lost": the timed job must find it
            $state['status'] = 'paid';
            $state['paid'] = $state['amount'];
            $sendWebhook = false;
            break;
        case 'pay-less':               // the company confirms a smaller amount: must NOT become paid
            $state['status'] = 'paid';
            $state['paid'] = max(1, intdiv((int) $state['amount'], 10));
            break;
        case 'fail':
            $state['status'] = 'failed';
            break;
        case 'close':                  // visitor closed the page: nothing happens now
            $sendWebhook = false;
            break;
        default:
            http_response_code(400);
            exit('Unknown choice');
    }
    FakeGateway::save($id, $state);
    if ($sendWebhook) {
        // Delivered in-process: the local test server handles one request at a
        // time, so it cannot call itself over HTTP. The code path is the same
        // one webhook.php uses, signature check included.
        $message = FakeGateway::webhookFor($id, $state['status']);
        Payments::handleWebhook('fake', $message['headers'], $message['body']);
    }
    header('Location: ' . $state['return'], true, 303);
    exit;
}

$e = static fn ($text) => Security::e($text);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pretend payment page (testing only)</title>
<style>
  body { margin: 0; font: 16px/1.5 Arial, sans-serif; background: #2a1f5c; color: #12302f; padding: 24px 16px; }
  .card { max-width: 440px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 24px; }
  .warn { background: #fbe1dc; color: #7e2a1c; border-radius: 10px; padding: 10px 14px; font-size: 14px; font-weight: bold; }
  h1 { font-size: 22px; margin: 16px 0 4px; }
  .amount { font-size: 34px; font-weight: bold; margin: 8px 0 18px; }
  button { display: block; width: 100%; margin: 8px 0; padding: 13px; border: 0; border-radius: 10px; font: bold 16px Arial; cursor: pointer; }
  .pay { background: #2e9e5b; color: #fff; } .fail { background: #e0604a; color: #fff; } .other { background: #eef6f6; color: #12302f; }
  dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 14px; } dt { color: #5b7477; }
</style>
</head>
<body>
<div class="card">
  <div class="warn">TEST ONLY: this pretend page stands in for Psoola. No real money moves.</div>
  <h1>Pay iSmile 2026</h1>
  <div class="amount"><?= $e(number_format((int) $state['amount'])) ?> <?= $e($state['currency']) ?></div>
  <dl><dt>Reference</dt><dd><?= $e($state['reference']) ?></dd><dt>Method</dt><dd><?= $e(strtoupper($state['method'])) ?></dd><dt>Payment id</dt><dd><?= $e($id) ?></dd><dt>Status</dt><dd><?= $e($state['status']) ?></dd></dl>
  <?php if ($state['status'] === 'waiting'): ?>
  <form method="post">
    <input type="hidden" name="id" value="<?= $e($id) ?>">
    <input type="hidden" name="s" value="<?= $e($_REQUEST['s']) ?>">
    <button class="pay" name="choice" value="pay">Pay (success)</button>
    <button class="fail" name="choice" value="fail">Payment fails</button>
    <button class="other" name="choice" value="pay-silent">Pay, but the webhook is lost</button>
    <button class="other" name="choice" value="pay-less">Company confirms a smaller amount</button>
    <button class="other" name="choice" value="close">Close the page without paying</button>
  </form>
  <?php else: ?>
  <p>This payment is already <?= $e($state['status']) ?>. <a href="<?= $e($state['return']) ?>">Back to iSmile</a></p>
  <?php endif; ?>
</div>
</body>
</html>
