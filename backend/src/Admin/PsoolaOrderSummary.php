<?php
declare(strict_types=1);
namespace Ismile\Admin;

final class PsoolaOrderSummary
{
    public static function kind(array $order): string
    {
        if (($order['isComplimentary']??false)===true) return ($order['order']['totalAmount']??null)===0 && ($order['order']['currency']??null)==='IQD'?'complimentary':'review';
        return ($order['channel']??'')==='direct'?'cash':'online';
    }
    public static function totals(array $orders,array $registrations=[]): array
    {
        $sum=['orders'=>0,'tickets'=>0,'card'=>0,'cash'=>0,'cash_orders'=>0,'used'=>0,'missing'=>0,
            'complimentary_orders'=>0,'complimentary_tickets'=>0,'discount'=>0,'review'=>0];
        foreach($orders as $order){
            $sum['orders']++;$quantity=(int)($order['order']['ticketCount']??0);$sum['tickets']+=$quantity;
            $kind=self::kind($order);$amount=(int)($order['order']['totalAmount']??0);
            if($kind==='complimentary'){$sum['complimentary_orders']++;$sum['complimentary_tickets']+=$quantity;$sum['discount']+=(int)($order['order']['discountAmount']??0);}
            elseif($kind==='review')$sum['review']++;
            elseif($kind==='cash'){$sum['cash']+=$amount;$sum['cash_orders']++;}
            else $sum['card']+=$amount;
            foreach((array)($order['tickets']??[]) as $ticket)if(is_array($ticket)&&!empty($ticket['checkedIn']))$sum['used']++;
            if(!isset($registrations[$order['partnerReference']??'']))$sum['missing']++;
        }
        return $sum;
    }
}
