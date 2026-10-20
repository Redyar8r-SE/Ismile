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

    /** A workshop is priced in dinars or dollars; its bookings use the same currency. */
    public const CURRENCIES = ['IQD', 'USD'];

    /** The details-page texts (three languages each) and their longest length. */
    private const DETAILS = ['credentials' => 200, 'format' => 120, 'duration' => 60, 'level' => 120];

    /** The trainer's photo: JPEG, PNG or WebP up to this size. */
    public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

    /** Where uploaded trainer photos are kept on the website (kept across deploys). */
    private const PHOTO_DIR = 'assets/uploads/workshops';

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

    /** An amount in the workshop's currency: "$100" or "75,000 IQD". */
    public static function money(int $amount, ?string $currency): string
    {
        return $currency === 'USD' ? '$' . number_format($amount) : number_format($amount) . ' IQD';
    }

    /** The currency of one workshop (IQD when unknown). */
    public static function currencyOf(?string $id): string
    {
        return $id !== null ? (string) (Db::value('SELECT currency FROM workshops WHERE id = ?', [$id]) ?? 'IQD') : 'IQD';
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
            'credentials' => $i18n('credentials'), 'format' => $i18n('format'), 'duration' => $i18n('duration'), 'level' => $i18n('level'),
            'overview' => $i18n('overview'), 'photo' => $row['photo'] !== null && $row['photo'] !== '' ? (string) $row['photo'] : null,
            'price' => (int) $row['price'], 'currency' => (string) ($row['currency'] ?? 'IQD'),
            'totalSeats' => (int) $row['total_seats'], 'seatsLeft' => max(0, (int) $row['total_seats'] - $booked),
            'booked' => $booked, 'status' => $row['status'], 'sortOrder' => (int) $row['sort_order'],
        ];
    }

    // ---------------- changes (Owner) ----------------

    /** A new workshop. Returns its id. $photo is the uploaded trainer photo ($_FILES entry), if any. */
    public static function create(array $in, array $user, ?array $photo = null): string
    {
        self::requireOwner($user);
        $fields = self::fields($in);
        if ($fields['title_en'] === null && $fields['title_ku'] === null && $fields['title_ar'] === null) {
            throw new UserError('Write the workshop name (at least in one language).');
        }
        $id = self::newId((string) ($fields['title_en'] ?? $fields['title_ku'] ?? $fields['title_ar']));
        $stored = self::storePhoto($photo, $id);
        $now = App::now();
        try {
            Db::insert('workshops', $fields + [
                'id' => $id, 'status' => 'active', 'photo' => $stored,
                'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM workshops'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $error) {
            self::deletePhotoFile($stored);
            throw $error;
        }
        Audit::log((int) $user['id'], 'workshop.create', 'workshop', null, ['id' => $id, 'photo' => $stored] + self::auditFields($fields));
        self::syncWebsite();
        return $id;
    }

    /**
     * Name, details, price, seats, picture and photo. The seats can never go
     * below the people already booked. A new photo replaces the old one;
     * remove_photo=1 takes the photo away.
     */
    public static function update(string $id, array $in, array $user, ?array $photo = null): void
    {
        self::requireOwner($user);
        $fields = self::fields($in);
        if (self::find($id) === null) {
            throw new UserError('Workshop not found.');
        }
        $stored = self::storePhoto($photo, $id);
        if ($stored !== null) {
            $fields['photo'] = $stored;
        } elseif (($in['remove_photo'] ?? '') === '1') {
            $fields['photo'] = null;
        }
        try {
            $old = Db::transaction(static function () use ($id, $fields): ?string {
                $row = Db::one('SELECT photo FROM workshops WHERE id = ? FOR UPDATE', [$id]);
                if ($row === null) {
                    throw new UserError('Workshop not found.');
                }
                $booked = self::booked($id);
                if ($fields['total_seats'] < $booked) {
                    throw new UserError("$booked people are already booked, so the seats cannot be fewer than $booked.");
                }
                // Bookings keep the amount they were agreed at, so the currency
                // changes only while nobody is booked.
                $currency = (string) Db::value('SELECT currency FROM workshops WHERE id = ?', [$id]);
                if ($fields['currency'] !== $currency && (int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ? AND removed_at IS NULL', [$id]) > 0) {
                    throw new UserError("People are booked at $currency prices, so the currency cannot change now.");
                }
                Db::update('workshops', $fields + ['updated_at' => App::now()], 'id = ?', [$id]);
                return $row['photo'];
            });
        } catch (\Throwable $error) {
            self::deletePhotoFile($stored);
            throw $error;
        }
        if (array_key_exists('photo', $fields) && $old !== $fields['photo']) {
            self::deletePhotoFile($old);
        }
        Audit::log((int) $user['id'], 'workshop.edit', 'workshop', null, ['id' => $id] + self::auditFields($fields));
        self::syncWebsite();
    }

    /**
     * Online "Register for workshop" on the website: the visitor types the
     * email they registered for the summit with. Only a registered (paid or
     * free ticket) person gets a seat; it is booked NOT PAID, and the office
     * collects the workshop money as for a phone booking.
     *
     * Errors are website translation keys (wd_err_*).
     */
    public static function registerOnline(string $workshopId, string $email): void
    {
        $email = strtolower(trim($email));
        if (!Validate::email($email)) {
            throw new UserError('wd_err_email', 'email');
        }
        $workshop = self::find($workshopId);
        if ($workshop === null || $workshop['status'] !== 'active' || !self::hasTitle($workshop)) {
            throw new UserError('wd_err_missing', null, 404);
        }
        $registration = Db::one("SELECT * FROM registrations WHERE LOWER(email) = ? AND status IN ('paid','complimentary') ORDER BY id DESC LIMIT 1", [$email]);
        if ($registration === null) {
            throw new UserError('wd_err_not_registered', 'email', 404);
        }
        // The workshop row is locked while the seat is taken, as for a phone
        // booking, so two people can never take the last seat together.
        $bookingId = Db::transaction(static function () use ($workshop, $registration): int {
            $row = Db::one('SELECT total_seats, status, price FROM workshops WHERE id = ? FOR UPDATE', [$workshop['id']]);
            if ($row === null || $row['status'] !== 'active') {
                throw new UserError('wd_err_missing', null, 404);
            }
            if (Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$registration['id'], $workshop['id']])) {
                throw new UserError('wd_err_already', 'email', 409);
            }
            if (self::booked($workshop['id']) >= (int) $row['total_seats']) {
                throw new UserError('wd_err_full', null, 409);
            }
            $now = App::now();
            return Db::insert('workshop_bookings', [
                'registration_id' => $registration['id'], 'workshop_id' => $workshop['id'], 'price_agreed' => (int) $row['price'],
                'payment_status' => 'unpaid', 'booked_by' => null, 'over_capacity' => 0,
                'notes' => 'Registered online on the website',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        self::syncWebsite();
        Audit::log(null, 'workshop.online', 'registration', (int) $registration['id'], ['workshop' => $workshop['id'], 'booking' => $bookingId]);
    }

    private static function hasTitle(array $workshop): bool
    {
        return array_filter((array) ($workshop['title'] ?? [])) !== [];
    }

    /**
     * Saves an uploaded trainer photo on the website and returns its path
     * (assets/uploads/workshops/…), or null when no file was sent.
     */
    private static function storePhoto(?array $file, string $id): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new UserError(in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The photo is too big (5 MB at most).' : 'The photo could not be uploaded. Try again.');
        }
        if ((int) ($file['size'] ?? 0) > self::PHOTO_MAX_BYTES) {
            throw new UserError('The photo is too big (5 MB at most).');
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $ext = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_WEBP => 'webp',
            default        => null,
        };
        if ($ext === null || ($info[0] ?? 0) < 200 || ($info[1] ?? 0) < 200) {
            throw new UserError('The photo must be a JPEG, PNG or WebP picture, at least 200 × 200 pixels.');
        }
        $dir = App::siteFile(self::PHOTO_DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . self::PHOTO_DIR);
        }
        $path = self::PHOTO_DIR . '/' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file((string) $file['tmp_name'], App::siteFile($path))) {
            throw new \RuntimeException('Cannot save the workshop photo.');
        }
        @chmod(App::siteFile($path), 0644);
        return $path;
    }

    /** Deletes an uploaded photo file (never the photos that come with the website). */
    private static function deletePhotoFile(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::PHOTO_DIR . '/') && !str_contains($path, '..')) {
            @unlink(App::siteFile($path));
        }
    }

    /** The audit log keeps the short fields; the long overview only as "changed". */
    private static function auditFields(array $fields): array
    {
        foreach (['overview_en', 'overview_ar', 'overview_ku'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = mb_strlen((string) $fields[$key]) . ' characters';
            }
        }
        return $fields;
    }

    /** +1 / -1 seat (or any number). */
    public static function changeSeats(string $id, int $delta, array $user): int
    {
        self::requireOwner($user);
        [$from, $seats] = Db::transaction(static function () use ($id, $delta): array {
            $from = Db::value('SELECT total_seats FROM workshops WHERE id = ? FOR UPDATE', [$id]);
            if ($from === null) {
                throw new UserError('Workshop not found.');
            }
            $booked = self::booked($id);
            $seats = (int) $from + $delta;
            if ($seats < max(1, $booked)) {
                throw new UserError($booked > 0 ? "$booked people are booked: the seats cannot be fewer than that. Remove a booking first." : 'A workshop needs at least 1 seat.');
            }
            if ($seats > 2000) {
                throw new UserError('That is too many seats.');
            }
            Db::update('workshops', ['total_seats' => $seats, 'updated_at' => App::now()], 'id = ?', [$id]);
            return [(int) $from, $seats];
        });
        Audit::log((int) $user['id'], 'workshop.seats', 'workshop', null, ['id' => $id, 'from' => $from, 'to' => $seats]);
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
        $details = [];
        foreach (['en', 'ar', 'ku'] as $lang) {
            foreach (self::DETAILS as $field => $max) {
                $details[$field . '_' . $lang] = Validate::text($in[$field . '_' . $lang] ?? '', $max) ?: null;
            }
            $details['overview_' . $lang] = Validate::multiline($in['overview_' . $lang] ?? '', 2000) ?: null;
        }
        return [
            'title_en' => $text('title_en'), 'title_ar' => $text('title_ar'), 'title_ku' => $text('title_ku'),
            'company_en' => $text('company_en'), 'company_ar' => $text('company_ar'), 'company_ku' => $text('company_ku'),
            'speaker_en' => $text('speaker_en'), 'speaker_ar' => $text('speaker_ar'), 'speaker_ku' => $text('speaker_ku'),
            'price' => $price === '' ? 0 : min((int) $price, 100000000),
            'currency' => Validate::oneOf($in['currency'] ?? null, self::CURRENCIES, 'IQD'),
            'total_seats' => $seats,
            'icon' => Validate::oneOf($in['icon'] ?? null, self::ICONS, 'tools'),
        ] + $details;
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
            'credentials' => $w['credentials'], 'format' => $w['format'], 'duration' => $w['duration'], 'level' => $w['level'],
            'overview' => $w['overview'], 'photo' => $w['photo'],
            'price' => $w['price'], 'currency' => $w['currency'], 'totalSeats' => $w['totalSeats'], 'seatsLeft' => $w['seatsLeft'],
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
