<?php
// Each PSOOLA ticket's entry QR, saved so the buyer's own page can show it.
//
// PSOOLA returns a `tickets` list with every paid order. Each ticket carries
// `qrCode` (the exact text the gate scanner reads) and `qrImageUrl` (a public
// PNG of that QR). Either one IS the entry ticket, so:
//  - they are shown only to the buyer, behind their private status link;
//  - they are never logged, never put in admin exports, and are stripped from
//    every stored PSOOLA answer (PsoolaGateway::withoutLinks).
// When PSOOLA's QR access is off, qrCode is null: the row is still saved (so
// it is not asked for again every minute) and the page falls back to PSOOLA's
// own ticket email / ticket page.

declare(strict_types=1);

namespace Ismile;

use Ismile\Payments\PsoolaGateway;

final class PsoolaTicketQrs
{
    /** Ticket states for which the buyer is shown the QR. */
    private const SHOWN = ['valid', 'used'];

    /**
     * Saves one PSOOLA order's tickets for a registration. Tickets PSOOLA no
     * longer lists for that order are removed. Returns how many were saved.
     */
    public static function save(int $registrationId, string $orderId, array $tickets): int
    {
        $rows = [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) continue;
            $code = $ticket['code'] ?? null;
            if (!is_string($code) || trim($code) === '' || strlen($code) > 190) continue;
            $qr = is_string($ticket['qrCode'] ?? null) && $ticket['qrCode'] !== '' && strlen($ticket['qrCode']) <= 2048 ? $ticket['qrCode'] : null;
            $image = PsoolaTickets::safeUrl($ticket['qrImageUrl'] ?? null) ? $ticket['qrImageUrl'] : null;
            $addOns = array_values(array_filter((array) ($ticket['addOns'] ?? []), static fn ($a): bool => is_string($a) && $a !== ''));
            $rows[$code] = [
                'registration_id'  => $registrationId,
                'psoola_order_id'  => $orderId,
                'code'             => $code,
                'ticket_type_name' => mb_substr((string) ($ticket['ticketTypeName'] ?? ''), 0, 190),
                'holder_name'      => mb_substr((string) ($ticket['holderName'] ?? ''), 0, 190),
                'add_ons'          => mb_substr((string) json_encode($addOns, JSON_UNESCAPED_UNICODE), 0, 2000),
                'qr_code'          => $qr,
                'qr_image_url'     => $image,
                'status'           => mb_substr((string) ($ticket['status'] ?? 'unknown'), 0, 20),
                'synced_at'        => App::now(),
            ];
        }
        if (!$rows) return 0;   // nothing issued yet (unpaid) or nothing usable: keep what was saved
        Db::transaction(static function () use ($rows, $orderId, $registrationId): void {
            foreach ($rows as $row) {
                Db::run('INSERT INTO psoola_ticket_qrs (registration_id, psoola_order_id, code, ticket_type_name, holder_name, add_ons, qr_code, qr_image_url, status, synced_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE registration_id = VALUES(registration_id), ticket_type_name = VALUES(ticket_type_name), holder_name = VALUES(holder_name),
                    add_ons = VALUES(add_ons), qr_code = VALUES(qr_code), qr_image_url = VALUES(qr_image_url), status = VALUES(status), synced_at = VALUES(synced_at)', array_values($row));
            }
            $codes = array_keys($rows);
            Db::run('DELETE FROM psoola_ticket_qrs WHERE registration_id = ? AND psoola_order_id = ? AND code NOT IN (' . implode(',', array_fill(0, count($codes), '?')) . ')',
                array_merge([$registrationId, $orderId], $codes));
        });
        return count($rows);
    }

    /**
     * Asks PSOOLA (GET /orders/{id}) for a registration's tickets and saves
     * them; returns PSOOLA's order (null when the registration has no PSOOLA
     * order). The order holds private links: never log or store it.
     */
    public static function refresh(int $registrationId): ?array
    {
        $source = PsoolaTickets::source($registrationId);
        if (!$source || !$source['id']) return null;
        $order = (new PsoolaGateway())->getOrder((string) $source['id']);
        self::save($registrationId, (string) $source['id'], is_array($order['tickets'] ?? null) ? $order['tickets'] : []);
        return $order;
    }

    /**
     * The minute job: saves the tickets of paid PSOOLA registrations that
     * have none saved yet. A few per run, so a slow PSOOLA never holds it up.
     */
    public static function syncMissing(int $limit = 20): array
    {
        $ids = Db::all("SELECT r.id FROM registrations r JOIN tickets t ON t.registration_id = r.id
            WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM psoola_ticket_qrs q WHERE q.registration_id = r.id)
            AND (EXISTS (SELECT 1 FROM payments p WHERE p.registration_id = r.id AND p.gateway = 'psoola' AND p.status = 'paid' AND p.provider_payment_id IS NOT NULL)
              OR EXISTS (SELECT 1 FROM sponsored_ticket_orders s WHERE s.registration_id = r.id AND s.status = 'issued' AND s.provider_order_id IS NOT NULL))
            ORDER BY r.id DESC LIMIT 500");
        $saved = 0;
        $asked = 0;
        foreach ($ids as $row) {
            if ($asked >= $limit) break;
            // An order PSOOLA could not answer for is asked again after 10 minutes, so it never blocks the newer ones.
            if (!RateLimit::hit('psoola-qr-sync:' . (int) $row['id'], 1, 600)) continue;
            $asked++;
            try {
                self::refresh((int) $row['id']);
                if (Db::value('SELECT 1 FROM psoola_ticket_qrs WHERE registration_id = ? LIMIT 1', [(int) $row['id']])) $saved++;
            } catch (\Throwable $error) {
                // Never the QR or the PSOOLA answer: only which registration and why.
                App::log('warning', 'PSOOLA ticket QR not saved', ['registration' => (int) $row['id'], 'error' => mb_substr($error->getMessage(), 0, 200)]);
            }
        }
        return ['checked' => $asked, 'saved' => $saved];
    }

    /**
     * The buyer's own tickets, for their status page (the caller has already
     * checked their private link). Asks PSOOLA once when nothing is saved yet.
     *
     * @return array{tickets: list<array>, ticketsUrl: ?string}
     */
    public static function forBuyer(array $registration): array
    {
        $id = (int) $registration['id'];
        $rows = Db::all('SELECT * FROM psoola_ticket_qrs WHERE registration_id = ? ORDER BY id', [$id]);
        $shown = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $r): bool => in_array($r['status'], self::SHOWN, true) && $r['qr_image_url'] !== null));
        $ticketsUrl = null;
        // Nothing to show yet (just paid, or QR access is off): ask PSOOLA, at most a few times a minute.
        if (!$shown($rows) && RateLimit::hit('psoola-qr:' . $id, 3, 60)) {
            try {
                $order = self::refresh($id);
                $ticketsUrl = PsoolaTickets::safeUrl($order['ticketsUrl'] ?? null) ? $order['ticketsUrl'] : null;
                $rows = Db::all('SELECT * FROM psoola_ticket_qrs WHERE registration_id = ? ORDER BY id', [$id]);
            } catch (\Throwable $error) {
                App::log('warning', 'PSOOLA ticket QR lookup failed', ['registration' => $id, 'error' => mb_substr($error->getMessage(), 0, 200)]);
            }
        }
        $cards = [];
        foreach ($shown($rows) as $row) {
            $addOns = json_decode((string) $row['add_ons'], true);
            $cards[] = [
                'code'       => $row['code'],
                'type'       => $row['ticket_type_name'],
                'holder'     => $row['holder_name'],
                'addOns'     => is_array($addOns) ? array_values(array_filter($addOns, 'is_string')) : [],
                'status'     => $row['status'],
                'qrImageUrl' => $row['qr_image_url'],
            ];
        }
        // PSOOLA's own ticket page only when there is no QR to show (QR access off).
        return ['tickets' => $cards, 'ticketsUrl' => $cards ? null : $ticketsUrl];
    }
}
