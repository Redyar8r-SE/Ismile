<?php
// The name lists on the admin's Lists page and in their Excel exports.
// One definition per list, so the page and the export always agree.

declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\Db;

final class Lists
{
    /** key => [tab label, explanation] */
    public const ALL = [
        'registered' => ['Registered', 'Everyone registered for the event. Only people who paid (or got a free ticket from the Owner) are here.'],
        'attendance' => ['Attendance', 'Each guest’s Day 1 and Day 2 arrivals and certificate. Payment without attendance does not earn a certificate.'],
        'forms'      => ['All forms sent', 'Every registration form sent (website or office) the moment it is sent, paid or not, newest first. Only the paid ones are registered: "waiting" means the person has not paid yet, "expired" means they never paid.'],
        'lunch1'     => ['Lunch day 1', 'Registered people who chose lunch on day 1: the list for the caterer.'],
        'lunch2'     => ['Lunch day 2', 'Registered people who chose lunch on day 2: the list for the caterer.'],
        'students'   => ['Students', 'Registered students, with their university and whether their ID photo is stored.'],
        'studentids' => ['Student IDs', 'Every registered student with the ID photo they sent, side by side. The photos are stored in the database.'],
        'workshops'  => ['Workshops', 'Each workshop with the people booked on it, and whether they paid the workshop.'],
        'sponsors'   => ['Sponsors', 'Sponsorship requests: the company, the package, where it stands, the money agreed and paid, and the next call.'],
        'exhibition' => ['Booths', 'Standard booth bookings, company contacts, agreed amounts, payments and follow-up calls.'],
        'cancelled'  => ['Cancelled', 'Registrations that were cancelled (no refunds are made).'],
    ];

    /** Lists of companies (sponsor requests), not of people. */
    public const COMPANY_LISTS = ['sponsors' => 'sponsor', 'exhibition' => 'booth'];

    public static function pick(string $key): string
    {
        return array_key_exists($key, self::ALL) ? $key : 'registered';
    }

    /** Extra columns after the name: key => heading. */
    public static function columns(string $list): array
    {
        return match ($list) {
            'attendance' => ['ticket_no' => 'Ticket', 'day1' => 'Day 1 arrival', 'day2' => 'Day 2 arrival', 'certificate_no' => 'Certificate', 'ambassador_code' => 'Ambassador', 'ref' => 'Reference'],
            'workshops'        => ['phone' => 'Phone', 'workshop_paid' => 'Workshop paid?', 'amount_paid' => 'Amount (IQD)', 'ref' => 'Reference'],
            'sponsors'         => ['package' => 'Package', 'contact_name' => 'Contact', 'phone' => 'Phone', 'sponsor_status' => 'Status', 'amount_agreed' => 'Agreed (IQD)', 'amount_paid' => 'Paid (IQD)', 'next_call_at' => 'Next call', 'cancelled_at' => 'Cancelled on', 'cancellation_reason' => 'Cancellation reason', 'ref' => 'Reference'],
            'exhibition'       => ['package' => 'Booth type', 'contact_name' => 'Contact', 'phone' => 'Phone', 'sponsor_status' => 'Status', 'amount_agreed' => 'Agreed (IQD)', 'amount_paid' => 'Paid (IQD)', 'next_call_at' => 'Next call', 'cancelled_at' => 'Cancelled on', 'cancellation_reason' => 'Cancellation reason', 'ref' => 'Reference'],
            'students', 'studentids' => ['phone' => 'Phone', 'university' => 'University', 'study_stage' => 'Stage', 'ambassador_code' => 'Ambassador', 'id_photo' => 'ID photo', 'ticket_no' => 'Ticket no.', 'ref' => 'Reference'],
            'forms'            => ['phone' => 'Phone', 'email' => 'Email', 'ticket_type' => 'Ticket', 'lunch' => 'Lunch', 'university' => 'University', 'ambassador_code' => 'Ambassador', 'form_status' => 'Payment', 'created_at' => 'Sent', 'ref' => 'Reference'],
            'lunch1', 'lunch2' => ['phone' => 'Phone', 'ticket_type' => 'Ticket', 'ticket_no' => 'Ticket no.', 'ambassador_code' => 'Ambassador', 'ref' => 'Reference'],
            default            => ['phone' => 'Phone', 'ticket_type' => 'Ticket', 'lunch' => 'Lunch', 'ticket_no' => 'Ticket no.', 'ambassador_code' => 'Ambassador', 'ref' => 'Reference', 'paid_at' => 'Paid'],
        };
    }

    public static function rows(string $list): array
    {
        if (isset(self::COMPANY_LISTS[$list])) {
            return Db::all("SELECT s.*, s.status AS sponsor_status, p.name_en AS package FROM sponsor_requests s LEFT JOIN sponsor_packages p ON p.id = s.package_id
                            WHERE s.kind = ? ORDER BY s.status = 'declined', s.company", [self::COMPANY_LISTS[$list]]);
        }
        if ($list === 'forms') {
            // The forms (checkouts), not the registrations: "id" is the registration, if paid.
            return Db::all("SELECT c.*, c.registration_id AS id,
                                   CASE WHEN c.status = 'paid' THEN 'paid' WHEN c.status = 'open' AND c.expires_at > ? THEN 'waiting' ELSE 'expired' END AS form_status
                            FROM checkouts c ORDER BY c.created_at DESC, c.id DESC", [\Ismile\App::now()]);
        }
        if ($list === 'workshops') {
            return array_merge(...array_column(self::workshopGroups(), 'people') ?: [[]]);
        }
        $registered = "r.status IN ('paid','complimentary')";
        $where = match ($list) {
            'lunch1'    => "$registered AND r.lunch_day1 = 1",
            'lunch2'    => "$registered AND r.lunch_day2 = 1",
            'students', 'studentids' => "$registered AND r.ticket_type = 'student'",
            'cancelled' => "r.status = 'cancelled'",
            default     => $registered,
        };
        return Db::all("SELECT r.*, t.ticket_no, a1.checked_in_at AS day1, a2.checked_in_at AS day2, c.certificate_no, p.uploaded_at AS photo_uploaded, p.bytes AS photo_bytes, IF(p.id IS NULL, 'missing', 'stored') AS id_photo
                        FROM registrations r LEFT JOIN tickets t ON t.registration_id = r.id AND t.cancelled_at IS NULL
                        LEFT JOIN ticket_attendance a1 ON a1.ticket_id=t.id AND a1.event_day=1
                        LEFT JOIN ticket_attendance a2 ON a2.ticket_id=t.id AND a2.event_day=2
                        LEFT JOIN certificates c ON c.registration_id=r.id AND t.id IS NOT NULL
                        LEFT JOIN student_id_photos p ON p.id = r.id_photo_id
                        WHERE $where ORDER BY r.first_name, r.father_name, r.grandfather_name");
    }

    /**
     * Every workshop with the people booked on it (removed bookings are not
     * shown). Workshops nobody booked yet are listed too, empty.
     */
    public static function workshopGroups(): array
    {
        $groups = [];
        foreach (\Ismile\Workshops::all(true) as $workshop) {
            $people = Db::all(
                "SELECT r.*, wb.payment_status AS workshop_paid, wb.amount_paid, ? AS workshop_name
                 FROM workshop_bookings wb JOIN registrations r ON r.id = wb.registration_id
                 WHERE wb.workshop_id = ? AND wb.removed_at IS NULL ORDER BY r.first_name, r.father_name",
                [\Ismile\Workshops::name($workshop), $workshop['id']]
            );
            if ($people || $workshop['status'] === 'active') {
                $groups[] = ['workshop' => $workshop, 'people' => $people];
            }
        }
        return $groups;
    }

    // ---------- Filters (the Lists page and its Print, PDF and Excel) ----------

    /** The filters from the address, cleaned. "amb" holds ambassador codes; "-" means no code. */
    public static function filters(array $in): array
    {
        $pick = static fn (string $key, array $allowed): string => in_array($value = (string) ($in[$key] ?? ''), $allowed, true) ? $value : '';
        $text = static fn (string $key, int $max): string => is_string($in[$key] ?? null) ? mb_substr(trim($in[$key]), 0, $max) : '';
        $date = static fn (string $key): string => is_string($in[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in[$key]) ? $in[$key] : '';
        // Several ambassadors travel as one value, "SARA26,KAR10" (the admin accepts no lists in addresses).
        $codes = is_string($in['amb'] ?? null) ? explode(',', $in['amb']) : [];
        $codes = array_values(array_unique(array_filter(
            array_map(static fn ($code): string => is_string($code) ? mb_strtoupper(mb_substr(trim($code), 0, 40)) : '', $codes),
            static fn (string $code): bool => $code !== ''
        )));
        return [
            'q' => $text('q', 120), 'ticket' => $pick('ticket', ['professional', 'student', 'vip']),
            'lunch' => $pick('lunch', ['day1', 'day2', 'both', 'none']), 'arrived' => $pick('arrived', ['day1', 'day2', 'none']),
            'cert' => $pick('cert', ['yes', 'no']), 'payment' => $pick('payment', ['paid', 'waiting', 'expired']),
            'source' => $pick('source', ['website', 'office']), 'wpaid' => $pick('wpaid', ['yes', 'no']),
            'status' => $text('status', 30), 'package' => $text('package', 80), 'calls' => $pick('calls', ['due', 'overdue']),
            'city' => $text('city', 80), 'specialty' => $pick('specialty', array_keys(\Ismile\Registrations::SPECIALTY_NAMES)),
            'university' => $text('university', 160), 'from' => $date('from'), 'to' => $date('to'),
            'amb' => array_slice($codes, 0, 50), 'sort' => $pick('sort', ['newest', 'oldest', 'name']),
        ];
    }

    /** Which filters each list offers. */
    public static function available(string $list): array
    {
        if (isset(self::COMPANY_LISTS[$list])) {
            return ['q', 'status', 'package', 'calls', 'sort'];
        }
        if ($list === 'workshops') {
            return ['q', 'ticket', 'wpaid', 'city', 'amb'];
        }
        $people = ['q', 'ticket', 'city', 'specialty', 'university', 'from', 'to', 'amb', 'sort'];
        return match ($list) {
            'forms'                  => [...$people, 'lunch', 'payment', 'source'],
            'lunch1', 'lunch2'       => [...$people, 'arrived'],
            'attendance'             => [...$people, 'lunch', 'arrived', 'cert'],
            'cancelled'              => [...$people, 'lunch'],
            'students', 'studentids' => [...array_values(array_diff($people, ['ticket'])), 'lunch', 'arrived'],
            default                  => [...$people, 'lunch', 'arrived'],
        };
    }

    /** Only the filters this list offers and that are set (sort is not a filter). */
    public static function active(string $list, array $filters): array
    {
        $active = array_intersect_key($filters, array_flip(self::available($list)));
        unset($active['sort']);
        return array_filter($active, static fn ($value): bool => $value !== '' && $value !== []);
    }

    public static function ambassadorCode(array $row): string
    {
        return mb_strtoupper(trim((string) ($row['ambassador_code'] ?? '')));
    }

    /** The rows that match every active filter, in the chosen order. */
    public static function filter(string $list, array $rows, array $filters): array
    {
        $f = self::active($list, $filters);
        $lower = static fn ($value): string => mb_strtolower(trim((string) $value));
        $dateField = $list === 'forms' || isset(self::COMPANY_LISTS[$list]) ? 'created_at' : 'paid_at';
        $today = substr(\Ismile\App::now(), 0, 10);
        $words = ($f['q'] ?? '') !== '' ? preg_split('/\s+/u', $lower($f['q'])) : [];
        $digits = ltrim(preg_replace('/\D+/', '', $f['q'] ?? ''), '0');
        $rows = array_values(array_filter($rows, static function (array $r) use ($f, $lower, $dateField, $today, $words, $digits): bool {
            if ($words) {
                $hay = $lower(implode(' ', array_map('strval', [
                    $r['first_name'] ?? '', $r['father_name'] ?? '', $r['grandfather_name'] ?? '', $r['company'] ?? '', $r['contact_name'] ?? '',
                    $r['email'] ?? '', $r['phone'] ?? '', $r['ref'] ?? '', $r['ticket_no'] ?? '', $r['ambassador_code'] ?? '',
                ])));
                $phoneMatch = strlen($digits) >= 4 && str_contains(preg_replace('/\D+/', '', (string) ($r['phone'] ?? '')), $digits);
                if (!$phoneMatch && array_filter($words, static fn (string $word): bool => !str_contains($hay, $word))) return false;
            }
            if (isset($f['ticket']) && ($r['ticket_type'] ?? '') !== $f['ticket']) return false;
            if (isset($f['lunch'])) {
                [$one, $two] = [(int) ($r['lunch_day1'] ?? 0), (int) ($r['lunch_day2'] ?? 0)];
                if (!match ($f['lunch']) { 'day1' => $one === 1, 'day2' => $two === 1, 'both' => $one + $two === 2, default => $one + $two === 0 }) return false;
            }
            if (isset($f['arrived']) && !match ($f['arrived']) { 'day1' => !empty($r['day1']), 'day2' => !empty($r['day2']), default => empty($r['day1']) && empty($r['day2']) }) return false;
            if (isset($f['cert']) && empty($r['certificate_no']) === ($f['cert'] === 'yes')) return false;
            if (isset($f['payment']) && ($r['form_status'] ?? '') !== $f['payment']) return false;
            if (isset($f['source']) && ($r['source'] ?? '') !== $f['source']) return false;
            if (isset($f['wpaid']) && in_array($r['workshop_paid'] ?? '', ['paid', 'complimentary'], true) !== ($f['wpaid'] === 'yes')) return false;
            if (isset($f['status']) && ($r['sponsor_status'] ?? '') !== $f['status']) return false;
            if (isset($f['package']) && ($f['package'] === '-' ? ($r['package'] ?? null) !== null : (string) ($r['package'] ?? '') !== $f['package'])) return false;
            if (isset($f['calls'])) {
                $next = (string) ($r['next_call_at'] ?? '');
                $open = !in_array($r['sponsor_status'] ?? '', ['confirmed', 'declined', 'cancelled'], true);
                if (!$open || $next === '' || ($f['calls'] === 'due' ? substr($next, 0, 10) !== $today : substr($next, 0, 10) >= $today)) return false;
            }
            if (isset($f['city']) && $lower($r['city'] ?? '') !== $lower($f['city'])) return false;
            if (isset($f['specialty']) && ($r['specialty'] ?? '') !== $f['specialty']) return false;
            if (isset($f['university']) && $lower($r['university'] ?? '') !== $lower($f['university'])) return false;
            $day = substr((string) ($r[$dateField] ?? ''), 0, 10);
            if (isset($f['from']) && ($day === '' || $day < $f['from'])) return false;
            if (isset($f['to']) && ($day === '' || $day > $f['to'])) return false;
            if (isset($f['amb'])) {
                $code = self::ambassadorCode($r);
                if (!in_array($code === '' ? '-' : $code, $f['amb'], true)) return false;
            }
            return true;
        }));
        $sort = $filters['sort'] ?? '';
        if ($sort === 'name') {
            usort($rows, static fn (array $a, array $b): int => strcasecmp(($a['company'] ?? '') . \Ismile\Registrations::fullName($a), ($b['company'] ?? '') . \Ismile\Registrations::fullName($b)));
        } elseif ($sort !== '') {
            usort($rows, static fn (array $a, array $b): int => $sort === 'newest'
                ? strcmp((string) ($b[$dateField] ?? ''), (string) ($a[$dateField] ?? ''))
                : strcmp((string) ($a[$dateField] ?? ''), (string) ($b[$dateField] ?? '')));
        }
        return $rows;
    }

    /** The choices for the filter menus, taken from everyone on the list. */
    public static function options(array $rows): array
    {
        $values = static function (string $key) use ($rows): array {
            $seen = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') $seen[mb_strtolower($value)] ??= $value;
            }
            natcasesort($seen);
            return array_values($seen);
        };
        $names = [];
        foreach (Db::all('SELECT code, owner_name FROM ambassadors') as $ambassador) {
            $names[mb_strtoupper(trim($ambassador['code']))] = $ambassador['owner_name'];
        }
        $counts = [];
        foreach ($rows as $row) {
            $code = self::ambassadorCode($row);
            $counts[$code === '' ? '-' : $code] = ($counts[$code === '' ? '-' : $code] ?? 0) + 1;
        }
        $none = $counts['-'] ?? 0;
        unset($counts['-']);
        arsort($counts);
        $ambassadors = [];
        foreach ($counts as $code => $count) {
            $ambassadors[] = ['code' => (string) $code, 'name' => $names[$code] ?? '', 'count' => $count];
        }
        return [
            'city' => $values('city'), 'university' => $values('university'),
            'specialty' => array_values(array_intersect(array_keys(\Ismile\Registrations::SPECIALTY_NAMES), array_column($rows, 'specialty'))),
            'status' => $values('sponsor_status'), 'package' => $values('package'),
            'ambassadors' => $ambassadors, 'no_ambassador' => $none,
        ];
    }

    /** "Ticket: Student", "Ambassador: SARA26"… for the chips and the PDF subtitle. */
    public static function describe(string $list, array $filters): array
    {
        $names = [
            'ticket' => \Ismile\Registrations::TICKET_NAMES,
            'lunch' => ['day1' => 'Day 1', 'day2' => 'Day 2', 'both' => 'Both days', 'none' => 'No lunch'],
            'arrived' => ['day1' => 'Day 1', 'day2' => 'Day 2', 'none' => 'Not yet'],
            'cert' => ['yes' => 'Issued', 'no' => 'Not yet'], 'payment' => ['paid' => 'Paid', 'waiting' => 'Waiting', 'expired' => 'Expired'],
            'source' => ['website' => 'Website', 'office' => 'Office'], 'wpaid' => ['yes' => 'Paid', 'no' => 'Not paid'],
            'calls' => ['due' => 'Due today', 'overdue' => 'Overdue'], 'specialty' => \Ismile\Registrations::SPECIALTY_NAMES,
        ];
        $labels = ['q' => 'Search', 'ticket' => 'Ticket', 'lunch' => 'Lunch', 'arrived' => 'Arrived', 'cert' => 'Certificate', 'payment' => 'Payment',
            'source' => 'Sent from', 'wpaid' => 'Workshop', 'status' => 'Status', 'package' => 'Package', 'calls' => 'Calls', 'city' => 'City',
            'specialty' => 'Specialty', 'university' => 'University', 'from' => 'From', 'to' => 'To', 'amb' => 'Ambassador'];
        $out = [];
        foreach (self::active($list, $filters) as $key => $value) {
            if ($key === 'amb') {
                foreach ($value as $code) $out[] = ['key' => $key, 'value' => $code, 'label' => 'Ambassador: ' . ($code === '-' ? 'none' : $code)];
                continue;
            }
            $shown = $key === 'package' && $value === '-' ? 'Not chosen' : ($key === 'status' ? ucfirst(str_replace('_', ' ', $value)) : ($names[$key][$value] ?? $value));
            $out[] = ['key' => $key, 'value' => $value, 'label' => $labels[$key] . ': ' . $shown];
        }
        return $out;
    }

    /** The address of this list with these filters. */
    public static function query(string $list, array $filters): string
    {
        $parts = ['list=' . rawurlencode($list)];
        foreach (self::active($list, $filters) + (($filters['sort'] ?? '') !== '' ? ['sort' => $filters['sort']] : []) as $key => $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode(is_array($value) ? implode(',', $value) : (string) $value);
        }
        return implode('&', $parts);
    }

    /** How many people are on each list (for the tabs). */
    public static function counts(): array
    {
        $row = Db::one(
            "SELECT
               COALESCE(SUM(status IN ('paid','complimentary')), 0)                           AS registered,
               COALESCE(SUM(status IN ('paid','complimentary') AND lunch_day1 = 1), 0)        AS lunch1,
               COALESCE(SUM(status IN ('paid','complimentary') AND lunch_day2 = 1), 0)        AS lunch2,
               COALESCE(SUM(status IN ('paid','complimentary') AND ticket_type = 'student'), 0) AS students,
               COALESCE(SUM(status IN ('paid','complimentary') AND ticket_type = 'student'), 0) AS studentids,
               COALESCE(SUM(status = 'cancelled'), 0)                                         AS cancelled
             FROM registrations"
        ) ?? [];
        $row['forms'] = (int) Db::value('SELECT COUNT(*) FROM checkouts');
        $row['attendance'] = $row['registered'];
        $row['workshops'] = (int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE removed_at IS NULL');
        $row['sponsors'] = (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE kind = 'sponsor'");
        $row['exhibition'] = (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE kind = 'booth'");
        return array_map('intval', $row);
    }
}
