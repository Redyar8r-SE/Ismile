<?php
declare(strict_types=1);
namespace Ismile;

/** Staff registration: collected cash uses a direct order; free tickets use verified zero-price checkout. */
final class WorkshopCashSale
{
    public static function quote(array $workshop, ?array $catalog): array
    {
        if(!$catalog||!$catalog['availabilityVerified']||$catalog['currency']!=='IQD'||$catalog['price']<0)throw new UserError('The current PSOOLA price could not be verified.');
        $usd=$workshop['displayPrice']??null;
        return ['iqd'=>(int)$catalog['price'],'usdMinor'=>$catalog['price']>0&&is_array($usd)&&$usd['currency']==='USD'&&(int)$usd['amount']>0?(int)$usd['amount']*100:null];
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
        if(($in['free_registration']??'')==='1')return self::registerFree($person,$user,$in);
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
            if(Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$workshop['id']]))throw new UserError('This attendee already has a paid workshop order or an order awaiting confirmation. Review the original ticket before collecting another payment.');
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

    private static function registerFree(array $person,array $user,array $in): string
    {
        if(($in['cash_amount']??'0')!=='0'||($in['cash_currency']??'IQD')!=='IQD')throw new UserError('No cash is collected for this 0 IQD workshop.');
        $workshop=Workshops::find((string)($in['workshop']??''));
        if(!$workshop||$workshop['organizer']!=='ismile'||$workshop['status']!=='active'||!$workshop['providerEventId']||$workshop['price']!==0)throw new UserError('Choose a free iSmile workshop configured with PSOOLA.');
        $quote=self::quote($workshop,WorkshopCatalog::one($workshop['id'],true));
        if($quote['iqd']!==0)throw new UserError('The current PSOOLA workshop price is not 0 IQD. Refresh before registering this attendee.');
        WorkshopCheckout::start($workshop['id'],(string)$person['email'],(int)$person['id'],0);
        $attempt=Db::one('SELECT * FROM workshop_checkouts WHERE registration_id=? AND workshop_id=? ORDER BY id DESC LIMIT 1',[$person['id'],$workshop['id']]);
        if(!$attempt||$attempt['provider_status']!=='paid'||(int)$attempt['amount']!==0||!$attempt['booking_id']||$attempt['fulfillment_error'])throw new UserError('PSOOLA has not confirmed the free ticket yet. Retry this same registration; no payment is required.');
        Db::transaction(static function()use($attempt,$person,$user,$workshop):void{
            $booking=Db::one('SELECT * FROM workshop_bookings WHERE id=? AND removed_at IS NULL FOR UPDATE',[$attempt['booking_id']]);
            if(!$booking||$booking['payment_status']!=='paid'||(int)$booking['amount_paid']!==0)throw new UserError('The free workshop registration needs review.');
            Db::update('workshop_bookings',['booked_by'=>$user['id'],'notes'=>'Registered by staff through PSOOLA at 0 IQD. No cash collected.','updated_at'=>App::now()],'id=?',[$booking['id']]);
            Audit::log((int)$user['id'],'workshop.staff_registered','registration',(int)$person['id'],['workshop'=>$workshop['id'],'booking'=>$booking['id'],'amount'=>0,'registrationType'=>'free']);
        });
        return 'Workshop registered at 0 IQD. No cash collected. PSOOLA sends the ticket to the attendee.';
    }
}
