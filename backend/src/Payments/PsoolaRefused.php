<?php
// PSOOLA answered and said no (sold out, limit reached, ...). The message is
// written for the office; the detail goes to the log and the payment record.

declare(strict_types=1);

namespace Ismile\Payments;

final class PsoolaRefused extends \RuntimeException
{
    public function __construct(string $friendly, public readonly string $detail)
    {
        parent::__construct($friendly);
    }
}
