<?php
// Nightly database backup, written in PHP so it works on any hosting (no
// mysqldump needed). Keeps the newest five successful copies, gzip-compressed.
// The hosting's own daily backup copies this folder off the server too.

declare(strict_types=1);

namespace Ismile;

final class Backup
{
    public const KEEP_COUNT = 5;
    private const VERIFICATION_SECONDS = 86400;

    public static function run(): string
    {
        // Manual backups and the nightly job must share the same lock.
        $lock = fopen(App::storage('tmp/backup.lock'), 'c');
        if ($lock === false) throw new \RuntimeException('The backup lock could not be opened.');
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new UserError('A backup is already running. Please try again after it finishes.');
            }
            return self::export();
        } finally {
            fclose($lock);
        }
    }

    private static function export(): string
    {
        $db = App::db();
        $ownsTransaction = !$db->inTransaction();
        $file = App::storage('backups/ismile-' . date('Y-m-d-His') . '-' . bin2hex(random_bytes(3)) . '.sql.gz');
        $temporary = $file . '.part';
        // Prefer faster compression over the smallest possible file.
        $out = gzopen($temporary, 'wb1');
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
                // Process one row at a time for every table. Photo export also
                // needs only one SELECT, rather than a separate query per ID.
                $buffered = $db->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
                $db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $rows = null;
                try {
                    $rows = $db->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $row = array_diff_key($row, $generated);
                        // Binary data is written as hex and restores byte for byte.
                        $values = array_map(static fn ($value) => match (true) {
                            $value === null => 'NULL',
                            !mb_check_encoding((string) $value, 'UTF-8') => '0x' . bin2hex((string) $value),
                            default => $db->quote((string) $value),
                        }, $row);
                        $write("INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ");\n");
                    }
                } finally {
                    // Unbuffered cursors must finish before any other query.
                    try {
                        if ($rows !== null) $rows->closeCursor();
                    } finally {
                        $db->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                    }
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
            // Verify once here so page views need only read small file metadata.
            if (!self::complete($file)) {
                @unlink($file);
                throw new \RuntimeException('The completed backup failed verification.');
            }
        } catch (\Throwable $error) {
            if (is_resource($out)) @gzclose($out);
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            @unlink($temporary);
            throw $error;
        }

        self::prune(basename($file));
        return basename($file);
    }

    /** Called only after a new export succeeds; failed backups preserve history. */
    private static function prune(string $newName): void
    {
        $new = self::find($newName, true);
        if ($new === null) return;
        // Always keep this run's verified copy, even when file timestamps tie.
        $backups = [$new];
        foreach (self::available() as $backup) {
            if ($backup['name'] !== $newName) $backups[] = $backup;
        }
        foreach (array_slice($backups, self::KEEP_COUNT) as $old) {
            if (@unlink($old['path'])) {
                @unlink($old['path'] . '.verified.json');
            } else {
                App::log('warning', 'Old database backup could not be deleted', ['file' => $old['name']]);
            }
        }
    }

    public static function latestTime(): ?int
    {
        foreach (self::candidates() as $name) {
            $backup = self::find($name, true);
            if ($backup !== null) return $backup['created_at'];
        }
        return null;
    }

    /** Only complete gzip exports inside the private backup folder are offered. */
    public static function available(): array
    {
        $backups = [];
        foreach (self::candidates() as $name) {
            $backup = self::find($name, true);
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

    /** Downloads use fresh verification; catalogs may reuse a recent result. */
    public static function find(string $name, bool $useCache = false): ?array
    {
        if (!preg_match('/^ismile-\d{4}-\d{2}-\d{2}-\d{6}(?:-[a-f0-9]{6})?\.sql\.gz$/D', $name)) return null;
        $directory = realpath(App::storage('backups'));
        $candidate = App::storage('backups/' . $name);
        clearstatcache(true, $candidate);
        $path = realpath($candidate);
        if ($directory === false || $path === false || !is_file($path) || is_link($candidate)) return null;
        $parent = dirname($path);
        $inside = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($parent, $directory) === 0 : $parent === $directory;
        if (!$inside || !self::complete($path, $useCache)) return null;
        return ['name' => $name, 'path' => $path, 'created_at' => (int) filemtime($path), 'size' => (int) filesize($path)];
    }

    private static function fingerprint(string $path): ?string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || $stat['size'] < 18) return null;
        $header = @file_get_contents($path, false, null, 0, 10);
        $trailer = @file_get_contents($path, false, null, $stat['size'] - 8, 8);
        if ($header === false || $trailer === false || !str_starts_with($header, "\x1f\x8b")) return null;
        return hash('sha256', serialize([
            $path, $stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'], $header, $trailer,
        ]));
    }

    private static function remember(string $path, string $fingerprint, bool $complete): void
    {
        // Optional cache: a read-only storage folder must not prevent downloads.
        $cache = $path . '.verified.json';
        $temporary = $cache . '.' . bin2hex(random_bytes(3)) . '.part';
        $json = json_encode(['fingerprint' => $fingerprint, 'checked_at' => time(), 'complete' => $complete]);
        if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
            @chmod($temporary, 0640);
            @rename($temporary, $cache);
        }
        if (is_file($temporary)) @unlink($temporary);
    }

    private static function complete(string $path, bool $useCache = false): bool
    {
        $fingerprint = self::fingerprint($path);
        if ($fingerprint === null) return false;
        if ($useCache) {
            $json = @file_get_contents($path . '.verified.json', false, null, 0, 2048);
            $cached = $json === false ? null : json_decode($json, true);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint
                && is_int($cached['checked_at'] ?? null) && $cached['checked_at'] <= time()
                && $cached['checked_at'] > time() - self::VERIFICATION_SECONDS
                && is_bool($cached['complete'] ?? null)) {
                return $cached['complete'];
            }
        }
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
            if (self::fingerprint($path) === $fingerprint) {
                self::remember($path, $fingerprint, $complete);
            } else {
                $complete = false;
            }
        }
        return $complete;
    }
}
