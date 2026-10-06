<?php
// Six-digit codes from a phone app (Google Authenticator, Microsoft
// Authenticator, Authy...), RFC 6238. Used for Owner and Finance sign-in.

declare(strict_types=1);

namespace Ismile;

final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function uri(string $secret, string $email): string
    {
        return 'otpauth://totp/' . rawurlencode('iSmile admin:' . $email) . '?secret=' . $secret . '&issuer=' . rawurlencode('iSmile admin');
    }

    /** Accepts the current code and the ones just before and after (clock drift). */
    public static function verify(string $secret, string $code): bool
    {
        return self::matchStep($secret, $code) !== null;
    }

    /**
     * Like verify(), but returns the 30-second step the code belongs to (or
     * null). Storing the step lets sign-in refuse a code that was already used.
     */
    public static function matchStep(string $secret, string $code, int $after = -1): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $step = intdiv(time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if ($step + $offset > $after && hash_equals(self::code($secret, $step + $offset), $code)) {
                return $step + $offset;
            }
        }
        return null;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
