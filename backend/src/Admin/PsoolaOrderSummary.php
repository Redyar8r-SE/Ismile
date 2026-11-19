<?php
declare(strict_types=1);
namespace Ismile\Admin;

final class PsoolaOrderSummary
{
    public static function kind(array $order): string
    {
        if (($order['isComplimentary']??false)===true) return ($order['order']['totalAmount']??null)===0 && ($order['order']['currency']??null)==='IQD'?'complimentary':'review';
        return ($order['channel']??'')==='direct'?'cash':'online';
    }
    public static function totals(array $orders,array $registrations=[]): array
    {
        $sum=['orders'=>0,'tickets'=>0,'card'=>0,'cash'=>0,'cash_orders'=>0,'used'=>0,'missing'=>0,
            'complimentary_orders'=>0,'complimentary_tickets'=>0,'discount'=>0,'review'=>0];
        foreach($orders as $order){
            $sum['orders']++;$quantity=(int)($order['order']['ticketCount']??0);$sum['tickets']+=$quantity;
            $kind=self::kind($order);$amount=(int)($order['order']['totalAmount']??0);
            if($kind==='complimentary'){$sum['complimentary_orders']++;$sum['complimentary_tickets']+=$quantity;$sum['discount']+=(int)($order['order']['discountAmount']??0);}
            elseif($kind==='review')$sum['review']++;
            elseif($kind==='cash'){$sum['cash']+=$amount;$sum['cash_orders']++;}
            else $sum['card']+=$amount;
            foreach((array)($order['tickets']??[]) as $ticket)if(is_array($ticket)&&!empty($ticket['checkedIn']))$sum['used']++;
            if(!isset($registrations[$order['partnerReference']??'']))$sum['missing']++;
        }
        return $sum;
    }

    // ---- the filters on the Psoola orders page (owner, 19 Nov 2026) ----

    /** The filter groups and their choices, in page order. */
    public const FILTERS = [
        'email'   => ['delivered' => 'Delivered', 'missed' => 'Not received', 'waiting' => 'Still sending'],
        'channel' => ['checkout' => 'Paid on Psoola', 'direct' => 'Cash', 'complimentary' => 'Guest (free)'],
        'ticket'  => ['professional' => 'Dentist', 'student' => 'Student', 'vip' => 'VIP'],
        'door'    => ['used' => 'Came in', 'unused' => 'Not yet'],
    ];

    /**
     * The ticket email as one of three: delivered; missed (PSOOLA could not
     * deliver it: bounced, complained or failed, so the person did not get
     * the ticket email); waiting (queued, delayed or no answer yet).
     */
    public static function emailGroup(array $order): string
    {
        $status = strtolower(trim((string) ($order['emailStatus'] ?? '')));
        if ($status === 'delivered') return 'delivered';
        return in_array($status, ['bounced', 'complained', 'failed'], true) ? 'missed' : 'waiting';
    }

    /**
     * What each filter sees in one sale. $ourType maps PSOOLA ticket type ids
     * to professional, student or vip.
     *
     * @return array{email: string, channel: string, ticket: list<string>, door: string}
     */
    public static function facts(array $order, array $ourType): array
    {
        $types = [];
        foreach ((array) ($order['order']['items'] ?? []) as $item) {
            if (is_array($item) && isset($ourType[$item['ticketTypeId'] ?? ''])) $types[] = $ourType[$item['ticketTypeId']];
        }
        $used = false;
        foreach ((array) ($order['tickets'] ?? []) as $ticket) if (is_array($ticket) && !empty($ticket['checkedIn'])) $used = true;
        return ['email' => self::emailGroup($order),
            'channel' => ['online' => 'checkout', 'cash' => 'direct', 'complimentary' => 'complimentary'][self::kind($order)] ?? 'review',
            'ticket' => array_values(array_unique($types)), 'door' => $used ? 'used' : 'unused'];
    }

    /** True when the sale's facts match every chosen filter, leaving out the group $skip. */
    public static function passes(array $facts, array $chosen, ?string $skip = null): bool
    {
        foreach ($chosen as $group => $value) {
            if ($group === $skip || $value === '') continue;
            $have = $facts[$group] ?? null;
            if (is_array($have) ? !in_array($value, $have, true) : $have !== $value) return false;
        }
        return true;
    }

    /**
     * How many sales each choice would show, with the other chosen filters
     * kept (so the numbers always add up to what you would see).
     *
     * @param list<array> $factsList
     * @return array<string, array<string, int>> group => [choice => count, '' => all]
     */
    public static function counts(array $factsList, array $chosen): array
    {
        $out = [];
        foreach (self::FILTERS as $group => $choices) {
            $out[$group] = ['' => 0] + array_fill_keys(array_keys($choices), 0);
            foreach ($factsList as $facts) {
                if (!self::passes($facts, $chosen, $group)) continue;
                $out[$group]['']++;
                foreach ((array) $facts[$group] as $value) if (isset($out[$group][$value])) $out[$group][$value]++;
            }
        }
        return $out;
    }
}
