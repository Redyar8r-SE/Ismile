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
    public static function all(string $search = ''): array
    {
        $where = '';
        $params = [];
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $digits = ltrim((string) preg_replace('/\D+/', '', $search), '0');
            $where = " WHERE (CONCAT_WS(' ', COALESCE(r.first_name, c.first_name), COALESCE(r.father_name, c.father_name), COALESCE(r.grandfather_name, c.grandfather_name)) LIKE ?
                OR COALESCE(r.email, c.email) LIKE ? OR ag.ref LIKE ?" . ($digits !== '' && strlen($digits) >= 3 ? ' OR COALESCE(r.phone, c.phone) LIKE ?' : '') . ')';
            array_push($params, $like, $like, $like);
            if ($digits !== '' && strlen($digits) >= 3) $params[] = '%' . $digits . '%';
        }
        return Db::all(
            "SELECT ag.id AS guest_id, ag.ref, ag.association, ag.created_at AS added_at, ab.name AS added_by_name,
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
               $where
              ORDER BY ag.id DESC",
            $params
        );
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

    /** Rows for the name list PDF: number, full name, ticket (registered guests only, A to Z). */
    public static function nameListRows(): array
    {
        $guests = array_values(array_filter(self::all(), static fn (array $g): bool => self::state($g) === 'registered'));
        usort($guests, static fn (array $a, array $b): int => strcasecmp(Registrations::fullName($a), Registrations::fullName($b)));
        $rows = [[self::NAME . ', ' . count($guests) . (count($guests) === 1 ? ' guest' : ' guests')], ['#', 'Full name', 'Ticket']];
        foreach ($guests as $index => $guest) {
            $rows[] = [(string) ($index + 1), Registrations::fullName($guest), Registrations::ticketName($guest['ticket_type'])];
        }
        return $rows;
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
