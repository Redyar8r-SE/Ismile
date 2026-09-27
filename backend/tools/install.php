<?php
// One-time setup, run on the server's command line (cPanel -> Terminal):
//
//   php backend/tools/install.php                 creates the tables
//   php backend/tools/install.php --owner you@example.com "Your Name"
//                                                 also creates the first Owner account
//                                                 (asks for the password, 12+ characters)
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

// 3. The read-only views, always rebuilt from the current design.
$run(array_filter($design, $isView));

// 4. The workshops: on a first install, the ones already on the website become
//    the starting list; then the website's cards are written from the database.
$imported = \Ismile\Workshops::importFromWebsite();
\Ismile\Workshops::syncWebsite();
if ($imported > 0) {
    echo "Workshops copied from the website: $imported
";
}

$kinds = ['BASE TABLE' => [], 'VIEW' => []];
foreach (Db::all('SHOW FULL TABLES') as $row) {
    [$tableName, $type] = array_values($row);
    $kinds[$type][] = $tableName;
}
echo 'Tables ready (' . count($kinds['BASE TABLE']) . '): ' . implode(', ', $kinds['BASE TABLE']) . "\n";
echo 'Views ready (' . count($kinds['VIEW']) . '): ' . implode(', ', $kinds['VIEW']) . "\n";

$ownerAt = array_search('--owner', $argv, true);
if ($ownerAt !== false) {
    $email = (string) ($argv[$ownerAt + 1] ?? '');
    $name = (string) ($argv[$ownerAt + 2] ?? 'Owner');
    if (Db::value('SELECT id FROM admin_users WHERE email = ?', [strtolower($email)])) {
        exit("An account for $email already exists.\n");
    }
    $password = getenv('ISMILE_OWNER_PASSWORD') ?: '';
    if ($password === '') {
        echo 'Password for ' . $email . ' (12+ characters, not shown): ';
        if (DIRECTORY_SEPARATOR === '/') {
            system('stty -echo');
        }
        $password = trim((string) fgets(STDIN));
        if (DIRECTORY_SEPARATOR === '/') {
            system('stty echo');
        }
        echo "\n";
    }
    if (!Auth::validPassword($password)) {
        exit("The password must have at least 12 characters.\n");
    }
    $id = Auth::createUser($email, $name, 'owner', $password);
    echo "Owner account created (#$id). Sign in at " . App::url('admin/') . " and set up the phone code.\n";
}
