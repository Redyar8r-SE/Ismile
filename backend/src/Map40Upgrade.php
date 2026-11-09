<?php
declare(strict_types=1);
namespace Ismile;

/** One authorised move: visible booth 22 to 40, retaining the booking's identity. */
final class Map40Upgrade
{
    public static function transfer(string $reference): array
    {
        BoothPlan::assertDatabaseMatches();
        $positions=BoothPlan::positions();
        if(BoothPlan::COUNT!==40||($positions[25]['display_number']??null)!==22||($positions[25]['tier']??null)!=='bronze'||($positions[45]['display_number']??null)!==40||($positions[45]['tier']??null)!=='silver')throw new \RuntimeException('The approved 40-position map must be installed before moving this booking.');
        return Db::transaction(static function()use($reference):array{
            // Lock both positions in the same order as a reservation update.
            Db::all('SELECT position_id FROM sponsor_map_positions WHERE position_id IN (25,45) ORDER BY position_id FOR UPDATE');
            $before=Db::one("SELECT * FROM sponsor_requests WHERE ref=? AND kind='sponsor' FOR UPDATE",[$reference]);
            if(!$before)throw new \RuntimeException('The specified sponsor booking was not found.');
            if($before['booth_number']==='45'&&Db::value("SELECT id FROM audit_log WHERE action='sponsor.map_22_to_40' AND target_type='sponsor' AND target_id=?",[$before['id']]))return ['moved'=>false,'alreadyApplied'=>true,'from'=>22,'to'=>40,'booking'=>(int)$before['id']];
            if($before['booth_number']!=='25'||$before['reserved_booth']!=='25'||$before['package_id']!=='silver')throw new \RuntimeException('The specified booking no longer matches the active Silver reservation at booth 22.');
            if(Db::value("SELECT id FROM sponsor_requests WHERE reserved_booth='45' FOR UPDATE"))throw new \RuntimeException('Booth 40 is already reserved. No booking was moved.');
            $calls=Db::all('SELECT * FROM sponsor_calls WHERE request_id=? ORDER BY id',[$before['id']]);
            $backupDir=App::storage('map-backups');if(!is_dir($backupDir)&&!mkdir($backupDir,0700,true))throw new \RuntimeException('Private backup folder could not be created.');
            $backup=$backupDir.'/22-to-40-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            if(file_put_contents($backup,json_encode(['booking'=>$before,'calls'=>$calls,'positions'=>Db::all('SELECT * FROM sponsor_map_positions ORDER BY position_id')],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Private booking backup failed.');
            chmod($backup,0600);
            Db::update('sponsor_requests',['booth_number'=>'45','updated_at'=>App::now()],'id=?',[$before['id']]);
            $after=Db::one('SELECT * FROM sponsor_requests WHERE id=?',[$before['id']]);
            $expected=$before;$expected['booth_number']='45';$expected['reserved_booth']='45';$expected['updated_at']=$after['updated_at'];
            if($after!==$expected||Db::all('SELECT * FROM sponsor_calls WHERE request_id=? ORDER BY id',[$before['id']])!==$calls)throw new \RuntimeException('Transferred booking did not preserve its contact, financial or call information.');
            Audit::log(null,'sponsor.map_22_to_40','sponsor',(int)$before['id'],['reference'=>$reference,'fromPosition'=>25,'fromDisplay'=>22,'toPosition'=>45,'toDisplay'=>40,'preservedPackage'=>'silver','backup'=>basename($backup)]);
            return ['moved'=>true,'from'=>22,'to'=>40,'booking'=>(int)$before['id'],'detailsPreserved'=>true,'callsPreserved'=>true];
        });
    }
}
