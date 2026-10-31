<?php
declare(strict_types=1);

namespace Ismile;

/** Immutable reservation IDs and their current, user-facing map numbers. */
final class BoothPlan
{
    public const COUNT = 39;
    public const TIERS = ['platinum' => 'Platinum', 'gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze'];
    private static ?array $positions = null;

    public static function positions(): array
    {
        if (self::$positions !== null) return self::$positions;
        $path = rtrim((string) App::config('site_root', dirname(__DIR__, 2)), '/\\') . '/data/booth-tiers.json';
        $plan = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $positions = [];
        $labels = [];
        foreach ($plan['rows'] ?? [] as $row) {
            if (!isset(self::TIERS[$row['tier'] ?? ''])) throw new \RuntimeException('Unknown floor-plan tier.');
            if (count($row['numbers'] ?? []) !== count($row['labels'] ?? [])) throw new \RuntimeException('Every map position needs a display number.');
            foreach ($row['numbers'] as $index => $id) {
                $label = $row['labels'][$index];
                if (!is_int($id) || $id < 1 || $id > 44 || isset($positions[$id])
                    || !is_int($label) || $label < 1 || $label > self::COUNT || isset($labels[$label])) {
                    throw new \RuntimeException('Invalid or duplicate floor-plan numbering.');
                }
                $labels[$label] = true;
                $positions[$id] = ['position_id' => $id, 'display_number' => $label, 'tier' => $row['tier'], 'size' => $row['size']];
            }
        }
        if (count($positions) !== self::COUNT || array_diff(range(1, self::COUNT), array_keys($labels))) {
            throw new \RuntimeException('The floor plan must show every number from 1 to 39 once.');
        }
        ksort($positions, SORT_NUMERIC);
        return self::$positions = $positions;
    }

    /** Form option values and reservation checks always use stable IDs. */
    public static function numbers(?string $tier): array
    {
        return array_keys(array_filter(self::positions(), static fn (array $p): bool => $p['tier'] === $tier));
    }

    public static function labels(): array
    {
        return array_map(static fn (array $p): int => $p['display_number'], self::positions());
    }

    public static function label(mixed $id): string
    {
        if ($id === null || $id === '') return '';
        $text = trim((string) $id);
        if (!ctype_digit($text)) return $text;
        $position = self::positions()[(int) $text] ?? null;
        return $position ? (string) $position['display_number'] : 'Former position ' . $text . ' (removed)';
    }

    public static function active(mixed $id): bool
    {
        return ctype_digit((string) $id) && isset(self::positions()[(int) $id]);
    }

    public static function count(): int
    {
        return count(self::positions());
    }

    /** Populate the database directory without updating a single booking. */
    public static function syncDatabase(): void
    {
        Db::transaction(static function (): void {
            $positions = self::positions();
            foreach (Db::all("SELECT reserved_booth FROM sponsor_requests WHERE kind='sponsor' AND reserved_booth IS NOT NULL FOR UPDATE") as $row) {
                if (!self::active($row['reserved_booth'])) {
                    throw new \RuntimeException('Cannot remove reserved map position ' . $row['reserved_booth'] . '. Resolve its reservation before deploying.');
                }
            }
            foreach ($positions as $position) {
                Db::run('INSERT INTO sponsor_map_positions (position_id, display_number, tier, size) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE display_number=VALUES(display_number), tier=VALUES(tier), size=VALUES(size)', array_values($position));
            }
            $ids = array_keys($positions);
            Db::run('DELETE FROM sponsor_map_positions WHERE position_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            self::assertDatabaseMatches();
        });
    }

    public static function assertDatabaseMatches(): void
    {
        $rows = Db::all('SELECT position_id, display_number, tier, size FROM sponsor_map_positions ORDER BY position_id');
        $expected = array_values(self::positions());
        if (count($rows) !== count($expected)) throw new \RuntimeException('Database and website floor plans do not match.');
        foreach ($rows as $index => $row) {
            $row['position_id'] = (int) $row['position_id'];
            $row['display_number'] = (int) $row['display_number'];
            if ($row !== $expected[$index]) throw new \RuntimeException('Database and website floor-plan numbering do not match.');
        }
    }

    /** Keep canonical tier cards/capacities aligned without changing prices. */
    public static function syncPackageCounts(): void
    {
        Db::transaction(static function (): void {
            foreach (array_keys(self::TIERS) as $tier) {
                $package = Db::one("SELECT * FROM sponsor_packages WHERE id=? AND kind='sponsor' FOR UPDATE", [$tier]);
                if (!$package) continue;
                $count = count(self::numbers($tier));
                $confirmed = (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE package_id=? AND status='confirmed'", [$tier]);
                if ($confirmed > $count) throw new \RuntimeException('The ' . $tier . ' package has more confirmed sponsors than its new map capacity.');
                $change = ['places' => $count];
                foreach (['en', 'ar', 'ku'] as $lang) {
                    $key = 'subtitle_' . $lang;
                    if (is_string($package[$key]) && preg_match('/^(6|7|21|10)(?=\s)/u', $package[$key])) {
                        $change[$key] = preg_replace('/^\d+/u', (string) $count, $package[$key]);
                    }
                }
                Db::update('sponsor_packages', $change, 'id=?', [$tier]);
            }
        });
    }

    public static function check(?array $package, ?string $number): void
    {
        if ($number === null) return;
        if (($package['kind']??null)==='booth') throw new UserError('Only sponsors use numbered positions on the map.');
        if ($package === null || !in_array((int) $number, self::numbers($package['booth_tier'] ?? null), true)) {
            throw new UserError('Choose a booth number that belongs to the selected package on the current map.');
        }
        self::assertPositionMatches($number);
    }

    public static function assertPositionMatches(string $number): void
    {
        if (!self::active($number)) throw new UserError('That position is no longer on the current map. Choose an available booth.');
        // Refuse writes if deployment has left the database on a different map.
        $stored = Db::one('SELECT display_number, tier, size FROM sponsor_map_positions WHERE position_id=?', [(int) $number]);
        $expected = self::positions()[(int) $number];
        if (!$stored || (int) $stored['display_number'] !== $expected['display_number']
            || $stored['tier'] !== $expected['tier'] || $stored['size'] !== $expected['size']) {
            throw new UserError('The map is being updated. Reload this page before booking a position.');
        }
    }
}
