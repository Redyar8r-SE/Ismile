<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\App;
use Ismile\Db;
use Ismile\Sponsors;

Page::guard('map');

// Private, authenticated map data. The public availability API stays anonymous
// and contains numbers only. Use the same generated reservation as booking.
if (Page::query('format') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $bookings = Db::all("SELECT s.id, s.ref, s.company, s.contact_name, s.phone,
            s.reserved_booth, s.status, p.name_en AS package_name
            FROM sponsor_requests s LEFT JOIN sponsor_packages p ON p.id=s.package_id
            WHERE s.kind='sponsor' AND s.reserved_booth IS NOT NULL ORDER BY s.id");
        $number = filter_var($_GET['number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 44]]);
        $detail = null;
        if ($number !== false && $number !== null) {
            $detail = Db::one("SELECT s.id, s.ref, s.company, s.contact_name, s.contact_role,
                s.phone, s.email, s.website, s.city, s.status, s.price_quoted,
                s.amount_agreed, s.amount_paid, s.paid_how, s.paid_at,
                s.last_call_at, s.next_call_at, s.notes, s.message, s.created_at,
                s.updated_at, p.name_en AS package_name, u.name AS assigned_name
                FROM sponsor_requests s LEFT JOIN sponsor_packages p ON p.id=s.package_id
                LEFT JOIN admin_users u ON u.id=s.assigned_to
                WHERE s.kind='sponsor' AND s.reserved_booth=?", [(string) $number]);
            if ($detail) $detail['calls'] = Sponsors::calls((int) $detail['id']);
        }
        echo json_encode(['ok' => true, 'bookings' => $bookings, 'detail' => $detail, 'number' => $number ?: null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        App::log('error', 'Private map load failed', ['error' => $error->getMessage()]);
        http_response_code(503);
        echo '{"ok":false,"error":"Map data is temporarily unavailable."}';
    }
    exit;
}

Page::top('Map', 'map');
$version = (int) filemtime(__DIR__ . '/map.js');
?>
<link rel="stylesheet" href="../css/sections/booth-map.css?v=<?= (int) filemtime(__DIR__ . '/../css/sections/booth-map.css') ?>">
<link rel="stylesheet" href="map.css?v=<?= (int) filemtime(__DIR__ . '/map.css') ?>">
<script type="module" src="map.js?v=<?= $version ?>"></script>
<section class="exhibition admin-map" id="operationsMap" aria-label="Exhibition floor plan">
  <div class="exhibition-head"><div><h2>Your exhibition floor plan</h2><p>Select a position on the map or in the directory to see its booking. Red means reserved, including bookings awaiting payment.</p></div><span id="mapLive" class="exhibition-live" role="status">Connecting to database...</span></div>
  <div class="exhibition-overview">
    <div class="exhibition-stat"><span>Numbered positions</span><strong>44</strong></div>
    <div class="exhibition-stat stat-available"><span>Available</span><strong id="mapAvailable">—</strong></div>
    <div class="exhibition-stat stat-booked"><span>Reserved</span><strong id="mapBooked">—</strong></div>
  </div>
  <div class="exhibition-legend"><span class="tier-platinum"><i></i>Platinum</span><span class="tier-gold"><i></i>Gold</span><span class="tier-silver"><i></i>Silver</span><span class="tier-bronze"><i></i>Bronze</span><span><i class="legend-booked"></i>Reserved</span></div>
  <div class="operations-map-layout">
    <div class="operations-map-main">
      <div class="card"><div class="map-toolbar"><h3>Explore the floor plan</h3><div><button type="button" class="btn ghost" id="mapZoomOut" aria-label="Zoom out">−</button> <button type="button" class="btn ghost" id="mapZoomReset" aria-label="Reset zoom">100%</button> <button type="button" class="btn ghost" id="mapZoomIn" aria-label="Zoom in">+</button></div></div><div class="operations-map-scroll" id="mapViewport"><div id="mapDrawing"></div></div></div>
      <div class="card"><label>Find a position or company<input type="search" id="mapSearch" placeholder="Number, company, contact or phone"></label><label>Availability<select id="mapFilter"><option value="all">All positions</option><option value="reserved">Reserved</option><option value="available">Available</option></select></label><div id="mapDirectory" class="map-directory"></div></div>
    </div>
    <aside class="card map-details" id="mapDetails" aria-label="Selected position details" aria-live="polite"><h3>Select a number</h3><p>Company, contact, agreed amount, payments, notes and call history will appear here.</p></aside>
  </div>
  <p class="muted small">View only. Numbered positions are sponsor reservations. Standard exhibition booths are managed separately in Booths.</p>
</section>
<?php Page::bottom(); ?>
