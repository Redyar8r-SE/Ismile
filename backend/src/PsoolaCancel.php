<?php
// Cancels a person's tickets in PSOOLA when the main Owner deletes them
// (owner, 19 Nov 2026). PSOOLA's DELETE /orders/{id} cancels the order: its
// tickets turn "cancelled" (the QR stops working) and the seat comes back
// (checked live on the Owner's own test VIP order, 19 Nov 2026).
//
// Safety: only the main Owner; an order is cancelled only when it is ours
// for this person alone (no other person in the database uses it) and holds
// one ticket; an order already cancelled counts as done. Nothing in our
// database is deleted until PSOOLA shows every order cancelled.

declare(strict_types=1);

namespace Ismile;

use Ismile\Payments\PsoolaGateway;

final class PsoolaCancel
{
    /** True when PSOOLA is the real gateway (not the fake one used by tests). */
    public static function active(): bool
    {
        return App::config('payments.gateway') === 'psoola';
    }

    /**
     * PSOOLA orders of a person still in the database: the summit ticket and
     * any paid PSOOLA workshop seats.
     *
     * @return list<array{id: string, what: string}>
     */
    public static function ordersFor(int $registrationId): array
    {
        $out = [];
        $add = static function (?string $id, string $what) use (&$out): void {
            if (is_string($id) && $id !== '' && !isset($out[$id])) $out[$id] = ['id' => $id, 'what' => $what];
        };
        $summit = PsoolaTickets::source($registrationId);
        $add($summit['id'] ?? null, 'Summit ticket');
        foreach (['workshop_ticket_orders', 'workshop_checkouts'] as $table) {
            foreach (Db::all("SELECT provider_session_id FROM $table WHERE registration_id = ? AND provider_status = 'paid' AND provider_session_id IS NOT NULL", [$registrationId]) as $row) {
                $add($row['provider_session_id'], 'Workshop ticket');
            }
        }
        foreach (Db::all("SELECT o.provider_order_id FROM guest_workshop_orders g JOIN sponsored_ticket_orders o ON o.id = g.guest_order_id
                          WHERE g.registration_id = ? AND o.status = 'issued' AND o.provider_order_id IS NOT NULL", [$registrationId]) as $row) {
            $add($row['provider_order_id'], 'Free workshop ticket');
        }
        return array_values($out);
    }

    /**
     * The same orders, read from the copy kept when a person was deleted
     * (deleted_records.data).
     *
     * @return list<array{id: string, what: string}>
     */
    public static function ordersFromCopy(array $copy): array
    {
        $out = [];
        $add = static function ($id, string $what) use (&$out): void {
            if (is_string($id) && $id !== '' && !isset($out[$id])) $out[$id] = ['id' => $id, 'what' => $what];
        };
        foreach ((array) ($copy['sponsored_ticket_orders'] ?? []) as $row) {
            if (($row['status'] ?? '') === 'issued') $add($row['provider_order_id'] ?? null, 'Summit ticket');
        }
        foreach ((array) ($copy['payments'] ?? []) as $row) {
            if (($row['gateway'] ?? '') === 'psoola' && ($row['status'] ?? '') === 'paid') $add($row['provider_payment_id'] ?? null, 'Summit ticket');
        }
        foreach (['workshop_ticket_orders', 'workshop_checkouts'] as $table) {
            foreach ((array) ($copy[$table] ?? []) as $row) {
                if (($row['provider_status'] ?? '') === 'paid') $add($row['provider_session_id'] ?? null, 'Workshop ticket');
            }
        }
        return array_values($out);
    }

    /** True when a person still in the database (other than $except) uses this PSOOLA order. */
    public static function usedByOther(string $orderId, ?int $except): bool
    {
        $other = static fn (string $sql): bool => (bool) Db::value($sql . ($except !== null ? ' AND registration_id <> ' . (int) $except : '') . ' LIMIT 1', [$orderId]);
        return $other("SELECT registration_id FROM payments WHERE provider_payment_id = ? AND registration_id IS NOT NULL")
            || $other("SELECT registration_id FROM sponsored_ticket_orders WHERE provider_order_id = ? AND registration_id IS NOT NULL")
            || $other("SELECT registration_id FROM workshop_ticket_orders WHERE provider_session_id = ?")
            || $other("SELECT registration_id FROM workshop_checkouts WHERE provider_session_id = ?");
    }

    /**
     * PSOOLA's view of one order, for the page: status, ticket count and
     * ticket statuses (no codes or personal details).
     *
     * @return array{found: bool, status: ?string, tickets: list<string>, count: int}
     */
    public static function look(string $orderId): array
    {
        $answer = (new PsoolaGateway())->partnerRequest('GET', 'orders/' . rawurlencode($orderId));
        if ($answer['status'] === 404) return ['found' => false, 'status' => null, 'tickets' => [], 'count' => 0];
        if ($answer['status'] !== 200 || !is_array($answer['json']) || ($answer['json']['id'] ?? null) !== $orderId) {
            throw new UserError('PSOOLA did not answer about order ' . $orderId . ' (HTTP ' . (int) $answer['status'] . '). Nothing was deleted. Try again in a minute.');
        }
        $tickets = array_values(array_map(static fn ($t): string => is_array($t) ? (string) ($t['status'] ?? '') : '', (array) ($answer['json']['tickets'] ?? [])));
        return ['found' => true, 'status' => is_string($answer['json']['status'] ?? null) ? $answer['json']['status'] : null, 'tickets' => $tickets, 'count' => count($tickets)];
    }

    /** True when PSOOLA shows the order and every ticket in it cancelled. */
    public static function isCancelled(array $look): bool
    {
        return $look['found'] && $look['status'] === 'cancelled' && array_diff($look['tickets'], ['cancelled']) === [];
    }

    /**
     * Cancels one order in PSOOLA and checks PSOOLA shows it cancelled.
     * Returns 'cancelled' or 'already cancelled'. Throws when it cannot be done.
     */
    public static function cancel(string $orderId, array $user, ?int $registrationId): string
    {
        if (!Erase::isMainOwner($user) || ($user['role'] ?? '') !== 'owner') {
            throw new UserError('Only the main Owner can cancel tickets in PSOOLA.');
        }
        if (self::usedByOther($orderId, $registrationId)) {
            throw new UserError('PSOOLA order ' . $orderId . ' also belongs to another person in the database, so it was not cancelled. Nothing was deleted.');
        }
        $before = self::look($orderId);
        if (!$before['found']) {
            throw new UserError('PSOOLA does not know order ' . $orderId . '. Nothing was deleted.');
        }
        if (self::isCancelled($before)) return 'already cancelled';
        if ($before['count'] !== 1) {
            throw new UserError('PSOOLA order ' . $orderId . ' holds ' . $before['count'] . ' tickets, so it was not cancelled (it may be other people too). Nothing was deleted.');
        }
        $answer = (new PsoolaGateway())->partnerRequest('DELETE', 'orders/' . rawurlencode($orderId), null, ['Idempotency-Key: ismile-cancel-' . $orderId]);
        $after = self::look($orderId);
        if (!self::isCancelled($after)) {
            $why = is_string($answer['json']['error']['message'] ?? null) ? ': ' . mb_substr($answer['json']['error']['message'], 0, 160) : '';
            throw new UserError('PSOOLA did not cancel order ' . $orderId . ' (HTTP ' . (int) $answer['status'] . $why . '). Nothing was deleted.');
        }
        Audit::log((int) $user['id'], 'psoola.order_cancel', 'registration', $registrationId ?? 0, ['order' => $orderId]);
        return 'cancelled';
    }

    /**
     * Before a person is deleted: cancels every PSOOLA order of theirs.
     * Returns a short line for the success message ('' when there is none).
     */
    public static function beforeDelete(int $registrationId, array $user): string
    {
        if (!self::active()) return '';
        $done = [];
        foreach (self::ordersFor($registrationId) as $order) {
            $done[] = $order['what'] . ' ' . self::cancel($order['id'], $user, $registrationId);
        }
        return $done ? ' PSOOLA: ' . implode(', ', $done) . '.' : ' PSOOLA: no ticket was linked to this person.';
    }

    /** The filters on the "Deleted people" page. */
    public const STATES = ['active' => 'Ticket still works in PSOOLA', 'cancelled' => 'Cancelled in PSOOLA', 'none' => 'No PSOOLA ticket', 'unknown' => 'PSOOLA not checked', 'all' => 'Everyone deleted'];

    /**
     * Everyone deleted from the database, newest first, with who deleted them
     * and their PSOOLA state: active (a ticket still works), cancelled, none
     * (no PSOOLA ticket, or PSOOLA does not know it) or unknown (PSOOLA did
     * not answer). Reads PSOOLA once per order (at most $limit people).
     *
     * @return list<array{record: array, state: string, orders: list<array{id: string, what: string, look: ?array, shared: bool, error: ?string}>}>
     */
    public static function deletedPeople(int $limit = 200): array
    {
        $out = [];
        $records = Db::all("SELECT d.id, d.ref, d.label, d.data, d.deleted_at, d.deleted_by, u.name AS deleted_by_name, u.email AS deleted_by_email
                              FROM deleted_records d LEFT JOIN admin_users u ON u.id = d.deleted_by
                             WHERE d.kind = 'registration' ORDER BY d.id DESC LIMIT " . max(1, min(500, $limit)));
        foreach ($records as $record) {
            $copy = json_decode((string) $record['data'], true);
            $orders = is_array($copy) && self::active() ? self::ordersFromCopy($copy) : [];
            $person = is_array($copy) ? ($copy['registrations'][0] ?? []) : [];
            $rows = [];
            foreach ($orders as $order) {
                $shared = self::usedByOther($order['id'], null);
                $look = null; $error = null;
                if (!$shared) {
                    try { $look = self::look($order['id']); } catch (UserError $e) { $error = $e->key; }
                }
                $rows[] = $order + ['look' => $look, 'shared' => $shared, 'error' => $error];
            }
            $works = static fn (array $r): bool => !$r['shared'] && $r['look'] && $r['look']['found'] && !self::isCancelled($r['look']);
            $state = match (true) {
                (bool) array_filter($rows, $works) => 'active',
                (bool) array_filter($rows, static fn (array $r): bool => $r['error'] !== null) => 'unknown',
                (bool) array_filter($rows, static fn (array $r): bool => $r['look'] && self::isCancelled($r['look'])) => 'cancelled',
                default => 'none',
            };
            $out[] = ['record' => array_diff_key($record, ['data' => 1]) + ['ticket_type' => (string) ($person['ticket_type'] ?? ''), 'status' => (string) ($person['status'] ?? '')],
                'state' => $state, 'orders' => $rows];
        }
        return $out;
    }

    /** People deleted earlier whose PSOOLA ticket may still work (or PSOOLA did not answer). */
    public static function deletedStillActive(int $limit = 200): array
    {
        return array_values(array_filter(self::deletedPeople($limit), static fn (array $p): bool => in_array($p['state'], ['active', 'unknown'], true)));
    }

    /** Cancels in PSOOLA the tickets of one person deleted earlier (deleted_records id). */
    public static function cancelDeleted(int $recordId, array $user): string
    {
        $record = Db::one("SELECT * FROM deleted_records WHERE id = ? AND kind = 'registration'", [$recordId]) ?? throw new UserError('That deleted person was not found.');
        $copy = json_decode((string) $record['data'], true);
        $orders = is_array($copy) ? self::ordersFromCopy($copy) : [];
        if (!$orders) throw new UserError('No PSOOLA ticket is linked to ' . $record['ref'] . '.');
        $done = [];
        foreach ($orders as $order) {
            if (self::usedByOther($order['id'], null)) { $done[] = $order['what'] . ' kept (another person uses it)'; continue; }
            $done[] = $order['what'] . ' ' . self::cancel($order['id'], $user, null);
        }
        Audit::log((int) $user['id'], 'psoola.deleted_cancel', 'deleted_record', $recordId, ['ref' => $record['ref']]);
        return $record['ref'] . ' (' . $record['label'] . '): ' . implode(', ', $done) . '.';
    }
}
