<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){echo "SKIP: pdo_sqlite indisponibil\n";exit(0);}
if(!function_exists('curl_init')&&!filter_var((string)ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){echo "SKIP: transport HTTP indisponibil\n";exit(0);}
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/alfamed-awb-v01124-'.bin2hex(random_bytes(4)).'.sqlite';@unlink($tmp);
putenv('DB_DSN=sqlite:'.$tmp);$_ENV['DB_DSN']='sqlite:'.$tmp;
putenv('EMAG_RO_ENABLED=true');$_ENV['EMAG_RO_ENABLED']='true';
putenv('EMAG_RO_BASE_URL=http://127.0.0.1:18082/api-3');$_ENV['EMAG_RO_BASE_URL']='http://127.0.0.1:18082/api-3';
putenv('EMAG_RO_USERNAME=test');$_ENV['EMAG_RO_USERNAME']='test';putenv('EMAG_RO_PASSWORD=test');$_ENV['EMAG_RO_PASSWORD']='test';
putenv('EMAG_BG_ENABLED=false');$_ENV['EMAG_BG_ENABLED']='false';
require $root.'/bootstrap.php';require $root.'/modules/couriers/src/EmagFulfillmentService.php';
use App\Core\Database;use AlfamedModules\Couriers\EmagFulfillmentService;
function ok24(bool $c,string $m):void{if(!$c)throw new RuntimeException('FAIL: '.$m);echo "OK: {$m}\n";}
$db=Database::connection();$schema=file_get_contents($root.'/database/schema_sqlite.sql');$db->exec((string)$schema);
$db->exec("INSERT INTO channels(code,name,type,country,currency,enabled) VALUES('emag_ro','eMAG Romania','emag','RO','RON',1)");$channelId=(int)$db->lastInsertId();
$raw=json_encode(['id'=>516452638,'status'=>4,'type'=>3],JSON_UNESCAPED_SLASHES);
$ins=$db->prepare("INSERT INTO orders(code,channel_id,external_id,status,currency,total,customer_name,raw_json,ordered_at) VALUES(?,?,?,?,?,?,?,?,?)");
$ins->execute(['AC-011204',$channelId,'516452638','completed','RON',45.98,'Test Client',$raw,'2026-09-17 14:54:52']);$orderId=(int)$db->lastInsertId();
$svc=new EmagFulfillmentService($db);$r=$svc->refreshOrderAwb($orderId,16);
ok24(($r['found']??false)===true,'cautarea tail/resumabila gaseste AWB-ul fara attachment order/read si fara total_pages');
$ship=$db->query("SELECT awb,awb_barcode,provider_awb_id FROM shipments WHERE order_id=".$orderId." LIMIT 1")->fetch();
ok24(($ship['awb_barcode']??'')==='4EMGLN187446944001','barcode-ul real este importat in shipments');
ok24(($ship['provider_awb_id']??'')==='255703514','emag_id real este pastrat pentru A4/A6');
$diag=(array)($r['diagnostic']??[]);
ok24(in_array(23,(array)($diag['feed_checked_pages']??[]),true),'algoritmul ajunge la pagina 23 prin descoperirea capatului istoric');

// A real API refusal must be visible in diagnostics, not silently turned into "0 results".
$db->exec('DELETE FROM shipments');
putenv('EMAG_RO_BASE_URL=http://127.0.0.1:18082/api-error');$_ENV['EMAG_RO_BASE_URL']='http://127.0.0.1:18082/api-error';
$r2=(new EmagFulfillmentService($db))->refreshOrderAwb($orderId,8);$d2=(array)($r2['diagnostic']??[]);
ok24(($r2['found']??false)===false,'eroarea AWB/read nu produce asociere falsa');
ok24(($d2['awb_read']??'')==='error','diagnosticul expune explicit eroarea AWB/read');
ok24(trim((string)($d2['awb_read_error']??''))!=='','diagnosticul pastreaza mesajul scurt al erorii API');
@unlink($tmp);echo "\nAWB V0.11.24 HTTP INTEGRATION PASSED\n";
