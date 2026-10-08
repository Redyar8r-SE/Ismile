<?php
// The website's own content files (data/*.json). The backend reads prices,
// workshops and sponsor tiers from them, so what the admin shows and what
// the server charges can never disagree.

declare(strict_types=1);

namespace Ismile;

final class SiteData
{
    public static function read(string $name): array
    {
        $file = App::siteFile('data/' . $name . '.json');
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /** Prices retain their configured currency; no exchange rate is inferred. */
    public static function prices(): array
    {
        $tickets = self::read('tickets');
        return [
            'currency'     => (string) ($tickets['currency'] ?? 'IQD'),
            'professional' => max(0, (int) ($tickets['professional'] ?? 0)),
            'student'      => max(0, (int) ($tickets['student'] ?? 0)),
            'vip'          => max(0, (int) ($tickets['vip'] ?? 0)),
            'vipCurrency'  => (string) ($tickets['vipCurrency'] ?? $tickets['currency'] ?? 'IQD'),
            'lunchCurrency'=> (string) ($tickets['lunchCurrency'] ?? $tickets['currency'] ?? 'IQD'),
            'lunchDay1'    => max(0, (int) ($tickets['lunchDay1'] ?? 0)),
            'lunchDay2'    => max(0, (int) ($tickets['lunchDay2'] ?? 0)),
        ];
    }

    /**
     * Registration visibility is controlled by database Settings.
     */
    public static function closedBySwitch(): bool
    {
        return !Settings::bool('registration_open');
    }

    /** Authoritative totals by currency. The chosen VIP lunch is included. */
    public static function quoteFor(array $registration): array
    {
        $prices = self::prices();
        $type = $registration['ticket_type'];
        $currency = $type === 'vip' ? $prices['vipCurrency'] : $prices['currency'];
        $totals = [$currency => $prices[$type] ?? 0];
        foreach ([1, 2] as $day) {
            if ((int) $registration['lunch_day' . $day] !== 1
                || ($type === 'vip' && (int) ($registration['vip_lunch_day'] ?? 0) === $day)) {
                continue;
            }
            $lunchCurrency = $prices['lunchCurrency'];
            $totals[$lunchCurrency] = ($totals[$lunchCurrency] ?? 0) + $prices['lunchDay' . $day];
        }
        $single = count($totals) === 1;
        return ['totals' => $totals, 'amount' => $single ? array_values($totals)[0] : null,
            'currency' => $single ? array_key_first($totals) : null];
    }

    /** A provider charge requires one currency, configured explicitly. */
    public static function amountFor(array $registration): int
    {
        $quote = self::quoteFor($registration);
        if ($quote['amount'] === null) {
            throw new UserError('pay_start_failed');
        }
        return $quote['amount'];
    }

    /** True when every price this registration needs has been set (> 0). */
    public static function pricesReadyFor(array $registration): bool
    {
        $prices = self::prices();
        $type = $registration['ticket_type'];
        $ticket = $prices[$type] ?? 0;
        if ($ticket <= 0) {
            return false;
        }
        if ($type === 'vip' && (!in_array((int) ($registration['vip_lunch_day'] ?? 0), [1, 2], true)
            || (int) $registration['lunch_day' . (int) $registration['vip_lunch_day']] !== 1)) {
            return false;
        }
        foreach ([1, 2] as $day) {
            if ((int) $registration['lunch_day' . $day] === 1
                && !($type === 'vip' && (int) $registration['vip_lunch_day'] === $day)
                && $prices['lunchDay' . $day] <= 0) {
                return false;
            }
        }
        return true;
    }

    /** The active workshops (from the database; see Workshops). */
    public static function workshops(): array
    {
        return Workshops::all();
    }

    public static function workshop(string $id): ?array
    {
        return Workshops::find($id);
    }

    public static function workshopName(array $workshop, string $lang = 'en'): string
    {
        return Workshops::name($workshop, $lang);
    }

    public static function sponsorTiers(): array
    {
        $sponsors = self::read('sponsors');
        return is_array($sponsors['tiers'] ?? null) ? $sponsors['tiers'] : [];
    }

    /** After a booking changes: the website's "seats left" follow the database. */
    public static function syncWorkshopSeats(): void
    {
        Workshops::syncWebsite();
    }

    /**
     * The content files the backend only READS: prices and the open/closed
     * switch (tickets), and what the ticket email shows (program, map, footer).
     * workshops.json and sponsors.json are never copied: the backend writes them.
     */
    public const FOLLOWED = ['tickets', 'program', 'map', 'footer'];

    /**
     * When the website runs on another server (config.php content_source_url,
     * e.g. https://ismile.krd), copy those files from it, so the backend always
     * charges the website's prices and follows its "Close registration" switch.
     * A file is only replaced by valid JSON that differs; on any problem the
     * current copy stays. Returns name => 'updated' | 'same' | 'error: …'.
     */
    public static function followWebsite(): array
    {
        $base = rtrim((string) App::config('content_source_url', ''), '/');
        if ($base === '') {
            return [];
        }
        $report = [];
        foreach (self::FOLLOWED as $name) {
            $curl = curl_init("$base/data/$name.json?t=" . time());
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $data = is_string($body) ? json_decode($body, true) : null;
            if ($status !== 200 || !is_array($data) || $data === []) {
                $report[$name] = "error: HTTP $status or not JSON";
                continue;
            }
            $file = App::siteFile("data/$name.json");
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "
";
            $current = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if ($current === $data) {
                $report[$name] = 'same';
                continue;
            }
            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                $report[$name] = 'error: cannot write';
                continue;
            }
            $report[$name] = 'updated';
        }
        return $report;
    }
}
