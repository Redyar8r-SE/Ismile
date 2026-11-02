<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../bootstrap.php';
if(!in_array('--apply',$argv,true)){
    echo json_encode(['planned_workshops'=>array_column(\Ismile\ApprovedWorkshops::definitions(),'title_en'),'current'=>\Ismile\Db::all('SELECT id,title_en,status FROM workshops')],JSON_PRETTY_PRINT)."\n";exit;
}
$owner=\Ismile\Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if(!$owner)throw new RuntimeException('An enabled Owner is required.');
if(!\Ismile\Db::value("SELECT v FROM settings WHERE k='real_workshops_2026_v1'"))\Ismile\Backup::run();
echo 'WORKSHOPS_PUBLISHED='.json_encode(\Ismile\ApprovedWorkshops::publish($owner),JSON_UNESCAPED_SLASHES)."\n";
