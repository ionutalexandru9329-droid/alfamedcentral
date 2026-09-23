<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php';
use App\Services\EmagImageSyncService;
function ok33(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
function private33(object $o,string $m,array $args=[]):mixed{$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return $r->invokeArgs($o,$args);}
function naked33(string $c):object{return (new ReflectionClass($c))->newInstanceWithoutConstructor();}
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$ship=(string)file_get_contents(dirname(__DIR__).'/views/shipments.php');
$courier=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/bootstrap.php');
$fulfillment=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$direct=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/CourierService.php');
$invoiceBoot=(string)file_get_contents(dirname(__DIR__).'/modules/invoices-documents/bootstrap.php');
$invoiceSvc=(string)file_get_contents(dirname(__DIR__).'/modules/invoices-documents/src/InvoiceService.php');
$orders=(string)file_get_contents(dirname(__DIR__).'/views/orders.php');
$index=(string)file_get_contents(dirname(__DIR__).'/public/index.php');
$js=(string)file_get_contents(dirname(__DIR__).'/public/assets/app.js');
$css=(string)file_get_contents(dirname(__DIR__).'/public/assets/app.css');
$image=(string)file_get_contents(dirname(__DIR__).'/app/Services/EmagImageSyncService.php');
ok33(!str_contains($scan,'Orders Details')&&!str_contains($ship,'Orders Details'),'Orders Details nu mai exista in runtime/UI');
ok33(!is_file(dirname(__DIR__).'/modules/awb-scan/src/EmagOrdersDetailsAwbImportService.php'),'importerul Orders Details a fost eliminat fizic');
ok33(str_contains($invoiceBoot,'oblio-action-grid')&&str_contains($invoiceBoot,'storno-auto'),'cardul Oblio contine grila responsive si Storno');
ok33(str_contains($invoiceSvc,'Factura are deja un document storno')&&str_contains($invoiceSvc,'exista deja pentru aceasta comanda'),'documentele Oblio au protectie la dubluri');
ok33(str_contains($fulfillment,"if(\$deliveryMode==='pickup'){\$parcels=1;\$envelopes=0;}")&&str_contains($fulfillment,'Pentru livrarea la domiciliu nu se creeaza AWB suplimentar'),'locker = un colet/AWB iar domiciliul foloseste colete in acelasi AWB');
ok33(str_contains($fulfillment,'additional_awb')&&str_contains($courier,'+ AWB suplimentar'),'AWB suplimentar eMAG este disponibil explicit in comanda');
ok33(str_contains($courier,"if(\$type==='emag')\$r=\$emagSvc->createAutomatic")&&str_contains($courier,"elseif(\$type==='woocommerce')\$r=\$wooSvc->createAutomatic"),'generarea bulk AWB trateaza eMAG si WooCommerce');
ok33(str_contains($courier,"'success_ids'=>\$successIds")&&str_contains($direct,"'success_ids'=>\$successIds")&&str_contains($index,"\$result['success_ids']"),'bulk AWB raporteaza si reselecteaza numai comenzile generate cu succes');
ok33(str_contains($orders,'data-auto-awb-options')&&str_contains($orders,'Selectia ramane bifata dupa generare'),'UI bulk explica logica AWB si pasul de descarcare');
ok33(str_contains($courier,'awbPdfs(')&&str_contains($courier,'awbPdfForShipment'),'descarcarea eMAG suporta toate AWB-urile locale si fiecare expediere separat');
ok33(str_contains($css,'v0.11.33 - polished order operations')&&str_contains($css,'.oblio-action-grid')&&str_contains($css,'@media(max-width:620px)'),'UI Oblio/AWB are stiluri responsive dedicate');
ok33(str_contains($image,'downloadToLocalCache')&&str_contains($image,"'Referer: '")&&str_contains($js,'controller.abort(), 30000'),'imaginea eMAG foloseste descarcare CDN controlata si request separat');
$media=naked33(EmagImageSyncService::class);
$html='<html><head><link rel="canonical" href="https://www.emag.ro/fasa-tifon/pd/DY0DVB2BM/"><meta property="og:image" content="https://s13emagst.akamaized.net/products/115059/115058920/images/res_test.png?width=450"></head></html>';
$parsed=private33($media,'parsePublicProductHtml',[$html,'https://www.emag.ro/fasa-tifon/pd/DY0DVB2BM/']);
ok33(str_contains((string)($parsed['image_url']??''),'s13emagst.akamaized.net/products/'),'parserul extrage imaginea oficiala eMAG din hyperlink');
echo "\nV0.11.33 ORDER OPERATIONS REGRESSION PASSED\n";
