<?php
// Deleting a registration or a sponsor/booth booking for good. Only the main
// Owner, redyar8r@gmail.com, may do it (not other Owners or admins), one record
// at a time, after typing its reference. Every row that goes is first copied,
// as JSON, into deleted_records, so nothing is lost by accident.

declare(strict_types=1);

namespace Ismile;

final class Erase
{
    /** The owner's own sign-in; fixed here so no setting or other admin can move it. */
    public const MAIN_OWNER = 'redyar8r@gmail.com';

    /** The main Owner's account, while it is switched on. */
    public static function isMainOwner(array $user): bool
    {
        return self::isMainOwnerAccount($user) && ($user['disabled_at'] ?? null) === null;
    }

    /** True for the main Owner's own account row (protected on the Users page). */
    public static function isMainOwnerAccount(array $account): bool
    {
        return strtolower(trim((string) ($account['email'] ?? ''))) === self::MAIN_OWNER;
    }

    /** For an Owner who cannot delete: why not (shown instead of the delete box). */
    public static function lockedReason(array $user): ?string
    {
        if (($user['role'] ?? '') !== 'owner' || self::isMainOwner($user)) {
            return null;
        }
        return 'Only the main Owner (' . self::MAIN_OWNER . ') can delete records. You are signed in as ' . self::mask((string) ($user['email'] ?? '')) . '.';
    }

    /** r•••••r@gmail.com: enough to recognise an address without showing it. */
    private static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_strlen($name) <= 2 ? mb_substr($name, 0, 1) . '•' : mb_substr($name, 0, 1) . str_repeat('•', mb_strlen($name) - 2) . mb_substr($name, -1);
        return $shown . ($domain !== '' ? '@' . $domain : '');
    }

    /** One paid person with their ticket, check-ins, certificate, workshops, payments, emails and ID photo. */
    public static function registration(int $id, string $typed, array $user): string
    {
        self::requireMainOwner($user);
        return Db::transaction(static function () use ($id, $typed, $user): string {
            $registration = Db::one('SELECT * FROM registrations WHERE id = ? FOR UPDATE', [$id])
                ?? throw new UserError('Registration not found.');
            self::requireTyped($typed, $registration['ref']);
            $tickets = Db::all('SELECT * FROM tickets WHERE registration_id = ?', [$id]);
            $ticketIds = array_map('intval', array_column($tickets, 'id'));
            $checkouts = Db::all('SELECT * FROM checkouts WHERE registration_id = ? OR ref = ?', [$id, $registration['ref']]);
            $checkoutIds = array_map('intval', array_column($checkouts, 'id'));
            $photoIds = array_values(array_unique(array_filter(array_map('intval', [$registration['id_photo_id'], ...array_column($checkouts, 'id_photo_id')]))));
            $snapshot = [
                'registrations'     => [$registration],
                'tickets'           => $tickets,
                'ticket_attendance' => self::rowsIn('ticket_attendance', 'ticket_id', $ticketIds),
                'certificates'      => Db::all('SELECT * FROM certificates WHERE registration_id = ?', [$id]),
                'workshop_bookings' => Db::all('SELECT * FROM workshop_bookings WHERE registration_id = ?', [$id]),
                'payments'          => Db::all('SELECT * FROM payments WHERE registration_id = ?' . ($checkoutIds ? ' OR checkout_id IN (' . self::marks($checkoutIds) . ')' : ''), [$id, ...$checkoutIds]),
                'checkouts'         => $checkouts,
                'emails'            => Db::all('SELECT * FROM emails WHERE registration_id = ?' . ($checkoutIds ? ' OR checkout_id IN (' . self::marks($checkoutIds) . ')' : ''), [$id, ...$checkoutIds]),
                'student_id_photos' => self::rowsIn('student_id_photos', 'id', $photoIds),
            ];
            self::keep('registration', $registration['ref'], Registrations::fullName($registration), $snapshot, $user);

            // Children first, so no foreign key is left pointing at a deleted row.
            $paymentIds = array_map('intval', array_column($snapshot['payments'], 'id'));
            if ($ticketIds) {
                Db::run('DELETE FROM ticket_attendance WHERE ticket_id IN (' . self::marks($ticketIds) . ')', $ticketIds);
            }
            Db::run('DELETE FROM certificates WHERE registration_id = ?', [$id]);
            Db::run('DELETE FROM workshop_bookings WHERE registration_id = ?', [$id]);
            Db::run('DELETE FROM emails WHERE registration_id = ?', [$id]);
            Db::run('DELETE FROM tickets WHERE registration_id = ?', [$id]);
            if ($paymentIds) {
                Db::run('DELETE FROM payments WHERE id IN (' . self::marks($paymentIds) . ')', $paymentIds);
            }
            if ($checkoutIds) {
                Db::run('DELETE FROM checkouts WHERE id IN (' . self::marks($checkoutIds) . ')', $checkoutIds);
            }
            Db::run('DELETE FROM registrations WHERE id = ?', [$id]);
            if ($photoIds) {
                Db::run('DELETE FROM student_id_photos WHERE id IN (' . self::marks($photoIds) . ')', $photoIds);
            }
            Audit::log((int) $user['id'], 'registration.delete', 'registration', $id, ['ref' => $registration['ref']]);
            return 'Deleted ' . $registration['ref'] . ' (' . Registrations::fullName($registration) . '). A copy is kept in the database (deleted_records).';
        });
    }

    /** One sponsor or exhibition-booth booking with its calls and emails. */
    public static function sponsorRequest(int $id, string $typed, array $user): string
    {
        self::requireMainOwner($user);
        return Db::transaction(static function () use ($id, $typed, $user): string {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$id])
                ?? throw new UserError('Booking not found.');
            self::requireTyped($typed, $request['ref']);
            unset($request['reserved_booth']);
            $snapshot = [
                'sponsor_requests' => [$request],
                'sponsor_calls'    => Db::all('SELECT * FROM sponsor_calls WHERE request_id = ?', [$id]),
                'emails'           => Db::all('SELECT * FROM emails WHERE sponsor_request_id = ?', [$id]),
            ];
            self::keep($request['kind'] === 'booth' ? 'booth' : 'sponsor', $request['ref'], $request['company'], $snapshot, $user);
            Db::run('DELETE FROM sponsor_calls WHERE request_id = ?', [$id]);
            Db::run('DELETE FROM emails WHERE sponsor_request_id = ?', [$id]);
            Db::run('DELETE FROM sponsor_requests WHERE id = ?', [$id]);
            Audit::log((int) $user['id'], 'sponsor.delete', 'sponsor_request', $id, ['ref' => $request['ref'], 'kind' => $request['kind']]);
            return 'Deleted ' . $request['ref'] . ' (' . $request['company'] . '). A copy is kept in the database (deleted_records).';
        });
    }

    private static function requireMainOwner(array $user): void
    {
        if (!self::isMainOwner($user)) {
            throw new UserError('Only the main Owner can delete records.');
        }
    }

    private static function requireTyped(string $typed, string $ref): void
    {
        if (strtoupper(trim($typed)) !== strtoupper($ref)) {
            throw new UserError("Nothing was deleted. Type the reference $ref exactly to confirm.");
        }
    }

    private static function keep(string $kind, string $ref, string $label, array $snapshot, array $user): void
    {
        // Binary values (the ID photo) are stored as base64 so the copy is valid JSON.
        array_walk_recursive($snapshot, static function (mixed &$value): void {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $value = 'base64:' . base64_encode($value);
            }
        });
        Db::insert('deleted_records', [
            'kind'       => $kind,
            'ref'        => $ref,
            'label'      => mb_substr($label, 0, 190),
            'data'       => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'deleted_by' => (int) $user['id'],
            'deleted_at' => App::now(),
        ]);
    }

    private static function rowsIn(string $table, string $column, array $ids): array
    {
        return $ids ? Db::all("SELECT * FROM $table WHERE $column IN (" . self::marks($ids) . ')', $ids) : [];
    }

    private static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
