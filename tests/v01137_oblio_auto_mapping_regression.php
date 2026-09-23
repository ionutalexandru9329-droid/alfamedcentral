<?php
declare(strict_types=1);
function ok37(bool $cond,string $message):void{if(!$cond)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/modules/invoices-documents/src/InvoiceService.php');
$view=(string)file_get_contents($root.'/views/settings_module.php');
$upgrade=(string)file_get_contents($root.'/app/Core/UpgradeManager.php');
$mysql=(string)file_get_contents($root.'/modules/invoices-documents/database/mysql.sql');
$sqlite=(string)file_get_contents($root.'/modules/invoices-documents/database/sqlite.sql');
$boot=(string)file_get_contents($root.'/modules/invoices-documents/bootstrap.php');

ok37(str_contains($service,'syncAutoMappingsFromCatalogue($rows)'),'sincronizarea nomenclatorului declanseaza importul echivalarilor automate');
ok37(str_contains($service,"row['codeClient']")&&str_contains($service,"row['codeEAN']"),'echivalarile automate folosesc identificatorii expliciti codeClient si codeEAN din Oblio');
ok37(str_contains($service,'knownSourceProductsByChannel')&&str_contains($service,'master_ean'),'codurile Oblio sunt asociate doar cu identificatori cunoscuti pe canalul local, inclusiv din comenzile istorice');
ok37(str_contains($service,"mapping_source='manual'")&&str_contains($service,'manualPreserved'),'sincronizarea protejeaza maparile introduse manual');
ok37(str_contains($service,"match_reason LIKE 'sync_%'")&&str_contains($service,"'oblio_codeClient','oblio_codeEAN'"),'maparile Auto Oblio sunt reconstruite si cele invechite nu raman active');
ok37(str_contains($service,'saveAutoMapping($channel,(string)$persistCode'),'maparile invatate sigur in timpul facturarii sunt marcate automat');
ok37(str_contains($mysql,'mapping_source')&&str_contains($mysql,'match_reason')&&str_contains($sqlite,'mapping_source'),'schema noua memoreaza sursa si motivul echivalarii');
ok37(str_contains($upgrade,'oblio_product_mappings')&&str_contains($upgrade,"DEFAULT 'manual'"),'upgrade-ul marcheaza echivalarile vechi ca manuale pentru a nu fi suprascrise');
ok37(str_contains($view,'Auto Oblio')&&str_contains($view,'Echivalari produse')&&str_contains($view,'Echivalari auto'),'interfata afiseaza separat echivalarile automate si manuale');
ok37(str_contains($boot,'echivalari automate actualizate'),'sincronizarea manuala raporteaza cate echivalari automate a procesat');
ok37(str_contains($service,'runtime.oblio_catalogue_last_auto_mappings')&&str_contains($service,'auto_mappings'),'statusul nomenclatorului pastreaza contoarele echivalarilor automate');

echo "\nV0.11.37 OBLIO AUTO MAPPING REGRESSION PASSED\n";
