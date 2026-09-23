<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;

function ok53(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "OK: {$message}\n";
}
function naked53(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function private53(object $object,string $method,array $args=[]): mixed {
    $r=new ReflectionMethod($object,$method);$r->setAccessible(true);return $r->invokeArgs($object,$args);
}
function methodBody53(string $src,string $name): string {
    $needle='private function '.$name.'(';$start=strpos($src,$needle);if($start===false)return '';
    $brace=strpos($src,'{',$start);if($brace===false)return '';$depth=0;$len=strlen($src);
    for($i=$brace;$i<$len;$i++){$c=$src[$i];if($c==='{')$depth++;elseif($c==='}'){$depth--;if($depth===0)return substr($src,$start,$i-$start+1);}}
    return '';
}

$root=dirname(__DIR__);
$svc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$layout=(string)file_get_contents($root.'/views/layout.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$css=(string)file_get_contents($root.'/public/assets/app.css');
$obj=naked53(EmagImageSyncService::class);
$seller='https://images.example-shop.ro/catalog/produs-881.jpg';
$offer=['part_number_key'=>'DHTF3H2BM','images'=>[['display_type'=>1,'url'=>$seller]]];

ok53(private53($obj,'isOfficialEmagImageUrl',[$seller])===false,'URL-ul seller nu este confundat cu CDN-ul eMAG');
ok53(private53($obj,'mainImageUrl',[$offer])===$seller,'imaginea seller din oferta API exacta este acceptata ca sursa de cache');
ok53(str_contains(methodBody53($svc,'syncProduct'),'downloadGenericImageToLocalCache'),'syncProduct descarca local imaginea seller returnata de API');
ok53(str_contains($svc,"'[EMAG_API_EXACT]'")&&str_contains(methodBody53($svc,'mediaRowIsVerified'),'isExactApiMediaRow'),'cache-ul seller API are provenienta exacta si verificare separata');
ok53(strpos($layout,'image-modal-kicker">PREVIEW PRODUS</span></div>')!==false,'headerul preview-ului nu mai contine titlul produsului');
ok53(strpos($layout,'<div id="imagePreviewCaption" class="image-modal-caption"></div>')>strpos($layout,'<div class="image-modal-body">'),'titlul produsului apare o singura data in corpul preview-ului');
ok53(str_contains($js,"imageFull.alt = src ? 'Imagine produs' : ''")&&str_contains($js,"imageFull?.addEventListener('error'"),'imaginea defecta nu mai afiseaza titlul ca alt text duplicat');
ok53(str_contains($css,'.image-modal-caption{padding:0 8px 12px!important')&&str_contains($css,'white-space:normal'),'titlul unic ramane responsive');

echo "\nV0.11.53 EMAG API SELLER IMAGE + PREVIEW REGRESSION PASSED\n";
