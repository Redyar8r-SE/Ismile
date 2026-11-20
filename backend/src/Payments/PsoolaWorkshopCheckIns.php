<?php
// Workshop door scans (owner, 19 Nov 2026). Each paid iSmile workshop is its
// own PSOOLA event; its check-ins (GET /check-ins?eventId=…) are copied onto
// the person's workshop booking: the first scan is when they arrived, and a
// scan with inside = false is when they left (cleared when scanned back in).
// PSOOLA allows 60 reads a minute for everything, so only today's workshops
// are read, once a minute while the summit runs (and by "Refresh from Psoola").

declare(strict_types=1);

namespace Ismile\Payments;

use Ismile\App;
use Ismile\Attendance;
use Ismile\Audit;
use Ismile\Db;

final class PsoolaWorkshopCheckIns
{
    /** Pages read for one workshop at most (100 scans each). */
    private const MAX_PAGES = 20;

    /**
     * iSmile workshops with a PSOOLA event: those on $date (today when null),
     * or every one when $all.
     *
     * @return array<string, string> workshop id => PSOOLA event id
     */
    public static function workshops(?string $date = null, bool $all = false): array
    {
        $rows = Db::all("SELECT id, provider_event_id FROM workshops WHERE organizer = 'ismile' AND provider_event_id IS NOT NULL AND provider_event_id <> ''"
            . ($all ? '' : ' AND scheduled_date = ?') . ' ORDER BY id', $all ? [] : [$date ?? date('Y-m-d')]);
        return array_column($rows, 'provider_event_id', 'id');
    }

    /**
     * Reads the door scans of today's workshops (or $all) and records them.
     *
     * @return array{workshops: int, seen: int, arrived: int, left: int, back_in: int, unmatched: int, other_day: int}
     */
    public static function sync(bool $all = false, ?PsoolaGateway $gateway = null): array
    {
        $gateway ??= new PsoolaGateway();
        $counts = ['workshops' => 0, 'seen' => 0, 'arrived' => 0, 'left' => 0, 'back_in' => 0, 'unmatched' => 0, 'other_day' => 0];
        foreach (self::workshops(null, $all) as $workshopId => $eventId) {
            $scans = [];
            $cursor = null;
            $pages = 0;
            do {
                $page = $gateway->listCheckIns($cursor, 100, $eventId);
                array_push($scans, ...$page['checkIns']);
                $cursor = $page['next'];
            } while ($cursor !== null && ++$pages < self::MAX_PAGES);
            $counts['workshops']++;
            foreach (self::record((string) $workshopId, $scans) as $key => $n) $counts[$key] += $n;
        }
        \Ismile\Settings::set('psoola_workshop_checkins_synced_at', App::now());
        return $counts;
    }

    /**
     * Records one workshop's PSOOLA scans on the matching bookings.
     *
     * @param list<array> $scans
     * Only scans on the workshop's own date count (an earlier test scan is
     * not an arrival): the first scan if it was that day, else the last one.
     *
     * @return array{seen: int, arrived: int, left: int, back_in: int, unmatched: int, other_day: int}
     */
    public static function record(string $workshopId, array $scans): array
    {
        $counts = ['seen' => count($scans), 'arrived' => 0, 'left' => 0, 'back_in' => 0, 'unmatched' => 0, 'other_day' => 0];
        $time = static fn (mixed $ms): ?string => is_int($ms) || is_float($ms) ? date('Y-m-d H:i:s', (int) floor($ms / 1000)) : null;
        $date = (string) (Db::value('SELECT scheduled_date FROM workshops WHERE id = ?', [$workshopId]) ?? '');
        $onDay = static fn (?string $at): bool => $at !== null && ($date === '' || str_starts_with($at, $date));
        foreach ($scans as $scan) {
            $bookingId = self::bookingFor($workshopId, $scan);
            $first = $time($scan['firstScannedAt'] ?? null);
            if ($bookingId === null || $first === null) {
                $counts['unmatched']++;
                continue;
            }
            if (!$onDay($first)) {
                $later = $time($scan['lastScannedAt'] ?? null);
                if (!$onDay($later)) {
                    $counts['other_day']++;
                    continue;
                }
                $first = $later;   // scanned before (a test), then on the workshop day
            }
            // From the end of the summit on (Day 2, 19:45) a scan counts for nothing.
            if (Attendance::closed($first)) continue;
            // Arrived: the first scan (an earlier one replaces a later one, never the other way).
            if (Db::run('UPDATE workshop_bookings SET checked_in_at = ? WHERE id = ? AND (checked_in_at IS NULL OR checked_in_at > ?)', [$first, $bookingId, $first]) > 0) {
                $counts['arrived']++;
                Audit::log(null, 'checkin.psoola_workshop', 'registration', (int) Db::value('SELECT registration_id FROM workshop_bookings WHERE id = ?', [$bookingId]), ['workshop' => $workshopId, 'at' => $first]);
            }
            $inside = $scan['inside'] ?? null;
            $last = $time($scan['lastScannedAt'] ?? null);
            if (!is_bool($inside) || !$onDay($last) || Attendance::closed($last)) continue;
            if ($inside === false) {
                if (Db::run('UPDATE workshop_bookings SET checked_out_at = ? WHERE id = ? AND checked_in_at < ? AND NOT (checked_out_at <=> ?)', [$last, $bookingId, $last, $last]) > 0) $counts['left']++;
            } elseif (Db::run('UPDATE workshop_bookings SET checked_out_at = NULL WHERE id = ? AND checked_out_at IS NOT NULL AND checked_out_at <= ?', [$bookingId, $last]) > 0) {
                $counts['back_in']++;
            }
        }
        return $counts;
    }

    /**
     * The active booking a workshop scan belongs to, found by PSOOLA's order:
     * a website card payment, a desk or staff order, or a free guest order.
     */
    private static function bookingFor(string $workshopId, array $scan): ?int
    {
        $orderId = is_string($scan['order']['id'] ?? null) ? $scan['order']['id'] : '';
        $reference = is_string($scan['order']['partnerReference'] ?? null) ? $scan['order']['partnerReference'] : '';
        if ($orderId === '' && $reference === '') return null;
        $id = Db::value("SELECT booking_id FROM workshop_ticket_orders WHERE workshop_id = ? AND booking_id IS NOT NULL AND ((provider_session_id = ? AND ? <> '') OR (partner_reference = ? AND ? <> '')) ORDER BY id DESC LIMIT 1",
                [$workshopId, $orderId, $orderId, $reference, $reference])
            ?? Db::value("SELECT booking_id FROM workshop_checkouts WHERE workshop_id = ? AND booking_id IS NOT NULL AND ((provider_session_id = ? AND ? <> '') OR (partner_reference = ? AND ? <> '')) ORDER BY id DESC LIMIT 1",
                [$workshopId, $orderId, $orderId, $reference, $reference])
            ?? Db::value("SELECT g.booking_id FROM guest_workshop_orders g JOIN sponsored_ticket_orders o ON o.id = g.guest_order_id WHERE g.workshop_id = ? AND g.booking_id IS NOT NULL AND ((o.provider_order_id = ? AND ? <> '') OR (o.partner_reference = ? AND ? <> '')) ORDER BY g.booking_id DESC LIMIT 1",
                [$workshopId, $orderId, $orderId, $reference, $reference]);
        if ($id === null) return null;
        return Db::value('SELECT id FROM workshop_bookings WHERE id = ? AND removed_at IS NULL', [(int) $id]) !== null ? (int) $id : null;
    }
}
