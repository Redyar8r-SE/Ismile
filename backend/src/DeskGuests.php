<?php
// "Guest" on Workshop & Register (owner, 19 Nov 2026): a free summit ticket
// (0 IQD, no lunch). Only four volunteer accounts see and use it: Nma, Lanu,
// Rawaz and Danial. Their sign-in emails are kept here as SHA-256 hashes, not
// as text, because this repository is public. Every other account (other
// volunteers too) never sees the Guest card and the server refuses it.
// One guest ticket per email; PSOOLA sends the QR by email and WhatsApp.

declare(strict_types=1);

namespace Ismile;

final class DeskGuests
{
    /** sha256(lower-case sign-in email) of each volunteer who may give a Guest ticket. */
    public const GIVER_HASHES = [
        'a1d336a6d6ef4a367ef38d4b28b8359cd0b2156e70e3e689211a1cab61a3e12f',   // Nma
        '22482e42f7d923dd6dbfaca9c699a331fc4fd2eb5a9976397592d0a764a412c3',   // Lanu
        '6a7012d63067f07ccb82b4a2f5512456a5973b0879dc7424c61ca3a3b1778c9b',   // Rawaz
        'a147025e9c50291c9419371e77548ab7273abe67d98f6086f5bd5a4e4a1ede83',   // Danial
    ];

    /** Stand-in hashes for the local tests only (set by reflection). */
    private static array $testHashes = [];

    /** True only for the four volunteers' own accounts, switched on, with desk Register access. */
    public static function canGive(array $user): bool
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        return $email !== '' && ($user['disabled_at'] ?? null) === null && Auth::canDeskRegister($user)
            && in_array(hash('sha256', $email), [...self::GIVER_HASHES, ...self::$testHashes], true);
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
