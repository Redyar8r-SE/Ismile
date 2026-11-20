<?php
// Registrations: people registered for the event. A registration exists only
// after the payment company confirmed the payment (or the Owner gave a free
// ticket); forms waiting for payment are Checkouts. Here: the capacity rules
// and the lookups the admin and the emails need.

declare(strict_types=1);

namespace Ismile;

final class Registrations
{
    public const SPECIALTIES = ['gp', 'spec', 'omfs', 'lab', 'acad', 'student'];
    /** "3rd stage": a student's stage, as the office reads it. */
    public static function stageName(int $stage): string
    {
        return $stage . (match ($stage) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' }) . ' stage';
    }

    public const SPECIALTY_NAMES = [
        'gp' => 'General dentist',
        'spec' => 'Specialist',
        'omfs' => 'Oral and maxillofacial surgeon',
        'lab' => 'Dental technician',
        'acad' => 'Academic or researcher',
        'student' => 'Dental student',
    ];
    /** What each ticket type is called on screen. 'professional' is the Dentist package. */
    public const TICKET_NAMES = ['professional' => 'Dentist', 'student' => 'Student', 'vip' => 'VIP'];

    public static function ticketName(?string $type): string
    {
        return self::TICKET_NAMES[(string) $type] ?? ucfirst((string) $type);
    }

    public const GENDERS = ['female', 'male', 'other', 'prefer-not'];
    public const GENDER_NAMES = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other', 'prefer-not' => 'Prefer not to say'];
    public const PAY_METHODS = ['visa', 'mastercard', 'fib', 'fastpay'];

    /**
     * Gender and age from a database form, checked like the website (age 16 to 120).
     * $required: both must be given (new people); otherwise an empty age stays empty.
     * @return array{gender: ?string, age: ?int}
     */
    /**
     * Every ticket the team makes (summit, workshop, guest, speaker) needs the
     * person's agreement to get the ticket QR code by email and WhatsApp.
     * Without the tick nothing is sold or given.
     */
    public static function requireWhatsappConsent(array $in): void
    {
        if (($in['whatsapp_consent'] ?? '') !== '1') {
            throw new UserError('Tick "The person agreed to get the ticket QR code by email and WhatsApp" first. Without it no ticket is made.');
        }
    }

    /** Remembers the agreement on the registration (kept from the first time). */
    public static function rememberWhatsappConsent(int $registrationId): void
    {
        Db::run('UPDATE registrations SET whatsapp_consent_at = COALESCE(whatsapp_consent_at, ?) WHERE id = ?', [App::now(), $registrationId]);
    }

    /** The required tick for every form that sells or gives a ticket. */
    public static function whatsappConsentField(string $who = 'The person', bool $checked = false): string
    {
        return '<label class="cash-confirm-check whatsapp-consent"><input type="checkbox" name="whatsapp_consent" value="1"' . ($checked ? ' checked' : '') . ' required>'
            . '<span>' . htmlspecialchars($who, ENT_QUOTES) . ' agreed to get the ticket QR code by <b>email and WhatsApp</b>.</span></label>';
    }

    public static function genderAge(array $in, bool $required = true): array
    {
        $gender = Validate::oneOf($in['gender'] ?? null, self::GENDERS);
        if ($gender === null && $required) {
            throw new UserError('Choose the gender.');
        }
        $typed = trim((string) (is_scalar($in['age'] ?? null) ? $in['age'] : ''));
        if ($typed === '') {
            if ($required) {
                throw new UserError('Enter the age.');
            }
            return ['gender' => $gender, 'age' => null];
        }
        $age = filter_var($typed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 16, 'max_range' => 120]]);
        if ($age === false) {
            throw new UserError('Enter an age between 16 and 120.');
        }
        return ['gender' => $gender, 'age' => $age];
    }

    /** The Gender and Age fields of a database form ($gender/$age: the values to show). */
    public static function genderAgeFields(?string $gender, int|string|null $age, bool $required = true): string
    {
        $options = '<option value="">Choose gender</option>';
        foreach (self::GENDER_NAMES as $key => $name) {
            $options .= '<option value="' . $key . '"' . ($gender === $key ? ' selected' : '') . '>' . $name . '</option>';
        }
        $must = $required ? ' required' : '';
        return '<label>Gender<select name="gender"' . $must . '>' . $options . '</select></label>'
            . '<label>Age<input type="number" name="age" inputmode="numeric" min="16" max="120" step="1" placeholder="28" value="'
            . htmlspecialchars((string) ($age ?? ''), ENT_QUOTES) . '"' . $must . '></label>';
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM registrations WHERE id = ?', [$id]);
    }

    public static function findByRef(string $ref): ?array
    {
        if (!preg_match('/^ISM26-[A-Z0-9]{6}$/', $ref)) {
            return null;
        }
        return Db::one('SELECT * FROM registrations WHERE ref = ?', [$ref]);
    }

    public static function fullName(array $registration): string
    {
        return trim($registration['first_name'] . ' ' . $registration['father_name'] . ' ' . $registration['grandfather_name']);
    }

    // ---------------- capacity ----------------

    /** Tickets that hold a place: paid or complimentary, not cancelled. */
    public static function ticketsTaken(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','complimentary')");
    }

    /**
     * $holdPending: also count people who are paying right now (an attempt
     * started in the last hour), except checkout $exceptId, the person asking.
     */
    public static function isFull(bool $holdPending = false, int $exceptId = 0): bool
    {
        $capacity = Settings::int('ticket_capacity');
        if ($capacity <= 0) {
            return false;
        }
        $held = $holdPending ? self::payingNow('1 = 1', $exceptId) : 0;
        return self::ticketsTaken() + $held >= $capacity;
    }

    /** Forms being paid right now: an open payment attempt started in the last hour. */
    private static function payingNow(string $condition, int $exceptId): int
    {
        return (int) Db::value(
            "SELECT COUNT(DISTINCT c.id) FROM checkouts c JOIN payments p ON p.checkout_id = c.id
             WHERE c.status = 'open' AND c.id <> ? AND p.status IN ('created','waiting') AND p.created_at > ? AND $condition",
            [$exceptId, date('Y-m-d H:i:s', time() - 3600)]
        );
    }

    public static function lunchTaken(int $day): int
    {
        $column = $day === 1 ? 'lunch_day1' : 'lunch_day2';
        return (int) Db::value("SELECT COUNT(*) FROM registrations WHERE $column = 1 AND status IN ('paid','complimentary')");
    }

    /** A lunch day closes at this Iraq time on its own date: the summit day is over (owner, 19 Nov 2026). */
    public const LUNCH_CLOSES_AT = '17:00:00';

    /**
     * Is this lunch day closed? From 17:00 on that day nobody can add it, by
     * any route: website, payment link, desk, phone, free ticket or speaker.
     * People who already have it keep it.
     */
    /** Owner, 20 Nov 2026: close all lunch now, nobody can add any lunch day any more. */
    public const ALL_LUNCH_CLOSED = true;

    public static function lunchClosed(int $day): bool
    {
        if (self::ALL_LUNCH_CLOSED) return true;
        $date = Settings::get('event_day' . $day);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 && App::now() >= $date . ' ' . self::LUNCH_CLOSES_AT;
    }

    /** The words staff see when they tick a closed lunch day. */
    public static function lunchClosedMessage(int $day): string
    {
        return 'Lunch day ' . $day . ' is closed: no more lunch can be added. Untick it.';
    }

    public static function lunchFull(int $day): bool
    {
        $capacity = Settings::int('lunch_capacity_day' . $day);
        return $capacity > 0 && self::lunchTaken($day) >= $capacity;
    }

    /** Is a lunch this form chose already full (counting people paying right now)? */
    public static function lunchFullFor(array $checkout, bool $holdPending = false): bool
    {
        foreach ([1, 2] as $day) {
            if ((int) $checkout['lunch_day' . $day] === 1 && self::lunchClosed($day)) {
                return true;   // that summit day is over: its lunch can no longer be paid for
            }
            $capacity = Settings::int('lunch_capacity_day' . $day);
            if ((int) $checkout['lunch_day' . $day] !== 1 || $capacity <= 0) {
                continue;
            }
            $held = $holdPending ? self::payingNow("c.lunch_day$day = 1", (int) $checkout['id']) : 0;
            if (self::lunchTaken($day) + $held >= $capacity) {
                return true;
            }
        }
        return false;
    }

    /** Limits that are exceeded right now, in words (empty when all is well). */
    public static function overCapacity(): array
    {
        $over = [];
        $capacity = Settings::int('ticket_capacity');
        if ($capacity > 0 && self::ticketsTaken() > $capacity) {
            $over[] = 'tickets ' . self::ticketsTaken() . ' of ' . $capacity;
        }
        foreach ([1, 2] as $day) {
            $capacity = Settings::int('lunch_capacity_day' . $day);
            if ($capacity > 0 && self::lunchTaken($day) > $capacity) {
                $over[] = "lunch day $day " . self::lunchTaken($day) . ' of ' . $capacity;
            }
        }
        return $over;
    }

    /**
     * What the registration page needs to know before showing the form.
     * 'open' is false when registration is switched off, the summit is full,
     * or the prices are not set yet.
     */
    /**
     * Database Settings are the only registration switch.
     */
    public static function isOpen(): bool
    {
        return Settings::bool('registration_open');
    }

    public static function publicState(string $lang): array
    {
        $prices = SiteData::prices();
        // With the Psoola gateway the page shows PSOOLA's prices (IQD), which
        // are what the visitor is charged; data/tickets.json if PSOOLA is unreachable.
        $psoola = Payments\PsoolaPrices::get();
        if ($psoola !== null) {
            $prices = Payments\PsoolaPrices::asSitePrices($psoola);
        }
        if (VipPackages::enabled()) {
            $prices['vipPackageEnabled'] = true;
            $prices['vipPackageUSD'] = VipPackages::PRICE_USD;
        }
        $reason = null;
        if (!self::isOpen()) {
            $reason = 'closed';
        } elseif (self::isFull()) {
            $reason = 'full';
        } elseif ($prices['professional'] <= 0 || $prices['student'] <= 0) {
            $reason = 'prices';
        }
        return [
            'open'     => $reason === null,
            'reason'   => $reason,
            'message'  => $reason === 'closed' ? Settings::closedMessage($lang) : null,
            'messages' => $reason === 'closed' ? ['en' => Settings::closedMessage('en'), 'ar' => Settings::closedMessage('ar'), 'ku' => Settings::closedMessage('ku')] : null,
            'prices'   => $prices,
            'vip'      => ['available' => !VipCapacity::full() && (!VipPackages::enabled() || VipPackages::ready())],
            'lunch'    => [
                'day1' => $prices['lunchDay1'] > 0 && !self::lunchFull(1) && !self::lunchClosed(1),
                'day2' => $prices['lunchDay2'] > 0 && !self::lunchFull(2) && !self::lunchClosed(2),
            ],
            'gateway'  => (string) App::config('payments.gateway', 'fake'),
        ];
    }
}
