<?php
namespace App\Services;

final class EmagOrderNormalizer {
    public static function normalize(array $order,array $channel=[]): array {
        $c=is_array($order['customer']??null)?$order['customer']:[];
        $country=(string)($channel['country']??'RO');
        $billingName=trim((string)($c['billing_name']??$c['company']??$c['name']??''));
        $shippingName=trim((string)($c['shipping_contact']??$c['name']??$billingName));
        $customerName=trim((string)($c['company']??''));
        if($customerName!=='' && trim((string)($c['name']??''))!=='')$customerName.=' - '.trim((string)$c['name']);
        if($customerName==='')$customerName=trim((string)($c['name']??$billingName??$shippingName));

        $legal=self::truthy($c['legal_entity']??false);
        $vat=self::truthy($c['is_vat_payer']??false);
        $fiscal=[
            'person_type'=>$legal?'Persoana juridica':'Persoana fizica',
            'vat_number'=>trim((string)($c['code']??'')),
            'registration_number'=>trim((string)($c['registration_number']??'')),
            'bank'=>trim((string)($c['bank']??'')),
            'iban'=>trim((string)($c['iban']??'')),
            'vat_payer'=>$vat,
        ];
        $billing=[
            'first_name'=>'','last_name'=>'','company'=>$legal?trim((string)($c['company']??$billingName)):'',
            'address_1'=>trim((string)($c['billing_street']??'')),'address_2'=>'',
            'city'=>trim((string)($c['billing_city']??'')),'state'=>trim((string)($c['billing_suburb']??'')),
            'country'=>trim((string)($c['billing_country']??$country)) ?: $country,
            'postcode'=>trim((string)($c['billing_postal_code']??'')),
            'phone'=>trim((string)($c['billing_phone']??$c['phone_1']??$c['phone_2']??'')),
            'email'=>trim((string)($c['email']??'')),
            'name'=>$billingName,
            'locality_id'=>(string)($c['billing_locality_id']??''),
            '_fiscal'=>$fiscal,
        ];
        $details=is_array($order['details']??null)?$order['details']:[];
        $shipping=[
            'first_name'=>'','last_name'=>'','company'=>'',
            'address_1'=>trim((string)($c['shipping_street']??'')),'address_2'=>'',
            'city'=>trim((string)($c['shipping_city']??'')),'state'=>trim((string)($c['shipping_suburb']??'')),
            'country'=>trim((string)($c['shipping_country']??$country)) ?: $country,
            'postcode'=>trim((string)($c['shipping_postal_code']??'')),
            'phone'=>trim((string)($c['shipping_phone']??$c['phone_1']??$c['phone_2']??'')),
            'email'=>trim((string)($c['email']??'')),
            'name'=>$shippingName,
            'locality_id'=>(string)($c['shipping_locality_id']??''),
            'locker_id'=>(string)($details['locker_id']??$order['locker_id']??''),
            'locker_name'=>(string)($details['locker_name']??$order['locker_name']??''),
        ];
        return [
            'customer_name'=>$customerName!==''?$customerName:($shippingName!==''?$shippingName:'Client eMAG'),
            'email'=>(string)($c['email']??''),
            'phone'=>(string)($c['shipping_phone']??$c['billing_phone']??$c['phone_1']??$c['phone_2']??''),
            'billing'=>$billing,
            'shipping'=>$shipping,
            'delivery_mode'=>strtolower(trim((string)($order['delivery_mode']??''))),
            'payment_mode_id'=>(int)($order['payment_mode_id']??0),
            'shipping_tax'=>(float)($order['shipping_tax']??0),
            'type'=>(int)($order['type']??3),
        ];
    }

    public static function vatPercent(mixed $value): float {
        $v=(float)$value;
        if($v>0 && $v<1)$v*=100;
        return round(max(0,$v),4);
    }

    public static function grossPrice(array $item): float {
        $net=(float)($item['sale_price']??$item['price']??0);
        $vat=self::vatPercent($item['vat']??$item['vat_rate']??0);
        return round($net*(1+$vat/100),4);
    }


    public static function voucherTotal(array $order): float {
        $sum=0.0;
        foreach((array)($order['vouchers']??[]) as $voucher){
            if(!is_array($voucher))continue;
            if(array_key_exists('sale_price',$voucher)||array_key_exists('sale_price_vat',$voucher)){
                $sum+=(float)($voucher['sale_price']??0)+(float)($voucher['sale_price_vat']??0);
                continue;
            }
            if(array_key_exists('value',$voucher)){
                $value=(float)$voucher['value'];
                $sum+=$value>0?-$value:$value;
            }
        }
        return round($sum,2);
    }

    public static function total(array $order): float {
        foreach(['total','total_price','grand_total'] as $key){
            if(array_key_exists($key,$order)&&is_numeric($order[$key]))return round((float)$order[$key],2);
        }
        $sum=0.0;
        foreach((array)($order['products']??$order['items']??[]) as $item){
            if(!is_array($item))continue;
            $qty=(float)($item['quantity']??1);
            $sum+=self::grossPrice($item)*$qty;
        }
        $sum+=(float)($order['shipping_tax']??0);
        $sum+=self::voucherTotal($order);
        return round($sum,2);
    }

    public static function paymentLabel(array $order): string {
        $id=(int)($order['payment_mode_id']??0);
        if($id===1)return 'Ramburs la livrare';if($id===2)return 'Transfer bancar';if($id===3)return 'Card online';
        $raw=trim((string)($order['detailed_payment_method']??$order['payment_mode']??$order['payment_method']??''));
        if($raw==='')return 'Plata eMAG';
        $norm=strtolower($raw);
        if(str_contains($norm,'ramburs')||str_contains($norm,'cash'))return 'Ramburs la livrare';
        if(str_contains($norm,'card'))return 'Card online';
        if(str_contains($norm,'transfer')||str_contains($norm,'ordin'))return 'Transfer bancar';
        return $raw;
    }

    public static function deliveryLabel(array $order): string {
        $mode=strtolower(trim((string)($order['delivery_mode']??'')));
        if($mode==='pickup')return 'easybox / locker';
        if($mode==='courier')return 'Livrare la domiciliu';
        return $mode!==''?$mode:'Livrare eMAG';
    }

    private static function truthy(mixed $value): bool {
        if(is_bool($value))return $value;
        if(is_numeric($value))return (int)$value===1;
        return in_array(strtolower(trim((string)$value)),['1','true','yes','da','y'],true);
    }
}
