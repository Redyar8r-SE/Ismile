<?php
// The Registrations list's search and filters, shared by the page and by the
// Excel export, so "export" always gives exactly what is on screen.

declare(strict_types=1);

namespace Ismile\Admin;

final class RegistrationQuery
{
    public const FILTERS = ['q', 'type', 'status', 'lunch', 'city', 'lang', 'email', 'dup', 'attendance'];

    /** @return array{0: string, 1: array} WHERE clause and its values */
    public static function where(array $in): array
    {
        $where = ['1 = 1'];
        $attendance = $in['attendance'] ?? '';
        if (in_array($attendance, ['attended', 'day1', 'day2', 'both', 'none'], true)) {
            $d1 = 'EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id=t.id AND a.event_day=1)';
            $d2 = 'EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id=t.id AND a.event_day=2)';
            $where[] = match ($attendance) {
                'attended' => "($d1 OR $d2)", 'day1' => $d1, 'day2' => $d2, 'both' => "($d1 AND $d2)", 'none' => "(NOT $d1 AND NOT $d2)",
            };
        }
        $params = [];
        $q = trim((string) ($in['q'] ?? ''));
        if ($q !== '') {
            $phone = GuestLookup::phonePrefix($q);
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $nameLike = substr($like,1);
            if (preg_match('/^\p{L}$/u',$q)) {
                $where[]="CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ?";
                $params[]=$nameLike;
            } else {
                $where[] = "(r.ref = ? OR r.email LIKE ? OR r.phone LIKE ? OR CONCAT_WS(' ', r.first_name, r.father_name, r.grandfather_name) LIKE ?"
                    . ($phone ? ' OR r.phone LIKE ?' : '') . ' OR t.ticket_no = ?)';
                array_push($params, strtoupper($q), $like, $like, $nameLike);
                if ($phone) $params[] = $phone.'%';
                $params[] = strtoupper($q);
            }
        }
        if (in_array($in['type'] ?? '', ['professional', 'student'], true)) {
            $where[] = 'r.ticket_type = ?';
            $params[] = $in['type'];
        }
        // Everyone here has paid (or has a free ticket); cancelled ones only when asked for.
        if (in_array($in['status'] ?? '', ['paid', 'complimentary', 'cancelled'], true)) {
            $where[] = 'r.status = ?';
            $params[] = $in['status'];
        } else {
            $where[] = "r.status IN ('paid','complimentary')";
        }
        $lunch = $in['lunch'] ?? '';
        if ($lunch === 'day1') {
            $where[] = 'r.lunch_day1 = 1';
        } elseif ($lunch === 'day2') {
            $where[] = 'r.lunch_day2 = 1';
        } elseif ($lunch === 'none') {
            $where[] = 'r.lunch_day1 = 0 AND r.lunch_day2 = 0';
        }
        if (($in['city'] ?? '') !== '') {
            $where[] = 'r.city = ?';
            $params[] = (string) $in['city'];
        }
        if (in_array($in['lang'] ?? '', ['en', 'ar', 'ku'], true)) {
            $where[] = 'r.lang = ?';
            $params[] = $in['lang'];
        }
        if (($in['email'] ?? '') === 'failed') {
            $where[] = "EXISTS (SELECT 1 FROM emails e WHERE e.registration_id = r.id AND e.status = 'failed')";
        }
        if (($in['dup'] ?? '') === '1') {
            $where[] = 'r.possible_duplicate = 1';
        }
        return [implode(' AND ', $where), $params];
    }

    public static function from(): string
    {
        return 'registrations r LEFT JOIN tickets t ON t.registration_id = r.id';
    }

    /** The current filters as a query string (for paging and the export link). */
    public static function queryString(array $in, array $extra = []): string
    {
        $keep = [];
        foreach (self::FILTERS as $key) {
            if (isset($in[$key]) && $in[$key] !== '') {
                $keep[$key] = (string) $in[$key];
            }
        }
        return http_build_query(array_merge($keep, $extra));
    }
}
