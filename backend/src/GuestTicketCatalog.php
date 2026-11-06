<?php
declare(strict_types=1);
namespace Ismile;

use Ismile\Payments\PsoolaGateway;

/** A server-side allow-list from the partner's general-admission catalogue. */
final class GuestTicketCatalog
{
    public static function events(): array
    {
        $answer=(new PsoolaGateway())->partnerRequest('GET','events');
        if ($answer['status']!==200 || !is_array($answer['json']['events']??null)) throw new UserError('The PSOOLA event catalogue is unavailable. Refresh before issuing tickets.');
        $events=[];
        foreach ($answer['json']['events'] as $event) if (is_array($event)) $events[]=self::normalize($event);
        return $events;
    }

    public static function event(string $id): array
    {
        $answer=(new PsoolaGateway())->partnerRequest('GET','events/'.rawurlencode($id));
        if ($answer['status']!==200 || !is_array($answer['json']['event']??null) || ($answer['json']['event']['id']??null)!==$id) throw new UserError('This event is unavailable to our PSOOLA partner. Refresh the available events.');
        return self::normalize($answer['json']['event']);
    }

    public static function normalize(array $event): array
    {
        $out=['id'=>(string)($event['id']??''),'name'=>self::label($event['name']??$event['title']??'Event'), 'shows'=>[]];
        foreach ((array)($event['shows']??[]) as $show) {
            if (!is_array($show) || !self::generalAdmission($event,$show)) continue;
            $row=['id'=>(string)($show['id']??''),'name'=>self::label($show['name']??$show['title']??$show['dateTime']??'Show'),
                'maxQuantity'=>min(self::limit($show,2000),self::limit($event,2000)), 'closed'=>self::closed($event)||self::closed($show), 'ticketTypes'=>[]];
            foreach ((array)($show['ticketTypes']??[]) as $type) {
                if (!is_array($type) || !is_string($type['id']??null)) continue;
                $ticket=['id'=>$type['id'],'name'=>self::label($type['name']??$type['title']??'Ticket'),
                    'available'=>self::available($type),'maxQuantity'=>min($row['maxQuantity'],self::limit($type)),
                    'closed'=>self::closed($type),'addOns'=>[]];
                foreach ((array)($type['addOns']??[]) as $addOn) if (is_array($addOn) && is_string($addOn['id']??null)) {
                    $ticket['addOns'][]=['id'=>$addOn['id'],'name'=>self::label($addOn['name']??$addOn['title']??'Add-on'),
                        'available'=>self::available($addOn),'closed'=>self::closed($addOn)];
                }
                $row['ticketTypes'][]=$ticket;
            }
            if ($row['id']!=='' && $row['ticketTypes']) $out['shows'][]=$row;
        }
        return $out;
    }

    public static function selection(array $in): array
    {
        $eventId=self::id($in['event_id']??null); $showId=self::id($in['show_id']??null);
        if (isset($in['items_json'])) {
            if (!is_string($in['items_json']) || strlen($in['items_json'])>25000) throw new UserError('Select valid ticket items.');
            $items=json_decode($in['items_json'],true);
        } else {
            $addons=$in['add_on_ids']??[];
            if(isset($in['add_on_ids_json'])) {
                if(!is_string($in['add_on_ids_json'])||strlen($in['add_on_ids_json'])>3000)throw new UserError('Choose valid add-ons.');
                $addons=json_decode($in['add_on_ids_json'],true);
            }
            $items=[['ticketTypeId'=>$in['ticket_type_id']??null,'quantity'=>$in['quantity']??null,'addOnIds'=>$addons]];
        }
        if (!is_array($items) || !array_is_list($items) || count($items)<1 || count($items)>20) throw new UserError('Choose between 1 and 20 ticket items.');
        $normalized=[];
        foreach ($items as $item) {
            if (!is_array($item)) throw new UserError('Choose valid ticket items.');
            $quantity=$item['quantity']??null;
            if ((!is_int($quantity) && !(is_string($quantity)&&preg_match('/^[1-9][0-9]{0,2}$/D',$quantity))) || (int)$quantity<1 || (int)$quantity>100) throw new UserError('Each ticket quantity must be a whole number from 1 to 100.');
            $addons=$item['addOnIds']??[];
            if (!is_array($addons) || !array_is_list($addons) || count($addons)>10 || count(array_unique($addons,SORT_REGULAR))!==count($addons)) throw new UserError('Choose no more than 10 distinct add-ons per ticket item.');
            $row=['ticketTypeId'=>self::id($item['ticketTypeId']??null),'quantity'=>(int)$quantity];
            if ($addons) $row['addOnIds']=array_map(self::id(...),$addons);
            $normalized[]=$row;
        }
        return ['eventId'=>$eventId,'showId'=>$showId,'items'=>$normalized];
    }

    public static function verify(array $selection): array
    {
        $event=self::event($selection['eventId']);
        $show=null;
        foreach ($event['shows'] as $candidate) if ($candidate['id']===$selection['showId']) $show=$candidate;
        if (!$show) throw new UserError('This show is not supported by the general-admission partner API.');
        if ($show['closed']) throw new UserError('PSOOLA reports that issuance has closed for this show.');
        $types=array_column($show['ticketTypes'],null,'id');$quantities=[];$addons=[];$total=0;
        foreach ($selection['items'] as $item) {
            $type=$types[$item['ticketTypeId']]??null;
            if (!$type || $type['closed']) throw new UserError('This ticket type is unavailable. Refresh the catalogue.');
            $qty=($quantities[$type['id']]??0)+$item['quantity'];$quantities[$type['id']]=$qty;$total+=$item['quantity'];
            if ($qty>$type['maxQuantity'] || ($type['available']!==null && $qty>$type['available'])) throw new UserError('The selected quantity exceeds PSOOLA availability or its ticket limit.');
            $allowed=array_column($type['addOns'],null,'id');
            foreach ($item['addOnIds']??[] as $id) {
                $add=$allowed[$id]??null;
                if (!$add || $add['closed']) throw new UserError('This add-on is unavailable for the selected ticket. Refresh the catalogue.');
                $addons[$id]=($addons[$id]??0)+$item['quantity'];
                if ($add['available']!==null && $addons[$id]>$add['available']) throw new UserError('The selected add-on does not have enough capacity.');
            }
        }
        if ($total>$show['maxQuantity']) throw new UserError('The selected quantity exceeds the show order limit.');
        return ['event'=>$event,'show'=>$show,'quantity'=>$total];
    }

    private static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,190}$/D',$value)) throw new UserError('Select an event, show and ticket type from PSOOLA.');
        return $value;
    }
    private static function label(mixed $value): array
    {
        if (is_array($value)) return array_map(static fn($v)=>is_string($v)?mb_substr($v,0,250):'',array_intersect_key($value,array_flip(['en','ar','ckb','ku','kmr'])));
        return ['en'=>mb_substr((string)$value,0,250)];
    }
    private static function available(array $row): ?int
    {
        $value=$row['available']??$row['remaining']??null;
        return is_int($value)&&$value>=0?$value:null;
    }
    private static function limit(array $row,int $default=100): int
    {
        $limits=[$default];
        foreach (['maxQuantity','maxPerOrder','maxTicketsPerOrder','perBuyerLimit'] as $key) if (is_int($row[$key]??null) && $row[$key]>0) $limits[]=$row[$key];
        return min($limits);
    }
    private static function closed(array $row): bool
    {
        if (($row['salesOpen']??true)===false || ($row['isAvailable']??true)===false) return true;
        foreach (['salesStart'=>'start','salesEnd'=>'end','salesStartAt'=>'start','salesEndAt'=>'end'] as $key=>$bound) {
            $at=$row[$key]??null;
            $seconds=is_numeric($at)?((float)$at>=100000000000?(float)$at/1000:(float)$at):(is_string($at)?strtotime($at):false);
            if ($seconds && (($bound==='start' && $seconds>time()) || ($bound==='end' && $seconds<time()))) return true;
        }
        return false;
    }
    private static function generalAdmission(array $event,array $show): bool
    {
        foreach ([$event,$show] as $row) {
            if (($row['hasSeating']??false) || ($row['requiresSeatSelection']??false) || ($row['isCinema']??false)) return false;
            foreach (['type','showType','seatingType'] as $key) if (in_array(strtolower((string)($row[$key]??'')),['cinema','movie','reserved','reserved_seating','assigned_seating'],true)) return false;
        }
        return true;
    }
}
