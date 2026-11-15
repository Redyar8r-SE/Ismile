<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../bootstrap.php';

use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\WorkshopCatalog;
use Ismile\WorkshopCheckout;
use Ismile\Workshops;

$definition = json_decode((string) file_get_contents(__DIR__ . '/../assets/vanest-implant-workshop-2026.json'), true, 512, JSON_THROW_ON_ERROR);
if ($definition['id'] !== 'implant-drilling-protocol' || $definition['organizer'] !== 'company'
    || $definition['company_en'] !== 'Vanest' || $definition['company_phone'] !== '07713666464'
    || $definition['price'] !== 0 || $definition['scheduled_date'] !== '2026-11-20'
    || $definition['start_time'] !== '09:00' || $definition['end_time'] !== '11:00') {
    throw new RuntimeException('Workshop definition differs from the approved details.');
}
if (!is_file(App::siteFile($definition['photo']))) throw new RuntimeException('Presenter portrait missing.');
$ownerId = (int) Db::value("SELECT id FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if (!$ownerId) throw new RuntimeException('Enabled Owner required.');
Db::transaction(static function () use ($definition, $ownerId): void {
    $existing = Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE', [$definition['id']]);
    if ($existing) {
        // 15 Nov: the plain photo became a transparent cut-out (same file name).
        if ($existing['photo_style'] === 'photo' && $definition['photo_style'] === 'cutout' && $existing['photo'] === $definition['photo']) {
            Db::update('workshops', ['photo_style' => 'cutout', 'updated_at' => App::now()], 'id=?', [$definition['id']]);
            Audit::log($ownerId, 'workshop.edit', 'workshop', null, ['id' => $definition['id'], 'photo_style' => 'cutout']);
            $existing['photo_style'] = 'cutout';
        }
        foreach ($definition as $field => $value) {
            $actual = in_array($field, ['start_time', 'end_time'], true) ? substr((string) $existing[$field], 0, 5) : (string) $existing[$field];
            if ($actual !== (string) $value) throw new RuntimeException('Existing workshop differs: ' . $field);
        }
        if ($existing['provider_event_id'] || $existing['provider_show_id'] || $existing['provider_ticket_type_id']) {
            throw new RuntimeException('Company workshop unexpectedly has provider registration.');
        }
        return;
    }
    Db::insert('workshops', $definition + [
        'sort_order' => (int) Db::value('SELECT COALESCE(MAX(sort_order),0)+1 FROM workshops'),
        'created_at' => App::now(), 'updated_at' => App::now(),
    ]);
    Audit::log($ownerId, 'workshop.company_publication', 'workshop', null, [
        'id' => $definition['id'], 'price' => 0, 'registration' => 'company-phone',
    ]);
});
Workshops::syncWebsite();
$catalog = WorkshopCatalog::one($definition['id'], true);
if (!$catalog || $catalog['organizer'] !== 'company' || $catalog['price'] !== 0
    || $catalog['companyPhone'] !== '07713666464' || !$catalog['availabilityVerified']
    || $catalog['scheduledDate'] !== '2026-11-20' || $catalog['startTime'] !== '09:00' || $catalog['endTime'] !== '11:00') {
    throw new RuntimeException('Published workshop verification failed.');
}
try {
    WorkshopCheckout::start($definition['id'], 'company-workshop-check@example.invalid');
    throw new RuntimeException('Company workshop accepted online registration.');
} catch (\Ismile\UserError $error) {
    if ($error->getMessage() !== 'wd_err_by_phone') throw $error;
}
echo 'WORKSHOPS_VERIFIED=' . json_encode(['workshop' => $catalog, 'registration' => 'company-phone', 'testBookingCreated' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
