<?php
declare(strict_types=1);
// No App boot, database or provider connection: every issuance path must stop first.
require __DIR__.'/../vendor/autoload.php';
use Ismile\SponsoredTickets;
use Ismile\UserError;
if (SponsoredTickets::issuanceAvailable()) throw new RuntimeException('Unverified free-ticket issuance must remain paused.');
$passed=1;
foreach ([['role'=>'owner','id'=>1],['role'=>'viewer','id'=>2,'registration_manage_access'=>1]] as $user) {
    foreach ([[],['attempt'=>str_repeat('a',32),'sponsoring_company'=>'Test company','amount'=>0]] as $input) {
        try { SponsoredTickets::issue($user,$input); throw new RuntimeException('An unverified order was allowed.'); }
        catch (UserError $error) {
            if (!str_contains($error->key,'issuance is paused') || !str_contains($error->key,'No order was submitted')) throw new RuntimeException('Issuance did not stop before database and API access.');
            $passed++;
        }
    }
}
echo "$passed checks passed: unverified free-ticket requests cannot reach the database or PSOOLA.\n";
