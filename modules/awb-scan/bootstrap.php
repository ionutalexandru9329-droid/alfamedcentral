<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Integrations\WooCommerceClient;
use App\Services\ChannelFactory;
use App\Services\OrderOperations;
use App\Services\SettingService;
use App\Services\SyncService;
$scanCandidates=static function(string $raw):array{
    $raw=trim($raw);$values=[];
    $push=static function(string $v) use (&$values):void{$v=trim($v);if($v!==''&&strlen($v)<=1000&&!in_array($v,$values,true))$values[]=$v;};
    $walkJson=null;
    $walkJson=static function(mixed $node,string $key='') use (&$walkJson,$push):void{
        if(is_array($node)){foreach($node as $k=>$v)$walkJson($v,is_string($k)?$k:'');return;}
        if(!is_scalar($node))return;$text=trim((string)$node);if($text==='')return;
        if($key===''||(bool)preg_match('/(?:awb|waybill|barcode|tracking|order.?id|external.?id|invoice)/i',$key))$push($text);
    };
    $push($raw);$decoded=urldecode($raw);$push($decoded);
    // Barcode readers may prepend AIM symbology identifiers (]C1, ]Q3 etc.) and
    // QR/GS1 payloads can contain ASCII group separators.
    $clean=preg_replace('/^\][A-Za-z0-9]{2}/','',$decoded)??$decoded;
    $clean=preg_replace('/[\x00-\x1F\x7F]+/',' ',$clean)??$clean;$push($clean);
    $json=json_decode($decoded,true);if(is_array($json))$walkJson($json);
    if(preg_match_all('/(?:awb(?:_number|_barcode)?|waybill|tracking(?:_number)?|order(?:_id)?|external(?:_id)?|invoice)\s*[:=]\s*["\']?([A-Za-z0-9._\/-]{5,120})/i',$clean,$kv))foreach($kv[1] as $v)$push((string)$v);
    $parts=@parse_url($decoded);
    if(is_array($parts)){
        if(!empty($parts['query'])){parse_str((string)$parts['query'],$q);$walkJson($q);}
        if(!empty($parts['path'])){$segments=array_values(array_filter(explode('/',trim((string)$parts['path'],'/'))));foreach(array_reverse($segments) as $segment)$push((string)$segment);}
    }
    if(preg_match_all('/[A-Za-z0-9][A-Za-z0-9._-]{5,120}/',$clean,$m))foreach($m[0] as $token)$push((string)$token);
    foreach(array_values($values) as $v){$compact=preg_replace('/[^A-Za-z0-9]/','',$v)??'';if(strlen($compact)>=6)$push($compact);}
    // Do not let JSON/query field names (awb_barcode, order_id, etc.) become
    // fake scan candidates. In older builds those short tokens could be tried
    // against the remote AWB history before the real barcode from the same QR.
    $noise=['AWB','AWBNUMBER','AWBBARCODE','BARCODE','WAYBILL','TRACKING','TRACKINGNUMBER','ORDER','ORDERID','EXTERNALID','INVOICE'];
    $values=array_values(array_filter($values,static function(string $value) use ($noise):bool{
        $compact=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');
        return $compact!==''&&!in_array($compact,$noise,true);
    }));
    // Put an actual eMAG label token before raw QR payloads or generic IDs.
    $score=static function(string $value):int{
        $compact=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$value)??'');
        if((bool)preg_match('/^[0-9]*EMG[A-Z0-9]{8,}$/',$compact))return 0;
        if(str_contains($compact,'EMGLN'))return 0;
        if((bool)preg_match('/(?:EMG|GLN)/',$compact))return 1;
        if((bool)preg_match('/^[A-Z0-9]{8,40}$/',$compact))return 2;
        return 3;
    };
    usort($values,static function(string $a,string $b) use ($score):int{
        $sa=$score($a);$sb=$score($b);return $sa<=>$sb ?: strlen($a)<=>strlen($b);
    });
    return array_slice(array_values(array_unique($values)),0,50);
};


$wooTrackingTokens=static function(array $order):array{
    $found=[];
    $normalize=static fn(string $v):string=>strtoupper(preg_replace('/[^A-Za-z0-9]/','',$v)??'');
    $add=static function(string $value,string $provider='WooCommerce') use (&$found,$normalize):void{
        $value=trim($value);if($value===''||strlen($value)>220)return;
        $norm=$normalize($value);if(strlen($norm)<6)return;
        // Exclude obvious dates/timestamps and bare numeric database IDs.
        if(preg_match('/^20\d{6,12}$/',$norm))return;
        $found[$norm]=['token'=>$value,'courier'=>trim($provider)!==''?trim($provider):'WooCommerce'];
    };
    $walk=null;
    $walk=static function(mixed $value,string $key='',bool $trackingContext=false,string $provider='WooCommerce') use (&$walk,$add):void{
        $lk=strtolower($key);
        $tokenKey=(bool)preg_match('/(?:^|_)(?:awb(?:_number|_code)?|tracking(?:_number|_code)?|waybill(?:_number)?|consignment(?:_number)?)(?:$|_)/i',$key);
        $container=$trackingContext||(bool)preg_match('/(?:shipment.?tracking|tracking.?items|tracking.?info|awb)/i',$key);
        if(is_array($value)){
            $localProvider=$provider;
            foreach(['tracking_provider','custom_tracking_provider','courier_name','courier','provider'] as $pk){
                if(isset($value[$pk])&&is_scalar($value[$pk])&&trim((string)$value[$pk])!==''){$localProvider=trim((string)$value[$pk]);break;}
            }
            foreach($value as $k=>$v){
                $childKey=is_string($k)?$k:'';
                $walk($v,$childKey,$container,$localProvider);
            }
            return;
        }
        if(!is_scalar($value))return;$text=trim((string)$value);if($text==='')return;
        if($tokenKey){$add($text,$provider);return;}
        if($container&&preg_match('/(?:url|link)$/i',$lk)){
            $parts=@parse_url($text);if(is_array($parts)){
                if(!empty($parts['query'])){parse_str((string)$parts['query'],$q);foreach($q as $v)if(is_scalar($v))$add((string)$v,$provider);}
                if(!empty($parts['path'])){$seg=array_values(array_filter(explode('/',trim((string)$parts['path'],'/'))));if($seg)$add((string)end($seg),$provider);}
            }
        }
    };
    $walk($order);
    return array_values($found);
};

$storeWooTracking=static function(\PDO $db,int $orderId,array $tokens,array $raw=[]):int{
    if($orderId<=0||!$tokens)return 0;$stored=0;
    foreach($tokens as $item){
        $awb=trim((string)($item['token']??''));if($awb==='')continue;$courier=trim((string)($item['courier']??'WooCommerce'))?:'WooCommerce';
        $st=$db->prepare("SELECT id,order_id FROM shipments WHERE awb=? OR awb_barcode=? ORDER BY id DESC LIMIT 1");$st->execute([$awb,$awb]);$existing=$st->fetch();
        if($existing){if((int)$existing['order_id']!==$orderId)continue;$stored++;continue;}
        try{
            $meta=['source'=>'woocommerce_remote','tracking'=>$item,'remote'=>$raw];
            $ins=$db->prepare('INSERT INTO shipments(order_id,courier,awb,awb_barcode,status,tracking_url,raw_json) VALUES(?,?,?,?,?,?,?)');
            $ins->execute([$orderId,$courier,$awb,$awb,'created',null,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$stored++;
        }catch(Throwable){}
    }
    return $stored;
};

$lookupWooTrackingLocal=static function(\PDO $db,string $candidate) use ($wooTrackingTokens,$storeWooTracking):array|false{
    $needle=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$candidate)??'');if(strlen($needle)<6)return false;
    $like='%'.$candidate.'%';
    $sql="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type FROM orders o JOIN channels c ON c.id=o.channel_id WHERE c.type='woocommerce' AND o.raw_json LIKE ? ORDER BY o.id DESC LIMIT 30";
    try{$st=$db->prepare($sql);$st->execute([$like]);$rows=$st->fetchAll();}catch(Throwable){$rows=[];}
    foreach($rows as $row){
        $raw=json_decode((string)($row['raw_json']??'{}'),true)?:[];$tokens=$wooTrackingTokens($raw);
        foreach($tokens as $t){$norm=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($t['token']??''))??'');if($norm!==$needle)continue;$storeWooTracking($db,(int)$row['id'],$tokens,$raw);$row['awb']=(string)$t['token'];$row['awb_barcode']=(string)$t['token'];$row['courier']=(string)($t['courier']??'WooCommerce');return $row;}
    }
    return false;
};

$recoverWooTrackingLive=static function(\PDO $db,string $candidate) use ($wooTrackingTokens,$storeWooTracking):array|false{
    $needle=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$candidate)??'');if(strlen($needle)<6)return false;
    $channels=$db->query("SELECT * FROM channels WHERE type='woocommerce' AND enabled=1 ORDER BY id")->fetchAll();
    foreach($channels as $channel){
        try{$client=ChannelFactory::client((string)$channel['code']);if(!$client instanceof WooCommerceClient)continue;$rows=$client->readOrders(['orderby'=>'modified','order'=>'desc','per_page'=>100]);}catch(Throwable){continue;}
        foreach($rows as $remote){
            if(!is_array($remote))continue;$tokens=$wooTrackingTokens($remote);$matched=null;
            foreach($tokens as $t){$norm=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($t['token']??''))??'');if($norm===$needle){$matched=$t;break;}}
            if(!$matched)continue;
            $external=(string)($remote['id']??'');if($external==='')continue;$q=$db->prepare('SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.channel_id=? AND o.external_id=? LIMIT 1');$q->execute([(int)$channel['id'],$external]);$local=$q->fetch();
            if(!$local){try{$result=(new SyncService())->ingestWooOrder($channel,$remote,false);$q->execute([(int)$channel['id'],$external]);$local=$q->fetch();}catch(Throwable){$local=false;}}
            if(!$local)continue;$storeWooTracking($db,(int)$local['id'],$tokens,$remote);$local['awb']=(string)$matched['token'];$local['awb_barcode']=(string)$matched['token'];$local['courier']=(string)($matched['courier']??'WooCommerce');return $local;
        }
    }
    return false;
};

$lookupLocalOrder=static function(\PDO $db,string $candidate):array|false{
    $candidate=trim($candidate);if($candidate==='')return false;
    $compact=strtoupper(preg_replace('/[^A-Za-z0-9]/','',$candidate)??'');
    $normSql=static fn(string $expr):string=>"UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($expr,''),'-',''),' ',''),'.',''),'/',''),'_',''))";

    $sql="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,s.awb,s.awb_barcode,s.courier
          FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id
          WHERE s.status NOT IN ('deleted','cancelled') AND (s.awb=? OR s.awb_barcode=? OR s.provider_awb_id=? OR ".$normSql('s.awb')."=? OR ".$normSql('s.awb_barcode')."=? OR ".$normSql('s.provider_awb_id')."=?)
          ORDER BY s.id DESC LIMIT 1";
    $st=$db->prepare($sql);$st->execute([$candidate,$candidate,$candidate,$compact,$compact,$compact]);$row=$st->fetch();if($row)return $row;

    // Eticheta eMAG tipareste frecvent AWB-ul de baza + sufixul coletului (001, 002...).
    // Exemplu real: AWB baza = 4EMGLN187446944, codul scanat = 4EMGLN187446944001.
    // Inainte de fuzzy-prefix, incercam exact baza fara cele 3 cifre, numai pentru coduri
    // alfanumerice care contin litere. Asa evitam taierea arbitrara a AWB-urilor numerice.
    if(strlen($compact)>=11&&preg_match('/^(.+[A-Z].*?)(\d{3})$/',$compact,$m)&&strlen($m[1])>=8){
        $base=$m[1];
        $sqlBase="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,s.awb,s.awb_barcode,s.courier
                  FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id
                  WHERE s.status NOT IN ('deleted','cancelled') AND (".$normSql('s.awb')."=? OR ".$normSql('s.awb_barcode')."=? OR ".$normSql('s.provider_awb_id')."=?)
                  ORDER BY s.id DESC LIMIT 2";
        $stBase=$db->prepare($sqlBase);$stBase->execute([$base,$base,$base]);$baseRows=$stBase->fetchAll();if(count($baseRows)===1)return $baseRows[0];
    }

    // eMAG awb_barcode may append a short package suffix to awb_number. Older
    // ALFAMED CENTRAL rows can therefore contain only the shorter number. Accept
    // a prefix relation only when it produces a single active shipment and the
    // difference is small, avoiding unsafe fuzzy matches.
    if(strlen($compact)>=8){
        $driver=(string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $awbNorm=$normSql('s.awb');$barcodeNorm=$normSql('s.awb_barcode');
        $prefixAwb=$driver==='mysql'?"? LIKE CONCAT($awbNorm,'%') OR $awbNorm LIKE CONCAT(?,'%')":"? LIKE $awbNorm || '%' OR $awbNorm LIKE ? || '%'";
        $prefixBarcode=$driver==='mysql'?"? LIKE CONCAT($barcodeNorm,'%') OR $barcodeNorm LIKE CONCAT(?,'%')":"? LIKE $barcodeNorm || '%' OR $barcodeNorm LIKE ? || '%'";
        $sql="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,s.awb,s.awb_barcode,s.courier
              FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id
              WHERE s.status NOT IN ('deleted','cancelled') AND ((($prefixAwb) AND ABS(LENGTH($awbNorm)-LENGTH(?)) BETWEEN 1 AND 5) OR (($prefixBarcode) AND ABS(LENGTH($barcodeNorm)-LENGTH(?)) BETWEEN 1 AND 5))
              ORDER BY s.id DESC LIMIT 2";
        $st=$db->prepare($sql);$st->execute([$compact,$compact,$compact,$compact,$compact,$compact]);$rows=$st->fetchAll();if(count($rows)===1)return $rows[0];
    }

    $st=$db->prepare("SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,NULL awb,NULL awb_barcode,NULL courier FROM orders o JOIN channels c ON c.id=o.channel_id WHERE UPPER(o.code)=UPPER(?) OR o.external_id=? OR ".$normSql('o.code')."=? OR ".$normSql('o.external_id')."=? ORDER BY o.id DESC LIMIT 1");
    $st->execute([$candidate,$candidate,$compact,$compact]);$row=$st->fetch();if($row)return $row;

    $driver=(string)$db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    $seriesNumber=$driver==='mysql'?"CONCAT(COALESCE(i.series,''),COALESCE(i.number,''))":"COALESCE(i.series,'') || COALESCE(i.number,'')";
    $sql="SELECT o.*,c.name channel_name,c.code channel_code,c.type channel_type,
                 (SELECT s2.awb FROM shipments s2 WHERE s2.order_id=o.id AND s2.status NOT IN ('deleted','cancelled') ORDER BY s2.id DESC LIMIT 1) awb,
                 (SELECT s3.awb_barcode FROM shipments s3 WHERE s3.order_id=o.id AND s3.status NOT IN ('deleted','cancelled') ORDER BY s3.id DESC LIMIT 1) awb_barcode,
                 (SELECT s4.courier FROM shipments s4 WHERE s4.order_id=o.id AND s4.status NOT IN ('deleted','cancelled') ORDER BY s4.id DESC LIMIT 1) courier
          FROM invoices i JOIN orders o ON o.id=i.order_id JOIN channels c ON c.id=o.channel_id
          WHERE i.status NOT IN ('deleted','cancelled','hidden') AND (i.number=? OR ".$normSql('i.number')."=? OR ".$normSql($seriesNumber)."=?)
          ORDER BY i.id DESC LIMIT 1";
    $st=$db->prepare($sql);$st->execute([$candidate,$compact,$compact]);$row=$st->fetch();return $row?:false;
};

$clearWooTracking=static function(array $shipment):?string{
    if((string)($shipment['channel_type']??'')!=='woocommerce')return null;
    try{
        $client=ChannelFactory::client((string)$shipment['channel_code']);
        if(!$client instanceof WooCommerceClient)return 'Integrarea WooCommerce este dezactivata; tracking-ul extern nu a putut fi curatat.';
        $remote=$client->readOrder((string)$shipment['external_id']);
        $keys=['alfamed_awb','alfamed_courier','alfamed_tracking_url','alfamed_parcels','alfamed_weight_kg'];$updates=[];
        foreach((array)($remote['meta_data']??[]) as $meta){$key=(string)($meta['key']??'');if(!in_array($key,$keys,true))continue;$row=['key'=>$key,'value'=>''];if(!empty($meta['id']))$row['id']=(int)$meta['id'];$updates[]=$row;}
        if($updates)$client->updateOrder((string)$shipment['external_id'],['meta_data'=>$updates]);
        $raw=json_decode((string)($shipment['raw_json']??'{}'),true)?:[];$noteId=(int)($raw['woocommerce']['note_id']??0);
        if($noteId>0){try{$client->deleteOrderNote((string)$shipment['external_id'],$noteId);}catch(Throwable){} }
        return null;
    }catch(Throwable $e){return $e->getMessage();}
};

return [
    'nav'=>[
        ['label'=>'AWB','url'=>'/awb','permission'=>'awb.view'],
        ['label'=>'Scanare','url'=>'/scan','permission'=>'awb.view'],
    ],
    'listeners'=>[
        'autosync.channel.completed'=>static function(array $payload) use ($wooTrackingTokens,$storeWooTracking):void{
            $channel=(array)($payload['channel']??[]);if(empty($channel['enabled']))return;$type=(string)($channel['type']??'');$code=(string)($channel['code']??'');if($code==='')return;$db=Database::connection();$settings=new SettingService($db);
            // eMAG AWB auto-import was removed: the Marketplace API does not expose a reliable
            // order -> existing AWB lookup for seller-issued labels. WooCommerce tracking reconciliation is kept.
            if($type==='woocommerce'){
                // Historical local backfill: walk ALL WooCommerce orders missing a shipment,
                // not only the newest 250 rows. Most courier plugins expose tracking/AWB in
                // order meta_data, which is already stored in raw_json by the order importer.
                try{
                    $version=(string)$settings->get('runtime.woo_awb_sync.backfill_version.'.$code,'');
                    if($version!=='2'){
                        $settings->set('runtime.woo_awb_sync.cursor.'.$code,'0','runtime');
                        $settings->set('runtime.woo_awb_sync.done.'.$code,'0','runtime');
                        $settings->set('runtime.woo_awb_sync.backfill_version.'.$code,'2','runtime');
                    }
                    $done=$settings->bool('runtime.woo_awb_sync.done.'.$code,false);
                    $completedAt=(int)$settings->get('runtime.woo_awb_sync.completed_at.'.$code,'0');
                    if($done&&$completedAt>0&&(time()-$completedAt)<43200)return;
                    if($done){$settings->set('runtime.woo_awb_sync.done.'.$code,'0','runtime');$settings->set('runtime.woo_awb_sync.cursor.'.$code,'0','runtime');}
                    $cursor=(int)$settings->get('runtime.woo_awb_sync.cursor.'.$code,'0');
                    $whereCursor=$cursor>0?' AND o.id<?':'';$params=[(int)$channel['id']];if($cursor>0)$params[]=$cursor;
                    $sql="SELECT o.id,o.external_id,o.raw_json FROM orders o WHERE o.channel_id=? AND o.remote_deleted=0".$whereCursor." AND NOT EXISTS (SELECT 1 FROM shipments s WHERE s.order_id=o.id AND s.status NOT IN ('deleted','cancelled')) ORDER BY o.id DESC LIMIT 300";
                    $st=$db->prepare($sql);$st->execute($params);$rows=$st->fetchAll();$lastId=0;$liveChecked=0;
                    foreach($rows as $row){
                        $lastId=(int)$row['id'];$raw=json_decode((string)($row['raw_json']??'{}'),true)?:[];$tokens=$wooTrackingTokens($raw);
                        if($tokens){$storeWooTracking($db,(int)$row['id'],$tokens,$raw);continue;}
                        // A small live probe covers old orders whose AWB was created after the
                        // local raw_json snapshot. Keep the batch small on shared hosting.
                        if($liveChecked<12){
                            $liveChecked++;
                            try{$client=ChannelFactory::client($code);if($client instanceof WooCommerceClient){$remote=$client->readOrder((string)$row['external_id']);$tokens=$wooTrackingTokens($remote);if($tokens)$storeWooTracking($db,(int)$row['id'],$tokens,$remote);}}catch(Throwable){}
                        }
                    }
                    if($rows){$settings->set('runtime.woo_awb_sync.cursor.'.$code,(string)$lastId,'runtime');}
                    else{$settings->set('runtime.woo_awb_sync.done.'.$code,'1','runtime');$settings->set('runtime.woo_awb_sync.completed_at.'.$code,(string)time(),'runtime');$settings->set('runtime.woo_awb_sync.cursor.'.$code,'0','runtime');}
                }catch(Throwable){}
            }
        },
    ],
    'routes'=>[
        ['method'=>'GET','path'=>'/awb','handler'=>static function():void{
            Auth::requirePermission('awb.view');$db=Database::connection();
            $shipments=$db->query('SELECT s.*,o.code,o.customer_name,c.name channel_name,c.code channel_code,c.type channel_type,o.external_id FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id ORDER BY s.id DESC LIMIT 500')->fetchAll();
            View::render('shipments',compact('shipments')+['title'=>'AWB']);
        }],
        ['method'=>'GET','path'=>'/scan','handler'=>static function():void{
            Auth::requirePermission('awb.view');$settings=new SettingService();
            $cameraEnabled=$settings->bool('module.awb-scan.camera_enabled',true);$cameraFacing=(string)$settings->get('module.awb-scan.camera_facing','environment');
            View::render('scan',compact('cameraEnabled','cameraFacing')+['title'=>'Scanare AWB']);
        }],
        ['method'=>'GET','path'=>'/api/scan','handler'=>static function() use ($scanCandidates,$lookupLocalOrder,$lookupWooTrackingLocal):void{
            Auth::requirePermission('awb.view');
            // Scanarea trebuie sa fie instant. Nu chemam eMAG/WooCommerce din requestul
            // scannerului: cautarea foloseste exclusiv datele deja salvate local.
            if(session_status()===PHP_SESSION_ACTIVE) @session_write_close();
            $db=Database::connection();header('Content-Type: application/json; charset=utf-8');$term=trim((string)($_GET['q']??''));
            if($term===''){echo json_encode(['ok'=>false,'message'=>'Cod gol']);return;}$o=false;$matched='';$candidates=$scanCandidates($term);
            foreach($candidates as $candidate){$o=$lookupLocalOrder($db,$candidate);if($o){$matched=$candidate;break;}}
            if(!$o){foreach($candidates as $candidate){$o=$lookupWooTrackingLocal($db,$candidate);if($o){$matched=$candidate;break;}}}
            if(!$o){echo json_encode(['ok'=>false,'message'=>'Nu am gasit comanda in baza locala dupa codul scanat. Verifica daca AWB-ul este salvat in ALFAMED CENTRAL.']);return;}
            echo json_encode(['ok'=>true,'redirect'=>app_path('/orders/'.$o['id']),'matched'=>$matched,'order'=>['id'=>$o['id'],'code'=>$o['code'],'channel'=>$o['channel_name'],'customer'=>$o['customer_name'],'status'=>$o['status'],'awb'=>$o['awb'],'awb_barcode'=>$o['awb_barcode']??'','courier'=>$o['courier'],'total'=>$o['total'],'currency'=>$o['currency']]],JSON_UNESCAPED_UNICODE);exit;
        }],
        ['method'=>'POST','path'=>'#^/orders/(\\d+)/shipment$#','handler'=>static function(array $m):void{
            Csrf::verify();Auth::requirePermission('orders.manage');$db=Database::connection();$id=(int)$m[1];$order=(new OrderOperations())->getOrder($id);if((string)($order['channel_type']??'')==='emag')throw new RuntimeException('Pentru comenzile eMAG foloseste fluxul AWB eMAG Marketplace, nu introducerea manuala.');
            $active=$db->prepare("SELECT id FROM shipments WHERE order_id=? AND status NOT IN ('deleted','cancelled') LIMIT 1");$active->execute([$id]);if($active->fetchColumn())throw new RuntimeException('Comanda are deja un AWB activ. Sterge AWB-ul existent inainte de a introduce altul.');
            $awb=trim((string)($_POST['awb']??''));if($awb==='')throw new RuntimeException('AWB este obligatoriu.');$settings=new SettingService($db);$courier=trim((string)($_POST['courier']??''));if($courier==='')$courier=trim((string)$settings->get('module.awb-scan.default_courier','Curier'))?:'Curier';
            $st=$db->prepare('INSERT INTO shipments(order_id,courier,awb,status,tracking_url) VALUES(?,?,?,?,?)');$st->execute([$id,$courier,$awb,'created',trim((string)($_POST['tracking_url']??''))?:null]);
            flash('success','AWB salvat si disponibil la scanare.');redirect('/orders/'.$id);
        }],
        ['method'=>'POST','path'=>'#^/awb/(\\d+)/delete$#','handler'=>static function(array $m) use ($clearWooTracking):void{
            Csrf::verify();Auth::requirePermission('orders.manage');$db=Database::connection();$shipmentId=(int)$m[1];
            $st=$db->prepare('SELECT s.*,o.external_id,o.code,c.code channel_code,c.type channel_type FROM shipments s JOIN orders o ON o.id=s.order_id JOIN channels c ON c.id=o.channel_id WHERE s.id=?');$st->execute([$shipmentId]);$shipment=$st->fetch();if(!$shipment)throw new RuntimeException('AWB-ul nu exista.');
            if(in_array((string)$shipment['status'],['deleted','cancelled'],true)){flash('success','AWB-ul era deja sters.');redirect('/orders/'.$shipment['order_id']);}
            if((string)($shipment['channel_type']??'')==='emag') throw new RuntimeException('AWB-ul eMAG nu poate fi sters doar local. Corecteaza/anuleaza expedierea in eMAG Marketplace pentru a evita dublurile.');
            $db->prepare("UPDATE shipments SET status='deleted',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$shipmentId]);$warning=$clearWooTracking($shipment);
            if($warning)flash('error','AWB sters din ALFAMED CENTRAL si poate fi inlocuit. Tracking-ul WooCommerce nu a putut fi curatat: '.$warning);else flash('success','AWB sters. Poti genera sau introduce acum un AWB nou.');
            $back=(string)($_POST['back']??'order');redirect($back==='awb'?'/awb':'/orders/'.(int)$shipment['order_id']);
        }],
    ],
    'renderers'=>[
        'order.operations'=>static function(array $ctx):void{
            if(!Auth::can('orders.manage'))return;$order=$ctx['order']??[];if(!$order)return;
            // Comenzile eMAG sunt administrate integral de modulul Curieri. Evitam un al
            // doilea card AWB si pastram pagina comenzii aerisita.
            if((string)($order['channel_type']??'')==='emag')return;
            $db=Database::connection();$st=$db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('deleted','cancelled') ORDER BY id DESC");$st->execute([(int)$order['id']]);$active=$st->fetchAll();
            echo '<div class="module-box awb-order-box"><h3>AWB / colet</h3>';
            if($active){
                foreach($active as $shipment){echo '<div class="awb-active-row"><div><small>AWB activ</small><strong>'.View::e($shipment['awb']).'</strong><span>'.View::e($shipment['courier']).'</span></div><form method="post" action="'.View::e(app_path('/awb/'.(int)$shipment['id'].'/delete')).'" onsubmit="return confirm(&quot;Stergi acest AWB pentru a-l putea inlocui?&quot;)">'.Csrf::field().'<input type="hidden" name="back" value="order"><button class="danger small" type="submit">Sterge AWB</button></form></div>';}
                echo '<p class="module-help">Dupa stergere poti genera sau introduce un AWB nou. Anularea efectiva la curier poate necesita o actiune separata.</p>';
            } else echo '<form method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/shipment')).'" class="stack">'.Csrf::field().'<input name="courier" placeholder="Curier" required><input name="awb" placeholder="Numar AWB" required><input name="tracking_url" placeholder="Link tracking (optional)"><button>Salveaza AWB</button></form>';
            echo '</div>';
        },
    ],
    'settings'=>[ [
        'title'=>'AWB & Scanare colete','description'=>'Identifica rapid comanda dupa AWB, cod comanda, ID extern sau factura si deschide direct comanda asociata.',
        'fields'=>[
            ['key'=>'camera_enabled','label'=>'Permite scanarea cu camera','type'=>'checkbox','default'=>'true'],
            ['key'=>'camera_facing','label'=>'Camera preferata','type'=>'select','default'=>'environment','options'=>['environment'=>'Camera spate / externa','user'=>'Camera fata']],
            ['key'=>'default_courier','label'=>'Curier implicit pentru AWB introdus manual','type'=>'text','default'=>''],
        ],
    ] ],
];
