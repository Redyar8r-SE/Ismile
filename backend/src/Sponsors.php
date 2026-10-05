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
            $done = in_array($request['status'], ['paid', 'confirmed'], true);
            if ($done && in_array($outcome, ['agreed', 'declined'], true)) {
                throw new UserError('They have already paid, so the call cannot change the amount or decline them. Save it as "Talked to them" with a note.');
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
            Db::update('sponsor_requests', $change, 'id = ?', [$requestId]);
            Audit::log((int) $user['id'], 'sponsor.call', 'sponsor_request', $requestId, [
                'outcome' => $outcome, 'amount' => $amount, 'next_call_at' => $next, 'from' => $request['status'], 'to' => $status,
            ]);
            return 'Call saved.' . ($status !== $request['status'] ? ' Status is now ' . str_replace('_', ' ', $status) . '.' : '');
        });
    }

    // ---------------- the money ----------------

    /**
     * The payment, recorded only when it is EXACTLY the agreed amount (a
     * different amount means the agreement must be changed first).
     */
    public static function recordPayment(int $requestId, array $in, array $user): void
    {
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
            if ($amount === null || $amount !== (int) $request['amount_agreed']) {
                throw new UserError('The amount must be exactly the agreed ' . number_format((int) $request['amount_agreed']) . ' IQD. If they agreed a different amount, save a new "Agreed" call first.');
            }
            Db::update('sponsor_requests', [
                'status' => 'paid', 'amount_paid' => $amount, 'paid_how' => $how, 'paid_at' => App::now(), 'updated_at' => App::now(),
            ], 'id = ?', [$requestId]);
            Audit::log((int) $user['id'], 'sponsor.paid', 'sponsor_request', $requestId, ['amount' => $amount, 'how' => $how]);
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
            Db::update('sponsor_requests', ['status' => 'agreed', 'amount_paid' => null, 'paid_how' => null, 'paid_at' => null, 'updated_at' => App::now()], 'id = ?', [$requestId]);
            Audit::log((int) $user['id'], 'sponsor.unpaid', 'sponsor_request', $requestId, ['was' => $request['amount_paid'], 'how' => $request['paid_how']]);
        });
    }

    // ---------------- the details and the status ----------------

    /** Package (must be of the same kind), booth number, who handles it, notes, next call. */
    public static function saveDetails(int $requestId, array $in, array $user): void
    {
        $request = self::find($requestId) ?? throw new UserError('Request not found.');
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
        $change = [
            'package_id'   => $package ? $package['id'] : null,
            'assigned_to'  => $assigned > 0 && Db::value('SELECT id FROM admin_users WHERE id = ?', [$assigned]) ? $assigned : null,
            'booth_number' => Validate::text($in['booth_number'] ?? '', 20) ?: null,
            'next_call_at' => self::dateTime((string) ($in['next_call_at'] ?? '')),
            'notes'        => Validate::multiline($in['notes'] ?? '', 5000) ?: null,
            'updated_at'   => App::now(),
        ];
        Db::update('sponsor_requests', $change, 'id = ?', [$requestId]);
        unset($change['updated_at'], $change['notes']);
        Audit::log((int) $user['id'], 'sponsor.details', 'sponsor_request', $requestId, $change);
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
        $wasPaid = in_array($request['status'], ['paid', 'confirmed'], true);
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
            if ($request['package_id']) {
                $spot = self::spots()[$request['package_id']] ?? null;
                if ($spot && $spot['spots'] > 0 && $spot['confirmed'] >= $spot['spots'] && !($override && $isOwner)) {
                    throw new UserError("All {$spot['spots']} {$spot['name']} places are already confirmed. (The Owner can override.)");
                }
            }
        }
        Db::update('sponsor_requests', ['status' => $status, 'updated_at' => App::now()] + ($extra ?? []), 'id = ?', [$request['id']]);
        Audit::log((int) $user['id'], 'sponsor.status', 'sponsor_request', (int) $request['id'], [
            'from' => $request['status'], 'to' => $status, 'override' => $override && $isOwner,
        ] + (isset($extra) ? ['payment_removed' => $request['amount_paid']] : []));
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
