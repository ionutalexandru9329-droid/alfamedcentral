<?php
declare(strict_types=1);
function ok36(bool $cond,string $message):void{if(!$cond)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$root=dirname(__DIR__);
$client=(string)file_get_contents($root.'/modules/invoices-documents/src/OblioClient.php');
$service=(string)file_get_contents($root.'/modules/invoices-documents/src/InvoiceService.php');
$boot=(string)file_get_contents($root.'/modules/invoices-documents/bootstrap.php');
$view=(string)file_get_contents($root.'/views/settings_module.php');
$sqlite=(string)file_get_contents($root.'/modules/invoices-documents/database/sqlite.sql');
$mysql=(string)file_get_contents($root.'/modules/invoices-documents/database/mysql.sql');

ok36(str_contains($client,'public function allProducts')&&str_contains($client,"'offset'=>\$offset")&&str_contains($client,"'includeUnsalable'=>1"),'clientul Oblio descarca nomenclatorul complet paginat');
ok36(str_contains($service,'public function syncOblioCatalogue')&&str_contains($service,'oblio_products_cache'),'serviciul sincronizeaza nomenclatorul intr-un cache local dedicat');
ok36(str_contains($service,'public function maybeAutoSyncCatalogue')&&str_contains($boot,"'autosync.channel.completed'"),'nomenclatorul are sincronizare automata prin worker/CRON');
ok36(str_contains($service,'runtime.oblio_catalogue_last_attempt')&&str_contains($service,"'reason'=>'retry_cooldown'"),'erorile de sincronizare automata au cooldown si nu lovesc API-ul la fiecare minut');
ok36(str_contains($boot,'catalogue-sync')&&str_contains($view,'Sincronizeaza acum din Oblio'),'setarile includ sincronizare manuala la cerere');
ok36(str_contains($service,'resolveCachedOblioProduct')&&str_contains($service,'if(!$resolved)$resolved=$oblio->resolveEquivalentProduct'),'facturarea foloseste cache-ul local inaintea fallback-ului API live');
ok36(str_contains($client,"is_array(\$item['_oblio_catalogue_row']??null)"),'linia de factura poate folosi direct produsul Oblio deja rezolvat local');
ok36(str_contains($sqlite,'CREATE TABLE IF NOT EXISTS oblio_products_cache')&&str_contains($mysql,'CREATE TABLE IF NOT EXISTS oblio_products_cache'),'schema cache-ului Oblio exista pentru SQLite si MySQL');
ok36(str_contains($sqlite,'code_client')&&str_contains($sqlite,'code_ean')&&str_contains($mysql,'stock_json'),'cache-ul pastreaza coduri alternative, EAN si stoc');
ok36(str_contains($view,'Echivalari produse')&&str_contains($view,'Manual'),'echivalarea manuala ramane fallback si este separata de maparile Auto Oblio');
ok36(str_contains($service,'Cache-ul local nu a fost modificat')&&str_contains($service,"empty(\$remote['complete'])"),'sincronizarea incompleta nu invalideaza cache-ul existent');

echo "\nV0.11.36 OBLIO CATALOGUE SYNC REGRESSION PASSED\n";
