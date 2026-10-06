<?php
declare(strict_types=1);

namespace Ismile\Admin;

final class CommunicationQuery
{
    public const KINDS = [
        'ticket' => 'Event ticket', 'pay_now' => 'Phone payment link',
        'sponsor_received' => 'Sponsor request receipt', 'sponsor_notify' => 'Sponsor notification',
        'alert' => 'Team alert',
    ];
    public const STATES = ['sent' => 'Sent', 'pending' => 'Pending', 'failed' => 'Failed', 'skipped' => 'Skipped'];
    public const STATE_SQL = "CASE WHEN e.status = 'sent' AND e.provider_message_id LIKE 'skipped:%' THEN 'skipped' ELSE e.status END";

    public static function from(): string
    {
        return 'emails e LEFT JOIN registrations r ON r.id = e.registration_id LEFT JOIN checkouts c ON c.id = e.checkout_id LEFT JOIN sponsor_requests s ON s.id = e.sponsor_request_id';
    }

    public static function where(array $user, array $filters = []): array
    {
        $where = [$user['role'] === 'owner' ? '1=1' : "e.kind <> 'alert'"];
        $params = [];
        if (isset(self::STATES[$filters['status'] ?? ''])) {
            $where[] = '(' . self::STATE_SQL . ') = ?';
            $params[] = $filters['status'];
        }
        if (isset(self::KINDS[$filters['kind'] ?? ''])) {
            $where[] = 'e.kind = ?';
            $params[] = $filters['kind'];
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $fields = ['e.to_email','r.ref','c.ref','s.ref','s.company',"CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name)","CONCAT_WS(' ',c.first_name,c.father_name,c.grandfather_name)"];
            $where[] = '(' . implode(' OR ', array_map(static fn (string $field): string => $field . ' LIKE ?', $fields)) . ')';
            $like = '%' . str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query) . '%';
            array_push($params, ...array_fill(0, count($fields), $like));
        }
        return [implode(' AND ', $where), $params];
    }
}
