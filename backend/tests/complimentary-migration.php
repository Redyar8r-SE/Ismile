<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';

use Ismile\App;
use Ismile\Auth;
use Ismile\Db;

if (App::isLive() || App::config('payments.gateway')!=='fake'
    || App::config('mail.driver')!=='log'
    || !str_starts_with((string)App::config('db.name'),'ismile_cash_test_')
    || (int)Db::value('SELECT COUNT(*) FROM sponsored_ticket_orders')!==0) {
    throw new RuntimeException('Use a fresh isolated ismile_cash_test_* database with fake payments, log mail and no sponsored orders.');
}
$staff=Auth::createUser('migration-'.bin2hex(random_bytes(4)).'@example.invalid','Migration fixture','owner',bin2hex(random_bytes(18)));
$key=bin2hex(random_bytes(16));$guestKey=hash('sha256',$key);$body='{"eventId":"legacy-event","items":[{"ticketTypeId":"professional","quantity":1}]}';
$id=Db::insert('sponsored_ticket_orders',[
    'attempt_key'=>$key,'partner_reference'=>'MIGRATE40000','created_by'=>$staff,'guest_key'=>$guestKey,'active_guest_key'=>$guestKey,
    'fingerprint'=>hash('sha256',$body),'guest_json'=>'{"email":"legacy@example.invalid"}','request_json'=>$body,
    'status'=>'issued','provider_order_id'=>'legacy-cash-40000','provider_amount'=>40000,'provider_currency'=>'IQD',
    'created_at'=>App::now(),'updated_at'=>App::now(),
]);
$before=Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$id]);
$columns=['is_complimentary','complimentary_reason','event_id','show_id','event_name','show_name','ticket_quantity',
    'discount_amount','provider_status','support_request_id','last_webhook_id','retry_after','attempts'];
// Reconstruct the previous schema only in this empty, disposable fixture.
foreach($columns as $column) App::db()->exec('ALTER TABLE sponsored_ticket_orders DROP COLUMN '.$column);
Db::run('DELETE FROM schema_migrations WHERE name=?',['2026-11-06-complimentary-orders.sql']);
App::db()->exec('ALTER TABLE sponsored_ticket_orders ADD COLUMN is_complimentary TINYINT(1) NOT NULL DEFAULT 0');
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAILED: '.$message);echo 'PASS: '.$message."\n";};
ob_start();require __DIR__.'/../tools/install.php';ob_end_clean();
$legacy=Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$id]);
$check((int)$legacy['provider_amount']===40000 && $legacy['provider_currency']==='IQD' && !(int)$legacy['is_complimentary'],
    'partially applied upgrade preserves the historical cash amount and classification');
$check($legacy['request_json']===$before['request_json'] && $legacy['provider_order_id']==='legacy-cash-40000' && $legacy['status']==='issued',
    'upgrade preserves legacy request, provider identity and status');
$check((int)Db::value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name IN ('.implode(',',array_fill(0,count($columns),'?')).')',array_merge(['sponsored_ticket_orders'],$columns))===count($columns),
    'upgrade adds every complimentary field');
Db::run('DELETE FROM schema_migrations WHERE name=?',['2026-11-06-complimentary-orders.sql']);
ob_start();require __DIR__.'/../tools/install.php';ob_end_clean();
$check(Db::one('SELECT * FROM sponsored_ticket_orders WHERE id=?',[$id])===$legacy,
    'replaying the migration with all columns already present preserves the entire order');
$check((int)Db::value('SELECT COUNT(*) FROM sponsored_ticket_events')===0,
    'webhook deduplication table installs without inventing events');
echo "5 complimentary migration checks passed.\n";
