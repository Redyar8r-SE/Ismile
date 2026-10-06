<?php
// ISMILE_CONFIG=/isolated/test/config.php php backend/tests/backup-streaming.php
// Writes a temporary 32 MiB fixture, exports it, then removes both.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Backup;

if (App::config('env') !== 'test') throw new RuntimeException('Use an isolated test database only.');
$db = App::db();
$table = 'backup_stream_probe_' . bin2hex(random_bytes(4));
$created = false;
$backup = null;
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
$buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
$originalLimit = ini_get('memory_limit');
try {
    $db->exec("CREATE TABLE `$table` (id INT PRIMARY KEY, payload MEDIUMBLOB NOT NULL) ENGINE=InnoDB");
    $created = true;
    $insert = $db->prepare("INSERT INTO `$table` VALUES (?, ?)");
    $payload = random_bytes(131072);
    $db->beginTransaction();
    for ($id = 1; $id <= 256; $id++) $insert->execute([$id, $payload]);
    $db->commit();
    $insert = null;
    unset($payload);
    ini_set('memory_limit', '32M');
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $baseline = memory_get_usage();
    $backup = Backup::run();
    $extra = memory_get_peak_usage() - $baseline;
    $check('32 MiB table exports within a 32 MiB PHP memory limit', $extra < 16 * 1048576);
    $check('normal buffered queries are restored after export', (bool)$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    $check('connection still accepts queries after export', (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 256);
    $stream = gzopen(App::storage('backups/' . $backup), 'rb');
    $rows = 0;
    try {
        while (($line = gzgets($stream)) !== false) {
            if (str_starts_with($line, "INSERT INTO `$table` ")) $rows++;
        }
    } finally {
        gzclose($stream);
    }
    $check('every fixture row is present in the export', $rows === 256);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $second = Backup::run();
    try {
        $check('an originally unbuffered connection stays unbuffered', !$db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
        $check('unbuffered export releases its transaction', !$db->inTransaction());
    } finally {
        unlink(App::storage('backups/' . $second));
        unlink(App::storage('backups/' . $second . '.verified.json'));
    }
    echo "$passed streaming checks passed; peak additional PHP memory: " . round($extra / 1048576, 2) . " MiB.\n";
} finally {
    ini_set('memory_limit', (string)$originalLimit);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    if ($db->inTransaction()) $db->rollBack();
    if ($created) $db->exec("DROP TABLE `$table`");
    if ($backup !== null) {
        unlink(App::storage('backups/' . $backup));
        unlink(App::storage('backups/' . $backup . '.verified.json'));
    }
}
