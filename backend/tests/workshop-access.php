<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Auth.php';
use Ismile\Auth;
$passed=0;
$check=static function(string $label,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$label);$passed++;echo "PASS: $label\n";};
foreach(Auth::ROLES as $role){
    $base=['role'=>$role];$granted=$base+['workshop_booking_access'=>1];
    $check($role.' default workshop booking remains owner-only',Auth::canBookWorkshops($base)===($role==='owner'));
    $check($role.' individual workshop grant permits booking routes',Auth::canBookWorkshops($granted)&&Auth::can($granted,'workshops')&&Auth::viewerMayPost($granted,'registration.php')&&Auth::viewerMayPost($granted,'workshops.php')&&Auth::needsTwoFactor($granted));
    foreach(['edit','content','registrations','payments','association','sponsors','checkin','certificates','photos']as$area)$check($role.' workshop grant preserves '.$area,Auth::can($granted,$area)===Auth::can($base,$area));
    foreach(['users.php','settings.php','sponsors.php','map.php','association.php','payments.php','content.php']as$page)$check($role.' workshop grant preserves changes on '.$page,Auth::viewerMayPost($granted,$page)===Auth::viewerMayPost($base,$page));
    $check($role.' guest issuance is a separate permission',Auth::canIssueComplimentary($granted)===Auth::canIssueComplimentary($base));
    $both=$granted+['registration_manage_access'=>1];
    $check($role.' combined grant permits guests and workshops',Auth::canIssueComplimentary($both)&&Auth::canBookWorkshops($both));
}
foreach([0,2,-1]as$flag)$check('invalid workshop flag grants no booking: '.$flag,!Auth::canBookWorkshops(['role'=>'viewer','workshop_booking_access'=>$flag]));
if(in_array('--database',$argv,true)){
    require __DIR__.'/../bootstrap.php';
    if(\Ismile\App::isLive() || !str_starts_with((string)\Ismile\App::config('db.name'),'ismile_sponsored_test_'))throw new RuntimeException('Local isolated database required.');
    $tag=bin2hex(random_bytes(5));
    $targetId=Auth::createUser('workshop-grant-'.$tag.'@example.invalid','Workshop Grant Test','viewer',bin2hex(random_bytes(16)));
    $otherId=Auth::createUser('workshop-other-'.$tag.'@example.invalid','Other Grant Test','viewer',bin2hex(random_bytes(16)));
    $before=\Ismile\Db::one('SELECT * FROM admin_users WHERE id=?',[$targetId]);$other=\Ismile\Db::one('SELECT * FROM admin_users WHERE id=?',[$otherId]);
    putenv('WORKSHOP_EDITOR_EMAIL='.$before['email']);
    $grant=static function():string{$command=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($command,'-c',$ini);array_push($command,__DIR__.'/../tools/configure-workshop-manager.php');$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);return $out;};
    $check('CLI grants workshop and guest access to the designated account',str_contains($grant(),'access granted and verified'));
    $after=\Ismile\Db::one('SELECT * FROM admin_users WHERE id=?',[$targetId]);
    $check('grant preserves role credentials and every other permission',$after===array_replace($before,['registration_manage_access'=>1,'workshop_booking_access'=>1]));
    $check('other accounts remain unchanged',\Ismile\Db::one('SELECT * FROM admin_users WHERE id=?',[$otherId])===$other);
    $grant();
    $check('repeat grant is idempotent',(int)\Ismile\Db::value("SELECT COUNT(*) FROM audit_log WHERE action='user.workshop_guest_manage_grant' AND target_id=?",[$targetId])===1);
    $revoked=array_replace($after,['workshop_booking_access'=>0]);
    $check('revoking workshop access retains guest access',!Auth::canBookWorkshops($revoked)&&Auth::canIssueComplimentary($revoked));
}
echo "$passed workshop access checks passed.\n";
