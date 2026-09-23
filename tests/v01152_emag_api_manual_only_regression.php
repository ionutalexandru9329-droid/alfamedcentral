<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

function ok52(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "OK: {$message}\n";
}
function methodBody52(string $src,string $name): string {
    $needle='private function '.$name.'(';$start=strpos($src,$needle);if($start===false)return '';
    $brace=strpos($src,'{',$start);if($brace===false)return '';$depth=0;$len=strlen($src);
    for($i=$brace;$i<$len;$i++){$c=$src[$i];if($c==='{')$depth++;elseif($c==='}'){$depth--;if($depth===0)return substr($src,$start,$i-$start+1);}}
    return '';
}
$root=dirname(__DIR__);
$svc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$ops=(string)file_get_contents($root.'/app/Services/OrderOperations.php');
$sync=(string)file_get_contents($root.'/app/Services/SyncService.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$css=(string)file_get_contents($root.'/public/assets/app.css');
$layout=(string)file_get_contents($root.'/views/layout.php');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
$syncProduct=methodBody52($svc,'syncProduct');
$hydrate=methodBody52($svc,'hydrateKnownProductLinks');
$verified=methodBody52($svc,'mediaRowIsVerified');

ok52($syncProduct!==''&&str_contains($syncProduct,'mainImageUrl($offer)'),'sincronizarea produsului foloseste imaginea returnata de oferta eMAG');
ok52(!str_contains($syncProduct,'publicProductMetadata')&&!str_contains($syncProduct,'catalogueImageForPnk')&&!str_contains($syncProduct,'catalogueImageForRemote')&&str_contains($syncProduct,'catalogueImageForExactSkuName'),'syncProduct nu face scraping public; catalogul este permis doar prin SKU + denumire exacta');
ok52(!str_contains($hydrate,'publicProductMetadata')&&!str_contains($hydrate,'catalogueImageForPnk')&&!str_contains($hydrate,'catalogueImageForItem')&&str_contains($hydrate,'catalogueImageForExactSkuName'),'hidratarea PNK foloseste API/cache sau fallback local strict SKU + nume');
ok52(str_contains($verified,'isExactCatalogueMediaRow')&&str_contains($verified,'isSafeCataloguePnk'),'cache-ul local de catalog este verificat numai cu provenienta exacta si PNK legat sigur');
ok52(str_contains(methodBody52($ops,'verifiedEmagMedia'),'[CATALOG_EXACT]')&&str_contains(methodBody52($ops,'verifiedEmagMedia'),'safeCatalogueLinkExists'),'OrderOperations afiseaza doar fallbackul local marcat si legat strict');
ok52(str_contains(methodBody52($sync,'verifiedEmagMedia'),'[CATALOG_EXACT]')&&str_contains(methodBody52($sync,'verifiedEmagMedia'),'safeCatalogueLinkExists'),'SyncService pastreaza doar fallbackul local marcat si legat strict');
ok52(str_contains($svc,'[MANUAL_IMAGE')&&str_contains($svc,'setManualOrderItemImage'),'uploadul manual ramane protejat si are prioritate');
ok52(str_contains($js,'uploadManualOrderImage')&&str_contains($js,"imageDropzone?.addEventListener('drop'"),'preview-ul accepta drag & drop pentru poza manuala');
ok52(str_contains($css,'.image-modal-media.is-dragging'),'drag & drop are feedback vizual discret');
ok52(str_contains($layout,'Daca imaginea lipseste, o poti incarca manual.'),'preview-ul explica fluxul simplu API sau manual');
ok52(str_contains($upgrade,'migration.emag_api_manual_only_v01152'),'upgrade-ul elimina o singura data fallbackurile automate vechi');
ok52(!str_contains($js,'eMAG blocheaza momentan citirea automata a paginii'),'UI nu mai expune diagnostice de bot/WAF');

echo "\nV0.11.52 EMAG API + MANUAL ONLY REGRESSION PASSED\n";
