<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Admin\Lists;
use Ismile\Admin\PaymentBreakdown;

/** Immutable handover snapshots, with one active handover per registration. */
final class Association
{
    public static function canManage(array $user): bool
    {
        return Auth::canManageAssociation($user);
    }

    public static function label(int $id): string { return 'Batch ' . str_pad((string) $id, 3, '0', STR_PAD_LEFT); }

    public static function ids(string $json): array
    {
        $ids = json_decode($json, true);
        if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 2000) throw new UserError('Select between 1 and 2,000 guests.');
        foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new UserError('Invalid guest selection. Refresh and select again.');
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function attach(array $people): array
    {
        $people = PaymentBreakdown::attach($people);
        foreach ($people as &$r) {
            $r['association_reason'] = match (true) {
                !in_array($r['status'], ['paid','complimentary'], true) => 'Cancelled registration',
                !empty($r['association_batch_id']) => 'Already sent in ' . self::label((int) $r['association_batch_id']),
                (int) ($r['paid_payment_count'] ?? 0) > 1 => 'Multiple confirmed payments; review in Payments',
                $r['receipt_ticket_amount'] === null => 'Ticket amount not recorded; review in Payments',
                $r['receipt_currency'] !== 'IQD' => 'Payment is not in IQD',
                default => '',
            };
        }
        unset($r);
        return $people;
    }

    public static function selectSql(): string
    {
        return "SELECT r.*, t.ticket_no, ai.batch_id AS association_batch_id,
            (SELECT COUNT(*) FROM payments p WHERE p.registration_id=r.id AND p.status='paid') AS paid_payment_count
            FROM registrations r LEFT JOIN tickets t ON t.registration_id=r.id AND t.cancelled_at IS NULL
            LEFT JOIN association_batch_items ai ON ai.registration_id=r.id AND ai.active=1";
    }

    public static function preview(array $ids, bool $lock = false): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        if ($lock) {
            // All writers acquire guest rows in the same order, including reversal.
            Db::all("SELECT id FROM registrations WHERE id IN ($marks) ORDER BY id FOR UPDATE", $ids);
            Db::all("SELECT id FROM payments WHERE registration_id IN ($marks) ORDER BY id FOR UPDATE", $ids);
        }
        $people = self::attach(Db::all(self::selectSql() . " WHERE r.id IN ($marks) ORDER BY r.id", $ids));
        if (count($people) !== count($ids)) throw new UserError('A selected guest no longer exists. Refresh the list.');
        $items = []; $total = 0;
        foreach ($people as $r) {
            if ($r['association_reason'] !== '') throw new UserError($r['ref'] . ': ' . $r['association_reason']);
            $item = [
                'registration_id' => (int) $r['id'], 'payment_id' => $r['receipt_payment_id'],
                'ref' => $r['ref'], 'full_name' => Registrations::fullName($r), 'phone' => $r['phone'],
                'ticket_type' => Registrations::ticketName($r['ticket_type']), 'ticket_no' => $r['ticket_no'] ?? '',
                'lunch' => Lists::exportValue('lunch', $r), 'total_paid' => $r['receipt_total_amount'],
                'ticket_amount' => $r['receipt_ticket_amount'], 'payment_channel' => $r['finance_channel'],
                'payment_date' => $r['finance_date'],
            ];
            $items[] = $item; $total += $item['ticket_amount'];
        }
        return ['ids' => $ids, 'items' => $items, 'total' => $total,
            'fingerprint' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    public static function confirm(array $user, array $draft, string $token): int
    {
        if (!self::canManage($user)) throw new UserError('Your account cannot confirm Association handovers.');
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new UserError('Invalid batch token.');
        $ids = self::ids(json_encode($draft['ids'] ?? [], JSON_THROW_ON_ERROR));
        $note = trim((string) ($draft['note'] ?? ''));
        if (mb_strlen($note) > 1000) throw new UserError('Notes must be 1,000 characters or fewer.');
        return Db::transaction(static function () use ($user, $draft, $token, $ids, $note): int {
            // Serialize repeat submissions using the same guest locks.
            $marks = implode(',', array_fill(0, count($ids), '?'));
            Db::all("SELECT id FROM registrations WHERE id IN ($marks) ORDER BY id FOR UPDATE", $ids);
            $existing = Db::one('SELECT id,created_by FROM association_batches WHERE request_token=? FOR UPDATE', [$token]);
            if ($existing) {
                if ((int) $existing['created_by'] !== (int) $user['id']) throw new UserError('This batch belongs to another staff member.');
                return (int) $existing['id'];
            }
            $current = self::preview($ids, true);
            if (!hash_equals((string) ($draft['fingerprint'] ?? ''), $current['fingerprint'])) throw new UserError('Guest or payment details changed. Review a new selection before confirming.');
            $id = Db::insert('association_batches', [
                'request_token'=>$token, 'guest_count'=>count($current['items']), 'total_amount'=>$current['total'],
                'currency'=>'IQD', 'note'=>$note, 'created_by'=>(int) $user['id'],
                'created_by_name'=>$user['name'], 'sent_at'=>App::now(),
            ]);
            foreach ($current['items'] as $item) Db::insert('association_batch_items', ['batch_id'=>$id] + $item);
            Audit::log((int) $user['id'], 'association.sent', 'association_batch', $id,
                ['guests'=>count($current['items']), 'amount'=>$current['total'], 'currency'=>'IQD']);
            return $id;
        });
    }

    public static function reverse(array $user, int $id, string $reason, ?array $registrationIds = null): void
    {
        if (!self::canManage($user)) throw new UserError('Your account cannot reverse Association handovers.');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) throw new UserError('Enter a reversal reason, up to 1,000 characters.');
        if ($registrationIds !== null) $registrationIds = self::ids(json_encode($registrationIds, JSON_THROW_ON_ERROR));
        Db::transaction(static function () use ($user, $id, $reason, $registrationIds): void {
            $ids = array_map('intval', array_column(Db::all('SELECT registration_id FROM association_batch_items WHERE batch_id=? ORDER BY registration_id', [$id]), 'registration_id'));
            if (!$ids) throw new UserError('Batch not found.');
            Db::all('SELECT id FROM registrations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids);
            $batch = Db::one('SELECT * FROM association_batches WHERE id=? FOR UPDATE', [$id]);
            if (!$batch || $batch['status'] !== 'sent') throw new UserError('This batch has already been reversed.');
            $active = Db::all('SELECT * FROM association_batch_items WHERE batch_id=? AND active=1 ORDER BY registration_id FOR UPDATE', [$id]);
            $chosen = $registrationIds ?? array_map('intval', array_column($active, 'registration_id'));
            $items = array_values(array_filter($active, static fn($item)=>in_array((int)$item['registration_id'], $chosen, true)));
            if (!$items || count($items) !== count($chosen)) throw new UserError('Select guests still active in this batch. A selected guest may already have been reversed.');
            $now = App::now();
            $marks = implode(',', array_fill(0, count($chosen), '?'));
            Db::update('association_batch_items', ['active'=>null, 'reversed_at'=>$now, 'reversed_by'=>(int)$user['id'], 'reversal_reason'=>$reason], "batch_id=? AND active=1 AND registration_id IN ($marks)", [$id, ...$chosen]);
            $fullyReversed = count($items) === count($active);
            if ($fullyReversed) Db::update('association_batches', ['status'=>'reversed','reversed_at'=>$now,'reversed_by'=>(int) $user['id'],'reversal_reason'=>$reason], 'id=?', [$id]);
            Audit::log((int) $user['id'], $fullyReversed ? 'association.reversed' : 'association.guests_reversed', 'association_batch', $id,
                ['reason'=>$reason, 'registration_ids'=>$chosen, 'amount'=>array_sum(array_column($items,'ticket_amount')), 'guests'=>count($items)]);
        });
    }

    public static function totals(): array
    {
        $sent = Db::one("SELECT COALESCE(SUM(i.ticket_amount),0) AS amount, COUNT(*) AS guests, COUNT(DISTINCT i.batch_id) AS batches FROM association_batch_items i JOIN association_batches b ON b.id=i.batch_id WHERE i.active=1 AND b.status='sent'");
        $sent['waiting'] = (int) Db::value("SELECT COUNT(*) FROM registrations r WHERE r.status IN ('paid','complimentary') AND NOT EXISTS (SELECT 1 FROM association_batch_items ai WHERE ai.registration_id=r.id AND ai.active=1)");
        return $sent;
    }

    public static function revenue(): array
    {
        $gross = Db::all("SELECT p.currency, SUM(p.amount_confirmed) AS total FROM payments p LEFT JOIN registrations r ON r.id=p.registration_id WHERE p.status='paid' AND (r.id IS NULL OR r.status<>'cancelled') GROUP BY p.currency ORDER BY p.currency");
        $sent = (int) Db::value("SELECT COALESCE(SUM(i.ticket_amount),0) FROM association_batch_items i JOIN association_batches b ON b.id=i.batch_id WHERE i.active=1 AND b.status='sent'");
        $hasIqd = false;
        foreach ($gross as &$row) if ($row['currency'] === 'IQD') { $row['total'] = (int) $row['total'] - $sent; $hasIqd = true; }
        unset($row);
        if (!$hasIqd && $sent) $gross[] = ['currency'=>'IQD','total'=>-$sent];
        return $gross;
    }

    public static function batch(int $id): ?array { return Db::one('SELECT b.*, (SELECT COUNT(*) FROM association_batch_items i WHERE i.batch_id=b.id AND i.active=1) AS active_guests, (SELECT COALESCE(SUM(ticket_amount),0) FROM association_batch_items i WHERE i.batch_id=b.id AND i.active=1) AS active_amount FROM association_batches b WHERE b.id=?', [$id]); }
    public static function history(): array { return Db::all("SELECT b.*, (SELECT COUNT(*) FROM association_batch_items i WHERE i.batch_id=b.id AND i.active=1) AS active_guests, (SELECT COALESCE(SUM(ticket_amount),0) FROM association_batch_items i WHERE i.batch_id=b.id AND i.active=1) AS active_amount FROM association_batches b WHERE b.status='sent' ORDER BY b.id DESC"); }
    public static function items(int $id): array { return Db::all('SELECT * FROM association_batch_items WHERE batch_id=? ORDER BY id', [$id]); }

    public static function report(array $batch, array $items): array
    {
        $meta = self::label((int) $batch['id']) . ' | ' . strtoupper($batch['status']) . ' | ' . $batch['sent_at'] . ' (Iraq) | ' . $batch['created_by_name']
            . ' | ' . $batch['guest_count'] . ' guests | Sent: ' . number_format((int) $batch['total_amount']) . ' IQD | Ticket amounts only; lunch excluded.';
        if ($batch['note'] !== '') $meta .= ' | Note: ' . $batch['note'];
        $active = array_filter($items, static fn($r)=>!empty($r['active']));
        $meta .= ' | Currently sent: ' . count($active) . ' guests, ' . number_format(array_sum(array_column($active,'ticket_amount'))) . ' IQD';
        if ($batch['status'] === 'reversed') $meta .= ' | Reversed: ' . $batch['reversed_at'] . ' | Reason: ' . $batch['reversal_reason'];
        $rows = [[$meta], ['#','Full name','Phone','Ticket type','Lunch days','Total paid','Original handover','Ticket no.','Reference','Cash / website','Payment date (Iraq)','Association status','Reversed (Iraq)']];
        foreach ($items as $i => $r) $rows[] = [$i+1,$r['full_name'],$r['phone'],$r['ticket_type'],$r['lunch'],number_format((int) $r['total_paid']).' IQD',number_format((int) $r['ticket_amount']).' IQD',$r['ticket_no'],$r['ref'],$r['payment_channel'],$r['payment_date'],empty($r['active'])?'Reversed':'Sent',$r['reversed_at'] ?? ''];
        return $rows;
    }
}
