<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Core/Http.php';
require dirname(__DIR__).'/app/Integrations/EmagClient.php';
use App\Integrations\EmagClient;

$base=getenv('ALFAMED_MOCK_BASE')?:'http://127.0.0.1:18081/api-3';
$c=new EmagClient($base,'test','test','RON');
$o=$c->readOrderById('516452638',2);
if((string)($o['id']??'')!=='516452638')throw new RuntimeException('order/read exact id failed');
$offer=$c->readOfferByPartNumberKey('DTESTPNKBM');
if((string)($offer['part_number_key']??'')!=='DTESTPNKBM')throw new RuntimeException('product_offer/read exact PNK failed');
if(!str_contains((string)($offer['images'][0]['url']??''),'/products/'))throw new RuntimeException('product_offer/read product image missing');
$ar=$c->readOrderAttachments('516452638',3,2);
$attachments=(array)($ar['results']??[]);
if((int)($attachments[0]['type']??0)!==10)throw new RuntimeException('order/attachments/read AWB attachment missing');

$a=$c->readAwbs(['emag_id'=>255703514],2);
$r=(array)($a['results'][0]??[]);
if((string)($r['order_id']??'')!=='516452638')throw new RuntimeException('awb/read order_id mismatch');
if((string)($r['awb_barcode']??'')!=='4EMGLN187446944001')throw new RuntimeException('awb/read barcode mismatch');

// Official API 4.5.1 shape: awb/save receives JSON {data:[{...}]} and can return the
// printable number immediately.
$saved=$c->createAwb(['order_id'=>516452638,'sender'=>[],'receiver'=>[],'is_oversize'=>0,'weight'=>1,'envelope_number'=>0,'parcel_number'=>1,'cod'=>0]);
$sr=(array)($saved['results'][0]??[]);
if((string)($sr['awb_number']??'')!=='4EMGLN187446944')throw new RuntimeException('awb/save direct response parsing failed');

// Gateway variant: save returns only identifiers; client must recover through awb/read
// without issuing a second save.
$recovered=$c->createAwb(['order_id'=>516452639,'sender'=>[],'receiver'=>[],'is_oversize'=>0,'weight'=>1,'envelope_number'=>0,'parcel_number'=>1,'cod'=>0]);
$rr=(array)($recovered['results'][0]??[]);
if((string)($rr['awb_number']??'')!=='4EMGLN187446955')throw new RuntimeException('awb/save follow-up awb/read recovery failed');
if(!isset($recovered['_save_response']))throw new RuntimeException('awb/save recovery did not preserve save response');

$att=$c->saveOrderAttachment(516452638,'Factura FCT 123','https://alfamedclinic.ro/uploads/invoices/test.pdf',1,true,3);
if(($att['isError']??true)!==false)throw new RuntimeException('order/attachments/save failed');

echo "EMAG CLIENT HTTP SMOKE PASSED\n";
