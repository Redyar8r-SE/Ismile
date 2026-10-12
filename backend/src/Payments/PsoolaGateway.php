<?php
// The real Psoola connection: PSOOLA's sales-partner API (v1).
//
//   A  createPayment   POST /api/partner/v1/checkout-sessions
//   C  fetchStatus     GET  /api/partner/v1/checkout-sessions/{id}   (cash orders too: one id space)
//   B  readWebhook     X-Psoola-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, t.body)>
//      createCashSale  POST /api/partner/v1/orders       a cash sale: paid tickets issued at once
//      listOrders      GET  /api/partner/v1/orders       the confirmed sales (card and cash)
//      listCheckIns    GET  /api/partner/v1/check-ins    tickets scanned at PSOOLA's gate
//
// PSOOLA sells the iSmile tickets on its own checkout page and issues and
// emails the ticket itself; iSmile keeps the registration (Payments::apply).
// PSOOLA is the price authority (all IQD; lunch is a PSOOLA add-on priced
// from USD at PSOOLA's rate), so the amount of each attempt is the total
// PSOOLA returns for its session, not iSmile's own quote.
// A half-configured connection stops with GatewayNotReady, so it can never
// take money or register anyone.

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;
use Ismile\Registrations;

final class PsoolaGateway implements Gateway, CashSales
{
    /** A webhook signed longer ago (or further ahead) than this is refused. */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    /** PSOOLA's session states, as the backend's payment states. */
    private const STATUSES = [
        'awaiting_payment' => 'waiting',
        'paid'             => 'paid',
        'expired'          => 'expired',
        'cancelled'        => 'failed',
        'refunded'         => 'failed',
    ];

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
        $body = $this->orderBody($registration) + ['returnUrl' => (string) $payment['_return_url']];
        // One key per attempt: a retried request returns the same session, never a second order.
        $answer = $this->request('POST', 'api/partner/v1/checkout-sessions', $body, ['Idempotency-Key: ismile-payment-' . (int) $payment['id']]);
        if (!in_array($answer['status'], [200, 201], true)) {
            throw new GatewayNotReady('Psoola refused the checkout session: ' . self::describeError($answer));
        }
        $json = $answer['json'] ?? [];
        $id = is_string($json['id'] ?? null) ? $json['id'] : '';
        $url = is_string($json['checkoutUrl'] ?? null) ? $json['checkoutUrl'] : '';
        if ($id === '' || !preg_match('#^https?://#i', $url)) {
            throw new GatewayNotReady('Psoola answered without a session id or checkout address.');
        }
        // PSOOLA sets the price, so the amount is taken from its answer. What
        // it priced must be exactly the ticket and lunches iSmile asked for: a
        // session for anything else is never shown to the visitor.
        $total = $json['order']['totalAmount'] ?? null;
        $currency = strtoupper((string) ($json['order']['currency'] ?? ''));
        if (!is_int($total) || $total <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new GatewayNotReady("Psoola answered session $id without a valid total and currency.");
        }
        if (isset($json['order']['items']) && !self::sameItems($body['items'], $json['order']['items'])) {
            App::log('error', 'Psoola priced different items', ['session' => $id, 'ref' => $registration['ref'] ?? null]);
            throw new GatewayNotReady("Psoola session $id does not contain the ticket and lunches iSmile asked for.");
        }
        return ['id' => $id, 'redirect_url' => $url, 'raw' => $answer['body'], 'amount' => $total, 'currency' => $currency];
    }

    /**
     * A cash sale (POST /orders): the office took the money, PSOOLA issues the
     * tickets at once, records them as a cash sale and emails them. iSmile owes
     * PSOOLA the ticket price. Same body as a checkout session, without returnUrl.
     */
    public function createCashSale(array $payment, array $checkout): array
    {
        $this->ready();
        $body = $this->orderBody($checkout);
        // One key per attempt, never shared with a checkout session (that would be a 409).
        $answer = $this->request('POST', 'api/partner/v1/orders', $body, ['Idempotency-Key: ismile-cash-' . (int) $payment['id']]);
        if ($answer['status'] >= 500) {
            // A server error says nothing about whether the sale was made: the caller looks it up.
            throw new GatewayNotReady('Psoola cash sale unanswered: ' . self::describeError($answer));
        }
        if (!in_array($answer['status'], [200, 201], true)) {
            throw new PsoolaRefused(self::friendlyError($answer), self::describeError($answer));
        }
        $json = $answer['json'] ?? [];
        $id = is_string($json['id'] ?? null) ? $json['id'] : '';
        $total = $json['order']['totalAmount'] ?? null;
        $currency = strtoupper((string) ($json['order']['currency'] ?? ''));
        if ($id === '' || !is_int($total) || $total <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new GatewayNotReady('Psoola answered the cash sale without an order id, total or currency.');
        }
        if (isset($json['order']['items']) && !self::sameItems($body['items'], $json['order']['items'])) {
            App::log('error', 'Psoola cash sale has different items', ['order' => $id, 'ref' => $checkout['ref'] ?? null]);
        }
        return ['id' => $id, 'raw' => self::withoutLinks($answer['body']), 'amount' => $total, 'currency' => $currency];
    }

    /**
     * The newest PSOOLA order with this partnerReference, or null when there is
     * none. Used when a cash sale's answer was lost: the order may exist anyway.
     *
     * @return ?array{id: string, raw: string, amount: int, currency: string}
     */
    public function findByReference(string $reference): ?array
    {
        $this->ready();
        $answer = $this->request('GET', 'api/partner/v1/checkout-sessions?' . http_build_query(['partnerReference' => $reference]));
        if ($answer['status'] === 404) {
            return null;
        }
        $json = $answer['json'] ?? [];
        $id = is_string($json['id'] ?? null) ? $json['id'] : '';
        $total = $json['order']['totalAmount'] ?? null;
        $currency = strtoupper((string) ($json['order']['currency'] ?? ''));
        if ($answer['status'] !== 200 || $id === '' || ($json['partnerReference'] ?? null) !== $reference || !is_int($total) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new GatewayNotReady('Psoola lookup by reference failed: ' . self::describeError($answer));
        }
        return ['id' => $id, 'raw' => self::withoutLinks($answer['body']), 'amount' => $total, 'currency' => $currency];
    }

    /**
     * One page of the confirmed sales (newest first, paid only).
     *
     * @return array{orders: list<array>, next: ?string}
     */
    public function listOrders(?string $cursor = null, int $limit = 100): array
    {
        return $this->page('orders', 'orders', $cursor, $limit);
    }

    /**
     * One page of the tickets scanned at PSOOLA's gate. A page can be short
     * (even empty) while more follow: keep going until next is null.
     *
     * @return array{checkIns: list<array>, next: ?string}
     */
    public function listCheckIns(?string $cursor = null, int $limit = 100): array
    {
        return $this->page('check-ins', 'checkIns', $cursor, $limit);
    }

    private function page(string $path, string $key, ?string $cursor, int $limit): array
    {
        $this->ready();
        $query = ['limit' => max(1, min(100, $limit))];
        $eventId = (string) App::config('payments.psoola.event_id', '');
        if ($eventId !== '') {
            $query['eventId'] = $eventId;
        }
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        $answer = $this->request('GET', 'api/partner/v1/' . $path . '?' . http_build_query($query));
        if ($answer['status'] !== 200 || !is_array($answer['json'][$key] ?? null)) {
            throw new GatewayNotReady("Psoola $path list failed: " . self::describeError($answer));
        }
        $next = $answer['json']['nextCursor'] ?? null;
        return [$key => array_values(array_filter($answer['json'][$key], 'is_array')), 'next' => is_string($next) && $next !== '' ? $next : null];
    }

    /** What a checkout session and a cash sale both send: the event, the items and the buyer. */
    private function orderBody(array $form): array
    {
        $eventId = (string) App::config('payments.psoola.event_id', '');
        $showId = (string) App::config('payments.psoola.show_id', '');
        if ($eventId === '' || $showId === '') {
            throw new GatewayNotReady('Psoola is not configured yet (payments.psoola.event_id / show_id in config.php).');
        }
        return [
            'eventId'          => $eventId,
            'showId'           => $showId,
            'items'            => self::items($form, (array) App::config('payments.psoola.ticket_types', []), (array) App::config('payments.psoola.add_ons', [])),
            'buyer'            => [
                'email' => (string) $form['email'],
                'phone' => (string) $form['phone'],   // already E.164 (Validate::phone)
                'name'  => Registrations::fullName($form),
            ],
            'locale'           => self::locale((string) ($form['lang'] ?? 'en')),
            'partnerReference' => (string) $form['ref'],
        ];
    }

    /** The event as PSOOLA's partner API lists it (ticket types, add-ons, IQD prices). */
    public function fetchEvent(): array
    {
        $this->ready();
        $eventId = (string) App::config('payments.psoola.event_id', '');
        if ($eventId === '') {
            throw new GatewayNotReady('Psoola is not configured yet (payments.psoola.event_id in config.php).');
        }
        $answer = $this->request('GET', 'api/partner/v1/events/' . rawurlencode($eventId));
        if ($answer['status'] !== 200 || !is_array($answer['json']['event'] ?? null)) {
            throw new GatewayNotReady('Psoola event lookup failed: ' . self::describeError($answer));
        }
        return $answer['json']['event'];
    }

    public function fetchStatus(string $providerPaymentId): array
    {
        $this->ready();
        $answer = $this->request('GET', 'api/partner/v1/checkout-sessions/' . rawurlencode($providerPaymentId));
        if ($answer['status'] !== 200 || $answer['json'] === null) {
            // Never decide anything from an error: the next check asks again.
            throw new GatewayNotReady('Psoola status check failed: ' . self::describeError($answer));
        }
        $json = $answer['json'];
        if (($json['id'] ?? null) !== $providerPaymentId) {
            throw new GatewayNotReady('Psoola answered about a different session.');
        }
        return self::mapStatus($json) + ['raw' => self::withoutLinks($answer['body'])];
    }

    public function readWebhook(array $headers, string $body): ?string
    {
        $this->ready();
        if ($this->webhookSecret === '') {
            // Not "bad signature": PSOOLA retries, and nothing is lost once the secret is set.
            throw new GatewayNotReady('Psoola webhook_secret is not configured.');
        }
        $header = null;
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'x-psoola-signature') {
                $header = is_array($value) ? implode(',', $value) : (string) $value;
            }
        }
        if ($header === null || !self::signatureValid($header, $body, $this->webhookSecret, time())) {
            return null;
        }
        $data = json_decode($body, true);
        $sessionId = is_array($data) && is_array($data['data'] ?? null) ? ($data['data']['sessionId'] ?? null) : null;
        // '' = correctly signed, but about no payment session (another kind of event).
        return is_string($sessionId) && $sessionId !== '' ? $sessionId : '';
    }

    // ---- pure helpers (public for backend/tests/psoola-gateway.php) ----

    /**
     * The one PSOOLA item for a form: its ticket type, with each chosen lunch
     * day as an add-on (bought once per ticket). A VIP ticket includes one
     * lunch day (vip_lunch_day), which is not sent, exactly as in
     * SiteData::quoteFor: PSOOLA cannot yet express "one of two lunches
     * free", so only an extra paid day is sent.
     *
     * @param array<string, string> $ticketTypes professional|student|vip => PSOOLA ticket type id
     * @param array<string, string> $addOns      lunchDay1|lunchDay2 => PSOOLA add-on id
     * @return list<array{ticketTypeId: string, quantity: int, addOnIds?: list<string>}>
     */
    public static function items(array $registration, array $ticketTypes, array $addOns): array
    {
        $type = (string) $registration['ticket_type'];
        if (!in_array($type, ['professional', 'student', 'vip'], true)) {
            throw new GatewayNotReady("Unknown ticket type '$type' for Psoola.");
        }
        $item = ['ticketTypeId' => self::mapped($ticketTypes, $type, 'ticket_types'), 'quantity' => 1];
        $chosen = [];
        foreach ([1, 2] as $day) {
            if ((int) ($registration['lunch_day' . $day] ?? 0) !== 1
                || ($type === 'vip' && (int) ($registration['vip_lunch_day'] ?? 0) === $day)) {
                continue;
            }
            $chosen[] = self::mapped($addOns, 'lunchDay' . $day, 'add_ons');
        }
        if ($chosen !== []) {
            $item['addOnIds'] = $chosen;
        }
        return [$item];
    }

    /** True when PSOOLA's order holds exactly the ticket types and add-ons that were sent. */
    public static function sameItems(array $sent, mixed $ordered): bool
    {
        if (!is_array($ordered)) {
            return false;
        }
        $count = static function (array $items, bool $fromOrder): array {
            $seen = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['ticketTypeId'] ?? null)) {
                    return ['invalid' => 1];
                }
                $quantity = (int) ($item['quantity'] ?? 0);
                $seen['t:' . $item['ticketTypeId']] = ($seen['t:' . $item['ticketTypeId']] ?? 0) + $quantity;
                $addOns = $fromOrder ? ($item['addOns'] ?? []) : array_map(static fn ($id) => ['addOnId' => $id, 'quantity' => $quantity], $item['addOnIds'] ?? []);
                foreach (is_array($addOns) ? $addOns : [] as $addOn) {
                    if (!is_array($addOn) || !is_string($addOn['addOnId'] ?? null)) {
                        return ['invalid' => 1];
                    }
                    $seen['a:' . $addOn['addOnId']] = ($seen['a:' . $addOn['addOnId']] ?? 0) + (int) ($addOn['quantity'] ?? 0);
                }
            }
            ksort($seen);
            return $seen;
        };
        $mine = $count($sent, false);
        return !isset($mine['invalid']) && $mine === $count($ordered, true);
    }

    private static function mapped(array $map, string $key, string $setting): string
    {
        $id = $map[$key] ?? '';
        if (!is_string($id) || trim($id) === '') {
            throw new GatewayNotReady("Psoola is not configured yet (payments.psoola.$setting.$key in config.php).");
        }
        return trim($id);
    }
    /** iSmile's languages as PSOOLA locales ('ku' is Sorani: 'ckb'). */
    public static function locale(string $lang): string
    {
        return match ($lang) {
            'ar'    => 'ar',
            'ku'    => 'ckb',
            default => 'en',
        };
    }

    /**
     * A PSOOLA session as the backend's status. An unknown status is treated
     * as still waiting, so it is asked again rather than decided.
     *
     * @return array{status: 'paid'|'failed'|'waiting'|'expired', amount: ?int, currency: ?string}
     */
    public static function mapStatus(array $session): array
    {
        $status = self::STATUSES[(string) ($session['status'] ?? '')] ?? 'waiting';
        $total = $session['order']['totalAmount'] ?? null;
        $currency = $session['order']['currency'] ?? null;
        return [
            'status'   => $status,
            'amount'   => is_int($total) ? $total : null,
            'currency' => is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
        ];
    }

    /** Checks an X-Psoola-Signature header against the raw body. */
    public static function signatureValid(string $header, string $body, string $secret, int $now): bool
    {
        if ($secret === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_map('trim', explode('=', $part, 2) + [1 => '']);
            if ($name === 't' && preg_match('/^\d{1,12}$/', $value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /**
     * PSOOLA's answer without the buyer's ticket link: PSOOLA asks that ticket
     * links are never logged or stored.
     */
    public static function withoutLinks(string $body): string
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !array_key_exists('ticketsUrl', $json)) {
            return $body;
        }
        unset($json['ticketsUrl']);
        return (string) json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** A refused cash sale, in words for the office. */
    public static function friendlyError(array $answer): string
    {
        $code = (string) ($answer['json']['error']['code'] ?? '');
        $message = (string) ($answer['json']['error']['message'] ?? '');
        $requestId = (string) ($answer['json']['requestId'] ?? '');
        $text = match ($code) {
            'INSUFFICIENT_INVENTORY'   => 'Psoola says this ticket or lunch is sold out.',
            'SALES_CLOSED'             => 'Psoola says ticket sales are closed for this event.',
            'LIMIT_EXCEEDED'           => 'Psoola says this phone number already has the most tickets allowed.',
            'DIRECT_ISSUE_NOT_ENABLED' => 'Psoola has not turned on cash sales for iSmile.',
            'EVENT_NOT_ENABLED'        => 'Psoola has paused this event for iSmile.',
            'TICKET_TYPE_NOT_AVAILABLE', 'ADD_ON_NOT_AVAILABLE' => 'Psoola says this ticket or lunch cannot be sold right now.',
            'RATE_LIMITED', 'TOO_MANY_OPEN_SESSIONS' => 'Psoola is busy. Wait a minute and press the button again.',
            'INVALID_REQUEST'          => 'Psoola refused the details' . ($message !== '' ? ': ' . $message : '.'),
            default                    => 'Psoola did not accept the cash sale' . ($message !== '' ? ': ' . $message : '.'),
        };
        return $text . ' Nothing was sold and no ticket was made.' . ($requestId !== '' ? " (Psoola request $requestId)" : '');
    }

    private static function describeError(array $answer): string
    {
        $error = $answer['json']['error'] ?? null;
        $code = is_array($error) ? (string) ($error['code'] ?? '') : '';
        $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
        $requestId = (string) ($answer['json']['requestId'] ?? '');
        return trim("HTTP {$answer['status']} $code $message" . ($requestId !== '' ? " (request $requestId)" : ''));
    }

    private function ready(): void
    {
        if ($this->base === '' || $this->key === '') {
            throw new GatewayNotReady('Psoola is not configured yet (api_base / api_key in config.php).');
        }
    }

    /**
     * A JSON request to Psoola. Kept here so the methods above stay short.
     * Verifies the TLS certificate, never follows redirects, times out.
     *
     * @param list<string> $extraHeaders
     * @return array{status: int, body: string, json: ?array}
     */
    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $curl = curl_init($this->base . '/' . ltrim($path, '/'));
        $headers = array_merge(['Accept: application/json', 'Authorization: Bearer ' . $this->key], $extraHeaders);
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
