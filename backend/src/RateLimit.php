<?php
// Counts hits per address in fixed windows. Used for the registration form,
// the sponsor form, Pay-now links, status checks and admin sign-in.

declare(strict_types=1);

namespace Ismile;

final class RateLimit
{
    /** Returns false (and counts nothing more) when the limit is reached. */
    public static function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $key = substr($bucket, 0, 120);
        Db::run(
            'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $window]
        );
        $hits = (int) Db::value('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?', [$key, $window]);
        if (random_int(1, 200) === 1) {
            Db::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
        }
        return $hits <= $limit;
    }
}
