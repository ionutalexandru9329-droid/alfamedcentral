<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;
function ok47(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
function naked47(string $c):object{return (new ReflectionClass($c))->newInstanceWithoutConstructor();}
function private47(object $o,string $m,array $a=[]):mixed{$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return $r->invokeArgs($o,$a);}
$root=dirname(__DIR__);$src=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');$idx=(string)file_get_contents($root.'/public/index.php');$env=(string)file_get_contents($root.'/.env.example');
ok47(str_contains($src,"'/search/'")&&str_contains($src,'emagSearchPageUrls'),'resolverul incearca listing/search public eMAG fara serviciu platit');
ok47(str_contains($src,"['ro','bg','hu']")||str_contains($src,"['bg','ro','hu']"),'resolverul are fallback best-effort intre storefront-urile eMAG RO/BG/HU');
ok47(str_contains($src,'facebookexternalhit/1.1')&&str_contains($src,'Twitterbot/1.0'),'resolverul are profile de crawler social ca fallback gratuit pentru WAF');
ok47(str_contains($src,'productJsonLdMetadata'),'pagina produsului foloseste si JSON-LD Product.image ca sursa gratuita');
ok47(!str_contains($src,'api.reefapi.com')&&!str_contains($idx,'EMAG_REEFAPI')&&!str_contains($env,'EMAG_REEFAPI'),'ReefAPI este eliminat din cod, UI si exemplul de configurare');
ok47(!str_contains($src,'sapi.emag.')&&!str_contains($src,'search-by-url'),'nu se bazeaza pe endpointuri interne eMAG cu forma neverificata');

$svc=naked47(EmagImageSyncService::class);
$url='https://www.emag.ro/produs/pd/DY0DVB2BM/';
$json=[
    'data'=>['items'=>[
        ['id'=>1,'pnk'=>'WRONG123','url'=>'https://www.emag.ro/alt/pd/WRONG123/','image'=>['url'=>'https://s13emagst.akamaized.net/products/1/1/images/res_wrong.jpg']],
        ['id'=>2,'pnk'=>'DY0DVB2BM','url'=>'https://www.emag.ro/fasa-test/pd/DY0DVB2BM/','image'=>['url'=>'https://s13emagst.akamaized.net/products/115059/115058920/images/res_good.png?width=720&height=720']]
    ]]
];
$meta=private47($svc,'parseEmagSearchJson',[$json,$url]);
ok47(($meta['product_code']??'')==='DY0DVB2BM','JSON parser selects the exact requested PNK');
ok47(str_contains((string)($meta['image_url']??''),'res_good.png'),'JSON parser selects the matching product image, not a neighboring card');
ok47(private47($svc,'metadataMatchesRequestedPnk',[$meta,$url])===true,'matched JSON metadata passes strict PNK validation');
$bad=$meta;$bad['product_code']='OTHERPNK';
ok47(private47($svc,'metadataMatchesRequestedPnk',[$bad,$url])===false,'mismatched PNK is rejected');

$html='<!doctype html><html><body><article class="card"><a href="https://www.emag.ro/fasa-test/pd/DY0DVB2BM/"><img data-src="https://s13emagst.akamaized.net/products/115059/115058920/images/res_html.png?width=720&height=720"></a></article></body></html>';
$htmlMeta=private47($svc,'parseEmagSearchHtml',[$html,'https://www.emag.ro/search/DY0DVB2BM',$url]);
ok47(str_contains((string)($htmlMeta['image_url']??''),'res_html.png'),'HTML listing parser extracts the image from the exact PNK card');
ok47(private47($svc,'metadataMatchesRequestedPnk',[$htmlMeta,$url])===true,'HTML listing result also passes exact PNK validation');

$embedded='<html><script>window.EM={"listingGlobals":{"items":[{"pnk":"DY0DVB2BM","url":"https:\/\/www.emag.ro\/fasa\/pd\/DY0DVB2BM\/","image":{"url":"https:\/\/s13emagst.akamaized.net\/products\/115059\/115058920\/images\/res_embedded.png"}}]}};</script></html>';
$embeddedMeta=private47($svc,'parseEmbeddedListingJson',[$embedded,$url]);
ok47(str_contains((string)($embeddedMeta['image_url']??''),'res_embedded.png'),'embedded listing JSON is parsed when cards are lazy-rendered');

$jsonLd='<html><head><script type="application/ld+json">'.json_encode(['@context'=>'https://schema.org','@type'=>'Product','url'=>$url,'image'=>['https://s13emagst.akamaized.net/products/115059/115058920/images/res_ld.png']]).'</script></head></html>';
$ldMeta=private47($svc,'parsePublicProductHtml',[$jsonLd,$url]);
ok47(str_contains((string)($ldMeta['image_url']??''),'res_ld.png'),'JSON-LD Product.image is extracted when OpenGraph is absent');

$roUrls=private47($svc,'emagSearchPageUrls',['ro','DY0DVB2BM']);
ok47(is_array($roUrls)&&count($roUrls)>=2&&str_contains((string)$roUrls[0],'www.emag.ro/search/DY0DVB2BM'),'RO public search URL variants are generated');
$bgUrls=private47($svc,'emagSearchPageUrls',['bg','DY0DVB2BM']);
ok47(str_contains((string)$bgUrls[0],'www.emag.bg/search/DY0DVB2BM'),'BG public search URL variants are generated');
$profiles=private47($svc,'publicPageHeaderProfiles',['https://www.emag.ro/']);
ok47(count($profiles)>=3,'multiple free browser/crawler header profiles are available');
echo "\nV0.11.47 FREE EMAG SEARCH RESOLVER REGRESSION PASSED\n";
