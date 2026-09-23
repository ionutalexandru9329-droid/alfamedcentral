<?php
$root=dirname(__DIR__);
function ok41(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$products=(string)file_get_contents($root.'/views/products.php');
$product=(string)file_get_contents($root.'/views/product.php');
$new=(string)file_get_contents($root.'/views/product_new.php');
$rank=(string)file_get_contents($root.'/views/partials/rank_math_editor.php');
$rich=(string)file_get_contents($root.'/views/partials/rich_editor.php');
$svc=(string)file_get_contents($root.'/app/Services/ProductService.php');
$sync=(string)file_get_contents($root.'/app/Services/SyncService.php');
$index=(string)file_get_contents($root.'/public/index.php');
$css=(string)file_get_contents($root.'/public/assets/app.css');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
ok41(strpos($products,'products-bulk-toolbar')<strpos($products,'product-status-panel') && strpos($products,'product-status-panel')<strpos($products,'products-table-panel'),'filtrele de status sunt intre Actiuni produse si lista');
ok41(str_contains($css,'.product-status-panel')&&str_contains($css,'.product-status-nav a.active')&&str_contains($css,'.product-status-nav a:hover'),'butoanele de status au chenar, stare activa si hover vizibil');
ok41(str_contains($sync,'syncProductsQuick')&&str_contains($index,"/api/products/sync")&&str_contains($js,'data-products-sync'),'sincronizarea manuala foloseste flux incremental AJAX');
ok41(str_contains($svc,"rank_math_seo_score")&&str_contains($upgrade,'backfillRankMathSeoScores'),'scorul Rank Math este importat si backfill-uit din cache-ul WooCommerce');
ok41(str_contains($products,'univera_seo_score')&&str_contains($products,'alfamed_seo_score')&&str_contains($product,'product-seo-score-panel'),'scorul SEO este vizibil in lista si in editor');
ok41(str_contains($rank,'data-rank-tab="general"')&&str_contains($rank,'data-rank-tab="advanced"')&&str_contains($rank,'data-rank-tab="social"'),'editorul Rank Math are General, Avansat si Social');
ok41(str_contains($svc,'rank_math_canonical_url')&&str_contains($svc,'rank_math_robots')&&str_contains($svc,'rank_math_facebook_title')&&str_contains($svc,'rank_math_twitter_title'),'campurile Rank Math avansate/sociale sunt trimise catre magazine');
ok41(str_contains($rich,'data-rich-action="source"')&&str_contains($rich,'data-rich-action="fullscreen"')&&str_contains($js,'rich-editor-fullscreen'),'editorul vizual are HTML source si fullscreen');
ok41(str_contains($product,"partials/rank_math_editor.php")&&str_contains($new,"partials/rank_math_editor.php"),'acelasi editor SEO este folosit la creare si editare');
echo "\nV0.11.41 RANK MATH + FAST PRODUCTS REGRESSION PASSED\n";
