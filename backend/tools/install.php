<?php
// One-time setup, run on the server's command line (cPanel -> Terminal):
//
//   php backend/tools/install.php                 creates the tables
//   php backend/tools/install.php --owner you@example.com "Your Name"
//                                                 also creates the first Owner account
//                                                 (asks for the password, 12+ characters)
//   php backend/tools/install.php --viewer ismile_viewer [host]
//                                                 creates a READ-ONLY database login that
//                                                 sees only the simple numbered lists
//                                                 (01_registered_people …), for MySQL Workbench
//   php backend/tools/install.php --set-password you@example.com
//                                                 a new password for an existing account
//
// Safe to run again: tables that exist are left as they are.

declare(strict_types=1);

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../bootstrap.php';

/** The statements in one .sql file (each ends with ";" at the end of a line). */
$statements = static function (string $file): array {
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file)) ?? '';
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql) ?: []), static fn ($s) => $s !== ''));
};
$run = static function (array $list): void {
    foreach ($list as $statement) {
        App::db()->exec($statement);
    }
};

// 1. The full current design: tables first. On a new database this creates
//    everything; on an existing one it only adds tables that are missing.
//    The views come last (step 3), after any upgrade has added new columns.
$isNew = !Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'registrations'");
$design = $statements(__DIR__ . '/../database/schema.sql');
$isView = static fn (string $s): bool => (bool) preg_match('/^CREATE\s+OR\s+REPLACE\s+.*?VIEW/is', $s);
$run(array_filter($design, static fn ($s) => !$isView($s)));

// 2. Upgrades for a database that is already in use, each applied once, in
//    order. A new database already has them all (schema.sql is always
//    current), so they are only recorded.
$migrations = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($migrations);
foreach ($migrations as $file) {
    $name = basename($file);
    if (Db::value('SELECT name FROM schema_migrations WHERE name = ?', [$name])) {
        continue;
    }
    if (!$isNew) {
        echo "Applying upgrade $name ... ";
        $run($statements($file));
        echo "done\n";
    }
    Db::run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, App::now()]);
}

// 3. The simple lists (views), always rebuilt from the current design. Lists
//    from an older design that are no longer in it are removed.
$run(array_filter($design, $isView));
preg_match_all('/VIEW\s+`([^`]+)`/i', implode("\n", array_filter($design, $isView)), $found);
foreach (Db::all('SELECT table_name AS name FROM information_schema.views WHERE table_schema = DATABASE()') as $view) {
    if (!in_array($view['name'], $found[1], true) && preg_match('/^[a-z0-9_]+$/', $view['name'])) {
        App::db()->exec("DROP VIEW `{$view['name']}`");
    }
}

// 4. The workshops: on a first install, the ones already on the website become
//    the starting list; then the website's cards are written from the database.
$imported = \Ismile\Workshops::importFromWebsite();
\Ismile\Workshops::syncWebsite();
if ($imported > 0) {
    echo "Workshops copied from the website: $imported\n";
}
// 5. The sponsor packages the same way (the tiers on the website, plus one
//    standard exhibition booth). Their prices are set in the admin.
$imported = \Ismile\SponsorPackages::importFromWebsite();
\Ismile\SponsorPackages::syncWebsite();
if ($imported > 0) {
    echo "Sponsor packages copied from the website: $imported (set their prices in the admin: Sponsors)\n";
}

$kinds = ['BASE TABLE' => [], 'VIEW' => []];
foreach (Db::all('SHOW FULL TABLES') as $row) {
    [$tableName, $type] = array_values($row);
    $kinds[$type][] = $tableName;
}
echo 'Tables ready (' . count($kinds['BASE TABLE']) . '): ' . implode(', ', $kinds['BASE TABLE']) . "\n";
echo 'Views ready (' . count($kinds['VIEW']) . '): ' . implode(', ', $kinds['VIEW']) . "\n";

/** A password from the environment, or typed (not shown). */
$askPassword = static function (string $env, string $for): string {
    $password = getenv($env) ?: '';
    if ($password === '') {
        echo "Password for $for (12+ characters, not shown): ";
        if (DIRECTORY_SEPARATOR === '/') {
            system('stty -echo');
        }
        $password = trim((string) fgets(STDIN));
        if (DIRECTORY_SEPARATOR === '/') {
            system('stty echo');
        }
        echo "\n";
    }
    return $password;
};

// The read-only login: SELECT on the numbered lists only, never on the tables.
$viewerAt = array_search('--viewer', $argv, true);
if ($viewerAt !== false) {
    $viewer = (string) ($argv[$viewerAt + 1] ?? 'ismile_viewer');
    $host = (string) ($argv[$viewerAt + 2] ?? 'localhost');
    if (!preg_match('/^[a-z0-9_]{3,32}$/', $viewer) || !preg_match('/^[a-zA-Z0-9.%_-]{1,60}$/', $host)) {
        exit("Viewer name: 3-32 lower-case letters, digits or _. Host: e.g. localhost.\n");
    }
    $password = $askPassword('ISMILE_VIEWER_PASSWORD', "the viewer $viewer");
    if (!Auth::validPassword($password)) {
        exit("The password must have at least 12 characters.\n");
    }
    $account = "'$viewer'@'$host'";
    $database = (string) Db::value('SELECT DATABASE()');
    try {
        App::db()->exec("CREATE USER IF NOT EXISTS $account IDENTIFIED BY " . App::db()->quote($password));
        App::db()->exec("ALTER USER $account IDENTIFIED BY " . App::db()->quote($password));
        foreach ($found[1] as $view) {
            if (preg_match('/^[0-9]{2}_[a-z0-9_]+$/', $view)) {
                App::db()->exec("GRANT SELECT ON `$database`.`$view` TO $account");
            }
        }
        echo "Read-only login ready: $viewer (sees only the numbered lists, cannot change anything).\n";
    } catch (\PDOException $error) {
        echo "The read-only login could not be made: this database account may not create logins.\n"
            . 'Ask the server administrator to run it with the root account. (' . $error->getMessage() . ")\n";
    }
}

$ownerAt = array_search('--owner', $argv, true);
if ($ownerAt !== false) {
    $email = (string) ($argv[$ownerAt + 1] ?? '');
    $name = (string) ($argv[$ownerAt + 2] ?? 'Owner');
    if (Db::value('SELECT id FROM admin_users WHERE email = ?', [strtolower($email)])) {
        exit("An account for $email already exists.\n");
    }
    $password = $askPassword('ISMILE_OWNER_PASSWORD', $email);
    if (!Auth::validPassword($password)) {
        exit("The password must have at least 12 characters.\n");
    }
    $id = Auth::createUser($email, $name, 'owner', $password);
    echo "Owner account created (#$id). Sign in at " . App::url('admin/') . " and set up the phone code.\n";
}

// A new password for an account that already exists (forgotten password, or a
// password that was seen by someone else). Also unlocks it, switches it back
// on and signs it out everywhere. The phone code stays as it is.
$resetAt = array_search('--set-password', $argv, true);
if ($resetAt !== false) {
    $email = strtolower((string) ($argv[$resetAt + 1] ?? ''));
    $id = (int) Db::value('SELECT id FROM admin_users WHERE email = ?', [$email]);
    if ($id === 0) {
        exit("There is no account for $email.\n");
    }
    $password = $askPassword('ISMILE_OWNER_PASSWORD', $email);
    if (!Auth::validPassword($password)) {
        exit("The password must have at least 12 characters.\n");
    }
    Db::run(
        'UPDATE admin_users SET password_hash = ?, failed_logins = 0, locked_until = NULL, disabled_at = NULL,'
        . ' session_version = session_version + 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $id]
    );
    echo "New password set for $email (#$id).\n";
}
