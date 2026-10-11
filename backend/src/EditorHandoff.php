<?php
// One sign-in for the database and the website editor. "Site content" in the
// database gives the person a pass that works once, for one minute; the editor's
// server (ismile.krd) asks redeem() whether it is genuine, and opens the editor
// signed in as that person. Nobody types a second password.

declare(strict_types=1);

namespace Ismile;

final class EditorHandoff
{
    private const SECONDS = 60;

    /** A one-time pass for this signed-in person (who may edit website content). */
    public static function issue(array $user): string
    {
        if (!Auth::can($user, 'content')) {
            throw new UserError('Your role cannot edit the website content.');
        }
        $body = self::encode(json_encode(['u' => (int) $user['id'], 'e' => time() + self::SECONDS, 'n' => bin2hex(random_bytes(8))], JSON_THROW_ON_ERROR));
        return $body . '.' . self::encode(hash_hmac('sha256', $body, self::key(), true));
    }

    /** The email of the person the pass belongs to, or null (forged, late, used, or no longer allowed). */
    public static function redeem(string $pass): ?string
    {
        [$body, $mac] = array_pad(explode('.', $pass, 2), 2, '');
        if ($body === '' || $mac === '' || !hash_equals(self::encode(hash_hmac('sha256', $body, self::key(), true)), $mac)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (!is_array($data) || !is_int($data['u'] ?? null) || !is_int($data['e'] ?? null) || $data['e'] < time()) {
            return null;
        }
        // Works once: the counter for this pass allows a single use.
        if (!RateLimit::hit('handoff:' . hash('sha256', $pass), 1, self::SECONDS * 2)) {
            return null;
        }
        $user = Db::one('SELECT * FROM admin_users WHERE id = ? AND disabled_at IS NULL', [$data['u']]);
        if ($user === null || !Auth::can($user, 'content')) {
            return null;
        }
        Audit::log((int) $user['id'], 'editor.handoff', 'admin_user', (int) $user['id']);
        return (string) $user['email'];
    }

    /** Signed with a key made from the server secret, used for nothing else. */
    private static function key(): string
    {
        return hash_hmac('sha256', 'editor-handoff', (string) App::config('secret'), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
