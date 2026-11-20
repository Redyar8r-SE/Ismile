<?php
// PSOOLA scans the tickets at the door. Its check-ins (GET /check-ins) are
// copied into ticket_attendance, so the Attended list, the Guest list, the
// Report and the certificates work exactly as with iSmile's own check-in.
// Run by the 5-minute job and by "Refresh from Psoola"; safe to repeat.

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;
use Ismile\Attendance;
use Ismile\Audit;
use Ismile\Certificates;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\Tickets;

final class PsoolaCheckIns
{
    /** Pages read in one run at most (100 scans each). */
    private const MAX_PAGES = 100;

    /** Pages PSOOLA returned in the last sync(): each one is a read from PSOOLA's 60-per-minute allowance. */
    public static int $pagesRead = 0;

    /** True when this site sells through PSOOLA, so PSOOLA's gate is the check-in. */
    public static function active(): bool
    {
        return (string) App::config('payments.gateway', 'fake') === 'psoola';
    }

    /**
     * Reads every PSOOLA check-in and records the new ones.
     *
     * @return array{seen: int, added: int, unmatched: int, outside_event_days: int, left: int, back_in: int}
     */
    public static function sync(): array
    {
        $gateway = new PsoolaGateway();
        $scans = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $gateway->listCheckIns($cursor);
            array_push($scans, ...$page['checkIns']);
            $cursor = $page['next'];
        } while (++$pages < self::MAX_PAGES && $cursor !== null);
        self::$pagesRead = $pages;
        $counts = self::record($scans);
        Settings::set('psoola_checkins_synced_at', App::now());
        return $counts;
    }

    /**
     * True until the end of the second event day: the gate scans are then
     * copied every few seconds (watch), not only by the 5-minute job.
     */
    public static function fastCopy(?string $today = null): bool
    {
        $lastDay = Settings::get('event_day2');
        return self::active() && $lastDay !== '' && ($today ?? date('Y-m-d')) <= $lastDay;
    }

    /** Seconds to wait before the next fast copy: 10, or longer as the scan list grows. */
    public static function pause(int $pagesRead): int
    {
        return max(10, (int) ceil($pagesRead * 2.5));
    }

    /**
     * Copies the gate scans again and again until $until (a Unix time), so
     * an arrival shows on the Registration page within seconds. PSOOLA
     * allows 60 reads a minute for everything this site does: the pause
     * keeps this at about 24 a minute at most (10 s between copies while
     * the list fits in 4 pages, longer after), leaving the rest for sales.
     * Stops at the first failure; the next minute starts again.
     *
     * @return array{copies: int, added: int}
     */
    public static function watch(int $until, ?callable $sleep = null): array
    {
        $sleep ??= static function (int $seconds): void { sleep($seconds); };
        $done = ['copies' => 0, 'added' => 0];
        while (time() < $until) {
            $counts = self::sync();
            $done['copies']++;
            $done['added'] += $counts['added'];
            $pause = self::pause(self::$pagesRead);
            if (time() + $pause >= $until) {
                break;
            }
            $sleep($pause);
        }
        return $done;
    }

    /**
     * Records PSOOLA check-ins (as GET /check-ins lists them).
     *
     * @param list<array> $scans
     * @return array{seen: int, added: int, unmatched: int, outside_event_days: int, left: int, back_in: int}
     */
    public static function record(array $scans): array
    {
        $counts = ['seen' => count($scans), 'added' => 0, 'unmatched' => 0, 'outside_event_days' => 0, 'left' => 0, 'back_in' => 0];
        foreach ($scans as $scan) {
            $ticketId = self::ticketIdFor($scan);
            if ($ticketId === null) {
                $counts['unmatched']++;
                continue;
            }
            $days = self::days($scan);
            if ($days === []) {
                $counts['outside_event_days']++;
                continue;
            }
            foreach ($days as $day => $at) {
                // A scan from the end of the summit on (Day 2, 19:45) is not an arrival.
                if (Attendance::closed($at)) continue;
                if (self::admit($ticketId, $day, $at, (string) ($scan['ticketCode'] ?? ''))) {
                    $counts['added']++;
                }
            }
            $out = self::checkOut($ticketId, $scan);
            if ($out === 'left') $counts['left']++;
            if ($out === 'back_in') $counts['back_in']++;
        }
        return $counts;
    }

    /**
     * Check-out (owner, 19 Nov 2026). PSOOLA's gate scans a guest again when
     * they leave: the scan then says inside = false, and lastScannedAt is when
     * they left. That time is kept on that day's attendance. Scanned back in
     * (inside = true), the time is cleared: the guest is inside again.
     * Returns 'left', 'back_in' or '' (nothing changed).
     */
    private static function checkOut(int $ticketId, array $scan): string
    {
        $inside = $scan['inside'] ?? null;
        $ms = $scan['lastScannedAt'] ?? null;
        if (!is_bool($inside) || (!is_int($ms) && !is_float($ms))) return '';
        $at = date('Y-m-d H:i:s', (int) floor($ms / 1000));
        // From the end of the summit on (Day 2, 19:45) a scan is neither a check-out nor back in.
        if (Attendance::closed($at)) return '';
        $day = Attendance::currentDay(substr($at, 0, 10));
        if ($day === null) return '';
        if ($inside === false) {
            // Only after that day's arrival, and only when the time is new.
            $changed = Db::run('UPDATE ticket_attendance SET checked_out_at = ? WHERE ticket_id = ? AND event_day = ? AND checked_in_at < ? AND NOT (checked_out_at <=> ?)', [$at, $ticketId, $day, $at, $at]);
            // Every visit (20 Nov 2026): this exit ends the visit going on.
            $ended = Attendance::visitOut($ticketId, $day, $at);
            return $changed > 0 || $ended ? 'left' : '';
        }
        $changed = Db::run('UPDATE ticket_attendance SET checked_out_at = NULL WHERE ticket_id = ? AND event_day = ? AND checked_out_at IS NOT NULL AND checked_out_at <= ?', [$ticketId, $day, $at]);
        // Back in: a new visit starts at this scan (the time outside is not counted).
        $started = Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ? AND event_day = ?', [$ticketId, $day]) !== null
            && Attendance::visitIn($ticketId, $day, $at);
        return $changed > 0 || $started ? 'back_in' : '';
    }

    /**
     * The event day(s) of a scan: the first scan, and the last one when it was
     * on the other day. Times are Unix milliseconds, shown in Iraq time.
     *
     * @return array<int, string> event day => arrival time (Y-m-d H:i:s)
     */
    public static function days(array $scan): array
    {
        $days = [];
        foreach (['firstScannedAt', 'lastScannedAt'] as $key) {
            $ms = $scan[$key] ?? null;
            if (!is_int($ms) && !is_float($ms)) {
                continue;
            }
            $at = date('Y-m-d H:i:s', (int) floor($ms / 1000));
            $day = Attendance::currentDay(substr($at, 0, 10));
            if ($day !== null && (!isset($days[$day]) || $at < $days[$day])) {
                $days[$day] = $at;
            }
        }
        ksort($days);
        return $days;
    }

    /**
     * PSOOLA gate scans made outside the two event days (tests before the
     * summit): each ticket is admitted once, so PSOOLA must reset these.
     * Read live from PSOOLA; nothing is saved. Oldest scan first.
     *
     * @return list<array{registration: ?array, ticketCode: string, orderId: string, first: ?string, last: ?string}>
     */
    public static function outsideEventDays(): array
    {
        $gateway = new PsoolaGateway();
        $list = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $gateway->listCheckIns($cursor);
            foreach ($page['checkIns'] as $scan) {
                if (self::days($scan) !== []) continue;
                $time = static function (mixed $ms): ?string {
                    return is_int($ms) || is_float($ms) ? date('Y-m-d H:i', (int) floor($ms / 1000)) : null;
                };
                $list[] = [
                    'registration' => self::registrationFor($scan),
                    'ticketCode'   => (string) ($scan['ticketCode'] ?? ''),
                    'orderId'      => (string) ($scan['order']['id'] ?? ''),
                    'first'        => $time($scan['firstScannedAt'] ?? null),
                    'last'         => $time($scan['lastScannedAt'] ?? null),
                ];
            }
            $cursor = $page['next'];
        } while ($cursor !== null && ++$pages < self::MAX_PAGES);
        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['first'], (string) $b['first']));
        return $list;
    }

    /** Our ticket for a PSOOLA scan: by our reference, else by PSOOLA's order id. */
    private static function ticketIdFor(array $scan): ?int
    {
        $registration = self::registrationFor($scan);
        if ($registration === null) return null;
        $ticket = Tickets::forRegistration((int) $registration['id']);
        return $ticket !== null && $ticket['cancelled_at'] === null ? (int) $ticket['id'] : null;
    }

    /** Our active registration for a PSOOLA scan, or null. */
    private static function registrationFor(array $scan): ?array
    {
        $registration = null;
        $reference = $scan['order']['partnerReference'] ?? null;
        if (is_string($reference) && $reference !== '') {
            $registration = Registrations::findByRef($reference);
        }
        $orderId = $scan['order']['id'] ?? null;
        if ($registration === null && is_string($orderId) && $orderId !== '') {
            $registrationId = Db::value("SELECT registration_id FROM payments WHERE provider_payment_id = ? AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$orderId]);
            $registrationId ??= Db::value("SELECT registration_id FROM sponsored_ticket_orders WHERE provider_order_id=? AND status='issued'",[$orderId]);
            $registration = $registrationId ? Registrations::find((int) $registrationId) : null;
        }
        if ($registration === null || !in_array($registration['status'], ['paid', 'complimentary'], true)) {
            return null;
        }
        return $registration;
    }

    /** One admission for one day, the same way the door would record it. */
    private static function admit(int $ticketId, int $day, string $at, string $ticketCode): bool
    {
        return Db::transaction(static function () use ($ticketId, $day, $at, $ticketCode): bool {
            $ticket = Db::one('SELECT * FROM tickets WHERE id = ? FOR UPDATE', [$ticketId]);
            if ($ticket === null || $ticket['cancelled_at'] !== null
                || Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ? AND event_day = ?', [$ticketId, $day])) {
                return false;
            }
            $registration = Registrations::find((int) $ticket['registration_id']);
            Db::insert('ticket_attendance', ['ticket_id' => $ticketId, 'event_day' => $day, 'checked_in_at' => $at, 'checked_in_by' => null, 'checkin_method' => 'psoola']);
            Attendance::visitIn($ticketId, $day, $at);
            Db::run('UPDATE tickets SET checked_in_at = LEAST(COALESCE(checked_in_at, ?), ?) WHERE id = ?', [$at, $at, $ticketId]);
            Certificates::issue($registration, $ticket);
            Audit::log(null, 'checkin.psoola', 'registration', (int) $registration['id'], ['ticket' => $ticket['ticket_no'], 'psoola_ticket' => $ticketCode, 'day' => $day]);
            return true;
        });
    }
}
