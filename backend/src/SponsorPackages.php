<?php
// The sponsorship packages (Platinum, Gold, Silver, Bronze) and the
// exhibition booth types: kept in the database with their price and number of
// places, managed on the admin's Sponsors page (Owner). The website's tier
// cards (data/sponsors.json "tiers") are written from here after every change.
// Prices never go to the website: the team tells them on the phone.

declare(strict_types=1);

namespace Ismile;

final class SponsorPackages
{
    /** The colours a website tier card can have (css tier classes). */
    public const STYLES = [
        'tc-dia' => 'Diamond (light blue)', 'tc-plat' => 'Platinum', 'tc-gold' => 'Gold',
        'tc-silver' => 'Silver', 'tc-bronze' => 'Bronze', 'tc-exhibitor' => 'Exhibitor',
    ];

    public const KINDS = ['sponsor' => 'Sponsorship', 'booth' => 'Exhibition booth'];

    /** Packages with how many are confirmed, in their order. */
    public static function all(?string $kind = null, bool $withHidden = true): array
    {
        $where = [];
        $params = [];
        if ($kind !== null) {
            $where[] = 'p.kind = ?';
            $params[] = $kind;
        }
        if (!$withHidden) {
            $where[] = "p.status = 'active'";
        }
        return Db::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM sponsor_requests s WHERE s.package_id = p.id AND s.status = 'confirmed') AS confirmed,
                    (SELECT COUNT(*) FROM sponsor_requests s WHERE s.package_id = p.id) AS requests
             FROM sponsor_packages p" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY p.kind, p.sort_order, p.id',
            $params
        );
    }

    public static function find(?string $id): ?array
    {
        return $id === null || $id === '' ? null : Db::one('SELECT * FROM sponsor_packages WHERE id = ?', [$id]);
    }

    public static function standardBooth(bool $activeOnly = false): ?array
    {
        $package=self::find('booth-standard');
        return $package && $package['kind']==='booth' && (!$activeOnly || $package['status']==='active')?$package:null;
    }

    public static function name(?array $package, string $lang = 'en'): string
    {
        if ($package === null) {
            return '';
        }
        $name = (string) ($package['name_' . $lang] ?? '');
        return $name !== '' ? $name : (string) $package['name_en'];
    }

    // ---------------- changes (Owner) ----------------

    public static function create(array $in, array $user): string
    {
        self::requireOwner($user);
        $kind = Validate::oneOf($in['kind'] ?? null, array_keys(self::KINDS), 'sponsor');
        $fields = self::fields($in,$kind);
        if ($kind==='booth' && self::standardBooth()!==null) throw new UserError('There is one Standard booth type. Edit its price and places instead.');
        $id = $kind==='booth'?'booth-standard':self::newId($fields['name_en']);
        $now = App::now();
        Db::insert('sponsor_packages', $fields + [
            'id' => $id, 'kind' => $kind, 'status' => 'active',
            'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sponsor_packages WHERE kind = ?', [$kind]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log((int) $user['id'], 'package.create', 'sponsor_package', null, ['id' => $id, 'kind' => $kind] + $fields);
        self::syncWebsite();
        return $id;
    }

    /** Name, price, places, colour. The places can never go below the confirmed companies. */
    public static function update(string $id, array $in, array $user): void
    {
        self::requireOwner($user);
        $package=self::find($id)??throw new UserError('Package not found.');
        if ($package['kind']==='booth' && $id!=='booth-standard') throw new UserError('Legacy booth types are archived. Edit the Standard booth instead.');
        $fields = self::fields($in,$package['kind']);
        Db::transaction(static function () use ($id, $fields): void {
            if (!Db::value('SELECT id FROM sponsor_packages WHERE id = ? FOR UPDATE', [$id])) {
                throw new UserError('Package not found.');
            }
            $confirmed = (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE package_id = ? AND status = 'confirmed'", [$id]);
            if ($fields['places'] > 0 && $fields['places'] < $confirmed) {
                throw new UserError("$confirmed companies are already confirmed on this package, so the places cannot be fewer than $confirmed.");
            }
            Db::update('sponsor_packages', $fields + ['updated_at' => App::now()], 'id = ?', [$id]);
        });
        Audit::log((int) $user['id'], 'package.edit', 'sponsor_package', null, ['id' => $id] + $fields);
        self::syncWebsite();
    }

    /** Hidden: not on the website or the form; the companies on it stay. */
    public static function setStatus(string $id, string $status, array $user): void
    {
        self::requireOwner($user);
        if (!in_array($status, ['active', 'hidden'], true) || self::find($id) === null) {
            throw new UserError('Package not found.');
        }
        if ($status==='active' && self::find($id)['kind']==='booth' && $id!=='booth-standard') throw new UserError('Only the Standard booth type can be offered.');
        Db::update('sponsor_packages', ['status' => $status, 'updated_at' => App::now()], 'id = ?', [$id]);
        Audit::log((int) $user['id'], 'package.' . ($status === 'hidden' ? 'hide' : 'show'), 'sponsor_package', null, ['id' => $id]);
        self::syncWebsite();
    }

    /** Deleted for good: only a package no company ever chose. Otherwise hide it. */
    public static function delete(string $id, array $user): void
    {
        self::requireOwner($user);
        if (self::find($id) === null) {
            throw new UserError('Package not found.');
        }
        if (Db::value('SELECT COUNT(*) FROM sponsor_requests WHERE package_id = ?', [$id])) {
            throw new UserError('Companies have chosen this package, so it cannot be deleted (their record stays). Hide it instead.');
        }
        Db::run('DELETE FROM sponsor_packages WHERE id = ?', [$id]);
        Audit::log((int) $user['id'], 'package.delete', 'sponsor_package', null, ['id' => $id]);
        self::syncWebsite();
    }

    private static function requireOwner(array $user): void
    {
        if (($user['role'] ?? '') !== 'owner') {
            throw new UserError('Only the Owner can add or change the packages and prices.');
        }
    }

    /** The form, checked. */
    private static function fields(array $in, string $kind): array
    {
        if ($kind==='booth') $in=array_replace($in,['name_en'=>'Standard booth','style'=>'tc-exhibitor','booth_tier'=>'']);
        $text = static fn (string $key, int $max): ?string => Validate::text($in[$key] ?? '', $max) ?: null;
        $name = $text('name_en', 80);
        if ($name === null) {
            throw new UserError('Write the package name in English (for example "Gold").');
        }
        $places = trim((string) ($in['places'] ?? ''));
        $places = $places === '' ? 0 : filter_var($places, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 500]]);
        if ($places === false) {
            throw new UserError('Places must be a number from 0 (no limit) to 500.');
        }
        $price = preg_replace('/\D/', '', (string) ($in['price'] ?? '')) ?? '';
        if ($price !== '' && (int) $price > 1000000000) {
            throw new UserError('That price is too big. Check the number of zeros.');
        }
        return [
            'name_en' => $name, 'name_ar' => $text('name_ar', 80), 'name_ku' => $text('name_ku', 80),
            'subtitle_en' => $text('subtitle_en', 160), 'subtitle_ar' => $text('subtitle_ar', 160), 'subtitle_ku' => $text('subtitle_ku', 160),
            'price' => $price === '' ? 0 : (int) $price,
            'places' => $places,
            'style' => Validate::oneOf($in['style'] ?? null, array_keys(self::STYLES), 'tc-silver'),
            'booth_tier' => array_key_exists('booth_tier', $in)
                ? Validate::oneOf($in['booth_tier'], array_keys(BoothPlan::TIERS))
                : (['tc-plat' => 'platinum', 'tc-gold' => 'gold', 'tc-silver' => 'silver', 'tc-bronze' => 'bronze'][$in['style'] ?? ''] ?? null),
        ];
    }

    private static function newId(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = substr($base !== '' && preg_match('/[a-z]/', $base) ? $base : 'package', 0, 30);
        $id = strlen($base) < 2 ? 'pk-' . $base : $base;
        for ($n = 2; Db::value('SELECT id FROM sponsor_packages WHERE id = ?', [$id]); $n++) {
            $id = substr($base, 0, 26) . '-' . $n;
        }
        return $id;
    }

    // ---------------- the website ----------------

    /**
     * Writes the website's tier cards from the database (shown sponsorship
     * packages only; no prices). The logos ("sponsors") and the contact
     * details ("enquiry") in the same file are left exactly as they are.
     */
    public static function syncWebsite(): void
    {
        $file = App::siteFile('data/sponsors.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = ['tiers' => [], 'sponsors' => [], 'enquiry' => ['whatsapp' => '', 'email' => '']];
        }
        $i18n = static fn (array $row, string $field): array => [
            'en' => (string) ($row[$field . '_en'] ?? ''), 'ar' => (string) ($row[$field . '_ar'] ?? ''), 'ku' => (string) ($row[$field . '_ku'] ?? ''),
        ];
        $data['tiers'] = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'name' => $i18n($row, 'name'), 'className' => $row['style'],
            'subtitle' => $i18n($row, 'subtitle'), 'spots' => (int) $row['places'],
        ], self::all('sponsor', false));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $temp = $file . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== false) {
            @rename($temp, $file);
        }
    }

    /**
     * First install: the tiers already on the website become the starting
     * packages (price not set yet), plus one Standard exhibition booth.
     * Does nothing once the table has packages.
     */
    public static function importFromWebsite(): int
    {
        if ((int) Db::value('SELECT COUNT(*) FROM sponsor_packages') > 0) {
            return 0;
        }
        $file = App::siteFile('data/sponsors.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $tiers = is_array($data['tiers'] ?? null) ? $data['tiers'] : [];
        $now = App::now();
        $n = 0;
        $part = static function (array $item, string $field, string $lang, int $max): ?string {
            $value = $item[$field] ?? null;
            $value = is_array($value) ? ($value[$lang] ?? null) : ($lang === 'en' ? $value : null);
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        };
        foreach ($tiers as $item) {
            if (!is_array($item) || !preg_match('/^[a-z0-9-]{2,40}$/', (string) ($item['id'] ?? ''))) {
                continue;
            }
            $row = ['id' => $item['id'], 'kind' => 'sponsor', 'price' => 0, 'places' => min(500, max(0, (int) ($item['spots'] ?? 0))),
                'style' => array_key_exists($item['className'] ?? '', self::STYLES) ? $item['className'] : 'tc-silver',
                'status' => 'active', 'sort_order' => ++$n, 'created_at' => $now, 'updated_at' => $now];
            foreach (['en', 'ar', 'ku'] as $lang) {
                $row['name_' . $lang] = $part($item, 'name', $lang, 80);
                $row['subtitle_' . $lang] = $part($item, 'subtitle', $lang, 160);
            }
            $row['name_en'] ??= ucfirst((string) $item['id']);
            $row['booth_tier'] = Validate::oneOf($item['id'], array_keys(BoothPlan::TIERS));
            Db::insert('sponsor_packages', $row);
        }
        Db::insert('sponsor_packages',['id'=>'booth-standard','kind'=>'booth','name_en'=>'Standard booth','price'=>0,'places'=>0,
            'style'=>'tc-exhibitor','booth_tier'=>null,'status'=>'active','sort_order'=>1,'created_at'=>$now,'updated_at'=>$now]);
        return $n + 1;
    }
}
