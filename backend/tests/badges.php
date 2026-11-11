<?php
// php backend/tests/badges.php
// No database: checks the printed badge PDF (size, sides, names).
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace(['Ismile\\', '\\'], ['', '/'], $class) . '.php';
    if (is_file($file)) require $file;
});

use Ismile\Badges;

$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $passed++;
};
$guest = static fn (string $type, string $specialty): array => ['first_name' => 'Khiam', 'father_name' => ' Rohan ', 'grandfather_name' => 'Ahmed', 'ticket_type' => $type, 'specialty' => $specialty];

$check('dentists are written DR. first + father', Badges::displayName($guest('professional', 'gp')) === 'DR. KHIAM ROHAN');
$check('VIP dentists are written with DR.', Badges::displayName($guest('vip', 'spec')) === 'DR. KHIAM ROHAN');
$check('students have no DR.', Badges::displayName($guest('student', 'student')) === 'KHIAM ROHAN');
$check('lab technicians have no DR.', Badges::displayName($guest('professional', 'lab')) === 'KHIAM ROHAN');

$pdf = Badges::pdf($guest('professional', 'gp'), ['098G3F86', 'AB12CD34']);
$check('the badge is a PDF', str_starts_with($pdf, '%PDF-'));
$check('two sides per ticket code', preg_match_all('#/Type\s*/Page\b#', $pdf) === 4);
$check('each side is 10 x 11.5 cm', preg_match_all('#/MediaBox \[0\.000+ 0\.000+ 283\.46\d* 325\.98\d*\]#', $pdf) === 4);
$check('prints at 100%, both sides', str_contains($pdf, '/PrintScaling /None') && str_contains($pdf, '/Duplex /DuplexFlipLongEdge'));

echo "Badge checks passed: $passed\n";
