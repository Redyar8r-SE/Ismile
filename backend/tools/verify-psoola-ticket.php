<?php
declare(strict_types=1);
// Optional read-only integration smoke check. Never print private ticket data.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../bootstrap.php';
use Ismile\Db;
use Ismile\PsoolaTickets;
$id=filter_var($argv[1]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id)throw new RuntimeException('Provide an existing registration id. No ticket will be issued.');
$user=Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
if(!$user)throw new RuntimeException('An active Owner account is required.');
try{
    $preview=PsoolaTickets::preview($user,$id);
    $count=count($preview['tickets']);$images=count(array_filter($preview['tickets'],static fn($ticket)=>is_string($ticket['qrImage']??null)));
    if($count<1||$images<1)throw new RuntimeException('Original QR not returned.');
    echo "PASS: existing PSOOLA order verified; original QR available; GET requests only; no tickets issued.\n";
    echo "PASS: private ticket codes, images, guest details and links excluded from verification output.\n";
}catch(Throwable){fwrite(STDERR,"Original PSOOLA ticket read-only check failed. Review the linked order through the staff interface.\n");exit(1);}
