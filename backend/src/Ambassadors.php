<?php
// Ambassador codes can be removed without changing the codes recorded on tickets.

declare(strict_types=1);

namespace Ismile;

final class Ambassadors
{
    public static function save(array $in, array $user): string
    {
        self::requireOwner($user);
        $code = Validate::text($in['code'] ?? '', 40);
        $name = Validate::text($in['owner_name'] ?? '', 120);
        if ($code === '' || $name === '') {
            throw new UserError('Code and ambassador name are required.');
        }
        if (!preg_match('/^[\p{L}\p{N}\-_. ]{1,40}$/u', $code)) {
            throw new UserError('Use letters, numbers, spaces, hyphens, dots or underscores in the code.');
        }
        Db::transaction(static function () use ($in, $user, $code, $name): void {
            Db::run('INSERT INTO ambassadors (code, owner_name, university, active, created_at) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE owner_name = VALUES(owner_name), university = VALUES(university), active = 1',
                [$code, $name, Validate::text($in['university'] ?? '', 160) ?: null, App::now()]);
            Audit::log((int) $user['id'], 'ambassador.save', null, null, ['code' => $code]);
        });
        return "Ambassador code $code saved.";
    }

    public static function delete(int $id, array $user): string
    {
        self::requireOwner($user);
        return Db::transaction(static function () use ($id, $user): string {
            $row = Db::one('SELECT * FROM ambassadors WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null) {
                throw new UserError('That ambassador code has already been deleted or cannot be found.');
            }
            Db::run('DELETE FROM ambassadors WHERE id = ?', [$id]);
            Audit::log((int) $user['id'], 'ambassador.delete', 'ambassador', $id, [
                'code' => $row['code'], 'owner_name' => $row['owner_name'], 'university' => $row['university'],
            ]);
            return "Ambassador code {$row['code']} deleted. Existing registrations keep their recorded code.";
        });
    }

    private static function requireOwner(array $user): void
    {
        if (($user['role'] ?? '') !== 'owner') {
            throw new UserError('Only the Owner can manage ambassador codes.');
        }
    }
}
