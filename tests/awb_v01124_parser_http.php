<?php
declare(strict_types=1);
if(!function_exists('curl_init')&&!filter_var((string)ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){echo "SKIP: awb_v01124_parser_http - lipseste transportul HTTP PHP\n";exit(0);}
require dirname(__DIR__).'/app/Core/Http.php';
require dirname(__DIR__).'/app/Integrations/EmagClient.php';
require dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php';
use App\Integrations\EmagClient;use AlfamedModules\Couriers\EmagFulfillmentService;
function okp(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
function priv(object $o,string $m,array $a=[]):mixed{$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return $r->invokeArgs($o,$a);}
$base=getenv('ALFAMED_MOCK_BASE')?:'http://127.0.0.1:18082/api-3';
$c=new EmagClient($base,'test','test','RON');
$r=$c->readAwbs(['currentPage'=>23,'itemsPerPage'=>100],2);
$svc=(new ReflectionClass(EmagFulfillmentService::class))->newInstanceWithoutConstructor();
$rows=priv($svc,'resultRows',[$r]);okp(count($rows)===1,'wrapper-ul HTTP data/payload/items este redus la un shipment row');
$entries=priv($svc,'parsedAwbEntries',[$rows[0]]);okp(($entries[0]['awb_barcode']??'')==='4EMGLN187446944001','shipment/labels produce barcode-ul real');
okp(priv($svc,'remoteOrderId',[$rows[0]])==='516452638','shipment-ul real pastreaza order_id');
$empty=$c->readAwbs(['currentPage'=>24,'itemsPerPage'=>100],2);$emptyRows=priv($svc,'resultRows',[$empty]);okp($emptyRows===[],'pagina 24 goala este detectata ca final de istoric');
echo "AWB V0.11.24 PARSER HTTP PASSED\n";
