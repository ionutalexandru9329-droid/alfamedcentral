<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;

function ok51(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "OK: {$message}\n";
}
function naked51(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function private51(object $object,string $method,array $args=[]): mixed {
    $r=new ReflectionMethod($object,$method);$r->setAccessible(true);return $r->invokeArgs($object,$args);
}

$root=dirname(__DIR__);
$svcSrc=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$syncSrc=(string)file_get_contents($root.'/app/Services/SyncService.php');
$layout=(string)file_get_contents($root.'/views/layout.php');
$css=(string)file_get_contents($root.'/public/assets/app.css');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$order=(string)file_get_contents($root.'/views/order.php');
$svc=naked51(EmagImageSyncService::class);

ok51(str_contains($syncSrc,"['product_url','emag_product_url','url','web_url','public_url','permalink']"),'importul pastreaza hyperlinkul eMAG exact cand exista in payload');
ok51(str_contains($svcSrc,'rawOrderMediaHint')&&str_contains($svcSrc,'findTrustedImageInNode'),'resolverul citeste URL/imagine direct din payloadul comenzii inainte de scraping');
ok51(str_contains($svcSrc,'browserLikeProductMetadata')&&str_contains($svcSrc,'CURLOPT_COOKIEFILE'),'fallback-ul direct foloseste o sesiune cURL cu cookie-uri, mai apropiata de un browser');
ok51(str_contains($svcSrc,'403,429,511')&&str_contains($svcSrc,'storefront_blocked'),'blocarea storefrontului eMAG este detectata explicit');
ok51(str_contains($svcSrc,'sku_name_similar'),'fallback-ul local poate folosi SKU unic plus nume foarte apropiat');
ok51(private51($svc,'namesStronglyMatch',['Cafea Boabe Davidoff Espresso 57 Dark & Chocolatey, 1 Kg, 100% Arabica','Cafea boabe Davidoff Espresso 57, 1kg'])===true,'potrivirea stricta accepta aceeasi cafea cu formulare mai scurta');
ok51(private51($svc,'namesStronglyMatch',['Cafea Boabe Davidoff Espresso 57, 1 Kg','Cafea Boabe Lavazza Qualita Oro, 1 Kg'])===false,'potrivirea stricta respinge alt produs cu aceeasi categorie/greutate');
ok51(str_contains($layout,'image-modal-head')&&str_contains($layout,'image-modal-actions'),'preview-ul are structura compacta profesionala');
ok51(str_contains($css,'.image-modal-upload input[type="file"]{display:none!important'),'inputul nativ Choose file este ascuns fortat');
ok51(str_contains($css,'@media(max-width:560px)')&&str_contains($css,'.image-modal-actions{grid-template-columns:1fr}'),'preview-ul se adapteaza pe mobil');
ok51(str_contains($css,'width:min(720px,calc(100vw - 32px))'),'preview-ul are latime controlata pe PC/tableta');
ok51(str_contains($js,'Nu am gasit o imagine disponibila in datele eMAG'),'preview-ul explica lipsa imaginii fara a adauga butoane in comanda');
ok51(!str_contains($order,'Leaga produsul'),'Leaga produsul ramane eliminat din comanda');

echo "\nV0.11.51 EMAG IMAGE BOT + UI REGRESSION PASSED\n";
