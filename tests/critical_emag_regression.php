<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php';
use AlfamedModules\Couriers\EmagFulfillmentService;
use App\Services\EmagImageSyncService;
use App\Services\OrderOperations;
use App\Services\SyncService;
function callPrivate(object $object,string $method,array $args=[]): mixed {$r=new ReflectionMethod($object,$method);$r->setAccessible(true);return $r->invokeArgs($object,$args);}
function withoutConstructor(string $class): object{return (new ReflectionClass($class))->newInstanceWithoutConstructor();}
function ok(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$scanRoutes=require dirname(__DIR__).'/modules/awb-scan/bootstrap.php';
foreach(['4EMGLN187446944001',']C14EMGLN187446944001','{"awb_barcode":"4EMGLN187446944001"}'] as $input){$c=$scanCandidates($input);ok(($c[0]??'')==='4EMGLN187446944001','parser scanare pastreaza codul AWB complet');}
$awb=withoutConstructor(EmagFulfillmentService::class);
$row=['emag_id'=>900001,'order_id'=>'516452638','awb'=>[['emag_id'=>910001,'awb_number'=>'4EMGLN187446944','awb_barcode'=>'4EMGLN187446944001','courier_name'=>'Sameday']]];
$entries=callPrivate($awb,'parsedAwbEntries',[$row]);
ok(count($entries)===1&&($entries[0]['awb_barcode']??'')==='4EMGLN187446944001','parserul raspunsului AWB/save pastreaza barcode-ul');
ok(callPrivate($awb,'remoteOrderId',[$row])==='516452638','raspunsul AWB/save este legat de comanda externa');
$media=withoutConstructor(EmagImageSyncService::class);
ok(callPrivate($media,'isTrustedEmagImageUrl',['https://www.alfamedclinic.ro/wp-content/uploads/woocommerce/product.jpg'])===false,'imaginea WooCommerce este respinsa pentru comanda eMAG');
ok(callPrivate($media,'isTrustedEmagImageUrl',['https://s13emagst.akamaized.net/products/71910/test.png'])===true,'CDN-ul oficial eMAG este acceptat');
ok(callPrivate($media,'isEmagProductUrl',['https://www.emag.ro/produs/pd/DF6Q5FYBM/'])===true,'hyperlinkul direct eMAG /pd/ este acceptat');
$html='<html><head><link rel="canonical" href="https://www.emag.ro/apa-oxigenata/pd/DF6Q5FYBM/"><meta property="og:image" content="https://s13emagst.akamaized.net/products/71910/test.png"></head></html>';
$parsed=callPrivate($media,'parsePublicProductHtml',[$html,'https://www.emag.ro/produs/pd/DF6Q5FYBM/']);
ok(($parsed['image_url']??'')==='https://s13emagst.akamaized.net/products/71910/test.png','imaginea poate fi extrasa din hyperlinkul eMAG prin og:image');
$orderOps=withoutConstructor(OrderOperations::class);
ok(callPrivate($orderOps,'isAllowedEmagMediaUrl',['https://s13emagst.akamaized.net/products/a.jpg',['emag.ro','www.emag.ro']])===true,'order view accepta imaginea eMAG');
ok(callPrivate($orderOps,'isDirectEmagProductUrl',['https://www.emag.ro/produs/pd/DDZRZTMBM/',['emag.ro','www.emag.ro']])===true,'order view pastreaza hyperlinkul eMAG valid');
$sync=withoutConstructor(SyncService::class);
ok(callPrivate($sync,'isTrustedEmagImageUrl',['https://www.alfamedclinic.ro/wp-content/uploads/woo.jpg'])===false,'sincronizarea nu introduce media WooCommerce in comenzile eMAG');
$awbSource=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$scanSource=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
ok(!str_contains($awbSource,'refreshOrderAwb(')&&!str_contains($awbSource,'recoverRemoteAwbByToken('),'codul experimental de descoperire AWB eMAG a fost eliminat');
ok(!str_contains($scanSource,'reconcileHistoricalMissingOrderAwbs('),'workerul AWB eMAG istoric a fost eliminat');
ok(str_contains($awbSource,'activeLocals($orderId)')&&str_contains($awbSource,'additional_awb'),'crearea AWB nou verifica expedierile locale si permite suplimentar doar explicit');
echo "\nCRITICAL EMAG REGRESSION TESTS PASSED\n";
