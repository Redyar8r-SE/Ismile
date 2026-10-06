<?php
// The application's shared state: settings from config.php, the database
// connection, the clock and the private folders.

declare(strict_types=1);

namespace Ismile;

use PDO;

final class App
{
    private static array $config = [];
    private static ?PDO $db = null;

    public static function boot(array $config): void
    {
        foreach (['site_url', 'site_root', 'storage', 'db', 'secret'] as $needed) {
            if (empty($config[$needed])) {
                throw new \RuntimeException("config.php is missing '$needed'.");
            }
        }
        if (strlen((string) $config['secret']) < 32 || str_starts_with((string) $config['secret'], 'CHANGE-THIS')) {
            throw new \RuntimeException("config.php: 'secret' must be a long random text.");
        }
        $config['site_url'] = rtrim((string) $config['site_url'], '/');
        $config['site_root'] = rtrim((string) $config['site_root'], '/\\');
        $config['storage'] = rtrim((string) $config['storage'], '/\\');
        self::$config = $config;

        date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Baghdad'));
        mb_internal_encoding('UTF-8');

        foreach (['logs', 'outbox', 'backups', 'tmp'] as $folder) {
            $path = $config['storage'] . '/' . $folder;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
        }
        // The private folder must never run code or be listed, even if a
        // hosting mistake ever puts it inside the web folder.
        $guard = $config['storage'] . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\nOptions -Indexes -ExecCGI\n");
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $value = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function isLive(): bool
    {
        return self::config('env') === 'live';
    }

    public static function db(): PDO
    {
        if (self::$db === null) {
            $db = self::config('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'] ?? 'localhost',
                (int) ($db['port'] ?? 3306),
                $db['name'] ?? ''
            );
            self::$db = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,   // real prepared statements: typed text never becomes SQL
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $offset = (new \DateTime())->format('P');
            self::$db->exec("SET time_zone = '$offset'");
        }
        return self::$db;
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function storage(string $path = ''): string
    {
        return self::config('storage') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }

    public static function siteFile(string $path): string
    {
        return self::config('site_root') . '/' . ltrim($path, '/');
    }

    public static function url(string $path = ''): string
    {
        return self::config('site_url') . '/' . ltrim($path, '/');
    }

    /** Writes a line to storage/logs/app-YYYY-MM.log. Never logs card data (we never see any). */
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s %s %s\n",
            self::now(),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents(self::storage('logs/app-' . date('Y-m') . '.log'), $line, FILE_APPEND | LOCK_EX);
    }

    public static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
    }
}
