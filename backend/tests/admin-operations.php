<?php
// php -d extension=mbstring backend/tests/admin-operations.php
// No database or mail service needed: validate catalog and access boundaries.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class,'Ismile\\')) require __DIR__ . '/../src/' . str_replace('\\','/',substr($class,7)) . '.php';
});

use Ismile\App;
use Ismile\Auth;
use Ismile\Backup;
use Ismile\Admin\CommunicationQuery;
use Ismile\Admin\Page;

$root = sys_get_temp_dir() . '/ismile-admin-check-' . bin2hex(random_bytes(6));
mkdir($root,0750,true);
$config=['site_url'=>'http://127.0.0.1','site_root'=>$root,'storage'=>$root,'db'=>['name'=>'unused'],'secret'=>str_repeat('test-only-',5)];
App::boot($config);
$passed=0;
$check=static function (string $name,bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++; echo "PASS: $name\n";
};
$created=[];
$put=static function(string $name,string $body,int $age=0) use (&$created): string {
    $path=App::storage('backups/' . $name);
    file_put_contents($path,$body);
    touch($path,time()-$age);
    $created[]=$path;
    return $path;
};
try {
    App::boot(['content_editor_url'=>'https://editor.example.invalid/admin.html']+$config);
    $check('Site content overview remains inside the Database',Page::contentUrl()==='content.php');
    $check('configured external editor is preserved',Page::contentEditorUrl()==='https://editor.example.invalid/admin.html');
    $check('content staff land on their Database overview',Page::home(['role'=>'content'])==='content.php');
    App::boot($config);
    foreach (Auth::ROLES as $role) {
        $check("communication access for $role",Auth::can(['role'=>$role],'communications')===in_array($role,['owner','registration','finance'],true));
    }
    [$scope,$params]=CommunicationQuery::where(['role'=>'registration'],['status'=>'failed','kind'=>'alert','q'=>"x' OR 1=1 --"]);
    $check('staff never gains alert access through filters',str_contains($scope,"e.kind <> 'alert'") && !str_contains($scope,"x' OR"));
    $check('filter values are passed as parameters',in_array('failed',$params,true) && in_array('alert',$params,true));
    [$invalid,$invalidParams]=CommunicationQuery::where(['role'=>'owner'],['status'=>'bad','kind'=>'bad']);
    $check('unknown filter values are ignored',$invalid==='1=1' && $invalidParams===[]);
    $check('skipped messages are classified separately',str_contains(CommunicationQuery::STATE_SQL,"LIKE 'skipped:%'") && str_contains(CommunicationQuery::STATE_SQL,"THEN 'skipped'"));
    $check('empty backup catalog has no success',Backup::available()===[] && Backup::latestTime()===null);
    $sql="-- iSmile backup test\nSET FOREIGN_KEY_CHECKS = 0;\nSET FOREIGN_KEY_CHECKS = 1;\n";
    $valid='ismile-2026-10-06-010000-abcdef.sql.gz';
    $put($valid,gzencode($sql),60);
    $put('ismile-2026-10-06-020000-abcdef.sql.gz',gzencode("-- iSmile backup test\npartial\n"));
    $put('ismile-2026-10-06-030000-abcdef.sql.gz',"not a gzip file");
    $put('ismile-2026-10-06-040000-abcdef.sql.gz.part',gzencode($sql));
    $put('ismile-2026-10-06-050000-abcdef.sql.gz',$sql);
    $bad=gzencode($sql);$bad[strlen($bad)-8]=chr(ord($bad[strlen($bad)-8]) ^ 255);
    $put('ismile-2026-10-06-060000-abcdef.sql.gz',$bad);
    $available=Backup::available();
    $check('only the completed valid gzip is offered',count($available)===1 && $available[0]['name']===$valid);
    $check('newer incomplete files do not become the latest success',Backup::latestTime()===$available[0]['created_at']);
    $check('valid catalog entries include size and timestamp',$available[0]['size']>0 && $available[0]['created_at']>0);
    $cachePath=App::storage('backups/' . $valid . '.verified.json');
    $cached=json_decode((string)file_get_contents($cachePath),true);
    $check('full verification persists a small result',($cached['complete']??false)===true && filesize($cachePath)<2048);
    // A deliberately changed cached verdict proves catalog calls skip the full
    // read. Download lookup must still check the actual bytes independently.
    $cached['complete']=false;
    file_put_contents($cachePath,json_encode($cached));
    $check('catalog uses a recent unchanged-file result',Backup::available()===[]);
    $check('downloads ignore the catalog cache',Backup::find($valid)!==null);
    $cached=json_decode((string)file_get_contents($cachePath),true);
    $cached['complete']=false;$cached['checked_at']=time()-86401;
    file_put_contents($cachePath,json_encode($cached));
    $check('expired catalog results are fully rechecked',count(Backup::available())===1);
    file_put_contents($cachePath,'invalid JSON');
    $check('damaged verification cache is rebuilt',count(Backup::available())===1);
    $cached=json_decode((string)file_get_contents($cachePath),true);
    $cached['fingerprint']='different-file';$cached['complete']=false;
    file_put_contents($cachePath,json_encode($cached));
    $check('mismatched file metadata triggers verification',count(Backup::available())===1);
    $validPath=App::storage('backups/' . $valid);
    $original=(string)file_get_contents($validPath);
    $damaged=$original;$damaged[strlen($damaged)-8]=chr(ord($damaged[strlen($damaged)-8]) ^ 255);
    file_put_contents($validPath,$damaged);
    $check('changed backup trailer invalidates a cached success',Backup::available()===[] && Backup::find($valid)===null);
    file_put_contents($validPath,$original);
    $check('repaired backup is checked again',count(Backup::available())===1);
    // Even matching size/timestamps/trailer are not sufficient for downloads.
    $cached=json_decode((string)file_get_contents($cachePath),true);
    $damaged=$original;$damaged[12]=chr(ord($damaged[12]) ^ 255);
    file_put_contents($validPath,$damaged);
    $cached['fingerprint']=(new ReflectionMethod(Backup::class,'fingerprint'))->invoke(null,$validPath);
    file_put_contents($cachePath,json_encode($cached));
    $check('fresh download verification rejects corruption despite a cached success',Backup::find($valid)===null);
    file_put_contents($validPath,$original);
    $check('backup can be downloaded again after repair',Backup::find($valid)!==null);
    $lock=fopen(App::storage('tmp/backup.lock'),'c');
    flock($lock,LOCK_EX);
    $blocked=false;
    try { Backup::run(); } catch (Ismile\UserError $error) { $blocked=str_contains($error->key,'already running'); }
    finally { fclose($lock); }
    $check('another backup is rejected before any database work',$blocked);
    foreach (['../config.php','..\\config.php','/etc/passwd',$valid . '.part','unknown.sql.gz',"$valid\0"] as $name) $check('rejects invalid download name ' . str_replace("\0",'NUL',$name),Backup::find($name)===null);
    echo "$passed checks passed.\n";
} finally {
    foreach ($created as $file) {
        if (is_file($file)) unlink($file);
        if (is_file($file . '.verified.json')) unlink($file . '.verified.json');
    }
    if (is_file(App::storage('tmp/backup.lock'))) unlink(App::storage('tmp/backup.lock'));
    foreach (['.htaccess'] as $file) if (is_file($root.'/'.$file)) unlink($root.'/'.$file);
    foreach (['logs','outbox','backups','tmp'] as $folder) if (is_dir($root.'/'.$folder)) rmdir($root.'/'.$folder);
    rmdir($root);
}
