<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Admin\Lists;
use Ismile\Admin\PaymentBreakdown;

/** Immutable handover snapshots, with one active handover per registration. */
final class Association
{
    public const DIRECTORY_SORTS = ['name'=>'Name A–Z','name_desc'=>'Name Z–A','newest'=>'Newest first','oldest'=>'Oldest first'];

    /** Only fixed SQL expressions may be used for guest directory sorting. */
    public static function directoryOrder(string $sort): string
    {
        return match ($sort) {
            'name_desc' => 'r.first_name DESC,r.father_name DESC,r.grandfather_name DESC,r.id DESC',
            'newest' => 'r.created_at DESC,r.id DESC',
            'oldest' => 'r.created_at,r.id',
            default => 'r.first_name,r.father_name,r.grandfather_name,r.id',
        };
    }

    public const REPORT_COLUMNS = ['#','Full name','Total paid','Phone','Ticket type','Lunch days','Lunch quantity','Ticket paid','Lunch day 1 paid','Lunch day 2 paid','Ticket no.','Ambassador','Reference','Payment date (Iraq)','Cash / website','Status'];
    public static function canManage(array $user): bool
    {
        return Auth::canManageAssociation($user);
    }

    public static function label(int $id, ?int $number = null): string
    {
        $number ??= (int)(Db::value('SELECT batch_number FROM association_batches WHERE id=?',[$id]) ?? $id);
        return 'Batch ' . str_pad((string)$number,3,'0',STR_PAD_LEFT);
    }

    /** Existing active handovers start at 001; archived reversals keep their internal IDs. */
    public static function initializeBatchNumbers(): int
    {
        return Db::transaction(static function():int {
            Db::run("INSERT IGNORE INTO settings (k,v,updated_at) VALUES ('association_next_batch_number','1',?)",[App::now()]);
            $sequence = Db::one("SELECT v FROM settings WHERE k='association_next_batch_number' FOR UPDATE");
            $next = max(1,(int)$sequence['v'],1+(int)Db::value('SELECT COALESCE(MAX(batch_number),0) FROM association_batches'));
            $count = 0;
            foreach(Db::all("SELECT id FROM association_batches WHERE status='sent' AND batch_number IS NULL ORDER BY id FOR UPDATE") as $batch) {
                Db::update('association_batches',['batch_number'=>$next],'id=?',[$batch['id']]);
                Audit::log(null,'association.number_assigned','association_batch',(int)$batch['id'],['batch_number'=>$next]);
                $next++;$count++;
            }
            Db::update('settings',['v'=>(string)$next,'updated_at'=>App::now()],"k='association_next_batch_number'");
            return $count;
        });
    }

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
                'report_snapshot' => self::reportSnapshot($r, $r),
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
            // Take the sequence lock before looking up a new request token, so
            // concurrent inserts cannot deadlock while holding token gap locks.
            $sequence = Db::one("SELECT v FROM settings WHERE k='association_next_batch_number' FOR UPDATE");
            $existing = Db::one('SELECT id,created_by FROM association_batches WHERE request_token=? FOR UPDATE', [$token]);
            if ($existing) {
                if ((int) $existing['created_by'] !== (int) $user['id']) throw new UserError('This batch belongs to another staff member.');
                return (int) $existing['id'];
            }
            $current = self::preview($ids, true);
            if (!hash_equals((string) ($draft['fingerprint'] ?? ''), $current['fingerprint'])) throw new UserError('Guest or payment details changed. Review a new selection before confirming.');
            $number = (int)($sequence['v'] ?? 0);
            if ($number < 1) throw new UserError('Batch numbering is not ready. Reload the page and try again.');
            Db::update('settings',['v'=>(string)($number+1),'updated_at'=>App::now()],"k='association_next_batch_number'");
            $id = Db::insert('association_batches', [
                'batch_number'=>$number,
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

    private static function reportSnapshot(array $registration, array $receipt): string
    {
        return json_encode([
            'lunch_quantity'=>(int)!empty($registration['lunch_day1'])+(int)!empty($registration['lunch_day2']),
            'lunch_day1_paid'=>$receipt['finance_lunch1'] ?? 'Not recorded',
            'lunch_day2_paid'=>$receipt['finance_lunch2'] ?? 'Not recorded',
            'ambassador'=>Lists::exportValue('ambassador_code',$registration),
            'status'=>$registration['status'] ?? 'paid',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** One-time enrichment of old rows; original names, money and handover state stay intact. */
    public static function backfillReportSnapshots(): int
    {
        $count = 0;
        foreach (Db::all('SELECT * FROM association_batch_items WHERE report_snapshot IS NULL ORDER BY id') as $item) {
            $registration = Db::one('SELECT * FROM registrations WHERE id=?', [$item['registration_id']]) ?? [];
            $type = array_search($item['ticket_type'], Registrations::TICKET_NAMES, true);
            $registration = [
                'ticket_type'=>$type !== false ? $type : ($registration['ticket_type'] ?? 'professional'),
                'lunch_day1'=>str_contains($item['lunch'],'Day 1') ? 1 : 0,
                'lunch_day2'=>str_contains($item['lunch'],'Day 2') ? 1 : 0,
                'status'=>$item['payment_id'] ? 'paid' : 'complimentary',
            ] + $registration;
            $payment = $item['payment_id'] ? Db::one('SELECT * FROM payments WHERE id=?',[$item['payment_id']]) : null;
            $receipt = PaymentBreakdown::describe($registration,$payment,(array)App::config('payments.psoola.add_ons',[]));
            if ($receipt['receipt_currency']!=='IQD' || $receipt['receipt_total_amount']!==(int)$item['total_paid'] || $receipt['receipt_ticket_amount']!==(int)$item['ticket_amount']) {
                $receipt['finance_lunch1']=$registration['lunch_day1']?'Not recorded':'Not booked';
                $receipt['finance_lunch2']=$registration['lunch_day2']?'Not recorded':'Not booked';
            }
            $count += Db::update('association_batch_items',['report_snapshot'=>self::reportSnapshot($registration,$receipt)],'id=? AND report_snapshot IS NULL',[$item['id']]);
        }
        return $count;
    }

    public static function report(array $batch, array $items): array
    {
        $meta = self::label((int) $batch['id'],isset($batch['batch_number'])?(int)$batch['batch_number']:null) . ' | ' . strtoupper($batch['status']) . ' | ' . $batch['sent_at'] . ' (Iraq) | ' . $batch['created_by_name']
            . ' | ' . $batch['guest_count'] . ' guests | Sent: ' . number_format((int) $batch['total_amount']) . ' IQD | Ticket amounts only; lunch excluded.';
        if ($batch['note'] !== '') $meta .= ' | Note: ' . $batch['note'];
        $active = array_filter($items, static fn($r)=>!empty($r['active']));
        $meta .= ' | Currently sent: ' . count($active) . ' guests, ' . number_format(array_sum(array_column($active,'ticket_amount'))) . ' IQD';
        if ($batch['status'] === 'reversed') $meta .= ' | Reversed: ' . $batch['reversed_at'] . ' | Reason: ' . $batch['reversal_reason'];
        $rows = [[$meta], self::REPORT_COLUMNS];
        foreach ($items as $i => $r) {
            $detail = json_decode($r['report_snapshot'] ?? '{}',true) ?: [];
            $status = ucfirst((string)($detail['status'] ?? 'paid'));
            if (empty($r['active'])) $status = 'Reversed / '.$status;
            $rows[] = [$i+1,$r['full_name'],number_format((int)$r['total_paid']).' IQD',$r['phone'],$r['ticket_type'],$r['lunch'],(string)($detail['lunch_quantity'] ?? 'Not recorded'),number_format((int)$r['ticket_amount']).' IQD',$detail['lunch_day1_paid'] ?? 'Not recorded',$detail['lunch_day2_paid'] ?? 'Not recorded',$r['ticket_no'],$detail['ambassador'] ?? '',$r['ref'],$r['payment_date'],$r['payment_channel'],$status];
        }
        return $rows;
    }
}
