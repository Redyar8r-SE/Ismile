<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
use Ismile\App;
use Ismile\Db;
use Ismile\Admin\WorkshopAttendeeSearch;
if(App::isLive()||!str_starts_with((string)App::config('db.name'),'ismile_workshop_test_real_'))throw new RuntimeException('Isolated workshop test database required.');
$passed=0;
$check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAILED: '.$name);echo 'PASS: '.$name.PHP_EOL;$passed++;};
$tag=bin2hex(random_bytes(4));
App::db()->beginTransaction();
try{
    $seed=static function(string $first,int $n,string $status='paid',string $father='Lookup')use($tag):array{
        $id=Db::insert('registrations',['ref'=>'LS'.strtoupper($tag).$n,'first_name'=>$first,'father_name'=>$father,'grandfather_name'=>$tag,'email'=>'lookup-'.$tag.'-'.$n.'@example.invalid','phone'=>'+964750612'.str_pad((string)$n,4,'0',STR_PAD_LEFT),'city'=>'Sulaimani','specialty'=>'gp','ticket_type'=>'professional','status'=>$status,'comp_reason'=>$status==='complimentary'?'Test':null,'view_nonce'=>bin2hex(random_bytes(16)),'paid_at'=>App::now(),'created_at'=>App::now(),'updated_at'=>App::now()]);
        return Db::one('SELECT * FROM registrations WHERE id=?',[$id]);
    };
    $first=$seed('Aban',1);$seed('Abas',2);$seed('Azad',3);$seed('Babak',4);$middle=$seed('Zana',5,'paid','Abas');
    for($i=6;$i<=36;$i++)$seed('Abel',$i);
    $free=$seed('Abguest',37,'complimentary');$cancelled=$seed('Abcancelled',38,'cancelled');
    $a=WorkshopAttendeeSearch::find('A',true);
    $check('A includes only names starting with A',count($a['people'])>0&&array_reduce($a['people'],static fn($ok,$p)=>$ok&&str_starts_with(strtolower($p['first_name']),'a'),true));
    $ab=WorkshopAttendeeSearch::find('AB',true);
    $check('AB narrows the complete prefix and ignores case',$ab['total']===33&&array_reduce($ab['people'],static fn($ok,$p)=>$ok&&str_starts_with(strtolower($p['first_name']),'ab'),true));
    $check('All matching names remain accessible beyond the first page',count($ab['people'])===25&&count(WorkshopAttendeeSearch::find('Ab',true,25)['people'])===8);
    $check('Pages contain no duplicates',count(array_unique(array_column(array_merge($ab['people'],WorkshopAttendeeSearch::find('Ab',true,25)['people']),'id')))===33);
    $check('A match in a second name does not appear',!in_array($middle['id'],array_column($ab['people'],'id')));
    $check('B starts a separate first-name search',array_column(WorkshopAttendeeSearch::find('B',true)['people'],'first_name')===['Babak']);
    $check('Full names can be typed progressively',WorkshopAttendeeSearch::find('Aban Lookup',true)['people'][0]['id']===$first['id']);
    foreach([$first['phone'],'07506120001','7506120001','009647506120001','+964 750 612 0001','٠٧٥٠٦١٢٠٠٠١']as$phone)$check('Phone lookup: '.$phone,(WorkshopAttendeeSearch::find($phone,true)['people'][0]['id']??null)===$first['id']);
    $check('Partial local phone numbers find every matching attendee',WorkshopAttendeeSearch::find('0750612',true)['total']===36);
    $check('Email lookup finds the attendee',WorkshopAttendeeSearch::find($first['email'],true)['people'][0]['id']===$first['id']);
    $check('Partial email lookup works',WorkshopAttendeeSearch::find('lookup-'.$tag.'-1@',true)['people'][0]['id']===$first['id']);
    $check('Reference lookup remains available',WorkshopAttendeeSearch::find($first['ref'],true)['people'][0]['id']===$first['id']);
    $check('Paid lookup excludes complimentary and cancelled attendees',$ab['total']===33&&WorkshopAttendeeSearch::find($free['email'],true)['total']===0&&WorkshopAttendeeSearch::find($cancelled['email'],true)['total']===0);
    $check('Guest booking preserves complimentary eligibility',WorkshopAttendeeSearch::find($free['email'],false)['total']===1);
    $check('An empty query returns no directory',WorkshopAttendeeSearch::find('',true)['people']===[]);
    $check('SQL wildcards remain literal search text',WorkshopAttendeeSearch::find('Ab%',true)['total']===0&&WorkshopAttendeeSearch::find('_',true)['total']===0);
}finally{App::db()->rollBack();}
echo $passed.' workshop attendee search checks passed.'.PHP_EOL;

