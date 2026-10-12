<?php
// A payment company that can record a sale the office was paid for in cash:
// the ticket is issued at once, with no payment page (PSOOLA: POST /orders).

declare(strict_types=1);

namespace Ismile\Payments;

interface CashSales
{
    /**
     * Records a cash sale for a form. The answer is believed only after
     * Payments::check() asked the company again (fetchStatus).
     *
     * @param array $payment  the payments row (id, amount_expected, currency, method 'cash')
     * @param array $checkout the form being sold
     * @return array{id: string, raw: string, amount: int, currency: string}
     */
    public function createCashSale(array $payment, array $checkout): array;
}
