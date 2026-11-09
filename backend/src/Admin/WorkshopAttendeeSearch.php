<?php
declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\Db;
use Ismile\Validate;

/** The same attendee lookup serves live suggestions and the ordinary GET form. */
final class WorkshopAttendeeSearch
{
    public static function find(string $query, bool $paidOnly, int $offset = 0): array
    {
        $query = Validate::text($query, 190);
        $offset = max(0, min(100000, $offset));
        if ($query === '') return ['people'=>[], 'total'=>0, 'offset'=>$offset];
        $prefix = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query).'%';
        $where = $paidOnly ? "r.status='paid'" : "r.status IN ('paid','complimentary')";
        // Letter-by-letter name search always starts at the first name: A -> Ab.
        $match = "CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ?";
        $params = [$prefix];
        if (!preg_match("/^[\p{L}\p{M}\s'’\-]+$/u", $query)) {
            $match .= ' OR r.email LIKE ? OR r.ref LIKE ?';
            array_push($params, $prefix, $prefix);
            $phone = GuestLookup::phonePrefix(strtr($query, array_combine(
                preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
                str_split('01234567890123456789')
            )));
            if ($phone !== null) {
                $match .= ' OR r.phone LIKE ?';
                $params[] = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $phone).'%';
            }
        }
        $where .= ' AND ('.$match.')';
        $total = (int) Db::value('SELECT COUNT(*) FROM registrations r WHERE '.$where, $params);
        $people = Db::all('SELECT r.id,r.ref,r.first_name,r.father_name,r.grandfather_name,r.phone,r.email FROM registrations r WHERE '.$where.' ORDER BY r.first_name,r.father_name,r.grandfather_name,r.id LIMIT 25 OFFSET '.$offset, $params);
        return ['people'=>$people, 'total'=>$total, 'offset'=>$offset];
    }
}
