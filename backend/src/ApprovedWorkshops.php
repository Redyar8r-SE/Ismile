<?php
declare(strict_types=1);
namespace Ismile;

final class ApprovedWorkshops
{
    public const DEMOS=['implant','dsd','endo','scan','soft-tissue-implants','composite-restorations'];

    public static function definitions(): array
    {
        $rows=json_decode((string)file_get_contents(__DIR__.'/../assets/real-workshops-2026.json'),true,512,JSON_THROW_ON_ERROR);
        if(count($rows)!==3)throw new \RuntimeException('Expected the three approved workshops.');
        return $rows;
    }

    public static function publish(array $owner): array
    {
        if(($owner['role']??'')!=='owner')throw new UserError('Owner access is required.');
        if(Db::value("SELECT v FROM settings WHERE k='real_workshops_2026_v1'"))return ['already_published'=>true];
        $rows=self::definitions();$ids=array_column($rows,'id');
        foreach($rows as $row)if(!is_file(App::siteFile($row['photo'])))throw new \RuntimeException('Approved workshop portrait missing: '.$row['id']);
        $result=Db::transaction(static function()use($rows,$ids,$owner):array{
            $existing=Db::all('SELECT * FROM workshops ORDER BY id FOR UPDATE');$deleted=[];$archived=[];
            foreach($existing as $w){
                if(in_array($w['id'],$ids,true))continue;
                if(!in_array($w['id'],self::DEMOS,true))throw new \RuntimeException('An unrecognized workshop requires review: '.$w['id']);
                $expected=['soft-tissue-implants'=>'Dr. Sarah Hassan','composite-restorations'=>'Dr. Alan Karim'];
                if(isset($expected[$w['id']]) && $w['speaker_en']!==$expected[$w['id']])throw new \RuntimeException('A former demo was edited; review it before replacement.');
                if((int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id=?',[$w['id']])>0){Db::update('workshops',['status'=>'hidden','updated_at'=>App::now()],'id=?',[$w['id']]);$archived[]=$w['id'];}
                else {Db::run('DELETE FROM workshops WHERE id=?',[$w['id']]);$deleted[]=$w['id'];}
            }
            foreach($rows as $row){
                $old=Db::one('SELECT * FROM workshops WHERE id=?',[$row['id']]);
                if($old){if((int)Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id=?',[$row['id']])>0 && ($old['currency']!==$row['currency'] || (int)$old['price']!==$row['price']))throw new \RuntimeException('A real workshop already has bookings at another price.');Db::update('workshops',$row+['updated_at'=>App::now()],'id=?',[$row['id']]);}
                else Db::insert('workshops',$row+['created_at'=>App::now(),'updated_at'=>App::now()]);
            }
            Db::insert('settings',['k'=>'real_workshops_2026_v1','v'=>App::now(),'updated_at'=>App::now()]);
            Audit::log((int)$owner['id'],'workshops.real_publication','workshop',null,['published'=>$ids,'deleted_demos'=>$deleted,'archived_demos'=>$archived]);
            return ['published'=>$ids,'deleted_demos'=>$deleted,'archived_demos'=>$archived];
        });
        Workshops::syncWebsite();return $result;
    }
}
