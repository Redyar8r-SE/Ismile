<?php
// Run by the deploy: adds a company workshop (register by phone) from its
// definition file in backend/assets, once. Later runs leave it alone, the
// Owner's edits on the Workshops page are kept, and after the Owner deletes it
// it is never added again.
//   php publish-company-workshop.php photogrammetry-workshop-2026.json
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\WorkshopCatalog;
use Ismile\WorkshopCheckout;
use Ismile\Workshops;

$file = basename((string) ($argv[1] ?? ''));
if (!preg_match('/^[a-z0-9-]+-workshop-2026\.json$/', $file) || !is_file(__DIR__ . '/../assets/' . $file)) {
    throw new RuntimeException('Give the workshop definition file name from backend/assets.');
}
$definition = json_decode((string) file_get_contents(__DIR__ . '/../assets/' . $file), true, 512, JSON_THROW_ON_ERROR);
$id = (string) ($definition['id'] ?? '');
if (!preg_match('/^[a-z0-9-]{3,80}$/', $id) || ($definition['organizer'] ?? '') !== 'company'
    || !preg_match('/^07\d{9}$/', (string) ($definition['company_phone'] ?? '')) || trim((string) ($definition['company_en'] ?? '')) === ''
    || !is_int($definition['price'] ?? null) || !preg_match('/^2026-11-(19|20)$/', (string) ($definition['scheduled_date'] ?? ''))
    || !preg_match('/^\d\d:\d\d$/', (string) ($definition['start_time'] ?? '')) || !preg_match('/^\d\d:\d\d$/', (string) ($definition['end_time'] ?? ''))
    || $definition['end_time'] <= $definition['start_time']) {
    throw new RuntimeException('The workshop definition is not a complete company workshop.');
}
if (!is_file(App::siteFile((string) $definition['photo']))) throw new RuntimeException('Presenter portrait missing.');
$ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if (!$ownerId) throw new RuntimeException('Enabled Owner required.');
$done = Db::transaction(static function () use ($definition, $id, $ownerId): string {
    $existing = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE', [$id]);
    if ($existing) {
        if ($existing['provider_event_id'] || $existing['provider_show_id'] || $existing['provider_ticket_type_id']) {
            throw new RuntimeException('Company workshop unexpectedly has provider registration.');
        }
        return 'kept';
    }
    // Added before and later deleted by the Owner: never added again.
    if (Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'workshop.company_publication' AND details LIKE ?", ['%"' . $id . '"%'])) {
        return 'deleted';
    }
    Db::insert('workshops', $definition + [
        'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM workshops'),
        'created_at' => App::now(), 'updated_at' => App::now(),
    ]);
    Audit::log($ownerId, 'workshop.company_publication', 'workshop', null, [
        'id' => $id, 'price' => (int) $definition['price'], 'registration' => 'company-phone',
    ]);
    return 'added';
});
if ($done !== 'added') {
    echo $done === 'kept' ? "Workshop $id is already on the website: left as it is.\n" : "Workshop $id was deleted on the Workshops page: not added again.\n";
    exit(0);
}
Workshops::syncWebsite();
$catalog = WorkshopCatalog::one($id, true);
if (!$catalog || $catalog['organizer'] !== 'company' || $catalog['price'] !== (int) $definition['price']
    || $catalog['companyPhone'] !== $definition['company_phone'] || !$catalog['availabilityVerified']
    || $catalog['scheduledDate'] !== $definition['scheduled_date'] || $catalog['startTime'] !== $definition['start_time'] || $catalog['endTime'] !== $definition['end_time']) {
    throw new RuntimeException('Published workshop verification failed.');
}
try {
    WorkshopCheckout::start($id, 'company-workshop-check@example.invalid');
    throw new RuntimeException('Company workshop accepted online registration.');
} catch (\Ismile\UserError $error) {
    if ($error->getMessage() !== 'wd_err_by_phone') throw $error;
}
echo 'WORKSHOPS_VERIFIED=' . json_encode(['workshop' => $catalog, 'registration' => 'company-phone'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
