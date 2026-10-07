<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';

use Ismile\Admin\Page;
use Ismile\Admin\RegistrationReport;

$user=Page::guard('checkin');
$day=in_array((int)($_GET['day']??0),[1,2],true)?(int)$_GET['day']:null;
// Release the session lock before querying, so a live report never delays a scan.
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$report=RegistrationReport::snapshot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
echo json_encode(['html'=>RegistrationReport::render($report,$user,$day),'updated_at'=>$report['updated_at']],JSON_THROW_ON_ERROR);
