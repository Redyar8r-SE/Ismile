<?php
// Nightly database backup, written in PHP so it works on any hosting (no
// mysqldump needed). Kept 30 days in storage/backups, gzip-compressed.
// The hosting's own daily backup copies this folder off the server too.

declare(strict_types=1);

namespace Ismile;

final class Backup
{
    private const KEEP_DAYS = 30;

    public static function run(): string
    {
        $file = App::storage('backups/ismile-' . date('Y-m-d-His') . '.sql.gz');
        $out = gzopen($file, 'wb6');
        if ($out === false) {
            throw new \RuntimeException('Backup file could not be created.');
        }
        $db = App::db();
        gzwrite($out, "-- iSmile backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        $tables = [];
        $views = [];
        foreach (Db::all('SHOW FULL TABLES') as $row) {
            [$name, $type] = array_values($row);
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }
        foreach ($tables as $table) {
            if (!preg_match('/^[a-z_0-9]+$/', $table)) {
                continue;
            }
            $create = Db::one("SHOW CREATE TABLE `$table`");
            gzwrite($out, "DROP TABLE IF EXISTS `$table`;\n" . array_values($create)[1] . ";\n");
            // Columns the database calculates itself (e.g. workshop_bookings.active)
            // cannot be written back, so they are left out and re-made on restore.
            $generated = array_flip(array_column(Db::all(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND (EXTRA LIKE '%GENERATED%' OR EXTRA LIKE '%PERSISTENT%' OR EXTRA LIKE '%VIRTUAL%')",
                [$table]
            ), 'COLUMN_NAME'));
            $statement = $db->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
            foreach ($statement as $row) {
                $row = array_diff_key($row, $generated);
                // Binary data (the student ID photos) is written as hex, so the
                // backup file stays plain text and restores byte for byte.
                $values = array_map(static fn ($value) => match (true) {
                    $value === null => 'NULL',
                    !mb_check_encoding((string) $value, 'UTF-8') => '0x' . bin2hex((string) $value),
                    default => $db->quote((string) $value),
                }, $row);
                gzwrite($out, "INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ");\n");
            }
            gzwrite($out, "\n");
        }
        // Views hold no data: only their definition, without the DEFINER
        // (the database account name), so the backup restores on any account.
        foreach ($views as $view) {
            if (!preg_match('/^[a-z_0-9]+$/', $view)) {
                continue;
            }
            $create = (string) array_values(Db::one("SHOW CREATE VIEW `$view`"))[1];
            $create = (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
            gzwrite($out, "DROP VIEW IF EXISTS `$view`;\n$create;\n\n");
        }
        gzwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        gzclose($out);
        @chmod($file, 0640);

        foreach (glob(App::storage('backups/ismile-*.sql.gz')) ?: [] as $old) {
            if (filemtime($old) < time() - self::KEEP_DAYS * 86400) {
                @unlink($old);
            }
        }
        return basename($file);
    }

    public static function latestTime(): ?int
    {
        $latest = null;
        foreach (glob(App::storage('backups/ismile-*.sql.gz')) ?: [] as $file) {
            $latest = max($latest ?? 0, (int) filemtime($file));
        }
        return $latest;
    }
}
