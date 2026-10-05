<?php
// Field validation checks. No server, configuration or database required.
// Run: php backend/tests/office-fields.php

declare(strict_types=1);

use Ismile\Checkouts;
use Ismile\Registrations;
use Ismile\UserError;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ismile\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$caller = [
    'first_name' => 'Sara', 'father_name' => 'Ahmed', 'grandfather_name' => 'Hassan',
    'phone' => '0750 123 4567', 'email' => 'sara@example.com', 'specialty' => 'gp', 'ticket' => 'professional',
];
$passed = 0;
$check = static function (string $name, bool $ok) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException("FAILED: $name");
    }
    $passed++;
    echo "PASS: $name\n";
};
$rejects = static function (array $in): bool {
    try {
        Checkouts::officeRow($in);
        return false;
    } catch (UserError) {
        return true;
    }
};
foreach (Registrations::SPECIALTIES as $specialty) {
    $row = Checkouts::officeRow(['specialty' => $specialty, 'university' => 'Test University'] + $caller);
    $check("stores specialty $specialty", $row['specialty'] === $specialty);
}
$missing = $caller;
unset($missing['specialty']);
$check('requires an explicit specialty', $rejects($missing));
$check('rejects an unknown specialty', $rejects(['specialty' => 'unknown'] + $caller));
$check('rejects an empty specialty', $rejects(['specialty' => ''] + $caller));
$student = ['specialty' => 'student', 'university' => 'University of Sulaimani', 'ambassador' => 'AMB-SARA'] + $caller;
$row = Checkouts::officeRow($student);
$check('dental student always receives a student ticket', $row['ticket_type'] === 'student');
$check('keeps university and ambassador code for students', $row['university'] === $student['university'] && $row['ambassador_code'] === 'AMB-SARA');
$check('requires a university for a dental student', $rejects(['university' => ''] + $student));
$check('requires a university for any student ticket', $rejects(['ticket' => 'student'] + $caller));
$check('rejects invalid ambassador characters', $rejects(['ambassador' => '<script>'] + $student));
$row = Checkouts::officeRow(['university' => 'Ignored', 'ambassador' => 'Ignored'] + $caller);
$check('professional tickets omit student-only details', $row['university'] === null && $row['ambassador_code'] === null);
$check('normalizes the caller phone number', $row['phone'] === '+9647501234567');
echo "$passed checks passed.\n";
