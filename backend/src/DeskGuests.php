<?php
// "Guest" on Workshop & Register (owner, 19 Nov 2026): a free summit ticket
// (0 IQD, no lunch) for the invited guests only. Their emails are kept here as
// SHA-256 hashes, not as text, because this repository is public. Each email
// gets one ticket; PSOOLA sends the QR by email and WhatsApp.

declare(strict_types=1);

namespace Ismile;

final class DeskGuests
{
    /** sha256(lower-case email) of each invited guest. */
    public const EMAIL_HASHES = [
        'a1d336a6d6ef4a367ef38d4b28b8359cd0b2156e70e3e689211a1cab61a3e12f',
        '6a7012d63067f07ccb82b4a2f5512456a5973b0879dc7424c61ca3a3b1778c9b',
        '22482e42f7d923dd6dbfaca9c699a331fc4fd2eb5a9976397592d0a764a412c3',
    ];

    /** Stand-in hashes for the local tests only (set by reflection). */
    private static array $testHashes = [];

    /** @return list<string> */
    public static function hashes(): array
    {
        return [...self::EMAIL_HASHES, ...self::$testHashes];
    }

    public static function invited(mixed $email): bool
    {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        return $email !== '' && in_array(hash('sha256', $email), self::hashes(), true);
    }

    /** The desk's Register form, ticket "guest": one free guest ticket in PSOOLA. */
    public static function issue(array $user, array $in): array
    {
        if (!Auth::canDeskRegister($user)) throw new UserError('Your account cannot register people.', null, 403);
        if (!self::invited($in['email'] ?? null)) {
            throw new UserError('The free guest ticket is only for the invited guests, and this email is not on the list. Nothing was sent. Choose Dentist, Student or VIP.');
        }
        if (($in['lunch_day1'] ?? '') === '1' || ($in['lunch_day2'] ?? '') === '1') {
            throw new UserError('A guest ticket comes without lunch. Untick the lunch days.');
        }
        $email = strtolower(trim((string) $in['email']));
        $has = Db::one("SELECT ref FROM registrations WHERE LOWER(email) = ? AND status IN ('paid','complimentary') LIMIT 1", [$email]);
        if ($has) throw new UserError('This email already has a summit ticket (' . $has['ref'] . '). Nothing was sent.');
        $guest = array_intersect_key($in, array_flip(['first_name', 'father_name', 'grandfather_name', 'email', 'phone', 'city', 'specialty']));
        return GuestOrders::issueInvited($user, $guest + ['attempt' => bin2hex(random_bytes(16)), 'complimentary_reason' => 'Invited guest (Workshop & Register)',
            'delivery_confirmation' => 'email-whatsapp-v1', 'sponsoring_company' => '']);
    }
}
