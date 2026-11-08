<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Online attempts stay separate from workshop registrations until PSOOLA verifies payment. */
final class WorkshopCheckout
{
    private const OPEN = ['created','unknown','awaiting_payment'];

    public static function start(string $workshopId, string $email): array
    {
        $email = strtolower(trim($email));
        if (!Validate::email($email)) throw new UserError('wd_err_email','email');
        $workshop = Workshops::find($workshopId);
        if (!$workshop || $workshop['status'] !== 'active') throw new UserError('wd_err_missing',null,404);
        if ($workshop['organizer'] === 'company') throw new UserError('wd_err_by_phone',null,409);
        if ($workshop['price'] === 0 && !$workshop['providerEventId']) return self::registerFree($workshop,$email);
        if (!$workshop['providerEventId']) throw new UserError('wd_err_server',null,503);
        $person = Db::one("SELECT * FROM registrations WHERE LOWER(email)=? AND status='paid' ORDER BY id DESC LIMIT 1",[$email]);
        if (!$person) throw new UserError('wd_err_not_registered','email',404);
        // An existing attempt is checked before making a new one. Lost responses keep the same key/body.
        $previous = self::latest((int)$person['id'],$workshopId);
        if ($previous && $previous['provider_session_id'] && in_array($previous['provider_status'],self::OPEN,true)) {
            self::reconcile($previous['provider_session_id'],$previous['partner_reference']);
        }
        $catalog = WorkshopCatalog::one($workshopId);
        $attempt = Db::transaction(static function()use($person,$workshop,$catalog):array {
            $row = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$workshop['id']]);
            if (!$row || $row['status'] !== 'active') throw new UserError('wd_err_missing',null,404);
            if ($row['organizer'] === 'company') throw new UserError('wd_err_by_phone',null,409);
            GuestWorkshops::assertNoOrder((int)$person['id'], $workshop['id']);
            if (Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$person['id']]) !== 'paid') throw new UserError('wd_err_not_registered','email',404);
            $saved = self::latest((int)$person['id'],$workshop['id']);
            if ($saved && $saved['provider_status'] === 'paid') throw new UserError('wd_err_already','email',409);
            $booking = Db::one('SELECT * FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL FOR UPDATE',[$person['id'],$workshop['id']]);
            if ($booking && $booking['payment_status'] !== 'unpaid') throw new UserError('wd_err_already','email',409);
            if (Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$workshop['id']])) throw new UserError('wd_err_already','email',409);
            if ($saved && in_array($saved['provider_status'],self::OPEN,true)) return $saved;
            if (!$catalog || !$catalog['availabilityVerified']) throw new UserError('wd_err_server',null,503);
            if ($catalog['seatsLeft'] < 1) throw new UserError('wd_err_full',null,409);
            $phone = Validate::phone((string)$person['phone']);
            if (!$phone || !preg_match('/^\+[1-9][0-9]{6,14}$/D',$phone)) throw new UserError('wd_err_phone','email');
            $token = bin2hex(random_bytes(32));
            $reference = 'ismile-workshop-online-'.bin2hex(random_bytes(16));
            $lang = (string)($person['lang'] ?? 'en');
            $body = [
                'eventId'=>$row['provider_event_id'],'showId'=>$row['provider_show_id'],
                'items'=>[['ticketTypeId'=>$row['provider_ticket_type_id'],'quantity'=>1]],
                'buyer'=>['name'=>Registrations::fullName($person),'email'=>strtolower($person['email']),'phone'=>$phone],
                'locale'=>$lang === 'ku' ? 'ckb' : (in_array($lang,['en','ar'],true) ? $lang : 'en'),
                'partnerReference'=>$reference,
                'returnUrl'=>'https://ismile.krd/workshop.html?id='.rawurlencode($workshop['id']).'&payment='.$token,
            ];
            $id = Db::insert('workshop_checkouts',[
                'registration_id'=>$person['id'],'workshop_id'=>$workshop['id'],'public_token'=>$token,
                'idempotency_key'=>'ismile-ws-online-'.bin2hex(random_bytes(16)),
                'partner_reference'=>$reference,'request_json'=>json_encode($body,JSON_THROW_ON_ERROR),
                'amount'=>$catalog['price'] === 0 ? 0 : null,'currency'=>'IQD',
                'created_at'=>App::now(),'updated_at'=>App::now(),
            ]);
            return Db::one('SELECT * FROM workshop_checkouts WHERE id=?',[$id]);
        });
        if (!$attempt['provider_session_id']) $attempt = self::create($attempt);
        if ($attempt['provider_status'] === 'paid') {
            $tickets = (int)$attempt['amount'] === 0 && $attempt['booking_id'] && !$attempt['fulfillment_error'] && self::safeCheckoutUrl($attempt['checkout_url']);
            return ['ok'=>true,'redirect'=>$tickets ? $attempt['checkout_url'] : self::resultUrl($attempt)];
        }
        if ($attempt['provider_status'] !== 'awaiting_payment' || !self::safeCheckoutUrl($attempt['checkout_url'])) throw new UserError('wd_err_server',null,503);
        return ['ok'=>true,'redirect'=>$attempt['checkout_url']];
    }

    /** Free iSmile workshops reserve local inventory without issuing a chargeable provider order. */
    private static function registerFree(array $workshop,string $email): array
    {
        $person=Db::one("SELECT * FROM registrations WHERE LOWER(email)=? AND status='paid' ORDER BY id DESC LIMIT 1",[$email]);
        if(!$person)throw new UserError('wd_err_not_registered','email',404);
        $attempt=Db::transaction(static function()use($workshop,$person):array{
            $row=Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$workshop['id']]);
            if(!$row||$row['status']!=='active')throw new UserError('wd_err_missing',null,404);
            if($row['organizer']!=='ismile')throw new UserError('wd_err_by_phone',null,409);
            if((int)$row['price']!==0||$row['provider_event_id']||$row['provider_show_id']||$row['provider_ticket_type_id'])throw new UserError('wd_err_server',null,503);
            if(Db::value('SELECT status FROM registrations WHERE id=? LOCK IN SHARE MODE',[$person['id']])!=='paid')throw new UserError('wd_err_not_registered','email',404);
            GuestWorkshops::assertNoOrder((int)$person['id'],$row['id']);
            if(Db::value('SELECT id FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL',[$person['id'],$row['id']])
                ||Db::value("SELECT id FROM workshop_checkouts WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$row['id']])
                ||Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')",[$person['id'],$row['id']]))throw new UserError('wd_err_already','email',409);
            if(Workshops::booked($row['id'])>=(int)$row['total_seats'])throw new UserError('wd_err_full',null,409);
            $now=App::now();
            $bookingId=Db::insert('workshop_bookings',['registration_id'=>$person['id'],'workshop_id'=>$row['id'],'price_agreed'=>0,'payment_status'=>'paid','amount_paid'=>0,'paid_how'=>'free','paid_at'=>$now,'paid_recorded_by'=>null,'booked_by'=>null,'over_capacity'=>0,'notes'=>'Free online workshop registration. No payment required.','created_at'=>$now,'updated_at'=>$now]);
            $id=Db::insert('workshop_checkouts',['registration_id'=>$person['id'],'workshop_id'=>$row['id'],'booking_id'=>$bookingId,'public_token'=>bin2hex(random_bytes(32)),'idempotency_key'=>'ismile-ws-free-'.bin2hex(random_bytes(16)),'partner_reference'=>'ismile-workshop-free-'.bin2hex(random_bytes(16)),'request_json'=>json_encode(['workshopId'=>$row['id'],'registrationId'=>(int)$person['id'],'registrationType'=>'free'],JSON_THROW_ON_ERROR),'provider_status'=>'paid','amount'=>0,'currency'=>'IQD','last_checked_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            Audit::log(null,'workshop.online_registered','registration',(int)$person['id'],['workshop'=>$row['id'],'booking'=>$bookingId,'amount'=>0,'registrationType'=>'free']);
            return Db::one('SELECT * FROM workshop_checkouts WHERE id=?',[$id]);
        });
        Workshops::syncWebsite();
        return ['ok'=>true,'redirect'=>self::resultUrl($attempt)];
    }

    private static function latest(int $personId, string $workshopId): ?array
    {
        return Db::one('SELECT * FROM workshop_checkouts WHERE registration_id=? AND workshop_id=? ORDER BY id DESC LIMIT 1',[$personId,$workshopId]);
    }

    public static function assertNoOpenPurchase(int $personId, string $workshopId): void
    {
        GuestWorkshops::assertNoOrder($personId, $workshopId);
        if (Db::value("SELECT id FROM workshop_checkouts WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid') LIMIT 1",[$personId,$workshopId])) {
            throw new UserError('This attendee already has an online PSOOLA workshop payment. Verify or finish it before recording another payment.');
        }
    }

    private static function create(array $attempt): array
    {
        try {
            $answer = (new PsoolaGateway())->workshopRequest('POST','checkout-sessions',json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR),['Idempotency-Key: '.$attempt['idempotency_key']]);
            if (!in_array($answer['status'],[200,201],true)) {
                // A definite client refusal can be retried as a genuinely new attempt. Ambiguous outcomes retain their key.
                $code = $answer['json']['error']['code'] ?? '';
                if ($code === 'INSUFFICIENT_INVENTORY' || $code === 'SALES_CLOSED' || $code === 'TICKET_TYPE_NOT_AVAILABLE') {
                    Db::update('workshop_checkouts',['provider_status'=>'cancelled','last_error'=>(string)$code,'updated_at'=>App::now()],'id=?',[$attempt['id']]);
                    throw new UserError('wd_err_full',null,409);
                }
                throw new \RuntimeException('Workshop checkout HTTP '.$answer['status']);
            }
            $session = $answer['json'] ?? [];
            self::validate($attempt,$session);
            $ticketsUrl = $session['ticketsUrl'] ?? $session['checkoutUrl'] ?? null;
            if (($session['status'] ?? '') === 'paid') {
                $verified = (new PsoolaGateway())->fetchStatus($session['id']);
                $session = json_decode($verified['raw'],true,512,JSON_THROW_ON_ERROR);
                self::validate($attempt,$session);
            }
            if (($session['status'] ?? '') === 'paid' && $session['order']['totalAmount'] === 0 && self::safeCheckoutUrl($ticketsUrl)) $session['checkoutUrl'] = $ticketsUrl;
            if (($session['status'] ?? '') === 'awaiting_payment' && !self::safeCheckoutUrl($session['checkoutUrl'] ?? null)) throw new \RuntimeException('Workshop checkout address is invalid.');
            self::apply($attempt,$session);
            @unlink(App::storage('tmp/workshop-catalog-'.$attempt['workshop_id'].'.json'));
            return Db::one('SELECT * FROM workshop_checkouts WHERE id=?',[$attempt['id']]);
        } catch (UserError $error) {
            throw $error;
        } catch (\Throwable $error) {
            Db::run("UPDATE workshop_checkouts SET provider_status='unknown',last_error=?,updated_at=? WHERE id=? AND provider_session_id IS NULL",[mb_substr($error->getMessage(),0,500),App::now(),$attempt['id']]);
            App::log('error','Workshop online checkout could not be confirmed',['attempt'=>$attempt['id'],'error'=>$error->getMessage()]);
            throw new UserError('wd_err_server',null,503);
        }
    }

    private static function safeCheckoutUrl(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $url = parse_url($value);
        return is_array($url) && ($url['scheme'] ?? '') === 'https' && !isset($url['user']) && !isset($url['pass'])
            && preg_match('/(^|\.)psoola\.com$/Di',(string)($url['host'] ?? '')) === 1 && (!isset($url['port']) || $url['port'] === 443);
    }

    private static function resultUrl(array $attempt): string
    {
        return 'https://ismile.krd/workshop.html?id='.rawurlencode($attempt['workshop_id']).'&payment='.$attempt['public_token'];
    }

    public static function validate(array $attempt, array $session): void
    {
        $request = json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_string($session['id'] ?? null) || $session['id'] === '' || ($session['partnerReference'] ?? null) !== $attempt['partner_reference']
            || !PsoolaGateway::sameItems($request['items'],$session['order']['items'] ?? null)
            || !is_int($session['order']['totalAmount'] ?? null) || $session['order']['totalAmount'] < 0
            || ($session['order']['totalAmount'] === 0 && (!isset($attempt['amount']) || (int)$attempt['amount'] !== 0))
            || ($session['order']['currency'] ?? null) !== 'IQD'
            || ($attempt['provider_session_id'] && $attempt['provider_session_id'] !== $session['id'])
            || ($attempt['amount'] !== null && (int)$attempt['amount'] !== $session['order']['totalAmount'])
            || (isset($session['eventId']) && $session['eventId'] !== $request['eventId'])
            || (isset($session['showId']) && $session['showId'] !== $request['showId'])) {
            throw new \RuntimeException('Workshop checkout identity, items, amount or currency mismatch.');
        }
    }

    /** A return URL never confirms payment: only this authenticated provider lookup does. */
    public static function reconcile(string $sessionId, ?string $reference = null, ?string $webhookId = null): bool
    {
        $attempt = Db::one('SELECT * FROM workshop_checkouts WHERE provider_session_id=? OR (? IS NOT NULL AND partner_reference=?)',[$sessionId,$reference,$reference]);
        if (!$attempt) return false;
        if ($webhookId && $attempt['last_webhook_id'] === $webhookId) return true;
        $response = (new PsoolaGateway())->fetchStatus($sessionId);
        $session = json_decode($response['raw'],true,512,JSON_THROW_ON_ERROR);
        self::validate($attempt,$session);
        self::apply($attempt,$session,$webhookId);
        @unlink(App::storage('tmp/workshop-catalog-'.$attempt['workshop_id'].'.json'));
        return true;
    }

    private static function apply(array $attempt, array $session, ?string $webhookId = null): void
    {
        $status = $session['status'] ?? '';
        if (!in_array($status,['awaiting_payment','paid','expired','cancelled','refunded'],true)) throw new \RuntimeException('Unknown workshop checkout status.');
        Db::transaction(static function()use($attempt,$session,$status,$webhookId):void {
            // Same workshop lock as office bookings and online checkout creation.
            $workshop = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$attempt['workshop_id']]);
            $person = Db::one('SELECT * FROM registrations WHERE id=? LOCK IN SHARE MODE',[$attempt['registration_id']]);
            $locked = Db::one('SELECT * FROM workshop_checkouts WHERE id=? FOR UPDATE',[$attempt['id']]);
            self::validate($locked,$session);
            if ($webhookId && $locked['last_webhook_id'] === $webhookId) return;
            // A response fetched before another request must not undo an already confirmed payment/refund.
            if ($locked['provider_status'] === 'refunded' && $status !== 'refunded') return;
            if ($locked['provider_status'] === 'paid' && in_array($status,['awaiting_payment','expired','cancelled'],true)) return;
            $now = App::now();
            $changes = ['provider_session_id'=>$session['id'],'provider_status'=>$status,'amount'=>$session['order']['totalAmount'],'currency'=>'IQD',
                'raw_response'=>PsoolaGateway::withoutLinks(json_encode($session,JSON_THROW_ON_ERROR)),
                'last_checked_at'=>$now,'last_error'=>null,'updated_at'=>$now];
            if ($webhookId) $changes['last_webhook_id'] = $webhookId;
            if (self::safeCheckoutUrl($session['checkoutUrl'] ?? null)) $changes['checkout_url'] = $session['checkoutUrl'];
            if (isset($session['expiresAt'])) {
                $expiry = $session['expiresAt'];
                $stamp = is_numeric($expiry) ? (int)floor((float)$expiry / ((float)$expiry > 100000000000 ? 1000 : 1)) : strtotime((string)$expiry);
                if ($stamp) $changes['expires_at'] = date('Y-m-d H:i:s',$stamp);
            }
            if ($status === 'paid' && !$locked['booking_id']) {
                $booking = Db::one('SELECT * FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL FOR UPDATE',[$person['id'],$workshop['id']]);
                if ($person['status'] !== 'paid' || ($booking && $booking['payment_status'] !== 'unpaid')) {
                    $changes['fulfillment_error'] = 'Payment received; summit eligibility or a duplicate booking needs owner review.';
                    App::log('error','Paid workshop checkout needs review',['attempt'=>$locked['id']]);
                } else {
                    $paid = ['price_agreed'=>$session['order']['totalAmount'],'payment_status'=>'paid','amount_paid'=>$session['order']['totalAmount'],
                        'paid_how'=>'psoola','paid_at'=>$now,'paid_recorded_by'=>null,'updated_at'=>$now];
                    if ($booking) {
                        Db::update('workshop_bookings',$paid,'id=?',[$booking['id']]);
                        $bookingId = (int)$booking['id'];
                    } else {
                        $bookingId = Db::insert('workshop_bookings',$paid+['registration_id'=>$person['id'],'workshop_id'=>$workshop['id'],
                            'notes'=>$session['order']['totalAmount'] === 0 ? 'Registered online through PSOOLA. No workshop payment required.' : 'Paid online through PSOOLA.','over_capacity'=>Workshops::booked($workshop['id']) >= (int)$workshop['total_seats'] ? 1 : 0,'created_at'=>$now]);
                    }
                    $changes['booking_id'] = $bookingId;
                    $changes['fulfillment_error'] = null;
                    Audit::log(null,'workshop.online_paid','registration',(int)$person['id'],['workshop'=>$workshop['id'],'booking'=>$bookingId,'session'=>$session['id'],'amount'=>$session['order']['totalAmount']]);
                }
            }
            if ($status === 'refunded' && $locked['booking_id']) {
                Db::run("UPDATE workshop_bookings SET removed_at=COALESCE(removed_at,?),updated_at=?,notes=LEFT(CONCAT(COALESCE(notes,''),' PSOOLA payment refunded.'),500) WHERE id=? AND removed_at IS NULL",[$now,$now,$locked['booking_id']]);
            }
            Db::update('workshop_checkouts',$changes,'id=?',[$locked['id']]);
        });
        Workshops::syncWebsite();
    }

    public static function status(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new UserError('wd_err_missing',null,404);
        $attempt = Db::one('SELECT * FROM workshop_checkouts WHERE public_token=?',[$token]);
        if (!$attempt) throw new UserError('wd_err_missing',null,404);
        if ($attempt['provider_session_id'] && in_array($attempt['provider_status'],self::OPEN,true)
            && (!$attempt['last_checked_at'] || time() - (int)strtotime($attempt['last_checked_at']) >= 20)) {
            self::reconcile($attempt['provider_session_id'],$attempt['partner_reference']);
            $attempt = Db::one('SELECT * FROM workshop_checkouts WHERE id=?',[$attempt['id']]);
        }
        $confirmed = $attempt['provider_status'] === 'paid' && $attempt['booking_id'] && !$attempt['fulfillment_error'];
        if ($confirmed && !Db::value("SELECT id FROM workshop_bookings WHERE id=? AND removed_at IS NULL AND payment_status='paid'",[$attempt['booking_id']])) $confirmed = false;
        $status = $attempt['provider_status'] === 'paid' && !$confirmed ? 'review' : $attempt['provider_status'];
        return ['ok'=>true,'workshopId'=>$attempt['workshop_id'],'status'=>$status,'confirmed'=>(bool)$confirmed,
            'checkoutUrl'=>$status === 'awaiting_payment' && self::safeCheckoutUrl($attempt['checkout_url']) ? $attempt['checkout_url'] : null];
    }
}
