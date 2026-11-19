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

    /**
     * Who runs a workshop. 'ismile': people register on the website with the
     * email they registered for the summit with. 'company': people call the
     * company's phone number to register (no online registration).
     */
    public const ORGANIZERS = ['ismile', 'company'];

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

    /**
     * Over: its date and finishing time (Iraq time) have passed. Nobody can
     * register for it after that, by any route (owner, 19 Nov 2026). Takes a
     * workshop in the website's shape or a raw database row.
     */
    public static function ended(array $workshop): bool
    {
        $date = (string) ($workshop['scheduledDate'] ?? $workshop['scheduled_date'] ?? '');
        $end = substr((string) ($workshop['endTime'] ?? $workshop['end_time'] ?? ''), 0, 5);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !preg_match('/^\d{2}:\d{2}$/D', $end)) {
            return false;
        }
        return App::now() >= $date . ' ' . $end . ':00';
    }

    /** The words staff see for a workshop that is over. */
    public const ENDED_MESSAGE = 'This workshop is over, so registration for it is closed.';

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
            'organizer' => (string) ($row['organizer'] ?? 'ismile'),
            'companyPhone' => ($row['company_phone'] ?? '') !== '' ? (string) $row['company_phone'] : null,
            'companyPhones' => array_values(array_filter([$row['company_phone'] ?? null,$row['company_phone_secondary'] ?? null])),
            'scheduledDate' => $row['scheduled_date'] ?? null,
            'startTime' => isset($row['start_time']) ? substr($row['start_time'],0,5) : null,
            'endTime' => isset($row['end_time']) ? substr($row['end_time'],0,5) : null,
            'photoStyle' => $row['photo_style'] ?? 'photo',
            'presenters' => json_decode((string) ($row['presenters_json'] ?? '[]'), true) ?: [],
            'providerEventId' => $row['provider_event_id'] ?? null,
            'providerShowId' => $row['provider_show_id'] ?? null,
            'providerTicketTypeId' => $row['provider_ticket_type_id'] ?? null,
            'price' => (int) $row['price'], 'currency' => (string) ($row['currency'] ?? 'IQD'),
            'displayPrice' => isset($row['display_price'], $row['display_currency']) ? ['amount'=>(int)$row['display_price'],'currency'=>(string)$row['display_currency']] : null,
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
        $before = self::find($id);
        if ($before === null) {
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
                $row = Db::one('SELECT photo, price, currency FROM workshops WHERE id = ? FOR UPDATE', [$id]);
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
                if ((int)$fields['price'] !== (int)$row['price'] || $fields['currency'] !== $row['currency']) {
                    $fields['display_price'] = null;
                    $fields['display_currency'] = null;
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
        try {
            self::syncProvider($id, $before, (int) $user['id']);
        } finally {
            self::syncWebsite();
        }
    }

    /**
     * iSmile workshops sold through PSOOLA: the website shows PSOOLA's seats,
     * date and time, and PSOOLA sends the emails, so every save also changes
     * PSOOLA: seats (ticket quantity), price, title, date and time, and the
     * parts of PSOOLA's description and ticket text that changed (title,
     * trainer, overview, date, time, "Limited to N attendees").
     * Each save compares with PSOOLA, so it also repairs older changes.
     * If PSOOLA refuses new seats, our seats go back so the two never differ.
     */
    private static function syncProvider(string $id, array $before, int $userId): void
    {
        $w = self::find($id);
        if ($w === null || $w['organizer'] !== 'ismile' || empty($w['providerEventId']) || empty($w['providerTicketTypeId'])) {
            return;
        }
        $gateway = new Payments\PsoolaGateway();
        $base = 'manage/events/' . rawurlencode((string) $w['providerEventId']);
        $ticketPath = '/ticket-types/' . rawurlencode((string) $w['providerTicketTypeId']);
        $call = static function (string $method, string $path = '', ?array $body = null) use ($gateway, $base): array {
            $answer = $gateway->partnerRequest($method, $base . $path, $body, $method === 'GET' ? [] : ['Idempotency-Key: ismile-ws-' . bin2hex(random_bytes(12))]);
            if (!in_array($answer['status'], [200, 201], true)) {
                throw new \RuntimeException($method . ' ' . ($path ?: 'event') . ': HTTP ' . $answer['status'] . ' ' . ($answer['json']['error']['message'] ?? ''));
            }
            return $answer['json'] ?? [];
        };
        $find = static function (array $managed, string $list, ?string $wanted): ?array {
            foreach (($managed[$list] ?? []) as $item) {
                if ($wanted !== null && ($item['_id'] ?? $item['id'] ?? null) === $wanted) {
                    return $item;
                }
            }
            return null;
        };
        $zone = new \DateTimeZone('Asia/Baghdad');
        $ms = static fn (?string $date, ?string $time): ?int => $date && $time ? (new \DateTimeImmutable($date . ' ' . $time, $zone))->getTimestamp() * 1000 : null;
        $at = static fn (int $ms): \DateTimeImmutable => (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($zone);
        $timeText = static function (int $start, int $end) use ($at): string {
            $a = $at($start);
            $b = $at($end);
            return $a->format('A') === $b->format('A') ? $a->format('g:i') . '–' . $b->format('g:i A') : $a->format('g:i A') . '–' . $b->format('g:i A');
        };
        $seats = (int) $w['totalSeats'];
        $oldSeats = (int) ($before['totalSeats'] ?? $seats);
        $problems = [];
        $changed = false;
        try {
            $managed = $call('GET');
            $event = $managed['event'] ?? [];
            $show = $find($managed, 'shows', $w['providerShowId'] ?? null);
            $ticket = $find($managed, 'ticketTypes', (string) $w['providerTicketTypeId']);
            if ($ticket === null) {
                throw new \RuntimeException('PSOOLA ticket not found.');
            }
            // 1. Seats first: they decide "Fully booked".
            if ((int) ($ticket['quantity'] ?? -1) !== $seats) {
                $changed = true;
                try {
                    $call('PATCH', $ticketPath, ['ticket' => ['quantity' => $seats]]);
                    if ((int) ($find($call('GET'), 'ticketTypes', (string) $w['providerTicketTypeId'])['quantity'] ?? -1) !== $seats) {
                        throw new \RuntimeException('PSOOLA still shows a different seat number.');
                    }
                } catch (\Throwable $error) {
                    App::log('error', 'PSOOLA workshop seats not changed', ['workshop' => $id, 'seats' => $seats, 'error' => $error->getMessage()]);
                    if ($seats !== $oldSeats) {
                        Db::update('workshops', ['total_seats' => $oldSeats, 'updated_at' => App::now()], 'id = ?', [$id]);
                        $problems[] = "the seats ($seats), so the seats stay at $oldSeats";
                    } else {
                        $problems[] = "the seats ($seats)";
                    }
                    $seats = (int) ($ticket['quantity'] ?? $oldSeats);
                }
            }
            // Text pairs: what PSOOLA says now -> what it should say.
            $pairs = [];
            $newTitle = (string) ($w['title']['en'] ?? '');
            if ($newTitle !== '') {
                $pairs[] = [(string) ($event['title'] ?? ''), $newTitle];
                $pairs[] = [(string) ($before['title']['en'] ?? ''), $newTitle];
            }
            foreach (['speaker', 'credentials', 'overview'] as $field) {
                $pairs[] = [(string) ($before[$field]['en'] ?? ''), (string) ($w[$field]['en'] ?? '')];
            }
            $start = $ms($w['scheduledDate'], $w['startTime']);
            $end = $ms($w['scheduledDate'], $w['endTime']);
            $timesOk = $start !== null && $end !== null && $end > $start;
            if ($show !== null && $timesOk && is_numeric($show['dateTime'] ?? null) && is_numeric($show['endTime'] ?? null)) {
                $pairs[] = [$at((int) $show['dateTime'])->format('j F Y'), $at($start)->format('j F Y')];
                $pairs[] = [$timeText((int) $show['dateTime'], (int) $show['endTime']), $timeText($start, $end)];
            }
            $swap = static function (string $text) use ($pairs, $seats): string {
                foreach ($pairs as [$from, $to]) {
                    if ($from !== '' && $to !== '' && $from !== $to) {
                        $text = str_replace($from, $to, $text);
                    }
                }
                return (string) preg_replace('/Limited to \d+ attendees/', "Limited to $seats attendees", $text);
            };
            // 2. Date and time of the session.
            if ($show !== null && $timesOk && ((int) ($show['dateTime'] ?? 0) !== $start || (int) ($show['endTime'] ?? 0) !== $end)) {
                $changed = true;
                try {
                    $call('PATCH', '/shows/' . rawurlencode((string) $w['providerShowId']), ['dateTime' => $start, 'endTime' => $end]);
                } catch (\Throwable $error) {
                    $problems[] = 'the date and time';
                    App::log('error', 'PSOOLA workshop time not changed', ['workshop' => $id, 'error' => $error->getMessage()]);
                }
            }
            // 3. Event title, dates and description.
            $patch = [];
            if ($newTitle !== '' && ($event['title'] ?? '') !== $newTitle) {
                $patch['title'] = $newTitle;
                $patch['titleI18n'] = array_merge((array) ($event['titleI18n'] ?? []), ['en' => $newTitle]);
            }
            if ($timesOk && isset($event['startDate']) && ((int) $event['startDate'] !== $start || (int) ($event['endDate'] ?? 0) !== $end)) {
                $patch['startDate'] = $start;
                $patch['endDate'] = $end;
            }
            if (is_string($event['description'] ?? null) && $swap($event['description']) !== $event['description']) {
                $patch['description'] = $swap($event['description']);
            }
            if ($patch) {
                $changed = true;
                try {
                    $call('PATCH', '', ['event' => $patch]);
                } catch (\Throwable $error) {
                    $problems[] = 'the title and description';
                    App::log('error', 'PSOOLA workshop event not changed', ['workshop' => $id, 'fields' => array_keys($patch), 'error' => $error->getMessage()]);
                }
            }
            // 4. Ticket price and ticket text.
            $patch = [];
            if ($w['currency'] === 'IQD' && is_numeric($ticket['price'] ?? null) && (int) $ticket['price'] !== (int) $w['price']) {
                $patch['price'] = (int) $w['price'];
            }
            if (is_string($ticket['description'] ?? null) && $swap($ticket['description']) !== $ticket['description']) {
                $patch['description'] = $swap($ticket['description']);
            }
            if ($patch) {
                $changed = true;
                try {
                    $call('PATCH', $ticketPath, ['ticket' => $patch]);
                } catch (\Throwable $error) {
                    $problems[] = isset($patch['price']) ? 'the price and ticket text' : 'the ticket text';
                    App::log('error', 'PSOOLA workshop ticket not changed', ['workshop' => $id, 'fields' => array_keys($patch), 'error' => $error->getMessage()]);
                }
            }
        } catch (\Throwable $error) {
            // PSOOLA could not be read: nothing was sent there.
            App::log('error', 'PSOOLA workshop not reachable', ['workshop' => $id, 'error' => $error->getMessage()]);
            if ($seats !== $oldSeats) {
                Db::update('workshops', ['total_seats' => $oldSeats, 'updated_at' => App::now()], 'id = ?', [$id]);
                $problems[] = "the seats ($seats), so the seats stay at $oldSeats";
            } elseif (self::providerFieldsChanged($before, $w)) {
                $problems[] = 'the new details';
            }
        } finally {
            @unlink(App::storage('tmp/workshop-catalog-' . $id . '.json'));
        }
        if ($changed && !$problems) {
            Audit::log($userId, 'workshop.psoola_sync', 'workshop', null, ['id' => $id, 'seats' => $seats]);
        }
        if ($problems) {
            throw new UserError('Saved here, but PSOOLA did not accept ' . implode(', ', $problems) . '. Press Save again in a minute.');
        }
    }

    /** Did a field that PSOOLA shows change in this save? */
    private static function providerFieldsChanged(array $before, array $after): bool
    {
        foreach (['title', 'speaker', 'credentials', 'overview', 'scheduledDate', 'startTime', 'endTime', 'price', 'totalSeats'] as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Online "Register for workshop" on the website: the visitor types the
     * email they registered for the summit with. Only a paid summit
     * registration can submit an unpaid workshop request; the office
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
        // A company's workshop is booked by calling the company, never here.
        if ($workshop['organizer'] === 'company') {
            throw new UserError('wd_err_by_phone', null, 409);
        }
        if (self::ended($workshop)) {
            throw new UserError('wd_err_full', null, 409);   // shown on the website as no seats left
        }
        $registration = Db::one("SELECT * FROM registrations WHERE LOWER(email) = ? AND status='paid' ORDER BY id DESC LIMIT 1", [$email]);
        if ($registration === null) {
            throw new UserError('wd_err_not_registered', 'email', 404);
        }
        // The workshop row is locked while the seat is taken, as for a phone
        // booking, so two people can never take the last seat together.
        $current = null;
        if ($workshop['providerEventId']) {
            $current = WorkshopCatalog::one($workshop['id']);
            if (!$current || !$current['availabilityVerified']) throw new UserError('wd_err_server',null,503);
            if ($current['seatsLeft'] < 1) throw new UserError('wd_err_full',null,409);
        }
        $bookingId = Db::transaction(static function () use ($workshop, $registration, $current): int {
            $row = Db::one('SELECT total_seats, status, price, organizer FROM workshops WHERE id = ? FOR UPDATE', [$workshop['id']]);
            if ($row === null || $row['status'] !== 'active') {
                throw new UserError('wd_err_missing', null, 404);
            }
            if ($row['organizer'] === 'company') throw new UserError('wd_err_by_phone',null,409);
            if (Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$registration['id']]) !== 'paid') throw new UserError('wd_err_not_registered','email',404);
            if (Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$registration['id'],$workshop['id']])) throw new UserError('wd_err_already','email',409);
            if (Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$registration['id'], $workshop['id']])) {
                throw new UserError('wd_err_already', 'email', 409);
            }
            if (self::booked($workshop['id']) >= (int) $row['total_seats']) {
                throw new UserError('wd_err_full', null, 409);
            }
            $now = App::now();
            return Db::insert('workshop_bookings', [
                'registration_id' => $registration['id'], 'workshop_id' => $workshop['id'], 'price_agreed' => (int) ($current['price'] ?? $row['price']),
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
        try {
            self::syncProvider($id, ['totalSeats' => $from] + (self::find($id) ?? []), (int) $user['id']);
        } finally {
            self::syncWebsite();
        }
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
        $organizer = Validate::oneOf($in['organizer'] ?? null, self::ORGANIZERS, 'ismile');
        $phone = Validate::text($in['company_phone'] ?? '', 40);
        $secondary = Validate::text($in['company_phone_secondary'] ?? '',40);
        if ($secondary !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,30}$/',$secondary)) throw new UserError('The second company phone must be a valid phone number.');
        $date = Validate::text($in['scheduled_date'] ?? '',10);
        $start = Validate::text($in['start_time'] ?? '',5);
        $end = Validate::text($in['end_time'] ?? '',5);
        if ($date !== '' || $start !== '' || $end !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end) || $end <= $start) throw new UserError('Choose a valid date and a finishing time after the starting time.');
        }
        if ($phone !== '' && (!preg_match('/^\+?[0-9][0-9 ()-]{6,30}$/', $phone) || strlen((string) preg_replace('/\D/', '', $phone)) < 7)) {
            throw new UserError('The company phone must be a phone number, e.g. +964 750 123 4567.');
        }
        if ($organizer === 'company') {
            if ($text('company_en') === null && $text('company_ar') === null && $text('company_ku') === null) {
                throw new UserError('Organized by a company: write the company name (at least in one language).');
            }
            if ($phone === '') {
                throw new UserError('Organized by a company: write the company phone number. People call it to register.');
            }
        }
        $details = [];
        foreach (['en', 'ar', 'ku'] as $lang) {
            foreach (self::DETAILS as $field => $max) {
                $details[$field . '_' . $lang] = Validate::text($in[$field . '_' . $lang] ?? '', $max) ?: null;
            }
            $details['overview_' . $lang] = Validate::multiline($in['overview_' . $lang] ?? '', 2000) ?: null;
        }
        // The simplified Owner editor saves visibility with the other fields.
        // Older callers that omit this field keep their existing status.
        if (array_key_exists('website_status', $in)) {
            if (!in_array($in['website_status'], ['active','hidden'], true)) throw new UserError('Choose whether the workshop is shown or hidden.');
            $details['status'] = $in['website_status'];
        }
        return [
            'title_en' => $text('title_en'), 'title_ar' => $text('title_ar'), 'title_ku' => $text('title_ku'),
            'company_en' => $text('company_en'), 'company_ar' => $text('company_ar'), 'company_ku' => $text('company_ku'),
            'speaker_en' => $text('speaker_en'), 'speaker_ar' => $text('speaker_ar'), 'speaker_ku' => $text('speaker_ku'),
            'organizer' => $organizer, 'company_phone' => $phone !== '' ? $phone : null,
            'company_phone_secondary' => $organizer==='company' && $secondary!=='' ? $secondary : null,
            'scheduled_date' => $date!==''?$date:null,
            'start_time' => $start!==''?$start:null,
            'end_time' => $end!==''?$end:null,
            'photo_style' => Validate::oneOf($in['photo_style']??null,['photo','cutout'],'photo'),
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
            'organizer' => $w['organizer'], 'companyPhone' => $w['organizer'] === 'company' ? $w['companyPhone'] : null,
            'companyPhones' => $w['organizer']==='company'?$w['companyPhones']:[],
            'scheduledDate'=>$w['scheduledDate'],'startTime'=>$w['startTime'],'endTime'=>$w['endTime'],'photoStyle'=>$w['photoStyle'],'presenters'=>$w['presenters'],
            'price' => $w['price'], 'currency' => $w['currency'], 'displayPrice' => $w['displayPrice'], 'totalSeats' => $w['totalSeats'], 'seatsLeft' => $w['seatsLeft'],
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
