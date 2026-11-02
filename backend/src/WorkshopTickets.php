<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Workshop orders use separate references and never enter summit payment accounting. */
final class WorkshopTickets
{
    public static function issue(int $bookingId,array $owner): string
    {
        if(($owner['role']??'')!=='owner')throw new UserError('Only the Owner can issue workshop tickets.');
        $booking=Db::one('SELECT * FROM workshop_bookings WHERE id=? AND removed_at IS NULL',[$bookingId]);
        if(!$booking || $booking['payment_status']!=='paid')throw new UserError('Record the collected workshop payment before issuing a ticket.');
        $registration=Db::one("SELECT * FROM registrations WHERE id=? AND status='paid'",[$booking['registration_id']]);
        if(!$registration)throw new UserError('A paid summit registration is required before a workshop ticket can be issued.');
        $workshop=Workshops::find($booking['workshop_id']);
        if(!$workshop || !$workshop['providerEventId'])throw new UserError('This workshop is not configured for PSOOLA tickets.');
        $existing=Db::one('SELECT * FROM workshop_ticket_orders WHERE booking_id=?',[$bookingId]);
        if($existing && $existing['provider_session_id']){
            self::reconcile($existing['provider_session_id'],$existing['partner_reference']);
            $existing=Db::one('SELECT * FROM workshop_ticket_orders WHERE id=?',[$existing['id']]);
            if($existing['provider_status']==='paid')return 'Workshop ticket already issued. PSOOLA sends the ticket to the attendee.';
            throw new UserError('Workshop order status: '.$existing['provider_status'].'. Review it before creating another booking.');
        }
        if(!$existing){
            $catalog=WorkshopCatalog::one($workshop['id'],true);
            if(!$catalog || !$catalog['availabilityVerified'])throw new UserError('Current workshop availability could not be verified. Try again later.');
            if($catalog['seatsLeft']<1)throw new UserError('PSOOLA reports that this workshop is sold out.');
            if((int)$booking['amount_paid']!==$catalog['price'] || $workshop['currency']!=='IQD')throw new UserError('The collected amount must match the current PSOOLA workshop price.');
        }
        $attempt=Db::transaction(static function()use($bookingId,$workshop,$registration):array{
            $locked=Db::one('SELECT * FROM workshop_bookings WHERE id=? AND removed_at IS NULL FOR UPDATE',[$bookingId]);
            if(!$locked || $locked['payment_status']!=='paid' || Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$registration['id']])!=='paid')throw new UserError('A paid summit registration and collected workshop payment are required.');
            $saved=Db::one('SELECT * FROM workshop_ticket_orders WHERE booking_id=?',[$bookingId]);
            if($saved)return $saved;
            if(Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$registration['id'],$workshop['id']]))throw new UserError('This attendee already has a workshop ticket or an order awaiting confirmation.');
            $phone=Validate::phone((string)$registration['phone']);
            if(!$phone || !preg_match('/^\+[1-9][0-9]{6,14}$/D',$phone))throw new UserError('A valid international attendee phone number is required.');
            $reference='ismile-workshop-booking-'.$bookingId;
            $body=['eventId'=>$workshop['providerEventId'],'showId'=>$workshop['providerShowId'],'items'=>[['ticketTypeId'=>$workshop['providerTicketTypeId'],'quantity'=>1]],'buyer'=>['name'=>Registrations::fullName($registration),'email'=>strtolower($registration['email']),'phone'=>$phone],'locale'=>($registration['lang']??'en')==='ku'?'ckb':(in_array($registration['lang']??'en',['en','ar'],true)?($registration['lang']??'en'):'en'),'partnerReference'=>$reference];
            $id=Db::insert('workshop_ticket_orders',['booking_id'=>$bookingId,'registration_id'=>$registration['id'],'workshop_id'=>$workshop['id'],'idempotency_key'=>'ismile-workshop-'.bin2hex(random_bytes(16)),'partner_reference'=>$reference,'request_json'=>json_encode($body,JSON_THROW_ON_ERROR),'created_at'=>App::now(),'updated_at'=>App::now()]);
            return Db::one('SELECT * FROM workshop_ticket_orders WHERE id=?',[$id]);
        });
        try{
            $answer=(new PsoolaGateway())->workshopRequest('POST','orders',json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR),['Idempotency-Key: '.$attempt['idempotency_key']]);
            if(!in_array($answer['status'],[200,201],true))throw new \RuntimeException('Workshop order HTTP '.$answer['status']);
            $session=$answer['json']??[];
            self::validate($attempt,$session);
            Db::update('workshop_ticket_orders',['provider_session_id'=>$session['id'],'updated_at'=>App::now()],'id=?',[$attempt['id']]);
            self::reconcile($session['id'],$attempt['partner_reference']);
            $after=Db::one('SELECT * FROM workshop_ticket_orders WHERE id=?',[$attempt['id']]);
            if($after['provider_status']!=='paid')throw new \RuntimeException('Workshop ticket payment is not confirmed.');
            Audit::log((int)$owner['id'],'workshop.ticket_issued','registration',(int)$registration['id'],['booking'=>$bookingId,'workshop'=>$workshop['id'],'order'=>$session['id']]);
            return 'Workshop ticket issued. PSOOLA sends the ticket to the attendee.';
        }catch(\Throwable $error){
            Db::run("UPDATE workshop_ticket_orders SET provider_status=IF(provider_status='paid','paid','unknown'),last_error=?,updated_at=? WHERE id=?",[mb_substr($error->getMessage(),0,500),App::now(),$attempt['id']]);
            App::log('error','Workshop ticket issue could not be confirmed',['booking'=>$bookingId,'error'=>$error->getMessage()]);
            throw new UserError('The workshop ticket could not be confirmed. Retry this same booking; its saved order key prevents duplicate purchases.');
        }
    }

    public static function validate(array $attempt,array $session): void
    {
        $request=json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_string($session['id']??null) || $session['id']==='' || ($session['partnerReference']??null)!==$attempt['partner_reference'] || !PsoolaGateway::sameItems($request['items'],$session['order']['items']??null) || !is_int($session['order']['totalAmount']??null) || $session['order']['totalAmount']<1 || ($session['order']['currency']??null)!=='IQD')throw new \RuntimeException('Workshop order identity, ticket, total or currency mismatch.');
        $booking=Db::one('SELECT amount_paid FROM workshop_bookings WHERE id=?',[$attempt['booking_id']]);
        if((int)$booking['amount_paid']!==$session['order']['totalAmount'])throw new \RuntimeException('Workshop order total does not match the collected amount.');
        if($attempt['provider_session_id'] && $attempt['provider_session_id']!==$session['id'])throw new \RuntimeException('Workshop session mismatch.');
    }

    /** Called only after the existing webhook signature check, or by the Owner. */
    public static function reconcile(string $sessionId,?string $reference=null,?string $webhookId=null): bool
    {
        $attempt=Db::one('SELECT * FROM workshop_ticket_orders WHERE provider_session_id=? OR (? IS NOT NULL AND partner_reference=?)',[$sessionId,$reference,$reference]);
        if(!$attempt)return false;
        if($webhookId && $attempt['last_webhook_id']===$webhookId)return true;
        $response=(new PsoolaGateway())->fetchStatus($sessionId);
        $session=json_decode($response['raw'],true,512,JSON_THROW_ON_ERROR);
        self::validate($attempt,$session);
        $status=$session['status']??'';
        if(!in_array($status,['awaiting_payment','paid','expired','cancelled','refunded'],true))throw new \RuntimeException('Unknown workshop order status.');
        Db::update('workshop_ticket_orders',['provider_session_id'=>$sessionId,'provider_status'=>$status,'amount'=>$session['order']['totalAmount'],'currency'=>'IQD','raw_response'=>$response['raw'],'last_webhook_id'=>$webhookId,'last_error'=>null,'updated_at'=>App::now()],'id=?',[$attempt['id']]);
        @unlink(App::storage('tmp/workshop-catalog-'.$attempt['workshop_id'].'.json'));
        return true;
    }
}
