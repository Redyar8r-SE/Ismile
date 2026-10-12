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

    /** True when this site sells through PSOOLA, so PSOOLA's gate is the check-in. */
    public static function active(): bool
    {
        return (string) App::config('payments.gateway', 'fake') === 'psoola';
    }

    /**
     * Reads every PSOOLA check-in and records the new ones.
     *
     * @return array{seen: int, added: int, unmatched: int, outside_event_days: int}
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
        } while ($cursor !== null && ++$pages < self::MAX_PAGES);
        $counts = self::record($scans);
        Settings::set('psoola_checkins_synced_at', App::now());
        return $counts;
    }

    /**
     * Records PSOOLA check-ins (as GET /check-ins lists them).
     *
     * @param list<array> $scans
     * @return array{seen: int, added: int, unmatched: int, outside_event_days: int}
     */
    public static function record(array $scans): array
    {
        $counts = ['seen' => count($scans), 'added' => 0, 'unmatched' => 0, 'outside_event_days' => 0];
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
                if (self::admit($ticketId, $day, $at, (string) ($scan['ticketCode'] ?? ''))) {
                    $counts['added']++;
                }
            }
        }
        return $counts;
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

    /** Our ticket for a PSOOLA scan: by our reference, else by PSOOLA's order id. */
    private static function ticketIdFor(array $scan): ?int
    {
        $registration = null;
        $reference = $scan['order']['partnerReference'] ?? null;
        if (is_string($reference) && $reference !== '') {
            $registration = Registrations::findByRef($reference);
        }
        $orderId = $scan['order']['id'] ?? null;
        if ($registration === null && is_string($orderId) && $orderId !== '') {
            $registrationId = Db::value("SELECT registration_id FROM payments WHERE provider_payment_id = ? AND registration_id IS NOT NULL ORDER BY id DESC LIMIT 1", [$orderId]);
            $registration = $registrationId ? Registrations::find((int) $registrationId) : null;
        }
        if ($registration === null || !in_array($registration['status'], ['paid', 'complimentary'], true)) {
            return null;
        }
        $ticket = Tickets::forRegistration((int) $registration['id']);
        return $ticket !== null && $ticket['cancelled_at'] === null ? (int) $ticket['id'] : null;
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
            Db::run('UPDATE tickets SET checked_in_at = LEAST(COALESCE(checked_in_at, ?), ?) WHERE id = ?', [$at, $at, $ticketId]);
            Certificates::issue($registration, $ticket);
            Audit::log(null, 'checkin.psoola', 'registration', (int) $registration['id'], ['ticket' => $ticket['ticket_no'], 'psoola_ticket' => $ticketCode, 'day' => $day]);
            return true;
        });
    }
}
