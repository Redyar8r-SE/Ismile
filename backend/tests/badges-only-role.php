<?php
declare(strict_types=1);

// Tickets & Certificates only: one menu item, everything inside it, nothing else.
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Admin/Page.php';

use Ismile\Auth;
use Ismile\Admin\Page;

$badges = ['role' => 'badges', 'registration_manage_access' => 1, 'workshop_booking_access' => 1, 'map_booking_access' => 1, 'association_manage_access' => 1];
foreach (Page::MENU as $page => [, $area]) {
    $can = $area === 'owner' ? false : Auth::can($badges, $area);
    if ($can !== ($page === 'tickets')) throw new RuntimeException('Tickets & Certificates only can open ' . $page);
}
foreach (['dashboard', 'registrations', 'edit', 'payments', 'association', 'workshops', 'sponsors', 'map', 'checkin', 'export', 'photos', 'content', 'communications'] as $area) {
    if (Auth::can($badges, $area)) throw new RuntimeException("Tickets & Certificates only can use $area");
}
if (!Auth::can($badges, 'certificates') || !Auth::can($badges, 'badges') || !Auth::can($badges, 'attendance')) throw new RuntimeException('Tickets & Certificates only cannot use its pages');
if (!Auth::canManageBadges($badges)) throw new RuntimeException('Tickets & Certificates only cannot make badges');
foreach (['tickets.php', 'certificates.php', 'psoola-ticket.php'] as $page) {
    if (!Auth::viewerMayPost($badges, '/admin/' . $page)) throw new RuntimeException("Tickets & Certificates only cannot save on $page");
}
if (Page::home($badges) !== 'tickets.php') throw new RuntimeException('Tickets & Certificates only does not start on Tickets');
if (!Auth::needsTwoFactor($badges)) throw new RuntimeException('Tickets & Certificates only needs the phone code');
if (!in_array('badges', Auth::ROLES, true) || !isset(Auth::ROLE_NAMES['badges'])) throw new RuntimeException('Role missing from the role list');
// Existing roles keep their access to badge PDFs and QR previews.
foreach (['registration', 'finance', 'viewer', 'sponsors'] as $role) {
    if (!Auth::can(['role' => $role], 'badges')) throw new RuntimeException("$role lost badge PDFs");
    if (!Auth::can(['role' => $role], 'attendance')) throw new RuntimeException("$role lost the attendance report");
}
foreach (['content', 'checkin', 'map_viewer'] as $role) {
    if (Auth::can(['role' => $role], 'badges')) throw new RuntimeException("$role gained badge PDFs");
    if (Auth::can(['role' => $role], 'attendance')) throw new RuntimeException("$role gained the attendance report");
}
echo "PASS: Tickets & Certificates only sees one page and manages everything in it
";
