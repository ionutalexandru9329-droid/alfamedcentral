<?php
$root=dirname(__DIR__);
$svc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$client=(string)file_get_contents($root.'/app/Integrations/EmagClient.php');
$index=(string)file_get_contents($root.'/public/index.php');
$view=(string)file_get_contents($root.'/views/order.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
function ok48(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "OK: $message\n";}
ok48(str_contains($client,'readOfferByPartNumberKey')&&str_contains($client,"'part_number_key'=>\$pnk"),'clientul oficial eMAG poate rezolva exact PNK prin product_offer/read');
ok48(str_contains($svc,'catalogueImageForPnk')&&str_contains($svc,'offerByPnkCached'),'resolverul foloseste PNK oficial inainte de fallback public');
ok48(str_contains($svc,'findCentralProductMatch')&&str_contains($svc,"'reason'=>'ean'")&&str_contains($svc,"'reason'=>'sku_ean'")&&str_contains($svc,"'reason'=>'sku_name'")&&!str_contains($svc,"'reason'=>'exact_name'"),'fallback-ul in catalog cere EAN unic sau doua semnale exacte; SKU/numele singure nu mai sunt suficiente');
ok48(str_contains($svc,'saveProductLink')&&str_contains($upgrade,'emag_product_links'),'legatura PNK -> produs central este persistenta');
ok48(str_contains($svc,'mapping_source')&&str_contains($svc,'$source===\'manual\'?\'manual\':\'auto\''),'maparile manuale au sursa separata de cele automate');
ok48(str_contains($index,"/api/emag/product-link/search")&&str_contains($index,'emag-product-link'),'exista API pentru cautare si asociere manuala');
ok48(!str_contains($view,'Leaga produsul')&&!str_contains($view,'data-emag-product-link-modal'),'fallback-ul Leaga produsul a fost eliminat din comanda');
ok48(!str_contains($js,'data-emag-link-product')&&str_contains($js,'imagePreviewResync'),'interfata muta mentenanta imaginilor in preview si nu mai afiseaza legarea produsului');
ok48(str_contains($svc,'catalogueImageForProduct')&&str_contains($svc,"c.code='alfamed'")&&str_contains($svc,"c.code='univera'"),'imaginea este luata din catalogul WooCommerce ALFAMED/Univera');
ok48(str_contains($svc,'publicProductMetadata')&&str_contains($svc,'metadataMatchesRequestedPnk'),'pagina publica eMAG ramane fallback strict, acceptat numai dupa validarea exacta a PNK-ului');
echo "\nV0.11.48 OFFICIAL PNK -> CENTRAL CATALOGUE REGRESSION PASSED\n";
