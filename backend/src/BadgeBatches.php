<?php
declare(strict_types=1);

namespace Ismile;

/**
 * Badge batches, like the Association handovers: guests ticked on the
 * Tickets tab are saved together as "Badge batch #N", with the names as
 * printed and who made it. Its PDF holds every guest's badge (10 × 11.5 cm
 * pages). A batch can be deleted; its number then goes to the next new
 * batch (with #1 and #2, deleting #2 makes the next one #2 again).
 * Printing one guest's badge from their own page is not a batch.
 */
final class BadgeBatches
{
    public const MAX_GUESTS = 400;

    /**
     * Saves the ticked guests as a new batch. Nothing is saved unless PSOOLA
     * returns an active ticket code for every one of them.
     *
     * @param list<int> $registrationIds
     * @return int the new batch id
     */
    public static function create(array $user, array $registrationIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn (int $id): bool => $id > 0)));
        if (!$ids) throw new UserError('Tick at least one guest first.');
        if (count($ids) > self::MAX_GUESTS) throw new UserError('Tick at most ' . self::MAX_GUESTS . ' guests for one batch.');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $guests = Db::all("SELECT * FROM registrations WHERE id IN ($marks) ORDER BY first_name, father_name, grandfather_name, id", $ids);
        if (count($guests) !== count($ids)) throw new UserError('A ticked guest no longer exists. Reload the page and tick again.');
        PsoolaTickets::codesFor($user, $guests);
        return Db::transaction(static function () use ($user, $guests): int {
            $number = 1;
            foreach (Db::all('SELECT batch_number FROM badge_batches ORDER BY batch_number FOR UPDATE') as $taken) {
                if ((int) $taken['batch_number'] !== $number) break;
                $number++;
            }
            $id = Db::insert('badge_batches', ['batch_number' => $number, 'guest_count' => count($guests), 'created_by' => (int) $user['id'],
                'created_by_name' => (string) $user['name'], 'created_at' => App::now(), 'downloads' => 0]);
            foreach ($guests as $position => $guest) {
                Db::insert('badge_batch_items', ['batch_id' => $id, 'registration_id' => (int) $guest['id'], 'position' => $position + 1, 'ref' => $guest['ref'],
                    'printed_name' => Badges::displayName($guest), 'ticket_type' => Registrations::ticketName($guest['ticket_type'])]);
            }
            Audit::log((int) $user['id'], 'badge.batch', 'badge_batch', $id, ['batch_number' => $number, 'guests' => count($guests)]);
            return $id;
        });
    }

    /** Every guest's badge in the batch on the chosen paper, as they are now (a corrected name is printed corrected). */
    public static function pdf(array $user, int $batchId, string $sheet = 'a4'): string
    {
        $batch = self::find($batchId);
        if (!$batch) throw new UserError('Badge batch not found.');
        $guests = Db::all('SELECT r.* FROM badge_batch_items i JOIN registrations r ON r.id = i.registration_id WHERE i.batch_id = ? ORDER BY i.position', [$batchId]);
        $codes = PsoolaTickets::codesFor($user, $guests);
        $pdf = Badges::pdfSheets(array_map(static fn (array $guest): array => [$guest, $codes[(int) $guest['id']]], $guests), 'iSmile 2026 badges · ' . self::label($batch), $sheet);
        Db::run('UPDATE badge_batches SET downloads = downloads + 1, last_downloaded_at = ? WHERE id = ?', [App::now(), $batchId]);
        Audit::log((int) $user['id'], 'badge.batch_download', 'badge_batch', $batchId, ['batch_number' => (int) $batch['batch_number'], 'paper' => $sheet]);
        return $pdf;
    }

    /** Removes a batch and its list of guests; its number becomes free again. */
    public static function delete(array $user, int $batchId): string
    {
        return Db::transaction(static function () use ($user, $batchId): string {
            $batch = Db::one('SELECT * FROM badge_batches WHERE id = ? FOR UPDATE', [$batchId]);
            if (!$batch) throw new UserError('Badge batch not found. It may already be deleted.');
            $names = array_column(Db::all('SELECT printed_name FROM badge_batch_items WHERE batch_id = ? ORDER BY position', [$batchId]), 'printed_name');
            Db::run('DELETE FROM badge_batch_items WHERE batch_id = ?', [$batchId]);
            Db::run('DELETE FROM badge_batches WHERE id = ?', [$batchId]);
            Audit::log((int) $user['id'], 'badge.batch_deleted', 'badge_batch', $batchId, ['batch_number' => (int) $batch['batch_number'], 'guests' => $names]);
            return self::label($batch) . ' deleted. Its number will be used for the next new batch.';
        });
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

    /** In number order, with the first few printed names. */
    public static function history(): array
    {
        return Db::all("SELECT b.*, (SELECT GROUP_CONCAT(i.printed_name ORDER BY i.position SEPARATOR ', ') FROM badge_batch_items i WHERE i.batch_id = b.id AND i.position <= 3) AS first_names
            FROM badge_batches b ORDER BY b.batch_number");
    }

    public static function label(array $batch): string
    {
        return 'Badge batch #' . (int) $batch['batch_number'];
    }
}
