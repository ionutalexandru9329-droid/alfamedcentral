<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!is_file($root.'/storage/installed.lock')){
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));$base='';
    foreach(['/public/index.php','/index.php'] as $suffix){if($script!==''&&str_ends_with($script,$suffix)){$candidate=rtrim(substr($script,0,-strlen($suffix)),'/');$base=$candidate==='/'?'':$candidate;break;}}
    header('Location: '.($base?:'').'/install.php');exit;
}
require_once $root.'/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\EnvEditor;
use App\Core\ModuleManager;
use App\Core\UpgradeManager;
use App\Core\View;
use App\Services\NotificationService;
use App\Services\AutoSyncService;
use App\Services\WooWebhookService;
use App\Services\OrderOperations;
use App\Services\OrderPresentation;
use App\Services\ProductService;
use App\Services\SettingService;
use App\Services\SyncService;
use App\Services\EmagDiagnosticService;
use App\Services\EmagImageSyncService;
use App\Services\ActivityService;

$path=app_route_path($_SERVER['REQUEST_URI']??'/');$method=$_SERVER['REQUEST_METHOD']??'GET';
$db=Database::connection();
try {
    UpgradeManager::runIfNeeded($db);
} catch (Throwable $upgradeError) {
    $root=dirname(__DIR__);
    @file_put_contents($root.'/storage/upgrade-error.log','['.date(DATE_ATOM).'] '.$upgradeError->getMessage().PHP_EOL.$upgradeError->getTraceAsString().PHP_EOL.PHP_EOL,FILE_APPEND|LOCK_EX);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $details=Env::bool('APP_DEBUG') ? '<details style="margin-top:18px"><summary>Detalii tehnice</summary><pre style="white-space:pre-wrap;background:#f7f7f8;padding:14px;border-radius:10px">'.htmlspecialchars($upgradeError->getMessage(),ENT_QUOTES,'UTF-8').'</pre></details>' : '';
    $retry=htmlspecialchars(app_path('/'),ENT_QUOTES,'UTF-8');
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizare ALFAMED CENTRAL</title><style>body{font-family:Arial,sans-serif;background:#f3f6fb;color:#13213a;margin:0}.box{max-width:720px;margin:10vh auto;background:white;padding:32px;border-radius:16px;box-shadow:0 10px 35px rgba(20,35,60,.10)}h1{margin-top:0}.btn{display:inline-block;background:#2457e6;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:700}.muted{color:#667085;line-height:1.55}</style></head><body><div class="box"><h1>Actualizarea bazei de date nu s-a finalizat</h1><p class="muted">Datele existente nu au fost sterse. Aplicatia a oprit pornirea pentru a evita o schema partiala. Dupa corectarea cauzei, apasa butonul de mai jos; upgrade-ul este reluabil.</p><p><a class="btn" href="'.$retry.'">Reincearca actualizarea</a></p><p class="muted">Detaliile complete sunt salvate in <code>storage/upgrade-error.log</code>.</p>'.$details.'</div></body></html>';
    exit;
}
$modules=new ModuleManager($db);$settings=new SettingService($db);

try {
    // Signed WooCommerce webhooks are public by design; signature verification happens before import.
    if(preg_match('#^/webhooks/woocommerce/(univera|alfamed)$#',$path,$wm)&&$method==='POST'){
        header('Content-Type: application/json; charset=utf-8');
        $raw=(string)file_get_contents('php://input');
        $sig=(string)($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE']??'');
        $svc=new WooWebhookService();
        if(!$svc->verify($wm[1],$raw,$sig)){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'invalid_signature']);exit;}
        $payload=json_decode($raw,true);
        if(!is_array($payload)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_json']);exit;}
        $topic=(string)($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC']??'order.updated');
        $result=$svc->ingest($wm[1],$payload,$topic);
        echo json_encode(['ok'=>true,'type'=>$result['type']??'unknown','new'=>(bool)($result['new']??false),'id'=>$result['id']??($result['product_id']??0),'deleted'=>(bool)($result['deleted']??false)],JSON_UNESCAPED_UNICODE);exit;
    }

    // Public module routes (for example a tokenized customer document) are handled before authentication.
    if($modules->handleRoute($path,$method,true))exit;

    if($path==='/login'&&$method==='GET'){if(Auth::check())redirect('/');View::render('login',['title'=>'Autentificare']);exit;}
    if($path==='/login'&&$method==='POST'){Csrf::verify();$remember=isset($_POST['remember_me']);if(Auth::attempt(trim((string)($_POST['email']??'')),(string)($_POST['password']??''),$remember)){(new ActivityService($db))->record('auth.login','s-a autentificat in ALFAMED CENTRAL','auth');redirect('/');}flash('error','Email sau parola incorecta sau cont inactiv.');redirect('/login');}
    if($path==='/logout'&&$method==='POST'){Csrf::verify();if(Auth::check())(new ActivityService($db))->record('auth.logout','s-a deconectat din ALFAMED CENTRAL','auth');Auth::logout();redirect('/login');}
    Auth::requireLogin();
    Auth::syncSession();
    Auth::touchActivity();
    ActivityService::beginRequestAudit($path,$method,$db);

    // Access control: administratorul are acces complet; ceilalti utilizatori primesc doar drepturile acordate.
    if($path==='/'&&!Auth::can('dashboard.view')){if(Auth::can('orders.view'))redirect('/orders');if(Auth::can('products.view'))redirect('/products');if(Auth::can('chat.use'))redirect('/profile');throw new RuntimeException('Contul nu are acces la nicio sectiune a aplicatiei.');}
    if(str_starts_with($path,'/orders')){Auth::requirePermission('orders.view');if($method!=='GET')Auth::requirePermission('orders.manage');}
    if(str_starts_with($path,'/products')){Auth::requirePermission('products.view');if($method!=='GET')Auth::requirePermission('products.manage');}
    if($path==='/sync')Auth::requirePermission('dashboard.view');
    if(str_starts_with($path,'/modules'))Auth::requirePermission('modules.manage');
    if(str_starts_with($path,'/settings'))Auth::requirePermission('settings.manage');

    // AJAX polling must not keep the PHP session locked; otherwise one slow poll blocks every tab for this user.
    $readOnlyPoll=$method==='GET' && ($path==='/api/notifications' || $path==='/api/chat/summary' || preg_match('#^/api/chat/messages/\d+$#',$path));
    if($readOnlyPoll && session_status()===PHP_SESSION_ACTIVE) @session_write_close();

    if($path==='/'&&$method==='GET'){
        $today=date('Y-m-d 00:00:00');$q=$db->prepare('SELECT COUNT(*) FROM orders WHERE remote_deleted=0 AND ordered_at>=?');$q->execute([$today]);$todayCount=(int)$q->fetchColumn();
        $pending=(int)$db->query("SELECT COUNT(*) FROM orders WHERE remote_deleted=0 AND status IN ('new','pending','processing','on-hold','prepared')")->fetchColumn();
        $productCount=(int)$db->query('SELECT COUNT(*) FROM products WHERE archived=0')->fetchColumn();
        $activeChannels=(int)$db->query('SELECT COUNT(*) FROM channels WHERE enabled=1')->fetchColumn();
        $stats=[['label'=>'Comenzi azi','value'=>$todayCount,'sub'=>'toate canalele'],['label'=>'De procesat','value'=>$pending,'sub'=>'noi / procesare / pregatite'],['label'=>'Produse','value'=>$productCount,'sub'=>'catalog central activ'],['label'=>'Canale active','value'=>$activeChannels,'sub'=>'marketplace + magazine']];
        $recent=$db->query('SELECT o.*,c.name channel_name,c.code channel_code FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.remote_deleted=0 ORDER BY COALESCE(o.ordered_at,o.created_at) DESC LIMIT 10')->fetchAll();
        $logs=$db->query('SELECT l.*,c.name channel_name FROM sync_logs l LEFT JOIN channels c ON c.id=l.channel_id ORDER BY l.id DESC LIMIT 6')->fetchAll();
        $channelState=$db->query('SELECT code,name,type,country,currency,enabled FROM channels ORDER BY name')->fetchAll();
        $userActivity=(new ActivityService($db))->recentOperational(7);
        View::render('dashboard',compact('stats','recent','logs','channelState','modules','userActivity')+['title'=>'Dashboard']);exit;
    }
    if($path==='/sync'&&$method==='POST'){
        Csrf::verify();
        // Manual sync must not lock every request from the same browser session. It also
        // refreshes orders only; product catalog sync remains an explicit Products action.
        if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        $run=(new AutoSyncService())->tick(true,false,true);$result=(array)($run['channels']??[]);
        $errors=array_filter($result,static fn($r)=>is_array($r)&&isset($r['ok'])&&!($r['ok']??false));
        $new=array_sum(array_map(static fn($r)=>is_array($r)?(int)($r['new']??0):0,$result));
        if(session_status()!==PHP_SESSION_ACTIVE) @session_start();
        if(!($run['ok']??false)) flash('error','Sincronizarea nu s-a putut finaliza. Verifica Setari > Jurnal.');
        elseif($run['skipped']??false) flash('success','O sincronizare ruleaza deja in fundal. Poti continua sa folosesti aplicatia.');
        else flash($errors?'error':'success',$errors?'Sincronizarea comenzilor s-a terminat cu erori. Verifica Setari > Jurnal.':'Sincronizare finalizata. Comenzi noi: '.$new.'.');
        redirect('/');
    }

    if($path==='/orders'&&$method==='GET'){
        $q=trim((string)($_GET['q']??''));$channel=trim((string)($_GET['channel']??''));$status=trim((string)($_GET['status']??''));$where=['o.remote_deleted=0'];$params=[];
        if($q!==''){$where[]='(o.code LIKE ? OR o.external_id LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ?)';for($i=0;$i<5;$i++)$params[]='%'.$q.'%';}
        if($channel!==''){$where[]='c.code=?';$params[]=$channel;}if($status!==''){$where[]='o.status=?';$params[]=$status;}
        $whereSql=implode(' AND ',$where);
        $count=$db->prepare('SELECT COUNT(*) FROM orders o JOIN channels c ON c.id=o.channel_id WHERE '.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();
        $perPage=max(10,min(200,(int)$settings->get('ui.orders_per_page','50')));$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,(int)($_GET['page']??1)));$offset=($page-1)*$perPage;
        $sql="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,c.country channel_country,
            (SELECT CONCAT(COALESCE(i.series,''),' ',COALESCE(i.number,'')) FROM invoices i WHERE i.order_id=o.id AND i.status NOT IN ('deleted','cancelled') ORDER BY i.id DESC LIMIT 1) document_no,
            (SELECT s.awb FROM shipments s WHERE s.order_id=o.id AND s.status NOT IN ('deleted','cancelled') ORDER BY s.id DESC LIMIT 1) awb,
            (SELECT s.courier FROM shipments s WHERE s.order_id=o.id AND s.status NOT IN ('deleted','cancelled') ORDER BY s.id DESC LIMIT 1) courier
            FROM orders o JOIN channels c ON c.id=o.channel_id WHERE ".$whereSql." ORDER BY COALESCE(o.ordered_at,o.created_at) DESC LIMIT {$perPage} OFFSET {$offset}";
        $st=$db->prepare($sql);$st->execute($params);$orders=$st->fetchAll();$channels=$db->query('SELECT * FROM channels ORDER BY name')->fetchAll();$orderActions=Auth::can('orders.manage')?$modules->actions('orders'):[];$reselectIds=array_values(array_unique(array_map('intval',(array)($_SESSION['_orders_reselect']??[]))));unset($_SESSION['_orders_reselect']);View::render('orders',compact('orders','channels','q','channel','status','orderActions','page','pages','perPage','total','reselectIds')+['title'=>'Comenzi']);exit;
    }
    if($path==='/orders/actions'&&$method==='POST'){
        Csrf::verify();
        $ids=(array)($_POST['order_ids']??[]);$action=(string)($_POST['action']??'');$single=(int)($_POST['single_order_id']??0);
        if($single>0){$ids=[$single];$action=(string)($_POST['row_action'][$single]??'');if($action==='generate_awb')$_POST['courier']=(string)($_POST['row_courier'][$single]??'');}
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));if(!$ids)throw new RuntimeException('Selecteaza cel putin o comanda.');if($action==='')throw new RuntimeException('Alege o actiune.');
        $result=$modules->runAction('orders',$action,['order_ids'=>$ids,'ids'=>$ids,'payload'=>$_POST]);$ok=(int)($result['ok']??0);$errors=(array)($result['errors']??[]);$warnings=(array)($result['warnings']??[]);$msg='Operatiune finalizata pentru '.$ok.' comenzi.';if($warnings)$msg.=' Atentionari: '.implode(' | ',array_slice($warnings,0,3));if($errors)$msg.=' Erori: '.implode(' | ',array_slice($errors,0,4));flash($errors?'error':'success',$msg);if(in_array($action,['generate_awb_auto','generate_awb'],true)){$_SESSION['_orders_reselect']=array_values(array_unique(array_map('intval',(array)($result['success_ids']??[]))));}$return=['q'=>trim((string)($_POST['return_q']??'')),'channel'=>trim((string)($_POST['return_channel']??'')),'status'=>trim((string)($_POST['return_status']??'')),'page'=>max(1,(int)($_POST['return_page']??1))];$query=http_build_query(array_filter($return,static fn($v,$k)=>$k==='page'?((int)$v>1):(string)$v!=='',ARRAY_FILTER_USE_BOTH));redirect('/orders'.($query!==''?'?'.$query:''));
    }
    if(preg_match('#^/orders/(\d+)/preview$#',$path,$m)&&$method==='GET'){
        /*
         * CRITICAL UX RULE: inline preview is LOCAL-ONLY.
         * Never call eMAG/WooCommerce/Oblio here. A marketplace timeout must not keep
         * the row stuck on "Se incarca detaliile comenzii...".
         * Remote AWB/media/document reconciliation runs in separate requests/jobs.
         */
        if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        try{
            $order=(new OrderOperations())->getOrder((int)$m[1]);
            $inv=$db->prepare("SELECT type,series,number,status,link FROM invoices WHERE order_id=? AND status NOT IN ('deleted','cancelled','hidden') ORDER BY id DESC LIMIT 3");$inv->execute([(int)$order['id']]);
            $ship=$db->prepare("SELECT courier,awb,awb_barcode,provider_awb_id,status,tracking_url FROM shipments WHERE order_id=? AND status NOT IN ('deleted','cancelled') ORDER BY id DESC LIMIT 1");$ship->execute([(int)$order['id']]);$shipment=$ship->fetch()?:null;
            if($shipment){
                $courierKey=strtolower(trim((string)($shipment['courier']??'')));
                $downloadable=(string)($order['channel_type']??'')==='emag'||str_contains($courierKey,'sameday')||$courierKey==='fan'||str_contains($courierKey,'fan courier');
                $shipment['download_url']=$downloadable?app_path('/orders/'.(int)$order['id'].'/awb-pdf/A4'):'';
            }
            $items=[];foreach((array)$order['items'] as $item)$items[]=['id'=>(int)($item['id']??0),'sku'=>(string)($item['sku']??''),'name'=>(string)($item['name']??''),'qty'=>(float)($item['qty']??0),'unit_price'=>(float)($item['unit_price']??0),'image_url'=>(string)($item['image_url']??''),'product_url'=>(string)($item['product_url']??'')];
            echo json_encode(['ok'=>true,'order'=>['code'=>(string)$order['code'],'external_id'=>(string)$order['external_id'],'channel_type'=>(string)($order['channel_type']??''),'customer_name'=>(string)$order['customer_name'],'email'=>(string)$order['customer_email'],'phone'=>(string)$order['customer_phone'],'currency'=>(string)$order['currency'],'total'=>(float)$order['total'],'payment'=>OrderPresentation::paymentLabel($order),'delivery'=>OrderPresentation::deliveryLabel($order),'items'=>$items,'documents'=>$inv->fetchAll(),'shipment'=>$shipment]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }catch(Throwable $previewError){
            http_response_code(500);
            echo json_encode(['ok'=>false,'message'=>'Detaliile comenzii nu au putut fi incarcate.','error'=>Env::bool('APP_DEBUG')?$previewError->getMessage():null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }
    }
    if(preg_match('#^/orders/(\d+)$#',$path,$m)&&$method==='GET'){
        $orderOps=new OrderOperations();$order=$orderOps->getOrder((int)$m[1]);
        View::render('order',compact('order','modules')+['title'=>$order['code']]);exit;
    }
    if(preg_match('#^/orders/(\d+)/status$#',$path,$m)&&$method==='POST'){Csrf::verify();$status=(string)($_POST['status']??'');$reason=trim((string)($_POST['reason']??''));$emagReason=isset($_POST['emag_cancellation_reason'])?(int)$_POST['emag_cancellation_reason']:null;(new OrderOperations())->setStatus((int)$m[1],$status,$reason,$emagReason);flash('success','Statusul comenzii a fost actualizat.');redirect('/orders/'.$m[1]);}
    if(preg_match('#^/orders/(\d+)/cancel$#',$path,$m)&&$method==='POST'){Csrf::verify();(new OrderOperations())->cancelOrder((int)$m[1],trim((string)($_POST['reason']??'')));flash('success','Comanda a fost anulata pe canalul extern.');redirect('/orders/'.$m[1]);}
    if(preg_match('#^/orders/(\d+)/emag-acknowledge$#',$path,$m)&&$method==='POST'){Csrf::verify();(new OrderOperations())->acknowledgeEmag((int)$m[1]);flash('success','Comanda eMAG a fost preluata si este acum in procesare.');redirect('/orders/'.$m[1]);}

    if($path==='/products'&&$method==='GET'){
        $q=trim((string)($_GET['q']??''));$channel=trim((string)($_GET['channel']??''));$productStatus=trim((string)($_GET['product_status']??''));if(!in_array($productStatus,['published','pending','trash'],true))$productStatus='';$service=new ProductService($db);$statusCounts=$service->statusCounts($q,$channel);$total=$service->countRows($q,$channel,$productStatus);$perPage=max(10,min(200,(int)$settings->get('ui.products_per_page','50')));$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,(int)($_GET['page']??1)));$products=$service->listRows($q,$channel,$productStatus,$perPage,($page-1)*$perPage);$channels=$db->query("SELECT * FROM channels WHERE type='woocommerce' ORDER BY name")->fetchAll();View::render('products',compact('products','channels','q','channel','productStatus','statusCounts','page','pages','perPage','total','modules')+['title'=>'Produse']);exit;
    }
    if($path==='/products/sync'&&$method==='POST'){
        Csrf::verify();if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        $result=(new SyncService())->syncProductsQuick();$errors=array_filter($result,fn($r)=>!($r['ok']??false));$count=array_sum(array_map(fn($r)=>(int)($r['products']??0),$result));$dedupe=array_sum(array_map(fn($r)=>(int)($r['deduplicated']??0),$result));
        if(session_status()!==PHP_SESSION_ACTIVE) @session_start();
        flash($errors?'error':'success',$errors?'Actualizarea rapida a produselor a avut erori. Vezi Jurnalul din Setari.':'Magazine actualizate rapid: '.$count.' produse'.($dedupe?' · '.$dedupe.' duplicate reunite':'').'. Reconcilierea completa continua automat in fundal.');redirect('/products');
    }
    if($path==='/api/products/sync'&&$method==='POST'){
        Csrf::verify();if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();header('Content-Type: application/json; charset=utf-8');
        $result=(new SyncService())->syncProductsQuick();$errors=array_values(array_filter($result,fn($r)=>!($r['ok']??false)));$count=array_sum(array_map(fn($r)=>(int)($r['products']??0),$result));$dedupe=array_sum(array_map(fn($r)=>(int)($r['deduplicated']??0),$result));
        echo json_encode(['ok'=>!$errors,'count'=>$count,'deduplicated'=>$dedupe,'channels'=>$result,'message'=>$errors?'Actualizarea a avut erori. Vezi Jurnalul.':'Actualizare terminata.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($path==='/products/new'&&$method==='GET'){$service=new ProductService($db);$taxonomies=$service->taxonomyCatalog();$nextSku=$service->nextSku();View::render('product_new',compact('taxonomies','nextSku')+['title'=>'Produs nou']);exit;}
    if($path==='/products/new'&&$method==='POST'){Csrf::verify();$created=(new ProductService($db))->createFromForm($_POST,$_FILES);$first=reset($created);$pid=(int)($first['product_id']??0);flash('success','Produsul a fost creat pe '.count($created).' magazin(e).');redirect($pid?'/products/'.$pid:'/products');}
    if($path==='/products/actions'&&$method==='POST'){
        Csrf::verify();$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['product_ids']??[])),fn($v)=>$v>0)));if(!$ids)throw new RuntimeException('Selecteaza cel putin un produs.');$action=(string)($_POST['action']??'');$svc=new ProductService($db);
        if($action==='transfer')$result=$svc->bulkTransfer($ids,(string)($_POST['source_code']??''),(string)($_POST['target_code']??''));
        elseif($action==='delete')$result=$svc->deleteProducts($ids,(array)($_POST['targets']??[]));
        elseif($action==='bulk_edit'){$_POST['targets']=(array)($_POST['edit_targets']??[]);$result=$svc->bulkUpdate($ids,$_POST);}
        else throw new RuntimeException('Actiune produs invalida.');
        $errors=(array)($result['errors']??[]);flash($errors?'error':'success','Produse procesate: '.(int)($result['ok']??0).($errors?' · Erori: '.implode(' | ',array_slice($errors,0,4)):''));redirect('/products');
    }
    if(preg_match('#^/products/(\d+)$#',$path,$m)&&$method==='GET'){
        $service=new ProductService($db);$product=$service->getProduct((int)$m[1]);$source=trim((string)($_GET['source']??''));$reference=null;
        foreach($product['channels'] as $cp){if($source!==''&&$cp['channel_code']===$source){$reference=$cp;break;}}
        if(!$reference){foreach($product['channels'] as $cp){if($cp['channel_type']==='woocommerce'){$reference=$cp;break;}}}
        $taxonomies=$service->taxonomyCatalog();
        View::render('product',compact('product','reference','taxonomies')+['title'=>$product['name']]);exit;
    }
    if(preg_match('#^/products/(\d+)/save$#',$path,$m)&&$method==='POST'){Csrf::verify();(new ProductService($db))->updateFromForm((int)$m[1],$_POST,$_FILES);flash('success','Produsul a fost actualizat pe magazinele selectate.');redirect('/products/'.$m[1]);}
    if(preg_match('#^/products/(\d+)/quick-save$#',$path,$m)&&$method==='POST'){Csrf::verify();(new ProductService($db))->quickUpdate((int)$m[1],$_POST);flash('success','Modificarile rapide au fost salvate.');redirect('/products');}
    if(preg_match('#^/products/(\d+)/transfer$#',$path,$m)&&$method==='POST'){Csrf::verify();$source=(string)($_POST['source_code']??'');$target=(string)($_POST['target_code']??'');(new ProductService($db))->transfer((int)$m[1],$source,$target);flash('success','Produsul a fost copiat pe magazinul destinatie si mapat in catalogul central.');redirect('/products/'.$m[1]);}

    if($path==='/api/notifications'&&$method==='GET'){
        // Keep this endpoint strictly fast. Heavy marketplace sync is never executed before the JSON response.
        $autoService=new AutoSyncService();$fallbackNeeded=$autoService->browserFallbackNeeded();
        header('Content-Type: application/json; charset=utf-8');$enabled=(int)$db->query("SELECT COALESCE(MAX(enabled),0) FROM modules WHERE slug='new-order-popup'")->fetchColumn();$u=Auth::user();$service=new NotificationService();
        $notifications=$enabled?$service->latestForUser((int)$u['id'],35):[];$popupItems=$enabled?$service->popupForUser((int)$u['id'],10):[];$unread=$enabled?$service->unreadCount((int)$u['id']):0;
        $poll=(int)$settings->get('module.new-order-popup.poll_seconds',(string)Env::get('NOTIFICATION_POLL_SECONDS','5'));$browser=$settings->bool('module.new-order-popup.browser_notifications',true);$markSeen=$settings->bool('module.new-order-popup.mark_seen_on_open',true);
        echo json_encode(['ok'=>true,'notifications'=>$notifications,'popup_items'=>$popupItems,'items'=>$popupItems,'unread_count'=>$unread,'poll_seconds'=>max(3,min(120,$poll)),'browser_notifications'=>$browser,'mark_seen_on_open'=>$markSeen,'auto_sync'=>['cron_active'=>$autoService->cronRecentlyActive(),'background_fallback'=>$fallbackNeeded]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    if(preg_match('#^/api/orders/(\d+)/opened$#',$path,$m)&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('orders.view');$user=Auth::user();$orderId=(int)$m[1];
        try{(new NotificationService($db))->markOrderRead((int)$user['id'],$orderId);}catch(Throwable){}
        if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        header('Content-Type: application/json; charset=utf-8');$warning='';
        try{$status=(new OrderOperations())->markOpenedProcessing($orderId);}catch(Throwable $e){$warning=$e->getMessage();try{$status=(string)((new OrderOperations())->getOrder($orderId)['status']??'');}catch(Throwable){$status='';}}
        echo json_encode(['ok'=>true,'status'=>$status,'warning'=>$warning],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($path==='/api/emag/product-link/search'&&$method==='GET'){
        Auth::requirePermission('orders.manage');header('Content-Type: application/json; charset=utf-8');
        try{$rows=(new EmagImageSyncService($db,$settings))->searchCentralProducts((string)($_GET['q']??''),20);echo json_encode(['ok'=>true,'results'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>Env::bool('APP_DEBUG')?$e->getMessage():'Cautarea produselor nu a putut fi efectuata.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        exit;
    }
    if(preg_match('#^/api/orders/(\d+)/items/(\d+)/emag-product-link$#',$path,$m)&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('orders.manage');header('Content-Type: application/json; charset=utf-8');
        try{$result=(new EmagImageSyncService($db,$settings))->linkOrderItemToProduct((int)$m[1],(int)$m[2],(int)($_POST['product_id']??0));echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        exit;
    }

    if(preg_match('#^/api/orders/(\d+)/items/(\d+)/manual-image$#',$path,$m)&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('orders.manage');header('Content-Type: application/json; charset=utf-8');
        try{$user=Auth::user();$result=(new EmagImageSyncService($db,$settings))->setManualOrderItemImage((int)$m[1],(int)$m[2],(array)($_FILES['image']??[]),(int)($user['id']??0));echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        exit;
    }

    if(preg_match('#^/api/orders/(\d+)/emag-images$#',$path,$m)&&$method==='POST'){
        // Product media is repaired in its own request. Release the PHP session first so
        // eMAG latency cannot freeze navigation or another tab from the same browser.
        Csrf::verify();Auth::requirePermission('orders.view');$orderId=(int)$m[1];
        if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        $force=isset($_POST['force'])&&filter_var((string)$_POST['force'],FILTER_VALIDATE_BOOL);
        try{$result=(new EmagImageSyncService($db,$settings))->syncOrder($orderId,$force);echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        catch(Throwable $e){http_response_code(502);echo json_encode(['ok'=>false,'message'=>'Media eMAG nu a putut fi actualizata acum.','error'=>Env::bool('APP_DEBUG')?$e->getMessage():null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
        exit;
    }
    if($path==='/api/background/order-sync'&&$method==='POST'){
        Csrf::verify();
        // Run in a separate request and release the session first so marketplace latency never freezes navigation/chat.
        if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        $result=(new AutoSyncService())->tick(false,false);
        echo json_encode(['ok'=>(bool)($result['ok']??false),'skipped'=>(bool)($result['skipped']??false)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
    if($path==='/api/notifications/read'&&$method==='POST'){
        Csrf::verify();header('Content-Type: application/json; charset=utf-8');$u=Auth::user();$service=new NotificationService();if(isset($_POST['all']))$service->markAllRead((int)$u['id']);else{$ids=$_POST['ids']??[];if(!is_array($ids))$ids=[$ids];$service->markRead((int)$u['id'],$ids);}echo json_encode(['ok'=>true,'unread_count'=>$service->unreadCount((int)$u['id'])]);exit;
    }

    if($path==='/modules'&&$method==='GET'){$moduleList=$modules->listing();$zipAvailable=class_exists(ZipArchive::class);View::render('modules',compact('moduleList','zipAvailable')+['title'=>'Module']);exit;}
    if(preg_match('#^/modules/([a-z0-9-]+)/install$#',$path,$m)&&$method==='POST'){Csrf::verify();Auth::requirePermission('modules.manage');$modules->install($m[1],false);flash('success','Modul instalat. Il poti activa acum.');redirect('/modules');}
    if(preg_match('#^/modules/([a-z0-9-]+)/(enable|disable)$#',$path,$m)&&$method==='POST'){Csrf::verify();Auth::requirePermission('modules.manage');$modules->setEnabled($m[1],$m[2]==='enable');flash('success',$m[2]==='enable'?'Modul activat.':'Modul dezactivat.');redirect('/modules');}
    if($path==='/modules/upload'&&$method==='POST'){Csrf::verify();Auth::requirePermission('modules.manage');$slug=$modules->uploadZip($_FILES['module_zip']??[]);flash('success','Modulul '.$slug.' a fost incarcat si instalat. Il poti activa dupa verificare.');redirect('/modules');}

    if($path==='/settings/general'&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('settings.manage');
        $appName=trim((string)($_POST['APP_NAME']??'ALFAMED CENTRAL'));$appUrl=rtrim(trim((string)($_POST['APP_URL']??'')),'/');$tz=trim((string)($_POST['APP_TIMEZONE']??'Europe/Bucharest'));if($appName==='')throw new RuntimeException('Numele aplicatiei este obligatoriu.');if(!filter_var($appUrl,FILTER_VALIDATE_URL))throw new RuntimeException('URL aplicatie invalid.');if(!in_array($tz,timezone_identifiers_list(),true))throw new RuntimeException('Fus orar invalid.');
        EnvEditor::update(['APP_NAME'=>$appName,'APP_URL'=>$appUrl,'APP_TIMEZONE'=>$tz,'APP_DEBUG'=>isset($_POST['APP_DEBUG'])?'true':'false']);
        $headerNavItems=[];foreach($modules->navItems() as $nav){$slug=(string)($nav['_module_slug']??'');$url=(string)($nav['url']??'');if($slug===''||$url==='')continue;$headerNavItems[$slug.'|'.$url]=['slug'=>$slug,'url'=>$url];}
        $postedHeaderLinks=array_values(array_unique(array_map('strval',(array)($_POST['HEADER_LINKS']??[]))));
        $headerAllowedKeys=array_values(array_intersect(array_keys($headerNavItems),$postedHeaderLinks));
        $headerAllowedModules=[];foreach($headerAllowedKeys as $navKey){$slug=(string)($headerNavItems[$navKey]['slug']??'');if($slug!=='')$headerAllowedModules[]=$slug;}$headerAllowedModules=array_values(array_unique($headerAllowedModules));
        $settings->setMany([
            'products.sync_max_pages'=>(string)max(1,min(250,(int)($_POST['PRODUCT_SYNC_MAX_PAGES']??100))),
            'products.sku_prefix'=>($skuPrefix=substr(strtoupper((string)(preg_replace('/[^A-Za-z0-9_-]+/','',trim((string)($_POST['PRODUCT_SKU_PREFIX']??'ON')))??'')),0,20))!==''?$skuPrefix:'ON',
            'ui.header_links'=>implode(',',$headerAllowedKeys),
            'ui.header_modules'=>implode(',',$headerAllowedModules),
            'products.auto_sync_enabled'=>isset($_POST['PRODUCT_AUTO_SYNC_ENABLED'])?'true':'false',
            'products.auto_sync_seconds'=>(string)max(120,min(86400,(int)($_POST['PRODUCT_POLL_SECONDS']??600))),
            'products.full_sync_seconds'=>(string)(max(1,min(168,(int)($_POST['PRODUCT_FULL_SYNC_HOURS']??6)))*3600),
            'ui.rows_per_page'=>(string)max(10,min(200,(int)($_POST['ORDERS_PER_PAGE']??50))), // legacy fallback
            'ui.orders_per_page'=>(string)max(10,min(200,(int)($_POST['ORDERS_PER_PAGE']??50))),
            'ui.products_per_page'=>(string)max(10,min(200,(int)($_POST['PRODUCTS_PER_PAGE']??50))),
            'orders.initial_import_days'=>(string)max(1,min(730,(int)($_POST['ORDER_INITIAL_IMPORT_DAYS']??180))),
            'orders.auto_sync_enabled'=>isset($_POST['ORDER_AUTO_SYNC_ENABLED'])?'true':'false',
            'orders.emag_poll_seconds'=>(string)max(15,min(3600,(int)($_POST['EMAG_POLL_SECONDS']??30))),
            'orders.woo_fallback_poll_seconds'=>(string)max(15,min(3600,(int)($_POST['WOO_POLL_SECONDS']??60))),
        ],'app');
        flash('success','Setarile generale au fost salvate.');redirect('/settings?tab=general');
    }

    $integrationDefinitions=[
        'emag_ro'=>['title'=>'eMAG Romania','description'=>'Comenzi si oferte Marketplace Romania. Autentificare Basic cu utilizator care are drepturi API.','enabled'=>'EMAG_RO_ENABLED','channel'=>'emag_ro','fields'=>[
            ['key'=>'EMAG_RO_USERNAME','label'=>'Utilizator / email API','type'=>'text','default'=>''],['key'=>'EMAG_RO_PASSWORD','label'=>'Parola utilizator API','type'=>'password','secret'=>true,'default'=>''],['key'=>'EMAG_RO_API_CODE','label'=>'API Code (informativ)','type'=>'password','secret'=>true,'default'=>'','help'=>'Se salveaza mascat pentru referinta/support. Nu este trimis in autentificarea Basic Auth.']]],
        'emag_bg'=>['title'=>'eMAG Bulgaria','description'=>'Comenzi si oferte Marketplace Bulgaria. Autentificare Basic cu utilizator care are drepturi API.','enabled'=>'EMAG_BG_ENABLED','channel'=>'emag_bg','fields'=>[
            ['key'=>'EMAG_BG_USERNAME','label'=>'Utilizator / email API','type'=>'text','default'=>''],['key'=>'EMAG_BG_PASSWORD','label'=>'Parola utilizator API','type'=>'password','secret'=>true,'default'=>''],['key'=>'EMAG_BG_API_CODE','label'=>'API Code (informativ)','type'=>'password','secret'=>true,'default'=>'','help'=>'Se salveaza mascat pentru referinta/support. Nu este trimis in autentificarea Basic Auth.']]],
        'univera'=>['title'=>'Univera.ro / WooCommerce','description'=>'Comenzi, produse, webhook-uri si sincronizare bidirectionala.','enabled'=>'WC_UNIVERA_ENABLED','channel'=>'univera','webhook'=>'univera','fields'=>[
            ['key'=>'WC_UNIVERA_BASE_URL','label'=>'URL magazin','type'=>'url','default'=>'https://www.univera.ro'],['key'=>'WC_UNIVERA_KEY','label'=>'Consumer Key','type'=>'text','default'=>''],['key'=>'WC_UNIVERA_SECRET','label'=>'Consumer Secret','type'=>'password','secret'=>true,'default'=>'']]],
        'alfamed'=>['title'=>'Alfamedclinic.ro / WooCommerce','description'=>'Comenzi, produse, webhook-uri si sincronizare bidirectionala.','enabled'=>'WC_ALFAMED_ENABLED','channel'=>'alfamed','webhook'=>'alfamed','fields'=>[
            ['key'=>'WC_ALFAMED_BASE_URL','label'=>'URL magazin','type'=>'url','default'=>'https://www.alfamedclinic.ro'],['key'=>'WC_ALFAMED_KEY','label'=>'Consumer Key','type'=>'text','default'=>''],['key'=>'WC_ALFAMED_SECRET','label'=>'Consumer Secret','type'=>'password','secret'=>true,'default'=>'']]],
    ];
    if(preg_match('#^/settings/integrations/(emag_ro|emag_bg)/test$#',$path,$m)&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('settings.manage');
        $result=(new EmagDiagnosticService($db,$settings))->run($m[1]);
        $type=($result['ok']??false)?'success':'error';
        $message=(string)($result['message']??'Testul conexiunii eMAG s-a incheiat.');
        if(!empty($result['public_ip']))$message.=' IP public server: '.(string)$result['public_ip'].'.';
        if(!empty($result['http_status']))$message.=' HTTP '.(int)$result['http_status'].'.';
        flash($type,$message);redirect('/settings/integrations/'.$m[1]);
    }
    if($path==='/settings/integrations/emag/test-all'&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('settings.manage');
        $svc=new EmagDiagnosticService($db,$settings);$results=[];
        foreach(['emag_ro','emag_bg'] as $code){$results[$code]=$svc->run($code);}
        $ok=count(array_filter($results,fn($r)=>!empty($r['ok'])));
        $parts=[];foreach($results as $code=>$r){$parts[]=($code==='emag_ro'?'RO':'BG').': '.(($r['ok']??false)?'OK':((string)($r['message']??'eroare')));}
        flash($ok===2?'success':'error','Diagnostic eMAG RO/BG finalizat. '.implode(' | ',$parts));
        redirect('/settings?tab=integrations');
    }

    if($path==='/settings/integrations/oblio'){
        $hasOblioSettings=false;
        foreach($modules->settingsSections() as $section){if(($section['_module_slug']??'')==='invoices-documents'){$hasOblioSettings=true;break;}}
        if($hasOblioSettings) redirect('/settings/modules/invoices-documents');
        flash('error','Oblio este acum modul. Activeaza modulul Oblio - Facturi & documente pentru a-l configura.');
        redirect('/modules');
    }
    if(preg_match('#^/settings/integrations/(emag_ro|emag_bg|univera|alfamed)$#',$path,$m)){
        $key=$m[1];$integration=$integrationDefinitions[$key];
        if($method==='POST'){
            Csrf::verify();Auth::requirePermission('settings.manage');$updates=[];$updates[$integration['enabled']]=isset($_POST['enabled'])?'true':'false';
            foreach($integration['fields'] as $field){$env=$field['key'];$value=trim((string)($_POST[$env]??''));if(!empty($field['secret'])&&$value==='')continue;if(($field['type']??'')==='url'&&$value!=='')$value=rtrim($value,'/');$updates[$env]=$value;}
            EnvEditor::update($updates);if(!empty($integration['channel'])){$st=$db->prepare('UPDATE channels SET enabled=? WHERE code=?');$st->execute([isset($_POST['enabled'])?1:0,$integration['channel']]);}
            flash('success','Setarile '.$integration['title'].' au fost salvate.');redirect('/settings/integrations/'.$key);
        }
        $webhookInfo=null;if(!empty($integration['webhook'])){$wh=new WooWebhookService();$webhookInfo=['url'=>$wh->endpointFor($integration['webhook']),'configured_at'=>$settings->get('webhook.'.$integration['webhook'].'.configured_at','')];}
        $emagDiagnostic=in_array($key,['emag_ro','emag_bg'],true)?(new EmagDiagnosticService($db,$settings))->last($key):null;
        $emagEndpoint=in_array($key,['emag_ro','emag_bg'],true)?(string)Env::get($key==='emag_bg'?'EMAG_BG_BASE_URL':'EMAG_RO_BASE_URL',$key==='emag_bg'?'https://marketplace-api.emag.bg/api-3':'https://marketplace-api.emag.ro/api-3'):'';
        View::render('settings_integration',compact('integration','key','webhookInfo','emagDiagnostic','emagEndpoint')+['title'=>'Setari '.$integration['title']]);exit;
    }
    if(preg_match('#^/settings/integrations/(univera|alfamed)/webhooks$#',$path,$m)&&$method==='POST'){
        Csrf::verify();Auth::requirePermission('settings.manage');$configured=(string)Env::get('APP_URL','');$host=(string)(parse_url($configured,PHP_URL_HOST)??'');if($host===''||in_array(strtolower($host),['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Webhook-urile necesita un APP_URL public HTTPS. Pe localhost functioneaza sincronizarea automata prin polling.');$st=$db->prepare("SELECT * FROM channels WHERE code=? AND type='woocommerce'");$st->execute([$m[1]]);$ch=$st->fetch();if(!$ch)throw new RuntimeException('Canal inexistent.');(new WooWebhookService())->configureChannel($ch);flash('success','Webhook-urile pentru comenzi si produse au fost configurate.');redirect('/settings/integrations/'.$m[1]);
    }

    if(preg_match('#^/settings/modules/([a-z0-9-]+)$#',$path,$m)){
        $slug=$m[1];$sections=array_values(array_filter($modules->settingsSections(),fn($x)=>(string)($x['_module_slug']??'')===$slug));if(!$sections)throw new RuntimeException('Modulul nu are setari sau nu este activ.');
        if($method==='POST'){
            Csrf::verify();Auth::requirePermission('settings.manage');
            $postedSlug=trim((string)($_POST['_module_slug']??''));
            if($postedSlug==='' || !hash_equals($slug,$postedSlug)) throw new RuntimeException('Formularul de setari nu corespunde modulului deschis. Reincarca pagina si incearca din nou.');
            $updates=[];
            foreach($sections as $section){foreach((array)($section['fields']??[]) as $field){$fieldKey=(string)($field['key']??'');$type=(string)($field['type']??'text');if($fieldKey===''||$type==='heading')continue;$full='module.'.$slug.'.'.$fieldKey;if($type==='checkbox')$value=isset($_POST['module'][$fieldKey])?'true':'false';else{$value=trim((string)($_POST['module'][$fieldKey]??''));if($type==='password'&&$value==='')continue;if(in_array($type,['number','emag_locality'],true)&&$value!==''){$min=(float)($field['min']??($type==='emag_locality'?1:-PHP_INT_MAX));$max=(float)($field['max']??PHP_INT_MAX);$value=(string)max($min,min($max,(float)$value));}}$updates[$full]=$value;}}
            $startedTx=false;
            try{
                if(!$db->inTransaction()){$db->beginTransaction();$startedTx=true;}
                foreach($updates as $full=>$value)$settings->set($full,$value,'module:'.$slug);
                foreach($updates as $full=>$value){$saved=(string)$settings->get($full,'__missing__');if($saved!==(string)$value)throw new RuntimeException('Verificarea salvarii a esuat pentru '.$full.'.');}
                if($startedTx)$db->commit();
            }catch(Throwable $e){if($startedTx&&$db->inTransaction())$db->rollBack();throw $e;}
            $moduleName=(string)($sections[0]['_module_name']??$slug);
            flash('success','Setarile '.$moduleName.' au fost salvate si verificate.');redirect('/settings/modules/'.$slug);
        }
        View::render('settings_module',compact('sections','settings')+['moduleSlug'=>$slug,'title'=>'Setari modul']);exit;
    }

    // Compatibilitate cu formularele din versiunile anterioare.
    if($path==='/settings/webhooks/setup'&&$method==='POST'){Csrf::verify();$result=(new WooWebhookService())->configureAll();flash('success','Webhook-urile WooCommerce au fost configurate.');redirect('/settings?tab=integrations');}
    if($path==='/settings'&&$method==='GET'){
        $integrations=[];foreach($integrationDefinitions as $key=>$def)$integrations[]=['key'=>$key,'name'=>$def['title'],'enabled'=>Env::bool($def['enabled']),'hint'=>$def['description']];
        $logs=$db->query('SELECT l.*,c.name channel_name FROM sync_logs l LEFT JOIN channels c ON c.id=l.channel_id ORDER BY l.id DESC LIMIT 80')->fetchAll();$moduleSettings=$modules->settingsSections();$tab=(string)($_GET['tab']??'general');
        $moduleCards=[];foreach($moduleSettings as $section){$slug=(string)($section['_module_slug']??'');if($slug!==''&&!isset($moduleCards[$slug]))$moduleCards[$slug]=['slug'=>$slug,'name'=>(string)($section['_module_name']??$slug),'description'=>(string)($section['description']??'')];}
        $headerLinkOptions=[];foreach($modules->navItems() as $nav){$slug=(string)($nav['_module_slug']??'');$url=(string)($nav['url']??'');if($slug===''||$url==='')continue;$key=$slug.'|'.$url;$headerLinkOptions[$key]=['key'=>$key,'slug'=>$slug,'module_name'=>(string)($nav['_module_name']??$slug),'label'=>(string)($nav['label']??'Modul'),'url'=>$url,'description'=>(string)($nav['description']??'')];}
        $headerLinksRaw=(string)$settings->get('ui.header_links','');
        if($headerLinksRaw!==''){$headerSelectedLinks=array_values(array_intersect(array_keys($headerLinkOptions),array_values(array_filter(explode(',',$headerLinksRaw)))));}
        else{$headerModuleRaw=(string)$settings->get('ui.header_modules','*');$headerSelectedModules=$headerModuleRaw==='*'?array_values(array_unique(array_map(fn($x)=>(string)$x['slug'],$headerLinkOptions))):array_values(array_filter(explode(',',$headerModuleRaw)));$headerSelectedLinks=[];foreach($headerLinkOptions as $key=>$item){if(in_array((string)$item['slug'],$headerSelectedModules,true))$headerSelectedLinks[]=$key;}}
        View::render('settings',compact('integrations','logs','moduleCards','settings','tab','headerLinkOptions','headerSelectedLinks')+['title'=>'Setari']);exit;
    }

    if($modules->handleRoute($path,$method,false))exit;
    http_response_code(404);echo '404 - pagina inexistenta';
} catch(Throwable $e){
    ActivityService::markCurrentFailed();
    if(Env::bool('APP_DEBUG'))flash('error',$e->getMessage());else flash('error','Operatiunea nu a putut fi efectuata. Verifica Jurnalul din Setari.');
    $ref=(string)($_SERVER['HTTP_REFERER']??'');if($ref!==''){header('Location: '.$ref);exit;}redirect('/');
}
