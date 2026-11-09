<?php
declare(strict_types=1);
namespace Ismile;

/** Collected cash is saved once in IQD; PSOOLA issues a paid direct order. */
final class WorkshopCashSale
{
    public static function quote(array $workshop, ?array $catalog): array
    {
        if(!$catalog||!$catalog['availabilityVerified']||$catalog['currency']!=='IQD'||$catalog['price']<=0)throw new UserError('The current PSOOLA price could not be verified.');
        $usd=$workshop['displayPrice']??null;
        return ['iqd'=>(int)$catalog['price'],'usdMinor'=>is_array($usd)&&$usd['currency']==='USD'&&(int)$usd['amount']>0?(int)$usd['amount']*100:null];
    }

    public static function amount(string $text, string $currency): int
    {
        $text=trim($text);
        if(!in_array($currency,['IQD','USD'],true)||!preg_match($currency==='USD'?'/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/D':'/^[0-9]{1,10}$/D',$text))throw new UserError('Enter a valid cash amount: whole dinars or dollars with up to two decimal places.');
        if($currency==='IQD')$minor=(int)$text;
        else{$parts=explode('.',$text);$minor=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');}
        if($minor<=0||$minor>1000000000)throw new UserError('Enter a positive cash amount within the supported limit.');
        return $minor;
    }

    public static function register(array $person,array $user,array $in): string
    {
        if(!Auth::canBookWorkshops($user))throw new UserError('Your account cannot register workshop attendees.',null,403);
        if(($in['cash_received']??'')!=='1')throw new UserError('Confirm that you have received the cash before issuing a paid ticket.');
        $currency=(string)($in['cash_currency']??'IQD');$minor=self::amount((string)($in['cash_amount']??''),$currency);
        $workshop=Workshops::find((string)($in['workshop']??''));
        if(!$workshop||$workshop['organizer']!=='ismile'||!$workshop['providerEventId']||$workshop['currency']!=='IQD')throw new UserError('Select an iSmile workshop configured for paid PSOOLA tickets in IQD.');
        $catalog=WorkshopCatalog::one($workshop['id'],true);
        $bookingId=Db::transaction(static function()use($person,$user,$workshop,$catalog,$minor,$currency):int{
            $row=Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$workshop['id']]);
            if(!$row||$row['status']!=='active'||$row['organizer']!=='ismile')throw new UserError('This workshop is unavailable.');
            if(Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$person['id']])!=='paid')throw new UserError('A paid summit registration is required.');
            $existing=Db::one('SELECT * FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL',[$person['id'],$workshop['id']]);
            if($existing){
                $receipt=Db::one('SELECT * FROM workshop_cash_receipts WHERE booking_id=?',[$existing['id']]);
                if(!$receipt||$existing['payment_status']!=='paid'||$existing['paid_how']!=='cash'||($user['role']!=='owner'&&(int)$existing['booked_by']!==(int)$user['id'])||$receipt['currency']!==$currency||(int)$receipt['amount_minor']!==$minor)throw new UserError('This attendee is already registered for this workshop. Review the existing payment.');
                return (int)$existing['id'];
            }
            WorkshopCheckout::assertNoOpenPurchase((int)$person['id'],$workshop['id']);
            $lockedWorkshop=Workshops::find($workshop['id']);$quote=self::quote($lockedWorkshop,$catalog);
            if($currency==='USD'){
                if(!$quote['usdMinor'])throw new UserError('This workshop has no saved USD price. Collect its PSOOLA price in IQD.');
                if($minor!==$quote['usdMinor'])throw new UserError('The received dollars must equal the saved workshop price: $'.number_format($quote['usdMinor']/100,2).'.');
            }elseif($minor!==$quote['iqd'])throw new UserError('The received dinars must equal the current PSOOLA price: '.number_format($quote['iqd']).' IQD.');
            if($catalog['seatsLeft']<1||Workshops::booked($workshop['id'])>=(int)$row['total_seats'])throw new UserError('This workshop is sold out.');
            $now=App::now();
            $id=Db::insert('workshop_bookings',['registration_id'=>$person['id'],'workshop_id'=>$workshop['id'],'price_agreed'=>$quote['iqd'],'payment_status'=>'paid','amount_paid'=>$quote['iqd'],'paid_how'=>'cash','paid_at'=>$now,'paid_recorded_by'=>$user['id'],'booked_by'=>$user['id'],'over_capacity'=>0,'notes'=>'Paid cash workshop registration.','created_at'=>$now,'updated_at'=>$now]);
            Db::insert('workshop_cash_receipts',['booking_id'=>$id,'amount_minor'=>$minor,'currency'=>$currency,'quote_usd_minor'=>$quote['usdMinor'],'amount_iqd'=>$quote['iqd'],'recorded_by'=>$user['id'],'created_at'=>$now]);
            Audit::log((int)$user['id'],'workshop.cash_received','registration',(int)$person['id'],['workshop'=>$workshop['id'],'booking'=>$id,'receivedMinor'=>$minor,'receivedCurrency'=>$currency,'amountIQD'=>$quote['iqd'],'usdQuoteMinor'=>$quote['usdMinor']]);
            return $id;
        });
        Workshops::syncWebsite();
        return WorkshopTickets::issue($bookingId,$user);
    }
}
