<?php
// The admin's server, for ordinary web hosting.
//
// It answers exactly what netlify/functions/api.mjs answers, so the admin page
// itself needs no change: point data/admin-server.json at this file and press
// Save. Unlike the GitHub way there is no key and no waiting - the files are
// written straight to the website and the change is live at once.
//
// Put this folder on your hosting, copy admin-config.sample.php to
// admin-config.php and fill it in. Needs PHP 7.4 or newer.

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function out(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$configFile = __DIR__ . '/admin-config.php';
if (!is_file($configFile)) {
    out(503, ['error' => 'The server settings are not finished: admin-config.php is missing.']);
}
// Loaded with the output held back. A settings file with a stray warning or a
// blank line before "<?php" would otherwise print before the status code is
// set, and a refused sign-in would go out as 200 OK.
ob_start();
$config = require $configFile;
$noise = (string) ob_get_clean();
if ($noise !== '') {
    out(503, ['error' => 'admin-config.php printed something before the settings were read. Check for a space or a blank line before <?php, and that the hash is in single quotes.']);
}
if (!is_array($config)) {
    out(503, ['error' => 'admin-config.php did not return the settings array.']);
}

foreach (['secret', 'accounts'] as $needed) {
    if (empty($config[$needed])) {
        out(503, ['error' => 'The server settings are not finished: ' . $needed . ' is missing.']);
    }
}

// The website itself: one folder up, unless the settings say otherwise.
$root = realpath($config['site_root'] ?? __DIR__ . '/..');
if ($root === false) {
    out(500, ['error' => 'The website folder was not found.']);
}

$name = (string) ($config['name'] ?? ($_SERVER['HTTP_HOST'] ?? 'this website'));

// ---------- small helpers ----------
function bodyJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '{}' : $raw, true);
    return is_array($data) ? $data : [];
}

function b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function unb64url(string $text): string {
    return (string) base64_decode(strtr($text, '-_', '+/'));
}

// ---------- password ----------
// The same shape the rest of this project uses: pbkdf2$iterations$salt$hash
function passwordMatches(string $password, string $stored): bool {
    $parts = explode('$', $stored);
    if (count($parts) !== 4 || $parts[0] !== 'pbkdf2') {
        return false;
    }
    $iterations = (int) $parts[1];
    $salt = base64_decode($parts[2], true);
    if ($salt === false || $iterations < 1000) {
        return false;
    }
    $made = base64_encode(hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true));
    return hash_equals($parts[3], $made);
}

// ---------- sign-in tickets ----------
function signTicket(array $payload, string $secret): string {
    $body = b64url((string) json_encode($payload));
    return $body . '.' . b64url(hash_hmac('sha256', $body, $secret, true));
}

function readTicket(string $ticket, string $secret): ?array {
    if (substr_count($ticket, '.') !== 1) {
        return null;
    }
    [$body, $mac] = explode('.', $ticket);
    if (!hash_equals(b64url(hash_hmac('sha256', $body, $secret, true)), $mac)) {
        return null;
    }
    $payload = json_decode(unb64url($body), true);
    if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

// ---------- which files may be touched ----------
// Only content, never the code that runs the site.
function siteFile(string $root, string $path): ?string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
        return null;
    }
    if (!preg_match('#^(data/|assets/uploads/)[A-Za-z0-9._/-]+$#', $path)) {
        return null;
    }
    return $root . '/' . $path;
}

// ---------- routes ----------
$route = trim((string) ($_SERVER['PATH_INFO'] ?? ($_GET['r'] ?? '')), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- sign in ---
if ($route === 'login' && $method === 'POST') {
    $in = bodyJson();
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $password = (string) ($in['password'] ?? '');

    $ok = false;
    foreach ($config['accounts'] as $account) {
        $known = strtolower(trim((string) ($account['email'] ?? '')));
        if ($known === $email && passwordMatches($password, (string) ($account['hash'] ?? ''))) {
            $ok = true;
            break;
        }
    }
    if (!$ok) {
        usleep(400000);                      // slow down guessing
        out(401, ['error' => 'Wrong email or password.']);
    }

    $exp = time() + 12 * 3600;
    out(200, [
        'token' => signTicket(['email' => $email, 'exp' => $exp], (string) $config['secret']),
        'email' => $email,
        'exp'   => $exp,
        'repo'  => $name,
    ]);
}

// Everything below this line needs a valid ticket.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$ticket = (string) preg_replace('/^Bearer\s+/i', '', (string) $auth);
$session = readTicket($ticket, (string) $config['secret']);
if ($session === null) {
    out(401, ['error' => 'Please sign in again.']);
}

if ($route === 'me') {
    out(200, ['email' => $session['email'], 'exp' => $session['exp'], 'repo' => $name]);
}

// --- read a file ---
if ($route === 'file' && $method === 'GET') {
    $full = siteFile($root, (string) ($_GET['path'] ?? ''));
    if ($full === null) {
        out(400, ['error' => 'Bad file name.']);
    }
    if (!is_file($full)) {
        out(404, ['error' => 'Not found']);
    }
    out(200, ['sha' => null, 'content' => base64_encode((string) file_get_contents($full))]);
}

// --- write a file (text or picture) ---
if ($route === 'save' && $method === 'POST') {
    $in = bodyJson();
    $asked = (string) ($in['path'] ?? '');
    $full = siteFile($root, $asked);
    if ($full === null) {
        out(403, ['error' => 'That file cannot be changed from the admin.']);
    }
    $bytes = base64_decode((string) ($in['contentBase64'] ?? ''), true);
    if ($bytes === false || $bytes === '') {
        out(400, ['error' => 'Nothing to save.']);
    }

    $folder = dirname($full);
    if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
        out(500, ['error' => 'The folder could not be made.']);
    }

    // Written beside the real file and moved into place, so a half-written file
    // is never left behind if something goes wrong part way.
    $temp = $full . '.writing';
    if (file_put_contents($temp, $bytes, LOCK_EX) === false || !rename($temp, $full)) {
        @unlink($temp);
        out(500, ['error' => 'The file could not be written. Check the folder is writable.']);
    }
    out(200, ['path' => $asked]);
}

// --- list the uploaded pictures ---
if ($route === 'photos' && $method === 'GET') {
    $folder = $root . '/assets/uploads';
    $photos = [];
    if (is_dir($folder)) {
        foreach (scandir($folder) ?: [] as $entry) {
            if (preg_match('/\.(webp|png|jpe?g|gif)$/i', $entry)) {
                $photos[] = 'assets/uploads/' . $entry;
            }
        }
    }
    rsort($photos);
    out(200, ['photos' => $photos]);
}

out(404, ['error' => 'Unknown request.']);
