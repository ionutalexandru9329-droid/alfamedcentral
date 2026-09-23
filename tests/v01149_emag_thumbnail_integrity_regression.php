<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;

function ok49(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    echo "OK: {$message}\n";
}
function naked49(string $class): object { return (new ReflectionClass($class))->newInstanceWithoutConstructor(); }
function private49(object $object,string $method,array $args=[]): mixed {
    $r=new ReflectionMethod($object,$method);$r->setAccessible(true);return $r->invokeArgs($object,$args);
}

$root=dirname(__DIR__);
$src=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');
$sync=(string)file_get_contents($root.'/app/Services/SyncService.php');
$order=(string)file_get_contents($root.'/app/Services/OrderOperations.php');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$svc=naked49(EmagImageSyncService::class);

$requested='https://www.emag.ro/produs/pd/RIGHTPNK/';
$goodImage='https://s13emagst.akamaized.net/products/10/20/images/right.jpg';
$wrongImage='https://s13emagst.akamaized.net/products/90/91/images/wrong.jpg';

ok49(private49($svc,'isOfficialEmagImageUrl',[$goodImage])===true,'accepta numai asset-ul de produs de pe CDN-ul eMAG');
ok49(private49($svc,'isOfficialEmagImageUrl',['https://www.emag.ro/produs/pd/RIGHTPNK/'])===false,'pagina produsului nu poate fi confundata cu o imagine');

$wrongHtml='<html><head><link rel="canonical" href="https://www.emag.ro/alt/pd/WRONGPNK/"><meta property="og:image" content="'.$wrongImage.'"></head></html>';
$wrongMeta=private49($svc,'parsePublicProductHtml',[$wrongHtml,$requested]);
ok49(private49($svc,'metadataMatchesRequestedPnk',[$wrongMeta,$requested])===false,'redirectul/canonicalul catre alt PNK este respins chiar daca are imagine eMAG valida');

$genericHtml='<html><head><meta property="og:image" content="'.$wrongImage.'"></head><body>generic WAF page</body></html>';
$genericMeta=private49($svc,'parsePublicProductHtml',[$genericHtml,$requested]);
ok49(($genericMeta['product_url']??'')===''&&private49($svc,'metadataMatchesRequestedPnk',[$genericMeta,$requested])===false,'o pagina generica fara identitate proprie nu mosteneste PNK-ul cerut');

$goodHtml='<html><head><link rel="canonical" href="'.$requested.'"><meta property="og:image" content="'.$goodImage.'"></head></html>';
$goodMeta=private49($svc,'parsePublicProductHtml',[$goodHtml,$requested]);
ok49(private49($svc,'metadataMatchesRequestedPnk',[$goodMeta,$requested])===true,'imaginea este acceptata cand canonicalul dovedeste exact acelasi PNK');

$unstructured='<html><head><link rel="canonical" href="'.$requested.'"></head><body><img src="'.$wrongImage.'"></body></html>';
$unstructuredMeta=private49($svc,'parsePublicProductHtml',[$unstructured,$requested]);
ok49(($unstructuredMeta['image_url']??'')==='', 'pagina de produs fara OG/JSON-LD nu imprumuta o imagine arbitrara din continut');

$offerWithoutIdentity=['images'=>[['display_type'=>1,'url'=>$wrongImage]]];
ok49(private49($svc,'offerMatchesHint',[$offerWithoutIdentity,['pnk'=>'RIGHTPNK']])===false,'un raspuns API fara PNK nu poate furniza imagine pentru un PNK cunoscut');
$offerExact=['part_number_key'=>'RIGHTPNK','images'=>[['display_type'=>1,'url'=>$goodImage]]];
ok49(private49($svc,'offerMatchesHint',[$offerExact,['pnk'=>'RIGHTPNK']])===true,'raspunsul API cu PNK identic este acceptat');
ok49(private49($svc,'mainImageUrl',[$offerExact])===$goodImage,'resolverul API alege doar URL-ul de imagine de produs eMAG');

ok49(str_contains($src,'return $requestCache[$cacheKey]=[];'),'fallback-ul public nu mai returneaza metadata nevalidata dupa un redirect gresit');
ok49(str_contains($src,'$seenPnks=$pnksInNode($node)')&&str_contains($src,'$seenPnks!==[$wanted]'),'parserul DOM opreste cautarea inaintea unui container care contine produse vecine');
ok49(str_contains($src,'$rowInterval=$needsRepair?min($interval,300):$interval')&&str_contains($src,'$rowInterval=(!$verified||!$hasDirect)?min($interval,300):$interval'),'thumbnailurile suspecte sunt reverificate automat la maximum 5 minute');
ok49(!str_contains($src,"if(!\$force && \$hasTrustedLocal)continue")&&!str_contains($src,"if(!\$force&&\$hasLocal)continue"),'cache-ul local nu mai blocheaza pentru totdeauna resincronizarea');
ok49(str_contains($src,"['ean','sku_ean','sku_name']")&&str_contains($src,"mapping_source']??'')==='manual'"),'fallback-ul din catalog este permis numai pentru mapari puternice sau manuale');
ok49(str_contains($sync,'verifiedEmagMedia')&&str_contains($order,'verifiedEmagMedia'),'atat importul comenzilor cat si afisarea refuza media neverificata');
ok49(str_contains($upgrade,'migration.emag_image_trust_v01149')&&str_contains($upgrade,"DELETE FROM emag_product_media")&&str_contains($upgrade,"mapping_source IS NULL OR mapping_source<>'manual'"),'upgrade-ul sterge o singura data cache-ul vechi si maparile automate, pastrand maparile manuale');
ok49(str_contains($js,'syncEmagOrderMedia(id, body)')&&str_contains($js,'setTimeout(() => syncEmagOrderMedia'),'deschiderea comenzii declanseaza automat repararea thumbnailurilor');

echo "\nV0.11.49 EMAG THUMBNAIL INTEGRITY REGRESSION PASSED\n";
