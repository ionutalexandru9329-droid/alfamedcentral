<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;

function ok54(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "OK: {$message}\n";
}
function methodBody54(string $src,string $name): string {
    $needle='private function '.$name.'(';$start=strpos($src,$needle);if($start===false)return '';
    $brace=strpos($src,'{',$start);if($brace===false)return '';$depth=0;$len=strlen($src);
    for($i=$brace;$i<$len;$i++){$c=$src[$i];if($c==='{')$depth++;elseif($c==='}'){$depth--;if($depth===0)return substr($src,$start,$i-$start+1);}}
    return '';
}
$root=dirname(__DIR__);
$svc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$ops=(string)file_get_contents($root.'/app/Services/OrderOperations.php');
$sync=(string)file_get_contents($root.'/app/Services/SyncService.php');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
$exact=methodBody54($svc,'catalogueImageForExactSkuName');
$syncProduct=methodBody54($svc,'syncProduct');
$hydrate=methodBody54($svc,'hydrateKnownProductLinks');
$safeLink=methodBody54($svc,'isSafeCatalogueLink');

ok54($exact!==''&&str_contains($exact,'UPPER(p.sku)=UPPER(?)')&&str_contains($exact,'UPPER(cp.external_sku)=UPPER(?)'),'fallback-ul cere SKU/external SKU exact');
ok54(str_contains($exact,'hash_equals($wanted,$this->normalizeProductName($candidate))')&&!str_contains($exact,'namesStronglyMatch'),'fallback-ul cere denumire exacta dupa normalizare, fara fuzzy matching');
ok54(str_contains($syncProduct,'catalogueImageForExactSkuName')&&str_contains($syncProduct,"'[CATALOG_EXACT]'"),'syncProduct foloseste catalogul local doar ca fallback marcat exact');
ok54(str_contains($hydrate,'catalogueImageForExactSkuName')&&str_contains($hydrate,"'[CATALOG_EXACT]'"),'hidratarea PNK poate completa thumbnailul cu aceeasi regula stricta');
ok54(!str_contains($safeLink,'sku_name_similar'),'maparile fuzzy nu sunt considerate sigure pentru thumbnail');
ok54(str_contains(methodBody54($ops,'verifiedEmagMedia'),'[CATALOG_EXACT]')&&str_contains(methodBody54($sync,'verifiedEmagMedia'),'[CATALOG_EXACT]'),'afisarea thumbnailului local cere provenienta CATALOG_EXACT');
ok54(str_contains($upgrade,'migration.emag_catalog_exact_v01154')&&str_contains($upgrade,"match_reason,'')='sku_name_similar'"),'upgrade-ul elimina legaturile fuzzy si reprogrameaza imaginile lipsa');

$obj=(new ReflectionClass(EmagImageSyncService::class))->newInstanceWithoutConstructor();
$m=new ReflectionMethod($obj,'normalizeProductName');$m->setAccessible(true);
$a=$m->invoke($obj,'Pachet x 2 Solutie pentru scos pete Nufar, 500ml');
$b=$m->invoke($obj,'Pachet x 2 Solutie pentru scos pete Nufar 500ml');
ok54($a===$b,'normalizarea ignora doar punctuatia/spatierea, nu identitatea produsului');

echo "\nV0.11.54 EMAG EXACT CATALOGUE FALLBACK REGRESSION PASSED\n";
