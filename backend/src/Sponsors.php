<?php
// Sponsorship and exhibition-booth requests. Never sold like tickets: they are
// requests a person handles, and the database keeps the record.

declare(strict_types=1);

namespace Ismile;

final class Sponsors
{
    public const STATUSES = ['new', 'contacted', 'agreed', 'paid', 'confirmed', 'declined', 'waiting_list'];

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM sponsor_requests WHERE id = ?', [$id]);
    }

    public static function createFromForm(array $in): array
    {
        $lang = Lang::pick($in['lang'] ?? 'en');
        $kind = Validate::oneOf($in['kind'] ?? null, ['sponsor', 'booth'], 'sponsor');
        $package = Validate::text($in['package'] ?? '', 40);
        $validPackages = array_merge(array_map(static fn ($tier) => (string) ($tier['id'] ?? ''), SiteData::sponsorTiers()), ['unsure', '']);
        if (!in_array($package, $validPackages, true)) {
            $package = 'unsure';
        }
        if ($kind === 'booth') {
            $package = '';
        }
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
            'kind' => $kind, 'package' => $package ?: null, 'company' => $company, 'contact_name' => $contact,
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
        Outbox::queue('sponsor_received', null, [], (int) $id, $email, $lang);
        $team = Settings::get('sponsor_notify_email');
        $teamList = Validate::email($team) ? [$team] : (array) App::config('alerts_to', []);
        foreach ($teamList as $address) {
            if (Validate::email((string) $address)) {
                Outbox::queue('sponsor_notify', null, [], (int) $id, (string) $address, 'en');
            }
        }
        return $request;
    }

    /** Spots per tier (from the admin's sponsor tiers) and how many are confirmed. */
    public static function spots(): array
    {
        $confirmed = [];
        foreach (Db::all("SELECT package, COUNT(*) AS n FROM sponsor_requests WHERE status = 'confirmed' AND kind = 'sponsor' GROUP BY package") as $row) {
            $confirmed[(string) $row['package']] = (int) $row['n'];
        }
        $out = [];
        foreach (SiteData::sponsorTiers() as $tier) {
            $id = (string) ($tier['id'] ?? '');
            $name = $tier['name'] ?? $id;
            $out[$id] = [
                'name'      => is_array($name) ? (string) ($name['en'] ?? $id) : (string) $name,
                'spots'     => (int) ($tier['spots'] ?? 0),
                'confirmed' => $confirmed[$id] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Moves a request to a new status. The two rules from the plan:
     *  - it cannot become Confirmed before it is Paid (Owner may override, logged);
     *  - a tier cannot have more Confirmed sponsors than it has spots (Owner may override).
     */
    public static function changeStatus(array $request, string $status, array $user, bool $override = false): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new UserError('Unknown status.');
        }
        $isOwner = $user['role'] === 'owner';
        if ($status === 'confirmed') {
            if ($request['status'] !== 'paid' && !($override && $isOwner)) {
                throw new UserError('A request can be confirmed only after it is marked Paid. (The Owner can override.)');
            }
            if ($request['kind'] === 'sponsor' && $request['package']) {
                $tier = self::spots()[$request['package']] ?? null;
                $others = $tier ? (int) $tier['confirmed'] - ($request['status'] === 'confirmed' ? 1 : 0) : 0;
                if ($tier && $tier['spots'] > 0 && $others >= $tier['spots'] && !($override && $isOwner)) {
                    throw new UserError("All {$tier['spots']} {$tier['name']} spots are already confirmed. (The Owner can override.)");
                }
            }
        }
        Db::update('sponsor_requests', ['status' => $status, 'updated_at' => App::now()], 'id = ?', [$request['id']]);
        Audit::log((int) $user['id'], 'sponsor.status', 'sponsor_request', (int) $request['id'], [
            'from' => $request['status'], 'to' => $status, 'override' => $override && $isOwner,
        ]);
    }
}
