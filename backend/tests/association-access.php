<?php
declare(strict_types=1);
require __DIR__.'/../src/Auth.php';
require __DIR__.'/../src/Association.php';
require __DIR__.'/../src/Admin/Page.php';
use Ismile\Auth;
use Ismile\Association;
use Ismile\Admin\Page;

foreach (Auth::ROLES as $role) {
    $normal = ['role'=>$role];
    $manager = $normal + ['association_manage_access'=>1];
    if (Association::canManage($normal) !== in_array($role,['owner','finance'],true)) throw new RuntimeException('Default Association access changed: '.$role);
    if (!Association::canManage($manager) || !Auth::can($manager,'association') || !Auth::viewerMayPost($manager,'/admin/association.php')) throw new RuntimeException('Designated manager cannot use Association: '.$role);
    foreach (Page::MENU as $page=>[, $area]) {
        if ($area !== 'association' && Auth::can($normal,$area) !== Auth::can($manager,$area)) throw new RuntimeException('Association grant widened another area: '.$area);
        if ($page !== 'association' && Auth::viewerMayPost($normal,'/admin/'.$page.'.php') !== Auth::viewerMayPost($manager,'/admin/'.$page.'.php')) throw new RuntimeException('Association grant widened changes on '.$page);
    }
    foreach (['edit','export','photos','content'] as $area) if (Auth::can($normal,$area) !== Auth::can($manager,$area)) throw new RuntimeException('Association grant widened '.$area);
    if (Auth::canManageMap($normal) !== Auth::canManageMap($manager)) throw new RuntimeException('Association grant changed Map access.');
    echo "PASS: $role has scoped Association management and preserves other permissions\n";
}
if (Association::canManage(['role'=>'viewer','association_manage_access'=>0]) || Association::canManage(['role'=>'viewer','association_manage_access'=>2])) throw new RuntimeException('Invalid flag grants access.');
echo "PASS: missing, zero and invalid grants remain read-only\n";
