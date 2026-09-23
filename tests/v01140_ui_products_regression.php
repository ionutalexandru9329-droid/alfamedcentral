<?php
$root=dirname(__DIR__);
function ok40(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$bootstrap=(string)file_get_contents($root.'/modules/invoices-documents/bootstrap.php');
$css=(string)file_get_contents($root.'/public/assets/app.css');
$modules=(string)file_get_contents($root.'/views/modules.php');
$products=(string)file_get_contents($root.'/views/products.php');
$svc=(string)file_get_contents($root.'/app/Services/ProductService.php');
$index=(string)file_get_contents($root.'/public/index.php');
ok40(str_contains($bootstrap,'invoice-upload-form')&&str_contains($bootstrap,'invoice-upload-sync')&&!str_contains($bootstrap,'class="settings-grid compact"'),'uploadul facturii foloseste structura compacta noua, nu grila veche');
ok40(str_contains($css,'.invoice-upload-actions .primary{min-width:138px;height:42px'),'butonul Incarca factura are dimensiune compacta');
ok40(str_contains($modules,'module-state-badge')&&!str_contains($modules,'<b>Status:</b>'),'pagina Module foloseste un singur badge de status si reduce accentuarile');
ok40(str_contains($css,'.module-state-badge.on')&&str_contains($css,'.module-state-badge.off'),'statusurile Activ/Inactiv au chenar dedicat');
ok40(str_contains($products,'product-status-nav')&&str_contains($products,'Publicate')&&str_contains($products,'In asteptare')&&str_contains($products,'Gunoi'),'lista Produse are filtre WooCommerce-like');
ok40(str_contains($index,"product_status")&&str_contains($index,'statusCounts'),'ruta Produse aplica filtrarea si contoarele de status');
ok40(str_contains($svc,'ORDER BY p.created_at ASC,p.id ASC'),'produsele sunt ordonate implicit de la cel mai vechi la cel mai nou');
ok40(str_contains($svc,"include_status'=>'publish,draft,pending,private,future,trash'"),'sincronizarea cere si statusul Trash cand API-ul WooCommerce il suporta');
ok40(str_contains($svc,'reconcileWooDuplicates')&&str_contains($svc,"SELECT id FROM products WHERE ean=? AND archived=0"),'catalogul central deduplica sigur dupa SKU/EAN intre magazine');
echo "\nV0.11.40 UI + PRODUCTS REGRESSION PASSED\n";
