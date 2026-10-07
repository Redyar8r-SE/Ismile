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
            'attendance' => ['ticket_no' => 'Ticket', 'day1' => 'Day 1 arrival', 'day2' => 'Day 2 arrival', 'certificate_no' => 'Certificate', 'ref' => 'Reference'],
            'workshops'        => ['phone' => 'Phone', 'workshop_paid' => 'Workshop paid?', 'amount_paid' => 'Amount (IQD)', 'ref' => 'Reference'],
            'sponsors'         => ['package' => 'Package', 'contact_name' => 'Contact', 'phone' => 'Phone', 'sponsor_status' => 'Status', 'amount_agreed' => 'Agreed (IQD)', 'amount_paid' => 'Paid (IQD)', 'next_call_at' => 'Next call', 'cancelled_at' => 'Cancelled on', 'cancellation_reason' => 'Cancellation reason', 'ref' => 'Reference'],
            'exhibition'       => ['package' => 'Booth type', 'contact_name' => 'Contact', 'phone' => 'Phone', 'sponsor_status' => 'Status', 'amount_agreed' => 'Agreed (IQD)', 'amount_paid' => 'Paid (IQD)', 'next_call_at' => 'Next call', 'cancelled_at' => 'Cancelled on', 'cancellation_reason' => 'Cancellation reason', 'ref' => 'Reference'],
            'students', 'studentids' => ['phone' => 'Phone', 'university' => 'University', 'ambassador_code' => 'Ambassador', 'id_photo' => 'ID photo', 'ticket_no' => 'Ticket no.', 'ref' => 'Reference'],
            'forms'            => ['phone' => 'Phone', 'email' => 'Email', 'ticket_type' => 'Ticket', 'lunch' => 'Lunch', 'university' => 'University', 'form_status' => 'Payment', 'created_at' => 'Sent', 'ref' => 'Reference'],
            'lunch1', 'lunch2' => ['phone' => 'Phone', 'ticket_type' => 'Ticket', 'ticket_no' => 'Ticket no.', 'ref' => 'Reference'],
            default            => ['phone' => 'Phone', 'ticket_type' => 'Ticket', 'lunch' => 'Lunch', 'ticket_no' => 'Ticket no.', 'ref' => 'Reference', 'paid_at' => 'Paid'],
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
