<?php
declare(strict_types=1);
namespace Ismile;

/**
 * Speaker tickets: speakers an Owner registers one by one on Event operations
 * › Speaker tickets. Each speaker gets a free (complimentary) PSOOLA guest
 * ticket, the same as Guest tickets: 0 IQD, and PSOOLA sends the QR by email
 * and WhatsApp. Lunch days chosen on the form go to PSOOLA as free add-ons on
 * the same ticket. This class only remembers which guest-ticket requests are
 * speakers (speaker_tickets.attempt_key), so the page can list them.
 */
final class SpeakerTickets
{
    public const REASON = 'Speaker at the iSmile summit';
    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A to Z', 'name_desc' => 'Name Z to A'];

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
        Registrations::genderAge($in);
        if (($in['consent'] ?? '') !== '1') {
            throw new UserError('Tick that the speaker agreed to get the ticket by WhatsApp.');
        }
        $addOns = self::lunchAddOns($in);
        $guest = ['complimentary_reason' => self::REASON, 'delivery_confirmation' => 'email-whatsapp-v1'] + $in;
        Db::run('INSERT IGNORE INTO speaker_tickets (attempt_key, added_by, created_at) VALUES (?, ?, ?)', [$key, (int) $user['id'], App::now()]);
        try {
            $order = GuestOrders::issueSingle($user, $guest, $addOns);
        } catch (\Throwable $error) {
            // Nothing was saved for PSOOLA: the tag goes too, so the list never
            // shows a speaker with no ticket request. A saved request stays (Retry).
            if (!Db::value('SELECT id FROM sponsored_ticket_orders WHERE attempt_key = ?', [$key])) {
                Db::run('DELETE FROM speaker_tickets WHERE attempt_key = ?', [$key]);
            }
            throw $error;
        }
        Audit::log((int) $user['id'], 'speaker_ticket.add', 'sponsored_ticket_order', (int) $order['id'], ['ref' => $order['partner_reference'], 'lunch' => count($addOns)]);
        return $order;
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
                    o.id AS order_id, o.partner_reference AS ref, o.status AS order_status, o.registration_id, o.guest_json, o.request_json,
                    o.email_status, o.whatsapp_status, o.last_error, o.retry_after,
                    r.status, r.first_name, r.father_name, r.grandfather_name, r.phone, r.email, r.ticket_type, r.specialty, r.lunch_day1, r.lunch_day2
               FROM speaker_tickets st
               JOIN sponsored_ticket_orders o ON o.attempt_key = st.attempt_key
               LEFT JOIN registrations r ON r.id = o.registration_id
               LEFT JOIN admin_users ab ON ab.id = st.added_by
              ORDER BY st.id DESC"
        );
        $addOns = (array) App::config('payments.psoola.add_ons', []);
        $types = (array) App::config('payments.psoola.ticket_types', []);
        foreach ($rows as &$row) {
            if ($row['registration_id'] !== null) continue;
            // Not registered yet (PSOOLA has not confirmed): the details from the saved request.
            $guest = json_decode((string) $row['guest_json'], true) ?: [];
            $request = json_decode((string) $row['request_json'], true) ?: [];
            $parts = array_pad(preg_split('/\s+/u', (string) ($guest['buyer_name'] ?? ''), 3) ?: [], 3, '');
            $item = $request['items'][0] ?? [];
            $chosen = (array) ($item['addOnIds'] ?? []);
            $row = array_replace($row, [
                'first_name' => $parts[0], 'father_name' => $parts[1], 'grandfather_name' => $parts[2],
                'phone' => $guest['phone'] ?? '', 'email' => $guest['email'] ?? '',
                'ticket_type' => array_search($item['ticketTypeId'] ?? null, $types, true) ?: 'professional',
                'lunch_day1' => isset($addOns['lunchDay1']) && in_array($addOns['lunchDay1'], $chosen, true) ? 1 : 0,
                'lunch_day2' => isset($addOns['lunchDay2']) && in_array($addOns['lunchDay2'], $chosen, true) ? 1 : 0,
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
