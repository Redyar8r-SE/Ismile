<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Read-only access to original provider tickets. Never creates an order. */
final class PsoolaTickets
{
    public static function canView(array $user, array $registration): bool
    {
        return Auth::can($user,'registrations') && (Auth::canBookWorkshops($user) || Office::canChange($user,$registration));
    }

    public static function available(array $user, array $registration): bool
    {
        return self::canView($user,$registration) && in_array($registration['status'],['paid','complimentary'],true) && self::source((int)$registration['id']) !== null;
    }

    private static function source(int $registrationId): ?array
    {
        $guest = Db::one("SELECT * FROM sponsored_ticket_orders WHERE registration_id=? AND status='issued' AND provider_order_id IS NOT NULL",[$registrationId]);
        if ($guest) return ['id'=>$guest['provider_order_id'],'reference'=>$guest['partner_reference'],'amount'=>$guest['provider_amount'],
            'currency'=>$guest['provider_currency'],'guest'=>$guest];
        $payment = Db::one("SELECT * FROM payments WHERE registration_id=? AND gateway='psoola' AND status='paid' AND provider_payment_id IS NOT NULL ORDER BY id DESC LIMIT 1",[$registrationId]);
        if (!$payment) return null;
        return ['id'=>$payment['provider_payment_id'],'reference'=>null,'amount'=>$payment['amount_confirmed'],'currency'=>$payment['currency'],'guest'=>null];
    }

    private static function context(array $user, int $registrationId): array
    {
        $registration = Registrations::find($registrationId);
        if (!$registration) throw new UserError('Registration not found.',null,404);
        if (!self::canView($user,$registration)) throw new UserError('Your account cannot view this person\'s PSOOLA ticket.',null,403);
        if (!in_array($registration['status'],['paid','complimentary'],true)) throw new UserError('This registration is not active.');
        $ticket = Tickets::forRegistration($registrationId);
        if ($ticket && $ticket['cancelled_at'] !== null) throw new UserError('This ticket has been cancelled.');
        $source = self::source($registrationId);
        if (!$source || !$source['id']) throw new UserError('No issued PSOOLA order is linked to this registration.');
        $answer = (new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($source['id']));
        if ($answer['status'] !== 200 || !is_array($answer['json'] ?? null)) throw new UserError('PSOOLA could not return the ticket page. Try again later; do not issue another ticket.');
        $session = $answer['json'];
        self::verify($registration,$source,$session);
        return [$registration,$source,$session];
    }

    private static function verify(array $registration,array $source,array $session): void
    {
        $reference = $source['reference'] ?? $registration['ref'];
        if (($session['id'] ?? null) !== $source['id'] || ($session['partnerReference'] ?? null) !== $reference || ($session['status'] ?? null) !== 'paid') {
            throw new UserError('PSOOLA did not confirm the linked order as issued. No replacement ticket was created.');
        }
        if ($source['guest'] && (int)$source['guest']['is_complimentary']) {
            try { GuestOrders::validate($source['guest'],$session); }
            catch (\Throwable) { throw new UserError('PSOOLA could not verify this complimentary ticket. Ask the Owner to review the existing order.'); }
        } elseif ($source['amount'] === null || !is_int($session['order']['totalAmount'] ?? null)
            || $session['order']['totalAmount'] !== (int)$source['amount'] || $session['order']['totalAmount'] < 1
            || ($session['order']['currency'] ?? null) !== $source['currency'] || ($session['isComplimentary'] ?? false) === true) {
            throw new UserError('PSOOLA returned different order details. Ask the Owner to review the existing order.');
        }
    }

    public static function link(array $user,int $registrationId): array
    {
        [,,$session]=self::context($user,$registrationId);
        $url = $session['ticketsUrl'] ?? null;
        if (!self::safeUrl($url)) throw new UserError('PSOOLA has not supplied a valid private ticket-page link for this order yet. Try again later.');
        return ['ticketsUrl'=>$url];
    }

    public static function preview(array $user,int $registrationId): array
    {
        [$registration,$source,$detail]=self::context($user,$registrationId);
        $url=self::safeUrl($detail['ticketsUrl']??null)?$detail['ticketsUrl']:null;
        $eventId=(string)($source['guest']['event_id']??App::config('payments.psoola.event_id',''));
        $gateway=new PsoolaGateway();$cursor=null;$seen=[];$rich=null;$started=microtime(true);
        // The single-order endpoint omits tickets. The paginated event list includes them.
        for($page=0;$page<20 && microtime(true)-$started<18;$page++) {
            $query=['eventId'=>$eventId,'limit'=>100];if($cursor!==null)$query['cursor']=$cursor;
            $answer=$gateway->partnerRequest('GET','orders?'.http_build_query($query));
            if($answer['status']!==200 || !is_array($answer['json']['orders']??null)) break;
            foreach($answer['json']['orders'] as $candidate) {
                if(!is_array($candidate)||($candidate['id']??null)!==$source['id'])continue;
                if($rich!==null)throw new UserError('PSOOLA returned ambiguous ticket details. Ask the Owner to review this order.');
                self::verify($registration,$source,$candidate);
                if(($candidate['event']['id']??$candidate['eventId']??null)!==$eventId)throw new UserError('PSOOLA returned a ticket for a different event.');
                $rich=$candidate;
            }
            if($rich!==null)break;
            $cursor=$answer['json']['nextCursor']??null;
            if(!is_string($cursor)||$cursor===''||isset($seen[$cursor]))break;
            $seen[$cursor]=true;
        }
        if(!$rich || !is_array($rich['tickets']??null) || !$rich['tickets']) {
            if($url)return ['ticketsUrl'=>$url,'tickets'=>[]];
            throw new UserError('PSOOLA could not return the original ticket codes. Try this lookup again; do not issue another ticket.');
        }
        $count=$rich['order']['ticketCount']??null;
        if(!is_int($count)||$count<1||$count>100||count($rich['tickets'])!==$count)throw new UserError('PSOOLA returned incomplete ticket details. Try again later.');
        $tickets=[];$codes=[];
        foreach($rich['tickets'] as $ticket) {
            $code=$ticket['code']??null;
            if(!is_string($code)||trim($code)===''||strlen($code)>512||isset($codes[$code]))throw new UserError('PSOOLA returned invalid ticket codes. Ask the Owner to review this order.');
            $codes[$code]=true;
            $active=in_array($ticket['status']??null,['valid','transferred'],true);
            $payload=is_string($ticket['qrCode']??null)&&trim($ticket['qrCode'])!==''?trim($ticket['qrCode']):$code;
            if(strlen($payload)>2048)throw new UserError('PSOOLA returned an unsupported QR payload.');
            $tickets[]=['code'=>$code,'holderName'=>$ticket['holderName']??'','ticketTypeName'=>$ticket['ticketTypeName']??'',
                'status'=>$ticket['status']??'unknown','checkedIn'=>($ticket['checkedIn']??false)===true,'inside'=>($ticket['inside']??false)===true,
                'addOns'=>$ticket['addOns']??[],'qrImage'=>$active?self::qrImage($payload):null];
        }
        return ['ticketsUrl'=>self::safeUrl($rich['ticketsUrl']??null)?$rich['ticketsUrl']:$url,'tickets'=>$tickets,
            'event'=>$rich['event']['title']??'PSOOLA event','showDateTime'=>$rich['show']['dateTime']??null,
            'buyer'=>$rich['buyer']??[],'totalAmount'=>$rich['order']['totalAmount'],'currency'=>$rich['order']['currency'],
            'isComplimentary'=>($rich['isComplimentary']??false)===true,'emailStatus'=>$rich['emailStatus']??null,'whatsappStatus'=>$rich['whatsappStatus']??null];
    }

    /**
     * The active PSOOLA ticket codes of many guests (for badges): the codes the
     * 5-minute job saved, and for anyone not saved yet, one live pass over
     * each event's order list with the same checks as the ticket preview.
     * All or nothing: if any guest has no active code, nothing is returned
     * and the names are listed.
     *
     * @param list<array> $registrations
     * @return array<int, list<string>> registration id => codes
     */
    public static function codesFor(array $user, array $registrations): array
    {
        foreach ($registrations as $registration) {
            if (!self::canView($user, $registration)) throw new UserError('Your account cannot view the PSOOLA ticket of ' . Registrations::fullName($registration) . '.');
        }
        $codes = self::savedCodes($registrations);
        $need = array_values(array_filter($registrations, static fn (array $r): bool => !isset($codes[(int) $r['id']])));
        if ($need) {
            $live = self::liveCodes($need, true);
            self::saveCodes($live);
            $codes += $live;
        }
        $missing = [];
        foreach ($registrations as $registration) {
            if (!isset($codes[(int) $registration['id']])) $missing[] = Registrations::fullName($registration);
        }
        if ($missing) {
            $names = implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' and ' . (count($missing) - 8) . ' more' : '');
            throw new UserError('PSOOLA has no active ticket code for: ' . $names . '. Nothing was downloaded.');
        }
        return $codes;
    }

    /**
     * The 5-minute job: fetches and saves the codes of guests who have none
     * saved yet (new registrations); with $all, refreshes every guest.
     *
     * @return array{checked: int, saved: int}
     */
    public static function syncCodes(bool $all = false): array
    {
        $rows = Db::all("SELECT r.* FROM registrations r JOIN tickets t ON t.registration_id = r.id
            WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL"
            . ($all ? '' : ' AND NOT EXISTS (SELECT 1 FROM badge_ticket_codes c WHERE c.registration_id = r.id)'));
        $live = $rows ? self::liveCodes($rows, false) : [];
        self::saveCodes($live);
        if ($all) {
            // A guest whose PSOOLA ticket is no longer active keeps no stale code.
            $stale = array_diff(array_map(static fn (array $r): int => (int) $r['id'], $rows), array_keys($live));
            foreach ($stale as $id) Db::run('DELETE FROM badge_ticket_codes WHERE registration_id = ?', [$id]);
        }
        Db::run("DELETE c FROM badge_ticket_codes c JOIN registrations r ON r.id = c.registration_id LEFT JOIN tickets t ON t.registration_id = r.id
            WHERE r.status NOT IN ('paid','complimentary') OR t.id IS NULL OR t.cancelled_at IS NOT NULL");
        return ['checked' => count($rows), 'saved' => count($live)];
    }

    /** How many guests already have their code saved. */
    public static function savedCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM badge_ticket_codes');
    }

    /** Saved codes of the guests whose ticket is still active. */
    private static function savedCodes(array $registrations): array
    {
        $active = [];
        foreach ($registrations as $registration) {
            $ticket = Tickets::forRegistration((int) $registration['id']);
            if (in_array($registration['status'], ['paid', 'complimentary'], true) && $ticket && $ticket['cancelled_at'] === null) $active[] = (int) $registration['id'];
        }
        if (!$active) return [];
        $codes = [];
        foreach (Db::all('SELECT registration_id, codes FROM badge_ticket_codes WHERE registration_id IN (' . implode(',', array_fill(0, count($active), '?')) . ')', $active) as $row) {
            $list = json_decode((string) $row['codes'], true);
            if (is_array($list) && $list && array_filter($list, 'is_string') === $list) $codes[(int) $row['registration_id']] = array_values($list);
        }
        return $codes;
    }

    /** @param array<int, list<string>> $codes */
    private static function saveCodes(array $codes): void
    {
        foreach ($codes as $id => $list) {
            Db::run('INSERT INTO badge_ticket_codes (registration_id, codes, synced_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE codes = VALUES(codes), synced_at = VALUES(synced_at)',
                [$id, json_encode(array_values($list)), App::now()]);
        }
    }

    /**
     * One pass over each event's PSOOLA order list. Strict (a download): any
     * problem stops everything. Otherwise (the background job) a guest whose
     * order does not check out is skipped and logged; the others are kept.
     *
     * @return array<int, list<string>>
     */
    private static function liveCodes(array $registrations, bool $strict): array
    {
        $wanted = [];
        foreach ($registrations as $registration) {
            $ticket = Tickets::forRegistration((int) $registration['id']);
            $source = in_array($registration['status'], ['paid', 'complimentary'], true) && $ticket && $ticket['cancelled_at'] === null ? self::source((int) $registration['id']) : null;
            if (!$source || !$source['id']) continue;
            $eventId = (string) ($source['guest']['event_id'] ?? App::config('payments.psoola.event_id', ''));
            $wanted[$eventId][(string) $source['id']] = [$registration, $source];
        }
        $codes = [];
        $gateway = new PsoolaGateway();
        foreach ($wanted as $eventId => $orders) {
            $cursor = null;
            $seen = [];
            $started = microtime(true);
            for ($page = 0; $page < 50 && $orders && microtime(true) - $started < 90; $page++) {
                $query = ['eventId' => $eventId, 'limit' => 100];
                if ($cursor !== null) $query['cursor'] = $cursor;
                $answer = $gateway->partnerRequest('GET', 'orders?' . http_build_query($query));
                if ($answer['status'] !== 200 || !is_array($answer['json']['orders'] ?? null)) throw new UserError('PSOOLA could not return the ticket list. Try again in a moment; nothing was downloaded.');
                foreach ($answer['json']['orders'] as $candidate) {
                    $id = is_array($candidate) ? ($candidate['id'] ?? null) : null;
                    if (!is_string($id) || !isset($orders[$id])) continue;
                    [$registration, $source] = $orders[$id];
                    unset($orders[$id]);
                    try {
                        self::verify($registration, $source, $candidate);
                        if (($candidate['event']['id'] ?? $candidate['eventId'] ?? null) !== $eventId) throw new UserError('PSOOLA returned a ticket for a different event.');
                        $list = self::activeCodes($candidate);
                    } catch (UserError $error) {
                        if ($strict) throw $error;
                        App::log('warning', 'Badge code not saved', ['registration' => (int) $registration['id'], 'reason' => $error->key]);
                        continue;
                    }
                    if ($list) $codes[(int) $registration['id']] = $list;
                }
                $cursor = $answer['json']['nextCursor'] ?? null;
                if (!is_string($cursor) || $cursor === '' || isset($seen[$cursor])) break;
                $seen[$cursor] = true;
            }
        }
        return $codes;
    }

    /** The codes of an order's valid tickets, checked like the preview. */
    private static function activeCodes(array $order): array
    {
        $tickets = $order['tickets'] ?? null;
        $count = $order['order']['ticketCount'] ?? null;
        if (!is_array($tickets) || !is_int($count) || $count < 1 || $count > 100 || count($tickets) !== $count) {
            throw new UserError('PSOOLA returned incomplete ticket details. Try again later.');
        }
        $codes = [];
        foreach ($tickets as $ticket) {
            $code = is_array($ticket) ? ($ticket['code'] ?? null) : null;
            if (!is_string($code) || trim($code) === '' || strlen($code) > 512 || in_array($code, $codes, true)) throw new UserError('PSOOLA returned invalid ticket codes. Ask the Owner to review this order.');
            if (in_array($ticket['status'] ?? null, ['valid', 'transferred'], true)) $codes[] = $code;
        }
        return $codes;
    }

    private static function qrImage(string $payload): string
    {
        require_once dirname(__DIR__).'/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
        // Encode PSOOLA's exact original payload, with its medium ECC and four-module quiet zone.
        $matrix=(new \TCPDF2DBarcode($payload,'QRCODE,M'))->getBarcodeArray();
        $size=$matrix['num_rows']??0;
        if($size<21||$size>177||$size!==($matrix['num_cols']??0))throw new UserError('The original PSOOLA QR could not be displayed.');
        $path='';foreach($matrix['bcode'] as $y=>$row)foreach($row as $x=>$black)if($black)$path.='M'.($x+4).' '.($y+4).'h1v1h-1z';
        $side=$size+8;
        return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$side.' '.$side.'" shape-rendering="crispEdges"><path fill="white" d="M0 0h'.$side.'v'.$side.'H0z"/><path fill="black" d="'.$path.'"/></svg>');
    }

    public static function safeUrl(mixed $url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url)>4096 || preg_match('/[\x00-\x20\x7f]/',$url) || str_contains($url,'\\')) return false;
        $parts = parse_url($url);
        if (!is_array($parts)) return false;
        $host = strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '') === 'https' && ($host === 'psoola.com' || str_ends_with($host,'.psoola.com'))
            && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443);
    }
}
