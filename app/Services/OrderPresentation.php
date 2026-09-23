<?php
namespace App\Services;

final class OrderPresentation {
    public static function paymentLabel(array $order): string {
        $type=(string)($order['channel_type']??'');
        $raw=self::raw($order);
        if($type==='emag' || str_starts_with((string)($order['channel_code']??''),'emag_')){
            return EmagOrderNormalizer::paymentLabel($raw);
        }
        if($type==='woocommerce' || in_array((string)($order['channel_code']??''),['univera','alfamed'],true)){
            foreach(['payment_method_title','payment_method'] as $key){
                $v=trim((string)($raw[$key]??''));if($v!=='')return $v;
            }
            return 'Plata WooCommerce';
        }
        return '—';
    }

    public static function deliveryLabel(array $order): string {
        $type=(string)($order['channel_type']??'');
        $raw=self::raw($order);
        if($type==='emag' || str_starts_with((string)($order['channel_code']??''),'emag_')){
            $shipping=self::shipping($order);
            $lockerText=self::ascii(strtolower(trim(implode(' ',[
                (string)($shipping['locker_name']??''),(string)($shipping['address_1']??''),
                (string)($raw['locker_name']??''),(string)(($raw['details']['locker_name']??'')),
            ]))));
            if(str_contains($lockerText,'fanbox')||str_contains($lockerText,'fan box'))return 'FANbox';
            if(str_contains($lockerText,'easybox')||str_contains($lockerText,'easy box'))return 'easybox';
            $mode=strtolower(trim((string)($raw['delivery_mode']??($raw['details']['delivery_mode']??''))));
            if($mode==='pickup'||str_contains($mode,'locker'))return 'Locker';
            if($mode==='courier'||str_contains($mode,'home')||str_contains($mode,'domicili'))return 'Livrare la domiciliu';
            return $mode!==''?$mode:'Livrare eMAG';
        }
        if($type==='woocommerce' || in_array((string)($order['channel_code']??''),['univera','alfamed'],true)){
            $candidates=[];
            foreach((array)($raw['shipping_lines']??[]) as $line)if(is_array($line))$candidates[]=(string)($line['method_title']??$line['method_id']??'');
            foreach(['shipping_method_title','shipping_method','shipping_method_name'] as $key)if(!empty($raw[$key]))$candidates[]=(string)$raw[$key];
            $label=trim(implode(' ',array_filter($candidates)));
            $norm=self::ascii(strtolower($label));
            if(str_contains($norm,'fanbox')||str_contains($norm,'fan box'))return 'FANbox';
            if(str_contains($norm,'easybox')||str_contains($norm,'easy box'))return 'easybox';
            return $label!==''?$label:'Livrare la domiciliu';
        }
        return '—';
    }

    public static function deliveryKind(array $order): string {
        $label=self::ascii(strtolower(self::deliveryLabel($order)));
        if(str_contains($label,'easybox'))return 'easybox';
        if(str_contains($label,'fanbox'))return 'fanbox';
        if(str_contains($label,'locker'))return 'locker';
        return 'home';
    }

    public static function shippingMethodRaw(array $order): string {
        $raw=self::raw($order);
        if((string)($order['channel_type']??'')==='emag')return trim((string)($raw['delivery_mode']??''));
        $parts=[];foreach((array)($raw['shipping_lines']??[]) as $line)if(is_array($line))$parts[]=trim((string)($line['method_title']??$line['method_id']??''));
        return trim(implode(' ',array_filter($parts)));
    }

    private static function raw(array $order): array {
        if(is_array($order['raw']??null))return $order['raw'];
        return json_decode((string)($order['raw_json']??'{}'),true)?:[];
    }
    private static function shipping(array $order): array {
        if(is_array($order['shipping']??null))return $order['shipping'];
        return json_decode((string)($order['shipping_json']??'{}'),true)?:[];
    }
    private static function ascii(string $value): string {
        if(function_exists('iconv')){$x=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);if(is_string($x)&&$x!=='')$value=$x;}
        return preg_replace('/\s+/',' ',strtolower(trim($value)))??strtolower(trim($value));
    }
}
