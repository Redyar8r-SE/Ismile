<?php
declare(strict_types=1);

namespace Ismile;

final class Attendance
{
    public static function methodLabel(?string $method): string
    {
        return match ($method) { 'signed_qr' => 'QR check-in', 'manual_lookup' => 'Manual check-in (identity confirmed)', 'psoola' => 'Scanned at the Psoola gate', default => '' };
    }

    public static function currentDay(?string $date = null): ?int
    {
        $date ??= date('Y-m-d');
        foreach ([1, 2] as $day) {
            if ($date === Settings::get('event_day' . $day)) return $day;
        }
        return null;
    }

    /**
     * When the summit ends (owner, 20 Nov 2026: Day 2 at 19:45), as
     * Y-m-d H:i:s, or '' when the last day or the time is not set.
     */
    public static function closesAt(): string
    {
        return self::dayEndsAt(2);
    }

    /**
     * When an event day ends (owner, 20 Nov 2026: both days end at 19:45),
     * as Y-m-d H:i:s, or '' when the date or the time is not set.
     */
    public static function dayEndsAt(int $day): string
    {
        $date = Settings::get('event_day' . $day);
        $time = substr(Settings::get('event_end_time'), 0, 5);
        return ($date === '' || !preg_match('/^\d{2}:\d{2}$/', $time)) ? '' : $date . ' ' . $time . ':00';
    }

    /** True from the end of the summit on (19:45:00 itself included): no check-in or check-out counts. */
    public static function closed(?string $at = null): bool
    {
        $end = self::closesAt();
        return $end !== '' && ($at ?? App::now()) >= $end;
    }

    /**
     * Every visit (owner, 20 Nov 2026): scanned in starts a visit, scanned out
     * ends it, scanned in again starts a new one. The time inside is the sum
     * of the visits, so it pauses while the guest is outside.
     * A new visit only starts when the last one has ended at or before $at.
     */
    public static function visitIn(int $ticketId, int $day, string $at): bool
    {
        $last = Db::one('SELECT entered_at, left_at FROM attendance_visits WHERE ticket_id = ? AND event_day = ? ORDER BY entered_at DESC LIMIT 1', [$ticketId, $day]);
        if ($last !== null && ($last['left_at'] === null || $last['left_at'] > $at || $last['entered_at'] >= $at)) return false;
        return Db::run('INSERT IGNORE INTO attendance_visits (ticket_id, event_day, entered_at, left_at, created_at) VALUES (?, ?, ?, NULL, ?)', [$ticketId, $day, $at, App::now()]) > 0;
    }

    /** Scanned out: ends the visit still open (one that started before $at). */
    public static function visitOut(int $ticketId, int $day, string $at): bool
    {
        if (!Db::value('SELECT id FROM attendance_visits WHERE ticket_id = ? AND event_day = ? LIMIT 1', [$ticketId, $day])) {
            // That day's arrival from before visits were kept: it is the first visit.
            $arrived = Db::value('SELECT checked_in_at FROM ticket_attendance WHERE ticket_id = ? AND event_day = ?', [$ticketId, $day]);
            if ($arrived === null) return false;
            self::visitIn($ticketId, $day, (string) $arrived);
        }
        return Db::run('UPDATE attendance_visits SET left_at = ? WHERE ticket_id = ? AND event_day = ? AND left_at IS NULL AND entered_at < ? ORDER BY entered_at DESC LIMIT 1', [$at, $ticketId, $day, $at]) > 0;
    }

    /**
     * One ticket's visits, by event day, oldest first.
     *
     * @return array<int, list<array{entered_at: string, left_at: ?string}>>
     */
    public static function visits(int $ticketId): array
    {
        $days = [];
        foreach (Db::all('SELECT event_day, entered_at, left_at FROM attendance_visits WHERE ticket_id = ? ORDER BY event_day, entered_at', [$ticketId]) as $row) {
            $days[(int) $row['event_day']][] = ['entered_at' => $row['entered_at'], 'left_at' => $row['left_at']];
        }
        return $days;
    }

    /**
     * Seconds inside on one event day (owner, 20 Nov 2026): the sum of the
     * visits, so the time pauses while the guest is outside. Each day ends at
     * 19:45: nothing after that counts, and a visit with no exit scan is
     * counted up to 19:45 once the day is over (up to now while it goes on).
     * 'open' = inside now; 'closed_at_end' = a visit with no exit scan was
     * counted up to the end of the day; 'unknown' = no end time is set, so
     * a visit with no exit scan cannot be counted. 'lengths' = each visit.
     *
     * @param list<array{entered_at: string, left_at: ?string}> $visits
     * @return array{seconds: int, open: bool, closed_at_end: bool, unknown: bool, lengths: list<?int>}
     */
    public static function timeInside(array $visits, int $day, ?string $now = null): array
    {
        $now ??= App::now();
        $end = self::dayEndsAt($day);
        $limit = null;
        if ($end !== '' && $now >= $end) {
            $limit = $end;
        } elseif (substr($now, 0, 10) === Settings::get('event_day' . $day)) {
            $limit = $now;
        }
        $total = ['seconds' => 0, 'open' => false, 'closed_at_end' => false, 'unknown' => false, 'lengths' => []];
        foreach ($visits as $visit) {
            $out = $visit['left_at'];
            if ($out === null) {
                if ($limit === null) {
                    $total['unknown'] = true;
                    $total['lengths'][] = null;
                    continue;
                }
                $out = $limit;
                $total[$limit === $end ? 'closed_at_end' : 'open'] = true;
            }
            if ($end !== '' && $out > $end) $out = $end;   // nothing after the end of the day
            $length = max(0, strtotime($out) - strtotime($visit['entered_at']));
            $total['lengths'][] = $length;
            $total['seconds'] += $length;
        }
        return $total;
    }

    /** "1 h 5 min 20 s": exact to the second. */
    public static function durationText(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) return $h . ' h ' . $m . ' min ' . $s . ' s';
        if ($m > 0) return $m . ' min ' . $s . ' s';
        return $s . ' s';
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
            if (App::isLive() && self::closed($now)) {
                throw new UserError('The summit ended at ' . date('g:i A', strtotime(self::closesAt())) . ' on ' . date('j M', strtotime(self::closesAt())) . '. Check-in is closed.');
            }
            $day = self::admissionDay($day, substr($now, 0, 10));
            $arrival = Db::one('SELECT * FROM ticket_attendance WHERE ticket_id = ? AND event_day = ?', [$ticket['id'], $day]);
            if ($arrival !== null) return ['duplicate' => true, 'arrival' => $arrival, 'ticket' => $ticket, 'day' => $day];
            $id = Db::insert('ticket_attendance', ['ticket_id' => $ticket['id'], 'event_day' => $day, 'checked_in_at' => $now, 'checked_in_by' => $user['id'], 'checkin_method' => $method]);
            self::visitIn((int) $ticket['id'], $day, $now);
            // Keep the old field as the first arrival, for existing dashboard queries.
            Db::run('UPDATE tickets SET checked_in_at = COALESCE(checked_in_at, ?), checked_in_by = COALESCE(checked_in_by, ?) WHERE id = ?', [$now, $user['id'], $ticket['id']]);
            Certificates::issue($registration, $ticket);
            Audit::log((int) $user['id'], 'checkin', 'registration', (int) $registration['id'], ['ticket' => $ticket['ticket_no'], 'day' => $day, 'method' => $method]);
            return ['duplicate' => false, 'arrival' => ['id' => $id, 'checked_in_at' => $now, 'checkin_method' => $method], 'ticket' => $ticket, 'day' => $day];
        });
    }
}
