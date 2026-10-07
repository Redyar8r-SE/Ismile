<?php
// ISMILE_CONFIG=/isolated/config.php php backend/tests/attendance-certificates.php
// Requires an isolated ismile_attendance_test_* database with the current schema.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Attendance;
use Ismile\Auth;
use Ismile\Certificates;
use Ismile\Db;
use Ismile\EmailTemplates;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\Security;
use Ismile\Settings;
use Ismile\Tickets;
use Ismile\UserError;
use Ismile\Admin\PdfReport;
use Ismile\Admin\CheckinWorkspace;
use Ismile\Admin\RegistrationReport;
use Ismile\Admin\RegistrationQuery;
use Ismile\Admin\GuestLookup;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_attendance_test_')) {
    throw new RuntimeException('Use an isolated ismile_attendance_test_* database, never a real event database.');
}
if (($argv[1] ?? '') === '--worker') {
    $workerUser=['id'=>(int)$argv[3],'role'=>'checkin'];
    $scan=Tickets::readScan($argv[2]);
    $result = ($argv[4]??'')==='manual' ? Attendance::checkInManually((int)$scan['ticket']['id'],(int)$scan['ticket']['version'],1,$workerUser,true) : Attendance::checkIn($argv[2],1,$workerUser);
    echo $result['duplicate'] ? 'duplicate' : 'admitted';
    exit;
}
if (($argv[1] ?? '') === '--render') {
    $page = $argv[2] ?? '';
    if (!in_array($page, ['certificates','checkin','checkin-report','registrations','lists','export','certificate-download'], true)) throw new RuntimeException('Unknown test page.');
    $user = Db::one("SELECT * FROM admin_users WHERE email LIKE 'attendance-%@test.invalid' ORDER BY id DESC LIMIT 1");
    $user['totp_enabled']=1;
    if (isset($argv[5]) && in_array($argv[5], Auth::ROLES, true)) $user['role']=$argv[5];
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $user);
    parse_str($argv[3] ?? '', $_GET);
    $_SERVER['REQUEST_METHOD']='GET';
    $_SERVER['SCRIPT_NAME']='/admin/' . $page . '.php';
    ob_start();
    $target = $argv[4];
    register_shutdown_function(static function () use ($target): void {
        $body = (string) ob_get_clean();
        if (!str_starts_with($body,'%PDF-')) $body=str_replace('<head>','<head><base href="file:///' . App::config('site_root') . '/admin/">', $body);
        file_put_contents($target, $body);
    });
    $pageFile=App::siteFile('admin/' . $page . '.php');
    if(!is_file($pageFile)) $pageFile=dirname(__DIR__,2) . '/admin/' . $page . '.php';
    require $pageFile;
    exit;
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++; echo "PASS: $name\n";
};
$rejects = static function (callable $fn): bool {
    try { $fn(); return false; } catch (UserError $e) { return true; }
};
$id = Auth::createUser('attendance-' . bin2hex(random_bytes(4)) . '@test.invalid', 'Attendance test', 'owner', bin2hex(random_bytes(14)));
$owner = ['id' => $id, 'role' => 'owner'];
$staff = ['id' => $id, 'role' => 'checkin'];
$make = static function (string $first) use ($owner): array {
    return Office::createComplimentary($owner, ['first_name' => $first, 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan',
        'email' => 'test-' . bin2hex(random_bytes(4)) . '@test.invalid', 'phone' => '07501112233', 'city' => 'Sulaimani',
        'gender' => 'female', 'specialty' => 'gp', 'ticket' => 'professional', 'comp_reason' => 'Attendance fixture']);
};
$r = $make('Sara');
$absent = $make('Nadia');
Db::run("UPDATE registrations SET status='paid' WHERE id=?", [$absent['id']]);
$check('a paid guest who never attended gets no certificate', Certificates::rows((int)$absent['id']) === []);
$ticket = Tickets::forRegistration((int)$r['id']);
$qr = Tickets::qrPayload($ticket);
$check('payment or free ticket alone has no certificate', Certificates::rows((int)$r['id']) === []);
$check('certificate issuance without attendance is rejected', $rejects(static fn() => Certificates::issue($r, $ticket)));
$check('forged QR is rejected', $rejects(static fn() => Attendance::checkIn(substr($qr, 0, -1) . (str_ends_with($qr, 'A') ? 'B' : 'A'), 1, $staff)));
$check('plain ticket number cannot bypass QR', $rejects(static fn() => Attendance::checkIn($ticket['ticket_no'], 1, $staff)));
$check('invalid third day is rejected', $rejects(static fn() => Attendance::checkIn($qr, 3, $staff)));
$check('unauthorized role is rejected', $rejects(static fn() => Attendance::checkIn($qr, 1, ['id'=>$id, 'role'=>'finance'])));
$first = Attendance::checkIn($qr, 1, $staff);
$check('first Day 1 scan admits the guest', !$first['duplicate']);
$check('certificate is created with the complete name', Certificates::rows((int)$r['id'])[0]['recipient_name'] === 'Sara Ahmed Hassan');
$check('second Day 1 scan is blocked', Attendance::checkIn($qr, 1, $staff)['duplicate']);
$check('same QR remains valid for Day 2', !Attendance::checkIn($qr, 2, $staff)['duplicate']);
$check('second Day 2 scan is blocked', Attendance::checkIn($qr, 2, $staff)['duplicate']);
$check('maximum two admissions', (int)Db::value('SELECT COUNT(*) FROM ticket_attendance WHERE ticket_id=?', [$ticket['id']]) === 2);
$check('two days create only one certificate', (int)Db::value('SELECT COUNT(*) FROM certificates WHERE registration_id=?', [$r['id']]) === 1);
$check('both event days are recorded on certificate', Certificates::rows((int)$r['id'])[0]['attended_days'] === 'Day 1 & Day 2');
$guestCount = static function (string $query, string $scope): int {
    [$where, $params] = CheckinWorkspace::where($query, $scope);
    return (int) Db::value('SELECT COUNT(*) FROM ' . CheckinWorkspace::from() . " WHERE $where", $params);
};
$check('Attended counts a two-day guest once', $guestCount($r['ref'], 'attended') === 1 && $guestCount($r['ref'], 'both') === 1);
$check('Attended excludes paid guests without attendance', $guestCount($absent['ref'], 'attended') === 0 && $guestCount($absent['ref'], 'none') === 1);
$check('guest filters search partial references and ticket numbers', $guestCount(substr($r['ref'], 2), 'day1') === 1 && $guestCount(substr($ticket['ticket_no'], 2), 'day2') === 1);
$check('guest search treats SQL and wildcard characters as literal text', $guestCount("' OR 1=1 --", '') === 0 && $guestCount('%', '') === 0);
$check('one letter matches first-name prefixes without matching other fields', $guestCount('S', '') === 1 && $guestCount('N', '') === 1 && $guestCount('X', '') === 0);
$check('partial local phone finds guests before the full number is typed', $guestCount('0750111', '') === 2);
$check('partial Iraqi phone formats normalize consistently', GuestLookup::phonePrefix('0750') === '+964750' && GuestLookup::phonePrefix('00964 750') === '+964750' && GuestLookup::phonePrefix('+964 (750)') === '+964750' && GuestLookup::phonePrefix('750') === '+964750');
[$lookupWhere,$lookupParams]=GuestLookup::where('Sa');
$check('welcome desk uses name prefixes', (int)Db::value('SELECT COUNT(*) FROM registrations r LEFT JOIN tickets t ON t.registration_id=r.id WHERE '.$lookupWhere,$lookupParams) === 1);
[$lookupWhere,$lookupParams]=GuestLookup::where('%');
$check('welcome desk treats wildcard input literally', !Db::value('SELECT COUNT(*) FROM registrations r LEFT JOIN tickets t ON t.registration_id=r.id WHERE '.$lookupWhere,$lookupParams));
[$directoryWhere,$directoryParams]=RegistrationQuery::where(['q'=>'S']);
$check('registration single-letter search matches first names', (int)Db::value('SELECT COUNT(*) FROM '.RegistrationQuery::from().' WHERE '.$directoryWhere,$directoryParams) === 1);
$summary = CheckinWorkspace::summary();
$check('report totals reconcile unique attendees and waiting guests', (int)$summary['guests'] === (int)$summary['attended'] + (int)$summary['waiting'] && (int)$summary['both_days'] <= (int)$summary['attended']);
try { Db::insert('ticket_attendance', ['ticket_id'=>$ticket['id'], 'event_day'=>1, 'checked_in_at'=>App::now(), 'checked_in_by'=>$id]); $unique=false; }
catch (PDOException $e) { $unique = (int)($e->errorInfo[1] ?? 0) === 1062; }
$check('database itself refuses a duplicate admission', $unique);
Office::editContact($r, $owner, ['first_name'=>'Sarah', 'father_name'=>'Ahmed', 'grandfather_name'=>'Hassan', 'email'=>$r['email'], 'phone'=>$r['phone']]);
$check('name correction updates certificate', Certificates::rows((int)$r['id'])[0]['recipient_name'] === 'Sarah Ahmed Hassan');
$check('old QR fails after name correction', $rejects(static fn() => Attendance::checkIn($qr, 2, $staff)));
$fresh = Tickets::forRegistration((int)$r['id']);
$check('reissuing QR does not reset used admission', Attendance::checkIn(Tickets::qrPayload($fresh), 1, $staff)['duplicate']);
Db::run('UPDATE tickets SET cancelled_at=? WHERE id=?', [App::now(), $ticket['id']]);
$check('guest and attendance lists omit cancelled tickets', $guestCount($r['ref'], '') === 0);
$check('cancelled ticket cannot be admitted', $rejects(static fn() => Attendance::checkIn(Tickets::qrPayload($fresh), 2, $staff)));
$check('cancelled ticket certificate cannot be downloaded', Certificates::rows((int)$r['id']) === []);
Db::run('UPDATE tickets SET cancelled_at=NULL WHERE id=?', [$ticket['id']]);
Db::run("UPDATE registrations SET status='cancelled' WHERE id=?", [$r['id']]);
$check('cancelled registration rejected even if ticket remains active', $rejects(static fn() => Attendance::checkIn(Tickets::qrPayload($fresh), 2, $staff)));
Db::run("UPDATE registrations SET status='complimentary' WHERE id=?", [$r['id']]);

$concurrent = $make('Dara');
$concurrentTicket = Tickets::forRegistration((int)$concurrent['id']);
$worker = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring', '-d', 'extension=pdo_mysql', '-d', 'extension=gd', '-d', 'extension=curl', __FILE__, '--worker', Tickets::qrPayload($concurrentTicket), (string)$id];
$processes = [];
for ($i=0; $i<2; $i++) { $pipes=[]; $process=proc_open($worker, [1=>['pipe','w'], 2=>['pipe','w']], $pipes); $processes[]=[$process,$pipes]; }
$outcomes=[];
foreach ($processes as [$process,$pipes]) {
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code=proc_close($process); if ($code !== 0) throw new RuntimeException('Concurrent worker failed: ' . $error); $outcomes[]=trim($output);
}
sort($outcomes);
$check('simultaneous scans at two doors admit exactly once', $outcomes === ['admitted','duplicate']);
$check('concurrent admission creates one certificate', count(Certificates::rows((int)$concurrent['id'])) === 1);

$failure = $make('Rojin');
$failureTicket = Tickets::forRegistration((int)$failure['id']);
App::db()->exec('CREATE TRIGGER attendance_certificate_failure BEFORE INSERT ON certificates FOR EACH ROW BEGIN IF NEW.registration_id=' . (int)$failure['id'] . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test certificate failure'; END IF; END");
try {
    try { Attendance::checkIn(Tickets::qrPayload($failureTicket), 1, $staff); $rolledBack=false; }
    catch (PDOException $e) { $rolledBack = Db::value('SELECT id FROM ticket_attendance WHERE ticket_id=?', [$failureTicket['id']])===null && Tickets::forRegistration((int)$failure['id'])['checked_in_at']===null; }
    $check('certificate failure rolls back admission so QR can be retried', $rolledBack);
} finally { App::db()->exec('DROP TRIGGER attendance_certificate_failure'); }

$legacy1=$make('Linda'); $legacy2=$make('Ranya'); $unknown=$make('Hala');
foreach ([[$legacy1,'2026-11-20 09:00:00'],[$legacy2,'2026-11-21 10:00:00'],[$unknown,'2026-11-19 08:00:00']] as [$person,$time]) {
    Db::run('UPDATE tickets SET checked_in_at=?, checked_in_by=? WHERE registration_id=?', [$time,$id,$person['id']]);
}
$sql=preg_replace('/^\s*--.*$/m','',(string)file_get_contents(__DIR__ . '/../database/migrations/2026-10-06-two-day-attendance.sql'));
foreach (array_filter(array_map('trim',preg_split('/;\s*$/m',$sql))) as $statement) App::db()->exec($statement);
$check('legacy Day 1 arrival is migrated to the correct day', Certificates::rows((int)$legacy1['id'])[0]['attended_days']==='Day 1');
$check('legacy Day 2 arrival is migrated to the correct day', Certificates::rows((int)$legacy2['id'])[0]['attended_days']==='Day 2');
$check('migration does not invent a day for unrelated historic arrivals', Certificates::rows((int)$unknown['id'])===[]);
$legacyTicket=Tickets::forRegistration((int)$legacy1['id']);
Db::run('UPDATE tickets SET ticket_no=? WHERE id=?', ['T26-' . (100000+(int)$legacyTicket['id']), $legacyTicket['id']]);
$legacyTicket=Tickets::forRegistration((int)$legacy1['id']);
$check('tickets above 99999 remain scannable', !isset(Tickets::readScan(Tickets::qrPayload($legacyTicket))['error']));

$saved = Settings::all();
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['ticket_qr_in_email'=>'0']+$saved);
$email = EmailTemplates::build(['kind'=>'ticket','data'=>null,'registration_id'=>$r['id']]);
$check('ticket email includes QR even with obsolete off setting', str_contains($email['html'], 'api/qr.php?') && count($email['attachments']) === 1);
$check('email explains one admission on each day', str_contains($email['text'], 'once on Day 1 and once on Day 2'));
$check('attached ticket is an actual PDF', str_starts_with($email['attachments'][0]['content'], '%PDF-'));
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, $saved);
$check('Day 1 calendar mapping', Attendance::currentDay('2026-11-20')===1);
$check('Day 2 calendar mapping', Attendance::currentDay('2026-11-21')===2);
$check('outside event has no admission day', Attendance::currentDay('2026-11-22')===null);
$config = ['site_url'=>'http://127.0.0.1','site_root'=>App::config('site_root'),'storage'=>App::config('storage'), 'db'=>App::config('db'), 'secret'=>App::config('secret')];
$overnight=$make('Shilan');
$overnightTicket=Tickets::forRegistration((int)$overnight['id']);
$overnightQr=Tickets::qrPayload($overnightTicket);
Attendance::checkIn($overnightQr, 1, $staff);
App::boot(['env'=>'live']+$config);
$check('Day 1 ignores a requested Day 2 admission', Attendance::admissionDay(2, '2026-11-20')===1);
$check('midnight switches an old Day 1 form to Day 2', Attendance::admissionDay(1, '2026-11-21')===2);
$check('a missing form day still uses the current live day', Attendance::admissionDay(0, '2026-11-21')===2);
$check('there is no third-day reset', $rejects(static fn()=>Attendance::admissionDay(1, '2026-11-22')));
$check('live admission cannot be performed outside its real date', $rejects(static fn() => Attendance::checkIn(Tickets::qrPayload($fresh), Attendance::currentDay()===1 ? 2 : 1, $staff)));
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null, ['event_day1'=>date('Y-m-d',strtotime('yesterday')),'event_day2'=>date('Y-m-d')]+$saved);
$overnightAdmission=Attendance::checkIn($overnightQr,1,$staff);
$check('same QR from Day 1 works on live Day 2 with a stale Day 1 form', !$overnightAdmission['duplicate'] && $overnightAdmission['day']===2);
$check('repeat scan after the midnight rollover is blocked for Day 2', Attendance::checkIn($overnightQr,1,$staff)['duplicate']);
$check('midnight rollover preserves both attendance records', (int)Db::value('SELECT COUNT(*) FROM ticket_attendance WHERE ticket_id=?',[$overnightTicket['id']])===2);
(new ReflectionProperty(Settings::class, 'cache'))->setValue(null,$saved);
App::boot(['env'=>'test']+$config);
$check('test rehearsals retain the selected day', Attendance::admissionDay(1, '2026-11-21')===1);
$check('check-in staff cannot download personal certificates', !Auth::can($staff,'certificates'));
$check('finance can download certificates but cannot view student IDs', Auth::can(['role'=>'finance'],'certificates') && !Auth::can(['role'=>'finance'],'photos'));
$preview = Certificates::rows((int)$r['id'])[0];
$preview['recipient_name']='Sara Ahmed Hassan';
$preview['certificate_no']='ISM26-C-SAMPLE';
file_put_contents(App::storage('tmp/certificate-preview.pdf'), Certificates::pdf([$preview]));
$unicode = $preview; $unicode['recipient_name']='سارا أحمد حسن';
$long = $preview; $long['recipient_name']=str_repeat('Long participant name ', 7);
$pdf = Certificates::pdf([$preview, $unicode, $long]);
file_put_contents(App::storage('tmp/certificates-test.pdf'), $pdf);
$check('bulk certificates render one page per guest', preg_match_all('/\/Type \/Page\b/', $pdf) === 3);
$headers = ['Reference','Name','Email','Phone','City','Ticket','Day 1','Day 2','Certificate','University','Notes'];
$report = PdfReport::make('iSmile guest directory', [$headers, ['ISM26-DEMO','Sara Ahmed Hassan','sample@example.invalid','+9647501112233','Sulaimani','Professional','09:00','09:05','ISM26-C-DEMO','','sample notes']]);
file_put_contents(App::storage('tmp/report-test.pdf'), $report);
$check('wide PDF report retains all columns in readable groups', preg_match_all('/\/Type \/Page\b/', $report) === 2);
$bundle=PdfReport::bundle(['Guests'=>[$headers], 'Attendance'=>[['Name','Day 1','Day 2'],['Sara','09:00','09:05']]]);
$check('combined event PDF includes separate sections', preg_match_all('/\/Type \/Page\b/', $bundle)===3);
$student = $make('Narin');
$image=imagecreatetruecolor(300,180); imagefill($image,0,0,imagecolorallocate($image,240,245,246));
imagestring($image,5,25,70,'SAMPLE STUDENT ID',imagecolorallocate($image,18,48,47));
ob_start(); imagejpeg($image); $jpeg=(string)ob_get_clean(); imagedestroy($image);
$photo=Db::insert('student_id_photos',['image'=>$jpeg,'mime'=>'image/jpeg','width'=>300,'height'=>180,'bytes'=>strlen($jpeg),'sha256'=>hash('sha256',$jpeg),'uploaded_at'=>App::now(),'uploaded_ip'=>'test']);
Db::run("UPDATE registrations SET ticket_type='student', specialty='student', university='Sample University', id_photo_id=? WHERE id=?", [$photo,$student['id']]);
$student=Registrations::find((int)$student['id']);
$idPdf=PdfReport::studentIds([$student]);
file_put_contents(App::storage('tmp/student-id-test.pdf'),$idPdf);
$check('student ID PDF includes the protected photo', str_contains($idPdf,'/Subtype /Image'));
Db::run("UPDATE registrations SET lunch_day1=1,lunch_day2=0,city='Filter Test City',ambassador_code='FILTER-TEST',lang='ku' WHERE id=?",[$student['id']]);
$studentTicket=Tickets::forRegistration((int)$student['id']);
Attendance::checkIn(Tickets::qrPayload($studentTicket),1,$staff);
Attendance::checkIn(Tickets::qrPayload($studentTicket),2,$staff);
Db::run("UPDATE ticket_attendance SET checked_in_at=CASE event_day WHEN 1 THEN '2026-11-20 09:30:42' ELSE '2026-11-21 14:15:09' END WHERE ticket_id=?",[$studentTicket['id']]);
$studentFilters=['q'=>$student['ref'],'type'=>'student','id'=>'present','lunch'=>'day1','city'=>'Filter Test City','university'=>'Sample University','lang'=>'ku','ambassador'=>'FILTER-TEST'];
$check('guest filters combine student ID, lunch, city, university, language and ambassador',count(CheckinWorkspace::rows($studentFilters))===1);
$check('lunch filters distinguish a one-day booking from both days',CheckinWorkspace::rows(['lunch'=>'both']+['q'=>$student['ref']])===[] && CheckinWorkspace::rows(['lunch'=>'day2','q'=>$student['ref']])===[]);
$check('professional filter excludes a student',CheckinWorkspace::rows(['q'=>$student['ref'],'type'=>'professional'])===[]);
$check('student ID missing and removed are distinct from retained images',CheckinWorkspace::rows(['q'=>$student['ref'],'id'=>'missing'])===[] && CheckinWorkspace::idStatus(Registrations::find((int)$student['id']))==='ID on file');
$check('Day 1 time filter cannot match the guest\'s Day 2 arrival',CheckinWorkspace::rows(['q'=>$student['ref'],'attendance'=>'day1','from_time'=>'14:00','to_time'=>'15:00'])===[]);
$check('arrival date and time must match the same admission',CheckinWorkspace::rows(['q'=>$student['ref'],'arrival_date'=>'2026-11-20','from_time'=>'14:00','to_time'=>'15:00'])===[]);
$filtered=CheckinWorkspace::rows(['q'=>$student['ref'],'attendance'=>'day1','arrival_date'=>'2026-11-20','from_time'=>'09:30','to_time'=>'09:30','staff'=>(string)$id]);
$check('arrival filter includes seconds throughout the chosen minute',count($filtered)===1 && $filtered[0]['day1']==='2026-11-20 09:30:42' && $filtered[0]['staff1']==='Attendance test');
$exports=CheckinWorkspace::exportRows($studentFilters);
$check('filtered export preserves guest selection and arrival staff details',count($exports)===2 && $exports[1][0]===$student['ref'] && $exports[1][12]==='Attendance test' && $exports[1][5]==='ID on file');
$check('attendee view cannot be switched to non-attendees by a filter',CheckinWorkspace::filters(['attendance'=>'none'],true)['attendance']==='attended');
$invalid=CheckinWorkspace::filters(['arrival_date'=>'2026-02-30','from_time'=>'25:00','staff'=>'1 OR 1=1','sort'=>'r.email DESC']);
$check('invalid arrival filters and SQL sort expressions are rejected',$invalid['arrival_date']==='' && $invalid['from_time']==='' && $invalid['staff']==='' && $invalid['sort']==='name');
$snapshot=RegistrationReport::snapshot();
$check('live graph totals match attendance on each day',array_sum($snapshot['hourly'][1])===(int)$snapshot['summary']['day1'] && array_sum($snapshot['hourly'][2])===(int)$snapshot['summary']['day2']);
$check('report category counts reconcile with unique attendance',(int)$snapshot['segments']['students_arrived']+(int)$snapshot['segments']['professionals_arrived']===(int)$snapshot['summary']['attended']);
$check('lunch attendance never exceeds booked lunch totals',(int)$snapshot['segments']['lunch_arrived1']<=(int)$snapshot['segments']['lunch1'] && (int)$snapshot['segments']['lunch_arrived2']<=(int)$snapshot['segments']['lunch2']);
Db::run('UPDATE tickets SET cancelled_at=? WHERE id=?',[App::now(),$studentTicket['id']]);
$cancelledSnapshot=RegistrationReport::snapshot();
$check('live reports and hourly charts exclude cancelled attendees',(int)$cancelledSnapshot['summary']['attended']===(int)$snapshot['summary']['attended']-1 && array_sum($cancelledSnapshot['hourly'][1])===array_sum($snapshot['hourly'][1])-1);
Db::run('UPDATE tickets SET cancelled_at=NULL WHERE id=?',[$studentTicket['id']]);
foreach ([['day1','latest'],['day2','latest'],['day1','earliest']] as [$scope,$sort]) {
    $times=array_column(CheckinWorkspace::rows(['attendance'=>$scope,'sort'=>$sort]),$scope);
    $expected=$times; $sort==='latest'?rsort($expected):sort($expected);
    $check("$sort sorting uses the selected $scope arrival",$times===$expected);
}
$rgba = ''; $image=imagecreatefromstring(Tickets::qrPng($fresh));
for ($y=0;$y<imagesy($image);$y++) for ($x=0;$x<imagesx($image);$x++) { $c=imagecolorat($image,$x,$y); $rgba.=chr(($c>>16)&255).chr(($c>>8)&255).chr($c&255).chr(255); }
file_put_contents(App::storage('tmp/qr-test.json'), json_encode(['width'=>imagesx($image),'height'=>imagesy($image),'rgba'=>base64_encode($rgba),'payload'=>Tickets::qrPayload($fresh)]));
imagedestroy($image);
$manual=$make('Manualguest');
$manualTicket=Tickets::forRegistration((int)$manual['id']);
$manualAdmit=static fn(int $day,array $as,bool $verified=true)=>Attendance::checkInManually((int)$manualTicket['id'],(int)$manualTicket['version'],$day,$as,$verified);
$check('manual admission requires identity confirmation',$rejects(static fn()=>$manualAdmit(1,$staff,false)));
$check('manual admission requires check-in permission',$rejects(static fn()=>$manualAdmit(1,['id'=>$id,'role'=>'finance'])));
$check('manual admission rejects invalid day',$rejects(static fn()=>$manualAdmit(3,$staff)));
$manualFirst=$manualAdmit(1,$staff);
$check('verified lookup admits guest and records method and staff',!$manualFirst['duplicate'] && Db::value('SELECT checkin_method FROM ticket_attendance WHERE ticket_id=? AND event_day=1',[$manualTicket['id']])==='manual_lookup');
$check('manual attendance creates certificate',count(Certificates::rows((int)$manual['id']))===1);
$check('check-in never automatically queues certificate emails',!Db::value("SELECT id FROM emails WHERE kind='certificate' AND registration_id=?",[$manual['id']]));
$check('manual repeat and QR after manual are blocked',$manualAdmit(1,$staff)['duplicate'] && Attendance::checkIn(Tickets::qrPayload($manualTicket),1,$staff)['duplicate']);
$check('Day 2 QR works after Day 1 manual admission',!Attendance::checkIn(Tickets::qrPayload($manualTicket),2,$staff)['duplicate']);
$check('manual after Day 2 QR is blocked',$manualAdmit(2,$staff)['duplicate']);
$check('mixed methods retain one certificate and two arrivals',count(Certificates::rows((int)$manual['id']))===1 && (int)Db::value('SELECT COUNT(*) FROM ticket_attendance WHERE ticket_id=?',[$manualTicket['id']])===2);
$export=CheckinWorkspace::exportRows(['q'=>$manual['ref']]);
$check('reports and exports label manual admission',$export[1][21]===Attendance::methodLabel('manual_lookup') && $export[1][22]===Attendance::methodLabel('signed_qr'));
Db::run('UPDATE tickets SET version=version+1 WHERE id=?',[$manualTicket['id']]);
$check('stale manual lookup cannot admit a reissued ticket',$rejects(static fn()=>$manualAdmit(1,$staff)));
Db::run('UPDATE tickets SET version=version-1,cancelled_at=? WHERE id=?',[App::now(),$manualTicket['id']]);
$check('manual admission rejects cancelled tickets',$rejects(static fn()=>$manualAdmit(1,$staff)));
Db::run('UPDATE tickets SET cancelled_at=NULL WHERE id=?',[$manualTicket['id']]);
Db::run("UPDATE registrations SET status='cancelled' WHERE id=?",[$manual['id']]);
$check('manual admission rejects cancelled registrations',$rejects(static fn()=>$manualAdmit(1,$staff)));
Db::run("UPDATE registrations SET status='complimentary' WHERE id=?",[$manual['id']]);
$check('certificate email requires authorised staff',$rejects(static fn()=>Certificates::queueEmails((int)$manual['id'],$staff)));
$check('paid absent guest cannot be emailed a certificate',$rejects(static fn()=>Certificates::queueEmails((int)$absent['id'],$owner)));
$queued=Certificates::queueEmails((int)$manual['id'],$owner,true);
$check('explicit email action queues only that guest certificate',$queued['queued']===1);
$check('repeated button press does not queue a second pending certificate',Certificates::queueEmails((int)$manual['id'],$owner,true)['queued']===0);
$emailRow=Db::one("SELECT * FROM emails WHERE kind='certificate' AND registration_id=? ORDER BY id DESC LIMIT 1",[$manual['id']]);
$message=EmailTemplates::build($emailRow);
$check('certificate email contains own name and one personal PDF',$message['to']===$manual['email'] && str_contains($message['html'],Registrations::fullName($manual)) && count($message['attachments'])===1 && str_starts_with($message['attachments'][0]['content'],'%PDF-'));
Db::run('UPDATE tickets SET cancelled_at=? WHERE id=?',[App::now(),$manualTicket['id']]);
$check('certificate email rechecks eligibility at send time',EmailTemplates::build($emailRow)===null);
Db::run('UPDATE tickets SET cancelled_at=NULL WHERE id=?',[$manualTicket['id']]);
$all=Certificates::queueEmails(null,$owner);
$check('one bulk action queues other eligible attendees',$all['queued']>0 && !Db::value("SELECT id FROM emails WHERE kind='certificate' AND registration_id=?",[$absent['id']]));
$check('double bulk click skips already queued emails',Certificates::queueEmails(null,$owner)['queued']===0);
$mailer=new class implements \Ismile\Mail\Mailer {
    public array $sent=[];
    public function send(string $to,string $subject,string $html,string $text,array $attachments=[]):string { $this->sent[]=[$to,$subject,$attachments]; return 'test-certificate-'.count($this->sent); }
};
// Only certificate messages are due for this test; no real email provider is used.
Db::run("UPDATE emails SET next_attempt_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE kind<>'certificate'");
$previousTestMode=Settings::get('email_test_mode');
Settings::set('email_test_mode','0'); // Mock mailer only; no external delivery.
\Ismile\Outbox::process($mailer,10000);
Settings::set('email_test_mode',$previousTestMode);
$sent=Db::value('SELECT status FROM emails WHERE id=?',[$emailRow['id']]);
$check('explicitly queued certificate emails send through normal outbox',$sent==='sent' && count($mailer->sent)>0);
$check('bulk does not resend already delivered certificates',Certificates::queueEmails(null,$owner)['queued']===0);
$check('individual resend remains an explicit action',Certificates::queueEmails((int)$manual['id'],$owner,true)['queued']===1);

echo "$passed checks passed.\n";
