<?php
// Read-only deployment checks. CLI only; never admits guests or sends emails.
// php tools/verify-deployment.php --source /path/to/deployed/git/checkout
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;
use Ismile\Admin\PdfReport;

$pages = ['index','checkin','registrations','certificates','sponsors','booths','lists','communications','payments','workshops','settings','users','backups','content'];
if (($argv[1] ?? '') === '--render') {
    $page = $argv[2] ?? '';
    if (!in_array($page, $pages, true)) throw new RuntimeException('Unknown verification page.');
    parse_str($argv[3] ?? '', $_GET);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $page . '.php';
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_HOST'] = (string) parse_url((string) App::config('site_url'), PHP_URL_HOST);
    $owner = Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
    if (!$owner) throw new RuntimeException('An enabled Owner account is required.');
    // A process-local rendering context, without signing in or updating an account.
    $owner['totp_enabled'] = 1;
    (new ReflectionProperty(Auth::class, 'user'))->setValue(null, $owner);
    $file = App::siteFile('admin/' . $page . '.php');
    if (!is_file($file)) $file = dirname(__DIR__, 2) . '/admin/' . $page . '.php';
    ob_start();
    require $file;
    exit;
}

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('Deployment verification failed: ' . $name);
    $passed++;
    echo "PASS: $name\n";
};
if (($argv[1] ?? '') === '--source') {
    $source = realpath($argv[2] ?? '') ?: throw new RuntimeException('Source checkout is missing.');
    $matched = 0;
    foreach (['admin' => App::siteFile('admin'), 'backend/src' => dirname(__DIR__) . '/src', 'backend/database' => dirname(__DIR__) . '/database'] as $folder => $deployed) {
        $root = $source . '/' . $folder;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile()) continue;
            $target = $deployed . '/' . substr($file->getPathname(), strlen($root) + 1);
            if (!is_file($target) || hash_file('sha256', $target) !== hash_file('sha256', $file->getPathname())) {
                throw new RuntimeException('Deployed source differs: ' . $folder . '/' . $file->getFilename());
            }
            $matched++;
        }
    }
    $check("all $matched dashboard, backend and schema files match the release", $matched > 50);
}
foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
    $check('migration recorded: ' . basename($file), (bool) Db::value('SELECT name FROM schema_migrations WHERE name=?', [basename($file)]));
}
$views = Db::all('SELECT table_name AS name FROM information_schema.views WHERE table_schema=DATABASE()');
foreach ($views as $view) {
    if (!preg_match('/^[a-z0-9_]+$/', $view['name'])) throw new RuntimeException('Unexpected database view name.');
    App::db()->query('SELECT * FROM `' . $view['name'] . '` LIMIT 0');
}
$check('all 14 database views are readable', count($views) === 14);
$check('no certificate without attendance', (int) Db::value('SELECT COUNT(*) FROM certificates c WHERE NOT EXISTS (SELECT 1 FROM tickets t JOIN ticket_attendance a ON a.ticket_id=t.id WHERE t.registration_id=c.registration_id)') === 0);
$check('one admission per ticket per day', (int) Db::value('SELECT COUNT(*) FROM (SELECT ticket_id,event_day FROM ticket_attendance GROUP BY ticket_id,event_day HAVING COUNT(*)>1) duplicates') === 0);
$check('only one active Standard booth type', (int) Db::value("SELECT COUNT(*) FROM sponsor_packages WHERE kind='booth' AND status='active'") <= 1 && (int) Db::value("SELECT COUNT(*) FROM sponsor_packages WHERE kind='booth' AND status='active' AND (id<>'booth-standard' OR booth_tier IS NOT NULL)") === 0);
$check('only sponsors reserve numbered map spaces', (int) Db::value("SELECT COUNT(*) FROM sponsor_requests WHERE kind='booth' AND reserved_booth IS NOT NULL") === 0);

$render = static function (string $page, string $query = ''): string {
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, __FILE__, '--render', $page, $query);
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start page verification.');
    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || str_contains($html, 'Fatal error') || !str_contains($html, 'admin-shell')) {
        App::log('error', 'Deployment page verification failed', ['page'=>$page,'exit_code'=>$code,'stderr'=>$errors]);
        throw new RuntimeException('Could not render deployed page: ' . $page . '; details are in private logs.');
    }
    return $html;
};
foreach ($pages as $page) {
    $html = $render($page);
    $check('deployed page renders: ' . $page, strlen($html) > 1000);
}
$html = $render('checkin', 'day=1');
$check('Scan QR button visible and local decoder included', (bool) preg_match('/<button\b[^>]*id="scanCamera"[^>]*>/', $html, $button) && !preg_match('/\bhidden\b/', $button[0]) && str_contains($html, 'vendor/jsQR.js'));
$check('automatic name, phone, reference and ticket lookup available', str_contains($html, 'data-instant-search') && str_contains($html, 'Name, phone, reference or ticket number'));
$check('Registration navigation has all four requested tabs', str_contains($html, '>Check-in</span>') && str_contains($html, '>Attended</span>') && str_contains($html, '>Guest list</span>') && str_contains($html, '>Report</span>'));
$check('Day 2 check-in page renders', strlen($render('checkin', 'day=2')) > 1000);
$html = $render('checkin', 'tab=attended');
$check('arrival times and staff filters available', str_contains($html, 'Day 1 arrival') && str_contains($html, 'Day 2 arrival') && str_contains($html, 'Checked in by') && str_contains($html, 'data-live-directory'));
$html = $render('checkin', 'tab=guests');
$check('guest categories, IDs, lunch and attendance filters available', str_contains($html, 'Student ID') && str_contains($html, 'Lunch booked') && str_contains($html, 'Professional') && str_contains($html, 'Event attendance'));
$html = $render('checkin', 'tab=report');
$check('live graph and Latest arrivals available', str_contains($html, 'data-live-report') && str_contains($html, 'report-chart-card') && str_contains($html, 'Latest arrivals'));
$html = $render('certificates');
$check('certificate delivery remains manual', str_contains($html, 'Check-in never sends certificate emails.') && str_contains($html, 'Download attendance report (PDF)'));
$check('PDF report generator works', str_starts_with(PdfReport::bundle(['Deployment check'=>[['Status'],['Verified']]]), '%PDF-'));
echo "Deployment verification complete: $passed read-only checks passed. No guests admitted or certificate emails sent.\n";
