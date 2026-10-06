<?php
// Shared handling for the public API files in /api: JSON answers, errors the
// visitor can fix (as translation keys), and no details of server errors.

declare(strict_types=1);

namespace Ismile;

final class Api
{
    public static function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url): never
    {
        header('Cache-Control: no-store');
        header('Location: ' . $url, true, 303);
        exit;
    }

    /** Runs one API action and turns any error into a safe JSON answer. */
    public static function run(callable $action): never
    {
        try {
            $action();
            self::json(200, ['ok' => true]);
        } catch (UserError $error) {
            self::json($error->status, ['ok' => false, 'error' => $error->key, 'field' => $error->field]);
        } catch (\Throwable $error) {
            App::log('error', 'API error', ['uri' => $_SERVER['REQUEST_URI'] ?? '', 'error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()]);
            self::json(500, ['ok' => false, 'error' => 'err_server']);
        }
    }

    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            self::json(405, ['ok' => false, 'error' => 'method']);
        }
    }

    /** Rejects a request sent from another website's page (only our own pages may post forms). */
    public static function sameOrigin(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '') {
            return;   // same-origin form posts and old browsers may leave it out
        }
        $site = parse_url((string) App::config('site_url'));
        $mine = ($site['scheme'] ?? 'https') . '://' . ($site['host'] ?? '') . (isset($site['port']) ? ':' . $site['port'] : '');
        if (!hash_equals($mine, $origin)) {
            self::json(403, ['ok' => false, 'error' => 'err_server']);   // e.g. an old http:// or www. page
        }
    }

    public static function limit(string $name, int $hits, int $seconds): void
    {
        if (!RateLimit::hit($name . ':' . App::clientIp(), $hits, $seconds)) {
            App::log('warning', 'Rate limit reached', ['limit' => $name, 'ip' => App::clientIp()]);
            self::json(429, ['ok' => false, 'error' => 'err_busy']);
        }
    }

    public static function headers(): array
    {
        if (function_exists('getallheaders')) {
            return array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        }
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        return $out;
    }
}
