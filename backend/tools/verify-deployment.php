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

$pages = ['index','checkin','registrations','certificates','sponsors','booths','map','lists','communications','payments','psoola-orders','registration','workshops','settings','users','backups','content'];
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
    // --render page query role: the same page as a Viewer or Sponsors account would see it.
    if (in_array($argv[4] ?? '', Auth::ROLES, true)) $owner['role'] = $argv[4];
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

$render = static function (string $page, string $query = '', string $as = ''): string {
    $command = [PHP_BINARY];
    if ($ini = php_ini_loaded_file()) array_push($command, '-c', $ini);
    array_push($command, __FILE__, '--render', $page, $query);
    if ($as !== '') $command[] = $as;
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
foreach (Auth::ROLES as $role) {
    $html = $render('map', '', $role);
    $check($role . ' sees Map in view-only mode', str_contains($html, 'id="operationsMap"')
        && str_contains($html, 'class="admin-shell view-only"') && str_contains($html, 'href="map.php"')
        && !Auth::viewerMayPost(['role' => $role], '/admin/map.php'));
}
// Read the real reservation data without logging in or changing any records.
$mapRows = Db::all("SELECT reserved_booth FROM sponsor_requests WHERE kind='sponsor' AND reserved_booth IS NOT NULL");
$check('map reservation query succeeds', is_array($mapRows));
// PSOOLA scans the tickets at the door: no iSmile scanner tab, the workspace opens on Attended.
$html = $render('checkin', 'day=1');
$check('Registration navigation has Attended, Guest list and Report, and no scanner tab', !str_contains($html, '>Check-in</span>') && !str_contains($html, 'id="scanCamera"')
    && str_contains($html, '>Attended</span>') && str_contains($html, '>Guest list</span>') && str_contains($html, '>Report</span>'));
$check('PSOOLA gate note shown, with Refresh from Psoola when this site sells through PSOOLA', str_contains($html, 'Psoola scans the tickets at the door')
    && (!\Ismile\Payments\PsoolaCheckIns::active() || str_contains($html, 'value="psoola_sync"')));
$check('automatic name, reference and ticket lookup available', str_contains($html, 'data-instant-search') && str_contains($html, 'Name, reference or ticket number'));
$check('Day 2 attendance page renders', strlen($render('checkin', 'day=2')) > 1000);
$html = $render('checkin', 'tab=attended');
$check('arrival times and staff filters available', str_contains($html, 'Day 1 arrival') && str_contains($html, 'Day 2 arrival') && str_contains($html, 'Checked in by') && str_contains($html, 'data-live-region'));
$html = $render('checkin', 'tab=guests');
$check('guest categories, IDs, lunch and attendance filters available', str_contains($html, 'Student ID') && str_contains($html, 'Lunch booked') && str_contains($html, 'Dentist') && str_contains($html, 'Event attendance'));
$html = $render('checkin', 'tab=report');
$check('live graph and Latest arrivals available', str_contains($html, 'data-live-report') && str_contains($html, 'report-chart-card') && str_contains($html, 'Latest arrivals'));
$html = $render('psoola-orders');
$check('Psoola orders page renders (live list, or a clear note when unavailable)', str_contains($html, 'Psoola orders') && (str_contains($html, 'Sales recorded by Psoola') || str_contains($html, 'Psoola orders are not available')));
$html = $render('workshops');
$check('Workshop settings offer the trainer photo, the details page texts and the currency', str_contains($html, 'name="photo"') && str_contains($html, 'name="overview_en"') && str_contains($html, 'name="currency"'));
$check('Workshop details page and online registration are deployed', is_file(App::siteFile('workshop.html')) && is_file(App::siteFile('api/workshop-register.php')));
$check('Workshop settings offer "Organized by" iSmile or a company, with the company phone', str_contains($html, 'name="organizer"') && str_contains($html, 'name="company_phone"') && str_contains($html, 'A company (register by phone call)'));
$check('The workshops page explains both ways to book (online and by phone)', str_contains((string) @file_get_contents(App::siteFile('workshops.html')), 'wsp3_co_title'));
$html = $render('registration', 'new=1');
$check('Register (cash) shows packages, lunch cards and the total to collect', str_contains($html, 'Packages &amp; prices') && str_contains($html, 'id="cash-total"') && str_contains($html, 'class="lunch-card"') && str_contains($html, 'Paid by cash'));
$check('Paid by cash first asks "Did you receive the cash?"', str_contains($html, 'id="cash-confirm"') && str_contains($html, 'Did you receive the cash?') && str_contains($html, 'Yes, sell the ticket'));
$check('Every "Are you sure?" uses the iSmile window (free ticket shown as an example)', str_contains($html, 'data-confirm-box=') && str_contains($html, 'Give a free ticket?') && str_contains((string) @file_get_contents(is_file(App::siteFile('admin/admin.js')) ? App::siteFile('admin/admin.js') : dirname(__DIR__, 2) . '/admin/admin.js'), 'confirm-box'));
$html = $render('lists', 'list=registered');
$check('Registered list offers the Sale type filter (cash, card, free)', str_contains($html, '<legend>Sale type</legend>') && str_contains($html, 'name="sale" value="cash"') && str_contains($html, 'Card (online)'));
$html = $render('certificates');
$check('certificate delivery remains manual', str_contains($html, 'Check-in never sends certificate emails.') && str_contains($html, 'Download attendance report (PDF)'));
// Viewer: opens every event page in view-only mode, never the Owner pages or the website editor.
foreach (['index','registrations','lists','payments','psoola-orders','workshops','sponsors','booths','checkin','certificates','communications'] as $page) {
    $html = $render($page, '', 'viewer');
    $check('Viewer sees ' . $page . ' in view-only mode', str_contains($html, 'class="admin-shell view-only"') && str_contains($html, 'View only.') && !str_contains($html, '<h2>Not allowed</h2>'));
}
foreach (['users','settings','backups','content'] as $page) {
    $check('Viewer cannot open ' . $page, str_contains($render($page, '', 'viewer'), '<h2>Not allowed</h2>'));
}
// Viewer + Sponsors: sees every event page like a Viewer, and books on Sponsors and Booths.
foreach (['sponsors','booths'] as $page) {
    $html = $render($page, '', 'sponsors');
    $check('Viewer + Sponsors can book on ' . $page, !str_contains($html, '<h2>Not allowed</h2>') && !str_contains($html, 'class="admin-shell view-only"') && str_contains($html, 'new=1'));
}
foreach (['index','registrations','lists','payments','psoola-orders','workshops','checkin','certificates','communications'] as $page) {
    $html = $render($page, '', 'sponsors');
    $check('Viewer + Sponsors sees ' . $page . ' in view-only mode', str_contains($html, 'class="admin-shell view-only"') && !str_contains($html, '<h2>Not allowed</h2>'));
}
foreach (['users','settings','backups','content'] as $page) {
    $check('Viewer + Sponsors cannot open ' . $page, str_contains($render($page, '', 'sponsors'), '<h2>Not allowed</h2>'));
}
$check('PDF report generator works', str_starts_with(PdfReport::bundle(['Deployment check'=>[['Status'],['Verified']]]), '%PDF-'));
echo "Deployment verification complete: $passed read-only checks passed. No guests admitted or certificate emails sent.\n";
