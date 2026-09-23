<?php
$root=dirname(__DIR__);
function ok42(bool $c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "OK: $m\n";}
$scan=(string)file_get_contents($root.'/views/scan.php');
$js=(string)file_get_contents($root.'/public/assets/app.js');
$css=(string)file_get_contents($root.'/public/assets/app.css');
ok42(!str_contains($scan,'id="scanInput" autofocus'),'campul AWB nu mai are autofocus HTML');
ok42(str_contains($js,'finePointerTextFocus')&&str_contains($js,"window.matchMedia('(pointer:fine)')"),'focusul automat pentru scanare este limitat la pointer fin/desktop');
ok42(str_contains($js,'touchChat')&&str_contains($js,'focusChatInput'),'chatul are gard pentru focus automat pe dispozitive touch');
ok42(!str_contains($js,'await loadMessages(true);messageInput?.focus();'),'deschiderea conversatiei nu mai focalizeaza direct textarea');
ok42(str_contains($css,'v0.11.42 - professional scanner')&&str_contains($css,'.scan-manual-form')&&str_contains($css,'@media(max-width:620px)'),'scanarea are layout responsive dedicat');
echo "\nV0.11.42 SCAN + CHAT TOUCH REGRESSION PASSED\n";
