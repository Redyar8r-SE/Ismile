<?php
// Personal links: the link to a person's own payment / ticket page, and the
// "Pay now" link. Each is a signature over a random value kept in the
// database, so it cannot be guessed. A form waiting for payment and the
// registration it becomes share the reference and the value, so the same
// link works before and after paying.

declare(strict_types=1);

namespace Ismile;

final class Links
{
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function mac(string $purpose, string $ref, string $nonce): string
    {
        return Security::b64url(substr(hash_hmac('sha256', "$purpose|$ref|$nonce", (string) App::config('secret'), true), 0, 24));
    }

    public static function viewToken(array $record): string
    {
        return self::mac('view', $record['ref'], $record['view_nonce']);
    }

    public static function viewMatches(array $record, string $token): bool
    {
        return $token !== '' && hash_equals(self::viewToken($record), $token);
    }

    public static function statusUrl(array $record): string
    {
        return App::url('payment.html?r=' . rawurlencode($record['ref']) . '&k=' . self::viewToken($record));
    }

    /**
     * The "Pay now" link for a form waiting for payment (a phone registration).
     * It uses the same personal signature as the status page and works until
     * the form expires.
     */
    public static function payUrl(array $checkout): string
    {
        return App::url('api/pay.php?r=' . rawurlencode($checkout['ref']) . '&k=' . self::viewToken($checkout));
    }
}
