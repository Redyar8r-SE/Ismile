<?php
// The three languages of the site.

declare(strict_types=1);

namespace Ismile;

final class Lang
{
    public const ALL = ['en', 'ar', 'ku'];

    public static function pick(mixed $lang): string
    {
        return is_string($lang) && in_array($lang, self::ALL, true) ? $lang : 'en';
    }

    public static function isRtl(string $lang): bool
    {
        return $lang === 'ar' || $lang === 'ku';
    }
}
