<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\Payments;
use Ismile\Payments\PsoolaGateway;

/**
 * Guests the Slemani Association registers for the summit. Each guest is
 * sold exactly like "Register (cash)": PSOOLA records a cash sale, issues the
 * ticket and emails the QR at once, and iSmile owes PSOOLA the price. The
 * association pays iSmile later. This class only remembers which references
 * the association added, so every page can tag those guests.
 */
final class AssociationGuests
{
    public const NAME = 'Slemani Association';

    /** The same people who may register someone for cash may add an association guest. */
    public static function canAdd(array $user): bool
    {
        return Auth::can($user, 'edit');
    }

    /**
     * Registers one association guest: the cash form, tagged with the
     * association before PSOOLA is asked, so a sale that PSOOLA confirms a few
     * minutes later is tagged too. The student ID photo is optional here.
     *
     * @return array{checkout: array, sale: array}
     */
    public static function add(array $in, array $user, ?array $photo = null): array
    {
        if (!self::canAdd($user)) {
            throw new UserError('Your account cannot add association guests.');
        }
        if (!in_array($in['gender'] ?? '', Registrations::GENDERS, true)) {
            throw new UserError('Choose the gender.');
        }
        if (trim((string) ($in['age'] ?? '')) === '') {
            throw new UserError('Enter the age.');
        }
        $checkout = Checkouts::createForCash($in, $user, $photo, false);
        Db::insert('association_guests', [
            'ref' => $checkout['ref'], 'association' => self::NAME,
            'added_by' => (int) $user['id'], 'created_at' => App::now(),
        ]);
        Audit::log((int) $user['id'], 'association_guest.add', 'checkout', (int) $checkout['id'], ['ref' => $checkout['ref'], 'association' => self::NAME]);
        try {
            $sale = Payments::sellForCash($checkout, $user);
        } catch (\Throwable $error) {
            // Nothing was sold (PSOOLA refused, or registration is closed): the
            // tag goes, so the list never shows a guest who has no ticket. A
            // sale PSOOLA may still confirm keeps its tag.
            $open = (int) Db::value("SELECT COUNT(*) FROM payments WHERE checkout_id = ? AND status IN ('created','waiting','paid','mismatch','duplicate','kept')", [$checkout['id']]);
            if ($open === 0) Db::run('DELETE FROM association_guests WHERE ref = ?', [$checkout['ref']]);
            throw $error;
        }
        return ['checkout' => $checkout, 'sale' => $sale];
    }

    /** Every association reference, read once per page (ref => true). */
    public static function refSet(): array
    {
        static $set = null;
        return $set ??= array_fill_keys(array_map('strval', array_column(Db::all('SELECT ref FROM association_guests'), 'ref')), true);
    }

    /** True when this reference was added by the association. */
    public static function isGuest(string $ref): bool
    {
        return Db::value('SELECT id FROM association_guests WHERE ref = ?', [$ref]) !== null;
    }

    /**
     * Which of these references the association added (ref => association name).
     *
     * @param list<string> $refs
     * @return array<string, string>
     */
    public static function tags(array $refs): array
    {
        $refs = array_values(array_unique(array_filter($refs, 'is_string')));
        $out = [];
        foreach (array_chunk($refs, 500) as $chunk) {
            foreach (Db::all('SELECT ref, association FROM association_guests WHERE ref IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $row) {
                $out[(string) $row['ref']] = (string) $row['association'];
            }
        }
        return $out;
    }

    /**
     * Every association guest, newest first. A guest whose sale PSOOLA has not
     * confirmed yet has no registration (registration_id NULL); a guest the
     * main Owner deleted for good is gone from this list too.
     */
    public static function all(): array
    {
        return Db::all(
            "SELECT ag.id AS guest_id, ag.ref, ag.association, ag.added_by, ag.created_at AS added_at, ab.name AS added_by_name,
                    r.id AS registration_id, r.status, c.id AS checkout_id,
                    (SELECT p.status FROM payments p WHERE p.checkout_id = c.id ORDER BY p.id DESC LIMIT 1) AS payment_status,
                    COALESCE(r.first_name, c.first_name) AS first_name, COALESCE(r.father_name, c.father_name) AS father_name,
                    COALESCE(r.grandfather_name, c.grandfather_name) AS grandfather_name,
                    COALESCE(r.phone, c.phone) AS phone, COALESCE(r.email, c.email) AS email,
                    COALESCE(r.ticket_type, c.ticket_type) AS ticket_type, COALESCE(r.specialty, c.specialty) AS specialty,
                    COALESCE(r.lunch_day1, c.lunch_day1) AS lunch_day1, COALESCE(r.lunch_day2, c.lunch_day2) AS lunch_day2,
                    (SELECT p.amount_confirmed FROM payments p WHERE p.registration_id = r.id AND p.status = 'paid' ORDER BY p.id DESC LIMIT 1) AS amount,
                    (SELECT p.currency FROM payments p WHERE p.registration_id = r.id AND p.status = 'paid' ORDER BY p.id DESC LIMIT 1) AS currency
               FROM association_guests ag
               LEFT JOIN registrations r ON r.ref = ag.ref
               LEFT JOIN checkouts c ON c.ref = ag.ref
               LEFT JOIN admin_users ab ON ab.id = ag.added_by
              ORDER BY ag.id DESC"
        );
    }

    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'name' => 'Name A to Z', 'name_desc' => 'Name Z to A'];
    public const EMAIL_FILTERS = ['delivered' => 'Delivered', 'pending' => 'Not delivered yet', 'problem' => 'Problem (failed or bounced)'];

    /** The page's search and filters, checked (unknown values are ignored). */
    public static function filters(array $in): array
    {
        $pick = static fn (string $key, array $allowed): string => is_string($in[$key] ?? null) && in_array($in[$key], $allowed, true) ? $in[$key] : '';
        return [
            'q' => is_string($in['q'] ?? null) ? mb_substr(trim($in['q']), 0, 120) : '',
            'ticket' => $pick('ticket', ['professional', 'student', 'vip']),
            'lunch' => $pick('lunch', ['day1', 'day2', 'both', 'none']),
            'state' => $pick('state', ['registered', 'waiting', 'cancelled']),
            'email' => $pick('email', array_keys(self::EMAIL_FILTERS)),
            'by' => is_string($in['by'] ?? null) && ctype_digit($in['by']) ? $in['by'] : '',
            'sort' => $pick('sort', array_keys(self::SORTS)) ?: 'newest',
        ];
    }

    /**
     * The search: the name from its start ("R" finds every first name that
     * starts with R, "Rawa H" finds Rawa Hiwa ...), or a phone number (3+
     * digits), or a reference, or an email (4+ characters, or with @).
     */
    public static function matches(array $guest, string $search): bool
    {
        $lower = static fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
        $q = $lower($search);
        if ($q === '') return true;
        if (str_starts_with($lower(Registrations::fullName($guest)), $q)) return true;
        $digits = ltrim((string) preg_replace('/\D+/', '', $q), '0');
        if (preg_match('/^[\d\s+()\-]+$/', $q) && strlen($digits) >= 3) {
            return str_contains((string) preg_replace('/\D+/', '', (string) $guest['phone']), $digits);
        }
        $long = mb_strlen($q) >= 4 || str_contains($q, '@');
        return $long && (str_contains($lower((string) $guest['ref']), $q) || str_contains($lower((string) $guest['email']), $q));
    }

    /**
     * The guests that match the search and filters, in the chosen order.
     * $mail is PSOOLA's email status by reference (for the QR email filter).
     */
    public static function filter(array $guests, array $f, ?array $mail = null): array
    {
        $out = [];
        foreach ($guests as $guest) {
            $state = self::state($guest);
            if ($state === 'none') continue;
            if ($f['q'] !== '' && !self::matches($guest, $f['q'])) continue;
            if ($f['ticket'] !== '' && $guest['ticket_type'] !== $f['ticket']) continue;
            if ($f['lunch'] !== '') {
                [$one, $two] = [(int) $guest['lunch_day1'], (int) $guest['lunch_day2']];
                if (!match ($f['lunch']) { 'day1' => $one === 1, 'day2' => $two === 1, 'both' => $one + $two === 2, default => $one + $two === 0 }) continue;
            }
            if ($f['state'] !== '' && $state !== $f['state'] && !($f['state'] === 'waiting' && $state === 'review')) continue;
            if ($f['by'] !== '' && (string) ($guest['added_by'] ?? '') !== $f['by']) continue;
            if ($f['email'] !== '') {
                $status = (string) ($mail[$guest['ref']] ?? '');
                $ok = $state === 'registered' && match ($f['email']) {
                    'delivered' => $status === 'delivered',
                    'problem' => in_array($status, ['bounced', 'complained', 'failed', 'delayed'], true),
                    default => !in_array($status, ['delivered', 'bounced', 'complained', 'failed', 'delayed'], true),
                };
                if (!$ok) continue;
            }
            $out[] = $guest;
        }
        $name = static fn (array $g): string => mb_strtolower(Registrations::fullName($g));
        usort($out, match ($f['sort']) {
            'oldest' => static fn (array $a, array $b): int => (int) $a['guest_id'] <=> (int) $b['guest_id'],
            'name' => static fn (array $a, array $b): int => [$name($a), (int) $a['guest_id']] <=> [$name($b), (int) $b['guest_id']],
            'name_desc' => static fn (array $a, array $b): int => [$name($b), (int) $b['guest_id']] <=> [$name($a), (int) $a['guest_id']],
            default => static fn (array $a, array $b): int => (int) $b['guest_id'] <=> (int) $a['guest_id'],
        });
        return $out;
    }

    /**
     * registered: PSOOLA confirmed the sale (the guest has a ticket).
     * waiting: PSOOLA made the sale; the 5-minute check finishes the registration.
     * review: PSOOLA's answer did not match (for example VIP full); Payments shows it.
     * cancelled: the Owner cancelled the registration.
     * none: nothing was sold (or the guest was deleted); not shown.
     */
    public static function state(array $guest): string
    {
        if ($guest['registration_id'] !== null) {
            return $guest['status'] === 'cancelled' ? 'cancelled' : 'registered';
        }
        return match ($guest['payment_status'] ?? null) {
            'created', 'waiting' => 'waiting',
            'mismatch', 'duplicate', 'kept' => 'review',
            default => 'none',
        };
    }

    /**
     * Counts for the boxes at the top and the amount the association owes:
     * the PSOOLA-confirmed price of every registered (not cancelled) guest.
     *
     * @return array{guests: int, professional: int, student: int, vip: int, waiting: int, owed: array<string, int>}
     */
    public static function totals(?array $guests = null): array
    {
        $guests ??= self::all();
        $out = ['guests' => 0, 'professional' => 0, 'student' => 0, 'vip' => 0, 'waiting' => 0, 'owed' => []];
        foreach ($guests as $guest) {
            $state = self::state($guest);
            if ($state === 'waiting') $out['waiting']++;
            if ($state !== 'registered') continue;
            $out['guests']++;
            if (isset($out[$guest['ticket_type']])) $out[$guest['ticket_type']]++;
            if ($guest['amount'] !== null) {
                $currency = (string) ($guest['currency'] ?: 'IQD');
                $out['owed'][$currency] = ($out['owed'][$currency] ?? 0) + (int) $guest['amount'];
            }
        }
        return $out;
    }

    /** "1,030,000 IQD" (or "0 IQD"), one amount per currency. */
    public static function owedText(array $owed): string
    {
        if (!$owed) return '0 IQD';
        $parts = [];
        foreach ($owed as $currency => $amount) $parts[] = number_format($amount) . ' ' . $currency;
        return implode(' + ', $parts);
    }

    /**
     * Rows for the guest list PDF: the same full details as the Lists PDFs
     * (full name, total, phone, ticket, lunch, ticket no., reference, payment
     * date, status and the ticket and lunch breakdown), for every registered
     * association guest, A to Z. For the association: no Ambassador column,
     * and "Sponsoring association" (the association's name) in place of
     * "Sponsoring company".
     */
    public static function reportRows(): array
    {
        $people = Admin\Lists::filter('registered', Admin\Lists::rows('registered'), Admin\Lists::filters(['sale' => 'association']));
        $people = Admin\PaymentBreakdown::attach($people);
        $title = self::NAME . ', ' . count($people) . (count($people) === 1 ? ' guest' : ' guests') . ', all pay later';
        $rows = Admin\Lists::reportRows('registered', $people, false);
        $headers = $rows[0];
        $drop = array_keys($headers, 'Ambassador', true);
        $sponsor = array_search('Sponsoring company', $headers, true);
        $tags = self::tags(array_map(static fn (array $p): string => (string) $p['ref'], $people));
        foreach ($rows as $index => $row) {
            if ($sponsor !== false) {
                $row[$sponsor] = $index === 0 ? 'Sponsoring association' : ($tags[(string) $people[$index - 1]['ref']] ?? self::NAME);
            }
            foreach ($drop as $column) unset($row[$column]);
            $rows[$index] = array_values($row);
        }
        return array_merge([[$title]], $rows);
    }

    /**
     * PSOOLA's email status for these references (ref => queued / sent /
     * delivered / failed …), read from PSOOLA's order list and kept for two
     * minutes. Returns null when PSOOLA cannot be read just now.
     *
     * @param list<string> $refs
     * @return array<string, string>|null
     */
    public static function emailStatuses(array $refs): ?array
    {
        if (!$refs || App::config('payments.gateway') !== 'psoola') return [];
        $file = App::storage('tmp/association-guest-emails.json');
        $cache = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $age = is_array($cache) ? time() - (int) ($cache['at'] ?? 0) : PHP_INT_MAX;
        $missing = is_array($cache) && array_diff($refs, array_keys((array) ($cache['found'] ?? []))) !== [];
        if ($age >= 120 || ($missing && $age >= 20)) {
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
                            $found[$ref] = is_string($order['emailStatus'] ?? null) ? mb_substr($order['emailStatus'], 0, 20) : '';
                        }
                    }
                    $cursor = $page['next'];
                } while ($cursor !== null && count($found) < count($wanted) && ++$pages < 30);
                $cache = ['at' => time(), 'found' => $found];
                @file_put_contents($file, json_encode($cache), LOCK_EX);
            } catch (\Throwable $error) {
                App::log('warning', 'Association guest email status could not be read', ['error' => $error->getMessage()]);
                return null;
            }
        }
        return array_intersect_key((array) ($cache['found'] ?? []), array_fill_keys($refs, true));
    }
}
