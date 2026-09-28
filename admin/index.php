<?php
// Dashboard: the big numbers, and a red list of anything that needs attention.

declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Backup;
use Ismile\Db;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;

$user = Page::guard('dashboard');

$count = static fn (string $sql, array $params = []): int => (int) Db::value($sql, $params);
$paid = $count("SELECT COUNT(*) FROM registrations WHERE status = 'paid'");
$comp = $count("SELECT COUNT(*) FROM registrations WHERE status = 'complimentary'");
$cancelled = $count("SELECT COUNT(*) FROM registrations WHERE status = 'cancelled'");
// Forms waiting for payment are NOT registrations; shown only as a number.
$payingNow = $count("SELECT COUNT(*) FROM checkouts WHERE status = 'open' AND expires_at > ?", [\Ismile\App::now()]);
$profPaid = $count("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','complimentary') AND ticket_type = 'professional'");
$studPaid = $count("SELECT COUNT(*) FROM registrations WHERE status IN ('paid','complimentary') AND ticket_type = 'student'");
$money = (int) Db::value("SELECT COALESCE(SUM(amount_confirmed), 0) FROM payments WHERE status = 'paid'");
$emailsSent = $count("SELECT COUNT(*) FROM emails WHERE status = 'sent'");
$emailsFailed = $count("SELECT COUNT(*) FROM emails WHERE status = 'failed'");
$openSponsors = $count("SELECT COUNT(*) FROM sponsor_requests WHERE status IN ('new','contacted','agreed','paid')");
$oldestSponsor = Db::value("SELECT MIN(created_at) FROM sponsor_requests WHERE status = 'new'");
$sponsorMoney = $count("SELECT COALESCE(SUM(amount_paid), 0) FROM sponsor_requests WHERE status IN ('paid','confirmed')");
$callsDue = \Ismile\Sponsors::callsDue();
$taken = Registrations::ticketsTaken();
$currency = SiteData::prices()['currency'];

// ---- Needs attention ----
$attention = [];
$flagged = $count("SELECT COUNT(*) FROM payments WHERE status IN ('mismatch','duplicate')");
if ($flagged) {
    $attention[] = ["$flagged payment(s) need Finance: wrong amount or paid twice. No ticket was made for them.", 'payments.php?status=flagged'];
}
if ($callsDue && \Ismile\Auth::can($user, 'sponsors')) {
    $attention[] = ["$callsDue sponsor / exhibition call(s) are due. Call them and save the call.", 'sponsors.php?due=1'];
}
if ($emailsFailed) {
    $attention[] = ["$emailsFailed email(s) failed. Fix the address and press Resend.", 'registrations.php?email=failed'];
}
$badHooks = $count("SELECT COUNT(*) FROM webhook_log WHERE outcome LIKE 'REJECTED%' AND received_at > ?", [date('Y-m-d H:i:s', time() - 86400)]);
if ($badHooks) {
    $attention[] = ["$badHooks payment message(s) with a bad signature in the last 24 hours (possible fakes; nothing was changed).", 'payments.php#webhooks'];
}
$lastBackup = Backup::latestTime();
if ($paid + $comp > 0 && ($lastBackup === null || $lastBackup < time() - 36 * 3600)) {
    $attention[] = ['No database backup in the last 36 hours. Check the nightly cron job.', null];
}
$prices = SiteData::prices();
if ($prices['professional'] <= 0 || $prices['student'] <= 0) {
    $attention[] = ['Ticket prices are not set. Nobody can pay until they are (Site content → Ticket prices).', '../admin.html'];
}
if (Settings::bool('email_test_mode')) {
    $attention[] = ['Email test mode is ON: every email goes to the test address, not to the people.', 'settings.php'];
}
if (App::isLive() && App::config('payments.gateway') !== 'psoola') {
    $attention[] = ['The live site is not connected to Psoola (payments.gateway in config.php).', null];
}

Page::top('Dashboard', 'index');
$tile = static fn (string $colour, string $label, string $value, string $note = ''): string =>
    '<div class="tile ' . $colour . '"><b>' . Page::e($value) . '</b><span>' . Page::e($label) . '</span>' . ($note !== '' ? '<small>' . Page::e($note) . '</small>' : '') . '</div>';

echo '<div class="state-line">Registration is ' . (Registrations::isOpen() ? '<span class="pill green">open</span>' : '<span class="pill red">closed</span>')
    . (SiteData::closedBySwitch() ? ' <span class="muted">(closed by the switch in Site content › Registration)</span>' : '')
    . ' · payments: <b>' . Page::e((string) App::config('payments.gateway')) . '</b>' . (App::isLive() ? '' : ' (test site)') . '</div>';

if ($attention) {
    echo '<div class="attention"><h2>Needs attention</h2><ul>';
    foreach ($attention as [$text, $link]) {
        $area = $link === null ? null : match (strtok($link, '.?#')) {
            'payments' => 'payments', 'registrations' => 'registrations',
            '../admin' => 'content', default => 'settings',
        };
        $canOpen = $area !== null && \Ismile\Auth::can($user, $area);
        echo '<li>' . Page::e($text) . ($canOpen ? ' <a href="' . Page::e($link) . '">Open</a>' : '') . '</li>';
    }
    echo '</ul></div>';
}

echo '<div class="tiles">'
    . $tile('green', 'Registered (paid)', (string) ($paid + $comp), $comp ? "incl. $comp free ticket(s)" : 'only paid people are registered')
    . $tile('gold', 'Paying right now', (string) $payingNow, 'not registered until paid')
    . $tile('violet', 'Cancelled', (string) $cancelled)
    . $tile('blue', 'Money confirmed', number_format($money) . " $currency")
    . $tile('teal', 'Professionals / students', "$profPaid / $studPaid", 'with a ticket')
    . $tile($emailsFailed ? 'red' : 'green', 'Emails sent / failed', "$emailsSent / $emailsFailed")
    . $tile('gold', 'Open sponsor requests', (string) $openSponsors, $oldestSponsor ? 'oldest new: ' . Page::when((string) $oldestSponsor) : '')
    . $tile('blue', 'Sponsor & booth money', number_format($sponsorMoney) . " $currency", 'paid by sponsors and exhibitors')
    . '</div>';

$meter = static function (string $label, int $used, int $limit, string $colour): string {
    $percent = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
    return '<div class="meter"><div class="meter-top"><span>' . Page::e($label) . '</span><b>' . $used . ' / ' . ($limit > 0 ? $limit : 'no limit set') . '</b></div>'
        . '<div class="track"><i class="' . $colour . '" style="width:' . $percent . '%"></i></div></div>';
};
echo '<div class="card"><h2>Lunch</h2><p class="muted small">The event has no seat limit. Lunch is limited by the caterer (Settings).</p>'
    . $meter('Lunch, day 1', Registrations::lunchTaken(1), Settings::int('lunch_capacity_day1'), 'gold')
    . $meter('Lunch, day 2', Registrations::lunchTaken(2), Settings::int('lunch_capacity_day2'), 'gold')
    . '</div>';

$recent = Db::all("SELECT id, ref, first_name, father_name, grandfather_name, ticket_type, status, created_at FROM registrations ORDER BY id DESC LIMIT 8");
echo '<div class="card"><h2>Latest registrations</h2><div class="table-wrap"><table><tr><th>Reference</th><th>Name</th><th>Ticket</th><th>Status</th><th>When</th></tr>';
foreach ($recent as $row) {
    echo '<tr><td><a href="registration.php?id=' . (int) $row['id'] . '"><code>' . Page::e($row['ref']) . '</code></a></td><td>' . Page::e(Registrations::fullName($row)) . '</td><td>' . Page::e($row['ticket_type']) . '</td><td>' . Page::pill($row['status']) . '</td><td>' . Page::when($row['created_at']) . '</td></tr>';
}
if (!$recent) {
    echo '<tr><td colspan="5" class="muted">No registrations yet.</td></tr>';
}
echo '</table></div></div>';
Page::bottom();
