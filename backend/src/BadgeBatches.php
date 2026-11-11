<?php
declare(strict_types=1);

namespace Ismile;

/**
 * Batch history for printed badges, like the Association handovers: every
 * badge PDF made in the admin is a batch (one guest or many), kept with the
 * names exactly as printed, who made it, and how often it was downloaded.
 */
final class BadgeBatches
{
    public const MAX_GUESTS = 400;

    /**
     * Makes the badges of these guests and records them as a new batch.
     * Nothing is recorded unless every guest's PSOOLA code was found.
     *
     * @param list<int> $registrationIds
     * @return array{id: int, pdf: string}
     */
    public static function create(array $user, array $registrationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn (int $id): bool => $id > 0)));
        if (!$ids) throw new UserError('Choose at least one guest.');
        if (count($ids) > self::MAX_GUESTS) throw new UserError('Choose at most ' . self::MAX_GUESTS . ' guests in one batch.');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $guests = Db::all("SELECT * FROM registrations WHERE id IN ($marks) ORDER BY first_name, father_name, grandfather_name, id", $ids);
        if (count($guests) !== count($ids)) throw new UserError('A chosen guest no longer exists. Reload the page and choose again.');
        $pdf = self::render($user, $guests, 'new batch');
        $id = Db::transaction(static function () use ($user, $guests): int {
            $now = App::now();
            $id = Db::insert('badge_batches', ['guest_count' => count($guests), 'created_by' => (int) $user['id'], 'created_by_name' => (string) $user['name'],
                'created_at' => $now, 'downloads' => 1, 'last_downloaded_at' => $now]);
            foreach ($guests as $position => $guest) {
                Db::insert('badge_batch_items', ['batch_id' => $id, 'registration_id' => (int) $guest['id'], 'position' => $position + 1, 'ref' => $guest['ref'],
                    'printed_name' => Badges::displayName($guest), 'ticket_type' => Registrations::ticketName($guest['ticket_type'])]);
            }
            Audit::log((int) $user['id'], 'badge.batch', 'badge_batch', $id, ['guests' => count($guests)]);
            return $id;
        });
        return ['id' => $id, 'pdf' => $pdf];
    }

    /** The same guests again, as they are now (a corrected name is printed corrected). */
    public static function download(array $user, int $batchId): string
    {
        if (!self::find($batchId)) throw new UserError('Badge batch not found.');
        $guests = Db::all('SELECT r.* FROM badge_batch_items i JOIN registrations r ON r.id = i.registration_id WHERE i.batch_id = ? ORDER BY i.position', [$batchId]);
        $pdf = self::render($user, $guests, self::label($batchId));
        Db::run('UPDATE badge_batches SET downloads = downloads + 1, last_downloaded_at = ? WHERE id = ?', [App::now(), $batchId]);
        Audit::log((int) $user['id'], 'badge.batch_download', 'badge_batch', $batchId);
        return $pdf;
    }

    public static function find(int $batchId): ?array
    {
        return Db::one('SELECT * FROM badge_batches WHERE id = ?', [$batchId]);
    }

    public static function items(int $batchId): array
    {
        return Db::all("SELECT i.*, r.status, t.ticket_no,
            (SELECT GROUP_CONCAT(CONCAT('Day ', a.event_day) ORDER BY a.event_day SEPARATOR ' & ') FROM ticket_attendance a WHERE a.ticket_id = t.id) AS attended_days
            FROM badge_batch_items i JOIN registrations r ON r.id = i.registration_id LEFT JOIN tickets t ON t.registration_id = r.id
            WHERE i.batch_id = ? ORDER BY i.position", [$batchId]);
    }

    /** Newest first, with the first few printed names. */
    public static function history(int $limit = 200): array
    {
        return Db::all("SELECT b.*, (SELECT GROUP_CONCAT(i.printed_name ORDER BY i.position SEPARATOR ', ') FROM badge_batch_items i WHERE i.batch_id = b.id AND i.position <= 3) AS first_names
            FROM badge_batches b ORDER BY b.id DESC LIMIT " . max(1, min($limit, 1000)));
    }

    public static function label(int $batchId): string
    {
        return 'Badge batch #' . $batchId;
    }

    /** @param list<array> $guests */
    private static function render(array $user, array $guests, string $what): string
    {
        $codes = PsoolaTickets::codesFor($user, $guests);
        $entries = array_map(static fn (array $guest): array => [$guest, $codes[(int) $guest['id']]], $guests);
        return Badges::pdfMany($entries, 'iSmile 2026 badges · ' . $what);
    }
}
