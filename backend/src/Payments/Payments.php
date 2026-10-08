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
        $quote = SiteData::quoteFor($checkout);
        // Psoola must define settlement for a cart containing USD and IQD.
        // Never add currencies together or silently convert the visitor's price.
        if ($quote['amount'] === null) {
            return ['redirect' => null, 'error' => 'pay_start_failed'];
        }
        // An attempt made in the last few minutes is reused, so repeated clicks
        // (or an email scanner opening the link) never pile up payments at the company.
        $recent = Db::one("SELECT * FROM payments WHERE checkout_id = ? AND status = 'waiting' AND redirect_url IS NOT NULL AND created_at > ? ORDER BY id DESC LIMIT 1",
            [$checkout['id'], date('Y-m-d H:i:s', time() - self::REUSE_MINUTES * 60)]);
        if ($recent !== null && (int) $recent['amount_expected'] === $quote['amount'] && $recent['currency'] === $quote['currency']) {
            return ['redirect' => $recent['redirect_url'], 'error' => null];
        }
        // Places held by people paying right now count too, so the last seats
        // and lunches are not sold twice.
        if (Registrations::isFull(true, (int) $checkout['id'])) {
            throw new UserError('reg_full', null, 409);
        }
        if (Registrations::lunchFullFor($checkout, true)) {
            throw new UserError('lunch_full', null, 409);   // the lunch they chose is full: register again without it
        }

        $now = App::now();
        $gatewayName = (string) App::config('payments.gateway', 'fake');
        $paymentId = Db::insert('payments', [
            'checkout_id'     => $checkout['id'],
            'gateway'         => $gatewayName,
            'method'          => $checkout['pay_method'],
            'amount_expected' => $quote['amount'],
            'currency'        => $quote['currency'],
            'status'          => 'created',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $payment = self::find($paymentId);
        $payment['_return_url'] = Links::statusUrl($checkout) . '&p=' . $paymentId;

        try {
            $created = self::gateway($gatewayName)->createPayment($payment, $checkout);
        } catch (\Throwable $error) {
            Db::update('payments', ['status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 500), 'updated_at' => App::now()], 'id = ?', [$paymentId]);
            App::log('error', 'Could not start a payment', ['payment' => $paymentId, 'error' => $error->getMessage()]);
            return ['redirect' => null, 'error' => 'pay_start_failed'];
        }

        Db::update('payments', [
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
        $afterwards = [];
        $status = Db::transaction(static function () use ($paymentId, $result, &$afterwards): string {
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
            $afterwards[] = ['ticket', $registrationId];
            // The person has paid, so they get their ticket. If this went over a
            // limit (two people paying for the last place at the same moment),
            // the Owner is told at once to talk to the venue or the caterer.
            $over = Registrations::overCapacity();
            if ($over !== []) {
                $afterwards[] = ['alert', "Payment #$paymentId for {$checkout['ref']} went over a limit: " . implode('; ', $over) . '. The ticket was issued; check with the venue/caterer.'];
            }
            return 'paid';
        });

        // Emails and alerts go out only after the database change is committed.
        foreach ($afterwards as $job) {
            if ($job[0] === 'ticket') {
                Outbox::queue('ticket', Registrations::find($job[1]));
                Audit::log(null, 'payment.confirmed', 'payment', $paymentId);
            } elseif ($job[0] === 'alert') {
                Outbox::alert($job[1]);
                Audit::log(null, 'payment.flagged', 'payment', $paymentId, $job[1]);
            }
        }
        return $status;
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
                if ((self::gateway($gatewayName)->fetchStatus($providerId)['status'] ?? '') === 'paid') {
                    $outcome = 'unknown payment, PAID: Finance alerted';
                    Outbox::alert("The payment company reports payment $providerId as PAID, but it matches no form here. Check it with Psoola; nobody was registered.");
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
            $status = self::check($payment);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return $counts;
    }
}
