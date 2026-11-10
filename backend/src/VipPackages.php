<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaPrices;

/** One persisted, included workshop for a paid $200 VIP package. */
final class VipPackages
{
    public const PRICE_USD = 200;
    public const WORKSHOPS = ['simplified-direct-veneers','crown-vs-veneer'];

    public static function enabled(): bool { return Settings::bool('vip_package_enabled'); }

    public static function expectedPrice(): ?array
    {
        if(App::config('payments.gateway')==='fake'&&!App::isLive())return ['amount'=>self::PRICE_USD,'currency'=>'USD'];
        $prices=PsoolaPrices::get();
        if(!$prices||$prices['currency']!=='IQD')return null;
        $rate=$prices['usdRate']??null;
        if($rate===null&&isset($prices['vipUsd'])&&(float)$prices['vipUsd']===self::PRICE_USD)return ['amount'=>$prices['ticketTypes']['vip'],'currency'=>'IQD'];
        if(!is_numeric($rate)||$rate<=0)return null;
        $amount=self::PRICE_USD*$rate;
        if(abs($amount-round($amount))>0.00001)return null;
        return ['amount'=>(int)round($amount),'currency'=>'IQD'];
    }

    /** Sales also need an explicit switch, independent of deployment and provider pricing. */
    public static function ready(): bool
    {
        if(!self::enabled()||!Settings::bool('vip_package_sales_open'))return false;
        $expected=self::expectedPrice();if(!$expected)return false;
        if(App::config('payments.gateway')==='fake')return SiteData::prices()['vip']===self::PRICE_USD&&SiteData::prices()['vipCurrency']==='USD';
        $actual=PsoolaPrices::get();
        return $actual&&$actual['ticketTypes']['vip']===$expected['amount']&&$actual['currency']===$expected['currency']&&GuestOrders::enabled();
    }

    public static function choiceFromInput(array $in,array $row): ?string
    {
        if(!self::enabled()||$row['ticket_type']!=='vip')return null;
        if(!self::ready())throw new UserError('vip_package_not_ready',null,409);
        $id=$in['vip_workshop']??null;
        if(!is_string($id)||!in_array($id,self::WORKSHOPS,true))throw new UserError('vip_workshop_required',null,422);
        return $id;
    }

    /** Called inside the checkout transaction, after the shared VIP lock. */
    public static function requireWorkshop(string $id,int $exceptCheckout=0): void
    {
        if(!in_array($id,self::WORKSHOPS,true))throw new UserError('vip_workshop_required');
        $row=Db::one('SELECT * FROM workshops WHERE id=? FOR UPDATE',[$id]);
        if(!$row||$row['organizer']!=='ismile'||$row['status']!=='active')throw new UserError('vip_workshop_full',null,409);
        $catalog=WorkshopCatalog::one($id,true,$exceptCheckout);
        if(!$catalog||!$catalog['availabilityVerified']||$catalog['seatsLeft']<1||Workshops::booked($id)+self::held($id,$exceptCheckout)>=(int)$row['total_seats'])throw new UserError('vip_workshop_full',null,409);
    }

    public static function remember(array $checkout,string $workshopId): void
    {
        Db::insert('vip_package_choices',['checkout_id'=>$checkout['id'],'workshop_id'=>$workshopId,'request_key'=>bin2hex(random_bytes(16)),
            'hold_until'=>$checkout['expires_at'],'created_at'=>App::now(),'updated_at'=>App::now()]);
    }

    public static function forCheckout(int $id): ?array
    {
        return self::hasTable()?Db::one('SELECT * FROM vip_package_choices WHERE checkout_id=?',[$id]):null;
    }

    public static function forRegistration(int $id): ?array
    {
        return self::hasTable()?Db::one('SELECT v.*,w.title_en AS workshop_name FROM vip_package_choices v JOIN workshops w ON w.id=v.workshop_id WHERE v.registration_id=?',[$id]):null;
    }

    /** Unpaid holds expire with their form; paid fulfilment keeps its place until issued/reviewed. */
    public static function held(string $id,int $exceptCheckout=0): int
    {
        if(!self::hasTable())return 0;
        return (int)Db::value("SELECT COUNT(*) FROM vip_package_choices v LEFT JOIN checkouts c ON c.id=v.checkout_id LEFT JOIN registrations r ON r.id=v.registration_id WHERE v.workshop_id=? AND (v.checkout_id IS NULL OR v.checkout_id<>?) AND ((v.state='held' AND v.hold_until>? AND c.status='open' AND c.expires_at>?) OR (v.state IN ('pending','review') AND r.status='paid'))",[$id,$exceptCheckout,App::now(),App::now()]);
    }

    public static function assertCheckout(array $checkout): void
    {
        if(!self::enabled()||$checkout['ticket_type']!=='vip')return;
        if(!self::ready())throw new UserError('vip_package_not_ready',null,409);
        $choice=self::forCheckout((int)$checkout['id']);
        if(!$choice)throw new UserError('vip_workshop_required',null,409);
        self::requireWorkshop($choice['workshop_id'],(int)$checkout['id']);
    }

    public static function assertPrice(array $checkout,array $created): void
    {
        if(!self::forCheckout((int)$checkout['id']))return;
        if(App::config('payments.gateway')==='fake')$created+=SiteData::quoteFor($checkout);
        $expected=self::expectedPrice();
        if($expected){
            $prices=PsoolaPrices::get();
            foreach([1,2] as $day)if((int)$checkout['lunch_day'.$day]===1&&(int)$checkout['vip_lunch_day']!==$day){
                $extra=App::config('payments.gateway')==='fake'?SiteData::prices()['lunchDay'.$day]:($prices['lunch']['day'.$day]??null);
                if(!is_int($extra)||$extra<=0)throw new \RuntimeException('Extra lunch price is unavailable.');
                $expected['amount']+=$extra;
            }
        }
        if(!$expected||($created['amount']??null)!==$expected['amount']||($created['currency']??null)!==$expected['currency'])throw new \RuntimeException('VIP package price must match the verified $200 provider quote.');
    }

    /** Same transaction as the confirmed registration: selection cannot be lost after payment. */
    public static function paid(array $checkout,int $registrationId): void
    {
        if(!self::hasTable())return;
        Db::run("UPDATE vip_package_choices SET registration_id=?,state='pending',updated_at=? WHERE checkout_id=? AND state='held'",[$registrationId,App::now(),$checkout['id']]);
    }

    public static function canConfirm(array $checkout): bool
    {
        $choice=self::forCheckout((int)$checkout['id']);if(!$choice)return true;
        try{self::requireWorkshop($choice['workshop_id'],(int)$checkout['id']);return true;}
        catch(UserError){return false;}
    }

    public static function entitled(int $registrationId,string $workshopId,string $key): bool
    {
        return self::hasTable()&&(bool)Db::value("SELECT v.id FROM vip_package_choices v JOIN registrations r ON r.id=v.registration_id WHERE v.registration_id=? AND v.workshop_id=? AND v.request_key=? AND v.state IN ('pending','review','issued') AND r.status='paid' AND r.ticket_type='vip'",[$registrationId,$workshopId,$key]);
    }

    public static function issued(string $key,int $bookingId,int $guestRequestId): void
    {
        if(!self::hasTable())return;
        Db::run("UPDATE vip_package_choices SET state='issued',booking_id=?,guest_request_id=?,last_error=NULL,updated_at=? WHERE request_key=? AND registration_id IS NOT NULL",[$bookingId,$guestRequestId,App::now(),$key]);
    }

    /** The cron retries the same saved request key; it never creates a second included ticket. */
    public static function process(int $limit=10,?int $checkoutId=null): array
    {
        $report=['issued'=>0,'review'=>0];if(!self::hasTable())return $report;
        $rows=Db::all("SELECT v.* FROM vip_package_choices v JOIN registrations r ON r.id=v.registration_id WHERE v.state IN ('pending','review') AND r.status='paid'".($checkoutId!==null?' AND v.checkout_id=?':'')." ORDER BY v.updated_at,v.id LIMIT ".max(1,min(30,$limit)),$checkoutId!==null?[$checkoutId]:[]);
        foreach($rows as $choice){
            $lock='ismile-vip-package-'.$choice['id'];if(!(int)Db::value('SELECT GET_LOCK(?,0)',[$lock]))continue;
            try{
                $fresh=Db::one('SELECT * FROM vip_package_choices WHERE id=?',[$choice['id']]);if($fresh['state']==='issued')continue;
                $person=Registrations::find((int)$choice['registration_id']);
                if(!$person||$person['status']!=='paid'||$person['ticket_type']!=='vip')continue;
                $owner=Db::one("SELECT * FROM admin_users WHERE role='owner' AND disabled_at IS NULL ORDER BY id LIMIT 1");
                if(!$owner)throw new \RuntimeException('An active owner is required for VIP workshop fulfilment.');
                Db::run('UPDATE vip_package_choices SET attempts=attempts+1,updated_at=? WHERE id=?',[App::now(),$choice['id']]);
                $saved=Db::one('SELECT id FROM guest_workshop_orders WHERE attempt_key=?',[$choice['request_key']]);
                if($saved)GuestWorkshops::resume($owner,$person,(int)$saved['id']);
                else GuestWorkshops::startIncludedVip($owner,$person,['attempt'=>$choice['request_key'],'workshop'=>$choice['workshop_id'],'complimentary_reason'=>'Included in paid VIP package '.$person['ref'].'. No additional workshop payment.']);
                $after=Db::one('SELECT * FROM vip_package_choices WHERE id=?',[$choice['id']]);
                if($after['state']!=='issued')throw new \RuntimeException('VIP workshop ticket is awaiting provider confirmation.');
                $report['issued']++;
            }catch(\Throwable $error){
                Db::run("UPDATE vip_package_choices SET state='review',last_error=?,updated_at=? WHERE id=? AND state<>'issued'",[mb_substr($error->getMessage(),0,500),App::now(),$choice['id']]);
                if((int)$choice['attempts']===0)Outbox::alert('VIP workshop fulfilment needs review for registration #'.$choice['registration_id'].'. The saved request is retained for retry.');
                App::log('error','VIP workshop fulfilment pending',['choice'=>$choice['id'],'error'=>$error->getMessage()]);$report['review']++;
            }finally{Db::value('SELECT RELEASE_LOCK(?)',[$lock]);}
        }
        return $report;
    }

    private static function hasTable(): bool
    {
        static $exists=null;
        return $exists??=(bool)Db::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='vip_package_choices'");
    }
}
