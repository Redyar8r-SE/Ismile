<?php
// Every field is checked again on the server, whatever the browser did.

declare(strict_types=1);

namespace Ismile;

final class Validate
{
    /** Trims, removes control characters and limits length. */
    public static function text(mixed $value, int $max): string
    {
        $text = is_string($value) ? $value : '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, $max);
    }

    public static function multiline(mixed $value, int $max): string
    {
        $text = is_string($value) ? $value : '';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        return mb_substr(trim($text), 0, $max);
    }

    /** A name part: 2–60 characters with at least one letter (any script), as the form checks. */
    public static function namePart(string $value): bool
    {
        return mb_strlen($value) >= 2 && mb_strlen($value) <= 60 && (bool) preg_match('/\p{L}/u', $value);
    }

    public static function email(string $value): bool
    {
        return strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Iraqi mobiles (0750 123 4567, +964 750 123 4567) or any international
     * number starting with +. Returns the number as +9647XXXXXXXXX or +<digits>,
     * or null when it is not a phone number. Mirrors isValidPhone() in the form.
     */
    public static function phone(string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', $value) ?? '';
        if (preg_match('/^(?:\+964|00964|964)(7\d{9})$/', $digits, $m)) {
            return '+964' . $m[1];
        }
        if (preg_match('/^0(7\d{9})$/', $digits, $m)) {
            return '+964' . $m[1];
        }
        if (preg_match('/^\+\d{8,15}$/', $digits) && !str_starts_with($digits, '+964')) {
            return $digits;
        }
        return null;
    }

    public static function oneOf(mixed $value, array $allowed, ?string $default = null): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
