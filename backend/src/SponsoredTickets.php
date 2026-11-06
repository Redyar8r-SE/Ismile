<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Free to the guest. PSOOLA's direct-order amount remains a separate provider record. */
final class SponsoredTickets
{
    /** Paid direct orders cannot satisfy a zero-price free-ticket request. */
    public static function issuanceAvailable(): bool
    {
        return GuestOrders::enabled();
    }

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
        return GuestOrders::issue($user,$in);
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
    public static function reconcile(string $providerId, ?string $reference = null, ?string $webhookId = null): bool
    {
        if (GuestOrders::reconcile($providerId,$reference,$webhookId)) return true;
        $attempt = Db::one('SELECT * FROM sponsored_ticket_orders WHERE provider_order_id=? OR partner_reference=?',[$providerId,$reference]);
        if ($attempt && $attempt['status'] !== 'issued') throw new \RuntimeException('Legacy cash-order requests require manual review; they cannot be issued as complimentary.');
        if (!$attempt) return false;
        $answer = (new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($providerId));
        if ($answer['status'] !== 200 || ($answer['json']['id'] ?? null) !== $providerId) throw new \RuntimeException('Sponsored order verification failed.');
        self::validate($attempt,$answer['json'] ?? []);
        self::complete($attempt,$answer['json']);
        return true;
    }
}
