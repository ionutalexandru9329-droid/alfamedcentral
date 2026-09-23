<?php
declare(strict_types=1);
function ok(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$index=(string)file_get_contents(dirname(__DIR__).'/public/index.php');
$js=(string)file_get_contents(dirname(__DIR__).'/public/assets/app.js');
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$courier=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/bootstrap.php');
$fulfillment=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$start=strpos($index,"if(preg_match('#^/orders/(\\d+)/preview$#'");
$end=strpos($index,"if(preg_match('#^/orders/(\\d+)$#'",$start?:0);
$preview=$start!==false&&$end!==false?substr($index,$start,$end-$start):'';
ok($preview!=='','ruta preview a fost gasita');
ok(!str_contains($preview,'syncExistingRemoteAwb'),'preview-ul nu face lookup AWB extern');
ok(!str_contains($preview,'InvoiceService'),'preview-ul nu sincronizeaza documente externe');
ok(str_contains($preview,'SELECT type,series,number,status,link FROM invoices'),'preview-ul citeste documentele numai local');
ok(str_contains($js,'controller.abort(), 8000'),'preview-ul are timeout client finit');
ok(str_contains($js,'controller.abort(), 5000'),'scanarea locala are timeout client scurt');
ok(!str_contains($scan,'recoverRemoteAwbByToken('),'scanarea nu interogheaza eMAG');
ok(!str_contains($scan,'recoverWooTrackingLive($db,$candidate)'),'scanarea nu interogheaza WooCommerce');
ok(!str_contains($courier,'emag-awb-refresh'),'nu mai exista endpoint/buton de recuperare automata AWB eMAG');
ok(!str_contains($js,'refreshEmagOrderAwb'),'browserul nu mai porneste recuperari AWB eMAG esuate');
ok(!str_contains($fulfillment,'refreshOrderAwb(')&&!str_contains($fulfillment,'reconcileHistoricalMissingOrderAwbs('),'serviciul eMAG nu mai contine backfill-ul experimental AWB');
echo "\nNONBLOCKING UI REGRESSION TESTS PASSED\n";
