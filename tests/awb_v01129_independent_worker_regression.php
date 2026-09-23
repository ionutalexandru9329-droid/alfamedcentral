<?php
declare(strict_types=1);
function ok29(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$root=dirname(__DIR__);
$auto=(string)file_get_contents($root.'/app/Services/AutoSyncService.php');
$mod=(string)file_get_contents($root.'/modules/awb-scan/bootstrap.php');
$view=(string)file_get_contents($root.'/views/shipments.php');
$bgPos=strpos($auto,'runEmagBackgroundModules($force)');
$lockPos=strpos($auto,'fopen($this->lockFile');
ok29($bgPos!==false && $lockPos!==false && $bgPos<$lockPos,'workerul AWB este lansat inaintea lock-ului principal auto-sync');
ok29(str_contains($auto,'awb-background.lock'),'workerul AWB are lock separat');
ok29(str_contains($auto,'if(($ch[\'type\']??\'\')!==\'emag\')'),'eMAG nu dubleaza workerul dupa order-sync');
ok29(str_contains($mod,"runtime.awb_sync.attempt."),'workerul salveaza ultima incercare');
ok29(str_contains($mod,"runtime.awb_sync.last_error."),'workerul salveaza eroarea AWB');
ok29(str_contains($view,'Ultima incercare AWB') && str_contains($view,'Ultima executie fara eroare') && str_contains($view,'Eroare AWB'),'pagina AWB expune diagnosticul workerului');
echo "v0.11.29 independent AWB worker regression passed.\n";
