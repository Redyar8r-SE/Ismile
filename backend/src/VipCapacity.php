<?php
declare(strict_types=1);
namespace Ismile;

final class VipCapacity
{
    public const LIMIT = 6;

    /** Acquire before any checkout/payment locks or snapshot reads. */
    public static function lockFor(array $checkout): void
    {
        if (($checkout['ticket_type'] ?? '') === 'vip') {
            // This existing settings row is the shared VIP allocation mutex.
            Db::one("SELECT k FROM settings WHERE k='registration_open' FOR UPDATE");
        }
    }

    public static function full(bool $includePending = true, int $exceptCheckout = 0): bool
    {
        $taken = (int) Db::value("SELECT COUNT(*) FROM registrations WHERE ticket_type='vip' AND status IN ('paid','complimentary')");
        $held = $includePending ? (int) Db::value("SELECT COUNT(DISTINCT c.id) FROM checkouts c JOIN payments p ON p.checkout_id=c.id WHERE c.ticket_type='vip' AND c.status='open' AND c.id<>? AND p.status IN ('created','waiting')", [$exceptCheckout]) : 0;
        return $taken + $held >= self::LIMIT;
    }

    public static function requireAvailable(array $checkout): void
    {
        if (($checkout['ticket_type'] ?? '') === 'vip' && self::full(true, (int) ($checkout['id'] ?? 0))) {
            throw new UserError('vip_unavailable', null, 409);
        }
    }
}
