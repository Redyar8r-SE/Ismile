<?php
declare(strict_types=1);
require __DIR__.'/_boot.php';
use Ismile\Admin\Page;
use Ismile\Auth;
use Ismile\Db;
use Ismile\GuestWorkshops;
use Ismile\Office;
use Ismile\Registrations;
use Ismile\SiteData;
use Ismile\UserError;
use Ismile\Validate;
use Ismile\Workshops;
use Ismile\WorkshopCatalog;

$user=Page::guard('workshops');
$canBook=Auth::canBookWorkshops($user);
$canGuest=$canBook && Auth::canIssueComplimentary($user) && \Ismile\GuestOrders::enabled();
$workshops=SiteData::workshops();
$open=Page::query('id');$q=Page::query('q');$personId=(int)Page::query('rid');
$group=in_array(Page::query('group'),['ismile','company'],true)?Page::query('group'):'';
$workshopQuery=Page::query('search');
$mode=Page::query('mode')==='paid'||!$canGuest?'paid':'guest';
$view=in_array(Page::query('view'),['overview','activity'],true)?Page::query('view'):'attendees';
$selected=null;foreach($workshops as $w)if($w['id']===$open&&(!$group||$w['organizer']===$group))$selected=$w;
if(!$selected){foreach($workshops as $w)if(!$group||$w['organizer']===$group){$selected=$w;$open=$w['id'];break;}}
$url=static fn(array $values=[],string $hash=''):string=>'workshops.php?'.http_build_query(array_filter(array_replace(['id'=>$open,'group'=>$group,'search'=>$workshopQuery],$values),static fn($v)=>$v!==''&&$v!==null)).$hash;
$back=$url();
$actionBack=($_SERVER['REQUEST_METHOD']??'')==='POST' && in_array($_POST['do']??'',['guest_workshop','guest_workshop_retry','workshop_add'],true)
    ?$url(['booking'=>'1','rid'=>$personId,'q'=>$q,'mode'=>$mode],'#book'):$back;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&str_starts_with((string)($_POST['do']??''),'ws_')){
    $settingsSection=in_array($_POST['settings_section']??'',['details','registration','visibility'],true)?$_POST['settings_section']:'details';
    $actionBack=$url(['settings'=>($_POST['do']??'')==='ws_create'?'__new':(string)($_POST['ws']??''),'section'=>$settingsSection],'#manage');
}
Page::action(static function()use($user,$back):string{
    $do=(string)($_POST['do']??'');
    if(in_array($do,['workshop_add','guest_workshop','guest_workshop_retry'],true)){
        $registration=Registrations::find((int)($_POST['rid']??0));
        if(!$registration)throw new UserError('Person not found.');
        if($do==='workshop_add')$message=Office::addWorkshop($registration,$user,$_POST);
        else{
            if($do==='guest_workshop')GuestWorkshops::start($user,$registration,$_POST);
            else GuestWorkshops::resume($user,$registration,(int)($_POST['request_id']??0));
            $message='Guest workshop booked. Complimentary ticket issued. Total: 0 IQD.';
        }
        Page::flash('ok',$message);Page::redirect($back.'#people');
    }
    if($do==='workshop_issue_ticket')return \Ismile\WorkshopTickets::issue((int)($_POST['booking']??0),$user);
    if($do==='workshop_change')return Office::changeWorkshop((int)($_POST['booking']??0),$user,$_POST);
    $id=(string)($_POST['ws']??'');
    return match($do){
        'ws_create'=>(static function()use($user):string{$id=Workshops::create($_POST,$user,$_FILES['photo']??null);Page::flash('ok','Workshop added: '.Workshops::name(Workshops::find($id)).'.');Page::redirect('workshops.php?'.http_build_query(['id'=>$id,'settings'=>$id]).'#manage');})(),
        'ws_update'=>(static function()use($id,$user):string{Workshops::update($id,$_POST,$user,$_FILES['photo']??null);return 'Workshop saved. The website shows the change.';})(),
        'ws_seats'=>'Seats now: '.Workshops::changeSeats($id,(int)($_POST['delta']??0),$user).'.',
        'ws_hide'=>(static function()use($id,$user):string{Workshops::setStatus($id,'hidden',$user);return 'Workshop hidden. Existing bookings are kept.';})(),
        'ws_show'=>(static function()use($id,$user):string{Workshops::setStatus($id,'active',$user);return 'Workshop shown on the website again.';})(),
        'ws_delete'=>(static function()use($id,$user):string{Workshops::delete($id,$user);Page::flash('ok','Workshop deleted.');Page::redirect('workshops.php#manage');})(),
        default=>throw new UserError('Unknown action.'),
    };
},$actionBack);
$e=[Page::class,'e'];
$workshopCounts=array_column(Db::all("SELECT workshop_id,COUNT(*) AS booked,COALESCE(SUM(payment_status='paid'),0) AS paid,COALESCE(SUM(payment_status='complimentary'),0) AS free,COALESCE(SUM(payment_status='unpaid'),0) AS unpaid,COALESCE(SUM(CASE WHEN payment_status='paid' THEN amount_paid ELSE 0 END),0) AS money FROM workshop_bookings WHERE removed_at IS NULL GROUP BY workshop_id"),null,'workshop_id');
$emptyCounts=['booked'=>0,'paid'=>0,'free'=>0,'unpaid'=>0,'money'=>0];
$liveCatalog=array_column(WorkshopCatalog::all(),null,'id');
$bookingTotal=$freeTotal=$unpaidTotal=$pendingTotal=0;$collected=[];
foreach($workshops as $w){$counts=$workshopCounts[$w['id']]??$emptyCounts;$bookingTotal+=(int)$counts['paid']+(int)$counts['free'];$freeTotal+=(int)$counts['free'];$unpaidTotal+=(int)$counts['unpaid'];$pendingTotal+=(int)($liveCatalog[$w['id']]['pendingPayments']??0);$collected[$w['currency']]=($collected[$w['currency']]??0)+(int)$counts['money'];}
$displayWorkshops=array_values(array_filter($workshops,static function($w)use($group,$workshopQuery):bool{return (!$group||$w['organizer']===$group)&&($workshopQuery===''||mb_stripos(Workshops::name($w).' '.($w['speaker']['en']??''),$workshopQuery)!==false);}));
$people=[];$activity=[];$attendeeQuery=Page::query('attendee');$ticketFilter=in_array(Page::query('ticket'),['paid','complimentary','unpaid'],true)?Page::query('ticket'):'';
if($selected){
    $selected=array_replace($selected,$liveCatalog[$open]??[]);
    $people=Db::all("SELECT wb.*,r.id AS rid,r.ref,r.first_name,r.father_name,r.grandfather_name,r.phone,r.email,r.status AS reg_status,c.provider_status AS online_status,g.id AS guest_request_id FROM workshop_bookings wb JOIN registrations r ON r.id=wb.registration_id LEFT JOIN workshop_checkouts c ON c.booking_id=wb.id LEFT JOIN guest_workshop_orders g ON g.booking_id=wb.id AND g.state='issued' WHERE wb.workshop_id=? AND wb.removed_at IS NULL ORDER BY wb.payment_status='unpaid' DESC,r.first_name,wb.id",[$open]);
    $activity=Db::all("SELECT a.action,a.created_at,u.name FROM audit_log a LEFT JOIN admin_users u ON u.id=a.user_id WHERE a.action LIKE 'workshop.%' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(a.details) THEN a.details ELSE '{}' END,'$.workshop')),JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(a.details) THEN a.details ELSE '{}' END,'$.id')))=? ORDER BY a.id DESC LIMIT 20",[$open]);
}
$filteredPeople=array_values(array_filter($people,static fn($p)=>(!$ticketFilter||$p['payment_status']===$ticketFilter)&&($attendeeQuery===''||mb_stripos(Registrations::fullName($p).' '.$p['ref'].' '.$p['phone'],$attendeeQuery)!==false)));
$page=max(1,(int)Page::query('page'));$pages=max(1,(int)ceil(count($filteredPeople)/10));$page=min($page,$pages);$shownPeople=array_slice($filteredPeople,($page-1)*10,10);
$found=[];$person=$canBook&&$personId?Registrations::find($personId):null;
if($person && !in_array($person['status'],['paid','complimentary'],true))$person=null;
if($canBook&&$q!==''){$phone=Validate::phone($q);$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';$found=Db::all("SELECT r.* FROM registrations r WHERE r.status IN ('paid','complimentary') AND (CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ? OR r.ref=?".($phone?' OR r.phone=?':'').') ORDER BY r.first_name LIMIT 15',$phone?[$like,strtoupper($q),$phone]:[$like,strtoupper($q)]);}
$needsReview=Db::all("SELECT c.registration_id,c.fulfillment_error,r.first_name,r.father_name,r.grandfather_name FROM workshop_checkouts c JOIN registrations r ON r.id=c.registration_id WHERE c.provider_status='paid' AND c.fulfillment_error IS NOT NULL ORDER BY c.id DESC");
$bookingErrors=array_column(array_filter($_SESSION['flash']??[],static fn($message)=>$message[0]!=='ok'),1);
$settingsMessages=$_SESSION['flash']??[];
Page::top('Workshops','workshops',($user['role']==='owner'?'<a class="btn ghost" href="#manage" data-ws-open="ws-settings">'.Page::navIcon('settings').'Workshop settings</a>':'').($canBook&&$selected?'<a class="btn ws-primary" href="'.$e($url(['booking'=>'1'],'#book')).'" data-ws-open="ws-book">'.Page::navIcon('plus').'Book a guest</a>':''));
require __DIR__.'/_workshop_workspace.php';
Page::bottom();
