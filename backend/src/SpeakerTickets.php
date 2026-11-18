<?php
declare(strict_types=1);
namespace Ismile;

/**
 * Speaker tickets: speakers an Owner registers one by one on Event operations
 * › Speaker tickets. Each speaker gets a free (complimentary) PSOOLA guest
 * ticket, the same as Guest tickets: 0 IQD, and PSOOLA sends the QR by email
 * and WhatsApp. The lunch days ticked on the form are added to that ticket
 * right after it is issued, free, with PSOOLA's add-on request
 * (POST orders/{order}/tickets/{code}/add-ons, complimentary: true). A lunch
 * counts in our database only once PSOOLA shows it on the ticket.
 * speaker_tickets remembers which guest-ticket requests are speakers, the lunch
 * days ticked, and why a lunch was not added yet.
 */
final class SpeakerTickets
{
    public const REASON = 'Speaker at the iSmile summit';
    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A to Z', 'name_desc' => 'Name Z to A'];

    /** A registration that came from Speaker tickets (its free ticket's reason says so). */
    public static function isSpeaker(array $person): bool
    {
        return ($person['status'] ?? '') === 'complimentary' && ($person['comp_reason'] ?? null) === self::REASON;
    }

    /** Only Owners add speakers. */
    public static function canAdd(array $user): bool
    {
        return ($user['role'] ?? '') === 'owner';
    }

    /**
     * Registers one speaker with a free PSOOLA ticket (and free lunch on the
     * days ticked). The tag is saved before PSOOLA is asked, so a ticket PSOOLA
     * confirms on a retry is still a speaker. Returns the guest-ticket order.
     */
    public static function add(array $in, array $user): array
    {
        if (!self::canAdd($user)) {
            throw new UserError('Only an Owner can add speakers.');
        }
        $key = $in['attempt'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new UserError('Reload the Speaker tickets form before adding.');
        }
        // Speakers: the gender is asked, the age is not.
        if (!in_array($in['gender'] ?? null, Registrations::GENDERS, true)) {
            throw new UserError('Choose the gender.');
        }
        $in['age'] = '';
        if (($in['consent'] ?? '') !== '1') {
            throw new UserError('Tick that the speaker agreed to get the ticket by WhatsApp.');
        }
        $addOns = self::lunchAddOns($in);   // checked before PSOOLA is asked
        $guest = ['complimentary_reason' => self::REASON, 'delivery_confirmation' => 'email-whatsapp-v1'] + $in;
        Db::run('INSERT IGNORE INTO speaker_tickets (attempt_key, added_by, lunch_day1, lunch_day2, created_at) VALUES (?, ?, ?, ?, ?)',
            [$key, (int) $user['id'], ($in['lunch_day1'] ?? '') === '1' ? 1 : 0, ($in['lunch_day2'] ?? '') === '1' ? 1 : 0, App::now()]);
        try {
            // The ticket alone: the free lunch is added to it afterwards (addLunch).
            $order = GuestOrders::issueSingle($user, $guest);
        } catch (\Throwable $error) {
            // Nothing was saved for PSOOLA: the tag goes too, so the list never
            // shows a speaker with no ticket request. A saved request stays (Retry).
            if (!Db::value('SELECT id FROM sponsored_ticket_orders WHERE attempt_key = ?', [$key])) {
                Db::run('DELETE FROM speaker_tickets WHERE attempt_key = ?', [$key]);
            }
            throw $error;
        }
        Audit::log((int) $user['id'], 'speaker_ticket.add', 'sponsored_ticket_order', (int) $order['id'], ['ref' => $order['partner_reference'], 'lunch' => count($addOns)]);
        if ($order['status'] === 'issued' && $addOns) self::lunchAfterTicket($key, $user, (int) $order['id']);
        return $order;
    }

    /** Adds the ticked lunch days after the ticket; a failure leaves the ticket and shows Add lunch again. */
    public static function lunchAfterTicket(string $key, array $user, int $orderId): void
    {
        try {
            self::addLunch($key, $user);
        } catch (\Throwable $error) {
            App::log('error', 'Speaker free lunch failed', ['order' => $orderId, 'error' => mb_substr($error->getMessage(), 0, 300)]);
            Db::run('UPDATE speaker_tickets SET lunch_error = ? WHERE attempt_key = ?', [mb_substr($error instanceof UserError ? $error->key : 'Something went wrong while adding the lunch. Press Add lunch again.', 0, 255), $key]);
        }
    }

    /**
     * Adds the free lunch days ticked for this speaker to their PSOOLA ticket.
     * Safe to press again: a day already on the ticket is not sent twice. A day
     * counts (registrations.lunch_dayN = 1) only when PSOOLA's order shows it.
     *
     * @return array{added: list<int>, missing: list<int>, error: ?string}
     */
    public static function addLunch(string $key, array $user): array
    {
        if (!self::canAdd($user)) {
            throw new UserError('Only an Owner can add speakers.');
        }
        $speaker = Db::one('SELECT st.*, o.id AS order_id, o.status AS order_status, o.provider_order_id, o.registration_id, r.lunch_day1 AS has_day1, r.lunch_day2 AS has_day2
            FROM speaker_tickets st JOIN sponsored_ticket_orders o ON o.attempt_key = st.attempt_key LEFT JOIN registrations r ON r.id = o.registration_id
            WHERE st.attempt_key = ?', [$key]);
        if (!$speaker) throw new UserError('That speaker was not found. Reload the page.');
        if ($speaker['order_status'] !== 'issued' || !$speaker['provider_order_id'] || $speaker['registration_id'] === null || $speaker['has_day1'] === null) {
            throw new UserError("PSOOLA has not issued this speaker's ticket yet. Press Retry first; the lunch is added after the ticket.");
        }
        $days = array_values(array_filter([1, 2], static fn (int $d): bool => (int) $speaker['lunch_day' . $d] === 1 && (int) $speaker['has_day' . $d] === 0));
        if (!$days) return ['added' => [], 'missing' => [], 'error' => null];
        $byDay = array_combine($days, self::lunchAddOns(array_fill_keys(array_map(static fn (int $d): string => 'lunch_day' . $d, $days), '1')));
        $gateway = new Payments\PsoolaGateway();
        $path = 'orders/' . rawurlencode((string) $speaker['provider_order_id']);
        $ticket = self::lunchTicket($gateway, $path);
        $error = null;
        foreach ($byDay as $day => $addOnId) {
            if (in_array($addOnId, $ticket['addOnIds'], true)) continue;   // already on the ticket
            try {
                $answer = $gateway->partnerRequest('POST', $path . '/tickets/' . rawurlencode($ticket['code']) . '/add-ons',
                    ['addOnId' => $addOnId, 'complimentary' => true], ['Idempotency-Key: ismile-speaker-lunch-' . $key . '-' . $day]);
            } catch (\Throwable) {
                $answer = ['status' => 0, 'json' => null];
            }
            if (!in_array($answer['status'], [200, 201], true)) {
                $message = is_string($answer['json']['error']['message'] ?? null) ? mb_substr($answer['json']['error']['message'], 0, 160) : null;
                $error = 'PSOOLA did not add lunch day ' . $day . match (true) {
                    $answer['status'] === 429 => ': it asked us to wait. Press Add lunch again in a minute.',
                    $answer['status'] === 0 => ': PSOOLA did not answer. Press Add lunch again.',
                    $message !== null => ': ' . rtrim($message, '.') . '.',
                    default => ' (HTTP ' . $answer['status'] . ').',
                };
            }
        }
        // What PSOOLA's order now shows is what counts.
        $ticket = self::lunchTicket($gateway, $path);
        $added = $missing = [];
        foreach ($byDay as $day => $addOnId) {
            if (in_array($addOnId, $ticket['addOnIds'], true)) $added[] = $day; else $missing[] = $day;
        }
        if ($added && $ticket['changesTotal'] !== 0) {
            $error = 'PSOOLA added the lunch but did not show it as free (lunch total ' . ($ticket['changesTotal'] === null ? 'not shown' : number_format($ticket['changesTotal']) . ' IQD') . '). Check this ticket with PSOOLA.';
        } elseif ($missing && $error === null) {
            $error = 'PSOOLA did not show lunch day ' . implode(' and ', $missing) . ' on the ticket. Press Add lunch again.';
        }
        Db::transaction(static function () use ($speaker, $added, $error, $key, $ticket): void {
            foreach ($added as $day) Db::run('UPDATE registrations SET lunch_day' . $day . ' = 1, updated_at = ? WHERE id = ?', [App::now(), (int) $speaker['registration_id']]);
            Db::run('UPDATE speaker_tickets SET lunch_error = ? WHERE attempt_key = ?', [$error === null ? null : mb_substr($error, 0, 255), $key]);
            // The QR email and WhatsApp status as PSOOLA shows them now.
            $sent = array_filter(['email_status' => $ticket['emailStatus'], 'whatsapp_status' => $ticket['whatsappStatus']], static fn ($v): bool => $v !== null);
            if ($sent) Db::update('sponsored_ticket_orders', $sent + ['updated_at' => App::now()], 'id = ?', [(int) $speaker['order_id']]);
        });
        if ($added) Audit::log((int) $user['id'], 'speaker_ticket.lunch', 'sponsored_ticket_order', (int) $speaker['order_id'], ['days' => $added, 'free' => $ticket['changesTotal'] === 0]);
        return ['added' => $added, 'missing' => $missing, 'error' => $error];
    }

    /** The speaker's one PSOOLA ticket: its code, the add-ons on it and the order's add-on total. */
    private static function lunchTicket(Payments\PsoolaGateway $gateway, string $path): array
    {
        $answer = $gateway->partnerRequest('GET', $path);
        $order = $answer['json'] ?? null;
        if ($answer['status'] !== 200 || !is_array($order)) {
            throw new UserError("PSOOLA did not return the speaker's ticket" . ($answer['status'] === 429 ? ' (it asked us to wait)' : '') . '. Press Add lunch again in a minute.');
        }
        $tickets = array_values(array_filter((array) ($order['tickets'] ?? []), static fn ($t): bool => is_array($t) && is_string($t['code'] ?? null) && $t['code'] !== '' && ($t['status'] ?? '') !== 'cancelled'));
        if (count($tickets) !== 1) throw new UserError("PSOOLA did not show exactly one ticket on this speaker's order, so no lunch was sent.");
        $ids = [];
        foreach (array_merge((array) ($tickets[0]['addOnItems'] ?? []), (array) ($tickets[0]['addOns'] ?? [])) as $item) {
            $id = is_array($item) ? ($item['addOnId'] ?? $item['id'] ?? null) : $item;
            if (is_string($id)) $ids[] = $id;
        }
        foreach ((array) ($order['order']['items'] ?? []) as $line) {
            foreach ((array) ($line['addOns'] ?? []) as $item) {
                if (is_string($item['addOnId'] ?? null)) $ids[] = $item['addOnId'];
            }
        }
        $total = $order['order']['addOnChangesTotal'] ?? null;
        $status = static fn ($v): ?string => is_string($v) && $v !== '' ? mb_substr($v, 0, 40) : null;
        return ['code' => $tickets[0]['code'], 'addOnIds' => array_values(array_unique($ids)), 'changesTotal' => is_int($total) ? $total : null,
            'emailStatus' => $status($order['emailStatus'] ?? null), 'whatsappStatus' => $status($order['whatsappStatus'] ?? null)];
    }

    /** The ticked lunch days PSOOLA has not added yet (only for an issued speaker). */
    public static function lunchMissing(array $speaker): array
    {
        if (self::state($speaker) !== 'registered') return [];
        return array_values(array_filter([1, 2], static fn (int $d): bool => (int) ($speaker['want_day' . $d] ?? 0) === 1 && (int) $speaker['lunch_day' . $d] === 0));
    }

    /** PSOOLA's lunch add-on ids for the ticked days (free on a complimentary ticket). */
    private static function lunchAddOns(array $in): array
    {
        $config = (array) App::config('payments.psoola.add_ons', []);
        $out = [];
        foreach ([1, 2] as $day) {
            if (($in['lunch_day' . $day] ?? '') !== '1') continue;
            $id = $config['lunchDay' . $day] ?? null;
            if (!is_string($id) || $id === '') {
                throw new UserError('Lunch day ' . $day . ' is not set up for PSOOLA on the server, so nothing was sent. Untick it or ask the Owner to check the PSOOLA lunch settings.');
            }
            $out[] = $id;
        }
        return $out;
    }

    /** Every speaker request, newest first, with the guest's registration when PSOOLA issued it. */
    public static function all(): array
    {
        $rows = Db::all(
            "SELECT st.id AS speaker_id, st.attempt_key, st.added_by, st.created_at AS added_at, ab.name AS added_by_name,
                    st.lunch_day1 AS want_day1, st.lunch_day2 AS want_day2, st.lunch_error,
                    o.id AS order_id, o.partner_reference AS ref, o.status AS order_status, o.registration_id, o.guest_json, o.request_json,
                    o.email_status, o.whatsapp_status, o.last_error, o.retry_after,
                    r.status, r.first_name, r.father_name, r.grandfather_name, r.phone, r.email, r.ticket_type, r.specialty, r.lunch_day1, r.lunch_day2
               FROM speaker_tickets st
               JOIN sponsored_ticket_orders o ON o.attempt_key = st.attempt_key
               LEFT JOIN registrations r ON r.id = o.registration_id
               LEFT JOIN admin_users ab ON ab.id = st.added_by
              ORDER BY st.id DESC"
        );
        $types = (array) App::config('payments.psoola.ticket_types', []);
        foreach ($rows as &$row) {
            if ($row['registration_id'] !== null) continue;
            // Not registered yet (PSOOLA has not confirmed): the details from the saved request.
            $guest = json_decode((string) $row['guest_json'], true) ?: [];
            $request = json_decode((string) $row['request_json'], true) ?: [];
            $parts = array_pad(preg_split('/\s+/u', (string) ($guest['buyer_name'] ?? ''), 3) ?: [], 3, '');
            $item = $request['items'][0] ?? [];
            // No lunch is on the ticket before PSOOLA issues it.
            $row = array_replace($row, [
                'first_name' => $parts[0], 'father_name' => $parts[1], 'grandfather_name' => $parts[2],
                'phone' => $guest['phone'] ?? '', 'email' => $guest['email'] ?? '',
                'ticket_type' => array_search($item['ticketTypeId'] ?? null, $types, true) ?: 'professional',
                'lunch_day1' => 0,
                'lunch_day2' => 0,
            ]);
        }
        unset($row);
        return $rows;
    }

    /**
     * registered: PSOOLA issued the free ticket (QR sent by email and WhatsApp).
     * cancelled: the Owner cancelled the registration.
     * waiting: saved, PSOOLA has not confirmed yet (press Retry).
     * rejected: PSOOLA refused it (see the reason).
     * none: issued but the person was deleted for good (not shown).
     */
    public static function state(array $speaker): string
    {
        if ($speaker['order_status'] === 'issued') {
            if ($speaker['registration_id'] === null || $speaker['status'] === null) return 'none';
            return $speaker['status'] === 'cancelled' ? 'cancelled' : 'registered';
        }
        return $speaker['order_status'] === 'rejected' ? 'rejected' : 'waiting';
    }

    /** The page's search and filters, checked (unknown values are ignored). */
    public static function filters(array $in): array
    {
        $pick = static fn (string $key, array $allowed): string => is_string($in[$key] ?? null) && in_array($in[$key], $allowed, true) ? $in[$key] : '';
        return [
            'q' => is_string($in['q'] ?? null) ? mb_substr(trim($in['q']), 0, 120) : '',
            'ticket' => $pick('ticket', ['professional', 'student']),
            'lunch' => $pick('lunch', ['day1', 'day2', 'both', 'none']),
            'state' => $pick('state', ['registered', 'waiting', 'rejected', 'cancelled']),
            'sort' => $pick('sort', array_keys(self::SORTS)) ?: 'newest',
        ];
    }

    /** The speakers that match the search and filters, in the chosen order. */
    public static function filter(array $speakers, array $f): array
    {
        $out = [];
        foreach ($speakers as $speaker) {
            $state = self::state($speaker);
            if ($state === 'none') continue;
            if ($f['q'] !== '' && !AssociationGuests::matches($speaker, $f['q'])) continue;
            if ($f['ticket'] !== '' && $speaker['ticket_type'] !== $f['ticket']) continue;
            if ($f['lunch'] !== '') {
                [$one, $two] = [(int) $speaker['lunch_day1'], (int) $speaker['lunch_day2']];
                if (!match ($f['lunch']) { 'day1' => $one === 1, 'day2' => $two === 1, 'both' => $one + $two === 2, default => $one + $two === 0 }) continue;
            }
            if ($f['state'] !== '' && $state !== $f['state']) continue;
            $out[] = $speaker;
        }
        $name = static fn (array $s): string => mb_strtolower(Registrations::fullName($s));
        usort($out, match ($f['sort']) {
            'oldest' => static fn (array $a, array $b): int => (int) $a['speaker_id'] <=> (int) $b['speaker_id'],
            'name' => static fn (array $a, array $b): int => [$name($a), (int) $a['speaker_id']] <=> [$name($b), (int) $b['speaker_id']],
            'name_desc' => static fn (array $a, array $b): int => [$name($b), (int) $b['speaker_id']] <=> [$name($a), (int) $a['speaker_id']],
            default => static fn (array $a, array $b): int => (int) $b['speaker_id'] <=> (int) $a['speaker_id'],
        });
        return $out;
    }

    /** @return array{speakers: int, lunch1: int, lunch2: int, sent: int, waiting: int} */
    public static function totals(array $speakers): array
    {
        $out = ['speakers' => 0, 'lunch1' => 0, 'lunch2' => 0, 'sent' => 0, 'waiting' => 0];
        foreach ($speakers as $speaker) {
            $state = self::state($speaker);
            if ($state === 'waiting') $out['waiting']++;
            if ($state !== 'registered') continue;
            $out['speakers']++;
            $out['lunch1'] += (int) $speaker['lunch_day1'];
            $out['lunch2'] += (int) $speaker['lunch_day2'];
            if (in_array(strtolower((string) $speaker['email_status']), ['sent', 'delivered'], true) || in_array(strtolower((string) $speaker['whatsapp_status']), ['sent', 'delivered', 'read'], true)) $out['sent']++;
        }
        return $out;
    }

    /** "Day 1 and Day 2", "Day 1", "No lunch". */
    public static function lunchText(array $speaker): string
    {
        $days = array_values(array_filter([(int) $speaker['lunch_day1'] ? 'Day 1' : null, (int) $speaker['lunch_day2'] ? 'Day 2' : null]));
        return $days ? implode(' and ', $days) : 'No lunch';
    }

    /** Rows for the speaker list PDF: every registered speaker, A to Z. */
    public static function reportRows(): array
    {
        $speakers = self::filter(self::all(), self::filters(['state' => 'registered', 'sort' => 'name']));
        $rows = [['Speaker tickets, ' . count($speakers) . (count($speakers) === 1 ? ' speaker' : ' speakers') . ', all free']];
        $rows[] = ['Full name', 'Phone', 'Email', 'Ticket', 'Lunch (free)', 'Reference', 'QR email', 'QR WhatsApp', 'Added'];
        foreach ($speakers as $speaker) {
            $rows[] = [Registrations::fullName($speaker), (string) $speaker['phone'], (string) $speaker['email'], Registrations::ticketName($speaker['ticket_type']) . ' (free)',
                self::lunchText($speaker), (string) $speaker['ref'], ucfirst((string) ($speaker['email_status'] ?: 'Not known')), ucfirst((string) ($speaker['whatsapp_status'] ?: 'Not known')),
                substr((string) $speaker['added_at'], 0, 16)];
        }
        return $rows;
    }
}
