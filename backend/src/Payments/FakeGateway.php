<?php
// A pretend payment company, for building and testing before Psoola sends
// its documents. It behaves like a real one: a payment page on its own address
// (api/fake-psoola.php), a signed webhook, and a status we can ask for.
//
// It is refused on the live site (env = live), so it can never take the place
// of real payments by accident.

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;
use Ismile\Security;

final class FakeGateway implements Gateway
{
    public function __construct()
    {
        // Fails closed: refused on the live site, and also on any address that is
        // not a test copy, in case a config.php was copied without changing 'env'.
        $host = strtolower((string) parse_url((string) App::config('site_url'), PHP_URL_HOST));
        $testHost = in_array($host, ['localhost', '127.0.0.1'], true) || str_starts_with($host, 'test.');
        if (App::isLive() || !$testHost) {
            throw new GatewayNotReady('The fake payment gateway runs only on a test site (test.… or localhost).');
        }
    }

    public function name(): string
    {
        return 'fake';
    }

    public function createPayment(array $payment, array $registration): array
    {
        $id = 'FAKE-' . strtoupper(bin2hex(random_bytes(6)));
        self::save($id, [
            'id'        => $id,
            'amount'    => (int) $payment['amount_expected'],
            'currency'  => (string) $payment['currency'],
            'method'    => (string) $payment['method'],
            'reference' => (string) $registration['ref'],
            'status'    => 'waiting',
            'paid'      => null,
            'return'    => (string) $payment['_return_url'],
        ]);
        $redirect = App::url('api/fake-psoola.php?id=' . rawurlencode($id) . '&s=' . Security::sign('fake:' . $id));
        return ['id' => $id, 'redirect_url' => $redirect, 'raw' => json_encode(['id' => $id])];
    }

    public function fetchStatus(string $providerPaymentId): array
    {
        $state = self::load($providerPaymentId);
        if ($state === null) {
            return ['status' => 'failed', 'amount' => null, 'currency' => null, 'raw' => '{"error":"unknown payment"}'];
        }
        return [
            'status'   => $state['status'],
            'amount'   => $state['status'] === 'paid' ? (int) $state['paid'] : null,
            'currency' => $state['currency'],
            'raw'      => json_encode($state),
        ];
    }

    public function readWebhook(array $headers, string $body): ?string
    {
        $signature = (string) ($headers['x-fake-signature'] ?? '');
        $expected = hash_hmac('sha256', $body, (string) App::config('payments.fake_secret', ''));
        if ($signature === '' || !hash_equals($expected, $signature)) {
            return null;
        }
        $data = json_decode($body, true);
        $id = is_array($data) ? (string) ($data['payment_id'] ?? '') : '';
        return $id !== '' ? $id : null;
    }

    // ---- the pretend company's own records ----

    public static function load(string $id): ?array
    {
        if (!preg_match('/^FAKE-[A-F0-9]{12}$/', $id)) {
            return null;
        }
        $file = App::storage('tmp/fake-' . $id . '.json');
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function save(string $id, array $state): void
    {
        file_put_contents(App::storage('tmp/fake-' . $id . '.json'), json_encode($state), LOCK_EX);
    }

    /** The webhook the pretend company sends, signed like a real one. */
    public static function webhookFor(string $id, string $status): array
    {
        $body = json_encode(['payment_id' => $id, 'status' => $status, 'sent_at' => date('c')]);
        return [
            'headers' => ['x-fake-signature' => hash_hmac('sha256', $body, (string) App::config('payments.fake_secret', ''))],
            'body'    => $body,
        ];
    }
}
