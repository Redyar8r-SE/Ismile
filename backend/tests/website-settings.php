<?php
// ISMILE_CONFIG=/isolated/config.php php backend/tests/website-settings.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;
use Ismile\Checkouts;
use Ismile\UserError;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_website_test_')) {
    throw new RuntimeException('Use an isolated ismile_website_test_* database and content folder.');
}
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++; echo "PASS: $name\n";
};
$write = static function (string $name, array $data): void {
    file_put_contents(App::siteFile("data/$name.json"), json_encode($data));
};

$write('tickets', ['currency' => 'IQD', 'professional' => 50000, 'student' => 25000, 'registrationClosed' => true]);
$write('program', ['toBeAnnounced' => true, 'days' => [['id' => '1', 'sessions' => [['title' => 'Saved session']]]]]);
Settings::set('registration_open', '1');
Settings::set('website_controls_migrated', '0');
Settings::migrateWebsiteControls();
$check('migration preserves closed registration', !Registrations::isOpen());
$check('migration preserves hidden program', Settings::bool('program_hidden'));
$check('database rows store both switches', Db::value("SELECT v FROM settings WHERE k = 'registration_open'") === '0' && Db::value("SELECT v FROM settings WHERE k = 'program_hidden'") === '1');

Settings::set('registration_open', '1');
Settings::set('program_hidden', '0');
$check('opening registration ignores the old JSON switch', Registrations::isOpen() && !SiteData::closedBySwitch());
$check('registration config follows database state', Registrations::publicState('en')['open'] === true);
Settings::migrateWebsiteControls();
$check('repeat installation preserves new Settings values', Registrations::isOpen() && !Settings::bool('program_hidden'));
$check('program sessions survive visibility changes', SiteData::read('program')['days'][0]['sessions'][0]['title'] === 'Saved session');

Settings::set('registration_open', '0');
$write('tickets', ['currency' => 'IQD', 'professional' => 50000, 'student' => 25000, 'registrationClosed' => false]);
$check('closing registration overrides old open JSON', Registrations::publicState('en')['reason'] === 'closed');
try {
    Checkouts::createFromForm(['lang' => 'en'], null);
    $check('closed registration blocks server submission', false);
} catch (UserError $error) {
    $check('closed registration blocks server submission', $error->key === 'reg_closed' && $error->status === 409);
}
Settings::set('closed_message_en', 'Registration opens on Friday');
$check('closed wording reaches public configuration', Registrations::publicState('en')['message'] === 'Registration opens on Friday');

// Exercise the other migration direction with a fresh marker.
Settings::set('website_controls_migrated', '0');
Settings::set('registration_open', '1');
$write('program', ['toBeAnnounced' => false, 'days' => []]);
Settings::migrateWebsiteControls();
$check('migration preserves open registration and visible program', Registrations::isOpen() && !Settings::bool('program_hidden'));
echo "$passed website Settings checks passed.\n";
