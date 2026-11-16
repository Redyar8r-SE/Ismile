<?php
declare(strict_types=1);

// Volunteer: one menu item (Workshop & Register), cash sales inside it, nothing else.
//   php backend/tests/volunteer-role.php
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Admin/Page.php';

use Ismile\Auth;
use Ismile\Admin\Page;

$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $label);
    $passed++;
};

// Even with every extra grant ticked, a Volunteer sees only Workshop & Register.
$volunteer = ['role' => 'volunteer', 'registration_manage_access' => 1, 'workshop_booking_access' => 1, 'map_booking_access' => 1, 'association_manage_access' => 1, 'badge_certificate_access' => 1];
foreach (Page::MENU as $page => [, $area]) {
    $can = $area === 'owner' ? false : Auth::can($volunteer, $area);
    $check("volunteer menu: $page", $can === ($page === 'desk'));
}
foreach (['dashboard', 'registrations', 'edit', 'payments', 'association', 'workshops', 'sponsors', 'map', 'checkin', 'export', 'certificates', 'badges', 'attendance', 'photos', 'content', 'communications'] as $area) {
    $check("volunteer cannot use $area", !Auth::can($volunteer, $area));
}
$check('volunteer can sell summit tickets and workshops', Auth::can($volunteer, 'desk') && Auth::canDeskRegister($volunteer) && Auth::canDeskWorkshop($volunteer));
$check('volunteer can send the desk forms', Auth::viewerMayPost($volunteer, '/admin/desk.php'));
$check('volunteer starts on Workshop & Register', Page::home($volunteer) === 'desk.php');
$check('volunteer signs in without a phone code', !Auth::needsTwoFactor(['role' => 'volunteer']) && !Auth::needsTwoFactor($volunteer));
$check('volunteer is in the role list with a name', in_array('volunteer', Auth::ROLES, true) && isset(Auth::ROLE_NAMES['volunteer']));
$check('volunteer cannot give free tickets or manage badges', !Auth::canIssueComplimentary(['role' => 'volunteer']) && !Auth::canManageBadges(['role' => 'volunteer']));

// Existing roles: the desk follows what they could already sell.
$check('owner uses both desk tabs', Auth::can(['role' => 'owner'], 'desk') && Auth::canDeskRegister(['role' => 'owner']) && Auth::canDeskWorkshop(['role' => 'owner']));
$check('registration staff use both desk tabs', Auth::can(['role' => 'registration'], 'desk') && Auth::canDeskRegister(['role' => 'registration']) && Auth::canDeskWorkshop(['role' => 'registration']));
foreach (['finance', 'content', 'checkin', 'viewer', 'sponsors', 'map_viewer', 'badges'] as $role) {
    $check("$role does not get the desk", !Auth::can(['role' => $role], 'desk') && !Auth::canDeskRegister(['role' => $role]) && !Auth::canDeskWorkshop(['role' => $role]));
}
$check('a workshop grant opens only the Workshop tab', Auth::can(['role' => 'finance', 'workshop_booking_access' => 1], 'desk')
    && Auth::canDeskWorkshop(['role' => 'finance', 'workshop_booking_access' => 1]) && !Auth::canDeskRegister(['role' => 'finance', 'workshop_booking_access' => 1]));
$check('a registration grant opens the Register tab', Auth::canDeskRegister(['role' => 'finance', 'registration_manage_access' => 1]));
$check('the Tickets & Certificates role keeps its single page', !Auth::can(['role' => 'badges', 'registration_manage_access' => 1, 'workshop_booking_access' => 1], 'desk'));

echo "Volunteer role checks passed: $passed\n";
