<?php
declare(strict_types=1);
namespace Ismile\Admin;

use Ismile\App;
use Ismile\Db;
use Ismile\Registrations;

/** Historical receipt data only. Never reprice an old sale using today's prices. */
final class PaymentBreakdown
{
    public const COLUMNS = [
        'finance_ticket' => 'Ticket paid',
        'finance_lunch1' => 'Lunch day 1 paid',
        'finance_lunch2' => 'Lunch day 2 paid',
        'finance_total' => 'Total paid',
        'finance_channel' => 'Cash / website',
        'finance_date' => 'Payment date (Iraq)',
        'finance_usd100' => '100 USD in IQD',
        'finance_rate_source' => 'Rate source',
        'finance_note' => 'Payment notes',
    ];

    /** Preserve original provider fields and the USD source prices at checkout, not export time. */
    public static function archive(string $raw, array $form, array $sourcePrices, array $addOnIds): string
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) return $raw;
        $data['_ismile_pricing'] = [
            'version' => 1, 'captured_at' => App::now(),
            'ticket_type' => $form['ticket_type'] ?? '',
            'lunch_day1' => (int) ($form['lunch_day1'] ?? 0),
            'lunch_day2' => (int) ($form['lunch_day2'] ?? 0),
            'vip_lunch_day' => $form['vip_lunch_day'] ?? null,
            'source_prices' => $sourcePrices, 'add_on_ids' => $addOnIds,
        ];
        // Reporting metadata must never block a successful provider checkout.
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : $raw;
    }

    /** Bulk lookup, shared by registration exports and every people list. */
    public static function attach(array $people): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['id'] ?? 0), $people))));
        $payments = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            foreach (Db::all("SELECT * FROM payments WHERE status='paid' AND registration_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY id', $chunk) as $p) {
                $payments[(int) $p['registration_id']] ??= $p;
            }
        }
        $map = (array) App::config('payments.psoola.add_ons', []);
        return array_map(static fn ($r) => array_merge($r, self::describe($r, $payments[(int) ($r['id'] ?? 0)] ?? null, $map)), $people);
    }

    /** Pure formatter: the confirmed receipt total is authoritative. */
    public static function describe(array $registration, ?array $payment, array $addOnIds = []): array
    {
        $unknown = 'Not recorded';
        $out = array_fill_keys(array_keys(self::COLUMNS), $unknown);
        $out['finance_date'] = (string) ($payment['confirmed_at'] ?? $registration['paid_at'] ?? $unknown);
        $out['finance_note'] = '';
        if (($registration['status'] ?? '') === 'complimentary' && $payment === null) {
            foreach (['finance_ticket','finance_lunch1','finance_lunch2','finance_total'] as $key) $out[$key] = '0 IQD (complimentary)';
            $out['finance_channel'] = 'Complimentary';
            $out['finance_usd100'] = $out['finance_rate_source'] = 'Not applicable';
            $out['finance_note'] = 'Complimentary ticket; no money collected.';
            return $out;
        }
        if ($payment === null || ($payment['status'] ?? '') !== 'paid' || !isset($payment['amount_confirmed'])) {
            $out['finance_note'] = 'No confirmed payment record; no amount inferred.';
            return $out;
        }
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        $total = self::integer($payment['amount_confirmed']);
        if ($total === null) return $out;
        $out['finance_total'] = self::money($total, $currency);
        $out['finance_channel'] = ($payment['method'] ?? '') === 'cash' ? 'Cash' : (($payment['gateway'] ?? '') === 'psoola' ? 'Website (Psoola)' : 'Test payment');
        $create = self::json($payment['raw_create'] ?? null);
        $status = self::json($payment['raw_status'] ?? null);
        if (($status['channel'] ?? $create['channel'] ?? '') === 'direct') $out['finance_channel'] = 'Cash';
        $paidAt = $status['paidAt'] ?? $create['paidAt'] ?? null;
        if (is_int($paidAt) && $paidAt > 0) $out['finance_date'] = date('Y-m-d H:i:s', (int) floor($paidAt / 1000));
        $snapshot = is_array($create['_ismile_pricing'] ?? null) ? $create['_ismile_pricing'] : [];
        $sale = $snapshot + $registration;
        $mapping = is_array($snapshot['add_on_ids'] ?? null) ? $snapshot['add_on_ids'] : $addOnIds;
        $prices = (array) ($snapshot['source_prices'] ?? []);
        $usd = [];
        foreach ([1,2] as $day) {
            $booked = (int) ($sale['lunch_day' . $day] ?? 0) === 1;
            $included = ($sale['ticket_type'] ?? '') === 'vip' && (int) ($sale['vip_lunch_day'] ?? 0) === $day;
            $out['finance_lunch' . $day] = !$booked ? 'Not booked' : ($included ? 'Included in VIP ticket' : $unknown);
        }
        // Status can contain newer detail, but each complete order must reconcile independently.
        $receipt = null;
        foreach ([$status, $create] as $payload) {
            $order = $payload['order'] ?? null;
            if (!is_array($order) || strtoupper((string) ($order['currency'] ?? $currency)) !== $currency
                || self::integer($order['totalAmount'] ?? null) !== $total) continue;
            $items = $order['items'] ?? null;
            if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) continue;
            $item = $items[0];
            if (self::integer($item['quantity'] ?? 1) !== 1) continue;
            $ticket = self::integer($item['unitPrice'] ?? null);
            if ($ticket === null) continue;
            $sum = $ticket; $lunches = []; $valid = true;
            foreach ((array) ($item['addOns'] ?? []) as $addOn) {
                if (!is_array($addOn)) { $valid = false; break; }
                $price = self::integer($addOn['unitPrice'] ?? null);
                $quantity = self::integer($addOn['quantity'] ?? 1);
                $key = array_search($addOn['addOnId'] ?? null, $mapping, true);
                $day = match ($key) { 'lunchDay1' => 1, 'lunchDay2' => 2, default => null };
                if ($price === null || $quantity !== 1 || $day === null || isset($lunches[$day])) { $valid = false; break; }
                $lunches[$day] = $price; $sum += $price;
            }
            if ($valid && $sum === $total) { $receipt = [$ticket, $lunches]; break; }
        }
        if ($receipt !== null) {
            [$ticket, $lunches] = $receipt;
            $out['finance_ticket'] = self::money($ticket, $currency);
            if (($sale['ticket_type'] ?? '') === 'vip' && ($prices['vipCurrency'] ?? '') === 'USD' && (float) ($prices['vip'] ?? 0) > 0) $usd[] = [$ticket, (float) $prices['vip']];
            foreach ($lunches as $day => $price) {
                $out['finance_lunch' . $day] = self::money($price, $currency);
                if (($prices['lunchCurrency'] ?? '') === 'USD' && (float) ($prices['lunchDay' . $day] ?? 0) > 0) {
                    $dollars = (float) $prices['lunchDay' . $day];
                    $out['finance_lunch' . $day] .= ' (USD ' . self::decimal($dollars) . ')';
                    $usd[] = [$price, $dollars];
                }
            }
        } else {
            // A total may include fees: never silently allocate an unknown total to the ticket.
            $out['finance_note'] = 'Confirmed total available; item breakdown not recorded or does not reconcile.';
        }
        if ($currency === 'IQD') {
            // Only explicitly directed USD -> IQD metadata is accepted as a provider rate.
            foreach ([$status, $create] as $payload) {
                if (self::integer($payload['order']['totalAmount'] ?? null) !== $total || strtoupper((string) ($payload['order']['currency'] ?? '')) !== 'IQD') continue;
                $exchange = $payload['order']['exchangeRate'] ?? $payload['exchangeRate'] ?? null;
                if (is_array($exchange) && ($exchange['fromCurrency'] ?? '') === 'USD' && ($exchange['toCurrency'] ?? '') === 'IQD'
                    && is_numeric($exchange['rate'] ?? null) && is_finite((float) $exchange['rate']) && (float) $exchange['rate'] > 0 && (float) $exchange['rate'] <= PHP_INT_MAX / 100) {
                    $out['finance_usd100'] = self::money((int) round(100 * (float) $exchange['rate']), 'IQD');
                    $out['finance_rate_source'] = 'Psoola payment record'; break;
                }
            }
            if ($out['finance_usd100'] === $unknown && $receipt !== null && $usd !== []) {
                $rates = array_map(static fn ($p) => $p[0] / $p[1], $usd);
                if (min($rates) > 0 && max($rates) - min($rates) < 0.01) {
                    $out['finance_usd100'] = self::money((int) round($rates[0] * 100), 'IQD');
                    $out['finance_rate_source'] = 'Implied by paid IQD / saved USD price';
                }
            }
        } else $out['finance_note'] = trim($out['finance_note'] . ' Paid in ' . $currency . '; no historical IQD conversion recorded.');
        return $out;
    }

    public static function reportRows(array $people): array
    {
        $rows = [array_merge(['Reference', 'Full name', 'Specialty'], array_values(self::COLUMNS))];
        foreach ($people as $r) $rows[] = array_merge([$r['ref'] ?? '', Registrations::fullName($r), Registrations::SPECIALTY_NAMES[$r['specialty'] ?? ''] ?? ($r['specialty'] ?? '')], array_map(static fn ($k) => $r[$k] ?? 'Not recorded', array_keys(self::COLUMNS)));
        return $rows;
    }

    private static function json(mixed $value): array { $v = is_string($value) ? json_decode($value, true) : null; return is_array($v) ? $v : []; }
    private static function integer(mixed $value): ?int { return is_int($value) && $value >= 0 ? $value : (is_string($value) && preg_match('/^\d{1,12}$/D', $value) ? (int) $value : null); }
    private static function money(int $value, string $currency): string { return number_format($value) . ' ' . $currency; }
    private static function decimal(float $value): string { return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'); }
}
