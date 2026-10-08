<?php
// Forms waiting for payment. A filled-in form is NOT a registration: it is
// kept here only while the person pays, and becomes a registration the moment
// the payment company confirms the exact amount (Payments::apply). A form that
// is not paid in time is deleted by the timed job, with its student ID photo.

declare(strict_types=1);

namespace Ismile;

final class Checkouts
{
    /** A website form can be paid for one day; a phone registration for pay_link_days. */
    private const WEBSITE_HOURS = 24;

    /** Kept this long after it expires (a late message from Psoola), then deleted. */
    public const DELETE_AFTER_HOURS = 48;

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM checkouts WHERE id = ?', [$id]);
    }

    public static function findByRef(string $ref): ?array
    {
        if (!preg_match('/^ISM26-[A-Z0-9]{6}$/', $ref)) {
            return null;
        }
        return Db::one('SELECT * FROM checkouts WHERE ref = ?', [$ref]);
    }

    /** Can this form still be paid? */
    public static function isOpen(array $checkout): bool
    {
        return $checkout['status'] === 'open' && strtotime((string) $checkout['expires_at']) > time();
    }

    // ---------------- the website form ----------------

    /**
     * Checks the public form and keeps it while the person pays.
     * Throws UserError for anything the visitor can fix.
     */
    public static function createFromForm(array $in, ?array $photo): array
    {
        $lang = Lang::pick($in['lang'] ?? 'en');
        $state = Registrations::publicState($lang);
        if (!$state['open']) {
            throw new UserError($state['reason'] === 'full' ? 'reg_full' : 'reg_closed', null, 409);
        }

        $first = Validate::text($in['first_name'] ?? '', 60);
        $father = Validate::text($in['father_name'] ?? '', 60);
        $grandfather = Validate::text($in['grandfather_name'] ?? '', 60);
        foreach (['p_first' => $first, 'p_second' => $father, 'p_third' => $grandfather] as $field => $value) {
            if (!Validate::namePart($value)) {
                throw new UserError($value === '' ? 'err_required' : 'err_name', $field);
            }
        }
        $phone = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if ($phone === null) {
            throw new UserError('err_phone', 'p_phone');
        }
        $email = strtolower(Validate::text($in['email'] ?? '', 190));
        if (!Validate::email($email)) {
            throw new UserError('err_email', 'p_email');
        }
        $city = Validate::text($in['city'] ?? '', 80);
        if ($city === '') {
            throw new UserError('err_required', 'p_city');
        }
        $gender = Validate::oneOf($in['gender'] ?? null, Registrations::GENDERS);
        if ($gender === null) {
            throw new UserError('err_required', 'p_gender');
        }
        $age = filter_var($in['age'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 16, 'max_range' => 120]]);
        if ($age === false) {
            throw new UserError('err_age', 'p_age');
        }
        $specialty = Validate::oneOf($in['specialty'] ?? null, Registrations::SPECIALTIES);
        if ($specialty === null) {
            throw new UserError('err_required', 'p_spec');
        }
        $ticket = Validate::oneOf($in['ticket'] ?? null, ['professional', 'student', 'vip'], 'professional');
        if ($specialty === 'student' && $ticket !== 'vip') $ticket = 'student';

        $lunch1 = !empty($in['lunch_day1']) && $in['lunch_day1'] !== '0';
        $lunch2 = !empty($in['lunch_day2']) && $in['lunch_day2'] !== '0';
        $vipDay = self::vipLunchDay($ticket, $in, $lunch1, $lunch2);
        if (($lunch1 && !$state['lunch']['day1']) || ($lunch2 && !$state['lunch']['day2'])) {
            throw new UserError('err_lunch_full', null, 409);
        }
        $payMethod = Validate::oneOf($in['pay'] ?? null, Registrations::PAY_METHODS, 'visa');
        if (($in['terms'] ?? '') !== '1') {
            throw new UserError('err_terms', 'terms');
        }

        $university = null;
        $ambassador = null;
        if ($ticket === 'student') {
            $university = Validate::text($in['university'] ?? '', 160);
            if ($university === '') {
                throw new UserError('err_required', 'p_uni');
            }
            $ambassador = Validate::text($in['ambassador'] ?? '', 40) ?: null;
            if ($ambassador !== null && !preg_match('/^[\p{L}\p{N}\-_. ]{1,40}$/u', $ambassador)) {
                throw new UserError('err_code', 'p_ambassador');
            }
        }

        $row = [
            'first_name' => $first, 'father_name' => $father, 'grandfather_name' => $grandfather,
            'phone' => $phone, 'email' => $email, 'city' => $city, 'gender' => $gender, 'age' => $age,
            'specialty' => $specialty, 'lang' => $lang, 'ticket_type' => $ticket,
            'lunch_day1' => $lunch1 ? 1 : 0, 'lunch_day2' => $lunch2 ? 1 : 0, 'pay_method' => $payMethod,
            'vip_lunch_day' => $vipDay,
            'university' => $university, 'ambassador_code' => $ambassador,
        ];
        if (!SiteData::pricesReadyFor($row)) {
            throw new UserError('reg_closed', null, 409);
        }

        // The student ID photo is required, checked and stored before anything
        // is written, so a refused photo leaves nothing behind. It is kept as a
        // record; there is no approval step.
        try {
            $row['id_photo_id'] = $ticket === 'student' ? IdPhotos::store($photo) : null;
        } catch (UserError $error) {
            throw new UserError($error->key, 'p_student_id');
        }
        try {
            return self::insert($row + ['source' => 'website', 'created_ip' => App::clientIp(), 'terms_accepted_at' => App::now()], self::WEBSITE_HOURS * 3600);
        } catch (\Throwable $error) {
            IdPhotos::delete($row['id_photo_id']);
            throw $error;
        }
    }

    // ---------------- a registration taken by phone ----------------

    /**
     * The office fills in a caller's details. The caller is registered only
     * after paying: they get an email with their personal payment link.
     */
    public static function createFromOffice(array $in, array $user): array
    {
        $row = self::officeRow($in);
        if (!SiteData::pricesReadyFor($row)) {
            throw new UserError('The ticket prices are not set yet, so this caller cannot pay.');
        }
        $checkout = self::insert($row + ['source' => 'office', 'created_by' => (int) $user['id'], 'created_ip' => 'office'], max(1, Settings::int('pay_link_days')) * 86400);
        Outbox::queueForCheckout('pay_now', $checkout);
        Audit::log((int) $user['id'], 'checkout.phone', 'checkout', (int) $checkout['id'], ['ref' => $checkout['ref']]);
        return $checkout;
    }

    /** The details the office types in for a caller, checked. */
    public static function officeRow(array $in): array
    {
        $first = Validate::text($in['first_name'] ?? '', 60);
        $father = Validate::text($in['father_name'] ?? '', 60);
        $grandfather = Validate::text($in['grandfather_name'] ?? '', 60);
        foreach ([$first, $father, $grandfather] as $part) {
            if (!Validate::namePart($part)) {
                throw new UserError('First, father\'s and grandfather\'s name are needed (2+ letters each).');
            }
        }
        $phone = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if ($phone === null) {
            throw new UserError('That phone number is not valid.');
        }
        $email = strtolower(Validate::text($in['email'] ?? '', 190));
        if (!Validate::email($email)) {
            throw new UserError('An email address is needed: the payment link and the ticket are sent there.');
        }
        $ticket = Validate::oneOf($in['ticket'] ?? null, ['professional', 'student', 'vip'], 'professional');
        $specialty = Validate::oneOf($in['specialty'] ?? null, Registrations::SPECIALTIES);
        if ($specialty === null) {
            throw new UserError('Choose the caller\'s specialty.');
        }
        if ($specialty === 'student' && $ticket !== 'vip') {
            $ticket = 'student';   // same rule as the website form
        }
        $university = null;
        $ambassador = null;
        if ($ticket === 'student') {
            $university = Validate::text($in['university'] ?? '', 160);
            if ($university === '') {
                throw new UserError('Write the student\'s university.');
            }
            $ambassador = Validate::text($in['ambassador'] ?? '', 40) ?: null;
            if ($ambassador !== null && !preg_match('/^[\p{L}\p{N}\-_. ]{1,40}$/u', $ambassador)) {
                throw new UserError('The ambassador code contains unsupported characters.');
            }
        }
        $lunch1 = ($in['lunch_day1'] ?? '') === '1';
        $lunch2 = ($in['lunch_day2'] ?? '') === '1';
        $vipDay = self::vipLunchDay($ticket, $in, $lunch1, $lunch2);
        return [
            'first_name' => $first, 'father_name' => $father, 'grandfather_name' => $grandfather,
            'phone' => $phone, 'email' => $email, 'city' => Validate::text($in['city'] ?? '', 80) ?: '-',
            'gender' => 'prefer-not', 'age' => null, 'specialty' => $specialty, 'lang' => 'en',
            'ticket_type' => $ticket, 'lunch_day1' => $lunch1 ? 1 : 0, 'lunch_day2' => $lunch2 ? 1 : 0, 'vip_lunch_day' => $vipDay,
            'pay_method' => 'visa', 'university' => $university, 'ambassador_code' => $ambassador, 'id_photo_id' => null,
        ];
    }

    /** Both lunch flags describe attendance; exactly one VIP day is included. */
    private static function vipLunchDay(string $ticket, array $in, bool $lunch1, bool $lunch2): ?int
    {
        if ($ticket !== 'vip') return null;
        $day = filter_var($in['vip_lunch_day'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($day, [1, 2], true) || ($day === 1 ? !$lunch1 : !$lunch2)) {
            throw new UserError('err_required');
        }
        return $day;
    }

    /** A reference used by no form and no registration. */
    public static function newReference(): string
    {
        for ($try = 0; $try < 20; $try++) {
            $ref = Security::reference('ISM26', 6);
            if (!Db::value('SELECT id FROM registrations WHERE ref = ?', [$ref]) && !Db::value('SELECT id FROM checkouts WHERE ref = ?', [$ref])) {
                return $ref;
            }
        }
        throw new \RuntimeException('Could not make a unique reference.');
    }

    private static function insert(array $row, int $seconds): array
    {
        $now = App::now();
        for ($try = 0; $try < 5; $try++) {
            $ref = self::newReference();   // never shared with another form or a registration
            try {
                $id = Db::insert('checkouts', $row + [
                    'ref' => $ref, 'status' => 'open', 'view_nonce' => Links::nonce(),
                    'created_at' => $now, 'expires_at' => date('Y-m-d H:i:s', time() + $seconds),
                ]);
                return self::find($id);
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 1062) {   // only a reference clash is retried
                    throw $error;
                }
            }
        }
        throw new \RuntimeException('Could not make a unique reference.');
    }

    // ---------------- paid: it becomes a registration ----------------

    /**
     * Makes the registration from a paid form. Runs inside the payment's
     * transaction, with the checkout row locked. Returns the registration id.
     */
    public static function toRegistration(array $checkout, string $paidAt): int
    {
        $duplicateOf = Db::value("SELECT id FROM registrations WHERE (email = ? OR phone = ?) AND status <> 'cancelled' LIMIT 1", [$checkout['email'], $checkout['phone']]);
        $fields = ['ref', 'first_name', 'father_name', 'grandfather_name', 'phone', 'email', 'city', 'gender', 'age', 'specialty', 'lang',
            'ticket_type', 'lunch_day1', 'lunch_day2', 'vip_lunch_day', 'pay_method', 'university', 'ambassador_code', 'id_photo_id', 'view_nonce', 'created_by', 'created_ip', 'created_at', 'terms_accepted_at'];
        $row = array_intersect_key($checkout, array_flip($fields));
        $id = Db::insert('registrations', $row + [
            'status' => 'paid', 'possible_duplicate' => $duplicateOf ? 1 : 0, 'paid_at' => $paidAt, 'updated_at' => $paidAt,
        ]);
        if ($duplicateOf) {
            Db::run('UPDATE registrations SET possible_duplicate = 1 WHERE id = ?', [$duplicateOf]);
        }
        // The form's personal details now live in the registration only.
        Db::run("UPDATE checkouts SET status = 'paid', registration_id = ?, id_photo_id = NULL WHERE id = ?", [$id, $checkout['id']]);
        // Other attempts for the same form now belong to this person too, so a
        // second payment arriving later is recognised as "paid twice".
        Db::run('UPDATE payments SET registration_id = ? WHERE checkout_id = ? AND registration_id IS NULL', [$id, $checkout['id']]);
        return $id;
    }

    // ---------------- the timed job ----------------

    /**
     * Unpaid forms past their time are closed; two days later they are deleted
     * with their photo and their unpaid payment attempts. Paid ones are removed
     * too: everything they held is in the registration.
     */
    public static function cleanUp(): array
    {
        $expired = Db::run("UPDATE checkouts SET status = 'expired' WHERE status = 'open' AND expires_at < ?", [App::now()]);
        // Candidates: past their time, with no payment still open and no money
        // Finance must look at (those forms are kept; the photo goes after the
        // summit with everyone else's). Oldest first, so the job never stalls.
        $old = Db::all(
            "SELECT id FROM checkouts c
             WHERE ((c.status = 'expired' AND c.expires_at < ?) OR (c.status = 'paid' AND c.created_at < ?))
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.checkout_id = c.id AND p.status IN ('created','waiting','mismatch','duplicate','kept'))
             ORDER BY c.id LIMIT 500",
            [date('Y-m-d H:i:s', time() - self::DELETE_AFTER_HOURS * 3600), date('Y-m-d H:i:s', time() - self::DELETE_AFTER_HOURS * 3600)]
        );
        $deleted = 0;
        foreach ($old as ['id' => $id]) {
            $photo = Db::transaction(static function () use ($id): ?int {
                // Read again under a lock: a late payment may have changed it.
                $checkout = Db::one('SELECT * FROM checkouts WHERE id = ? FOR UPDATE', [$id]);
                if ($checkout === null || $checkout['status'] === 'open'
                    || Db::value("SELECT COUNT(*) FROM payments WHERE checkout_id = ? AND status IN ('created','waiting','mismatch','duplicate','kept')", [$id])) {
                    return -1;
                }
                // Attempts that never reached the payment company are removed.
                // Attempts the company knows about stay (without the form), so a
                // late "paid" from the company is still recorded for Finance.
                Db::run("DELETE FROM payments WHERE checkout_id = ? AND status IN ('failed','expired') AND registration_id IS NULL AND provider_payment_id IS NULL", [$id]);
                Db::run('DELETE FROM checkouts WHERE id = ?', [$id]);
                // A paid form's photo belongs to the registration now (its id_photo_id is NULL here).
                return $checkout['status'] === 'paid' ? null : ($checkout['id_photo_id'] !== null ? (int) $checkout['id_photo_id'] : null);
            });
            if ($photo === -1) {
                continue;
            }
            IdPhotos::delete($photo);
            $deleted++;
        }
        return ['expired' => $expired, 'deleted' => $deleted];
    }
}
