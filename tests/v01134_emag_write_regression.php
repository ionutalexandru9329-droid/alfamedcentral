<?php
declare(strict_types=1);
function ok34(bool $cond,string $message):void{if(!$cond)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/app/Integrations/EmagClient.php');
$invoice=(string)file_get_contents($root.'/modules/invoices-documents/src/InvoiceService.php');
$fulfillment=(string)file_get_contents($root.'/modules/couriers/src/EmagFulfillmentService.php');

ok34(str_contains($client,"postJson('awb','save',['data'=>[\$data]],60)"),'awb/save foloseste JSON data[] conform API 4.5.1');
ok34(str_contains($client,"return \$this->postJson('awb','read',\$payload,\$timeout)"),'awb/read foloseste JSON top-level emag_id/reservation_id');
ok34(str_contains($client,"\$read=\$this->readAwbs(\$filters,20)")&&str_contains($client,"\$read['_save_response']=\$saved"),'awb/save poate recupera numarul prin awb/read fara al doilea save');
ok34(str_contains($client,"postJson('order/attachments','save',['data'=>[\$attachment]],30)"),'atasamentul eMAG este trimis ca JSON data[]');
ok34(str_contains($client,"'order_type'=>\$orderType")&&str_contains($invoice,"\$order['raw']['type']??3"),'factura transmite order_type 2/3 catre eMAG');
ok34(str_contains($invoice,'sincronizarea catre canal a fost reluata fara emiterea unei facturi noi'),'bulk factura reia sincronizarea unei facturi deja emise fara dublura');
ok34(!str_contains($client,'postMultipart('),'multipart-ul experimental pentru atasamente a fost eliminat');
ok34(str_contains($fulfillment,'Verifica Marketplace inainte sa reincerci'),'protectia anti-dublura AWB ramane activa daca save a fost acceptat dar AWB-ul nu poate fi citit');

echo "\nV0.11.34 EMAG WRITE REGRESSION PASSED\n";
