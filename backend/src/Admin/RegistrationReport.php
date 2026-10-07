<?php
declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\App;
use Ismile\Db;
use Ismile\Settings;

final class RegistrationReport
{
    /** A consistent snapshot, including arrivals committed by other check-in desks. */
    public static function snapshot(): array
    {
        $db=App::db();
        $ownTransaction=!$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();
        try {
            $summary=CheckinWorkspace::summary();
            $segments=Db::one("SELECT
                COALESCE(SUM(r.ticket_type='student'),0) AS students,
                COALESCE(SUM(r.ticket_type='professional'),0) AS professionals,
                COALESCE(SUM(r.ticket_type='student' AND (a1.id IS NOT NULL OR a2.id IS NOT NULL)),0) AS students_arrived,
                COALESCE(SUM(r.ticket_type='professional' AND (a1.id IS NOT NULL OR a2.id IS NOT NULL)),0) AS professionals_arrived,
                COALESCE(SUM(r.ticket_type='student' AND r.id_photo_id IS NULL),0) AS students_without_id,
                COALESCE(SUM(r.lunch_day1),0) AS lunch1,
                COALESCE(SUM(r.lunch_day2),0) AS lunch2,
                COALESCE(SUM(r.lunch_day1=1 AND a1.id IS NOT NULL),0) AS lunch_arrived1,
                COALESCE(SUM(r.lunch_day2=1 AND a2.id IS NOT NULL),0) AS lunch_arrived2
                FROM ".CheckinWorkspace::from()." WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL");
            $base="ticket_attendance a JOIN tickets t ON t.id=a.ticket_id JOIN registrations r ON r.id=t.registration_id";
            $valid="r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL";
            $hourly=[1=>array_fill(0,24,0),2=>array_fill(0,24,0)];
            foreach (Db::all("SELECT a.event_day,HOUR(a.checked_in_at) AS hour,COUNT(*) AS n FROM $base WHERE $valid GROUP BY a.event_day,HOUR(a.checked_in_at)") as $row) {
                $hourly[(int)$row['event_day']][(int)$row['hour']]=(int)$row['n'];
            }
            $latest=Db::all("SELECT r.id,r.ref,r.first_name,r.father_name,r.grandfather_name,r.ticket_type,r.lunch_day1,r.lunch_day2,t.ticket_no,a.event_day,a.checked_in_at,a.checkin_method,u.name AS staff FROM $base LEFT JOIN admin_users u ON u.id=a.checked_in_by WHERE $valid ORDER BY a.checked_in_at DESC,a.id DESC LIMIT 10");
            $recent=(int)Db::value("SELECT COUNT(*) FROM $base WHERE $valid AND a.checked_in_at>=? AND a.checked_in_at<=?",[date('Y-m-d H:i:s',time()-900),App::now()]);
            $peak=null;
            foreach ($hourly as $eventDay=>$hours) foreach ($hours as $hour=>$n) if ($n>0 && ($peak===null || $n>$peak['count'])) $peak=['day'=>$eventDay,'hour'=>$hour,'count'=>$n];
            $result=['summary'=>$summary,'segments'=>$segments,'hourly'=>$hourly,'latest'=>$latest,'recent'=>$recent,'peak'=>$peak,
                'rate'=>(int)$summary['guests']?round((int)$summary['attended']/(int)$summary['guests']*100,1):0,
                'updated_at'=>App::now(),'timezone'=>App::config('timezone','Asia/Baghdad'),
                'dates'=>[1=>Settings::get('event_day1'),2=>Settings::get('event_day2')]];
            if ($ownTransaction) $db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** Cumulative lines make the pace of Day 1 and Day 2 directly comparable. */
    public static function chart(array $report): string
    {
        $totals=array_map('array_sum',$report['hourly']);
        $max=max(5,(int)(ceil(max($totals)/5)*5));
        $html='<svg class="arrival-chart" viewBox="0 0 800 270" role="img" aria-label="Cumulative check-ins by hour, comparing Day 1 and Day 2. Exact values are in the hourly table below."><title>Arrivals over the day</title>';
        for ($i=0;$i<=5;$i++) {
            $y=225-$i*39;
            $html.='<line x1="48" y1="'.$y.'" x2="776" y2="'.$y.'" class="chart-grid"/><text x="38" y="'.($y+4).'" text-anchor="end">'.Page::e((string)($max*$i/5)).'</text>';
        }
        foreach ([0,6,12,18,24] as $hour) {
            $x=48+$hour/24*728;
            $html.='<text x="'.$x.'" y="251" text-anchor="'.($hour===24?'end':($hour===0?'start':'middle')).'">'.sprintf('%02d:00',$hour).'</text>';
        }
        foreach ([1,2] as $eventDay) {
            $cumulative=0; $points=['48,225']; $dots='';
            foreach ($report['hourly'][$eventDay] as $hour=>$count) {
                $cumulative+=$count;
                $x=round(48+($hour+1)/24*728,2); $y=round(225-$cumulative/$max*195,2);
                $points[]="$x,$y";
                $dots.='<circle cx="'.$x.'" cy="'.$y.'" r="4" tabindex="0"><title>Day '.$eventDay.' · '.sprintf('%02d:00–%02d:00',$hour,$hour+1).' · '.$count.' arrivals · '.$cumulative.' total</title></circle>';
            }
            $html.='<g class="chart-series series-day'.$eventDay.'"><polyline points="'.implode(' ',$points).'"/>'.$dots.'</g>';
        }
        if (array_sum($totals)===0) $html.='<text x="412" y="110" text-anchor="middle" class="chart-empty">Waiting for the first arrival</text>';
        return $html.'</svg>';
    }

    public static function render(array $report, array $user, ?int $day): string
    {
        ob_start();
        require __DIR__.'/Views/registration-report.php';
        return (string)ob_get_clean();
    }
}
