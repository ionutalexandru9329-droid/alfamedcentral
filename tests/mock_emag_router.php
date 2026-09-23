<?php
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$rawBody=file_get_contents('php://input')?:'';$contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
if(str_contains($contentType,'application/json')){$body=json_decode($rawBody,true);if(!is_array($body))$body=[];}else{parse_str($rawBody,$body);}
$data=is_array($body['data']??null)?$body['data']:$body;
header('Content-Type: application/json; charset=utf-8');
if($path==='/api-3/awb/save'){
    if(!str_contains($contentType,'application/json')||!isset($body['data'])||!is_array($body['data'])||!array_is_list($body['data'])){http_response_code(422);echo json_encode(['isError'=>true,'messages'=>['awb/save request must be JSON data array']]);exit;}
    $row=(array)($body['data'][0]??[]);$orderId=(int)($row['order_id']??0);
    if($orderId===516452638){
        echo json_encode(['isError'=>false,'messages'=>[],'results'=>[ [
            'emag_id'=>255703514,'reservation_id'=>900001,'awb_number'=>'4EMGLN187446944','awb_barcode'=>'4EMGLN187446944001','order_id'=>516452638,'type'=>3,
            'courier'=>['courier_account_id'=>123,'courier_name'=>'Sameday']
        ] ]],JSON_UNESCAPED_SLASHES);exit;
    }
    if($orderId===516452639){
        // Simulate a real gateway variant that acknowledges the reservation first;
        // EmagClient must follow up with awb/read instead of issuing a second save.
        echo json_encode(['isError'=>false,'messages'=>[],'results'=>[ [
            'emag_id'=>255703515,'reservation_id'=>900002,'order_id'=>516452639,'type'=>3
        ] ]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>true,'messages'=>['unknown mock order']]);exit;
}
if($path==='/api-3/awb/read'){
    if(!str_contains($contentType,'application/json')||isset($body['data'])){http_response_code(422);echo json_encode(['isError'=>true,'messages'=>['awb/read request must be top-level JSON']]);exit;}
    $emagId=(int)($body['emag_id']??0);$reservationId=(int)($body['reservation_id']??0);
    if($emagId===255703514){
        echo json_encode(['isError'=>false,'results'=>[ [
            'emag_id'=>255703514,'reservation_id'=>900001,'order_id'=>516452638,
            'awb_number'=>'4EMGLN187446944','awb_barcode'=>'4EMGLN187446944001','courier'=>['courier_name'=>'Sameday']
        ] ]],JSON_UNESCAPED_SLASHES);exit;
    }
    if($emagId===255703515||$reservationId===900002){
        echo json_encode(['isError'=>false,'results'=>[ [
            'emag_id'=>255703515,'reservation_id'=>900002,'order_id'=>516452639,
            'awb_number'=>'4EMGLN187446955','awb_barcode'=>'4EMGLN187446955001','courier'=>['courier_name'=>'Sameday']
        ] ]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>true,'messages'=>['Emag id or reservation id is mandatory']]);exit;
}
if($path==='/api-3/order/attachments/save'){
    if(!str_contains($contentType,'application/json')||!isset($body['data'])||!is_array($body['data'])||!array_is_list($body['data'])){http_response_code(422);echo json_encode(['isError'=>true,'messages'=>['attachments/save request must be JSON data array']]);exit;}
    $att=(array)($body['data'][0]??[]);
    if((int)($att['order_id']??0)<=0){echo json_encode(['isError'=>true,'messages'=>['ERROR: order_id este necesar pentru fiecare atasament']]);exit;}
    if(!in_array((int)($att['order_type']??0),[2,3],true)){echo json_encode(['isError'=>true,'messages'=>['ERROR: order_type este necesar']]);exit;}
    if((int)($att['type']??0)!==1||!str_ends_with(strtolower((string)($att['url']??'')),'.pdf')){echo json_encode(['isError'=>true,'messages'=>['invalid invoice attachment']]);exit;}
    echo json_encode(['isError'=>false,'messages'=>[],'results'=>[null]],JSON_UNESCAPED_SLASHES);exit;
}
if($path==='/api-3/order/attachments/read'){
    if(!str_contains($contentType,'application/json')||isset($body['data'])){http_response_code(422);echo json_encode(['isError'=>true,'messages'=>['attachments request must be top-level JSON']]);exit;}
    $id=(string)($body['order_id']??'');$type=(int)($body['order_type']??0);
    if($id==='516452638'&&$type===3){
        echo json_encode(['isError'=>false,'results'=>[[
            'order_id'=>516452638,'order_type'=>3,'name'=>'AWB','type'=>10,
            'url'=>'https://marketplace.emag.ro/awb/read_pdf?emag_id=255703514','force_download'=>1
        ]]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>false,'results'=>[]]);exit;
}
if($path==='/api-3/order/read'){
    $id=(string)($data['id']??'');
    if($id==='516452638'){
        echo json_encode(['isError'=>false,'results'=>[array_filter([
            'id'=>516452638,'status'=>4,'type'=>3,'currency'=>'RON','date'=>'2026-09-17 20:00:00','total'=>49.99,
            'payment_mode_id'=>3,'delivery_mode'=>'pickup',
            'customer'=>[
                'name'=>'Dinu Ali','shipping_contact'=>'Dinu Ali','email'=>'test@example.invalid','shipping_phone'=>'0700000000',
                'shipping_street'=>'Str. 1 Decembrie 1918, Nr. 134','shipping_city'=>'Petrosani','shipping_suburb'=>'Hunedoara','shipping_postal_code'=>'332057','shipping_country'=>'RO','shipping_locality_id'=>12345
            ],
            'details'=>['locker_id'=>'987','locker_name'=>'easybox Partener Rompetrol Petrosani'],
            'products'=>[[
                'id'=>991,'product_id'=>555,'part_number'=>'ON483','name'=>'Detergent test eMAG','quantity'=>1,'sale_price'=>42.0084,'vat'=>19,'part_number_key'=>'DTESTPNKBM'
            ]]
        ],static fn($v)=>$v!==null)]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>false,'results'=>[]]);exit;
}
if($path==='/api-3/product_offer/read'){
    $id=(string)($data['id']??'');$pnk=strtoupper(trim((string)($data['part_number_key']??'')));$part=strtoupper(trim((string)($data['part_number']??'')));
    if($id==='555'||$pnk==='DTESTPNKBM'||$part==='ON483'){
        echo json_encode(['isError'=>false,'results'=>[[
            'id'=>555,'part_number'=>'ON483','part_number_key'=>'DTESTPNKBM','name'=>'Detergent test eMAG',
            'images'=>[['url'=>'https://s13emagst.akamaized.net/products/mock/test.jpg','display_type'=>1]]
        ]]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>false,'results'=>[]]);exit;
}
http_response_code(404);echo json_encode(['isError'=>true,'messages'=>['mock route not found: '.$path]]);
