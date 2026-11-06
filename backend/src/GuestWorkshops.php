<?php
declare(strict_types=1);
namespace Ismile;

/** A persisted, one-person workshop request using the existing complimentary client. */
final class GuestWorkshops
{
    public static function allowed(array $workshop): bool
    {
        return ($workshop['organizer'] ?? '') === 'ismile' && ($workshop['status'] ?? '') === 'active'
            && !empty($workshop['providerEventId']) && !empty($workshop['providerShowId']) && !empty($workshop['providerTicketTypeId']);
    }

    private static function authorize(array $user, array $registration): void
    {
        if (($user['role'] ?? '') !== 'owner' || !Auth::can($user, 'workshops') || !Auth::canIssueComplimentary($user) || !Office::canChange($user, $registration)) {
            throw new UserError('Your account cannot issue a guest workshop for this registration.', null, 403);
        }
    }

    public static function start(array $user, array $registration, array $in): array
    {
        self::authorize($user, $registration);
        $key = $in['attempt'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new UserError('Reload the workshop form before booking.');
        if (($in['delivery_confirmation'] ?? '') !== 'email-whatsapp-v1') throw new UserError('Confirm permission to send the guest workshop by WhatsApp.');
        $workshop = Workshops::find((string) ($in['workshop'] ?? ''));
        if (!$workshop || !self::allowed($workshop)) throw new UserError('Choose an available iSmile workshop. Company workshops are booked with their organizer.');
        if (!in_array($registration['status'], ['paid','complimentary'], true)) throw new UserError('An active paid or complimentary summit registration is required.');
        $payload = ['attempt'=>$key, 'buyer_name'=>Registrations::fullName($registration), 'email'=>$registration['email'],
            'phone'=>$registration['phone'], 'locale'=>$registration['lang'] === 'ku' ? 'ckb' : $registration['lang'],
            'specialty'=>$registration['specialty'], 'complimentary_reason'=>$in['complimentary_reason'] ?? '',
            'event_id'=>$workshop['providerEventId'], 'show_id'=>$workshop['providerShowId'],
            'ticket_type_id'=>$workshop['providerTicketTypeId'], 'quantity'=>1, 'whatsapp_consent'=>'1'];
        GuestOrders::guest($payload);
        $saved = Db::transaction(static function () use ($user, $registration, $workshop, $payload, $key): array {
            $lockedWorkshop = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE', [$workshop['id']]);
            if (!$lockedWorkshop || $lockedWorkshop['organizer'] !== 'ismile' || $lockedWorkshop['status'] !== 'active'
                || $lockedWorkshop['provider_event_id'] !== $workshop['providerEventId'] || $lockedWorkshop['provider_show_id'] !== $workshop['providerShowId']
                || $lockedWorkshop['provider_ticket_type_id'] !== $workshop['providerTicketTypeId']) throw new UserError('This workshop configuration changed. Refresh before booking.');
            $fresh = Db::one('SELECT * FROM registrations WHERE id=? FOR UPDATE', [$registration['id']]);
            if (!$fresh || !in_array($fresh['status'], ['paid','complimentary'], true)) throw new UserError('This registration is no longer active.');
            $old = Db::one('SELECT * FROM guest_workshop_orders WHERE attempt_key=?', [$key]);
            if ($old) {
                if ((int)$old['registration_id'] !== (int)$registration['id'] || $old['workshop_id'] !== $workshop['id']
                    || json_decode($old['payload_json'], true, 512, JSON_THROW_ON_ERROR) != $payload) throw new UserError('This request has different details. Resume its saved request instead.');
                return $old;
            }
            WorkshopCheckout::assertNoOpenPurchase((int)$registration['id'], $workshop['id']);
            if (Db::value('SELECT id FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL', [$registration['id'], $workshop['id']])
                || Db::value("SELECT id FROM workshop_ticket_orders WHERE registration_id=? AND workshop_id=? AND provider_status IN ('created','unknown','awaiting_payment','paid')", [$registration['id'], $workshop['id']])) {
                throw new UserError('This person already has a booking or a PSOOLA workshop order. Open the existing booking.');
            }
            $id = Db::insert('guest_workshop_orders', ['registration_id'=>$registration['id'], 'workshop_id'=>$workshop['id'],
                'created_by'=>$user['id'], 'attempt_key'=>$key, 'active_pair'=>hash('sha256', $registration['id'].':'.$workshop['id']),
                'payload_json'=>json_encode($payload, JSON_THROW_ON_ERROR), 'created_at'=>App::now(), 'updated_at'=>App::now()]);
            return Db::one('SELECT * FROM guest_workshop_orders WHERE id=?', [$id]);
        });
        return self::resume($user, $registration, (int)$saved['id']);
    }

    public static function resume(array $user, array $registration, int $id): array
    {
        self::authorize($user, $registration);
        $saved = Db::one('SELECT * FROM guest_workshop_orders WHERE id=? AND registration_id=?', [$id, $registration['id']]);
        if (!$saved || ($user['role'] !== 'owner' && (int)$saved['created_by'] !== (int)$user['id'])) throw new UserError('Saved workshop request not found.', null, 404);
        if ($saved['state'] === 'rejected') throw new UserError('This request was rejected. Review the error before making an explicit new request.');
        $lock = 'ismile-guest-workshop-'.$id;
        if (!(int)Db::value('SELECT GET_LOCK(?,0)', [$lock])) throw new UserError('This workshop request is still running. Resume the saved request shortly; do not create another.');
        try {
            $order = Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?', [$saved['attempt_key']]);
            $order = $order ? GuestOrders::retry($user, $saved['attempt_key']) : GuestOrders::issue($user, json_decode($saved['payload_json'], true, 512, JSON_THROW_ON_ERROR));
            SiteData::syncWorkshopSeats();
            return $order;
        } catch (UserError $error) {
            $order = Db::one('SELECT * FROM sponsored_ticket_orders WHERE attempt_key=?', [$saved['attempt_key']]);
            if (!$order || $order['status'] === 'rejected') Db::update('guest_workshop_orders', ['state'=>'rejected','active_pair'=>null,'updated_at'=>App::now()], 'id=?', [$id]);
            throw $error;
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public static function assertNoOrder(int $registrationId, string $workshopId): void
    {
        if (Db::value('SELECT id FROM guest_workshop_orders WHERE registration_id=? AND workshop_id=? AND active_pair IS NOT NULL', [$registrationId, $workshopId])) {
            throw new UserError('This attendee has a saved complimentary workshop request. Open the registration and resume it instead of creating another order.');
        }
    }

    /** Called inside GuestOrders' verified fulfilment transaction, including webhooks. */
    public static function mirror(array $order): void
    {
        $saved = Db::one('SELECT * FROM guest_workshop_orders WHERE attempt_key=? FOR UPDATE', [$order['attempt_key']]);
        if (!$saved) return;
        $bookingId = $saved['booking_id'];
        if (!$bookingId) {
            if (Db::value('SELECT id FROM workshop_bookings WHERE registration_id=? AND workshop_id=? AND removed_at IS NULL', [$saved['registration_id'], $saved['workshop_id']])) throw new \RuntimeException('A different workshop booking requires reconciliation.');
            $cancelled = Db::value('SELECT status FROM registrations WHERE id=?', [$saved['registration_id']]) === 'cancelled';
            $bookingId = Db::insert('workshop_bookings', ['registration_id'=>$saved['registration_id'], 'workshop_id'=>$saved['workshop_id'],
                'price_agreed'=>0, 'payment_status'=>'complimentary', 'amount_paid'=>null, 'booked_by'=>$saved['created_by'],
                'notes'=>$order['complimentary_reason'], 'removed_at'=>$cancelled ? App::now() : null,
                'created_at'=>App::now(), 'updated_at'=>App::now()]);
            Audit::log((int)$saved['created_by'], 'workshop.complimentary_issued', 'registration', (int)$saved['registration_id'],
                ['workshop'=>$saved['workshop_id'], 'booking'=>$bookingId, 'order'=>$order['provider_order_id'], 'total'=>0, 'reason'=>$order['complimentary_reason']]);
        }
        Db::update('guest_workshop_orders', ['state'=>'issued','guest_order_id'=>$order['id'],'booking_id'=>$bookingId,'updated_at'=>App::now()], 'id=?', [$saved['id']]);
        @unlink(App::storage('tmp/workshop-catalog-'.$saved['workshop_id'].'.json'));
    }
}
