<?php
// Admin sign-in, sessions, roles and form tokens.
//
// From the plan (section 10.3):
//  - passwords 12+ characters, stored only as slow hashes;
//  - 5 wrong tries lock the account for 15 minutes;
//  - Owner and Finance need a second code from a phone app (TOTP);
//  - sessions end after 30 minutes idle and 12 hours in any case; the cookie
//    cannot be read by scripts and only travels over HTTPS;
//  - every admin action needs a one-time form token (CSRF);
//  - roles are checked on the server for every request.

declare(strict_types=1);

namespace Ismile;

final class Auth
{
    public const ROLES = ['owner', 'registration', 'finance', 'content', 'checkin'];
    public const ROLE_NAMES = [
        'owner'        => 'Owner (everything)',
        'registration' => 'Registration (registrations, approvals, workshops, export)',
        'finance'      => 'Finance (payments, money checks, exports)',
        'content'      => 'Content (site text only)',
        'checkin'      => 'Check-in (arrivals, attendance, guest list and totals)',
    ];
    public const NEEDS_TWO_FACTOR = ['owner', 'finance'];

    private const IDLE_SECONDS = 1800;
    private const MAX_SECONDS = 43200;
    private const MAX_FAILS = 5;
    private const LOCK_MINUTES = 15;

    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = str_starts_with((string) App::config('site_url'), 'https://');
        session_name('ismile_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    /** The signed-in user, or null. Ends the session when it is too old or idle. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        self::startSession();
        $id = (int) ($_SESSION['uid'] ?? 0);
        if ($id === 0) {
            return null;
        }
        $now = time();
        if ($now - (int) ($_SESSION['started'] ?? 0) > self::MAX_SECONDS || $now - (int) ($_SESSION['seen'] ?? 0) > self::IDLE_SECONDS) {
            self::logout();
            return null;
        }
        $user = Db::one('SELECT * FROM admin_users WHERE id = ? AND disabled_at IS NULL', [$id]);
        if ($user === null || (int) $user['session_version'] !== (int) ($_SESSION['ver'] ?? 0)) {
            self::logout();   // disabled, or password / phone code changed since this sign-in
            return null;
        }
        $_SESSION['seen'] = $now;
        self::$user = $user;
        return $user;
    }

    /**
     * Step one of signing in: email and password.
     * Returns the user row; throws UserError with a message for the screen.
     */
    public static function checkPassword(string $email, string $password): array
    {
        if (!RateLimit::hit('login:' . App::clientIp(), 20, 900)) {
            throw new UserError('Too many sign-in attempts from this network. Wait 15 minutes.');
        }
        $email = strtolower(trim($email));
        $user = Db::one('SELECT * FROM admin_users WHERE email = ?', [$email]);
        if ($user !== null && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            throw new UserError('This account is locked for 15 minutes after too many wrong passwords.');
        }
        if ($user === null || $user['disabled_at'] !== null || !password_verify($password, $user['password_hash'])) {
            if ($user !== null) {
                $fails = (int) $user['failed_logins'] + 1;
                $lock = $fails >= self::MAX_FAILS ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
                Db::update('admin_users', ['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = ?', [$user['id']]);
                if ($lock) {
                    Audit::log((int) $user['id'], 'login.locked', 'admin_user', (int) $user['id']);
                    Outbox::queue('alert', null, ['message' => "Your iSmile admin account ($email) was locked for 15 minutes after 5 wrong passwords from address " . App::clientIp() . '. If this was not you, tell the Owner.'], null, $email, 'en');
                }
            }
            usleep(400000);
            throw new UserError('Wrong email or password.');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('admin_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        return $user;
    }

    public static function completeLogin(array $user): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION = ['uid' => (int) $user['id'], 'ver' => (int) $user['session_version'], 'started' => time(), 'seen' => time(), 'csrf' => Security::token()];
        Db::update('admin_users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => App::now()], 'id = ?', [$user['id']]);
        Audit::log((int) $user['id'], 'login', 'admin_user', (int) $user['id']);
        self::$user = null;
    }

    /**
     * Signs this person out on every computer and phone (after a password
     * change or a phone-code reset). If it is the person doing it, their own
     * current session stays signed in.
     */
    public static function endOtherSessions(int $userId): void
    {
        Db::run('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        if ((int) ($_SESSION['uid'] ?? 0) === $userId) {
            $_SESSION['ver'] = (int) Db::value('SELECT session_version FROM admin_users WHERE id = ?', [$userId]);
            self::$user = null;
        }
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function needsTwoFactor(array $user): bool
    {
        return in_array($user['role'], self::NEEDS_TWO_FACTOR, true);
    }

    /** True when this role may use this area. The Owner may use everything. */
    public static function can(array $user, string $area): bool
    {
        if ($user['role'] === 'owner') {
            return true;
        }
        $allowed = [
            'dashboard'     => ['registration', 'finance'],
            'registrations' => ['registration', 'finance'],
            'edit'          => ['registration'],
            'payments'      => ['finance'],
            'workshops'     => ['registration'],
            'sponsors'      => ['registration', 'finance'],
            'checkin'       => ['registration', 'checkin'],
            'export'        => ['registration', 'finance'],
            'certificates'  => ['registration', 'finance'],
            'photos'        => ['registration'],
            'content'       => ['content'],
            'communications' => ['registration', 'finance'],
        ];
        return in_array($user['role'], $allowed[$area] ?? [], true);
    }

    public static function csrf(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = Security::token();
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $sent = (string) ($_POST['csrf'] ?? '');
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {
            Audit::log(self::$user['id'] ?? null, 'csrf.rejected', null, null, ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            throw new UserError('This form has expired. Go back, reload the page and try again.', null, 400);
        }
    }

    public static function validPassword(string $password): bool
    {
        return mb_strlen($password) >= 12;
    }

    public static function createUser(string $email, string $name, string $role, string $password): int
    {
        if (!Validate::email($email) || !in_array($role, self::ROLES, true) || !self::validPassword($password)) {
            throw new UserError('Email, role or password (12+ characters) not valid.');
        }
        return Db::insert('admin_users', [
            'email' => strtolower($email), 'name' => $name, 'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => App::now(),
        ]);
    }
}
