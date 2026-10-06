<?php
declare(strict_types=1);

namespace Ismile;

/** The public table and staff booking rules read the same floor-plan data. */
final class BoothPlan
{
    public const TIERS = ['platinum' => 'Platinum', 'gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze'];
    private static ?array $numbers = null;

    public static function numbers(?string $tier): array
    {
        if (self::$numbers === null) {
            $plan = json_decode((string) file_get_contents(App::siteFile('data/booth-tiers.json')), true, 512, JSON_THROW_ON_ERROR);
            $numbers = array_fill_keys(array_keys(self::TIERS), []);
            $seen = [];
            foreach ($plan['rows'] ?? [] as $row) {
                if (!isset($numbers[$row['tier'] ?? ''])) throw new \RuntimeException('Unknown floor-plan tier.');
                foreach ($row['numbers'] ?? [] as $number) {
                    if (!is_int($number) || $number < 1 || $number > 44 || isset($seen[$number])) throw new \RuntimeException('Invalid floor-plan numbering.');
                    $seen[$number] = true;
                    $numbers[$row['tier']][] = $number;
                }
            }
            if (count($seen) !== 44) throw new \RuntimeException('The floor plan must contain all 44 booths.');
            foreach ($numbers as &$list) sort($list, SORT_NUMERIC);
            unset($list);
            self::$numbers = $numbers;
        }
        return self::$numbers[$tier ?? ''] ?? [];
    }

    public static function check(?array $package, ?string $number): void
    {
        if ($number === null) return;
        if ($package === null || !in_array((int) $number, self::numbers($package['booth_tier'] ?? null), true)) {
            throw new UserError('Choose a booth number that belongs to the selected package.');
        }
    }
}
