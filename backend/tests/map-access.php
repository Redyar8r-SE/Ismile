<?php
declare(strict_types=1);

require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Admin/Page.php';

use Ismile\Auth;
use Ismile\Admin\Page;

foreach (Auth::ROLES as $role) {
    $user = ['role' => $role];
    if (!Auth::can($user, 'map')) throw new RuntimeException("$role cannot view Map");
    foreach (['map.php', 'sponsors.php'] as $page) {
        if (Auth::viewerMayPost($user, '/admin/' . $page) !== ($role === 'owner')) throw new RuntimeException("Unexpected booking access for $role");
    }
    echo "PASS: $role has correct default Map access\n";
}
if (Page::MENU['map'] !== ['Map', 'map']) throw new RuntimeException('Map menu uses the wrong permission');
foreach (['registration', 'content', 'checkin'] as $role) {
    if (Auth::can(['role' => $role], 'sponsors')) throw new RuntimeException('Map access expanded sponsor access');
}
if (Auth::viewerMayPost(['role' => 'sponsors'], '/admin/sponsors.php')) throw new RuntimeException('Undesignated sponsor staff can book');
if (Auth::viewerMayPost(['role' => 'viewer'], '/admin/sponsors.php')) throw new RuntimeException('Viewer gained booking permission');
echo "PASS: navigation and existing booking permissions are preserved\n";
$editor = ['role' => 'sponsors', 'map_booking_access' => 1];
if (!Auth::canManageMap($editor) || !Auth::viewerMayPost($editor, '/admin/map.php') || !Auth::viewerMayPost($editor, '/admin/sponsors.php')) throw new RuntimeException('Designated map manager cannot book');
$viewer = ['role' => 'map_viewer', 'map_booking_access' => 1];
if (Auth::canManageMap($viewer)) throw new RuntimeException('Map viewer inherited booking access');
if (Page::home($viewer) !== 'map.php') throw new RuntimeException('Map viewer does not start on Map');
foreach (Page::MENU as $page => [, $area]) {
    if (Auth::can($viewer, $area) !== ($page === 'map')) throw new RuntimeException('Map viewer can open ' . $page);
}
foreach (['map.php','sponsors.php','booths.php','users.php','settings.php'] as $page) {
    if (Auth::viewerMayPost($viewer, '/admin/' . $page)) throw new RuntimeException('Map viewer may post to ' . $page);
}
echo "PASS: only Owner and individually designated manager can book\n";
echo "PASS: Map viewer has only Map, starts there, and cannot post changes\n";
