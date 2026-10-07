<?php
declare(strict_types=1);

namespace Ismile\Admin;

final class GuestLookup
{
    /** Prefix lookup for the welcome desk; SQL wildcards remain literal text. */
    public static function where(string $query): array
    {
        $query=trim($query);
        $escape=static fn(string $value): string => str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value).'%';
        $where="(CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ? OR r.ref LIKE ? OR t.ticket_no LIKE ?";
        $params=[$escape($query),$escape(strtoupper($query)),$escape(strtoupper($query))];
        $phone=self::phonePrefix($query);
        if ($phone!==null) {
            $where.=' OR r.phone LIKE ?'; $params[]=$escape($phone);
        }
        return [$where.')',$params];
    }

    public static function phonePrefix(string $query): ?string
    {
        $phone=preg_replace('/[\s().-]+/u','',trim($query))??'';
        if (preg_match('/^\+?\d+$/D',$phone)) {
            return match(true) {
                str_starts_with($phone,'00')=>'+' . substr($phone,2),
                str_starts_with($phone,'0')=>'+964' . substr($phone,1),
                str_starts_with($phone,'964')=>'+' . $phone,
                str_starts_with($phone,'7')=>'+964' . $phone,
                default=>$phone,
            };
        }
        return null;
    }
}
