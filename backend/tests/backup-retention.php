<?php
// php -d extension=mbstring backend/tests/backup-retention.php
// Uses disposable files only; no database, mail or live storage is touched.
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Ismile\\')) require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
});

use Ismile\App;
use Ismile\Backup;

$root = sys_get_temp_dir() . '/ismile-retention-check-' . bin2hex(random_bytes(6));
App::boot(['site_url'=>'http://localhost', 'site_root'=>$root, 'storage'=>$root, 'db'=>['name'=>'unused'], 'secret'=>str_repeat('retention-test-', 3)]);
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$sql = "-- iSmile backup retention test\nSET FOREIGN_KEY_CHECKS = 0;\nSET FOREIGN_KEY_CHECKS = 1;\n";
$files = [];
$put = static function (int $number, int $age, bool $valid = true) use (&$files, $sql): string {
    $name = 'ismile-2026-10-06-030000-' . sprintf('%06x', $number) . '.sql.gz';
    $path = App::storage('backups/' . $name);
    file_put_contents($path, gzencode($valid ? $sql : "-- iSmile backup retention test\npartial\n"));
    touch($path, time() - $age);
    $files[] = $path;
    return $name;
};
$prune = new ReflectionMethod(Backup::class, 'prune');
try {
    $check('retention is five copies', Backup::KEEP_COUNT === 5);
    $names = [];
    for ($id = 1; $id <= 5; $id++) $names[] = $put($id, (6 - $id) * 86400);
    $prune->invoke(null, $names[4]);
    $check('five existing copies are preserved', count(Backup::available()) === 5);
    $failed = $put(6, 0, false);
    $prune->invoke(null, $failed);
    $check('an incomplete sixth copy cannot delete history', count(Backup::available()) === 5 && is_file(App::storage('backups/' . $names[0])));
    $sixth = $put(7, 0);
    $prune->invoke(null, $sixth);
    $available = array_column(Backup::available(), 'name');
    $expected = [$sixth, $names[4], $names[3], $names[2], $names[1]];
    $check('a verified sixth copy retains the newest five', $available === $expected);
    $check('oldest file is removed', !is_file(App::storage('backups/' . $names[0])));
    $check('oldest verification sidecar is removed', !is_file(App::storage('backups/' . $names[0] . '.verified.json')));
    // Name suffixes are random, so name order alone cannot choose this run.
    $tied = $put(0, 0);
    $prune->invoke(null, $tied);
    $available = array_column(Backup::available(), 'name');
    $check('the new copy is kept when timestamps tie', count($available) === 5 && in_array($tied, $available, true));
    $check('another oldest copy rotates out on the next success', !is_file(App::storage('backups/' . $names[1])));
    // Copy count replaces the former 30-day rule.
    foreach ($files as $file) {
        if (is_file($file)) touch($file, time() - 45 * 86400);
    }
    $prune->invoke(null, $tied);
    $check('five copies are kept even when older than thirty days', count(Backup::available()) === 5);
    $check('repeated cleanup does not remove more copies', count(Backup::available()) === 5);
    if (in_array('--database', $argv, true)) {
        $configPath = getenv('ISMILE_CONFIG');
        if (!$configPath || !is_file($configPath)) throw new RuntimeException('Set ISMILE_CONFIG to an isolated test configuration.');
        $databaseConfig = require $configPath;
        if (($databaseConfig['env'] ?? null) !== 'test') throw new RuntimeException('Database integration checks require a test configuration.');
        // Read the test database, but write and rotate only our temporary copies.
        App::boot(array_replace($databaseConfig, ['storage' => $root]));
        $before = array_column(Backup::available(), 'name');
        $export = Backup::run();
        $files[] = App::storage('backups/' . $export);
        $after = array_column(Backup::available(), 'name');
        $check('a real successful export rotates history to five copies', count($after) === 5 && in_array($export, $after, true));
        $check('four previous copies remain after a real export', count(array_intersect($before, $after)) === 4);
        $check('the retained new database export passes full verification', Backup::find($export) !== null);
    }
    echo "$passed retention checks passed.\n";
} finally {
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
        if (is_file($file . '.verified.json')) unlink($file . '.verified.json');
    }
    unlink($root . '/.htaccess');
    if (is_file($root . '/tmp/backup.lock')) unlink($root . '/tmp/backup.lock');
    foreach (['backups', 'tmp', 'logs', 'outbox'] as $folder) rmdir($root . '/' . $folder);
    rmdir($root);
}
