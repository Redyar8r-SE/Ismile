<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

final class WorkshopCatalog
{
    public static function all(): array
    {
        return array_values(array_map(static fn($w)=>self::one($w['id']),Workshops::all()));
    }

    public static function one(string $id, bool $fresh = false): ?array
    {
        $w=Workshops::find($id);
        if(!$w || $w['status']!=='active')return null;
        $w['availabilityVerified']=!$w['providerEventId'];
        if($w['providerEventId']){
            $file=App::storage('tmp/workshop-catalog-'.$id.'.json');
            $cache=is_file($file)?json_decode((string)file_get_contents($file),true):null;
            if($fresh || !$cache || time()-(int)($cache['at']??0)>=30){
                try{
                    $response=(new PsoolaGateway())->workshopRequest('GET','events/'.rawurlencode($w['providerEventId']));
                    if($response['status']!==200 || !is_array($response['json']['event']??null))throw new \RuntimeException('Workshop catalog unavailable.');
                    $data=self::fromEvent($response['json']['event'],$w);
                }catch(\Throwable $e){$data=null;App::log('warning','Workshop catalog unavailable',['workshop'=>$id,'error'=>$e->getMessage()]);}
                $cache=['at'=>time(),'data'=>$data];
                @file_put_contents($file,json_encode($cache),LOCK_EX);
            }
            if(is_array($cache['data']??null))$w=array_replace($w,$cache['data'],['availabilityVerified'=>true]);
            else {$w['availabilityVerified']=false;$w['seatsLeft']=0;}
        }
        unset($w['providerEventId'],$w['providerShowId'],$w['providerTicketTypeId']);
        return $w;
    }

    public static function fromEvent(array $event,array $workshop): array
    {
        if(($event['id']??null)!==$workshop['providerEventId'])throw new \RuntimeException('Workshop event mismatch.');
        $show=null;$ticket=null;
        foreach(($event['shows']??[]) as $candidate)if(($candidate['id']??null)===$workshop['providerShowId'])$show=$candidate;
        foreach(($show['ticketTypes']??[]) as $candidate)if(($candidate['id']??null)===$workshop['providerTicketTypeId'])$ticket=$candidate;
        if(!$show || !$ticket || !is_int($ticket['price']??null) || $ticket['price']<1 || !is_int($ticket['available']??null) || $ticket['available']<0 || !is_numeric($show['dateTime']??null) || !is_numeric($show['endTime']??null))throw new \RuntimeException('Workshop session, ticket type or price is unavailable.');
        $zone=new \DateTimeZone('Asia/Baghdad');
        $start=(new \DateTimeImmutable('@'.(int)floor((float)$show['dateTime']/1000)))->setTimezone($zone);
        $end=(new \DateTimeImmutable('@'.(int)floor((float)$show['endTime']/1000)))->setTimezone($zone);
        return ['price'=>$ticket['price'],'currency'=>'IQD','seatsLeft'=>$ticket['available'],'scheduledDate'=>$start->format('Y-m-d'),'startTime'=>$start->format('H:i'),'endTime'=>$end->format('H:i')];
    }
}
