<?php

declare(strict_types=1);

namespace Ismile\Payments;

/** The payment company cannot be used right now (not configured, down, or refused). */
final class GatewayNotReady extends \RuntimeException
{
}
