<?php
declare(strict_types=1);

namespace Ismile\Admin;

use Ismile\Auth;
use Ismile\Attendance;
use Ismile\Db;
use Ismile\Registrations;

final class CheckinWorkspace
{
    /** Guests shown at first, and added each time the list is scrolled to its end. */
    public const BATCH = 20;

    // PSOOLA scans the tickets at the door (copied in by PsoolaCheckIns), so
    // there is no iSmile scanner tab: the workspace opens on Attended.
    public const TABS = [
        'attended' => ['Attended', 'check', 'checkin'],
        'guests' => ['Guest list', 'users', 'checkin'],
        'report' => ['Report', 'lists', 'checkin'],
    ];

    public static function pick(string $tab): string
    {
        return isset(self::TABS[$tab]) ? $tab : 'attended';
    }

    /** The three Registration tabs; $counts (tab => number) shows a count on a tab. */
    public static function navigation(string $active, array $user, ?int $day = null, array $counts = []): string
    {
        $html = '<nav class="checkin-tabs registration-tabs no-print" aria-label="Registration sections">';
        foreach (self::TABS as $key => [$label, $icon, $area]) {
            $content = Page::navIcon($icon) . '<span>' . Page::e($label) . '</span>' . (isset($counts[$key]) ? '<span class="registration-tab-count">' . number_format((int) $counts[$key]) . '</span>' : '');
            if (!Auth::can($user, $area)) {
                $html .= '<span class="checkin-tab restricted" aria-disabled="true" title="Your role cannot use this section">' . $content . '</span>';
                continue;
            }
            $html .= '<a class="checkin-tab' . ($key === $active ? ' on' : '') . '" href="' . Page::e(self::url($key, $day)) . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . $content . '</a>';
        }
        return $html . '</nav>';
    }

    public static function url(string $tab, ?int $day = null, array $extra = []): string
    {
        $in = ['tab' => self::pick($tab)];
        if (in_array($day, [1, 2], true)) $in['day'] = $day;
        return 'checkin.php?' . http_build_query(array_merge($in, $extra));
    }

    public static function from(): string
    {
        return 'registrations r JOIN tickets t ON t.registration_id=r.id
            LEFT JOIN ticket_attendance a1 ON a1.ticket_id=t.id AND a1.event_day=1
            LEFT JOIN ticket_attendance a2 ON a2.ticket_id=t.id AND a2.event_day=2
            LEFT JOIN admin_users u1 ON u1.id=a1.checked_in_by
            LEFT JOIN admin_users u2 ON u2.id=a2.checked_in_by
            LEFT JOIN certificates c ON c.registration_id=r.id';
    }

    /** Parameterized search, one row per guest, with attendance on each day. */
    public static function where(string $query, string $attendance = '', array $filters = []): array
    {
        $where = ["r.status IN ('paid','complimentary')", 't.cancelled_at IS NULL'];
        $params = [];
        if ($query !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
            if (preg_match('/^\p{L}$/u',$query)) {
                $where[]="CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ?";
                $params=[substr($like,1)];
            } else {
                $phone=GuestLookup::phonePrefix($query);
                $where[] = "(CONCAT_WS(' ',r.first_name,r.father_name,r.grandfather_name) LIKE ? OR r.ref LIKE ? OR t.ticket_no LIKE ?".($phone!==null?' OR r.phone LIKE ?':'').')';
                $params = [substr($like,1), $like, $like];
                if ($phone!==null) $params[]=$phone.'%';
            }
        }
        $scope = match ($attendance) {
            'attended' => '(a1.id IS NOT NULL OR a2.id IS NOT NULL)',
            'day1' => 'a1.id IS NOT NULL',
            'day2' => 'a2.id IS NOT NULL',
            'both' => '(a1.id IS NOT NULL AND a2.id IS NOT NULL)',
            'none' => '(a1.id IS NULL AND a2.id IS NULL)',
            default => null,
        };
        if ($scope !== null) $where[] = $scope;
        $filters = self::filters($filters);
        // Checked out (owner, 19 Nov 2026): on the chosen day, else today's event day, else either day.
        if ($filters['out'] !== '') {
            $outDay = ['day1' => 1, 'day2' => 2][$attendance] ?? Attendance::currentDay();
            $where[] = match ($filters['out']) {
                'left' => $outDay ? "a$outDay.checked_out_at IS NOT NULL" : '(a1.checked_out_at IS NOT NULL OR a2.checked_out_at IS NOT NULL)',
                default => $outDay ? "(a$outDay.id IS NOT NULL AND a$outDay.checked_out_at IS NULL)"
                    : '((a1.id IS NOT NULL AND a1.checked_out_at IS NULL) OR (a2.id IS NOT NULL AND a2.checked_out_at IS NULL))',
            };
        }
        // Time at the summit (owner, 19 Nov 2026): the time inside, as on the profile (20 Nov
        // 2026): the sum of the visits, time outside not counted, each day ending at 19:45.
        // On the chosen day, else today's event day, else either day. 1 hour = 3600 seconds.
        if ($filters['stay'] !== '') {
            $stayDay = ['day1' => 1, 'day2' => 2][$attendance] ?? Attendance::currentDay();
            $long = static function (int $d) use (&$params): string {
                [$sql, $values] = self::insideSql($d);
                array_push($params, ...$values);
                return "(a$d.id IS NOT NULL AND $sql >= 3600)";
            };
            if ($stayDay) {
                if ($filters['stay'] === 'long') {
                    $where[] = $long($stayDay);
                } else {
                    [$sql, $values] = self::insideSql($stayDay);
                    array_push($params, ...$values);
                    $where[] = "(a$stayDay.id IS NOT NULL AND $sql < 3600)";
                }
            } else {
                $either = '(' . $long(1) . ' OR ' . $long(2) . ')';
                $where[] = $filters['stay'] === 'long' ? $either : "((a1.id IS NOT NULL OR a2.id IS NOT NULL) AND NOT $either)";
            }
        }
        foreach (['type'=>'ticket_type','status'=>'status','specialty'=>'specialty','city'=>'city','university'=>'university','lang'=>'lang','ambassador'=>'ambassador_code'] as $key=>$column) {
            if ($filters[$key] !== '') { $where[] = "r.$column = ?"; $params[] = $filters[$key]; }
        }
        $lunch = match ($filters['lunch']) {
            'day1'=>'r.lunch_day1=1', 'day2'=>'r.lunch_day2=1', 'both'=>'r.lunch_day1=1 AND r.lunch_day2=1',
            'any'=>'(r.lunch_day1=1 OR r.lunch_day2=1)', 'none'=>'r.lunch_day1=0 AND r.lunch_day2=0', default=>null,
        };
        if ($lunch !== null) $where[] = "($lunch)";
        if ($filters['id'] === 'present') $where[] = "r.ticket_type='student' AND r.id_photo_id IS NOT NULL";
        if ($filters['id'] === 'missing') $where[] = "r.ticket_type='student' AND r.id_photo_id IS NULL AND r.id_photo_deleted_at IS NULL";
        if ($filters['id'] === 'removed') $where[] = "r.ticket_type='student' AND r.id_photo_id IS NULL AND r.id_photo_deleted_at IS NOT NULL";
        $arrival = [];
        if ($filters['arrival_date'] !== '') { $arrival[]='DATE(ax.checked_in_at)=?'; $params[]=$filters['arrival_date']; }
        if ($filters['from_time'] !== '') { $arrival[]='TIME(ax.checked_in_at)>=?'; $params[]=$filters['from_time'].':00'; }
        if ($filters['to_time'] !== '') { $arrival[]='TIME(ax.checked_in_at)<=?'; $params[]=$filters['to_time'].':59'; }
        if ($filters['staff'] !== '') { $arrival[]='ax.checked_in_by=?'; $params[]=(int)$filters['staff']; }
        if ($arrival) {
            if (in_array($attendance,['day1','day2'],true)) $arrival[]='ax.event_day='.($attendance==='day1'?1:2);
            $where[] = 'EXISTS (SELECT 1 FROM ticket_attendance ax WHERE ax.ticket_id=t.id AND '.implode(' AND ',$arrival).')';
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Seconds inside on one day for the ticket t, in SQL, counted exactly like
     * Attendance::timeInside(): every visit, a visit with no exit scan up to
     * now while the day goes on and up to 19:45 once it is over, nothing
     * after 19:45. A visit that cannot be counted adds nothing.
     *
     * @return array{0: string, 1: list<?string>}
     */
    public static function insideSql(int $day, ?string $now = null): array
    {
        $now ??= \Ismile\App::now();
        $end = Attendance::dayEndsAt($day);
        $limit = null;
        if ($end !== '' && $now >= $end) {
            $limit = $end;
        } elseif (substr($now, 0, 10) === \Ismile\Settings::get('event_day' . $day)) {
            $limit = $now;
        }
        $sql = "(SELECT COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, v.entered_at, LEAST(COALESCE(v.left_at, ?), ?)))), 0)"
            . " FROM attendance_visits v WHERE v.ticket_id = t.id AND v.event_day = $day)";
        return [$sql, [$limit, $end !== '' ? $end : '9999-12-31 23:59:59']];
    }

    /**
     * Adds 'inside1' / 'inside2' to each row: Attendance::timeInside() of that
     * day's visits, or null when the day has no visits.
     */
    public static function withTimeInside(array $rows, ?string $now = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($r) => (int) ($r['ticket_id'] ?? 0), $rows))));
        $visits = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (Db::all('SELECT ticket_id, event_day, entered_at, left_at FROM attendance_visits WHERE ticket_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY entered_at', $chunk) as $v) {
                $visits[(int) $v['ticket_id']][(int) $v['event_day']][] = ['entered_at' => $v['entered_at'], 'left_at' => $v['left_at']];
            }
        }
        foreach ($rows as $i => $row) {
            foreach ([1, 2] as $day) {
                $list = $visits[(int) ($row['ticket_id'] ?? 0)][$day] ?? [];
                $rows[$i]['inside' . $day] = $list ? Attendance::timeInside($list, $day, $now) : null;
            }
        }
        return $rows;
    }

    public static function filters(array $input, bool $attended = false): array
    {
        $filters=[];
        foreach (['q','attendance','type','status','lunch','id','specialty','city','university','lang','ambassador','arrival_date','from_time','to_time','staff','sort','out','stay'] as $key) {
            $filters[$key]=mb_substr(trim(is_string($input[$key]??null) ? $input[$key] : ''),0,$key==='q'?200:160);
        }
        foreach (['attendance'=>['attended','day1','day2','both','none'],'type'=>['student','professional','vip'],'status'=>['paid','complimentary'],
            'lunch'=>['day1','day2','both','any','none'],'id'=>['present','missing','removed'],'specialty'=>array_keys(Registrations::SPECIALTY_NAMES),
            'lang'=>['en','ar','ku'],'sort'=>['name','latest','earliest'],'out'=>['left','inside'],'stay'=>['short','long']] as $key=>$valid) {
            if (!in_array($filters[$key],$valid,true)) $filters[$key]='';
        }
        if ($attended && !in_array($filters['attendance'],['attended','day1','day2','both'],true)) $filters['attendance']='attended';
        if ($filters['sort']==='') $filters['sort']=$attended?'latest':'name';
        if ($filters['arrival_date']!=='') {
            $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$filters['arrival_date']);
            if (!$parsed || $parsed->format('Y-m-d')!==$filters['arrival_date']) $filters['arrival_date']='';
        }
        foreach (['from_time','to_time'] as $key) if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$filters[$key])) $filters[$key]='';
        if (!ctype_digit($filters['staff']) || (int)$filters['staff']<1) $filters['staff']='';
        return $filters;
    }

    public static function order(string $sort, string $attendance = ''): string
    {
        if (in_array($attendance,['day1','day2'],true) && in_array($sort,['latest','earliest'],true)) {
            return ($attendance==='day1'?'a1':'a2').'.checked_in_at '.($sort==='latest'?'DESC':'ASC').',r.id';
        }
        return match ($sort) {
            'latest'=>"GREATEST(COALESCE(a1.checked_in_at,'1000-01-01'),COALESCE(a2.checked_in_at,'1000-01-01')) DESC,r.id DESC",
            'earliest'=>"LEAST(COALESCE(a1.checked_in_at,'9999-12-31'),COALESCE(a2.checked_in_at,'9999-12-31')) ASC,r.id ASC",
            default=>'r.first_name,r.father_name,r.grandfather_name,r.id',
        };
    }

    public static function queryString(array $filters, array $extra = []): string
    {
        return http_build_query(array_merge(array_filter(self::filters($filters),static fn($value)=>$value!==''),$extra));
    }

    public static function rows(array $filters, ?int $limit = null, int $offset = 0): array
    {
        $filters=self::filters($filters);
        [$where,$params]=self::where($filters['q'],$filters['attendance'],$filters);
        $paging=$limit!==null?' LIMIT '.max(1,min(5000,$limit)).' OFFSET '.max(0,$offset):'';
        return self::withTimeInside(Db::all('SELECT r.*,t.id AS ticket_id,t.version AS ticket_version,t.source AS ticket_source,t.ticket_no,a1.checked_in_at AS day1,a2.checked_in_at AS day2,a1.checked_out_at AS out1,a2.checked_out_at AS out2,a1.checkin_method AS method1,a2.checkin_method AS method2,u1.name AS staff1,u2.name AS staff2,c.certificate_no FROM '.self::from()." WHERE $where ORDER BY ".self::order($filters['sort'],$filters['attendance']).$paging,$params));
    }

    public static function options(): array
    {
        $options=[];
        foreach (['city'=>'city','university'=>'university','ambassador'=>'ambassador_code'] as $key=>$column) {
            $options[$key]=array_column(Db::all("SELECT DISTINCT r.$column AS value FROM registrations r JOIN tickets t ON t.registration_id=r.id WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL AND r.$column IS NOT NULL AND r.$column<>'' ORDER BY r.$column LIMIT 200"),'value');
        }
        $options['staff']=Db::all('SELECT DISTINCT u.id,u.name FROM admin_users u JOIN ticket_attendance a ON a.checked_in_by=u.id ORDER BY u.name');
        return $options;
    }

    /**
     * Time at the summit on one day, as words: "Stayed 2 h 15 min" after a
     * check-out, "Here 45 min" while still inside today, '' otherwise.
     */
    /**
     * The list's time tag from the visits (owner, 20 Nov 2026): "Here 1 h 5 min 3 s"
     * while inside, "Stayed …" otherwise; '' when nothing can be counted.
     */
    public static function insideText(?array $inside): string
    {
        if ($inside === null || ($inside['seconds'] === 0 && $inside['unknown'])) return '';
        return ($inside['open'] ? 'Here ' : 'Stayed ') . Attendance::durationText($inside['seconds']);
    }

    public static function stayText(?string $arrived, ?string $left, bool $today, ?int $now = null): string
    {
        if (!$arrived) return '';
        $end = $left ? strtotime($left) : ($today ? ($now ?? time()) : null);
        if ($end === null) return '';
        $minutes = max(0, intdiv($end - strtotime($arrived), 60));
        $text = $minutes < 60 ? $minutes . ' min' : intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '');
        return ($left ? 'Stayed ' : 'Here ') . $text;
    }

    public static function idStatus(array $row): string
    {
        if ($row['ticket_type']!=='student') return 'Not required';
        if ($row['id_photo_id']!==null) return 'ID on file';
        return $row['id_photo_deleted_at']!==null ? 'ID removed' : 'No ID on file';
    }

    public static function exportRows(array $filters): array
    {
        $rows=[['Reference','Name','Ticket','Category','Status','Student ID','University','City','Specialty','Lunch Day 1','Lunch Day 2','Day 1 arrival (Iraq time)','Day 1 checked in by','Day 2 arrival (Iraq time)','Day 2 checked in by','Registered','Paid / ticket issued','Language','Ambassador','Phone','Email','Day 1 check-in method','Day 2 check-in method','Day 1 left (Iraq time)','Day 2 left (Iraq time)']];
        foreach (self::rows($filters) as $row) {
            $rows[]=[$row['ref'],Registrations::fullName($row),$row['ticket_no'],\Ismile\Registrations::ticketName($row['ticket_type']),$row['status'],self::idStatus($row),$row['university']??'',$row['city'],Registrations::SPECIALTY_NAMES[$row['specialty']]??$row['specialty'],
                $row['lunch_day1']?'Booked':'Not booked',$row['lunch_day2']?'Booked':'Not booked',$row['day1']??'',$row['staff1']??'',$row['day2']??'',$row['staff2']??'',$row['created_at'],$row['paid_at'],$row['lang'],$row['ambassador_code']??'',$row['phone'],$row['email'],Attendance::methodLabel($row['method1']),Attendance::methodLabel($row['method2']),$row['out1']??'',$row['out2']??''];
        }
        return $rows;
    }

    public static function summary(): array
    {
        return Db::one("SELECT COUNT(*) AS guests,
            COALESCE(SUM(a1.id IS NOT NULL),0) AS day1,
            COALESCE(SUM(a2.id IS NOT NULL),0) AS day2,
            COALESCE(SUM(a1.id IS NOT NULL OR a2.id IS NOT NULL),0) AS attended,
            COALESCE(SUM(a1.id IS NOT NULL AND a2.id IS NOT NULL),0) AS both_days,
            COALESCE(SUM(a1.id IS NULL AND a2.id IS NULL),0) AS waiting,
            COALESCE(SUM(a1.checked_out_at IS NOT NULL),0) AS left_day1,
            COALESCE(SUM(a2.checked_out_at IS NOT NULL),0) AS left_day2,
            COALESCE(SUM(a1.checked_out_at IS NOT NULL OR a2.checked_out_at IS NOT NULL),0) AS left_any,
            COALESCE(SUM(c.id IS NOT NULL AND (a1.id IS NOT NULL OR a2.id IS NOT NULL)),0) AS certificates
            FROM " . self::from() . " WHERE r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL") ?? [];
    }
}
