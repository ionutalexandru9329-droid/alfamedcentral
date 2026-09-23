<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$view=(string)file_get_contents($root.'/views/order.php');
$layout=(string)file_get_contents($root.'/views/layout.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$index=(string)file_get_contents($root.'/public/index.php');
$svc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
function ok50(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
ok50(!str_contains($view,'Leaga produsul')&&!str_contains($view,'data-emag-product-link-modal'),'Leaga produsul nu mai apare in comanda');
ok50(!str_contains($view,'data-refresh-emag-images')&&!str_contains($view,'Actualizeaza imaginile'),'butonul de refresh imagine nu mai apare in tabelul comenzii');
ok50(str_contains($view,'order-image-placeholder-trigger')&&str_contains($view,'data-order-image-tools'),'thumbnailul lipsa deschide preview-ul fara buton separat');
ok50(str_contains($layout,'imagePreviewResync')&&str_contains($layout,'imagePreviewUpload'),'resincronizarea si uploadul manual sunt numai in preview');
ok50(str_contains($js,'setTimeout(() => syncEmagOrderMedia(openedOrderRoot.dataset.orderOpenProcessing'),'resincronizarea automata la deschiderea comenzii ramane activa');
ok50(str_contains($index,'/manual-image')&&str_contains($svc,'setManualOrderItemImage'),'uploadul manual din preview are endpoint dedicat');
ok50(str_contains($svc,'[MANUAL_IMAGE')&&str_contains($svc,"'source'=>'manual'"),'poza manuala este marcata si are prioritate fata de auto-sync');
ok50(!str_contains($js,'data-emag-link-product'),'codul UI pentru Leaga produsul a fost eliminat');
echo "\nV0.11.50 ORDER IMAGE PREVIEW REGRESSION PASSED\n";
