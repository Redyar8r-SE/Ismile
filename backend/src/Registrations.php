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
    public const GENDERS = ['female', 'male', 'other', 'prefer-not'];
    public const PAY_METHODS = ['visa', 'mastercard', 'fib', 'fastpay'];

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

    public static function lunchFull(int $day): bool
    {
        $capacity = Settings::int('lunch_capacity_day' . $day);
        return $capacity > 0 && self::lunchTaken($day) >= $capacity;
    }

    /** Is a lunch this form chose already full (counting people paying right now)? */
    public static function lunchFullFor(array $checkout, bool $holdPending = false): bool
    {
        foreach ([1, 2] as $day) {
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
    public static function publicState(string $lang): array
    {
        $prices = SiteData::prices();
        $reason = null;
        if (!Settings::bool('registration_open')) {
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
            'lunch'    => [
                'day1' => $prices['lunchDay1'] > 0 && !self::lunchFull(1),
                'day2' => $prices['lunchDay2'] > 0 && !self::lunchFull(2),
            ],
            'gateway'  => (string) App::config('payments.gateway', 'fake'),
        ];
    }
}
