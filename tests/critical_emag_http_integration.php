<?php
declare(strict_types=1);

// This test needs SQLite plus one PHP HTTP transport because it boots a real local
// mock Marketplace endpoint. Shared-hosting production has cURL, but lightweight
// build containers may intentionally omit both extensions.
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){
    echo "SKIP: critical_emag_http_integration - pdo_sqlite indisponibil in mediul de build\n";exit(0);
}
if(!function_exists('curl_init')&&!filter_var((string)ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){
    echo "SKIP: critical_emag_http_integration - lipseste transportul HTTP PHP (cURL/allow_url_fopen)\n";exit(0);
}
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/alfamed-emag-http-'.bin2hex(random_bytes(4)).'.sqlite';
@unlink($tmp);
putenv('DB_DSN=sqlite:'.$tmp);$_ENV['DB_DSN']='sqlite:'.$tmp;
putenv('EMAG_RO_ENABLED=true');$_ENV['EMAG_RO_ENABLED']='true';
putenv('EMAG_RO_BASE_URL=http://127.0.0.1:18081/api-3');$_ENV['EMAG_RO_BASE_URL']='http://127.0.0.1:18081/api-3';
putenv('EMAG_RO_USERNAME=test');$_ENV['EMAG_RO_USERNAME']='test';
putenv('EMAG_RO_PASSWORD=test');$_ENV['EMAG_RO_PASSWORD']='test';
putenv('EMAG_BG_ENABLED=false');$_ENV['EMAG_BG_ENABLED']='false';
require $root.'/bootstrap.php';
require $root.'/modules/couriers/src/EmagFulfillmentService.php';
use App\Core\Database;
use AlfamedModules\Couriers\EmagFulfillmentService;

function ok2(bool $condition,string $message):void{if(!$condition)throw new RuntimeException('FAIL: '.$message);echo "OK: {$message}\n";}
$db=Database::connection();
$schema=file_get_contents($root.'/database/schema_sqlite.sql');if($schema===false)throw new RuntimeException('schema missing');$db->exec($schema);
$db->exec("INSERT INTO channels(code,name,type,country,currency,enabled) VALUES('emag_ro','eMAG Romania','emag','RO','RON',1)");

$svc=new EmagFulfillmentService($db);
$hit=$svc->recoverRemoteAwbByToken('emag_ro','4EMGLN187446944001',120);
ok2(is_array($hit),'AWB-ul real de control este gasit prin HTTP AWB/read');
ok2(($hit['external_id']??'')==='516452638','AWB-ul este legat de comanda externa reala 516452638');
$st=$db->prepare("SELECT o.id,o.external_id,o.code,s.awb,s.awb_barcode,s.courier FROM orders o JOIN shipments s ON s.order_id=o.id WHERE o.external_id='516452638' LIMIT 1");$st->execute();$row=$st->fetch();
ok2(is_array($row),'comanda lipsa este importata local inainte de asocierea AWB');
ok2(($row['awb_barcode']??'')==='4EMGLN187446944001','barcode-ul complet este salvat in shipments');
ok2(($row['awb']??'')==='4EMGLN187446944','awb_number este pastrat separat de barcode');
$item=$db->query("SELECT remote_product_id,sku,image_url,product_url FROM order_items LIMIT 1")->fetch();
ok2(($item['remote_product_id']??'')==='555','product_id eMAG este salvat pe linia comenzii');
ok2(($item['sku']??'')==='ON483','part_number eMAG este pastrat ca SKU de referinta');
ok2(($item['image_url']??'')===''||$item['image_url']===null,'importul AWB/comanda nu introduce imagine WooCommerce');
ok2(($item['product_url']??'')==='https://www.emag.ro/produs/pd/DTESTPNKBM/','part_number_key din order/read produce direct hyperlink eMAG');

$hit2=$svc->recoverRemoteAwbByToken('emag_ro','4EMGLN187446944',120);
ok2(is_array($hit2)&&($hit2['order_id']??0)===(int)$row['id'],'scanarea awb_number fara sufix regaseste aceeasi comanda');

// v0.11.23: the normal application flow must repair a missing local AWB per order,
// without requiring the scanner to crawl Marketplace history.
$db->exec('DELETE FROM shipments');
$refresh=$svc->refreshOrderAwb((int)$row['id'],9);
ok2(($refresh['ok']??false)===true&&($refresh['found']??false)===true,'refreshOrderAwb reimporta AWB-ul pentru o comanda deja locala');
$reloaded=$db->query("SELECT awb,awb_barcode,provider_awb_id FROM shipments LIMIT 1")->fetch();
ok2(($reloaded['awb_barcode']??'')==='4EMGLN187446944001','refresh-ul per comanda salveaza barcode-ul real');
ok2(($reloaded['provider_awb_id']??'')==='255703514','order/attachments/read conduce la emag_id-ul AWB si il pastreaza pentru A4/A6');
@unlink($tmp);
echo "\nCRITICAL EMAG HTTP INTEGRATION PASSED\n";
