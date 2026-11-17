<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';
// Run by every deploy: adds the free J Dental Care workshop (register by phone)
// once; later runs only check it. The Owner's later edits on the Workshops page
// are kept: an existing row is left alone.

use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\WorkshopCatalog;
use Ismile\WorkshopCheckout;
use Ismile\Workshops;

$definition = json_decode((string) file_get_contents(__DIR__ . '/../assets/jdental-zygoma-workshop-2026.json'), true, 512, JSON_THROW_ON_ERROR);
if ($definition['id'] !== 'pterygoid-zygoma-placement' || $definition['organizer'] !== 'company'
    || $definition['company_en'] !== 'J Dental Care' || $definition['company_phone'] !== '07700031502'
    || $definition['price'] !== 0 || $definition['scheduled_date'] !== '2026-11-19'
    || $definition['start_time'] !== '13:00' || $definition['end_time'] !== '15:00') {
    throw new RuntimeException('Workshop definition differs from the approved details.');
}
if (!is_file(App::siteFile($definition['photo']))) throw new RuntimeException('Presenter portrait missing.');
$ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if (!$ownerId) throw new RuntimeException('Enabled Owner required.');
$done = Db::transaction(static function () use ($definition, $ownerId): string {
    $existing = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE', [$definition['id']]);
    if ($existing) {
        if ($existing['provider_event_id'] || $existing['provider_show_id'] || $existing['provider_ticket_type_id']) {
            throw new RuntimeException('Company workshop unexpectedly has provider registration.');
        }
        return 'kept';
    }
    // Added before and later deleted by the Owner: never added again.
    if (Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'workshop.company_publication' AND details LIKE ?", ['%"' . $definition['id'] . '"%'])) {
        return 'deleted';
    }
    Db::insert('workshops', $definition + [
        'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM workshops'),
        'created_at' => App::now(), 'updated_at' => App::now(),
    ]);
    Audit::log($ownerId, 'workshop.company_publication', 'workshop', null, [
        'id' => $definition['id'], 'price' => 0, 'registration' => 'company-phone',
    ]);
    return 'added';
});
if ($done !== 'added') {
    echo $done === 'kept' ? "The J Dental Care workshop is already on the website: left as it is.
" : "The J Dental Care workshop was deleted on the Workshops page: not added again.
";
    exit(0);
}
Workshops::syncWebsite();
$catalog = WorkshopCatalog::one($definition['id'], true);
if (!$catalog || $catalog['organizer'] !== 'company' || $catalog['price'] !== 0
    || $catalog['companyPhone'] !== '07700031502' || !$catalog['availabilityVerified']
    || $catalog['scheduledDate'] !== '2026-11-19' || $catalog['startTime'] !== '13:00' || $catalog['endTime'] !== '15:00') {
    throw new RuntimeException('Published workshop verification failed.');
}
try {
    WorkshopCheckout::start($definition['id'], 'company-workshop-check@example.invalid');
    throw new RuntimeException('Company workshop accepted online registration.');
} catch (\Ismile\UserError $error) {
    if ($error->getMessage() !== 'wd_err_by_phone') throw $error;
}
echo 'WORKSHOPS_VERIFIED=' . json_encode(['workshop' => $catalog, 'registration' => 'company-phone', 'testBookingCreated' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
