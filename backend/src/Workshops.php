<?php
// The workshops: kept in the database and managed on the admin's Workshops
// page (Owner). The website's workshop cards (data/workshops.json) are written
// from here after every change, so the website always shows the real seats.
// total_seats is the limit: no booking can go past it (Owner override only).

declare(strict_types=1);

namespace Ismile;

final class Workshops
{
    /** The pictures a workshop card can have (js/config/icons.js WORKSHOP_ICONS). */
    public const ICONS = ['tools', 'tooth', 'implant', 'smile', 'root', 'scan', 'braces', 'drill'];

    /** Workshops in the website's shape: title/company/speaker as {en, ar, ku} or null. */
    public static function all(bool $withHidden = false): array
    {
        $rows = Db::all('SELECT * FROM workshops' . ($withHidden ? '' : " WHERE status = 'active'") . ' ORDER BY sort_order, created_at, id');
        $booked = self::bookedCounts();
        return array_map(static fn (array $row): array => self::shape($row, $booked[$row['id']] ?? 0), $rows);
    }

    public static function find(string $id): ?array
    {
        $row = Db::one('SELECT * FROM workshops WHERE id = ?', [$id]);
        return $row ? self::shape($row, self::booked($id)) : null;
    }

    public static function name(array $workshop, string $lang = 'en'): string
    {
        $title = $workshop['title'] ?? null;
        if (is_array($title)) {
            $title = ($title[$lang] ?? '') !== '' ? $title[$lang] : ($title['en'] ?? null);
        }
        return $title ? (string) $title : ucfirst((string) $workshop['id']) . ' workshop';
    }

    public static function booked(string $id): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ? AND removed_at IS NULL', [$id]);
    }

    private static function bookedCounts(): array
    {
        $out = [];
        foreach (Db::all('SELECT workshop_id, COUNT(*) AS n FROM workshop_bookings WHERE removed_at IS NULL GROUP BY workshop_id') as $row) {
            $out[$row['workshop_id']] = (int) $row['n'];
        }
        return $out;
    }

    private static function shape(array $row, int $booked): array
    {
        $i18n = static function (string $field) use ($row): ?array {
            $values = ['en' => $row[$field . '_en'], 'ar' => $row[$field . '_ar'], 'ku' => $row[$field . '_ku']];
            return array_filter($values, static fn ($v) => $v !== null && $v !== '') ? array_map(static fn ($v) => (string) ($v ?? ''), $values) : null;
        };
        return [
            'id' => $row['id'], 'icon' => $row['icon'], 'title' => $i18n('title'), 'company' => $i18n('company'), 'speaker' => $i18n('speaker'),
            'price' => (int) $row['price'], 'totalSeats' => (int) $row['total_seats'], 'seatsLeft' => max(0, (int) $row['total_seats'] - $booked),
            'booked' => $booked, 'status' => $row['status'], 'sortOrder' => (int) $row['sort_order'],
        ];
    }

    // ---------------- changes (Owner) ----------------

    /** A new workshop. Returns its id. */
    public static function create(array $in, array $user): string
    {
        self::requireOwner($user);
        $fields = self::fields($in);
        if ($fields['title_en'] === null && $fields['title_ku'] === null && $fields['title_ar'] === null) {
            throw new UserError('Write the workshop name (at least in one language).');
        }
        $id = self::newId((string) ($fields['title_en'] ?? $fields['title_ku'] ?? $fields['title_ar']));
        $now = App::now();
        Db::insert('workshops', $fields + [
            'id' => $id, 'status' => 'active',
            'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM workshops'),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Audit::log((int) $user['id'], 'workshop.create', 'workshop', null, ['id' => $id] + $fields);
        self::syncWebsite();
        return $id;
    }

    /** Name, price, seats, picture. The seats can never go below the people already booked. */
    public static function update(string $id, array $in, array $user): void
    {
        self::requireOwner($user);
        $current = self::find($id) ?? throw new UserError('Workshop not found.');
        $fields = self::fields($in);
        if ($fields['total_seats'] < $current['booked']) {
            throw new UserError("{$current['booked']} people are already booked, so the seats cannot be fewer than {$current['booked']}.");
        }
        Db::update('workshops', $fields + ['updated_at' => App::now()], 'id = ?', [$id]);
        Audit::log((int) $user['id'], 'workshop.edit', 'workshop', null, ['id' => $id] + $fields);
        self::syncWebsite();
    }

    /** +1 / -1 seat (or any number). */
    public static function changeSeats(string $id, int $delta, array $user): int
    {
        self::requireOwner($user);
        $current = self::find($id) ?? throw new UserError('Workshop not found.');
        $seats = $current['totalSeats'] + $delta;
        if ($seats < max(1, $current['booked'])) {
            throw new UserError($current['booked'] > 0
                ? "{$current['booked']} people are booked: the seats cannot be fewer than that. Remove a booking first."
                : 'A workshop needs at least 1 seat.');
        }
        if ($seats > 2000) {
            throw new UserError('That is too many seats.');
        }
        Db::update('workshops', ['total_seats' => $seats, 'updated_at' => App::now()], 'id = ?', [$id]);
        Audit::log((int) $user['id'], 'workshop.seats', 'workshop', null, ['id' => $id, 'from' => $current['totalSeats'], 'to' => $seats]);
        self::syncWebsite();
        return $seats;
    }

    /** Hidden: not on the website and no new bookings; the people booked stay. */
    public static function setStatus(string $id, string $status, array $user): void
    {
        self::requireOwner($user);
        if (!in_array($status, ['active', 'hidden'], true) || self::find($id) === null) {
            throw new UserError('Workshop not found.');
        }
        Db::update('workshops', ['status' => $status, 'updated_at' => App::now()], 'id = ?', [$id]);
        Audit::log((int) $user['id'], 'workshop.' . ($status === 'hidden' ? 'hide' : 'show'), 'workshop', null, ['id' => $id]);
        self::syncWebsite();
    }

    /** Deleted for good: only a workshop nobody was ever booked on. Otherwise hide it. */
    public static function delete(string $id, array $user): void
    {
        self::requireOwner($user);
        if (self::find($id) === null) {
            throw new UserError('Workshop not found.');
        }
        if (Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ?', [$id])) {
            throw new UserError('People have been booked on this workshop, so it cannot be deleted (their history stays). Hide it instead.');
        }
        Db::run('DELETE FROM workshops WHERE id = ?', [$id]);
        Audit::log((int) $user['id'], 'workshop.delete', 'workshop', null, ['id' => $id]);
        self::syncWebsite();
    }

    private static function requireOwner(array $user): void
    {
        if (($user['role'] ?? '') !== 'owner') {
            throw new UserError('Only the Owner can add or change workshops.');
        }
    }

    /** The form, checked. */
    private static function fields(array $in): array
    {
        $text = static fn (string $key): ?string => Validate::text($in[$key] ?? '', 160) ?: null;
        $seats = filter_var($in['total_seats'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2000]]);
        if ($seats === false) {
            throw new UserError('Seats must be a number from 1 to 2000.');
        }
        $price = preg_replace('/\D/', '', (string) ($in['price'] ?? '')) ?? '';
        return [
            'title_en' => $text('title_en'), 'title_ar' => $text('title_ar'), 'title_ku' => $text('title_ku'),
            'company_en' => $text('company_en'), 'company_ar' => $text('company_ar'), 'company_ku' => $text('company_ku'),
            'speaker_en' => $text('speaker_en'), 'speaker_ar' => $text('speaker_ar'), 'speaker_ku' => $text('speaker_ku'),
            'price' => $price === '' ? 0 : min((int) $price, 100000000),
            'total_seats' => $seats,
            'icon' => Validate::oneOf($in['icon'] ?? null, self::ICONS, 'tools'),
        ];
    }

    private static function newId(string $title): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
        $base = substr($base !== '' && preg_match('/[a-z]/', $base) ? $base : 'workshop', 0, 30);
        $id = $base;
        for ($n = 2; Db::value('SELECT id FROM workshops WHERE id = ?', [$id]); $n++) {
            $id = substr($base, 0, 26) . '-' . $n;
        }
        return strlen($id) < 2 ? 'ws-' . $id : $id;
    }

    // ---------------- the website ----------------

    /** Writes the website's workshop cards from the database (active workshops only). */
    public static function syncWebsite(): void
    {
        $file = App::siteFile('data/workshops.json');
        $cards = array_map(static fn (array $w): array => [
            'id' => $w['id'], 'icon' => $w['icon'], 'title' => $w['title'], 'company' => $w['company'], 'speaker' => $w['speaker'],
            'price' => $w['price'], 'totalSeats' => $w['totalSeats'], 'seatsLeft' => $w['seatsLeft'],
        ], self::all());
        $json = json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $temp = $file . '.tmp';
        if (@file_put_contents($temp, $json, LOCK_EX) !== false) {
            @rename($temp, $file);
        }
    }

    /**
     * First install: the workshops already on the website become the starting
     * list in the database. Does nothing once the table has workshops.
     */
    public static function importFromWebsite(): int
    {
        if ((int) Db::value('SELECT COUNT(*) FROM workshops') > 0) {
            return 0;
        }
        $file = App::siteFile('data/workshops.json');
        $list = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($list)) {
            return 0;
        }
        $now = App::now();
        $n = 0;
        foreach ($list as $item) {
            if (!is_array($item) || !preg_match('/^[a-z0-9-]{2,40}$/', (string) ($item['id'] ?? ''))) {
                continue;
            }
            $part = static function (string $field, string $lang) use ($item): ?string {
                $value = $item[$field] ?? null;
                $value = is_array($value) ? ($value[$lang] ?? null) : ($lang === 'en' ? $value : null);
                return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 160) : null;
            };
            $row = ['id' => $item['id'], 'icon' => in_array($item['icon'] ?? '', self::ICONS, true) ? $item['icon'] : 'tools',
                'price' => max(0, (int) ($item['price'] ?? 0)), 'total_seats' => min(2000, max(1, (int) ($item['totalSeats'] ?? 20))),
                'status' => 'active', 'sort_order' => ++$n, 'created_at' => $now, 'updated_at' => $now];
            foreach (['title', 'company', 'speaker'] as $field) {
                foreach (['en', 'ar', 'ku'] as $lang) {
                    $row[$field . '_' . $lang] = $part($field, $lang);
                }
            }
            Db::insert('workshops', $row);
        }
        return $n;
    }
}
