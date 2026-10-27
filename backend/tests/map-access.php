<?php
declare(strict_types=1);

require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Admin/Page.php';

use Ismile\Auth;
use Ismile\Admin\Page;

foreach (Auth::ROLES as $role) {
    $user = ['role' => $role];
    if (!Auth::can($user, 'map')) throw new RuntimeException("$role cannot view Map");
    if (Auth::viewerMayPost($user, '/admin/map.php')) throw new RuntimeException("$role may change Map");
    echo "PASS: $role can view Map and cannot send changes\n";
}
if (Page::MENU['map'] !== ['Map', 'map']) throw new RuntimeException('Map menu uses the wrong permission');
foreach (['registration', 'content', 'checkin'] as $role) {
    if (Auth::can(['role' => $role], 'sponsors')) throw new RuntimeException('Map access expanded sponsor access');
}
if (!Auth::viewerMayPost(['role' => 'sponsors'], '/admin/sponsors.php')) throw new RuntimeException('Sponsor booking permission changed');
if (Auth::viewerMayPost(['role' => 'viewer'], '/admin/sponsors.php')) throw new RuntimeException('Viewer gained booking permission');
echo "PASS: navigation and existing booking permissions are preserved\n";
