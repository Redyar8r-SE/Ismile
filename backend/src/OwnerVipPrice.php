<?php
// "VIP $100": only the main Owner can sell a VIP ticket for $100 on
// Register (cash). PSOOLA has no price per order, so for each sale the server:
//   1. sets the PSOOLA VIP price to $100 (the dollar price only: PSOOLA
//      refuses both prices in one change, and works out 171,100 IQD itself),
//   2. makes the normal cash sale (PSOOLA emails and WhatsApps the QR).
//      VIP $100 has no included lunch: a ticked lunch day is a paid extra.
//   3. sets VIP back to the price it had ($200), even when the sale fails.
// Only one such sale runs at a time. A note in storage/tmp remembers the
// normal price while it is lowered; the minute job (cron/run.php) puts it
// back if a sale was ever cut off half-way, and emails an alert.

declare(strict_types=1);

namespace Ismile;

use Ismile\Payments\Payments;
use Ismile\Payments\PsoolaGateway;
use Ismile\Payments\PsoolaPrices;

final class OwnerVipPrice
{
    public const USD = 100;
    public const NORMAL_USD = 200;
    private const LOCK = 'ismile-owner-vip-price';

    /** Only the main Owner, signed in as Owner, on the PSOOLA gateway. */
    public static function allowed(array $user): bool
    {
        return ($user['role'] ?? '') === 'owner' && Erase::isMainOwner($user)
            && App::config('payments.gateway') === 'psoola' && self::vipId() !== '';
    }

    /**
     * The dinar price of VIP $100 at PSOOLA's own VIP rate, for the form
     * (from PSOOLA's cached prices: the VIP dinar price and its dollar price).
     */
    public static function previewAmount(?array $prices): ?int
    {
        $usd = (float) ($prices['vipUsd'] ?? self::NORMAL_USD);
        $vip = $prices['ticketTypes']['vip'] ?? null;
        return is_int($vip) && $usd > 0 ? self::scaled($vip, $usd) : null;
    }

    /**
     * Sells one VIP ticket for $100 (cash), as Payments::sellForCash does.
     * $in is the Register (cash) form.
     */
    public static function sell(array $in, array $user, ?array $photo = null): array
    {
        if (!self::allowed($user)) {
            throw new UserError('Only the main Owner can sell VIP for $100.');
        }
        $in['ticket'] = 'vip';
        if (($in['specialty'] ?? '') === 'student') {
            throw new UserError('VIP is not for dental students. Choose another specialty.');
        }
        if (!(int) Db::value('SELECT GET_LOCK(?, 0)', [self::LOCK])) {
            throw new UserError('Another VIP $100 sale is running. Try again in a minute.');
        }
        $gateway = new PsoolaGateway();
        try {
            // A sale cut off earlier: the normal price goes back first.
            self::restoreSaved($gateway);
            $normal = self::read($gateway);
            if ((float) $normal['usdPrice'] !== (float) self::NORMAL_USD) {
                throw new UserError('VIP in PSOOLA is not $' . self::NORMAL_USD . ' right now (it is $' . $normal['usdPrice'] . '), so nothing was sold. Check the VIP price in PSOOLA first.');
            }
            $lowered = self::scaled($normal['price'], (float) $normal['usdPrice']);
            if ($lowered === null) {
                throw new UserError('PSOOLA\'s VIP dinar price cannot be halved exactly, so nothing was sold.');
            }
            // The form is checked and saved before PSOOLA's price is touched.
            $checkout = Checkouts::createForCash($in, $user, $photo, true, true);
            $expected = $lowered;
            $prices = PsoolaPrices::get();
            foreach ([1, 2] as $day) {
                if ((int) $checkout['lunch_day' . $day] === 1 && (int) $checkout['vip_lunch_day'] !== $day) {
                    $extra = $prices['lunch']['day' . $day] ?? null;
                    if (!is_int($extra) || $extra <= 0) {
                        throw new UserError('PSOOLA\'s lunch price is not available right now, so nothing was sold. Try again in a minute.');
                    }
                    $expected += $extra;
                }
            }
            self::remember($normal);
            $restored = true;
            try {
                // PSOOLA takes either the dollar or the dinar price, never both (live check
                // 17 Nov 2026): $100 gives 171,100 IQD at its own VIP rate.
                self::set($gateway, ['usdPrice' => self::USD]);
                $now = self::read($gateway);
                if ($now['price'] !== $lowered || (float) $now['usdPrice'] !== (float) self::USD) {
                    throw new UserError('PSOOLA did not take the $' . self::USD . ' VIP price, so nothing was sold.');
                }
                $sale = Payments::sellForCash($checkout, $user, ['amount' => $expected, 'currency' => (string) ($now['currency'] ?? 'IQD')]);
            } finally {
                // Never hides the sale's own answer: a failed put-back is alerted and retried by the minute job.
                try {
                    self::restore($gateway, $normal);
                } catch (\Throwable) {
                    $restored = false;
                }
            }
            Audit::log((int) $user['id'], 'vip.owner_price_sale', 'checkout', (int) $checkout['id'], [
                'ref' => $checkout['ref'], 'usd' => self::USD, 'amount' => $sale['amount'], 'currency' => $sale['currency'], 'status' => $sale['status'],
            ]);
            return ['checkout' => $checkout, 'sale' => $sale, 'restored' => $restored];
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * The minute job: a lowered VIP price left behind by a sale that was cut
     * off is put back. Returns what happened, for the cron report.
     */
    public static function repair(): string
    {
        if (!is_file(self::noteFile()) || App::config('payments.gateway') !== 'psoola') {
            return 'ok';
        }
        if (!(int) Db::value('SELECT GET_LOCK(?, 0)', [self::LOCK])) {
            return 'sale running';
        }
        try {
            return self::restoreSaved(new PsoolaGateway()) ? 'restored' : 'ok';
        } catch (\Throwable $error) {
            App::log('error', 'VIP price not put back', ['error' => $error->getMessage()]);
            return 'failed';
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    private static function restoreSaved(PsoolaGateway $gateway): bool
    {
        $saved = is_file(self::noteFile()) ? json_decode((string) file_get_contents(self::noteFile()), true) : null;
        if (!is_array($saved) || !is_int($saved['price'] ?? null)) {
            @unlink(self::noteFile());
            return false;
        }
        self::restore($gateway, $saved);
        Outbox::alert('The VIP price in PSOOLA was left at $' . self::USD . ' by a VIP $100 sale that was cut off. It was put back to $' . $saved['usdPrice'] . '.');
        return true;
    }

    /** Puts the normal VIP price back and checks it; the note stays until it is back. */
    private static function restore(PsoolaGateway $gateway, array $normal): void
    {
        // Either the dollar price (VIP is set in dollars) or the dinar price, never both.
        $body = $normal['usdPrice'] !== null ? ['usdPrice' => $normal['usdPrice']] : ['price' => (int) $normal['price']];
        $problem = null;
        for ($try = 0; $try < 3; $try++) {
            try {
                self::set($gateway, $body);
                if (self::read($gateway)['price'] === (int) $normal['price']) {
                    @unlink(self::noteFile());
                    // The website and forms show $200 again at once.
                    @unlink(App::storage('tmp/psoola-prices.json'));
                    return;
                }
                $problem = 'PSOOLA still shows another VIP price.';
            } catch (\Throwable $error) {
                $problem = $error->getMessage();
            }
            usleep(300_000);
        }
        App::log('error', 'VIP price not put back', ['error' => $problem]);
        Outbox::alert('URGENT: the VIP price in PSOOLA could not be put back to $' . $normal['usdPrice'] . ' after a VIP $100 sale. The server tries again every minute; check PSOOLA now.');
        throw new \RuntimeException('VIP price not put back: ' . $problem);
    }

    /** @return array{price: int, usdPrice: int|float|null, currency: ?string} */
    private static function read(PsoolaGateway $gateway): array
    {
        $answer = $gateway->partnerRequest('GET', 'events/' . rawurlencode(self::eventId()) . '/ticket-types');
        foreach ((array) ($answer['json']['ticketTypes'] ?? []) as $type) {
            if (is_array($type) && ($type['id'] ?? null) === self::vipId() && is_int($type['price'] ?? null) && $type['price'] > 0) {
                $usd = $type['usdPrice'] ?? null;
                return ['price' => $type['price'], 'usdPrice' => is_int($usd) || is_float($usd) ? $usd : null, 'currency' => isset($type['currency']) ? strtoupper((string) $type['currency']) : null];
            }
        }
        throw new UserError('PSOOLA did not answer with the VIP price (HTTP ' . (int) $answer['status'] . '), so nothing was sold.');
    }

    private static function set(PsoolaGateway $gateway, array $body): void
    {
        $answer = $gateway->partnerRequest('PATCH', 'events/' . rawurlencode(self::eventId()) . '/ticket-types/' . rawurlencode(self::vipId()), $body,
            ['Idempotency-Key: ismile-vip-price-' . bin2hex(random_bytes(12))]);
        if (!in_array($answer['status'], [200, 201], true)) {
            throw new UserError('PSOOLA refused the VIP price change (HTTP ' . (int) $answer['status'] . ': ' . mb_substr((string) ($answer['json']['error']['message'] ?? ''), 0, 160) . '), so nothing was sold.');
        }
    }

    private static function remember(array $normal): void
    {
        if (@file_put_contents(self::noteFile(), json_encode($normal + ['at' => time()]), LOCK_EX) === false) {
            throw new UserError('The server could not save the normal VIP price, so nothing was changed or sold.');
        }
    }

    /** $amount at $100 instead of $usd, when it divides exactly. */
    private static function scaled(int $amount, float $usd): ?int
    {
        $value = $amount * self::USD / $usd;
        return $usd > 0 && abs($value - round($value)) < 0.00001 && $value > 0 ? (int) round($value) : null;
    }

    private static function noteFile(): string
    {
        return App::storage('tmp/owner-vip-price.json');
    }

    private static function eventId(): string
    {
        return (string) App::config('payments.psoola.event_id', '');
    }

    private static function vipId(): string
    {
        return (string) App::config('payments.psoola.ticket_types.vip', '');
    }
}
