<?php
declare(strict_types=1);

namespace Ismile;

final class Attendance
{
    public static function methodLabel(?string $method): string
    {
        return match ($method) { 'signed_qr' => 'QR check-in', 'manual_lookup' => 'Manual check-in (identity confirmed)', default => '' };
    }

    public static function currentDay(?string $date = null): ?int
    {
        $date ??= date('Y-m-d');
        foreach ([1, 2] as $day) {
            if ($date === Settings::get('event_day' . $day)) return $day;
        }
        return null;
    }

    /** Live admissions use the server date, including forms left open before midnight. */
    public static function admissionDay(int $requestedDay, ?string $date = null): int
    {
        if (App::isLive()) {
            $day = self::currentDay($date);
            if ($day === null) throw new UserError('Check-in is available only on the two event dates.');
            return $day;
        }
        if (!in_array($requestedDay, [1, 2], true)) throw new UserError('Choose Day 1 or Day 2.');
        return $requestedDay;
    }

    /** One admission per ticket per day. The ticket lock serializes simultaneous scans. */
    public static function checkIn(string $qr, int $day, array $user): array
    {
        if (!Auth::can($user, 'checkin')) throw new UserError('Check-in access is required.');
        $day = self::admissionDay($day);
        if (!str_starts_with(trim($qr), 'ISM26:')) throw new UserError('Scan the QR from the guest’s ticket email.');
        $scan = Tickets::readScan($qr);
        if ($scan === null || isset($scan['error'])) throw new UserError('Invalid or outdated QR. Ask for the newest ticket email.');
        return self::admit((int)$scan['ticket']['id'], (int)$scan['ticket']['version'], $day, $user, 'signed_qr');
    }

    /** Staff verify the guest from their lookup result when no QR is available. */
    public static function checkInManually(int $ticketId, int $version, int $day, array $user, bool $verified): array
    {
        if (!Auth::can($user, 'checkin')) throw new UserError('Check-in access is required.');
        if (!$verified) throw new UserError('Confirm the guest\'s identity before admitting them without a QR.');
        if ($ticketId < 1 || $version < 1) throw new UserError('Find the guest and select their valid ticket first.');
        return self::admit($ticketId, $version, self::admissionDay($day), $user, 'manual_lookup');
    }

    /** QR and manual admission share the same lock, daily limit and certificate. */
    private static function admit(int $ticketId, int $version, int $day, array $user, string $method): array
    {
        return Db::transaction(static function () use ($ticketId, $version, $day, $user, $method): array {
            $ticket = Db::one('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if ($ticket === null) throw new UserError('Ticket not found. Find the guest again.');
            $registration = Db::one('SELECT * FROM registrations WHERE id = ? FOR UPDATE', [$ticket['registration_id']]);
            if ($ticket['cancelled_at'] !== null || !in_array($registration['status'], ['paid', 'complimentary'], true)
                || (int) $ticket['version'] !== $version) {
                throw new UserError('This ticket is cancelled or outdated. Find the guest again or send them to the registration desk.');
            }
            // A request may have waited on another scanner's lock across midnight.
            $now = App::now();
            $day = self::admissionDay($day, substr($now, 0, 10));
            $arrival = Db::one('SELECT * FROM ticket_attendance WHERE ticket_id = ? AND event_day = ?', [$ticket['id'], $day]);
            if ($arrival !== null) return ['duplicate' => true, 'arrival' => $arrival, 'ticket' => $ticket, 'day' => $day];
            $id = Db::insert('ticket_attendance', ['ticket_id' => $ticket['id'], 'event_day' => $day, 'checked_in_at' => $now, 'checked_in_by' => $user['id'], 'checkin_method' => $method]);
            // Keep the old field as the first arrival, for existing dashboard queries.
            Db::run('UPDATE tickets SET checked_in_at = COALESCE(checked_in_at, ?), checked_in_by = COALESCE(checked_in_by, ?) WHERE id = ?', [$now, $user['id'], $ticket['id']]);
            Certificates::issue($registration, $ticket);
            Audit::log((int) $user['id'], 'checkin', 'registration', (int) $registration['id'], ['ticket' => $ticket['ticket_no'], 'day' => $day, 'method' => $method]);
            return ['duplicate' => false, 'arrival' => ['id' => $id, 'checked_in_at' => $now, 'checkin_method' => $method], 'ticket' => $ticket, 'day' => $day];
        });
    }
}
