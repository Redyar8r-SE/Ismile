<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** Read-only access to an existing provider ticket page. Never creates an order. */
final class PsoolaTickets
{
    public static function canView(array $user, array $registration): bool
    {
        return Auth::can($user,'registrations') && (Auth::canBookWorkshops($user) || Office::canChange($user,$registration));
    }

    public static function available(array $user, array $registration): bool
    {
        return self::canView($user,$registration) && in_array($registration['status'],['paid','complimentary'],true) && self::source((int)$registration['id']) !== null;
    }

    private static function source(int $registrationId): ?array
    {
        $guest = Db::one("SELECT * FROM sponsored_ticket_orders WHERE registration_id=? AND status='issued' AND provider_order_id IS NOT NULL",[$registrationId]);
        if ($guest) return ['id'=>$guest['provider_order_id'],'reference'=>$guest['partner_reference'],'amount'=>$guest['provider_amount'],
            'currency'=>$guest['provider_currency'],'guest'=>$guest];
        $payment = Db::one("SELECT * FROM payments WHERE registration_id=? AND gateway='psoola' AND status='paid' AND provider_payment_id IS NOT NULL ORDER BY id DESC LIMIT 1",[$registrationId]);
        if (!$payment) return null;
        return ['id'=>$payment['provider_payment_id'],'reference'=>null,'amount'=>$payment['amount_confirmed'],'currency'=>$payment['currency'],'guest'=>null];
    }

    public static function link(array $user, int $registrationId): array
    {
        $registration = Registrations::find($registrationId);
        if (!$registration) throw new UserError('Registration not found.',null,404);
        if (!self::canView($user,$registration)) throw new UserError('Your account cannot view this person\'s PSOOLA ticket.',null,403);
        if (!in_array($registration['status'],['paid','complimentary'],true)) throw new UserError('This registration is not active.');
        $ticket = Tickets::forRegistration($registrationId);
        if ($ticket && $ticket['cancelled_at'] !== null) throw new UserError('This ticket has been cancelled.');
        $source = self::source($registrationId);
        if (!$source || !$source['id']) throw new UserError('No issued PSOOLA order is linked to this registration.');
        $answer = (new PsoolaGateway())->partnerRequest('GET','orders/'.rawurlencode($source['id']));
        if ($answer['status'] !== 200 || !is_array($answer['json'] ?? null)) throw new UserError('PSOOLA could not return the ticket page. Try again later; do not issue another ticket.');
        $session = $answer['json'];
        $reference = $source['reference'] ?? $registration['ref'];
        if (($session['id'] ?? null) !== $source['id'] || ($session['partnerReference'] ?? null) !== $reference || ($session['status'] ?? null) !== 'paid') {
            throw new UserError('PSOOLA did not confirm the linked order as issued. No replacement ticket was created.');
        }
        if ($source['guest'] && (int)$source['guest']['is_complimentary']) {
            try { GuestOrders::validate($source['guest'],$session); }
            catch (\Throwable) { throw new UserError('PSOOLA could not verify this complimentary ticket. Ask the Owner to review the existing order.'); }
        } elseif ($source['amount'] === null || !is_int($session['order']['totalAmount'] ?? null)
            || $session['order']['totalAmount'] !== (int)$source['amount'] || $session['order']['totalAmount'] < 1
            || ($session['order']['currency'] ?? null) !== $source['currency'] || ($session['isComplimentary'] ?? false) === true) {
            throw new UserError('PSOOLA returned different order details. Ask the Owner to review the existing order.');
        }
        $url = $session['ticketsUrl'] ?? null;
        if (!self::safeUrl($url)) throw new UserError('PSOOLA has not supplied a valid private ticket-page link for this order yet. Try again later.');
        return ['ticketsUrl'=>$url];
    }

    public static function safeUrl(mixed $url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url)>4096 || preg_match('/[\x00-\x20\x7f]/',$url) || str_contains($url,'\\')) return false;
        $parts = parse_url($url);
        if (!is_array($parts)) return false;
        $host = strtolower($parts['host'] ?? '');
        return ($parts['scheme'] ?? '') === 'https' && ($host === 'psoola.com' || str_ends_with($host,'.psoola.com'))
            && !isset($parts['user']) && !isset($parts['pass']) && (!isset($parts['port']) || $parts['port'] === 443);
    }
}
