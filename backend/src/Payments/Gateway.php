<?php
// What any payment company must do for us. Three connections, as in the plan:
//   A  createPayment  – our server asks for a payment and gets a payment page link
//   B  readWebhook    – the company tells us "payment X changed"; we check it is genuine
//   C  fetchStatus    – our server asks "what is the status of payment X?"
// Only C is ever believed. B only tells us which payment to ask about.

declare(strict_types=1);

namespace Ismile\Payments;

interface Gateway
{
    public function name(): string;

    /**
     * @param array $payment      the payments row (amount_expected, currency, method...)
     * @param array $registration the registrations row
     * A gateway that sets the price itself (Psoola) also returns the amount
     * and currency of the payment it created; that becomes amount_expected.
     *
     * @return array{id: string, redirect_url: string, raw: string, amount?: int, currency?: string}
     */
    public function createPayment(array $payment, array $registration): array;

    /**
     * @return array{status: 'paid'|'failed'|'waiting'|'expired', amount: ?int, currency: ?string, raw: string}
     */
    public function fetchStatus(string $providerPaymentId): array;

    /**
     * Checks the message really came from the payment company.
     * Returns the payment id it is about, '' when it is genuine but about no
     * payment, or null when it is not genuine.
     */
    public function readWebhook(array $headers, string $body): ?string;
}
