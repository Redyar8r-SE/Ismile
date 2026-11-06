<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Explicit complimentary orders. No cash request, price override or checkout fallback. */
final class GuestOrders
{
    public const LOCALES=['en','ar','ckb','kmr'];

    public static function enabled(): bool
    {
        $flag=getenv('PSOOLA_COMPLIMENTARY_ENABLED');
        return $flag!==false?in_array(strtolower($flag),['1','true'],true):(bool)App::config('payments.psoola.complimentary_enabled',false);
    }

    public static function guest(array $in): array
    {
        $name=trim((string)(is_string($in['buyer_name']??null)?$in['buyer_name']:implode(' ',array_map(static fn($key)=>Validate::text($in[$key]??'',60),['first_name','father_name','grandfather_name']))));
        if (mb_strlen($name)>120) throw new UserError('Guest name must be 120 characters or fewer.');
        $name=Validate::text($name,120);
        $email=strtolower(Validate::text($in['email']??'',191));
        if (!Validate::email($email)) throw new UserError('Enter a valid guest email address.');
        $phone=Validate::phone(Validate::text($in['phone']??'',30));
        if (!$phone || !preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)) throw new UserError('Enter a valid international phone number, such as +9647501234567.');
        $reason=Validate::text($in['complimentary_reason']??'',501);
        if ($reason==='' || mb_strlen($reason)>500) throw new UserError('Enter a complimentary reason from 1 to 500 characters.');
        $locale=$in['locale']??'en'; if ($locale==='ku') $locale='ckb';
        if (!is_string($locale) || !in_array($locale,self::LOCALES,true)) throw new UserError('Choose a supported ticket language.');
        $company=Validate::text($in['sponsoring_company']??'',161);
        if (mb_strlen($company)>160) throw new UserError('Sponsoring company must be 160 characters or fewer.');
        return ['buyer_name'=>$name,'email'=>$email,'phone'=>$phone,'complimentary_reason'=>$reason,
            'locale'=>$locale,'sponsoring_company'=>$company,'specialty'=>Validate::oneOf($in['specialty']??null,Registrations::SPECIALTIES,'gp')];
    }

    public static function issue(array $user,array $in): array
    {
        self::authorize($user);
        $key=$in['attempt']??'';
        if (!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new UserError('Reload the guest ticket form before submitting.');
        $saved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$key]);
        if ($saved && !(int)$saved['is_complimentary']) throw new UserError('This is an old cash-order request. It cannot be retried as complimentary. Start a new Guest tickets request.');
        $guest=self::guest($in);$selection=GuestTicketCatalog::selection($in);
        $consent=($in['whatsapp_consent']??'')==='1';
        $fingerprint=hash('sha256',json_encode([$guest,$selection,$consent],JSON_THROW_ON_ERROR));
        if ($saved) {
            self::access($user,$saved);
            if (!hash_equals($saved['fingerprint'],$fingerprint)) throw new UserError('The original guest order has different details. Reconcile or retry its saved request; do not replace its key.');
            return self::send($saved);
        }
        if (!self::enabled()) throw new UserError('Complimentary issuance needs setup: confirm PSOOLA partner permission and an authorized staging test before enabling it. No order was submitted.');
        if (!Settings::bool('registration_open')) throw new UserError('Registration is closed in Settings.');
        $catalogue=GuestTicketCatalog::verify($selection);
        $reference=Checkouts::newReference();
        $buyer=['email'=>$guest['email'],'phone'=>$guest['phone']];
        if ($guest['buyer_name']!=='') $buyer['name']=$guest['buyer_name'];
        if ($consent) $buyer['whatsappConsent']=['acknowledged'=>true,'receivedAt'=>(int)floor(microtime(true)*1000),
            'source'=>'other','reference'=>'ismile-guest-consent-'.$key,'categories'=>['utility']];
        // Construct this on the server. Never merge a posted request body or payment fields.
        $request=$selection+['buyer'=>$buyer,'locale'=>$guest['locale'],'partnerReference'=>$reference,
            'issuanceType'=>'complimentary','complimentaryReason'=>$guest['complimentary_reason']];
        self::assertRequest($request);
        try {
            $saved=Db::transaction(static function()use($user,$key,$reference,$guest,$selection,$request,$fingerprint,$catalogue):array{
                Db::one('SELECT id FROM admin_users WHERE id=? FOR UPDATE',[$user['id']]);
                $existing=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$key]);
                if ($existing) return $existing;
                $guestKey=hash('sha256',json_encode([$guest['email'],$guest['phone'],$selection,$guest['complimentary_reason']],JSON_THROW_ON_ERROR));
                $now=App::now();
                $id=Db::insert('sponsored_ticket_orders',['attempt_key'=>$key,'partner_reference'=>$reference,'created_by'=>(int)$user['id'],
                    'guest_key'=>$guestKey,'active_guest_key'=>$guestKey,'fingerprint'=>$fingerprint,
                    'guest_json'=>json_encode($guest,JSON_THROW_ON_ERROR),'request_json'=>json_encode($request,JSON_THROW_ON_ERROR),
                    'is_complimentary'=>1,'complimentary_reason'=>$guest['complimentary_reason'],'event_id'=>$selection['eventId'],'show_id'=>$selection['showId'],
                    'event_name'=>self::label($catalogue['event']['name'],$guest['locale']),'show_name'=>self::label($catalogue['show']['name'],$guest['locale']),
                    'ticket_quantity'=>$catalogue['quantity'],'created_at'=>$now,'updated_at'=>$now]);
                return Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$id]);
            });
        } catch (\PDOException $error) {
            if (($error->errorInfo[1]??0)!==1062) throw $error;
            $saved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$key]);
            if (!$saved) throw new UserError('An identical guest order already exists. Open it in order history instead of issuing again.');
        }
        self::access($user,$saved);
        if (!hash_equals($saved['fingerprint'],$fingerprint)) throw new UserError('The saved order has different details.');
        return self::send($saved);
    }

    public static function retry(array $user,string $key): array
    {
        $saved=self::byKey($user,$key);
        self::assertRequest(json_decode($saved['request_json'],true,512,JSON_THROW_ON_ERROR));
        return self::send($saved);
    }

    public static function assertRequest(array $request): void
    {
        if (($request['issuanceType']??null)!=='complimentary' || !is_string($request['complimentaryReason']??null)
            || trim($request['complimentaryReason'])==='' || mb_strlen($request['complimentaryReason'])>500) throw new UserError('Only an explicit complimentary request with a reason can be issued here.');
        if (array_diff(array_keys($request),['eventId','showId','items','buyer','locale','partnerReference','issuanceType','complimentaryReason'])) throw new UserError('Guest orders cannot contain prices, totals or payment details.');
        foreach ($request['items']??[] as $item) if (!is_array($item)||array_diff(array_keys($item),['ticketTypeId','quantity','addOnIds'])) throw new UserError('Guest ticket items cannot contain prices or payment details.');
    }

    private static function send(array $saved): array
    {
        $request=json_decode($saved['request_json'],true,512,JSON_THROW_ON_ERROR);
        self::assertRequest($request);
        if (!(int)$saved['is_complimentary']) throw new UserError('Legacy cash requests cannot be reissued as guest tickets.');
        if ($saved['status']==='issued') return $saved;
        if (!self::enabled()) throw new UserError('Complimentary issuance is disabled until its PSOOLA setup and staging verification are complete. No order was submitted.');
        if ($saved['status']==='rejected') throw new UserError('PSOOLA rejected this request. Review the saved error. Corrected details require an explicit new request, not a changed retry.');
        if ($saved['retry_after'] && strtotime($saved['retry_after'])>time()) throw new UserError('PSOOLA asked us to wait. Retry after '.$saved['retry_after'].' (Iraq time).');
        $gateway=new PsoolaGateway();
        // Recovery is read-only and happens before a retry of an uncertain POST.
        if ($saved['status']==='uncertain' || (int)$saved['attempts']>0) {
            try {
                $lookup=$gateway->partnerRequest('GET','orders?'.http_build_query(['partnerReference'=>$saved['partner_reference']]));
                if ($lookup['status']===200) return self::recover($saved,$lookup['json']??[]);
                if ($lookup['status']===429) { self::waitUntil($saved,$lookup); throw self::error($lookup); }
            } catch (UserError $error) { throw $error; }
            catch (\Throwable) { /* Replaying the identical key remains safe. */ }
        }
        for ($attempt=0;$attempt<2;$attempt++) {
            Db::run('UPDATE sponsored_ticket_orders SET attempts=attempts+1,updated_at=? WHERE id=?',[App::now(),$saved['id']]);
            try {
                $answer=$gateway->partnerRequest('POST','orders',$request,['Idempotency-Key: ismile-guest-'.$saved['attempt_key']]);
            } catch (\Throwable) { $answer=['status'=>0,'json'=>null,'retry_after'=>null]; }
            $support=self::requestId($answer);
            if (in_array($answer['status'],[200,201],true)) {
                try { return self::complete($saved,$answer['json']??[]); }
                catch (\Throwable) {
                    self::uncertain($saved,'ZERO_TOTAL_VERIFICATION_FAILED',$support);
                    throw new UserError('PSOOLA did not confirm a zero-total complimentary order. No paid fallback was attempted. Reconcile this saved request with PSOOLA.'.self::support($support));
                }
            }
            $code=is_string($answer['json']['error']['code']??null)?$answer['json']['error']['code']:'';
            $transient=$answer['status']===0 || $answer['status']>=500 || $code==='INTERNAL_ERROR';
            if ($answer['status']===429 || $code==='RATE_LIMITED') {
                self::uncertain($saved,'RATE_LIMITED',$support); self::waitUntil($saved,$answer); throw self::error($answer);
            }
            if (!$transient) {
                $conflict=$code==='IDEMPOTENCY_CONFLICT';
                if ($conflict) self::uncertain($saved,$code,$support);
                else Db::update('sponsored_ticket_orders',['status'=>'rejected','active_guest_key'=>null,'last_error'=>$code?:'HTTP_'.$answer['status'],
                    'support_request_id'=>$support,'updated_at'=>App::now()],'id=? AND status<>?',[$saved['id'],'issued']);
                throw self::error($answer);
            }
            self::uncertain($saved,$code?:'NETWORK_OR_PROVIDER_ERROR',$support);
            if (($answer['retry_after']??0)>0) {self::waitUntil($saved,$answer);throw new UserError('PSOOLA asked us to wait before retrying this saved request.'.self::support($support));}
            try {
                $lookup=$gateway->partnerRequest('GET','orders?'.http_build_query(['partnerReference'=>$saved['partner_reference']]));
                if ($lookup['status']===200) return self::recover($saved,$lookup['json']??[]);
                if ($lookup['status']===429) { self::waitUntil($saved,$lookup); throw self::error($lookup); }
            } catch (UserError $error) { throw $error; }
            catch (\Throwable) { /* The saved request is retained for the bounded retry. */ }
            if ($attempt===0) usleep(300000);
        }
        throw new UserError('Issuance is awaiting reconciliation. Retry the saved request in order history; do not start a duplicate.');
    }

    public static function validate(array $saved,array $session): void
    {
        $request=json_decode($saved['request_json'],true,512,JSON_THROW_ON_ERROR);self::assertRequest($request);
        $quantity=array_sum(array_column($request['items'],'quantity'));
        if (!is_string($session['id']??null)||$session['id']===''||strlen($session['id'])>190
            || ($session['partnerReference']??null)!==$saved['partner_reference'] || ($session['status']??null)!=='paid'
            || ($session['channel']??null)!=='direct' || ($session['isComplimentary']??null)!==true
            || ($session['complimentaryReason']??null)!==$request['complimentaryReason']
            || ($session['order']['totalAmount']??null)!==0 || ($session['order']['currency']??null)!=='IQD'
            || ($session['order']['ticketCount']??null)!==$quantity
            || !is_int($session['order']['discountAmount']??null) || $session['order']['discountAmount']<0
            || !PsoolaGateway::sameItems($request['items'],$session['order']['items']??null)
            || ($saved['provider_order_id'] && $saved['provider_order_id']!==$session['id'])) throw new \RuntimeException('Complimentary identity, item or zero-total verification failed.');
    }

    private static function recover(array $saved,array $session): array
    {
        try{return self::complete($saved,$session);}
        catch(\Throwable){self::uncertain($saved,'ZERO_TOTAL_VERIFICATION_FAILED',null);throw new UserError('The existing provider order did not pass complimentary zero-total verification. Contact PSOOLA; no new order was submitted.');}
    }

    private static function complete(array $saved,array $session,?string $webhookId=null): array
    {
        self::validate($saved,$session);
        return Db::transaction(static function()use($saved,$session,$webhookId):array{
            $locked=Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=? FOR UPDATE',[$saved['id']]);
            self::validate($locked,$session);
            if ($webhookId) {
                $previous=Db::one('SELECT order_id FROM sponsored_ticket_events WHERE event_id=?',[$webhookId]);
                if ($previous) {
                    if ((int)$previous['order_id']!==(int)$locked['id']) throw new \RuntimeException('Webhook identity conflict.');
                    return $locked;
                }
            }
            $first=$locked['status']!=='issued';
            $update=['status'=>'issued','provider_order_id'=>$session['id'],'provider_status'=>'paid','is_complimentary'=>1,
                'provider_amount'=>0,'provider_currency'=>'IQD','discount_amount'=>$session['order']['discountAmount'],
                'email_status'=>self::delivery($session['emailStatus']??null),'whatsapp_status'=>self::delivery($session['whatsappStatus']??null),
                'raw_response'=>PsoolaGateway::withoutLinks(json_encode($session,JSON_THROW_ON_ERROR)),
                'last_error'=>null,'retry_after'=>null,'updated_at'=>App::now()];
            if ($webhookId) $update['last_webhook_id']=$webhookId;
            if ($first) $update['registration_id']=self::registerSingleSummitGuest($locked);
            Db::update('sponsored_ticket_orders',$update,'id=?',[$locked['id']]);
            if ($webhookId) Db::insert('sponsored_ticket_events',['event_id'=>$webhookId,'order_id'=>$locked['id'],'received_at'=>App::now()]);
            if ($first) Audit::log((int)$locked['created_by'],'ticket.complimentary_order','sponsored_ticket_order',(int)$locked['id'],
                ['order'=>$session['id'],'quantity'=>(int)$locked['ticket_quantity'],'reason'=>$locked['complimentary_reason'],'total'=>0,'currency'=>'IQD']);
            return Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$locked['id']]);
        });
    }

    private static function registerSingleSummitGuest(array $saved): ?int
    {
        // Group orders and other events belong in order history, not as invented summit attendees.
        if ($saved['event_id']!==App::config('payments.psoola.event_id') || (int)$saved['ticket_quantity']!==1) return null;
        $body=json_decode($saved['request_json'],true,512,JSON_THROW_ON_ERROR);
        if (count($body['items'])!==1) return null;
        $type=array_search($body['items'][0]['ticketTypeId'],(array)App::config('payments.psoola.ticket_types',[]),true);
        if (!in_array($type,['professional','student'],true)) return null;
        $guest=json_decode($saved['guest_json'],true,512,JSON_THROW_ON_ERROR);
        if (Db::value("SELECT id FROM registrations WHERE email=? AND phone=? AND status<>'cancelled'",[$guest['email'],$guest['phone']])) return null;
        $parts=preg_split('/\s+/u',$guest['buyer_name'],3)?:[];
        $names=array_pad($parts,3,'');$now=App::now();
        $selected=$body['items'][0]['addOnIds']??[];$addOns=(array)App::config('payments.psoola.add_ons',[]);
        $id=Db::insert('registrations',['ref'=>$saved['partner_reference'],'first_name'=>mb_substr($names[0],0,60),
            'father_name'=>mb_substr($names[1],0,60),'grandfather_name'=>mb_substr($names[2],0,60),
            'email'=>$guest['email'],'phone'=>$guest['phone'],'city'=>'-','gender'=>'prefer-not','specialty'=>$type==='student'?'student':$guest['specialty'],
            'lang'=>$guest['locale']==='ckb'?'ku':(in_array($guest['locale'],['en','ar'],true)?$guest['locale']:'en'),
            'ticket_type'=>$type,'lunch_day1'=>isset($addOns['lunchDay1'])&&in_array($addOns['lunchDay1'],$selected,true)?1:0,
            'lunch_day2'=>isset($addOns['lunchDay2'])&&in_array($addOns['lunchDay2'],$selected,true)?1:0,
            'status'=>'complimentary','comp_reason'=>mb_substr($guest['complimentary_reason'],0,255),
            'sponsoring_company'=>$guest['sponsoring_company']?:null,'pay_method'=>null,'view_nonce'=>Links::nonce(),
            'created_by'=>$saved['created_by'],'created_ip'=>'office','created_at'=>$now,'paid_at'=>$now,'updated_at'=>$now]);
        // This is only the administrative/attendance record. The provider owns every real QR and PDF.
        Tickets::issue($id,'complimentary',null,(int)$saved['created_by']);
        return $id;
    }

    public static function reconcile(string $providerId,?string $reference=null,?string $webhookId=null): bool
    {
        $saved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE is_complimentary=1 AND (provider_order_id=? OR partner_reference=?)',[$providerId,$reference]);
        if (!$saved) return false;
        if ($webhookId!==null && (strlen($webhookId)>190 || $webhookId==='')) throw new \RuntimeException('Invalid webhook event id.');
        if ($webhookId && Db::value('SELECT event_id FROM sponsored_ticket_events WHERE event_id=? AND order_id=?',[$webhookId,$saved['id']])) return true;
        $answer=(new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($providerId));
        if ($answer['status']!==200 || ($answer['json']['id']??null)!==$providerId) throw new \RuntimeException('Complimentary order lookup failed.');
        self::complete($saved,$answer['json']??[],$webhookId);
        return true;
    }

    public static function ticketLink(array $user,int $id): array
    {
        $saved=self::get($user,$id);
        if ($saved['status']!=='issued'||!(int)$saved['is_complimentary']) throw new UserError('Only verified complimentary orders have a guest ticket link here.');
        $answer=(new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($saved['provider_order_id']));
        if ($answer['status']!==200) throw new UserError('PSOOLA could not return the ticket link. Retry the link lookup, not issuance.');
        self::complete($saved,$answer['json']??[]);
        $url=$answer['json']['ticketsUrl']??null;
        $host=is_string($url)?strtolower((string)parse_url($url,PHP_URL_HOST)):'';
        if (!is_string($url)||strlen($url)>4096||parse_url($url,PHP_URL_SCHEME)!=='https'||!($host==='psoola.com'||str_ends_with($host,'.psoola.com'))||parse_url($url,PHP_URL_USER)!==null) throw new UserError('The provider has not returned a valid private ticket link yet.');
        return ['ticketsUrl'=>$url,'emailStatus'=>$answer['json']['emailStatus']??null,'whatsappStatus'=>$answer['json']['whatsappStatus']??null];
    }

    public static function get(array $user,int $id): array
    {
        self::authorize($user);
        $saved=Db::one('SELECT o.*,u.name AS staff_name FROM sponsored_ticket_orders o JOIN admin_users u ON u.id=o.created_by WHERE o.id=?',[$id]);
        if (!$saved) throw new UserError('Guest order not found.',null,404);
        self::access($user,$saved);return $saved;
    }
    public static function byKey(array $user,string $key): array
    {
        self::authorize($user);$saved=Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?',[$key]);
        if (!$saved) throw new UserError('Saved guest order not found.',null,404);
        self::access($user,$saved);return $saved;
    }
    public static function history(array $user): array
    {
        self::authorize($user);
        return Db::all('SELECT o.*,u.name AS staff_name FROM sponsored_ticket_orders o JOIN admin_users u ON u.id=o.created_by'.
            ($user['role']==='owner'?'':' WHERE o.created_by=?').' ORDER BY o.id DESC LIMIT 100',$user['role']==='owner'?[]:[$user['id']]);
    }
    public static function label(array $labels,string $locale): string {return (string)($labels[$locale]??($locale==='ckb'?($labels['ku']??null):null)??$labels['en']??reset($labels)?:'');}
    private static function authorize(array $user): void {if (!Auth::canIssueComplimentary($user)) throw new UserError('Your account cannot issue guest tickets.',null,403);}
    private static function access(array $user,array $saved): void {self::authorize($user);if($user['role']!=='owner'&&(int)$saved['created_by']!==(int)$user['id'])throw new UserError('You cannot access another staff member\'s private guest order.',null,403);}
    private static function delivery(mixed $value): ?string {return is_string($value)?mb_substr($value,0,40):null;}
    private static function requestId(array $answer): ?string {$id=$answer['json']['requestId']??null;return is_string($id)&&preg_match('/^[A-Za-z0-9_.:-]{1,190}$/D',$id)?$id:null;}
    private static function support(?string $id): string {return $id?' Support reference: '.$id.'.':'';}
    private static function uncertain(array $saved,string $code,?string $support): void {Db::run("UPDATE sponsored_ticket_orders SET status=IF(status='issued','issued','uncertain'),last_error=?,support_request_id=?,updated_at=? WHERE id=?",[$code,$support,App::now(),$saved['id']]);}
    private static function waitUntil(array $saved,array $answer): void {$seconds=max(1,min(86400,(int)($answer['retry_after']??30)));Db::update('sponsored_ticket_orders',['retry_after'=>date('Y-m-d H:i:s',time()+$seconds)],'id=?',[$saved['id']]);}
    private static function error(array $answer): UserError
    {
        $code=$answer['json']['error']['code']??'';
        $text=match($code){
            'COMPLIMENTARY_ISSUE_NOT_ENABLED'=>'PSOOLA must enable “Allow complimentary issuance” for our partner account. Cash-sale permission is separate.',
            'UNAUTHORIZED'=>'The PSOOLA integration credentials are not accepted. Ask the Owner to check the server configuration.',
            'EVENT_NOT_ENABLED'=>'This event is unavailable to our PSOOLA partner account.',
            'INSUFFICIENT_INVENTORY'=>'There is not enough inventory. Refresh the catalogue and adjust the quantity in an explicit new request.',
            'SALES_CLOSED'=>'Issuance has closed for this event.',
            'LIMIT_EXCEEDED'=>'The quantity exceeds the applicable order or guest phone-number limit.',
            'TICKET_TYPE_NOT_AVAILABLE','ADD_ON_NOT_AVAILABLE'=>'A selected ticket or add-on is unavailable. Refresh the catalogue.',
            'UNSUPPORTED_SHOW'=>'This show is unsupported. Only general-admission shows can be issued.',
            'INVALID_REQUEST'=>'PSOOLA rejected the request details. Check the guest email, international phone, reason, quantity and selected options.',
            'IDEMPOTENCY_CONFLICT'=>'This key already belongs to different details. Reconcile the original saved request; do not issue a replacement.',
            'RATE_LIMITED'=>'PSOOLA has rate-limited the request. Wait for the recorded retry time and use the same saved request.',
            default=>'PSOOLA could not confirm this guest order. Review the saved request before retrying.',
        };
        return new UserError($text.self::support(self::requestId($answer)));
    }
}
