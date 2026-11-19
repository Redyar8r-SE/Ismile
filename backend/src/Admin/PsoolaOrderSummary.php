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

    // ---- speed (owner, 19 Nov 2026): PSOOLA's list is kept for one minute ----
    // Reading every order from PSOOLA takes about ten requests one after the
    // other, so the page kept them for a minute: a filter or a search is then
    // instant. "Refresh from Psoola" ($fresh) always reads PSOOLA again.
    // Ticket links and QR codes are removed before the copy is saved.
    public const KEEP_SECONDS = 60;
    // An older copy (up to a day) is shown at once and PSOOLA is read again
    // just after the page is sent, so no visit waits for PSOOLA. The five-minute
    // job keeps the copy fresh by itself (warm()), so it is rarely older than that.
    public const STALE_SECONDS = 86400;

    /** Every PSOOLA order for the summit, page by page. */
    public static function fetchAll(): array
    {
        $gateway = new \Ismile\Payments\PsoolaGateway();
        $all = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $gateway->listOrders($cursor);
            array_push($all, ...$page['orders']);
            $cursor = $page['next'];
        } while ($cursor !== null && ++$pages < 30);
        return $all;
    }

    /**
     * One person's ticket email and WhatsApp as PSOOLA last reported them,
     * from the saved order list (no extra PSOOLA read), or null when unknown.
     *
     * @return array{email: string, emailStatus: string, whatsapp: string, whatsappStatus: string, at: int}|null
     */
    public static function deliveryFor(string $reference, array $orderIds = []): ?array
    {
        $saved = self::saved();
        if (!$saved) return null;
        $orderIds = array_values(array_filter($orderIds, static fn ($id): bool => is_string($id) && $id !== ''));
        foreach ($saved['orders'] as $order) {
            if (!is_array($order)) continue;
            if (($reference !== '' && ($order['partnerReference'] ?? null) === $reference) || in_array($order['id'] ?? null, $orderIds, true)) {
                return ['email' => self::emailGroup($order), 'emailStatus' => (string) ($order['emailStatus'] ?? ''),
                    'whatsapp' => self::whatsappGroup($order), 'whatsappStatus' => (string) ($order['whatsappStatus'] ?? ''), 'at' => $saved['at']];
            }
        }
        return null;
    }

    /** The five-minute job: reads PSOOLA's list when the copy is older than a minute. */
    public static function warm(?callable $fetch = null): bool
    {
        $saved = self::saved();
        if ($saved && $saved['age'] < self::KEEP_SECONDS) return false;
        self::read($fetch ?? [self::class, 'fetchAll']);
        return true;
    }

    private static function file(): string
    {
        return \Ismile\App::storage('tmp/psoola-orders-list.json');
    }

    /** The kept copy and its age in seconds, or null. */
    private static function saved(): ?array
    {
        $file = self::file();
        if (!is_file($file)) return null;
        $saved = json_decode((string) @file_get_contents($file), true);
        return is_array($saved['orders'] ?? null) && is_int($saved['at'] ?? null) ? ['orders' => $saved['orders'], 'at' => $saved['at'], 'age' => time() - (int) filemtime($file)] : null;
    }

    /**
     * @param callable(): list<array> $fetch reads every order from PSOOLA
     * @return array{orders: list<array>, at: int, stale: bool} the orders, when PSOOLA was read, and
     *         whether the page should call refreshLater() (an older copy was shown)
     */
    public static function orders(callable $fetch, bool $fresh = false): array
    {
        $saved = $fresh ? null : self::saved();
        if ($saved && $saved['age'] < self::KEEP_SECONDS) return ['orders' => $saved['orders'], 'at' => $saved['at'], 'stale' => false];
        if ($saved && $saved['age'] < self::STALE_SECONDS && function_exists('fastcgi_finish_request')) {
            return ['orders' => $saved['orders'], 'at' => $saved['at'], 'stale' => true];
        }
        return self::read($fetch) + ['stale' => false];
    }

    /** After the page is sent to the browser: read PSOOLA again (one reader at a time). */
    public static function refreshLater(callable $fetch): void
    {
        register_shutdown_function(static function () use ($fetch): void {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $lock = @fopen(self::file() . '.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;   // another visit is already reading
            try {
                $saved = self::saved();
                if (!$saved || $saved['age'] >= self::KEEP_SECONDS) self::read($fetch);
            } catch (\Throwable $error) {
                \Ismile\App::log('error', 'Psoola orders background refresh failed', ['error' => mb_substr($error->getMessage(), 0, 300)]);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        });
    }

    /** Reads PSOOLA now and keeps the copy (without ticket links or QR codes). */
    private static function read(callable $fetch): array
    {
        $file = self::file();
        $at = time();
        $json = \Ismile\Payments\PsoolaGateway::withoutLinks((string) json_encode(['orders' => array_values($fetch()), 'at' => $at], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0770, true);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';   // written whole, then swapped in: never read half-written
        if (@file_put_contents($temp, $json) !== false && !@rename($temp, $file)) @unlink($temp);
        $data = json_decode($json, true);
        return ['orders' => is_array($data['orders'] ?? null) ? $data['orders'] : [], 'at' => $at];
    }

    // ---- the filters on the Psoola orders page (owner, 19 Nov 2026) ----

    /** The filter groups and their choices, in page order. */
    public const FILTERS = [
        'reach'   => ['yes' => 'Got the QR', 'no' => 'Got no QR', 'waiting' => 'Not sure yet'],
        'email'   => ['delivered' => 'Delivered', 'missed' => 'Not received', 'waiting' => 'Still sending'],
        'whatsapp' => ['delivered' => 'Delivered', 'missed' => 'Not received', 'none' => 'Not sent', 'waiting' => 'Sent, not confirmed'],
        'channel' => ['checkout' => 'Paid on Psoola', 'direct' => 'Cash', 'complimentary' => 'Guest (free)'],
        'ticket'  => ['professional' => 'Dentist', 'student' => 'Student', 'vip' => 'VIP'],
        'door'    => ['used' => 'Came in', 'unused' => 'Not yet'],
    ];

    /**
     * The ticket email as one of three: delivered; missed (PSOOLA could not
     * deliver it: bounced, complained or failed, so the person did not get
     * the ticket email); waiting (queued, delayed or no answer yet).
     */
    /**
     * The ticket WhatsApp as one of four: delivered (delivered or read);
     * missed (PSOOLA could not deliver it); none (not asked: no WhatsApp
     * agreement, as for website buyers); waiting (pending, queued or sent).
     */
    public static function whatsappGroup(array $order): string
    {
        $status = strtolower(trim((string) ($order['whatsappStatus'] ?? '')));
        if ($status === '' || $status === 'null' || $status === 'not_requested' || $status === 'skipped') return 'none';
        if (in_array($status, ['delivered', 'read'], true)) return 'delivered';
        return in_array($status, ['failed', 'undelivered', 'rejected', 'error', 'expired'], true) || !empty($order['whatsappErrorCode']) ? 'missed' : 'waiting';
    }

    /** Did the person get the QR at all: yes (email or WhatsApp delivered), no (neither, and nothing on the way), or waiting. */
    public static function reachGroup(array $order): string
    {
        $email = self::emailGroup($order);
        $whatsapp = self::whatsappGroup($order);
        if ($email === 'delivered' || $whatsapp === 'delivered') return 'yes';
        return $email === 'missed' && in_array($whatsapp, ['missed', 'none'], true) ? 'no' : 'waiting';
    }

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
     * @return array{reach: string, email: string, whatsapp: string, channel: string, ticket: list<string>, door: string}
     */
    public static function facts(array $order, array $ourType): array
    {
        $types = [];
        foreach ((array) ($order['order']['items'] ?? []) as $item) {
            if (is_array($item) && isset($ourType[$item['ticketTypeId'] ?? ''])) $types[] = $ourType[$item['ticketTypeId']];
        }
        $used = false;
        foreach ((array) ($order['tickets'] ?? []) as $ticket) if (is_array($ticket) && !empty($ticket['checkedIn'])) $used = true;
        return ['reach' => self::reachGroup($order), 'email' => self::emailGroup($order), 'whatsapp' => self::whatsappGroup($order),
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
