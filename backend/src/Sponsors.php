<?php
// Sponsorship and exhibition-booth requests. Never sold like tickets: a company
// sends the form, the team calls them and tells them the amount, and the
// database keeps the whole record:
//
//   New → Contacted (called) → Agreed (amount agreed) → Paid (exact amount) → Confirmed
//   or Declined / Waiting list.
//
// Every call is saved (who, when, result, amount told, when to call again).
// Money is recorded only as exactly the agreed amount, and nobody is Confirmed
// before they paid: the database itself refuses it. No refunds.

declare(strict_types=1);

namespace Ismile;

final class Sponsors
{
    public const STATUSES = ['new', 'contacted', 'agreed', 'paid', 'confirmed', 'declined', 'waiting_list'];

    /** Call results, with the plain words the admin shows. */
    public const OUTCOMES = [
        'reached'    => 'Talked to them',
        'no_answer'  => 'No answer',
        'call_back'  => 'Call back later',
        'interested' => 'Interested',
        'agreed'     => 'Agreed on an amount',
        'declined'   => 'Not interested',
    ];

    public const PAID_HOW = ['cash' => 'Cash', 'transfer' => 'Bank transfer', 'psoola' => 'Psoola', 'other' => 'Other'];

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sponsor_requests WHERE id = ?', [$id]);
    }

    public static function calls(int $requestId): array
    {
        return Db::all('SELECT c.*, u.name AS caller FROM sponsor_calls c LEFT JOIN admin_users u ON u.id = c.called_by WHERE c.request_id = ? ORDER BY c.called_at DESC, c.id DESC', [$requestId]);
    }

    public static function createFromForm(array $in): array
    {
        $lang = Lang::pick($in['lang'] ?? 'en');
        $kind = Validate::oneOf($in['kind'] ?? null, ['sponsor', 'booth'], 'sponsor');
        // The package they picked, if it is a shown package of this kind; "not sure" = none yet.
        $package = SponsorPackages::find(Validate::text($in['package'] ?? '', 40));
        $packageId = $package && $package['kind'] === $kind && $package['status'] === 'active' ? $package['id'] : null;
        $company = Validate::text($in['company'] ?? '', 160);
        if (mb_strlen($company) < 2) {
            throw new UserError($company === '' ? 'err_required' : 'err_name', 's_company');
        }
        $contact = Validate::text($in['contact'] ?? '', 120);
        if (mb_strlen($contact) < 2) {
            throw new UserError($contact === '' ? 'err_required' : 'err_name', 's_contact');
        }
        $phone = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if ($phone === null) {
            throw new UserError('err_phone', 's_phone');
        }
        $email = strtolower(Validate::text($in['email'] ?? '', 190));
        if (!Validate::email($email)) {
            throw new UserError('err_email', 's_email');
        }
        $now = App::now();
        $row = [
            'kind' => $kind, 'package_id' => $packageId, 'company' => $company, 'contact_name' => $contact,
            'contact_role' => Validate::text($in['role'] ?? '', 120) ?: null,
            'phone' => $phone, 'email' => $email,
            'website' => Validate::text($in['website'] ?? '', 190) ?: null,
            'city' => Validate::text($in['city'] ?? '', 80) ?: null,
            'message' => Validate::multiline($in['note'] ?? '', 3000) ?: null,
            'lang' => $lang, 'status' => 'new', 'created_ip' => App::clientIp(),
            'created_at' => $now, 'updated_at' => $now,
        ];
        $id = null;
        for ($try = 0; $try < 5 && $id === null; $try++) {
            try {
                $id = Db::insert('sponsor_requests', $row + ['ref' => Security::reference('SPN26', 5)]);
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? 0) !== 1062) {
                    throw $error;
                }
            }
        }
        $request = self::find((int) $id);
        Outbox::queue('sponsor_received', null, [], (int) $id, $email);
        $team = Settings::get('sponsor_notify_email');
        $teamList = Validate::email($team) ? [$team] : (array) App::config('alerts_to', []);
        foreach ($teamList as $address) {
            if (Validate::email((string) $address)) {
                Outbox::queue('sponsor_notify', null, [], (int) $id, (string) $address);
            }
        }
        return $request;
    }

    /** A company booked directly with the office; no website form is needed. */
    public static function createByStaff(array $in, array $user): array
    {
        if (!Auth::can($user, 'sponsors')) {
            throw new UserError('Your role cannot add sponsor or exhibition bookings.');
        }
        $kind = Validate::oneOf($in['kind'] ?? null, ['sponsor', 'booth']);
        if ($kind === null) {
            throw new UserError('Choose sponsorship or an exhibition booth.');
        }
        $company = Validate::text($in['company'] ?? '', 160);
        $contact = Validate::text($in['contact'] ?? '', 120);
        if (mb_strlen($company) < 2) {
            throw new UserError('Enter the company name (at least two characters).');
        }
        if (mb_strlen($contact) < 2) {
            throw new UserError('Enter the contact person (at least two characters).');
        }
        $phone = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if ($phone === null) {
            throw new UserError('Enter a valid contact phone number.');
        }
        $email = strtolower(Validate::text($in['email'] ?? '', 190));
        if ($email !== '' && !Validate::email($email)) {
            throw new UserError('Enter a valid email address, or leave it empty.');
        }
        $booth = self::boothNumber($in['booth_number'] ?? '');
        if ($kind === 'booth' && $booth === null) {
            throw new UserError('Choose the booth number to reserve.');
        }
        $price = trim((string) ($in['amount_agreed'] ?? ''));
        if ($price !== '' && (!preg_match('/^\d{1,10}$/D', $price) || (int) $price > 1000000000)) {
            throw new UserError('Enter the agreed price in whole IQD (up to 1,000,000,000), or leave it empty.');
        }
        $amount = $price === '' ? null : (int) $price;
        $how = (string) ($in['paid_how'] ?? '');
        if ($how !== '' && !array_key_exists($how, self::PAID_HOW)) {
            throw new UserError('Choose how the payment was received.');
        }
        if ($how !== '' && $amount === null) {
            throw new UserError('Enter the agreed amount before recording its full payment.');
        }
        $packageId = Validate::text($in['package_id'] ?? '', 40);

        return Db::transaction(static function () use ($in, $user, $kind, $company, $contact, $phone, $email, $booth, $amount, $how, $packageId): array {
            $package = Db::one('SELECT * FROM sponsor_packages WHERE id = ? FOR UPDATE', [$packageId]);
            if ($package === null || $package['kind'] !== $kind || $package['status'] !== 'active') {
                throw new UserError('Choose an available ' . ($kind === 'booth' ? 'booth type.' : 'sponsorship package.'));
            }
            $now = App::now();
            $status = $how !== '' ? 'paid' : ($amount !== null ? 'agreed' : 'new');
            BoothPlan::check($package, $booth);
            $row = [
                'kind' => $kind, 'package_id' => $packageId, 'company' => $company, 'contact_name' => $contact,
                'contact_role' => Validate::text($in['role'] ?? '', 120) ?: null,
                'phone' => $phone, 'email' => $email,
                'website' => Validate::text($in['website'] ?? '', 190) ?: null,
                'city' => Validate::text($in['city'] ?? '', 80) ?: null,
                'lang' => Lang::pick($in['lang'] ?? 'en'), 'status' => $status,
                'assigned_to' => (int) $user['id'], 'booth_number' => $booth,
                'price_quoted' => $amount, 'amount_agreed' => $amount,
                'amount_paid' => $how !== '' ? $amount : null,
                'paid_how' => $how !== '' ? $how : null, 'paid_at' => $how !== '' ? $now : null,
                'notes' => Validate::multiline($in['notes'] ?? '', 5000) ?: null,
                'created_ip' => App::clientIp(), 'created_at' => $now, 'updated_at' => $now,
            ];
            $id = null;
            for ($try = 0; $try < 5 && $id === null; $try++) {
                try {
                    $id = Db::insert('sponsor_requests', $row + ['ref' => Security::reference('SPN26', 5)]);
                } catch (\PDOException $error) {
                    if (($error->errorInfo[1] ?? 0) === 1062 && str_contains($error->getMessage(), 'uq_sponsor_reserved_booth')) {
                        throw new UserError('That booth is already booked. Choose another booth number.');
                    }
                    if (($error->errorInfo[1] ?? 0) !== 1062 || !str_contains($error->getMessage(), 'uq_sponsor_ref')) {
                        throw $error;
                    }
                }
            }
            if ($id === null) {
                throw new \RuntimeException('Could not generate a unique sponsor reference.');
            }
            Audit::log((int) $user['id'], 'sponsor.office_create', 'sponsor_request', $id, [
                'company' => $company, 'kind' => $kind, 'package_id' => $packageId,
                'booth_number' => $booth, 'status' => $status, 'amount_agreed' => $amount,
                'amount_paid' => $row['amount_paid'], 'paid_how' => $row['paid_how'],
            ]);
            return self::find($id) ?? throw new \RuntimeException('Created booking not found.');
        });
    }

    /** Places per package and how many companies are confirmed on it. */
    public static function spots(?string $kind = null): array
    {
        $out = [];
        foreach (SponsorPackages::all($kind) as $package) {
            $out[$package['id']] = [
                'name' => (string) $package['name_en'], 'kind' => $package['kind'], 'price' => (int) $package['price'],
                'spots' => (int) $package['places'], 'confirmed' => (int) $package['confirmed'], 'status' => $package['status'],
            ];
        }
        return $out;
    }

    // ---------------- the phone calls ----------------

    /**
     * Saves one call and moves the request forward:
     *  - talked / interested / call back: New becomes Contacted;
     *  - agreed: needs the amount; the request becomes Agreed with that amount;
     *  - not interested: Declined (not once they paid);
     *  - no answer: only the call is saved.
     * The amount told on the call is kept as "price told".
     */
    public static function logCall(int $requestId, array $in, array $user): string
    {
        $outcome = (string) ($in['outcome'] ?? '');
        if (!array_key_exists($outcome, self::OUTCOMES)) {
            throw new UserError('Choose what happened on the call.');
        }
        $amount = self::amount($in['amount'] ?? '');
        if ($outcome === 'agreed' && $amount === null) {
            throw new UserError('Write the amount they agreed to (IQD).');
        }
        $next = self::dateTime((string) ($in['next_call_at'] ?? ''));
        $note = Validate::text($in['note'] ?? '', 500) ?: null;

        return Db::transaction(static function () use ($requestId, $outcome, $amount, $next, $note, $user): string {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === null) {
                throw new UserError('Request not found.');
            }
            $done = $request['amount_paid'] !== null;
            if ($done && in_array($outcome, ['agreed', 'declined'], true)) {
                throw new UserError('A payment is already recorded. Use Edit details to change the agreed total; save this call as "Talked to them" with a note.');
            }
            $now = App::now();
            Db::insert('sponsor_calls', [
                'request_id' => $requestId, 'called_by' => (int) $user['id'], 'called_at' => $now,
                'outcome' => $outcome, 'amount_quoted' => $amount, 'note' => $note, 'next_call_at' => $next,
            ]);
            $change = ['last_call_at' => $now, 'next_call_at' => $outcome === 'declined' ? null : $next, 'updated_at' => $now];
            if ($amount !== null) {
                $change['price_quoted'] = $amount;
            }
            if ($request['assigned_to'] === null) {
                $change['assigned_to'] = (int) $user['id'];   // whoever calls first handles it
            }
            $status = $request['status'];
            if ($outcome === 'agreed') {
                $status = 'agreed';
                $change['amount_agreed'] = $amount;
            } elseif ($outcome === 'declined') {
                $status = 'declined';
            } elseif ($outcome !== 'no_answer' && $status === 'new') {
                $status = 'contacted';
            }
            $change['status'] = $status;
            self::updateWithBoothCheck($requestId, $change);
            Audit::log((int) $user['id'], 'sponsor.call', 'sponsor_request', $requestId, [
                'outcome' => $outcome, 'amount' => $amount, 'next_call_at' => $next, 'from' => $request['status'], 'to' => $status,
            ]);
            return 'Call saved.' . ($status !== $request['status'] ? ' Status is now ' . str_replace('_', ' ', $status) . '.' : '');
        });
    }

    // ---------------- the money ----------------

    /**
     * Receive exactly the outstanding balance, keeping earlier payments.
     */
    public static function recordPayment(int $requestId, array $in, array $user): void
    {
        if (!Auth::can($user, 'sponsors')) {
            throw new UserError('Your role cannot record sponsor or exhibition payments.');
        }
        $amount = self::amount($in['amount_paid'] ?? '');
        $how = (string) ($in['paid_how'] ?? '');
        if (!array_key_exists($how, self::PAID_HOW)) {
            throw new UserError('Choose how they paid.');
        }
        Db::transaction(static function () use ($requestId, $amount, $how, $user): void {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === null) {
                throw new UserError('Request not found.');
            }
            if ($request['status'] !== 'agreed' || $request['amount_agreed'] === null) {
                throw new UserError('First save the call where they agreed on the amount (status Agreed). Then record the payment.');
            }
            $previousPaid = (int) ($request['amount_paid'] ?? 0);
            $balance = (int) $request['amount_agreed'] - $previousPaid;
            if ($amount === null || $amount !== $balance || $balance < 0) {
                throw new UserError('Record exactly the remaining ' . number_format(max(0, $balance)) . ' IQD. Use Edit details if the agreed total has changed.');
            }
            Db::update('sponsor_requests', [
                'status' => 'paid', 'amount_paid' => $previousPaid + $amount, 'paid_how' => $how, 'paid_at' => App::now(), 'updated_at' => App::now(),
            ], 'id = ?', [$requestId]);
            Audit::log((int) $user['id'], 'sponsor.paid', 'sponsor_request', $requestId, [
                'amount' => $amount, 'how' => $how, 'previous_paid' => $request['amount_paid'],
                'previous_how' => $request['paid_how'], 'previous_paid_at' => $request['paid_at'],
                'total_paid' => $previousPaid + $amount,
            ]);
        });
    }

    /** A payment recorded by mistake (Owner only): back to Agreed. */
    public static function undoPayment(int $requestId, array $user): void
    {
        if ($user['role'] !== 'owner') {
            throw new UserError('Only the Owner can undo a recorded payment.');
        }
        Db::transaction(static function () use ($requestId, $user): void {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$requestId]);
            if ($request === null || $request['status'] !== 'paid') {
                throw new UserError('Only a request that is Paid (not yet Confirmed) can be undone.');
            }
            $lastPayment = Db::one("SELECT details FROM audit_log WHERE target_type = 'sponsor_request' AND target_id = ? AND action IN ('sponsor.paid','sponsor.unpaid','sponsor.office_create') ORDER BY id DESC LIMIT 1", [$requestId]);
            $details = json_decode((string) ($lastPayment['details'] ?? ''), true) ?: [];
            $previousPaid = $details['previous_paid'] ?? null;
            Db::update('sponsor_requests', [
                'status' => 'agreed', 'amount_paid' => $previousPaid,
                'paid_how' => $previousPaid !== null ? ($details['previous_how'] ?? null) : null,
                'paid_at' => $previousPaid !== null ? ($details['previous_paid_at'] ?? null) : null,
                'updated_at' => App::now(),
            ], 'id = ?', [$requestId]);
            Audit::log((int) $user['id'], 'sponsor.unpaid', 'sponsor_request', $requestId, ['was' => $request['amount_paid'], 'how' => $request['paid_how'], 'restored_paid' => $previousPaid]);
        });
    }

    // ---------------- the details and the status ----------------

    /** The public map receives numbers only, never a request or company. */
    public static function bookedBooths(): array
    {
        $numbers = [];
        foreach (Db::all('SELECT reserved_booth FROM sponsor_requests WHERE reserved_booth IS NOT NULL') as $row) {
            $number = (string) $row['reserved_booth'];
            if (ctype_digit($number) && (int) $number >= 1 && (int) $number <= 44) {
                $numbers[] = (int) $number;
            }
        }
        sort($numbers, SORT_NUMERIC);
        return $numbers;
    }

    /** New assignments use this floor plan; preserve an unchanged legacy label. */
    public static function boothNumber(mixed $value, ?string $previous = null): ?string
    {
        $number = trim((string) $value);
        if ($number === '') {
            return null;
        }
        if (ctype_digit($number) && (int) $number >= 1 && (int) $number <= 44) {
            return (string) (int) $number;
        }
        if ($previous !== null && $number === $previous) {
            return $previous;
        }
        throw new UserError('Choose a booth number from 1 to 44, or leave it empty to release the booth.');
    }

    /** The database unique key also protects simultaneous staff reservations. */
    private static function updateWithBoothCheck(int $id, array $change): void
    {
        try {
            Db::update('sponsor_requests', $change, 'id = ?', [$id]);
        } catch (\PDOException $error) {
            if (($error->errorInfo[1] ?? 0) === 1062 && str_contains($error->getMessage(), 'uq_sponsor_reserved_booth')) {
                throw new UserError('That booth is already booked. Choose another booth number.');
            }
            throw $error;
        }
    }

    /** Edit contact details, package, agreed total and booth in one transaction. */
    public static function saveDetails(int $requestId, array $in, array $user): void
    {
        if (!Auth::can($user, 'sponsors')) {
            throw new UserError('Your role cannot edit sponsor or exhibition bookings.');
        }
        Db::transaction(static function () use ($requestId, $in, $user): void {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$requestId]) ?? throw new UserError('Request not found.');
            $packageId = Validate::text($in['package_id'] ?? '', 40);
            $package = SponsorPackages::find($packageId);
            if ($packageId !== '' && ($package === null || $package['kind'] !== $request['kind'])) {
                throw new UserError('That package is not for ' . ($request['kind'] === 'booth' ? 'exhibition booths.' : 'sponsors.'));
            }
            if ($package && $packageId !== $request['package_id'] && $request['status'] === 'confirmed') {
                $spot = self::spots()[$packageId];
                if ($spot['spots'] > 0 && $spot['confirmed'] >= $spot['spots'] && $user['role'] !== 'owner') {
                    throw new UserError("All {$spot['spots']} {$spot['name']} places are taken. (The Owner can do it.)");
                }
            }
            $assigned = (int) ($in['assigned_to'] ?? 0);
            $booth = self::boothNumber($in['booth_number'] ?? '', $request['booth_number']);
            BoothPlan::check($package, $booth);
            $change = [
                'package_id'   => $package ? $package['id'] : null,
                'assigned_to'  => $assigned > 0 && Db::value('SELECT id FROM admin_users WHERE id = ?', [$assigned]) ? $assigned : null,
                'booth_number' => $booth,
                'next_call_at' => self::dateTime((string) ($in['next_call_at'] ?? '')),
                'notes'        => Validate::multiline($in['notes'] ?? '', 5000) ?: null,
                'updated_at'   => App::now(),
            ];
            foreach (['company' => ['company', 160], 'contact' => ['contact_name', 120], 'role' => ['contact_role', 120], 'city' => ['city', 80], 'website' => ['website', 190]] as $field => [$column, $max]) {
                if (!array_key_exists($field, $in)) {
                    continue;
                }
                $value = Validate::text($in[$field], $max);
                if (in_array($field, ['company', 'contact'], true) && mb_strlen($value) < 2) {
                    throw new UserError('Enter the ' . ($field === 'company' ? 'company name' : 'contact person') . ' (at least two characters).');
                }
                $change[$column] = $value;
            }
            if (array_key_exists('phone', $in)) {
                $change['phone'] = Validate::phone(Validate::text($in['phone'], 30)) ?? throw new UserError('Enter a valid contact phone number.');
            }
            if (array_key_exists('email', $in)) {
                $email = strtolower(Validate::text($in['email'], 190));
                if ($email !== '' && !Validate::email($email)) {
                    throw new UserError('Enter a valid email address, or leave it empty.');
                }
                $change['email'] = $email;
            }
            if (array_key_exists('lang', $in)) {
                $change['lang'] = Validate::oneOf($in['lang'], ['en', 'ar', 'ku']) ?? throw new UserError('Choose English, Arabic or Kurdish.');
            }
            if (array_key_exists('amount_agreed', $in)) {
                $price = trim((string) $in['amount_agreed']);
                if ($price !== '' && (!preg_match('/^\d{1,10}$/D', $price) || (int) $price > 1000000000)) {
                    throw new UserError('Enter the agreed total in whole IQD (up to 1,000,000,000), or leave it empty.');
                }
                $total = $price === '' ? null : (int) $price;
                if ($total === null && ($request['amount_paid'] !== null || in_array($request['status'], ['agreed', 'paid', 'confirmed'], true))) {
                    throw new UserError('An agreed or paid booking needs an agreed total.');
                }
                if ($request['amount_paid'] !== null && $total < (int) $request['amount_paid']) {
                    throw new UserError('The agreed total cannot be less than the money already received.');
                }
                $change['amount_agreed'] = $total;
                if ($total !== null && !in_array($request['status'], ['declined', 'waiting_list'], true)) {
                    if ($request['amount_paid'] !== null) {
                        $change['status'] = $total > (int) $request['amount_paid'] ? 'agreed' : ($request['status'] === 'confirmed' ? 'confirmed' : 'paid');
                    } else {
                        $change['status'] = 'agreed';
                    }
                }
            }
            if ($request['amount_paid'] !== null && $package === null) {
                throw new UserError('Choose a package for this paid booking.');
            }
            self::updateWithBoothCheck($requestId, $change);
            unset($change['updated_at']);
            $before = $after = [];
            foreach ($change as $field => $value) {
                if ((string) ($request[$field] ?? '') !== (string) ($value ?? '')) {
                    $before[$field] = $request[$field];
                    $after[$field] = $value;
                }
            }
            Audit::log((int) $user['id'], 'sponsor.details', 'sponsor_request', $requestId, ['before' => $before, 'after' => $after]);
        });
    }

    /**
     * The status buttons. Agreed comes from a call (with the amount) and Paid
     * from the payment form, so they are not buttons. The rules:
     *  - Confirmed only after Paid (the database refuses it otherwise);
     *  - a package cannot have more Confirmed companies than places (Owner may override);
     *  - once paid, going back or declining is for the Owner only (no refunds).
     */
    public static function changeStatus(array $request, string $status, array $user, bool $override = false): void
    {
        Db::transaction(static function () use ($request, $status, $user, $override): void {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ? FOR UPDATE', [$request['id']]) ?? throw new UserError('Request not found.');
            if (!in_array($status, self::STATUSES, true)) {
                throw new UserError('Unknown status.');
            }
            if ($status === $request['status']) {
                return;
            }
            $isOwner = $user['role'] === 'owner';
            if ($status === 'paid' && !($request['status'] === 'confirmed' && $isOwner)) {
                throw new UserError('Use "Record the payment" with the exact amount.');
            }
            if ($status === 'agreed' && $request['amount_agreed'] === null) {
                throw new UserError('Save the call where they agreed, with the amount. That makes it Agreed.');
            }
            $wasPaid = $request['amount_paid'] !== null;
            if ($wasPaid && $status !== 'confirmed' && !$isOwner) {
                throw new UserError('They have already paid. Only the Owner can change that.');
            }
            if ($wasPaid && in_array($status, ['new', 'contacted', 'agreed', 'waiting_list', 'declined'], true)) {
                // Out of paid: the money record goes with it (kept in the history).
                $extra = ['amount_paid' => null, 'paid_how' => null, 'paid_at' => null];
            }
            if ($status === 'confirmed') {
                if ($request['status'] !== 'paid') {
                    throw new UserError('A company can be confirmed only after its payment is recorded.');
                }
                if ($request['amount_paid'] === null || (int) $request['amount_paid'] !== (int) $request['amount_agreed']) {
                    throw new UserError('Record the full remaining balance before confirming this booking.');
                }
                if ($request['package_id']) {
                    $spot = self::spots()[$request['package_id']] ?? null;
                    if ($spot && $spot['spots'] > 0 && $spot['confirmed'] >= $spot['spots'] && !($override && $isOwner)) {
                        throw new UserError("All {$spot['spots']} {$spot['name']} places are already confirmed. (The Owner can override.)");
                    }
                }
            }
            self::updateWithBoothCheck((int) $request['id'], ['status' => $status, 'updated_at' => App::now()] + ($extra ?? []));
            Audit::log((int) $user['id'], 'sponsor.status', 'sponsor_request', (int) $request['id'], [
                'from' => $request['status'], 'to' => $status, 'override' => $override && $isOwner,
            ] + (isset($extra) ? ['payment_removed' => $request['amount_paid']] : []));
        });
    }

    /** Calls due now or earlier (for the dashboard, and per tab on the sponsor page). */
    public static function callsDue(?string $kind = null): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE next_call_at IS NOT NULL AND next_call_at <= ? AND status NOT IN ('confirmed','declined')"
            . ($kind !== null ? ' AND kind = ?' : ''), $kind !== null ? [App::now(), $kind] : [App::now()]);
    }

    private static function amount(mixed $value): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) > 10 || (int) $digits > 1000000000) {
            throw new UserError('That amount is too big. Check the number of zeros.');
        }
        return (int) $digits;
    }

    /** "2026-10-03T14:30" (from the date box) → "2026-10-03 14:30:00", or null. */
    private static function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value) ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            throw new UserError('The next call date is not valid.');
        }
        return $date->format('Y-m-d H:i:s');
    }
}
