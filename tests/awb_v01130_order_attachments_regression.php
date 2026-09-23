<?php
declare(strict_types=1);
function ok30(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/app/Integrations/EmagClient.php');
$svc=(string)file_get_contents($root.'/modules/couriers/src/EmagFulfillmentService.php');
$worker=(string)file_get_contents($root.'/modules/awb-scan/bootstrap.php');
$view=(string)file_get_contents($root.'/views/shipments.php');
ok30(str_contains($client,'function readOrderAttachments'),'clientul are order/attachments/read');
ok30(str_contains($client,"'/order/attachments/read'") && str_contains($client,"Content-Type: application/json") && str_contains($client,"['order_id'=>\$orderId,'order_type'=>\$orderType]"),'cererea attachments trimite JSON top-level cu order_id si order_type');
ok30(str_contains($svc,'readOrderAttachments((int)$external,$orderType,$callTimeout)'),'refreshOrderAwb citeste atasamentele comenzii');
ok30(str_contains($svc,'foreach([3,2] as $t)'),'refreshOrderAwb acopera seller fulfilled si fulfilled by eMAG');
ok30(str_contains($svc,'awbLookupFiltersFromOrderPayload($attachments)'),'identificatorul intern AWB este extras din atasamentul tip 10');
ok30(str_contains($svc,"'source'=>'order_attachment_awb'"),'AWB-ul gasit prin atasament este marcat explicit');
ok30(!str_contains($worker,'$svc->syncRecentRemoteAwbs($code)'),'workerul nu mai face AWB/read nefiltrat');
ok30(!str_contains($worker,'$svc->syncRemoteAwbPage($code,$page,100)'),'workerul nu mai parcurge pagini AWB/read fara identificator');
ok30(str_contains($view,'order/attachments (JSON, AWB tip 10)'),'UI afiseaza noua metoda automata');
echo "v0.11.30 order attachments regression passed.\n";
