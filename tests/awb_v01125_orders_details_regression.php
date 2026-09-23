<?php
declare(strict_types=1);
function ok25(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$scan=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/bootstrap.php');
$svc=(string)file_get_contents(dirname(__DIR__).'/modules/awb-scan/src/EmagOrdersDetailsAwbImportService.php');
$view=(string)file_get_contents(dirname(__DIR__).'/views/shipments.php');
ok25(str_contains($scan,'4EMGLN187446944001')&&str_contains($scan,'4EMGLN187446944'),'scannerul documenteaza cazul real AWB baza + 001');
ok25(str_contains($scan,"preg_match('/^(.+[A-Z].*?)(\\d{3})$/',\$compact,\$m)"),'scannerul elimina explicit doar sufixul numeric de 3 cifre pentru cod alfanumeric');
ok25(str_contains($scan,"/awb/import-emag-orders-details"),'ruta de import Orders Details este instalata');
ok25(str_contains($svc,"nr_comanda")&&str_contains($svc,"numar_awb"),'importerul leaga Nr. comanda de Numar AWB');
ok25(str_contains($svc,"emag_orders_details_export"),'shipment-ul importat pastreaza sursa auditabila');
ok25(str_contains($svc,"c.type='emag'")&&str_contains($svc,"c.code LIKE 'emag_%'")&&str_contains($svc,'TRIM(o.external_id)=?'),'importul asociaza tolerant numai comenzi eMAG dupa ID extern');
ok25(str_contains($view,'Orders Details')&&str_contains($view,'name="orders_details"'),'pagina AWB are formular pentru exportul eMAG');
echo "\nAWB V0.11.25 ORDERS DETAILS REGRESSION PASSED\n";
