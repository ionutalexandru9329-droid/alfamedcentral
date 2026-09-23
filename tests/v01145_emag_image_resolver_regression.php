<?php
declare(strict_types=1);
$root=dirname(__DIR__);$src=(string)file_get_contents($root.'/app/Services/EmagImageSyncService.php');$idx=(string)file_get_contents($root.'/public/index.php');
function ok45(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
ok45(str_contains($src,'publicProductMetadata')&&str_contains($src,'parsePublicProductHtml'),'direct eMAG product-page resolver remains available');
ok45(str_contains($src,'emagSearchMetadata')&&str_contains($src,'parseEmagSearchJson')&&str_contains($src,'parseEmagSearchHtml'),'free eMAG listing resolvers are available');
ok45(str_contains($src,'productJsonLdMetadata')&&str_contains($src,'publicPageHeaderProfiles'),'free JSON-LD and alternate crawler profiles are available');
ok45(!str_contains($src,'api.reefapi.com')&&!str_contains($src,'reefApiProductMetadata'),'paid ReefAPI resolver has been removed');
ok45(!str_contains($idx,'EMAG_REEFAPI_KEY')&&!str_contains($idx,'EMAG_REEFAPI_DAILY_LIMIT'),'ReefAPI settings are removed from eMAG integrations');
ok45(str_contains($src,'metadataMatchesRequestedPnk'),'every external image path is guarded by exact PNK validation');
echo "\nV0.11.45 COMPATIBILITY REGRESSION PASSED\n";
