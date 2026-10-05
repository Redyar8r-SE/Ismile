<?php
// Nightly database backup, written in PHP so it works on any hosting (no
// mysqldump needed). Kept 30 days in storage/backups, gzip-compressed.
// The hosting's own daily backup copies this folder off the server too.

declare(strict_types=1);

namespace Ismile;

final class Backup
{
    public const KEEP_DAYS = 30;

    public static function run(): string
    {
        $db = App::db();
        $ownsTransaction = !$db->inTransaction();
        $file = App::storage('backups/ismile-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz');
        $temporary = $file . '.part';
        $out = gzopen($temporary, 'wb6');
        if ($out === false) {
            throw new \RuntimeException('Backup file could not be created.');
        }
        $write = static function (string $text) use ($out): void {
            if (gzwrite($out, $text) !== strlen($text)) {
                throw new \RuntimeException('The backup could not be fully written.');
            }
        };
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $write("-- iSmile backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
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
                $write("DROP TABLE IF EXISTS `$table`;\n" . array_values($create)[1] . ";\n");
                // Columns the database calculates itself (e.g. workshop_bookings.active)
                // cannot be written back, so they are left out and re-made on restore.
                $generated = array_flip(array_column(Db::all(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                       AND GENERATION_EXPRESSION <> ''",
                    [$table]
                ), 'COLUMN_NAME'));
                // The photo table is read one row at a time (each can be ~1 MB);
                // the other tables are small enough to read in one go.
                $rowsOf = $table === 'student_id_photos'
                    ? (static function () use ($db): \Generator {
                        foreach (Db::all('SELECT id FROM student_id_photos ORDER BY id') as ['id' => $photoId]) {
                            $photo = Db::one('SELECT * FROM student_id_photos WHERE id = ?', [$photoId]);
                            if ($photo !== null) {
                                yield $photo;
                            }
                        }
                    })()
                    : $db->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
                foreach ($rowsOf as $row) {
                    $row = array_diff_key($row, $generated);
                    // Binary data (the student ID photos) is written as hex, so the
                    // backup file stays plain text and restores byte for byte.
                    $values = array_map(static fn ($value) => match (true) {
                        $value === null => 'NULL',
                        !mb_check_encoding((string) $value, 'UTF-8') => '0x' . bin2hex((string) $value),
                        default => $db->quote((string) $value),
                    }, $row);
                    $write("INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ");\n");
                }
                $write("\n");
            }
            // Views hold no data: only their definition, without the DEFINER
            // (the database account name), so the backup restores on any account.
            foreach ($views as $view) {
                if (!preg_match('/^[a-z_0-9]+$/', $view)) {
                    continue;
                }
                $create = (string) array_values(Db::one("SHOW CREATE VIEW `$view`"))[1];
                $create = (string) preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create);
                $write("DROP VIEW IF EXISTS `$view`;\n$create;\n\n");
            }
            $write("SET FOREIGN_KEY_CHECKS = 1;\n");
            if (!gzclose($out)) throw new \RuntimeException('The backup could not be completed.');
            $out = null;
            if ($ownsTransaction) $db->commit();
            @chmod($temporary, 0640);
            if (!rename($temporary, $file)) throw new \RuntimeException('The completed backup could not be saved.');
        } catch (\Throwable $error) {
            if (is_resource($out)) @gzclose($out);
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            @unlink($temporary);
            throw $error;
        }

        foreach (glob(App::storage('backups/ismile-*.sql.gz')) ?: [] as $old) {
            if (filemtime($old) < time() - self::KEEP_DAYS * 86400) {
                @unlink($old);
            }
        }
        return basename($file);
    }

    public static function latestTime(): ?int
    {
        foreach (self::candidates() as $name) {
            $backup = self::find($name);
            if ($backup !== null) return $backup['created_at'];
        }
        return null;
    }

    /** Only complete gzip exports inside the private backup folder are offered. */
    public static function available(): array
    {
        $backups = [];
        foreach (self::candidates() as $name) {
            $backup = self::find($name);
            if ($backup !== null) $backups[] = $backup;
        }
        return $backups;
    }

    private static function candidates(): array
    {
        $files = glob(App::storage('backups/ismile-*.sql.gz')) ?: [];
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a) ?: strcmp($b, $a));
        return array_map('basename', $files);
    }

    public static function find(string $name): ?array
    {
        if (!preg_match('/^ismile-\d{4}-\d{2}-\d{2}-\d{6}(?:-[a-f0-9]{6})?\.sql\.gz$/D', $name)) return null;
        $directory = realpath(App::storage('backups'));
        $candidate = App::storage('backups/' . $name);
        $path = realpath($candidate);
        if ($directory === false || $path === false || !is_file($path) || is_link($candidate)) return null;
        $parent = dirname($path);
        $inside = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($parent, $directory) === 0 : $parent === $directory;
        if (!$inside || !self::complete($path)) return null;
        return ['name' => $name, 'path' => $path, 'created_at' => (int) filemtime($path), 'size' => (int) filesize($path)];
    }

    private static function complete(string $path): bool
    {
        static $verified = [];
        $key = $path . ':' . filemtime($path) . ':' . filesize($path);
        if (array_key_exists($key, $verified)) return $verified[$key];
        $stream = null;
        $complete = false;
        try {
            $size = (int) filesize($path);
            if ($size < 18 || file_get_contents($path, false, null, 0, 2) !== "\x1f\x8b") return false;
            $trailer = file_get_contents($path, false, null, $size - 8, 8);
            $stream = gzopen($path, 'rb');
            if ($stream === false) return false;
            $first = true;
            $header = false;
            $tail = '';
            $checksum = hash_init('crc32b');
            $length = 0;
            while (!gzeof($stream)) {
                $chunk = gzread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !gzeof($stream))) return false;
                if ($first) { $header = str_starts_with($chunk, '-- iSmile backup '); $first = false; }
                $tail = substr($tail . $chunk, -64);
                hash_update($checksum, $chunk);
                $length = ($length + strlen($chunk)) % 4294967296;
            }
            $complete = $header && str_ends_with($tail, "SET FOREIGN_KEY_CHECKS = 1;\n")
                && $trailer === strrev(hex2bin(hash_final($checksum))) . pack('V', $length);
        } catch (\Throwable) {
            $complete = false;
        } finally {
            if (is_resource($stream)) @gzclose($stream);
        }
        return $verified[$key] = $complete;
    }
}
