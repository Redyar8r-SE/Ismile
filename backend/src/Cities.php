<?php
declare(strict_types=1);
namespace Ismile;

/** The public, office, guest and company forms share one list of capitals. */
final class Cities
{
    public static function all(): array
    {
        static $cities;
        if ($cities === null) {
            $file = App::siteFile('data/cities.json');
            if (!is_file($file)) $file = dirname(__DIR__, 2) . '/data/cities.json';
            $cities = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }
        return $cities;
    }

    public static function label(string $locale = 'en'): string
    {
        return match ($locale) { 'ar' => 'المدينة', 'ku', 'ckb' => 'شار', 'kmr' => 'Bajar', default => 'City' };
    }

    public static function select(mixed $current = '', string $locale = 'en'): string
    {
        $current = Validate::text($current, 80);
        $language = in_array($locale, ['ku', 'ckb'], true) ? 'ku' : ($locale === 'ar' ? 'ar' : 'en');
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $placeholder = match ($locale) { 'ar' => 'اختر مدينتك', 'ku', 'ckb' => 'شارەکەت هەڵبژێرە', 'kmr' => 'Bajar hilbijêre', default => 'Choose your city' };
        $html = '<select name="city" autocomplete="address-level2"><option value="">' . $escape($placeholder) . '</option>';
        $matched = $current === '';
        foreach (self::all() as $city) {
            $selected = $city['value'] === $current;
            $matched = $matched || $selected;
            $html .= '<option value="' . $escape($city['value']) . '"' . ($selected ? ' selected' : '') . '>' . $escape($city['name'][$language]) . '</option>';
        }
        // Keep an existing city intact when editing older registrations.
        if (!$matched) $html .= '<option value="' . $escape($current) . '" selected>' . $escape($current) . '</option>';
        return $html . '</select>';
    }
}
