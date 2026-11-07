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

$pages = ['index','checkin','registrations','association','certificates','sponsors','booths','map','lists','communications','payments','psoola-orders','registration','workshops','settings','users','backups','content'];
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
    $owner['map_booking_access'] = 0;
    $owner['association_manage_access'] = 0;
    $owner['registration_manage_access'] = 0;
    // --render page query role: the same page as a Viewer or Sponsors account would see it.
    if (in_array($argv[4] ?? '', Auth::ROLES, true)) $owner['role'] = $argv[4];
    if (($argv[4] ?? '') === 'map_manager') {
        $owner['role'] = 'sponsors';
        $owner['map_booking_access'] = 1;
    }
    if (($argv[4] ?? '') === 'association_manager') {
        $owner['role'] = 'viewer';
        $owner['association_manage_access'] = 1;
    }
    if (($argv[4] ?? '') === 'registration_manager') {
        $owner['role'] = 'sponsors';
        $owner['registration_manage_access'] = 1;
    }
    if (($argv[4] ?? '') === 'designated_registrar') {
        $email = strtolower(trim((string) getenv('REGISTRATION_EDITOR_EMAIL')));
        $owner = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL', [$email]);
        if (!$owner || (int) $owner['registration_manage_access'] !== 1) throw new RuntimeException('The designated registrar is not configured.');
        $owner['totp_enabled'] = 1; // Process-local page rendering; the real sign-in still requires the phone code.
    }
    if (($argv[4] ?? '') === 'designated_workshop_manager') {
        $email = strtolower(trim((string)getenv('WORKSHOP_EDITOR_EMAIL')));
        $owner = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL',[$email]);
        if (!$owner || !Auth::canBookWorkshops($owner) || !Auth::canIssueComplimentary($owner)) throw new RuntimeException('The designated workshop manager is not configured.');
        $owner['totp_enabled'] = 1;
    }
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
$check('all 15 database views are readable', count($views) === 15);
\Ismile\BoothPlan::assertDatabaseMatches();
$check('all 39 map numbers, types and sizes match the website', \Ismile\BoothPlan::count() === 39);
$check('every active sponsor reservation has a position in the new map', (int) Db::value("SELECT COUNT(*) FROM sponsor_requests s LEFT JOIN sponsor_map_positions m ON CAST(m.position_id AS CHAR) COLLATE utf8mb4_unicode_ci=s.reserved_booth WHERE s.reserved_booth IS NOT NULL AND m.position_id IS NULL") === 0);
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
$html = $render('association');
$dashboard = $render('index');
$check('dashboard shows separate ticket, workshop and combined income; remaining revenue is only on Association', str_contains($dashboard,'<span>Ticket income</span>') && str_contains($dashboard,'<span>Workshop income</span>') && str_contains($dashboard,'<span>Total income</span>') && !str_contains($dashboard,'Ticket revenue remaining') && str_contains($html,'Ticket revenue remaining'));
$income = \Ismile\Admin\DashboardIncome::totals();
foreach ($income['total'] as $currency => $amount) {
    $check('dashboard combined income reconciles in ' . $currency, $amount === ($income['tickets'][$currency] ?? 0) + ($income['workshops'][$currency] ?? 0));
}
$check('Association guest selection, revenue preview, batch history and navigation render', str_contains($html, 'data-association-selection') && str_contains($html,'data-association-balance') && str_contains($html,'data-revenue-values') && str_contains($html, 'Batch history') && str_contains($html, 'href="association.php"'));
$check('Association shows separate cash and website balances and guest payment methods', str_contains($html,'<span>Cash payments</span>') && str_contains($html,'<span>Website payments remaining</span>') && str_contains($html,'data-website-values') && str_contains($html,'<th>Payment method</th>'));
$check('Association guest directory includes a Guest ticket filter',str_contains($html,'value="guest"')&&str_contains($html,'Guest ticket'));
$freeForm=$render('registration','new=free');
$optionsForm=$render('registration','new=free&options=1');
$check('Guest ticket options include catalogue selection, quantity, add-ons, language and optional consent', str_contains($freeForm,'options=1') && str_contains($optionsForm,'data-guest-options') && str_contains($optionsForm,'name="event_id"') && str_contains($optionsForm,'name="show_id"') && str_contains($optionsForm,'name="ticket_type_id"') && str_contains($optionsForm,'name="quantity"') && str_contains($optionsForm,'name="locale"') && str_contains($optionsForm,'name="add_on_ids_json"') && str_contains($optionsForm,'name="whatsapp_consent"') && str_contains($optionsForm,'Total: 0 IQD'));
$soraniOptions=$render('registration','new=free&options=1&ui=ckb');
$check('Sorani guest options preserve RTL and ticket terminology',str_contains($soraniOptions,'dir="rtl"')&&str_contains($soraniOptions,'پسووڵە'));
$check('Free ticket form collects names, specialty, guest contact details and a reason', str_contains($freeForm,'data-guest-ticket-form') && str_contains($freeForm,'name="first_name"') && str_contains($freeForm,'name="father_name"') && str_contains($freeForm,'name="grandfather_name"') && str_contains($freeForm,'name="specialty"') && str_contains($freeForm,'name="email"') && str_contains($freeForm,'name="phone"') && str_contains($freeForm,'name="complimentary_reason"') && !str_contains($freeForm,'guest-language-links') && !str_contains($freeForm,'name="event_id"') && !str_contains($freeForm,'name="locale"'));
$explicitGuard=false;
try { \Ismile\GuestOrders::assertRequest(['issuanceType'=>'cash','complimentaryReason'=>'Test']); }
catch(\Ismile\UserError){$explicitGuard=true;}
$check('Guest issuance requires an explicit complimentary request and shows a zero total', $explicitGuard && str_contains($freeForm,'Total: 0 IQD'));
$check('Approved Guest ticket design shows the ticket card and both delivery channels without a consent checkbox',str_contains($freeForm,'guest-issuance-layout')&&str_contains($freeForm,'guest-pass-header')&&str_contains($freeForm,'Delivery: Email + WhatsApp')&&str_contains($freeForm,'name="delivery_confirmation"')&&!str_contains($freeForm,'type="checkbox"')&&!str_contains($freeForm,'First name *')&&!str_contains($freeForm,'class="flash warn">Issuance needs setup:'));
if(\Ismile\GuestOrders::enabled())$check('Authorized Guest ticket action is enabled in the deployed form',preg_match('/<button\b(?=[^>]*data-guest-issue)(?![^>]*\bdisabled\b)[^>]*>/',$freeForm)===1);
$check('Owner sees separate cash and free registration buttons', str_contains($render('registrations'),'href="registration.php?new=free"'));
$profileId = Db::value('SELECT id FROM registrations ORDER BY id LIMIT 1');
if ($profileId) {
    $profile = $render('registration', 'id=' . (int)$profileId);
    $check('Approved registration details render for an existing record', str_contains($profile,'data-registration-details') && str_contains($profile,'registration-details.css') && str_contains($profile,'registration-details.js') && str_contains($profile,'Personal information') && str_contains($profile,'Registration information'));
    $check('Registration details omit standalone Payments Emails and Notes sections', !str_contains($profile,'<h2>Payments</h2>') && !str_contains($profile,'<h2>Emails</h2>') && !str_contains($profile,'<h2>Notes</h2>'));
    $check('Registration details preserve contact history and protected actions', str_contains($profile,'Email address') && str_contains($profile,'rd-history') && str_contains($profile,'Edit details') && str_contains($profile,'rd-menu'));
}
$check('Guest workshop request storage is installed', (bool)Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='guest_workshop_orders'"));
$psoolaProfileId = Db::value("SELECT r.id FROM registrations r JOIN tickets t ON t.registration_id=r.id AND t.cancelled_at IS NULL WHERE r.status IN ('paid','complimentary') AND (EXISTS(SELECT 1 FROM payments p WHERE p.registration_id=r.id AND p.gateway='psoola' AND p.status='paid' AND p.provider_payment_id IS NOT NULL) OR EXISTS(SELECT 1 FROM sponsored_ticket_orders o WHERE o.registration_id=r.id AND o.status='issued' AND o.provider_order_id IS NOT NULL)) ORDER BY r.id DESC LIMIT 1");
if ($psoolaProfileId) {
    $ticketProfile = $render('registration','id='.(int)$psoolaProfileId);
    $check('PSOOLA ticket-page button is available for a linked issued registration', str_contains($ticketProfile,'data-rd-ticket="psoola"') && str_contains($ticketProfile,'View PSOOLA ticket'));
    $check('Original PSOOLA QR dialog is present without private ticket data', str_contains($ticketProfile,'data-rd-psoola-dialog') && str_contains($ticketProfile,'data-rd-psoola-body') && !str_contains($ticketProfile,'data:image/svg+xml;base64,'));
}
if ($workshopEmail = strtolower(trim((string)getenv('WORKSHOP_EDITOR_EMAIL')))) {
    $manager = Db::one('SELECT * FROM admin_users WHERE email=? AND disabled_at IS NULL',[$workshopEmail]);
    $check('designated account has guest registration and all-attendee workshop booking', $manager && Auth::canBookWorkshops($manager) && Auth::canIssueComplimentary($manager));
    $check('designated account can open guest ticket issuance', str_contains($render('registration','new=free','designated_workshop_manager'),'data-guest-ticket-form'));
    $otherPerson = Db::value("SELECT id FROM registrations WHERE status IN ('paid','complimentary') AND (created_by IS NULL OR created_by<>?) ORDER BY id LIMIT 1",[$manager['id']]);
    if ($otherPerson && \Ismile\GuestOrders::enabled()) {
        $managerProfile = $render('registration','id='.(int)$otherPerson,'designated_workshop_manager');
        $check('designated account can book a guest workshop for another staff member or website attendee', str_contains($managerProfile,'data-rd-open="rd-workshop"') && !str_contains($managerProfile,'class="admin-shell view-only"'));
        if ($manager['role'] !== 'owner') $check('designated account retains owner-only restrictions', !str_contains($managerProfile,'value="erase"') && !str_contains($managerProfile,'id="rd-cancel"'));
    }
}
$check('Ordinary registration staff do not get the free ticket button', !str_contains($render('registrations','','registration'),'href="registration.php?new=free"'));
foreach (['finance','viewer','sponsors'] as $role) {
    $html = $render('association', '', $role);
    $check($role . ' has correct Association controls', str_contains($html, 'Batch history') && (str_contains($html, 'data-association-review') === ($role === 'finance')));
}
$associationTotals = \Ismile\Association::totals();
$html = $render('association', '', 'association_manager');
$check('designated Association manager can select and confirm without a view-only banner', str_contains($html, 'data-association-review') && str_contains($html, 'data-association-choice') && !str_contains($html, 'class="admin-shell view-only"'));
$html = $render('registration', 'new=1', 'registration_manager');
$check('designated registrar has paid and free registration controls without a view-only banner', str_contains($html, 'id="caller-pay"') && str_contains($html, 'Register with a free ticket') && !str_contains($html, 'class="admin-shell view-only"'));
$html = $render('registrations', '', 'viewer');
$check('ordinary viewers have no Register action', !str_contains($html, 'href="registration.php?new=1"'));
$html = $render('registration', 'new=1', 'registration');
$check('ordinary registration staff keep paid-only registration', str_contains($html, 'id="caller-pay"') && !str_contains($html, 'Register with a free ticket'));
if (getenv('REGISTRATION_EDITOR_EMAIL')) {
    $html = $render('registration', 'new=1', 'designated_registrar');
    $check('selected live account can open paid and complimentary registration', str_contains($html, 'id="caller-pay"') && str_contains($html, 'Register with a free ticket') && !str_contains($html, 'class="admin-shell view-only"'));
    $html = $render('registrations', '', 'designated_registrar');
    $check('selected live account has the Register action in its guest list', str_contains($html, 'href="registration.php?new=1"') && !str_contains($html, 'class="admin-shell view-only"'));
}
$check('Association sent totals match the active guest ledger', (int)$associationTotals['amount'] === (int)Db::value("SELECT COALESCE(SUM(total_paid),0) FROM association_batch_items WHERE active=1 AND payment_channel<>'Cash'") && (int)$associationTotals['guests'] === (int)Db::value('SELECT COUNT(*) FROM association_batch_items WHERE active=1'));
foreach (Auth::ROLES as $role) {
    $html = $render('map', '', $role);
    $manage = $role === 'owner';
    $check($role . ' has correct Map access', str_contains($html, 'id="operationsMap"')
        && str_contains($html, 'href="map.php"')
        && str_contains($html, 'data-can-manage="' . ($manage ? '1' : '0') . '"')
        && (str_contains($html, 'class="admin-shell view-only"') !== $manage));
}
$html = $render('map', '', 'map_manager');
$check('designated map manager can book without a view-only banner', str_contains($html, 'id="mapBookingForm"') && !str_contains($html, 'class="admin-shell view-only"'));
$html = $render('map', '', 'map_viewer');
$check('Map viewer has only Map navigation and no booking form', !str_contains($html, 'id="mapBookingForm"') && !str_contains($html, 'href="sponsors.php"') && !str_contains($html, 'href="index.php"') && !str_contains($html, 'href="users.php"'));
$check('at most one non-owner account has map booking access', (int) Db::value("SELECT COUNT(*) FROM admin_users WHERE map_booking_access=1 AND role<>'owner' AND disabled_at IS NULL") <= 1);
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
$check('Approved workshop directory and attendee layout is deployed', str_contains($html,'data-workshop-workspace') && str_contains($html,'ws-directory') && str_contains($html,'workshops.css') && str_contains($html,'workshops.js'));
$check('Workshop guest tickets are counted separately from collections', str_contains($html,'Confirmed bookings') && str_contains($html,'Guest tickets excluded') && str_contains($html,'Complimentary · 0 IQD'));
$check('Workshop default view hides numerical seat availability', !str_contains($html,'Seat availability') && !str_contains($html,'Seats available now') && !str_contains($html,'available now') && !str_contains($html,'<progress'));
$check('Workshop settings offer the trainer photo, the details page texts and the currency', str_contains($html, 'name="photo"') && str_contains($html, 'name="overview_en"') && str_contains($html, 'name="currency"'));
$check('Workshop details page and online registration are deployed', is_file(App::siteFile('workshop.html')) && is_file(App::siteFile('api/workshop-register.php')));
$check('Workshop settings offer iSmile or company registration with organizer contacts', str_contains($html, 'name="organizer"') && str_contains($html, 'name="company_phone"') && str_contains($html, 'Contact the organizer to register.'));
$check('Simplified Owner settings preserve details registration visibility and languages', str_contains($html,'data-workshop-settings') && str_contains($html,'data-settings-panel="details"') && str_contains($html,'data-settings-panel="registration"') && str_contains($html,'data-settings-panel="visibility"') && str_contains($html,'name="website_status"') && str_contains($html,'name="title_ku"'));
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
foreach (['booths'] as $page) {
    $html = $render($page, '', 'sponsors');
    $check('Viewer + Sponsors can book on ' . $page, !str_contains($html, '<h2>Not allowed</h2>') && !str_contains($html, 'class="admin-shell view-only"') && str_contains($html, 'new=1'));
}
foreach (['index','registrations','lists','payments','psoola-orders','workshops','sponsors','checkin','certificates','communications'] as $page) {
    $html = $render($page, '', 'sponsors');
    $check('Viewer + Sponsors sees ' . $page . ' in view-only mode', str_contains($html, 'class="admin-shell view-only"') && !str_contains($html, '<h2>Not allowed</h2>'));
}
foreach (['users','settings','backups','content'] as $page) {
    $check('Viewer + Sponsors cannot open ' . $page, str_contains($render($page, '', 'sponsors'), '<h2>Not allowed</h2>'));
}
$check('PDF report generator works', str_starts_with(PdfReport::bundle(['Deployment check'=>[['Status'],['Verified']]]), '%PDF-'));
echo "Deployment verification complete: $passed read-only checks passed. No guests admitted or certificate emails sent.\n";
