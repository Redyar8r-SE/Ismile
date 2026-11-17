<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/**
 * Speakers: the Owner gives each speaker a free summit ticket from the
 * Speakers page. It is an ordinary complimentary PSOOLA order (GuestOrders):
 * PSOOLA issues the ticket, emails the QR and, when the speaker agreed, sends
 * it on WhatsApp too. Lunch on Day 1 and Day 2 can ride on the same free
 * order. This class only remembers which order belongs to which speaker, so
 * the page can list them and the badge can say "Speaker".
 */
final class SpeakerGuests
{
    /** The complimentary reason on every speaker ticket; the badge reads it. */
    public const REASON = 'iSmile 2026 speaker';

    public static function canRegister(array $user): bool
    {
        return ($user['role'] ?? '') === 'owner';
    }

    /** The speaker's guest record: the badge says "Speaker" instead of the ticket. */
    public static function isSpeaker(array $registration): bool
    {
        return ($registration['comp_reason'] ?? null) === self::REASON;
    }

    /**
     * The speakers on the website (data/speakers.json), by English name:
     * name, role, topic and photo. The server copies the file from the website
     * every minute, so a speaker added there appears here too.
     *
     * @return array<string, array{name: string, role: string, topic: string, photo: ?string}>
     */
    public static function website(): array
    {
        $out = [];
        foreach (SiteData::read('speakers') as $speaker) {
            if (!is_array($speaker)) continue;
            $name = self::english($speaker['name'] ?? '');
            if ($name === '' || isset($out[$name])) continue;
            $photo = is_string($speaker['photo'] ?? null) && preg_match('#^assets/[A-Za-z0-9/_.-]+$#', $speaker['photo']) ? $speaker['photo'] : null;
            $out[$name] = ['name' => $name, 'role' => self::english($speaker['role'] ?? ''), 'topic' => self::english($speaker['topic'] ?? ''), 'photo' => $photo];
        }
        return $out;
    }

    private static function english(mixed $value): string
    {
        if (is_array($value)) $value = $value['en'] ?? reset($value) ?: '';
        return is_string($value) ? trim($value) : '';
    }

    /** Every speaker ticket, newest first, with its PSOOLA order and guest record. */
    public static function all(): array
    {
        return Db::all("SELECT g.id, g.ref, g.speaker_name, g.added_by, g.created_at, u.name AS added_by_name,
                o.id AS order_id, o.status AS order_status, o.email_status, o.whatsapp_status, o.last_error, o.guest_json, o.request_json,
                o.registration_id, r.lunch_day1, r.lunch_day2, r.status AS registration_status
            FROM speaker_guests g
            LEFT JOIN sponsored_ticket_orders o ON o.partner_reference = g.ref
            LEFT JOIN admin_users u ON u.id = g.added_by
            LEFT JOIN registrations r ON r.id = o.registration_id
            ORDER BY g.created_at DESC, g.id DESC");
    }

    /** 'issued' (ticket sent), 'waiting' (PSOOLA not sure yet: retry), 'refused'. */
    public static function state(array $row): string
    {
        return match ((string) ($row['order_status'] ?? '')) {
            'issued' => 'issued',
            'rejected' => 'refused',
            default => 'waiting',
        };
    }

    /** The guest details saved with the order: name on the ticket, email, phone. */
    public static function guest(array $row): array
    {
        $guest = json_decode((string) ($row['guest_json'] ?? ''), true);
        return is_array($guest) ? $guest : [];
    }

    /** The lunch days asked for on the order (also when PSOOLA has not answered yet). */
    public static function lunch(array $row): array
    {
        $request = json_decode((string) ($row['request_json'] ?? ''), true);
        $chosen = (array) ($request['items'][0]['addOnIds'] ?? []);
        $ids = (array) App::config('payments.psoola.add_ons', []);
        return [
            'day1' => isset($ids['lunchDay1']) && in_array($ids['lunchDay1'], $chosen, true),
            'day2' => isset($ids['lunchDay2']) && in_array($ids['lunchDay2'], $chosen, true),
        ];
    }

    /**
     * Registers one speaker: a free summit ticket (Dentist ticket, price 0)
     * with the chosen lunch days, emailed by PSOOLA and sent on WhatsApp when
     * the speaker agreed. The same form sent again (same attempt) only asks
     * PSOOLA again for that one order; it never makes a second ticket.
     */
    public static function register(array $user, array $in): array
    {
        if (!self::canRegister($user)) {
            throw new UserError('Only the Owner can register speakers.', null, 403);
        }
        $speaker = Validate::text($in['speaker_name'] ?? '', 121);
        if ($speaker === '' || mb_strlen($speaker) > 120) {
            throw new UserError('Choose the speaker or type the speaker name.');
        }
        $key = (string) ($in['attempt'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new UserError('Reload the Speakers page before registering.');
        }
        $retry = Db::one('SELECT partner_reference FROM sponsored_ticket_orders WHERE attempt_key = ?', [$key]);
        if (!$retry) {
            $taken = Db::one("SELECT g.ref FROM speaker_guests g LEFT JOIN sponsored_ticket_orders o ON o.partner_reference = g.ref
                WHERE g.speaker_name = ? AND COALESCE(o.status, '') <> 'rejected' LIMIT 1", [$speaker]);
            if ($taken) {
                throw new UserError($speaker . ' already has a speaker ticket (' . $taken['ref'] . '). Open it in the list below.');
            }
        }
        $ticketName = Validate::text($in['ticket_name'] ?? '', 121);
        if ($ticketName === '') $ticketName = $speaker;
        $ids = (array) App::config('payments.psoola.add_ons', []);
        $lunch = [];
        if (($in['lunch_day1'] ?? '') === '1') {
            if (empty($ids['lunchDay1'])) throw new UserError('Lunch on Day 1 is not set up for PSOOLA. Untick it or ask the Owner to check the PSOOLA settings.');
            $lunch[] = $ids['lunchDay1'];
        }
        if (($in['lunch_day2'] ?? '') === '1') {
            if (empty($ids['lunchDay2'])) throw new UserError('Lunch on Day 2 is not set up for PSOOLA. Untick it or ask the Owner to check the PSOOLA settings.');
            $lunch[] = $ids['lunchDay2'];
        }
        $event = (string) App::config('payments.psoola.event_id', '');
        $show = (string) App::config('payments.psoola.show_id', '');
        $ticket = (string) App::config('payments.psoola.ticket_types.professional', '');
        if ($event === '' || $show === '' || $ticket === '') {
            throw new UserError('The free speaker ticket needs the PSOOLA event settings. Ask the Owner to check them.');
        }
        $form = [
            'attempt' => $key, 'buyer_name' => $ticketName, 'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '',
            'complimentary_reason' => self::REASON, 'specialty' => 'spec', 'locale' => 'en',
            'event_id' => $event, 'show_id' => $show, 'ticket_type_id' => $ticket, 'quantity' => 1,
            'add_on_ids_json' => json_encode($lunch, JSON_THROW_ON_ERROR),
            'whatsapp_consent' => ($in['whatsapp'] ?? '') === '1' ? '1' : '0',
        ];
        try {
            $order = GuestOrders::issue($user, $form);
        } finally {
            // The order is saved before PSOOLA is asked: tag it with the speaker
            // even when PSOOLA's answer is late or refused, so it shows here.
            $saved = Db::one('SELECT partner_reference FROM sponsored_ticket_orders WHERE attempt_key = ?', [$key]);
            if ($saved && !Db::value('SELECT id FROM speaker_guests WHERE ref = ?', [$saved['partner_reference']])) {
                Db::insert('speaker_guests', ['ref' => $saved['partner_reference'], 'speaker_name' => $speaker,
                    'added_by' => (int) $user['id'], 'created_at' => App::now()]);
                Audit::log((int) $user['id'], 'speaker.register', 'sponsored_ticket_order', null,
                    ['ref' => $saved['partner_reference'], 'speaker' => $speaker, 'lunch' => count($lunch), 'whatsapp' => $form['whatsapp_consent'] === '1']);
            }
        }
        return $order;
    }

    /**
     * Asks PSOOLA again for a speaker order that is still waiting (its saved
     * request, same key: never a second ticket).
     */
    public static function retry(array $user, string $ref): array
    {
        if (!self::canRegister($user)) {
            throw new UserError('Only the Owner can register speakers.', null, 403);
        }
        $row = Db::one('SELECT o.attempt_key FROM speaker_guests g JOIN sponsored_ticket_orders o ON o.partner_reference = g.ref WHERE g.ref = ?', [$ref]);
        if (!$row) throw new UserError('That speaker ticket was not found. Reload the page.');
        return GuestOrders::retry($user, (string) $row['attempt_key']);
    }

    /**
     * QR email and WhatsApp delivery as PSOOLA reports them now, by reference
     * (read from PSOOLA's order list, kept for a minute). null when PSOOLA
     * could not be reached.
     *
     * @return ?array<string, array{email: string, whatsapp: string}>
     */
    public static function deliveries(array $refs): ?array
    {
        if (!$refs || App::config('payments.gateway') !== 'psoola') return [];
        $file = App::storage('tmp/speaker-deliveries.json');
        $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $age = is_array($cache) ? time() - (int) ($cache['at'] ?? 0) : PHP_INT_MAX;
        $missing = is_array($cache) && array_diff($refs, array_keys((array) ($cache['found'] ?? []))) !== [];
        if ($age >= 60 || ($missing && $age >= 15)) {
            try {
                $wanted = array_fill_keys($refs, true);
                $found = [];
                $gateway = new PsoolaGateway();
                $cursor = null;
                $pages = 0;
                do {
                    $page = $gateway->listOrders($cursor);
                    foreach ($page['orders'] as $order) {
                        $ref = $order['partnerReference'] ?? null;
                        if (is_string($ref) && isset($wanted[$ref]) && !isset($found[$ref])) {
                            $found[$ref] = [
                                'email' => is_string($order['emailStatus'] ?? null) ? mb_substr($order['emailStatus'], 0, 20) : '',
                                'whatsapp' => is_string($order['whatsappStatus'] ?? null) ? mb_substr($order['whatsappStatus'], 0, 20) : '',
                            ];
                        }
                    }
                    $cursor = $page['next'];
                } while ($cursor !== null && count($found) < count($wanted) && ++$pages < 10);
                $cache = ['at' => time(), 'found' => $found];
                @file_put_contents($file, json_encode($cache), LOCK_EX);
            } catch (\Throwable $error) {
                App::log('warning', 'Speaker ticket delivery could not be read', ['error' => $error->getMessage()]);
                return null;
            }
        }
        return array_intersect_key((array) ($cache['found'] ?? []), array_fill_keys($refs, true));
    }
}
