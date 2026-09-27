<?php
// Tokens, signatures and escaping.

declare(strict_types=1);

namespace Ismile;

final class Security
{
    /** A long random token for links (43 characters, 256 bits). */
    public static function token(): string
    {
        return self::b64url(random_bytes(32));
    }

    /** Tokens are stored only as their hash, so a copied database cannot use them. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function sign(string $text): string
    {
        return self::b64url(substr(hash_hmac('sha256', $text, (string) App::config('secret'), true), 0, 16));
    }

    public static function verify(string $text, string $signature): bool
    {
        return hash_equals(self::sign($text), $signature);
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** A reference such as ISM26-7KQ2XM: no 0/O or 1/I, so it is easy to read out. */
    public static function reference(string $prefix, int $length): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $prefix . '-' . $out;
    }

    /** Escapes text for HTML, so typed-in text always stays text. */
    public static function e(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
