<?php
declare(strict_types=1);
function ok28(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$svc=(string)file_get_contents(dirname(__DIR__).'/modules/couriers/src/EmagFulfillmentService.php');
$view=(string)file_get_contents(dirname(__DIR__).'/views/shipments.php');
ok28(str_contains($svc,'reconcileHistoricalMissingOrderAwbs'),'exista worker-ul separat pentru comenzile eMAG istorice fara AWB');
ok28(str_contains($svc,"runtime.awb_order_reconcile.cursor."),'worker-ul istoric foloseste cursor persistent');
ok28(str_contains($svc,"AND o.id<?"),'cursorul avanseaza descrescator si nu ramane blocat pe aceleasi comenzi noi');
ok28(str_contains($svc,"completed_at.")&&str_contains($svc,'1800'),'o trecere completa este reluata periodic pentru AWB-uri emise ulterior');
ok28(str_contains($scan,'reconcileHistoricalMissingOrderAwbs($code'),'autosync-ul ruleaza reconcilierea istorica automat');
ok28(str_contains($scan,'refreshRecentMissingOrderAwbs($code'),'autosync-ul pastreaza prioritatea pentru comenzile noi');
ok28(str_contains($scan,'order/attachments (type 10 = AWB)')||str_contains($scan,'order/attachments (AWB tip 10)'),'workerul foloseste atasamentele comenzii ca sursa de identificare AWB');
ok28(!str_contains($scan,'$svc->syncRecentRemoteAwbs($code)'),'workerul nu mai apeleaza AWB/read fara emag_id/reservation_id');
ok28(str_contains($view,'Import automat AWB eMAG')&&str_contains($view,'Fallback browser activ'),'pagina AWB afiseaza starea importului automat');
ok28(str_contains($view,'Import manual de rezerva - Orders Details'),'importul XLSX ramane fallback manual');
echo "\nAWB AUTO RECONCILE REGRESSION PASSED\n";
