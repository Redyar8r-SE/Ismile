<?php
// Payment attempts, and the ONE place where a payment is confirmed.
//
// The rules from the plan, enforced here:
//  - the amount is set by the server and fixed on the attempt when it is made;
//  - a ticket is made only after our server asked the payment company itself
//    (fetchStatus) and heard "paid" for exactly that amount and currency;
//  - each company payment id can be confirmed once; repeats change nothing;
//  - the person is REGISTERED only here, when the company confirmed the
//    exact amount: the form waiting for payment becomes a registration;
//  - a second paid attempt for an already-paid form is a duplicate for
//    Finance to review, never a second ticket (iSmile does not refund).

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;
use Ismile\Audit;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\Links;
use Ismile\Outbox;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\Tickets;
use Ismile\UserError;

final class Payments
{
    /** Attempts still open this long are asked about by the timed job, then closed. */
    public const EXPIRE_AFTER_HOURS = 24;

    /** A waiting attempt younger than this is reused instead of starting a new one. */
    public const REUSE_MINUTES = 10;

    public static function gateway(?string $name = null): Gateway
    {
        $name ??= (string) App::config('payments.gateway', 'fake');
        return match ($name) {
            'fake'   => new FakeGateway(),
            'psoola' => new PsoolaGateway(),
            default  => throw new GatewayNotReady("Unknown payment gateway '$name'."),
        };
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM payments WHERE id = ?', [$id]);
    }

    /**
     * Starts a new attempt for a form waiting for payment and returns the
     * payment page address. Nothing is registered here: the registration is
     * made only when the company confirms the money (apply).
     *
     * @return array{redirect: ?string, error: ?string}
     */
    public static function start(array $checkout): array
    {
        if ($checkout['status'] === 'paid') {
            throw new UserError('pay_already', null, 409);
        }
        if (!Checkouts::isOpen($checkout)) {
            throw new UserError('pay_expired', null, 409);
        }
        // The Settings switch is the emergency stop: closed means no new payments.
        if (!Settings::bool('registration_open') || !SiteData::pricesReadyFor($checkout)) {
            throw new UserError('reg_closed', null, 409);
        }
        $gatewayName = (string) App::config('payments.gateway', 'fake');
        // Psoola is the price authority: it prices the cart itself (in IQD,
        // converting the USD lunch at its own rate), and its total becomes the
        // attempt's amount. Every other gateway charges iSmile's own quote.
        $priceFromGateway = $gatewayName === 'psoola';
        $quote = SiteData::quoteFor($checkout);
        // Never add currencies together or silently convert the visitor's price.
        if (!$priceFromGateway && $quote['amount'] === null) {
            return ['redirect' => null, 'error' => 'pay_start_failed'];
        }
        // An earlier attempt may have been paid already (its message not yet
        // here): ask the company first, so nobody is sent to pay twice.
        foreach (Db::all("SELECT * FROM payments WHERE checkout_id = ? AND status IN ('created','waiting') AND provider_payment_id IS NOT NULL ORDER BY id DESC LIMIT 5", [$checkout['id']]) as $open) {
            if (self::check($open) === 'paid') {
                throw new UserError('pay_already', null, 409);
            }
        }
        // One attempt at a time per form, decided under the form's lock: a
        // double click, a second tab or an email scanner opening the link at
        // the same moment all end on the same payment page.
        $deadline = time() + 25;
        while (true) {
            $claim = Db::transaction(static function () use ($checkout, $gatewayName, $priceFromGateway, $quote): array {
                $form = Db::one('SELECT * FROM checkouts WHERE id = ? FOR UPDATE', [$checkout['id']]);
                if ($form === null || $form['status'] === 'paid' || $form['registration_id'] !== null) {
                    throw new UserError('pay_already', null, 409);
                }
                if (!Checkouts::isOpen($form)) {
                    throw new UserError('pay_expired', null, 409);
                }
                // An attempt made in the last few minutes is reused.
                $recent = Db::one("SELECT * FROM payments WHERE checkout_id = ? AND status = 'waiting' AND redirect_url IS NOT NULL AND created_at > ? ORDER BY id DESC LIMIT 1",
                    [$checkout['id'], date('Y-m-d H:i:s', time() - self::REUSE_MINUTES * 60)]);
                if ($recent !== null && ($priceFromGateway || ((int) $recent['amount_expected'] === $quote['amount'] && $recent['currency'] === $quote['currency']))) {
                    return ['redirect' => $recent['redirect_url']];
                }
                // Another request is asking the company for a page right now: wait for it.
                if (Db::value("SELECT id FROM payments WHERE checkout_id = ? AND status = 'created' AND created_at > ? LIMIT 1", [$checkout['id'], date('Y-m-d H:i:s', time() - 60)])) {
                    return ['wait' => true];
                }
                // Places held by people paying right now count too, so the last seats
                // and lunches are not sold twice.
                if (Registrations::isFull(true, (int) $checkout['id'])) {
                    throw new UserError('reg_full', null, 409);
                }
                if (Registrations::lunchFullFor($form, true)) {
                    throw new UserError('lunch_full', null, 409);   // the lunch they chose is full: register again without it
                }
                // Psoola: a provisional figure from PSOOLA's listed prices until its
                // answer gives the real total (1 IQD when even the list is unavailable);
                // only an attempt that never reached PSOOLA keeps it.
                $expected = $priceFromGateway
                    ? (PsoolaPrices::estimate($form) ?? ['amount' => 1, 'currency' => 'IQD'])
                    : ['amount' => $quote['amount'], 'currency' => $quote['currency']];
                $now = App::now();
                return ['payment' => Db::insert('payments', [
                    'checkout_id'     => $checkout['id'],
                    'gateway'         => $gatewayName,
                    'method'          => $form['pay_method'],
                    'amount_expected' => $expected['amount'],
                    'currency'        => $expected['currency'],
                    'status'          => 'created',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ])];
            });
            if (isset($claim['redirect'])) {
                return ['redirect' => $claim['redirect'], 'error' => null];
            }
            if (isset($claim['payment'])) {
                $paymentId = $claim['payment'];
                break;
            }
            if (time() >= $deadline) {
                return ['redirect' => null, 'error' => 'pay_start_failed'];
            }
            usleep(500000);
        }

        // The company is asked outside any lock (it can take many seconds).
        $payment = self::find($paymentId);
        $payment['_return_url'] = Links::statusUrl($checkout) . '&p=' . $paymentId;

        try {
            $created = self::gateway($gatewayName)->createPayment($payment, $checkout);
            if ($priceFromGateway && (!is_int($created['amount'] ?? null) || $created['amount'] <= 0 || !preg_match('/^[A-Z]{3}$/', (string) ($created['currency'] ?? '')))) {
                throw new GatewayNotReady('The payment company did not say what it will charge.');
            }
        } catch (\Throwable $error) {
            Db::update('payments', ['status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 500), 'updated_at' => App::now()], 'id = ?', [$paymentId]);
            App::log('error', 'Could not start a payment', ['payment' => $paymentId, 'error' => $error->getMessage()]);
            return ['redirect' => null, 'error' => 'pay_start_failed'];
        }

        // The exact amount Payments::apply later requires the company to confirm.
        $fixed = $priceFromGateway ? ['amount_expected' => $created['amount'], 'currency' => $created['currency']] : [];
        Db::update('payments', $fixed + [
            'provider_payment_id' => $created['id'],
            'redirect_url'        => $created['redirect_url'],
            'raw_create'          => $created['raw'],
            'status'              => 'waiting',
            'updated_at'          => App::now(),
        ], 'id = ?', [$paymentId]);
        return ['redirect' => $created['redirect_url'], 'error' => null];
    }

    /**
     * Asks the payment company about one attempt (connection C) and records
     * the answer. Safe to call any number of times.
     *
     * $reopen: also ask about an attempt we already marked failed or expired.
     * Used for webhooks: a person may still pay on the company's page after a
     * declined card, or after our 24 hours, and that money must not be lost.
     */
    public static function check(array $payment, bool $reopen = false): string
    {
        $open = $reopen ? ['created', 'waiting', 'failed', 'expired'] : ['created', 'waiting'];
        if (!in_array($payment['status'], $open, true) || empty($payment['provider_payment_id'])) {
            return $payment['status'];
        }
        try {
            $result = self::gateway($payment['gateway'])->fetchStatus((string) $payment['provider_payment_id']);
        } catch (\Throwable $error) {
            Db::run('UPDATE payments SET checks = checks + 1, last_error = ?, updated_at = ? WHERE id = ?', [mb_substr($error->getMessage(), 0, 500), App::now(), $payment['id']]);
            return $payment['status'];
        }
        return self::apply((int) $payment['id'], $result);
    }

    /** Records one answer from the payment company. Returns the attempt's new status. */
    private static function apply(int $paymentId, array $result): string
    {
        return Db::transaction(static function () use ($paymentId, $result): string {
            $afterwards = [];
            $status = self::record($paymentId, $result, $afterwards);
            // Emails and alerts are queued in the same transaction as the change
            // (the timed job sends the emails table later): both happen or neither,
            // so a retry after a crash never loses a ticket email or an alert.
            foreach ($afterwards as $job) {
                if ($job[0] === 'ticket') {
                    // PSOOLA issues and emails the ticket itself: no iSmile ticket email.
                    if ($job[2] !== 'psoola') {
                        Outbox::queue('ticket', Registrations::find($job[1]));
                    }
                    Audit::log(null, 'payment.confirmed', 'payment', $paymentId);
                } elseif ($job[0] === 'alert') {
                    Outbox::alert($job[1]);
                    Audit::log(null, 'payment.flagged', 'payment', $paymentId, $job[1]);
                }
            }
            return $status;
        });
    }

    /** The decision itself, inside apply()'s transaction. */
    private static function record(int $paymentId, array $result, array &$afterwards): string
    {
        // Locks in one order everywhere (the form, then its payments), so two
        // messages for the same person never deadlock each other.
        $formId = Db::value('SELECT checkout_id FROM payments WHERE id = ?', [$paymentId]);
        if ($formId) {
            Db::one('SELECT id FROM checkouts WHERE id = ? FOR UPDATE', [$formId]);
        }
        $payment = Db::one('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
        if ($payment === null) {
            return 'missing';
        }
        $closedEarlier = in_array($payment['status'], ['failed', 'expired'], true);
        if (!in_array($payment['status'], ['created', 'waiting'], true) && !($closedEarlier && $result['status'] === 'paid')) {
            return $payment['status'];                    // already decided: a repeat changes nothing
        }
        $now = App::now();
        Db::run('UPDATE payments SET raw_status = ?, checks = checks + 1, updated_at = ? WHERE id = ?', [mb_substr($result['raw'], 0, 60000), $now, $paymentId]);

        if ($result['status'] === 'waiting') {
            if (strtotime($payment['created_at']) < time() - self::EXPIRE_AFTER_HOURS * 3600) {
                Db::run("UPDATE payments SET status = 'expired', updated_at = ? WHERE id = ?", [$now, $paymentId]);
                return 'expired';
            }
            return 'waiting';
        }

        if ($result['status'] === 'failed' || $result['status'] === 'expired') {
            Db::run('UPDATE payments SET status = ?, updated_at = ? WHERE id = ?', [$result['status'], $now, $paymentId]);
            return $result['status'];
        }

        // ---- "paid" from the company itself ----
        $amount = $result['amount'];
        $currency = strtoupper((string) $result['currency']);
        if ($amount === null || (int) $amount !== (int) $payment['amount_expected'] || $currency !== strtoupper($payment['currency'])) {
            Db::run("UPDATE payments SET status = 'mismatch', amount_confirmed = ?, confirmed_at = ?, updated_at = ?, last_error = ? WHERE id = ?", [
                $amount, $now, $now, "Expected {$payment['amount_expected']} {$payment['currency']}, company confirmed " . ($amount ?? '?') . " $currency", $paymentId,
            ]);
            $afterwards[] = ['alert', "Payment #$paymentId: the confirmed amount does not match. Held for Finance; the person was NOT registered."];
            return 'mismatch';
        }

        $checkout = $payment['checkout_id'] ? Db::one('SELECT * FROM checkouts WHERE id = ? FOR UPDATE', [$payment['checkout_id']]) : null;
        if ($checkout === null && $payment['registration_id'] !== null) {
            $checkout = ['registration_id' => $payment['registration_id'], 'ref' => (string) Db::value('SELECT ref FROM registrations WHERE id = ?', [$payment['registration_id']])];
        }
        if ($checkout === null) {
            Db::run("UPDATE payments SET status = 'mismatch', amount_confirmed = ?, confirmed_at = ?, updated_at = ?, last_error = ? WHERE id = ?", [
                $amount, $now, $now, 'Paid, but the form it belonged to no longer exists. Finance: contact Psoola for the payer.', $paymentId,
            ]);
            $afterwards[] = ['alert', "Payment #$paymentId was paid, but its form was already deleted. Held for Finance; nobody was registered."];
            return 'mismatch';
        }
        if ($checkout['registration_id'] !== null) {
            // This form was already paid by another attempt: never a second
            // ticket. Kept on record for Finance (iSmile does not refund).
            Db::run("UPDATE payments SET status = 'duplicate', registration_id = ?, amount_confirmed = ?, confirmed_at = ?, updated_at = ?, last_error = ? WHERE id = ?", [
                $checkout['registration_id'], $amount, $now, $now, 'Paid twice: no second ticket. Finance: contact the person.', $paymentId,
            ]);
            $afterwards[] = ['alert', "Payment #$paymentId for {$checkout['ref']} is a second payment for the same person. No second ticket was made; contact the person."];
            return 'duplicate';
        }

        // Paid in full: the person is registered now, and only now.
        $registrationId = Checkouts::toRegistration($checkout, $now);
        Db::run("UPDATE payments SET status = 'paid', registration_id = ?, amount_confirmed = ?, confirmed_at = ?, updated_at = ? WHERE id = ?", [$registrationId, $amount, $now, $now, $paymentId]);
        Tickets::issue($registrationId, 'payment', $paymentId, null);
        $afterwards[] = ['ticket', $registrationId, $payment['gateway']];
        // The person has paid, so they get their ticket. If this went over a
        // limit (two people paying for the last place at the same moment),
        // the Owner is told at once to talk to the venue or the caterer.
        $over = Registrations::overCapacity();
        if ($over !== []) {
            $afterwards[] = ['alert', "Payment #$paymentId for {$checkout['ref']} went over a limit: " . implode('; ', $over) . '. The ticket was issued; check with the venue/caterer.'];
        }
        return 'paid';
    }

    /**
     * A webhook from the payment company (connection B). It only tells us which
     * payment to ask about; the answer always comes from check() (connection C).
     */
    public static function handleWebhook(string $gatewayName, array $headers, string $body): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $logId = Db::insert('webhook_log', [
            'received_at' => App::now(),
            'ip'          => App::clientIp(),
            'gateway'     => $gatewayName,
            'outcome'     => 'received',
            'headers'     => mb_substr(json_encode(self::safeHeaders($headers)), 0, 20000),
            'body'        => mb_substr($body, 0, 60000),
        ]);

        try {
            $providerId = self::gateway($gatewayName)->readWebhook($headers, $body);
        } catch (\Throwable $error) {
            Db::update('webhook_log', ['outcome' => 'gateway not ready'], 'id = ?', [$logId]);
            return ['status' => 503, 'outcome' => 'gateway not ready'];
        }
        if ($providerId === '') {
            // Signed by the company, but about no payment session (another kind
            // of event): accepted, so the company does not resend it forever.
            Db::update('webhook_log', ['signature_ok' => 1, 'outcome' => 'ignored: no payment session'], 'id = ?', [$logId]);
            return ['status' => 200, 'outcome' => 'ignored'];
        }
        if ($providerId === null) {
            Db::update('webhook_log', ['outcome' => 'REJECTED: signature'], 'id = ?', [$logId]);
            App::log('warning', 'Webhook with a bad signature', ['ip' => App::clientIp()]);
            $recent = (int) Db::value("SELECT COUNT(*) FROM webhook_log WHERE outcome = 'REJECTED: signature' AND received_at > ?", [date('Y-m-d H:i:s', time() - 3600)]);
            if ($recent === 4) {   // "more than 3 in an hour" – alert once
                Outbox::alert('More than 3 payment messages with a bad signature in the last hour. Someone may be sending fake "payment succeeded" messages. Nothing was changed.');
            }
            return ['status' => 401, 'outcome' => 'bad signature'];
        }

        $payment = Db::one('SELECT * FROM payments WHERE gateway = ? AND provider_payment_id = ?', [$gatewayName, $providerId]);
        if ($payment === null) {
            // Not ours, or already cleaned up. If the company says it was PAID,
            // Finance is told, so money is never silently ignored.
            $outcome = 'unknown payment';
            try {
                // Finance is told once per payment, not on every resend of the message.
                $alerted = (bool) Db::value("SELECT COUNT(*) FROM webhook_log WHERE provider_payment_id = ? AND outcome = 'unknown payment, PAID: Finance alerted'", [$providerId]);
                if ((self::gateway($gatewayName)->fetchStatus($providerId)['status'] ?? '') === 'paid') {
                    $outcome = 'unknown payment, PAID: Finance alerted';
                    if (!$alerted) Outbox::alert("The payment company reports payment $providerId as PAID, but it matches no form here. Check it with Psoola; nobody was registered.");
                }
            } catch (\Throwable) {
                // the company could not be asked; the log keeps the message
            }
            Db::update('webhook_log', ['signature_ok' => 1, 'provider_payment_id' => $providerId, 'outcome' => $outcome], 'id = ?', [$logId]);
            return ['status' => 200, 'outcome' => $outcome];
        }
        $before = $payment['status'];
        $after = self::check($payment, true);
        $outcome = $before === $after ? "no change ($after)" : "$before -> $after";
        Db::update('webhook_log', ['signature_ok' => 1, 'provider_payment_id' => $providerId, 'outcome' => $outcome], 'id = ?', [$logId]);
        return ['status' => 200, 'outcome' => $outcome];
    }

    private static function safeHeaders(array $headers): array
    {
        unset($headers['cookie'], $headers['authorization']);
        return $headers;
    }

    /** The timed job: asks about every attempt still waiting (catches lost webhooks). */
    public static function checkWaiting(): array
    {
        $counts = [];
        $waiting = Db::all("SELECT * FROM payments WHERE status IN ('created','waiting') AND created_at < ? ORDER BY id LIMIT 200", [date('Y-m-d H:i:s', time() - 120)]);
        foreach ($waiting as $payment) {
            if ($payment['status'] === 'created' && strtotime($payment['created_at']) < time() - 3600) {
                // Never reached the company (it failed before answering).
                Db::run("UPDATE payments SET status = 'failed', updated_at = ? WHERE id = ? AND status = 'created'", [App::now(), $payment['id']]);
                $counts['failed'] = ($counts['failed'] ?? 0) + 1;
                continue;
            }
            try {
                $status = self::check($payment);
            } catch (\Throwable $error) {
                // One bad answer (or a lock timeout) never stops the rest of the run.
                App::log('error', 'Payment check failed', ['payment' => $payment['id'], 'error' => $error->getMessage()]);
                $status = 'error';
            }
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        // Attempts we closed (declined, or no answer in 24 hours) can still be
        // paid on the company's page. Ask again every 30 minutes for 7 days, so a
        // payment whose message was lost is still recorded.
        $closed = Db::all("SELECT * FROM payments WHERE status IN ('failed','expired') AND provider_payment_id IS NOT NULL AND created_at > ? AND updated_at < ? ORDER BY id LIMIT 50",
            [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() - 1800)]);
        foreach ($closed as $payment) {
            try {
                $status = self::check($payment, true);
            } catch (\Throwable $error) {
                App::log('error', 'Late payment check failed', ['payment' => $payment['id'], 'error' => $error->getMessage()]);
                $status = 'error';
            }
            Db::run("UPDATE payments SET updated_at = ? WHERE id = ? AND status IN ('failed','expired')", [App::now(), $payment['id']]);
            if ($status === 'paid') {
                $counts['paid late'] = ($counts['paid late'] ?? 0) + 1;
            }
        }
        return $counts;
    }
}
