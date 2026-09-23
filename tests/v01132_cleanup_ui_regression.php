<?php
declare(strict_types=1);
function ok32(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$view=(string)file_get_contents(dirname(__DIR__).'/views/shipments.php');
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$courier=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/bootstrap.php');
$svc=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$productSvc=(string)file_get_contents(dirname(__DIR__).'/app/Services/ProductService.php');
$imageSvc=(string)file_get_contents(dirname(__DIR__).'/app/Services/EmagImageSyncService.php');
$css=(string)file_get_contents(dirname(__DIR__).'/public/assets/app.css');
$product=(string)file_get_contents(dirname(__DIR__).'/views/product.php');
$productNew=(string)file_get_contents(dirname(__DIR__).'/views/product_new.php');
ok32(!str_contains($view,'Import automat AWB eMAG'),'panoul AWB automat a fost eliminat');
ok32(!str_contains($view,'Orders Details')&&!str_contains($scan,'import-emag-orders-details'),'importul Orders Details a fost eliminat din UI si rute');
ok32(!str_contains($courier,'emag-awb-refresh'),'optiunea de verificare/import AWB eMAG per comanda a fost eliminata');
ok32(!str_contains($svc,'refreshOrderAwb(')&&!str_contains($svc,'reconcileHistoricalMissingOrderAwbs('),'serviciul eMAG a fost curatat de backfill-ul esuat');
$taxStart=strpos($productSvc,'public function taxonomyCatalog');$taxEnd=strpos($productSvc,'public function transfer',$taxStart?:0);$tax=$taxStart!==false&&$taxEnd!==false?substr($productSvc,$taxStart,$taxEnd-$taxStart):'';
ok32($tax!==''&&!str_contains($tax,'readTerms(')&&str_contains($tax,'channel_products'),'editorul de produs incarca taxonomiile din baza locala, fara request-uri WooCommerce');
ok32(str_contains($imageSvc,'hydrateKnownProductLinks')&&str_contains($imageSvc,'publicProductMetadata'),'poza eMAG poate fi hidratata din hyperlinkul produsului');
ok32(str_contains($css,'v0.11.32 - unified responsive UI')&&str_contains($css,'@media(max-width:620px)'),'stilul global are audit responsive PC/tableta/mobil');
ok32(str_contains($product,'editor-card')&&str_contains($product,'product-editor-savebar'),'editarea produsului foloseste layout-ul nou');
ok32(str_contains($productNew,'editor-card')&&str_contains($productNew,'Editor rapid'),'crearea produsului foloseste layout-ul nou');
echo "\nV0.11.32 CLEANUP + UI REGRESSION PASSED\n";
