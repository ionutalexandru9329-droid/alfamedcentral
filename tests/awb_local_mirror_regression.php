<?php
declare(strict_types=1);
function ok(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$fulfillment=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$couriers=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/bootstrap.php');
$view=(string)file_get_contents(dirname(__DIR__).'/views/shipments.php');
$js=(string)file_get_contents(dirname(__DIR__).'/public/assets/app.js');
ok(!str_contains($scan,'recoverRemoteAwbByToken('),'scannerul foloseste doar datele locale');
ok(!str_contains($scan,'recoverWooTrackingLive($db,$candidate)'),'scannerul nu face request extern');
ok(str_contains($scan,'SELECT s2.awb FROM shipments'),'lookup-ul de scanare foloseste shipments local');
ok(!str_contains($scan,'import-emag-orders-details')&&!str_contains($view,'Orders Details'),'fluxul Orders Details a fost eliminat complet din runtime');
ok(!str_contains($view,'Import automat AWB eMAG'),'panoul eMAG AWB automat ramane eliminat');
ok(!str_contains($fulfillment,'syncRecentRemoteAwbs(')&&!str_contains($fulfillment,'syncMissingOrderAwbs('),'serviciul nu mai parcurge AWB/read in fundal');
ok(str_contains($fulfillment,'additional_awb')&&str_contains($fulfillment,"\$deliveryMode!=='pickup'"),'AWB suplimentar este un flux explicit numai pentru pickup/locker');
ok(str_contains($couriers,'awbPdfs('),'descarcarea bulk poate include toate expedierile eMAG locale');
ok(str_contains($js,'controller.abort(), 5000'),'scanarea are timeout client scurt');
echo "\nAWB LOCAL FLOW REGRESSION TESTS PASSED\n";
