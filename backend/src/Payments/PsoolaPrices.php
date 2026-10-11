<?php
// PSOOLA's prices for iSmile, for showing on the registration page when the
// gateway is 'psoola'. PSOOLA is the price authority (all IQD); the amount
// actually charged is always the total PSOOLA returns for the session.
//
// The event is read from PSOOLA's partner API on the server (the API key never
// reaches a browser) and cached in storage/tmp for five minutes. When PSOOLA
// cannot be reached, the last good answer is used for up to an hour, then
// nothing (the page falls back to data/tickets.json).

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;

final class PsoolaPrices
{
    private const FRESH_SECONDS = 300;
    private const RETRY_AFTER_FAILURE_SECONDS = 30;
    private const STALE_LIMIT_SECONDS = 3600;

    /**
     * @return ?array{currency: string, ticketTypes: array{professional: int, student: int, vip: int}, lunch: array{day1: int, day2: int}}
     */
    public static function get(): ?array
    {
        if ((string) App::config('payments.gateway', 'fake') !== 'psoola') {
            return null;
        }
        $file = App::storage('tmp/psoola-prices.json');
        $cache = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $cache = is_array($cache) ? $cache : ['checked_at' => 0, 'fetched_at' => 0, 'prices' => null];
        $now = time();
        $wait = $cache['prices'] !== null && (int) $cache['checked_at'] === (int) $cache['fetched_at']
            ? self::FRESH_SECONDS : self::RETRY_AFTER_FAILURE_SECONDS;
        if ($now - (int) $cache['checked_at'] >= $wait) {
            $cache['checked_at'] = $now;
            try {
                $cache['prices'] = self::fromEvent((new PsoolaGateway())->fetchEvent(),
                    (string) App::config('payments.psoola.show_id', ''),
                    (array) App::config('payments.psoola.ticket_types', []),
                    (array) App::config('payments.psoola.add_ons', []));
                $cache['fetched_at'] = $now;
            } catch (\Throwable $error) {
                App::log('warning', 'Psoola prices unavailable', ['error' => $error->getMessage()]);
            }
            @file_put_contents($file, json_encode($cache), LOCK_EX);
        }
        return $cache['prices'] !== null && $now - (int) $cache['fetched_at'] <= self::STALE_LIMIT_SECONDS ? $cache['prices'] : null;
    }

    /**
     * The iSmile prices in one PSOOLA event answer. Every mapped ticket type
     * and lunch add-on must be listed with a positive whole price in one
     * currency, or the answer is refused.
     */
    public static function fromEvent(array $event, string $showId, array $ticketTypes, array $addOns): array
    {
        $show = null;
        foreach ((array) ($event['shows'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $showId) {
                $show = $candidate;
            }
        }
        if ($show === null) {
            throw new GatewayNotReady('Psoola event does not list the configured show.');
        }
        $currency = null;
        $price = static function (mixed $amount, mixed $itemCurrency) use (&$currency): int {
            $itemCurrency = strtoupper((string) $itemCurrency);
            if (!is_int($amount) || $amount <= 0 || !preg_match('/^[A-Z]{3}$/', $itemCurrency) || ($currency !== null && $currency !== $itemCurrency)) {
                throw new GatewayNotReady('Psoola listed a price iSmile cannot show.');
            }
            $currency = $itemCurrency;
            return $amount;
        };
        $types = [];
        $lunch = [];
        foreach ((array) ($show['ticketTypes'] ?? []) as $type) {
            if (!is_array($type)) {
                continue;
            }
            $key = array_search($type['id'] ?? null, $ticketTypes, true);
            if (is_string($key) && in_array($key, ['professional', 'student', 'vip'], true)) {
                $types[$key] = $price($type['price'] ?? null, $type['currency'] ?? $event['currency'] ?? '');
            }
            // Add-on prices are per ticket and in the event currency.
            foreach ((array) ($type['addOns'] ?? []) as $addOn) {
                $day = is_array($addOn) ? array_search($addOn['id'] ?? null, $addOns, true) : false;
                if (in_array($day, ['lunchDay1', 'lunchDay2'], true) && !isset($lunch[$day])) {
                    $lunch[$day] = $price($addOn['price'] ?? null, $type['currency'] ?? $event['currency'] ?? '');
                }
            }
        }
        if (count($types) !== 3 || count($lunch) !== 2) {
            throw new GatewayNotReady('Psoola event does not list every iSmile ticket type and lunch day.');
        }
        return [
            'currency'    => (string) $currency,
            'ticketTypes' => ['professional' => $types['professional'], 'student' => $types['student'], 'vip' => $types['vip']],
            'lunch'       => ['day1' => $lunch['lunchDay1'], 'day2' => $lunch['lunchDay2']],
        ];
    }

    /**
     * What PSOOLA's listed prices add up to for one form (the VIP's included
     * lunch day is not charged, as in PsoolaGateway::items), or null.
     *
     * @return ?array{amount: int, currency: string}
     */
    public static function estimate(array $checkout): ?array
    {
        $prices = self::get();
        $type = (string) $checkout['ticket_type'];
        if ($prices === null || !isset($prices['ticketTypes'][$type])) {
            return null;
        }
        $amount = $prices['ticketTypes'][$type];
        foreach ([1, 2] as $day) {
            if ((int) ($checkout['lunch_day' . $day] ?? 0) === 1
                && !($type === 'vip' && (int) ($checkout['vip_lunch_day'] ?? 0) === $day)) {
                $amount += $prices['lunch']['day' . $day];
            }
        }
        return ['amount' => $amount, 'currency' => $prices['currency']];
    }

    /** The same prices in the shape of SiteData::prices(), for the registration page. */
    public static function asSitePrices(array $prices): array
    {
        return [
            'currency'      => $prices['currency'],
            'professional'  => $prices['ticketTypes']['professional'],
            'student'       => $prices['ticketTypes']['student'],
            'vip'           => $prices['ticketTypes']['vip'],
            'vipCurrency'   => $prices['currency'],
            'lunchCurrency' => $prices['currency'],
            'lunchDay1'     => $prices['lunch']['day1'],
            'lunchDay2'     => $prices['lunch']['day2'],
        ];
    }
}
