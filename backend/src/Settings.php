<?php
// Settings the Owner changes on the admin's Settings page. Prices are not
// here: they stay in data/tickets.json where the content admin edits them.

declare(strict_types=1);

namespace Ismile;

final class Settings
{
    // Safe defaults: registration closed and every email going to the test
    // address until someone deliberately opens it.
    public const DEFAULTS = [
        'registration_open'     => '0',
        'program_hidden'        => '1',
        'website_controls_migrated' => '0',
        // Empty = the page's own wording ("Online registration is not open yet…").
        // The Owner can write a custom one in Settings, e.g. with the opening date.
        'closed_message_en'     => '',
        'closed_message_ar'     => '',
        'closed_message_ku'     => '',
        'ticket_capacity'       => '0',     // 0 = no limit set yet (the dashboard warns)
        'lunch_capacity_day1'   => '0',
        'lunch_capacity_day2'   => '0',
        'email_test_mode'       => '1',
        'email_test_address'    => '',
        'sponsor_notify_email'  => '',
        'summit_end'            => '2026-11-21',
        'photo_keep_days'       => '90',
        'ticket_qr_in_email'    => '1',     // Legacy setting; ticket emails always include the QR and PDF.
        'event_day1'            => '2026-11-20',
        'event_day2'            => '2026-11-21',
        'pay_link_days'         => '7',
        // Not on the Settings page: the prices the timed job last saw, so any
        // change to data/tickets.json is noticed, logged and announced.
        'prices_seen'           => '',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = [];
            foreach (Db::all('SELECT k, v FROM settings') as $row) {
                $stored[$row['k']] = (string) $row['v'];
            }
            self::$cache = array_merge(self::DEFAULTS, $stored);
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, string $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown setting $key");
        }
        Db::run(
            'INSERT INTO settings (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [$key, $value, App::now()]
        );
        self::$cache = null;
    }

    public static function closedMessage(string $lang): string
    {
        return self::get('closed_message_' . Lang::pick($lang));
    }

    /** Import the old switches once, preserving the effective visitor state. */
    public static function migrateWebsiteControls(): void
    {
        if (self::bool('website_controls_migrated')) return;
        Db::transaction(static function (): void {
            // Serialize installers so a later deployment cannot restore old values.
            Db::run("INSERT IGNORE INTO settings (k, v, updated_at) VALUES ('website_controls_migrated', '0', ?)", [App::now()]);
            $done = Db::value("SELECT v FROM settings WHERE k = 'website_controls_migrated' FOR UPDATE");
            if ($done === '1') return;
            if ((SiteData::read('tickets')['registrationClosed'] ?? false) === true) {
                self::set('registration_open', '0');
            }
            $program = SiteData::read('program');
            self::set('program_hidden', ($program['toBeAnnounced'] ?? true) === true ? '1' : '0');
            self::set('website_controls_migrated', '1');
        });
        self::$cache = null;
    }
}
