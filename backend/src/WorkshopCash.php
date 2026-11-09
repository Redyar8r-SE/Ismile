<?php
declare(strict_types=1);
namespace Ismile;

/** Cash requests are unpaid and do not reserve provider inventory. */
final class WorkshopCash
{
    public static function request(string $workshopId, string $email): array
    {
        $email=strtolower(trim($email));
        if(!Validate::email($email))throw new UserError('wd_err_email','email');
        $workshop=Workshops::find($workshopId);
        if(!$workshop||$workshop['status']!=='active')throw new UserError('wd_err_missing',null,404);
        if($workshop['organizer']!=='ismile')throw new UserError('wd_err_by_phone',null,409);
        if($workshop['price']<=0)throw new UserError('wd_err_server',null,409);
        $person=Db::one("SELECT * FROM registrations WHERE LOWER(email)=? AND status='paid' ORDER BY id DESC LIMIT 1",[$email]);
        if(!$person)throw new UserError('wd_err_not_registered','email',404);
        $catalog=WorkshopCatalog::one($workshopId,true);
        return Db::transaction(static function()use($person,$workshop,$catalog):array{
            $row=Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$workshop['id']]);
            if(!$row||$row['status']!=='active')throw new UserError('wd_err_missing',null,404);
            if($row['organizer']!=='ismile')throw new UserError('wd_err_by_phone',null,409);
            if((int)$row['price']<=0)throw new UserError('wd_err_server',null,409);
            if(Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$person['id']])!=='paid')throw new UserError('wd_err_not_registered','email',404);
            GuestWorkshops::assertNoOrder((int)$person['id'],$row['id']);
            if(Db::value('SELECT id FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL',[$person['id'],$row['id']])
                ||Db::value("SELECT id FROM workshop_checkouts WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$row['id']])
                ||Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$row['id']]))throw new UserError('wd_err_already','email',409);
            if(Db::value('SELECT id FROM workshop_cash_requests WHERE registration_id=? AND workshop_id=?',[$person['id'],$row['id']]))return ['ok'=>true,'cashRequested'=>true];
            if(!$catalog||!$catalog['availabilityVerified'])throw new UserError('wd_err_server',null,503);
            if($catalog['seatsLeft']<1||Workshops::booked($row['id'])>=(int)$row['total_seats'])throw new UserError('wd_err_full',null,409);
            $id=Db::insert('workshop_cash_requests',['registration_id'=>$person['id'],'workshop_id'=>$row['id'],'amount'=>$catalog['price'],'currency'=>$catalog['currency'],'payment_method'=>'cash','created_at'=>App::now()]);
            Audit::log(null,'workshop.cash_requested','registration',(int)$person['id'],['workshop'=>$row['id'],'request'=>$id,'payment_method'=>'cash','payment_status'=>'unpaid']);
            return ['ok'=>true,'cashRequested'=>true];
        });
    }

    public static function pending(string $workshopId): array
    {
        return Db::all("SELECT c.*,r.first_name,r.father_name,r.grandfather_name,r.ref,r.phone FROM workshop_cash_requests c JOIN registrations r ON r.id=c.registration_id WHERE c.workshop_id=? AND r.status='paid' AND NOT EXISTS (SELECT 1 FROM workshop_bookings b WHERE b.registration_id=c.registration_id AND b.workshop_id=c.workshop_id AND b.removed_at IS NULL) ORDER BY c.id DESC",[$workshopId]);
    }
}
