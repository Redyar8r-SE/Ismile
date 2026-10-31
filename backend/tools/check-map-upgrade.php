<?php
// Read-only preflight, before replacing any deployed files or database rows.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) exit(1);
$config = require $argv[1];
$plan = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$ids = [];
$counts = [];
foreach ($plan['rows'] as $row) foreach ($row['numbers'] as $id) {
    $ids[$id] = true;
    $counts[$row['tier']] = ($counts[$row['tier']] ?? 0) + 1;
}
$settings = $config['db'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'] ?? 'localhost', $settings['port'] ?? 3306, $settings['name']), $settings['user'], $settings['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hasBookings = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sponsor_requests'")->fetchColumn();
if ($hasBookings) {
    foreach ($pdo->query("SELECT reserved_booth FROM sponsor_requests WHERE kind='sponsor' AND reserved_booth IS NOT NULL") as $row) {
        if (!ctype_digit((string) $row['reserved_booth']) || !isset($ids[(int) $row['reserved_booth']])) {
            throw new RuntimeException('STOP: reserved position ' . $row['reserved_booth'] . ' would be removed. No files or bookings were changed.');
        }
    }
    foreach ($counts as $tier => $count) {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM sponsor_requests WHERE package_id=? AND status='confirmed'");
        $statement->execute([$tier]);
        if ((int) $statement->fetchColumn() > $count) throw new RuntimeException('STOP: confirmed ' . $tier . ' sponsors exceed the new map capacity.');
    }
}
$proof = ['created_at' => date(DATE_ATOM), 'tables' => []];
foreach (['sponsor_requests', 'sponsor_calls'] as $table) {
    if (!$hasBookings) continue;
    $rows = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $proof['tables'][$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
}
if ($proof['tables']) {
    $folder = rtrim($config['storage'], '/\\') . '/tmp';
    if (!is_dir($folder) && !mkdir($folder, 0750, true)) throw new RuntimeException('Could not save the booking-preservation proof.');
    if (file_put_contents($folder . '/map-39-before.json', json_encode($proof, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not save the booking-preservation proof.');
}
echo "Map preflight passed: every active reservation keeps its position ID.\n";
