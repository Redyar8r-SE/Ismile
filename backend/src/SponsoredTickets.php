<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Free to the guest. PSOOLA's direct-order amount remains a separate provider record. */
final class SponsoredTickets
{
    public static function guest(array $in): array
    {
        $row = [];
        foreach (['first_name','father_name','grandfather_name'] as $key) {
            $row[$key] = Validate::text($in[$key] ?? '', 60);
            if (!Validate::namePart($row[$key])) throw new UserError('Enter first, second and third names with at least two characters each.');
        }
        $row['phone'] = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if (!$row['phone'] || !preg_match('/^\+[1-9][0-9]{7,14}$/D', $row['phone'])) throw new UserError('Enter a valid WhatsApp number, including the country code.');
        $row['email'] = strtolower(Validate::text($in['email'] ?? '', 190));
        if (!Validate::email($row['email'])) throw new UserError('Enter a valid email address for the ticket.');
        $row['specialty'] = Validate::oneOf($in['specialty'] ?? null, Registrations::SPECIALTIES);
        if (!$row['specialty']) throw new UserError('Choose the guest\'s specialty.');
        $row['sponsoring_company'] = Validate::text($in['sponsoring_company'] ?? '', 161);
        if ($row['sponsoring_company'] === '' || mb_strlen($row['sponsoring_company']) > 160) throw new UserError('Enter the sponsoring company (160 characters or fewer).');
        return $row + ['city'=>'-', 'gender'=>'prefer-not', 'age'=>null, 'lang'=>'en',
            'ticket_type'=>$row['specialty'] === 'student' ? 'student' : 'professional',
            'lunch_day1'=>0, 'lunch_day2'=>0, 'vip_lunch_day'=>null, 'pay_method'=>null,
            'university'=>null, 'study_stage'=>null, 'ambassador_code'=>null, 'id_photo_id'=>null];
    }

    public static function issue(array $user, array $in): array
    {
        if (!Auth::canIssueComplimentary($user)) throw new UserError('Your account cannot issue free tickets.');
        if (App::config('payments.gateway') !== 'psoola') throw new UserError('PSOOLA ticket issuance is not available on this site.');
        $key = $in['attempt'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new UserError('Reload the free ticket form and try again.');
        $guest = self::guest($in);
        $consent = ($in['whatsapp_consent'] ?? '') === '1';
        $fingerprint = hash('sha256', json_encode([$guest,$consent], JSON_THROW_ON_ERROR));
        $attempt = Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?', [$key]);
        if (!$attempt) {
            if (!Settings::bool('registration_open')) throw new UserError('Registration is closed in Settings.');
            if (Registrations::isFull(true)) throw new UserError('Registration is full: no places are left.');
            $reference = Checkouts::newReference();
            $body = (new PsoolaGateway())->orderBody($guest + ['ref'=>$reference]);
            if ($consent) $body['buyer']['whatsappConsent'] = ['acknowledged'=>true,
                'receivedAt'=>(int)floor(microtime(true)*1000), 'source'=>'other',
                'reference'=>'ismile-sponsored-consent-'.$key, 'categories'=>['utility']];
            try {
                $attempt = Db::transaction(static function() use($key,$reference,$guest,$body,$user,$fingerprint): array {
                    // Serialize submissions by this staff member, then enforce the guest key across staff.
                    Db::one('SELECT id FROM admin_users WHERE id=? FOR UPDATE', [$user['id']]);
                    $saved = Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?', [$key]);
                    if ($saved) return $saved;
                    if (Db::value("SELECT id FROM registrations WHERE email=? AND phone=? AND status<>'cancelled'", [$guest['email'],$guest['phone']])) throw new UserError('A guest with this email address and phone number is already registered. Open their existing registration.');
                    $now = App::now();
                    $guestKey = hash('sha256', $guest['email'].'|'.$guest['phone']);
                    $id = Db::insert('sponsored_ticket_orders', ['attempt_key'=>$key,'partner_reference'=>$reference,
                        'created_by'=>(int)$user['id'], 'guest_key'=>$guestKey,'fingerprint'=>$fingerprint,
                        'guest_json'=>json_encode($guest,JSON_THROW_ON_ERROR),'request_json'=>json_encode($body,JSON_THROW_ON_ERROR),
                        'created_at'=>$now,'updated_at'=>$now]);
                    return Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?', [$id]);
                });
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 1062) throw $error;
                $attempt = Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?', [$key]);
                if (!$attempt) throw new UserError('This guest already has a ticket request. Finish that request before starting another.');
            }
        }
        if ((int)$attempt['created_by'] !== (int)$user['id'] || !hash_equals($attempt['fingerprint'],$fingerprint)) throw new UserError('The saved ticket request has different details. Retry with its original details.');
        if ($attempt['status'] === 'issued') return self::registration($attempt);
        if ($attempt['status'] === 'rejected') throw new UserError('This request was rejected. Check the details and submit a new free ticket form.');
        if (!Settings::bool('registration_open')) throw new UserError('Registration is closed in Settings. Retry this saved request after registration reopens.');
        $body = json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR);
        try {
            // The same body and key are replayed after timeouts, double clicks and local failures.
            $answer = (new PsoolaGateway())->partnerRequest('POST','orders',$body,['Idempotency-Key: ismile-free-'.$key]);
            if (!in_array($answer['status'],[200,201],true)) {
                $code = $answer['json']['error']['code'] ?? '';
                if (in_array($answer['status'],[400,401,403,404,422],true) || ($answer['status'] === 409 && in_array($code,['INSUFFICIENT_INVENTORY','SALES_CLOSED'],true))) {
                    Db::update('sponsored_ticket_orders',['status'=>'rejected','last_error'=>'PSOOLA HTTP '.$answer['status'],'updated_at'=>App::now()],'id=? AND status<>?',[$attempt['id'],'issued']);
                    throw new UserError(match($code) {
                        'INSUFFICIENT_INVENTORY'=>'PSOOLA says this ticket is sold out.',
                        'SALES_CLOSED'=>'PSOOLA says ticket issuance is closed for this event.',
                        'LIMIT_EXCEEDED'=>'PSOOLA says this phone number has reached its ticket limit.',
                        'DIRECT_ISSUE_NOT_ENABLED'=>'PSOOLA has not enabled direct ticket issuance for this account.',
                        'EVENT_NOT_ENABLED','TICKET_TYPE_NOT_AVAILABLE'=>'PSOOLA has not enabled this event or ticket type.',
                        default=>'PSOOLA did not accept the ticket request. Check the details and try again.',
                    });
                }
                throw new \RuntimeException('PSOOLA order HTTP '.$answer['status']);
            }
            $session = $answer['json'] ?? [];
            self::validate($attempt,$session);
            return self::complete($attempt,$session);
        } catch (UserError $error) {
            throw $error;
        } catch (\Throwable $error) {
            Db::run("UPDATE sponsored_ticket_orders SET status=IF(status='issued','issued','uncertain'),last_error=?,updated_at=? WHERE id=?",['The ticket response could not be verified.',App::now(),$attempt['id']]);
            // Do not log provider payloads, customer details or private ticket URLs.
            App::log('warning','Sponsored ticket issuance needs a retry',['request'=>(int)$attempt['id']]);
            throw new UserError('The ticket could not be confirmed yet. Retry this same form with its original details; the saved request prevents duplicate tickets.');
        }
    }

    public static function validate(array $attempt, array $session): void
    {
        $request = json_decode($attempt['request_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_string($session['id'] ?? null) || $session['id'] === '' || strlen($session['id']) > 190
            || ($session['partnerReference'] ?? null) !== $attempt['partner_reference']
            || ($session['status'] ?? null) !== 'paid' || ($session['channel'] ?? null) !== 'direct'
            || !PsoolaGateway::sameItems($request['items'],$session['order']['items'] ?? null)
            || !is_int($session['order']['totalAmount'] ?? null) || $session['order']['totalAmount'] < 0
            || !preg_match('/^[A-Z]{3}$/D',(string)($session['order']['currency'] ?? ''))
            || ($attempt['provider_amount'] !== null && ((int)$attempt['provider_amount'] !== $session['order']['totalAmount'] || $attempt['provider_currency'] !== $session['order']['currency']))
            || ($attempt['provider_order_id'] && $attempt['provider_order_id'] !== $session['id'])) throw new \RuntimeException('Sponsored order identity or receipt mismatch.');
    }

    private static function complete(array $attempt,array $session): array
    {
        return Db::transaction(static function() use($attempt,$session): array {
            $saved = Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=? FOR UPDATE',[$attempt['id']]);
            self::validate($saved,$session);
            if ($saved['status'] === 'issued') {
                Db::update('sponsored_ticket_orders',['email_status'=>self::deliveryStatus($session['emailStatus'] ?? null),
                    'whatsapp_status'=>self::deliveryStatus($session['whatsappStatus'] ?? null),
                    'raw_response'=>PsoolaGateway::withoutLinks(json_encode($session,JSON_THROW_ON_ERROR)),
                    'updated_at'=>App::now()],'id=?',[$saved['id']]);
                return self::registration($saved);
            }
            $guest = json_decode($saved['guest_json'],true,512,JSON_THROW_ON_ERROR);
            $now = App::now();
            $duplicate = Db::value("SELECT id FROM registrations WHERE (email=? OR phone=?) AND status<>'cancelled' LIMIT 1",[$guest['email'],$guest['phone']]);
            $id = Db::insert('registrations',$guest + ['ref'=>$saved['partner_reference'],'status'=>'complimentary',
                'comp_reason'=>'Sponsored by '.$guest['sponsoring_company'], 'possible_duplicate'=>$duplicate?1:0,
                'view_nonce'=>Links::nonce(),'created_by'=>(int)$saved['created_by'],'created_ip'=>'office',
                'created_at'=>$now,'paid_at'=>$now,'updated_at'=>$now]);
            Tickets::issue($id,'complimentary',null,(int)$saved['created_by']);
            Db::update('sponsored_ticket_orders',['registration_id'=>$id,'status'=>'issued','provider_order_id'=>$session['id'],
                'provider_amount'=>$session['order']['totalAmount'],'provider_currency'=>$session['order']['currency'],
                'email_status'=>self::deliveryStatus($session['emailStatus'] ?? null),
                'whatsapp_status'=>self::deliveryStatus($session['whatsappStatus'] ?? null),
                'raw_response'=>PsoolaGateway::withoutLinks(json_encode($session,JSON_THROW_ON_ERROR)),
                'last_error'=>null,'updated_at'=>$now],'id=?',[$saved['id']]);
            Audit::log((int)$saved['created_by'],'ticket.sponsored','registration',$id,
                ['company'=>$guest['sponsoring_company'],'order'=>$session['id'],'guest_paid'=>0,
                    'provider_amount'=>$session['order']['totalAmount'],'provider_currency'=>$session['order']['currency']]);
            // PSOOLA handles delivery. Do not create a second ticket email in iSmile.
            return Registrations::find($id);
        });
    }

    private static function deliveryStatus(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value,0,40) : null;
    }

    private static function registration(array $attempt): array
    {
        return ($attempt['registration_id'] ? Registrations::find((int)$attempt['registration_id']) : null)
            ?? throw new UserError('This ticket was issued for an archived registration. Contact the Owner before issuing another.');
    }

    /** Verified signed webhooks recover requests whose original response was lost. */
    public static function reconcile(string $providerId, ?string $reference = null): bool
    {
        $attempt = Db::one('SELECT * FROM sponsored_ticket_orders WHERE provider_order_id=? OR partner_reference=?',[$providerId,$reference]);
        if (!$attempt) return false;
        $answer = (new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($providerId));
        if ($answer['status'] !== 200 || ($answer['json']['id'] ?? null) !== $providerId) throw new \RuntimeException('Sponsored order verification failed.');
        self::validate($attempt,$answer['json'] ?? []);
        self::complete($attempt,$answer['json']);
        return true;
    }
}
