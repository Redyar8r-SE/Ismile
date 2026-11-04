<?php
declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\Db;

final class DashboardIncome
{
    /** Confirmed income by source and currency, before Association handovers. */
    public static function totals(): array
    {
        $totals = ['tickets' => [], 'workshops' => [], 'total' => []];
        $rows = Db::all("SELECT 'tickets' AS source, p.currency, SUM(p.amount_confirmed) AS amount
            FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id
            WHERE p.status = 'paid' AND (r.id IS NULL OR r.status <> 'cancelled')
            GROUP BY p.currency
            UNION ALL
            SELECT 'workshops' AS source, w.currency, SUM(b.amount_paid) AS amount
            FROM workshop_bookings b
            JOIN workshops w ON w.id = b.workshop_id
            JOIN registrations r ON r.id = b.registration_id
            WHERE b.payment_status = 'paid' AND b.removed_at IS NULL AND r.status <> 'cancelled'
            GROUP BY w.currency");
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $amount = (int) $row['amount'];
            $totals[$row['source']][$currency] = $amount;
            $totals['total'][$currency] = ($totals['total'][$currency] ?? 0) + $amount;
        }
        foreach ($totals as &$amounts) {
            ksort($amounts);
        }
        return $totals;
    }
}
