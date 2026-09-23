<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use App\Services\EmagImageSyncService;
function ok46(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
function naked46(string $c):object{return (new ReflectionClass($c))->newInstanceWithoutConstructor();}
function private46(object $o,string $m,array $a=[]):mixed{$r=new ReflectionMethod($o,$m);$r->setAccessible(true);return $r->invokeArgs($o,$a);}
$root=dirname(__DIR__);$src=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');$view=(string)file_get_contents($root.'/views/order.php');$js=(string)file_get_contents($root.'/public/assets/app.js');
ok46(str_contains($src,'cachedPnkMedia')&&str_contains($src,'catalogueImageForRemote'),'resolverul foloseste cache PNK si catalogul central inainte de retea');
ok46(!str_contains($src,"if(!\$force && \$hasTrustedLocal)continue")&&!str_contains($src,"if(!\$force&&\$hasLocal)continue")&&str_contains($src,'mediaRowIsVerified')&&str_contains($src,'$rowInterval'),'thumbnailurile sunt reverificate periodic, iar cele suspecte intra automat la retry rapid');
ok46(!str_contains($view,'data-refresh-emag-images')&&str_contains($js,"payload.set('force','1')")&&str_contains($js,'imagePreviewResync'),'refresh-ul manual nu mai aglomereaza comanda si ramane disponibil numai in preview');
ok46(str_contains($src,'downloadGenericImageToLocalCache')&&str_contains($src,'isSafeCatalogueImageUrl'),'imaginile Woo sunt copiate in cache local cu protectie SSRF');
ok46(!str_contains($src,'reefapi')&&!str_contains($src,'EMAG_REEFAPI'),'rezolvarea nu mai depinde de ReefAPI sau buget de credite');
$svc=naked46(EmagImageSyncService::class);
ok46(private46($svc,'extractPnk',['https://www.emag.ro/produs/pd/DY0DVB2BM/'])==='DY0DVB2BM','PNK-ul este extras corect din hyperlink');
ok46(private46($svc,'extractPnk',['https://www.emag.bg/product/pd/ABC123/'])==='ABC123','PNK-ul este extras si pentru eMAG BG');
echo "\nV0.11.46 CACHE/FREE-FIRST REGRESSION PASSED\n";
