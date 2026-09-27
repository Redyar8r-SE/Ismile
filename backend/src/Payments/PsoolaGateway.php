<?php
// The real Psoola connection.
//
// Psoola has no public documentation, so the exact addresses, field names and
// webhook signature are NOT known yet. Everything around them is ready: the
// secure HTTP call, timeouts, error handling and logging. When Psoola sends
// their documents, only the three marked blocks below are filled in — nothing
// else in the backend changes, because the rest only talks to Gateway.
//
// Until then every call stops with GatewayNotReady, so a half-configured
// connection can never take money or issue a ticket.

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;

final class PsoolaGateway implements Gateway
{
    private string $base;
    private string $key;
    private string $webhookSecret;

    public function __construct()
    {
        $this->base = rtrim((string) App::config('payments.psoola.api_base', ''), '/');
        $this->key = (string) App::config('payments.psoola.api_key', '');
        $this->webhookSecret = (string) App::config('payments.psoola.webhook_secret', '');
    }

    public function name(): string
    {
        return 'psoola';
    }

    public function createPayment(array $payment, array $registration): array
    {
        $this->ready();
        // ---- TO FILL FROM PSOOLA'S DOCUMENTATION (question 3.1) ----
        // What we will send (names to be matched to theirs):
        //   amount          $payment['amount_expected']   (whole IQD)
        //   currency        $payment['currency']          ('IQD')
        //   our reference   $registration['ref']          (ISM26-XXXXXX)
        //   method          $payment['method']            (visa|mastercard|fib|fastpay, if they accept a preset method: Q 3.4)
        //   customer        $registration['email'], $registration['phone']
        //   return address  $payment['_return_url']
        //   webhook address App::url('api/webhook.php')   (unless registered once in their dashboard: Q 6.3)
        //   language        $registration['lang']         (if their page supports it: Q 3.8)
        // What we need back: their payment id and the payment page address.
        throw new GatewayNotReady('Psoola: createPayment is waiting for the API documentation.');
    }

    public function fetchStatus(string $providerPaymentId): array
    {
        $this->ready();
        // ---- TO FILL (question 4.4) ----
        // Ask Psoola for the payment by id and map their answer to:
        //   'paid' | 'failed' | 'waiting' | 'expired', plus the amount and currency actually paid (Q 4.6).
        throw new GatewayNotReady('Psoola: fetchStatus is waiting for the API documentation.');
    }

    public function readWebhook(array $headers, string $body): ?string
    {
        $this->ready();
        // ---- TO FILL (questions 4.1, 4.2, 4.5) ----
        // Check the signature (algorithm and header name from Psoola) with
        // $this->webhookSecret using hash_equals(), then return their payment id.
        // Return null for anything that does not verify.
        return null;
    }

    private function ready(): void
    {
        if ($this->base === '' || $this->key === '') {
            throw new GatewayNotReady('Psoola is not configured yet (api_base / api_key in config.php).');
        }
    }

    /**
     * A JSON request to Psoola. Kept here so the filled-in methods above stay
     * short. Verifies the TLS certificate, never follows redirects, times out.
     *
     * @return array{status: int, body: string, json: ?array}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $curl = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->key];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            App::log('error', 'Psoola request failed', ['path' => $path, 'error' => $error]);
            throw new GatewayNotReady('Psoola did not answer: ' . $error);
        }
        $json = json_decode((string) $body, true);
        return ['status' => $status, 'body' => (string) $body, 'json' => is_array($json) ? $json : null];
    }
}
