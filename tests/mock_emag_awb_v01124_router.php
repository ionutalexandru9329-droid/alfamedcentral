<?php
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
parse_str(file_get_contents('php://input')?:'', $body);
$data=is_array($body['data']??null)?$body['data']:$body;
header('Content-Type: application/json; charset=utf-8');

$isErrorPath=str_starts_with($path,'/api-error/');
$resourcePath=preg_replace('#^/(?:api-3|api-error)#','',$path)?:$path;

if($resourcePath==='/order/read'){
    $id=(string)($data['id']??'');
    if(in_array($id,['516452638','516452639'],true)){
        echo json_encode(['isError'=>false,'results'=>[[
            'id'=>(int)$id,'status'=>4,'type'=>3,'currency'=>'RON','date'=>'2026-09-17 14:54:52','total'=>45.98,
            'payment_mode_id'=>3,'delivery_mode'=>'pickup',
            'customer'=>['name'=>'Test Client','shipping_contact'=>'Test Client','email'=>'test@example.invalid','shipping_phone'=>'0700000000','shipping_street'=>'Test','shipping_city'=>'Galati','shipping_country'=>'RO'],
            'details'=>['locker_id'=>'13755','locker_name'=>'easybox test'],
            'products'=>[['id'=>991,'product_id'=>555,'part_number'=>'ON483','name'=>'Produs test','quantity'=>1,'sale_price'=>45.98,'vat'=>19]]
        ]]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>false,'results'=>[]]);exit;
}

if($resourcePath==='/awb/read'){
    if($isErrorPath){http_response_code(403);echo json_encode(['isError'=>true,'messages'=>['AWB/read not allowed for this account']]);exit;}
    $page=max(1,(int)($data['currentPage']??1));
    if($page>=24){echo json_encode(['isError'=>false,'data'=>['payload'=>['items'=>[]]]]);exit;}
    if($page===23){
        echo json_encode(['isError'=>false,'data'=>['payload'=>['items'=>[[
            'emag_id'=>255703514,
            'order_id'=>516452638,
            'shipment'=>['labels'=>[[
                'emag_id'=>255703514,
                'awb_number'=>'4EMGLN187446944',
                'awb_barcode'=>'4EMGLN187446944001',
                'courier_name'=>'Sameday'
            ]]]
        ]]]]],JSON_UNESCAPED_SLASHES);exit;
    }
    echo json_encode(['isError'=>false,'data'=>['payload'=>['items'=>[[
        'emag_id'=>100000+$page,
        'order_id'=>700000000+$page,
        'shipment'=>['labels'=>[[
            'emag_id'=>110000+$page,
            'awb_number'=>'4EMGTEST'.str_pad((string)$page,9,'0',STR_PAD_LEFT),
            'awb_barcode'=>'4EMGTEST'.str_pad((string)$page,12,'0',STR_PAD_LEFT),
            'courier_name'=>'Sameday'
        ]]]
    ]]]]],JSON_UNESCAPED_SLASHES);exit;
}

http_response_code(404);echo json_encode(['isError'=>true,'messages'=>['mock route not found: '.$path]]);
