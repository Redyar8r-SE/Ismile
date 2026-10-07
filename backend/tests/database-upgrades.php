<?php
// Upgrade a fresh isolated database from an explicitly supplied legacy schema.
// ISMILE_CONFIG=/isolated/config.php php backend/tests/database-upgrades.php /path/to/legacy-schema.sql
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Db;

if (App::isLive() || !str_starts_with((string) App::config('db.name'), 'ismile_upgrade_test_')) {
    throw new RuntimeException('Use a fresh isolated ismile_upgrade_test_* database.');
}
if (Db::value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')) {
    throw new RuntimeException('The upgrade test database must be empty.');
}
$schema = $argv[1] ?? '';
if (!is_file($schema)) throw new RuntimeException('Supply the previous release schema.');
$sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($schema)) ?? '';
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: [])) as $statement) {
    App::db()->exec($statement);
}
foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
    $name = basename($file);
    if (strcmp($name, '2026-10-06-two-day-attendance.sql') < 0) {
        Db::run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, App::now()]);
    }
}
$check = static function (string $name, bool $ok): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    echo "PASS: $name\n";
};
$check('legacy schema has no attendance table', !Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ticket_attendance'"));
$check('legacy sponsor schema has no cancellation column', !Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='sponsor_requests' AND column_name='cancelled_at'"));
require __DIR__ . '/../tools/install.php';
$check('all three registration upgrades recorded', (int) Db::value("SELECT COUNT(*) FROM schema_migrations WHERE name IN ('2026-10-06-two-day-attendance.sql','2026-10-07-manual-checkin.sql','2026-10-07-separate-sponsors-booths.sql')") === 3);
$check('manual admission column present', (int) Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_attendance' AND column_name='checkin_method'") === 1);
$check('certificate table present', (int) Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='certificates'") === 1);
$views = Db::all('SELECT table_name AS name FROM information_schema.views WHERE table_schema=DATABASE()');
foreach ($views as $view) {
    $name = $view['name'];
    if (!preg_match('/^[a-z0-9_]+$/', $name)) throw new RuntimeException('Unexpected view name.');
    App::db()->query("SELECT * FROM `$name` LIMIT 0");
}
$check('all 14 views readable', count($views) === 14);
$check('one Standard booth available', (int) Db::value("SELECT COUNT(*) FROM sponsor_packages WHERE kind='booth' AND status='active'") === 1 && (bool) Db::value("SELECT id FROM sponsor_packages WHERE id='booth-standard' AND status='active'"));
// Resume a deployment interrupted after the table was created, before this
// migration was recorded. The existing column must not be added again.
Db::run('DELETE FROM schema_migrations WHERE name=?', ['2026-10-07-manual-checkin.sql']);
require __DIR__ . '/../tools/install.php';
$check('interrupted deployment resumes without duplicate column', (bool) Db::value('SELECT name FROM schema_migrations WHERE name=?', ['2026-10-07-manual-checkin.sql']));
// Also exercise the ALTER path for an installation that already had attendance
// before the manual-lookup feature, then confirm installation is repeatable.
App::db()->exec('ALTER TABLE ticket_attendance DROP COLUMN checkin_method');
Db::run('DELETE FROM schema_migrations WHERE name=?', ['2026-10-07-manual-checkin.sql']);
require __DIR__ . '/../tools/install.php';
$check('pre-existing attendance gains manual admission column', (int) Db::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_attendance' AND column_name='checkin_method'") === 1);
require __DIR__ . '/../tools/install.php';
$check('repeated installation keeps all upgrades recorded once', (int) Db::value("SELECT COUNT(*) FROM schema_migrations WHERE name IN ('2026-10-06-two-day-attendance.sql','2026-10-07-manual-checkin.sql','2026-10-07-separate-sponsors-booths.sql')") === 3);
echo "Database upgrade regression checks passed.\n";
