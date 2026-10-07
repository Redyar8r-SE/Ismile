<?php
// Shared by the full Registration report and its authenticated live updates.
declare(strict_types=1);

use Ismile\Admin\CheckinWorkspace;
use Ismile\Admin\Page;
use Ismile\Admin\RegistrationReport;
use Ismile\Auth;
use Ismile\Registrations;

if (!isset($report,$user)) { http_response_code(403); exit; }
$e=[Page::class,'e'];
$summary=$report['summary']; $segments=$report['segments'];
?>
<?= Page::stats([
    ['Registered guests',number_format((int)$summary['guests']),'Active paid and complimentary tickets','users','blue'],
    ['Attended',number_format((int)$summary['attended']),'Unique guests, across both days','check','teal'],
    ['Awaiting arrival',number_format((int)$summary['waiting']),'Have not attended either day','clock','gold'],
    ['Attendance rate',$report['rate'].'%','Of all registered guests','index','violet'],
]) ?>
<div class="live-report-grid">
  <section class="card report-chart-card">
    <div class="panel-top"><?= Page::panelHeading('Arrivals over the day','Compare the check-in pace on Day 1 and Day 2. Times are in Iraq time.','checkin') ?>
      <div class="chart-legend" aria-label="Visible chart series"><button type="button" data-chart-day="1" aria-pressed="true"><i class="day1-dot"></i>Day 1</button><button type="button" data-chart-day="2" aria-pressed="true"><i class="day2-dot"></i>Day 2</button></div>
    </div>
    <?= RegistrationReport::chart($report) ?>
    <div class="chart-foot"><span>Cumulative arrivals · Hover or focus on a point for details</span><span><?= (int)$report['recent'] ?> arrivals in the last 15 minutes</span></div>
    <details class="hourly-details" data-report-detail="hourly"><summary>View hourly arrival numbers</summary><div class="table-wrap"><table><thead><tr><th>Hour (Iraq time)</th><th>Day 1 arrivals</th><th>Day 2 arrivals</th></tr></thead><tbody><?php for($hour=0;$hour<24;$hour++): ?><tr><td><?= sprintf('%02d:00–%02d:00',$hour,$hour+1) ?></td><td><?= $report['hourly'][1][$hour] ?></td><td><?= $report['hourly'][2][$hour] ?></td></tr><?php endfor; ?></tbody></table></div></details>
  </section>
  <section class="card report-attendance-card">
    <?= Page::panelHeading('Attendance overview','Arrivals and guests still expected.','users') ?>
    <div class="attendance-ring" style="--attendance:<?= (float)$report['rate'] ?>%"><div><b><?= $report['rate'] ?>%</b><span>have attended</span></div></div>
    <?php foreach([1,2] as $reportDay): $count=(int)$summary['day'.$reportDay]; $percent=(int)$summary['guests']?round($count/(int)$summary['guests']*100):0; ?>
      <div class="checkin-report-day"><div><b>Day <?= $reportDay ?></b><span><?= $count ?> / <?= (int)$summary['guests'] ?></span></div><div class="track"><i class="<?= $reportDay===1?'teal':'violet' ?>" style="width:<?= $percent ?>%"></i></div><a href="<?= $e(CheckinWorkspace::url('attended',$day,['attendance'=>'day'.$reportDay])) ?>"><?= $e($report['dates'][$reportDay]) ?> · View attendees</a></div>
    <?php endforeach; ?>
    <div class="report-mini"><span>Attended both days</span><b><?= (int)$summary['both_days'] ?></b></div>
    <div class="report-mini"><span>Busiest arrival hour</span><b><?= $report['peak']?'Day '.$report['peak']['day'].' · '.sprintf('%02d:00',$report['peak']['hour']):'Waiting for arrivals' ?></b></div>
    <?php if($report['peak']): ?><p class="small muted"><?= $report['peak']['count'] ?> check-ins during that hour.</p><?php endif; ?>
  </section>
</div>
<div class="grid2">
  <section class="card"><?= Page::panelHeading('Guest breakdown','See who has arrived in each ticket category.','registrations') ?>
    <?php foreach(['professionals'=>'Professional','students'=>'Student'] as $key=>$label): $total=(int)$segments[$key]; $arrived=(int)$segments[$key.'_arrived']; ?>
      <div class="guest-type-report"><div><b><?= $label ?></b><span><?= $arrived ?> attended / <?= $total ?> registered</span></div><div class="track"><i class="<?= $key==='students'?'violet':'teal' ?>" style="width:<?= $total?round($arrived/$total*100):0 ?>%"></i></div><a href="<?= $e(CheckinWorkspace::url('guests',$day,['type'=>$key==='students'?'student':'professional'])) ?>">Open <?= strtolower($label) ?> guest list</a></div>
    <?php endforeach; ?>
    <?php if((int)$segments['students_without_id']>0): ?><p class="report-attention"><?= (int)$segments['students_without_id'] ?> students have no ID image on file. <a href="<?= $e(CheckinWorkspace::url('guests',$day,['type'=>'student'])) ?>">Review student records</a></p><?php endif; ?>
  </section>
  <section class="card"><?= Page::panelHeading('Lunch planning','Booked lunches and eligible guests who have arrived.','lunch') ?>
    <div class="lunch-report-grid"><?php foreach([1,2] as $reportDay): ?><div class="lunch-report-day"><span>DAY <?= $reportDay ?></span><strong><?= (int)$segments['lunch_arrived'.$reportDay] ?></strong><b>arrived with lunch booked</b><p><?= (int)$segments['lunch'.$reportDay] ?> lunches booked · <?= (int)$segments['lunch'.$reportDay]-(int)$segments['lunch_arrived'.$reportDay] ?> guests still expected</p><a href="<?= $e(CheckinWorkspace::url('guests',$day,['lunch'=>'day'.$reportDay])) ?>">View lunch list</a></div><?php endforeach; ?></div>
    <p class="small muted">These totals show lunch bookings and event attendance. They do not record meals collected.</p>
  </section>
</div>
<section class="card latest-arrival-card"><div class="panel-top"><?= Page::panelHeading('Latest arrivals','The 10 most recent confirmed check-ins, across both event days.','clock') ?><a class="btn ghost" href="<?= $e(CheckinWorkspace::url('attended',$day,['sort'=>'latest'])) ?>">View all attended</a></div>
  <?php if(!$report['latest']): ?><?= Page::emptyState('Waiting for the first arrival','Confirmed QR check-ins will appear here automatically.','checkin') ?><?php else: ?>
  <div class="table-wrap"><table class="arrival-feed-table"><thead><tr><th>Guest</th><th>Day</th><th>Arrival (Iraq time)</th><th>Checked in by</th><th>Lunch</th></tr></thead><tbody>
    <?php foreach($report['latest'] as $arrival): ?><tr><td><b><?= $e(Registrations::fullName($arrival)) ?></b><small><?= $e(ucfirst($arrival['ticket_type'])) ?> · <?= $e($arrival['ticket_no']) ?></small></td><td><span class="arrival-day">Day <?= (int)$arrival['event_day'] ?></span></td><td><b><?= date('H:i:s',strtotime($arrival['checked_in_at'])) ?></b><small><?= date('d M Y',strtotime($arrival['checked_in_at'])) ?></small></td><td><?= $e($arrival['staff']??'Staff unavailable') ?><small><?= $e(\Ismile\Attendance::methodLabel($arrival['checkin_method'])) ?></small></td><td><?= $arrival['lunch_day'.$arrival['event_day']]?'Booked':'Not booked' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php if(Auth::can($user,'export')): ?><section class="card report-download-card"><?= Page::panelHeading('Download reports','Save attendance, guest and lunch lists for your team.','download') ?><div class="report-download-grid"><a class="btn green" href="export.php?what=attendance&amp;format=pdf">Attendance PDF</a><a class="btn ghost" href="export.php?what=attendance">Attendance Excel</a><a class="btn ghost" href="export.php?what=registrations&amp;workspace=checkin&amp;format=pdf">Guest list PDF</a><a class="btn ghost" href="export.php?what=registrations&amp;workspace=checkin&amp;lunch=day1&amp;format=pdf">Day 1 lunch PDF</a><a class="btn ghost" href="export.php?what=registrations&amp;workspace=checkin&amp;lunch=day2&amp;format=pdf">Day 2 lunch PDF</a><a class="btn ghost" href="export.php?what=all&amp;format=pdf">All event reports PDF</a></div></section><?php endif; ?>
