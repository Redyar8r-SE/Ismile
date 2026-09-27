<?php
// Excel exports (CSV that Excel opens with Kurdish and Arabic intact). Only the
// columns needed; every export is logged with who and when.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\RegistrationQuery;
use Ismile\Audit;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\SiteData;

$what = (string) ($_GET['what'] ?? '');
$user = Page::guard($what === 'workshop' ? 'workshops' : 'export');

$rows = [];
$name = 'ismile';
switch ($what) {
    case 'registrations':
        $in = [];
        foreach (RegistrationQuery::FILTERS as $key) {
            $in[$key] = Page::query($key);
        }
        [$where, $params] = RegistrationQuery::where($in);
        $rows[] = ['Reference', 'First name', "Father's name", "Grandfather's name", 'Phone', 'Email', 'City', 'Ticket', 'Specialty', 'University', 'Lunch day 1', 'Lunch day 2', 'Status', 'Ticket no.', 'Checked in', 'Language', 'Form sent', 'Paid'];
        foreach (Db::all('SELECT r.*, t.ticket_no, t.checked_in_at FROM ' . RegistrationQuery::from() . " WHERE $where ORDER BY r.first_name, r.father_name", $params) as $row) {
            $rows[] = [$row['ref'], $row['first_name'], $row['father_name'], $row['grandfather_name'], $row['phone'], $row['email'], $row['city'], $row['ticket_type'], $row['specialty'], $row['university'] ?? '',
                $row['lunch_day1'] ? 'yes' : '', $row['lunch_day2'] ? 'yes' : '', $row['status'], $row['ticket_no'] ?? '', $row['checked_in_at'] ?? '', $row['lang'], $row['created_at'], $row['paid_at'] ?? ''];
        }
        $name = 'ismile-registrations';
        break;

    case 'payments':
        if (!Auth::can($user, 'payments')) {
            Page::redirect('index.php');
        }
        $rows[] = ['Payment #', 'Reference', 'Name', 'Method', 'Expected', 'Confirmed', 'Currency', 'Status', 'Company id', 'Started', 'Confirmed at', 'Note'];
        foreach (Db::all('SELECT p.*, COALESCE(r.ref, c.ref) AS ref, COALESCE(r.first_name, c.first_name, \'\') AS first_name, COALESCE(r.father_name, c.father_name, \'\') AS father_name,
                                 COALESCE(r.grandfather_name, c.grandfather_name, \'\') AS grandfather_name
                          FROM payments p LEFT JOIN registrations r ON r.id = p.registration_id LEFT JOIN checkouts c ON c.id = p.checkout_id ORDER BY p.id') as $row) {
            $rows[] = [$row['id'], $row['ref'], Registrations::fullName($row), $row['method'], $row['amount_expected'], $row['amount_confirmed'] ?? '', $row['currency'], $row['status'], $row['provider_payment_id'] ?? '', $row['created_at'], $row['confirmed_at'] ?? '', $row['last_error'] ?? ''];
        }
        $name = 'ismile-payments';
        break;

    case 'sponsors':
        if (!Auth::can($user, 'sponsors')) {
            Page::redirect('index.php');
        }
        $rows[] = ['Reference', 'Kind', 'Package', 'Company', 'Contact', 'Role', 'Phone', 'Email', 'Website', 'City', 'Status', 'Amount agreed', 'Received', 'Message', 'Notes'];
        foreach (Db::all('SELECT * FROM sponsor_requests ORDER BY id') as $row) {
            $rows[] = [$row['ref'], $row['kind'], $row['package'] ?? '', $row['company'], $row['contact_name'], $row['contact_role'] ?? '', $row['phone'], $row['email'], $row['website'] ?? '', $row['city'] ?? '', $row['status'], $row['amount_agreed'] ?? '', $row['created_at'], $row['message'] ?? '', $row['notes'] ?? ''];
        }
        $name = 'ismile-sponsors';
        break;

    case 'workshop':
        $workshop = SiteData::workshop((string) ($_GET['id'] ?? ''));
        if ($workshop === null) {
            Page::redirect('workshops.php');
        }
        $rows[] = [SiteData::workshopName($workshop) . ' – sign-in sheet'];
        $rows[] = ['#', 'Name', 'Phone', 'Workshop paid?', 'Amount paid', 'Signature'];
        $number = 0;
        foreach (Db::all('SELECT wb.payment_status, wb.amount_paid, r.first_name, r.father_name, r.grandfather_name, r.phone FROM workshop_bookings wb JOIN registrations r ON r.id = wb.registration_id WHERE wb.workshop_id = ? AND wb.removed_at IS NULL ORDER BY r.first_name', [$workshop['id']]) as $row) {
            $paidWord = ['paid' => 'PAID', 'complimentary' => 'FREE'][$row['payment_status']] ?? 'NOT PAID';
            $rows[] = [++$number, Registrations::fullName($row), $row['phone'], $paidWord, $row['amount_paid'] ?? '', ''];
        }
        $name = 'ismile-workshop-' . $workshop['id'];
        break;

    case 'list':
        $list = \Ismile\Admin\Lists::pick((string) ($_GET['list'] ?? ''));
        $columns = \Ismile\Admin\Lists::columns($list);
        $rows[] = [\Ismile\Admin\Lists::ALL[$list][0]];
        $number = 0;
        if (isset(\Ismile\Admin\Lists::COMPANY_LISTS[$list])) {
            $rows[] = array_merge(['#', 'Company'], array_values($columns));
            foreach (\Ismile\Admin\Lists::rows($list) as $row) {
                $cells = [++$number, $row['company']];
                foreach (array_keys($columns) as $key) {
                    $cells[] = (string) ($row[$key] ?? '');
                }
                $rows[] = $cells;
            }
            $name = 'ismile-' . $list;
            break;
        }
        if ($list === 'workshops') {
            $rows[] = array_merge(['Workshop', '#', 'Name'], array_values($columns));
            foreach (\Ismile\Admin\Lists::workshopGroups() as ['workshop' => $workshop, 'people' => $people]) {
                foreach (array_values($people) as $i => $row) {
                    $cells = [SiteData::workshopName($workshop), $i + 1, Registrations::fullName($row)];
                    foreach (array_keys($columns) as $key) {
                        $cells[] = $key === 'workshop_paid' ? (['paid' => 'PAID', 'complimentary' => 'FREE'][$row['workshop_paid']] ?? 'NOT PAID') : (string) ($row[$key] ?? '');
                    }
                    $rows[] = $cells;
                }
            }
            $name = 'ismile-workshops';
            break;
        }
        $rows[] = array_merge(['#', 'Name', 'Paid?'], array_values($columns));
        foreach (\Ismile\Admin\Lists::rows($list) as $row) {
            $cells = [++$number, Registrations::fullName($row), in_array($row['status'], ['paid', 'complimentary'], true) ? 'PAID' : 'NOT PAID'];
            foreach (array_keys($columns) as $key) {
                $cells[] = $key === 'lunch' ? trim(($row['lunch_day1'] ? 'Day 1 ' : '') . ($row['lunch_day2'] ? 'Day 2' : '')) : (string) ($row[$key] ?? '');
            }
            $rows[] = $cells;
        }
        $name = 'ismile-' . $list;
        break;

    default:
        Page::redirect('index.php');
}

Audit::log((int) $user['id'], 'export', null, null, ['what' => $what, 'rows' => count($rows) - 1, 'filters' => $_GET]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d-Hi') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // lets Excel read Kurdish and Arabic correctly
foreach ($rows as $row) {
    fputcsv($out, array_map(static function ($value): string {
        $value = (string) $value;
        // Phone numbers stay text (Excel would turn +964… into 9.64E+12).
        if (preg_match('/^\+\d{6,15}$/', $value)) {
            return '="' . $value . '"';
        }
        // A typed-in cell starting with = + - @ could run as a formula: prefix it.
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }, $row));
}
fclose($out);
