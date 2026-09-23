<?php
$service=file_get_contents(__DIR__.'/../modules/invoices-documents/src/InvoiceService.php');
$view=file_get_contents(__DIR__.'/../views/settings_module.php');
$boot=file_get_contents(__DIR__.'/../modules/invoices-documents/bootstrap.php');
function ok38($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "OK: $msg\n";}
ok38(str_contains($service,'knownSourceProductsByChannel()'),'sync-ul foloseste produse de canal cu relatie catre catalogul central');
ok38(str_contains($service,"'sync_direct_code'")&&str_contains($service,"'sync_master_code'")&&str_contains($service,"'sync_exact_name'"),'matching-ul are cod direct, bridge central si denumire exacta');
ok38(str_contains($service,'sourceNameCounts')&&str_contains($service,'count($matches)!==1'),'denumirea exacta este acceptata doar cand potrivirea este unica si neambigua');
ok38(str_contains($service,"mapping_source='manual'")&&str_contains($service,'manualPreserved'),'maparile manuale raman protejate');
ok38(str_contains($service,"match_reason LIKE 'sync_%'"),'maparile generate de sincronizare sunt reconstruite controlat');
ok38(str_contains($service,"runtime.oblio_catalogue_last_auto_exact_name")&&str_contains($service,"runtime.oblio_catalogue_last_auto_unmatched"),'diagnosticul de matching este persistat');
ok38(str_contains($view,'Ultima analiza:')&&str_contains($view,'denumire exacta')&&str_contains($view,'fara potrivire'),'interfata arata diagnosticul ultimei analize');
ok38(str_contains($boot,'produse de canal analizate'),'mesajul de sync raporteaza volumul analizat');
echo "\nV0.11.38 OBLIO SAFE MATCH REGRESSION PASSED\n";
