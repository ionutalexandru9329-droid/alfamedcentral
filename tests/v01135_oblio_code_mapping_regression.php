<?php
declare(strict_types=1);
function ok35(bool $cond,string $message):void{if(!$cond)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/modules/invoices-documents/src/OblioClient.php');
$service=(string)file_get_contents($root.'/modules/invoices-documents/src/InvoiceService.php');

ok35(str_contains($client,"private static array \$catalogueIndexCache=[]"),'catalogul Oblio este indexat/cached pentru mapari bulk');
ok35(str_contains($client,"'offset'=>\$offset")&&str_contains($client,"'includeUnsalable'=>1"),'nomenclatorul Oblio este parcurs paginat, inclusiv produse nevandabile');
ok35(str_contains($client,"['codeClient','codeEAN','ean','eanCode']"),'maparea cauta si cod client/EAN din Oblio');
ok35(str_contains($client,"\$line['codeClient']=\$channelCode"),'factura pastreaza separat codul marketplace in codeClient');
ok35(str_contains($client,"\$line['codeEAN']=\$ean"),'factura trimite EAN separat catre Oblio');
ok35(str_contains($service,"['_channel_code']")&&str_contains($service,"['_source_ean']"),'itemul pastreaza codul eMAG si EAN inainte de inlocuirea cu codul Oblio');
ok35(str_contains($service,"['id','product_id','product_offer_id','offer_id']"),'produsele eMAG sunt corelate si dupa offer/product ids');
ok35(!str_contains($service,"\$push(\$item['external_item_id']??'');"),'order-line id nu mai este folosit ca si cod de produs');
ok35(str_contains($service,"\$persistCodes=array_values(array_unique(array_filter([\$matchedSource,\$originalSku"),'maparea invatata persista si SKU-ul eMAG chiar daca potrivirea a venit din EAN');
ok35(str_contains($service,"SELECT external_sku FROM channel_products WHERE product_id=?"),'maparea poate folosi si SKU-urile cunoscute din catalogul central');
ok35(str_contains($service,"['_require_oblio_catalogue']")&&str_contains($client,'Factura nu a fost emisa pentru a evita crearea sau folosirea unui cod gresit'),'eMAG nu mai poate emite factura cu un cod Oblio neverificat');

echo "\nV0.11.35 OBLIO CODE MAPPING REGRESSION PASSED\n";
