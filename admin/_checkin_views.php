<?php
declare(strict_types=1);

use Ismile\Admin\CheckinWorkspace;
use Ismile\Admin\Page;
use Ismile\Admin\RegistrationReport;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Registrations;

// Included by the authenticated Registration workspace only.
if (!isset($user,$tab)) { http_response_code(403); exit; }
$e=[Page::class,'e'];
Page::top('Registration · '.CheckinWorkspace::TABS[$tab][0],'checkin');
echo CheckinWorkspace::navigation($tab,$user,$day);
if ($tab==='report') {
    echo '<div class="live-report-toolbar"><div><span class="live-report-badge"><i></i> Live report</span><span class="muted small">Updates every 15 seconds</span></div><div><span data-live-report-status role="status" aria-live="polite">Updated '.date('H:i:s').' · Iraq time</span><button type="button" class="btn small ghost" data-live-report-refresh>Refresh now</button></div></div>';
    echo '<div data-live-report data-source="checkin-report.php'.($day?'?day='.$day:'').'">'.RegistrationReport::render(RegistrationReport::snapshot(),$user,$day).'</div>';
    Page::bottom(); exit;
}
$summary=CheckinWorkspace::summary();
$isAttended=$tab==='attended';
$filters=CheckinWorkspace::filters($_GET,$isAttended);
[$where,$params]=CheckinWorkspace::where($filters['q'],$filters['attendance'],$filters);
$total=(int)Db::value('SELECT COUNT(*) FROM '.CheckinWorkspace::from()." WHERE $where",$params);
$pages=max(1,(int)ceil($total/50));
$page=max(1,min($pages,(int)($_GET['page']??1)));
$rows=CheckinWorkspace::rows($filters,50,($page-1)*50);
$options=CheckinWorkspace::options();
$choices=$isAttended?['attended'=>'Either day','day1'=>'Day 1','day2'=>'Day 2','both'=>'Both days']:[''=>'All guests','attended'=>'Attended','none'=>'Not attended','day1'=>'Day 1','day2'=>'Day 2','both'=>'Both days'];
$select=static function(string $key,string $label,array $choices) use($filters): string {
    $html='<label class="filter-field"><span>'.Page::e($label).'</span><select name="'.Page::e($key).'">';
    foreach($choices as $value=>$text) $html.='<option value="'.Page::e($value).'"'.($filters[$key]===(string)$value?' selected':'').'>'.Page::e($text).'</option>';
    return $html.'</select></label>';
};
$distinct=static function(string $key,string $label) use($filters,$options,$select): string {
    $values=$options[$key]; if($filters[$key]!=='' && !in_array($filters[$key],$values,true)) $values[]=$filters[$key];
    return $select($key,$label,[''=>'All '.strtolower($label)]+array_combine($values,$values));
};
$advanced=false;
foreach(['id','specialty','status','city','university','lang','ambassador','arrival_date','from_time','to_time','staff'] as $key) if($filters[$key]!=='') $advanced=true;
$canContact=Auth::can($user,'registrations');
?>
<?= Page::liveUpdates() ?>
<div data-live-region="guest-totals">
<?= Page::stats([
    ['Registered guests',number_format((int)$summary['guests']),'All active paid and complimentary tickets','users','blue'],
    ['Attended',number_format((int)$summary['attended']),'Unique guests who attended either day','check','teal'],
    ['Awaiting arrival',number_format((int)$summary['waiting']),'Guests who have not checked in','clock','gold'],
    ['Both days',number_format((int)$summary['both_days']),'Guests who attended Day 1 and Day 2','calendar','violet'],
]) ?>
</div>
<form method="get" class="card registration-filter-card" data-instant-search data-search-regions="guest-totals guest-directory"><input type="hidden" name="tab" value="<?= $e($tab) ?>"><?php if($day): ?><input type="hidden" name="day" value="<?= $day ?>"><?php endif; ?>
  <div class="registration-filter-main"><label class="filter-field filter-search"><span>Name, reference or ticket number</span><input type="search" name="q" value="<?= $e($filters['q']) ?>" placeholder="Find a guest…"></label>
    <?= $select('attendance','Event attendance',$choices) ?>
    <?= $select('type','Ticket category',[''=>'All categories','professional'=>'Professional','student'=>'Student','vip'=>'VIP']) ?>
    <?= $select('lunch','Lunch booked',[''=>'Any lunch booking','day1'=>'Day 1','day2'=>'Day 2','both'=>'Both days','any'=>'At least one day','none'=>'No lunch booked']) ?>
  </div>
  <details class="registration-advanced-filters"<?= $advanced?' open':'' ?>><summary>More filters · ID, guest details<?= $isAttended?' and arrival times':'' ?></summary><div class="registration-filter-grid">
    <?= $select('id','Student ID',[''=>'Any ID status','present'=>'ID on file','missing'=>'No ID on file','removed'=>'ID removed']) ?>
    <?= $select('specialty','Specialty',[''=>'All specialties']+Registrations::SPECIALTY_NAMES) ?>
    <?= $select('status','Registration status',[''=>'Paid and complimentary','paid'=>'Paid','complimentary'=>'Complimentary']) ?>
    <?= $distinct('city','Cities') ?><?= $distinct('university','Universities') ?><?= $distinct('ambassador','Ambassador codes') ?>
    <?= $select('lang','Language',[''=>'All languages','en'=>'English','ar'=>'Arabic','ku'=>'Kurdish']) ?>
    <label class="filter-field"><span>Arrival date (Iraq time)</span><input type="date" name="arrival_date" value="<?= $e($filters['arrival_date']) ?>"></label>
    <label class="filter-field"><span>Arrived from</span><input type="time" name="from_time" value="<?= $e($filters['from_time']) ?>"></label>
    <label class="filter-field"><span>Arrived until</span><input type="time" name="to_time" value="<?= $e($filters['to_time']) ?>"></label>
    <?= $select('staff','Checked in by',[''=>'Any staff member']+array_column($options['staff'],'name','id')) ?>
  </div><p class="small muted">Arrival filters match one recorded check-in<?= $isAttended?' on the selected event day(s)':'' ?>. All times use Iraq time.</p></details>
  <div class="registration-filter-actions"><?= $select('sort','Sort by',['name'=>'Guest name','latest'=>'Latest arrival first','earliest'=>'Earliest arrival first']) ?><div><a class="btn ghost" href="<?= $e(CheckinWorkspace::url($tab,$day)) ?>">Clear filters</a><button class="btn">Apply filters</button></div></div>
</form>
<section class="card registration-directory" data-live-region="guest-directory"><div class="panel-top"><?= Page::panelHeading($isAttended?'Attended guests':'Guest list',number_format($total).' matching guests · '.($isAttended?'Dates, exact arrival times and the staff who checked them in.':'Ticket categories, student IDs, lunch bookings and event attendance.'),'users') ?><div class="panel-actions">
<?php if(Auth::can($user,'export')): ?><a class="btn ghost" href="export.php?<?= $e(CheckinWorkspace::queryString($filters,['what'=>'registrations','workspace'=>'checkin','format'=>'pdf'])) ?>">Download PDF</a><a class="btn ghost" href="export.php?<?= $e(CheckinWorkspace::queryString($filters,['what'=>'registrations','workspace'=>'checkin'])) ?>">Excel</a><?php endif; ?>
</div></div>
<div class="table-wrap"><table class="registration-guest-table <?= $isAttended?'attended-table':'directory-table' ?>"><thead><tr><th>Guest</th><th>Ticket &amp; status</th><?php if($isAttended): ?><th>Day 1 arrival</th><th>Day 2 arrival</th><th>Lunch booked</th><?php else: ?><th>Student ID</th><th>Day 1 lunch</th><th>Day 2 lunch</th><th>Event attendance</th><?php endif; ?><th>Guest details</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr>
  <td data-label="Guest"><div class="guest-cell"><span class="guest-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($row['first_name'],0,1))) ?></span><div><?php if($canContact): ?><a href="registration.php?id=<?= (int)$row['id'] ?>&amp;workspace=checkin<?= $day?'&amp;day='.$day:'' ?>"><b><?= $e(Registrations::fullName($row)) ?></b></a><?php else: ?><b><?= $e(Registrations::fullName($row)) ?></b><?php endif; ?><small><?= $e($row['ref']) ?></small></div></div></td>
  <td data-label="Ticket &amp; status"><b><?= $e(($row['ticket_type']==='vip' ? 'VIP' : ucfirst($row['ticket_type']))) ?></b><small><code><?= $e($row['ticket_no']) ?></code></small><?= Page::pill($row['status']) ?><?php if($row['possible_duplicate']): ?><small class="duplicate-note">Possible duplicate record</small><?php endif; ?></td>
  <?php if($isAttended): foreach([1,2] as $arrivalDay): ?><td data-label="Day <?= $arrivalDay ?> arrival"><div class="arrival-detail"><?php if($row['day'.$arrivalDay]): $time=strtotime($row['day'.$arrivalDay]); ?><?= Page::pill('arrived') ?><b><?= date('H:i:s',$time) ?></b><span><?= date('d M Y',$time) ?></span><small>By <?= $e($row['staff'.$arrivalDay]??'Staff unavailable') ?></small><small><?= $e(\Ismile\Attendance::methodLabel($row['method'.$arrivalDay])) ?> · Iraq time</small><?php else: ?><span class="muted">Not attended</span><?php endif; ?></div></td><?php endforeach; ?><td data-label="Lunch booked"><span>Day 1: <?= $row['lunch_day1']?'Booked':'Not booked' ?></span><small>Day 2: <?= $row['lunch_day2']?'Booked':'Not booked' ?></small></td>
  <?php else: ?><td data-label="Student ID"><span class="student-id-status"><?= $e(CheckinWorkspace::idStatus($row)) ?></span><?php if($row['ticket_type']==='student' && $row['university']): ?><small><?= $e($row['university']) ?></small><?php endif; ?><?php if($row['ticket_type']==='student' && $row['id_photo_id']!==null && Auth::can($user,'photos')): ?><a class="text-action" target="_blank" rel="noopener" href="photo.php?id=<?= (int)$row['id'] ?>">View student ID</a><?php endif; ?></td>
    <?php foreach([1,2] as $lunchDay): ?><td data-label="Day <?= $lunchDay ?> lunch"><b><?= $row['lunch_day'.$lunchDay]?'Booked':'Not booked' ?></b><?php if($row['lunch_day'.$lunchDay]): ?><small><?= $row['day'.$lunchDay]?'Guest arrived':'Awaiting guest' ?></small><?php endif; ?></td><?php endforeach; ?>
    <td data-label="Event attendance"><div class="guest-attendance-days"><?php foreach([1,2] as $arrivalDay): ?><div><b>Day <?= $arrivalDay ?></b><span><?= $row['day'.$arrivalDay]?'Attended':'Not attended' ?></span><?php if($row['day'.$arrivalDay]): ?><small><?= date('d M · H:i:s',strtotime($row['day'.$arrivalDay])) ?></small><?php endif; ?></div><?php endforeach; ?></div></td>
  <?php endif; ?>
  <td data-label="Guest details"><details class="registration-guest-details" data-live-key="guest-<?= (int)$row['id'] ?>"><summary>View details</summary><dl>
    <dt>Gender</dt><dd><?= $e(ucfirst($row['gender'])) ?></dd><dt>Age</dt><dd><?= $row['age']!==null?(int)$row['age']:'Not provided' ?></dd><dt>Payment method</dt><dd><?= $e($row['pay_method']?ucfirst($row['pay_method']):'Complimentary') ?></dd>
    <dt>Specialty</dt><dd><?= $e(Registrations::SPECIALTY_NAMES[$row['specialty']]??$row['specialty']) ?></dd><dt>City</dt><dd><?= $e($row['city']?:'Not provided') ?></dd><dt>University</dt><dd><?= $e($row['university']?:'Not applicable') ?></dd><dt>Student ID</dt><dd><?= $e(CheckinWorkspace::idStatus($row)) ?></dd><dt>Language</dt><dd><?= $e(['en'=>'English','ar'=>'Arabic','ku'=>'Kurdish'][$row['lang']]??$row['lang']) ?></dd><dt>Ambassador</dt><dd><?= $e($row['ambassador_code']?:'None') ?></dd><dt>Registered</dt><dd><?= $e(date('d M Y · H:i:s',strtotime($row['created_at']))) ?></dd><dt><?= $row['status']==='paid'?'Paid':'Ticket issued' ?></dt><dd><?= $e(date('d M Y · H:i:s',strtotime($row['paid_at']))) ?></dd>
    <?php if($canContact): ?><dt>Phone</dt><dd><?= $e($row['phone']) ?></dd><dt>Email</dt><dd><?= $e($row['email']) ?></dd><?php if($row['notes']): ?><dt>Office notes</dt><dd><?= $e($row['notes']) ?></dd><?php endif; ?><?php endif; ?>
    <?php foreach([1,2] as $arrivalDay): ?><dt>Day <?= $arrivalDay ?> check-in method</dt><dd><?= $e(\Ismile\Attendance::methodLabel($row['method'.$arrivalDay])?:'Not attended') ?></dd><dt>Day <?= $arrivalDay ?> arrival</dt><dd><?= $row['day'.$arrivalDay]?$e($row['day'.$arrivalDay]).' · '.$e($row['staff'.$arrivalDay]??'Staff unavailable'):'Not attended' ?></dd><?php endforeach; ?>
  </dl><?php if($row['ticket_type']==='student' && $row['id_photo_id']!==null && Auth::can($user,'photos')): ?><a class="text-action" target="_blank" rel="noopener" href="photo.php?id=<?= (int)$row['id'] ?>">View student ID</a><?php endif; ?></details></td>
</tr><?php endforeach; ?>
<?php if(!$rows): ?><tr class="empty-row"><td colspan="<?= $isAttended?6:7 ?>"><?= Page::emptyState($isAttended?'No attendees match these filters':'No guests match these filters','Clear a filter or search for another guest. Confirmed QR arrivals appear automatically.','users') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if($pages>1): ?><div class="toolbar checkin-pagination"><?php if($page>1): ?><a class="btn ghost" href="<?= $e(CheckinWorkspace::url($tab,$day,array_merge($filters,['page'=>$page-1]))) ?>">Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if($page<$pages): ?><a class="btn ghost" href="<?= $e(CheckinWorkspace::url($tab,$day,array_merge($filters,['page'=>$page+1]))) ?>">Next</a><?php endif; ?></div><?php endif; ?>
</section>
<?php Page::bottom();
