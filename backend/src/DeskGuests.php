<?php
// "Guest" on Workshop & Register (owner, 19 Nov 2026): a free summit ticket
// (0 IQD, no lunch). Only accounts the Owner switched on (Users > the account's
// Account activity page > "Allow Guest tickets") see and use it; every other
// account never sees the Guest card and the server refuses it. One guest
// ticket per email; PSOOLA sends the QR by email and WhatsApp.

declare(strict_types=1);

namespace Ismile;

final class DeskGuests
{
    /** True only for a switched-on account with desk Register access and Guest tickets allowed. */
    public static function canGive(array $user): bool
    {
        return (int) ($user['desk_guest_access'] ?? 0) === 1 && ($user['disabled_at'] ?? null) === null && Auth::canDeskRegister($user);
    }

    /** The desk's Register form, ticket "guest": one free guest ticket in PSOOLA. */
    public static function issue(array $user, array $in): array
    {
        if (!self::canGive($user)) throw new UserError('Your account cannot give free guest tickets.', null, 403);
        if (($in['lunch_day1'] ?? '') === '1' || ($in['lunch_day2'] ?? '') === '1') {
            throw new UserError('A guest ticket comes without lunch. Untick the lunch days.');
        }
        $email = strtolower(trim(is_string($in['email'] ?? null) ? $in['email'] : ''));
        $has = $email !== '' ? Db::one("SELECT ref FROM registrations WHERE LOWER(email) = ? AND status IN ('paid','complimentary') LIMIT 1", [$email]) : null;
        if ($has) throw new UserError('This email already has a summit ticket (' . $has['ref'] . '). Nothing was sent.');
        $guest = array_intersect_key($in, array_flip(['first_name', 'father_name', 'grandfather_name', 'email', 'phone', 'city', 'specialty']));
        return GuestOrders::issueInvited($user, $guest + ['attempt' => bin2hex(random_bytes(16)), 'complimentary_reason' => 'Guest ticket from the desk (' . mb_substr((string) ($user['name'] ?? ''), 0, 60) . ')',
            'delivery_confirmation' => 'email-whatsapp-v1', 'sponsoring_company' => '']);
    }
}
