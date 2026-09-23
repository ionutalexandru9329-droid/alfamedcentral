<?php
declare(strict_types=1);
function ok31(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/app/Integrations/EmagClient.php');
$svc=(string)file_get_contents($root.'/modules/couriers/src/EmagFulfillmentService.php');
$worker=(string)file_get_contents($root.'/modules/awb-scan/bootstrap.php');
$view=(string)file_get_contents($root.'/views/shipments.php');
ok31(str_contains($client,"'/order/attachments/read'"),'endpoint-ul attachments este apelat direct');
ok31(str_contains($client,"Content-Type: application/json"),'attachments foloseste Content-Type JSON');
ok31(str_contains($client,"json_encode(['order_id'=>\$orderId,'order_type'=>\$orderType]"),'order_id/order_type sunt top-level JSON');
$attachmentsPos=strpos($svc,'readOrderAttachments((int)$external,$orderType,$callTimeout)');
$orderReadPos=strpos($svc,'$client->readOrderById($external');
ok31($attachmentsPos!==false&&$orderReadPos!==false&&$attachmentsPos<$orderReadPos,'attachments ruleaza inainte de order/read');
ok31(str_contains($svc,"!=='3'"),'cursorul istoric este resetat pentru noua strategie');
ok31(str_contains($worker,'runtime.awb_sync.last_stats.'),'workerul salveaza statistici pe ciclu');
ok31(str_contains($view,'AWB importate'),'UI arata cate AWB-uri au fost importate in ultimul ciclu');
echo "v0.11.31 JSON attachments regression passed.\n";
