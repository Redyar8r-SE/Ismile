<?php
// Compare private pre-deployment hashes; never print company or payment data.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../bootstrap.php';
$file = \Ismile\App::storage('tmp/map-39-before.json');
if (!is_file($file)) { echo "No prior booking snapshot (first installation).\n"; exit; }
$proof = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
foreach ($proof['tables'] as $table => $before) {
    if (!in_array($table, ['sponsor_requests', 'sponsor_calls'], true)) throw new RuntimeException('Unexpected snapshot table.');
    $rows = \Ismile\Db::all('SELECT * FROM ' . $table . ' ORDER BY id');
    if (count($rows) !== $before['count'] || !hash_equals($before['sha256'], hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)))) {
        throw new RuntimeException('Booking records changed during deployment: ' . $table . '. Check concurrent staff activity and the pre-deployment backup before continuing.');
    }
    echo 'Verified: all ' . count($rows) . ' ' . $table . " records are unchanged.\n";
}
\Ismile\BoothPlan::assertDatabaseMatches();
echo "Verified: database directory matches all 39 website positions.\n";
