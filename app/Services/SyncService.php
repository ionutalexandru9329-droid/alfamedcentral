<?php
namespace App\Services;

use App\Core\Database;
use App\Core\ModuleManager;
use App\Core\Env;
use App\Integrations\EmagClient;
use App\Integrations\WooCommerceClient;
use PDO;
use Throwable;

final class SyncService {
    private PDO $db;
    public function __construct(?PDO $db=null){ $this->db=$db ?: Database::connection(); }

    public function syncAll(): array {
        $channels=$this->db->query('SELECT * FROM channels WHERE enabled=1 ORDER BY id')->fetchAll();
        $out=[];
        foreach($channels as $ch) $out[$ch['code']]=$this->syncChannel($ch);
        return $out;
    }

    public function syncOrdersOnly(bool $auto=false): array {
        $channels=$this->db->query('SELECT * FROM channels WHERE enabled=1 ORDER BY id')->fetchAll();
        $out=[];
        foreach($channels as $ch) $out[$ch['code']]=$this->syncChannelOrders($ch,$auto);
        return $out;
    }

    public function syncProductsOnly(): array {
        $channels=$this->db->query("SELECT * FROM channels WHERE enabled=1 AND type='woocommerce' ORDER BY id")->fetchAll();
        $out=[];
        foreach($channels as $ch){
            $client=ChannelFactory::client($ch['code']);
            if(!$client instanceof WooCommerceClient){$out[$ch['code']]=['ok'=>true,'skipped'=>true,'products'=>0];continue;}
            try{
                $settings=new SettingService($this->db);
                $pages=(int)$settings->get('products.sync_max_pages','100');
                $r=(new ProductService($this->db))->syncWooChannel($ch,$client,$pages);
                $this->log((int)$ch['id'],'success','product_sync',"Produse sincronizate: {$r['count']} ({$r['new']} noi)");
                $out[$ch['code']]=['ok'=>true,'products'=>$r['count'],'new_products'=>$r['new']];
            }catch(Throwable $e){$this->log((int)$ch['id'],'error','product_sync',$e->getMessage());$out[$ch['code']]=['ok'=>false,'error'=>$e->getMessage(),'products'=>0];}
        }
        return $out;
    }

    /** Fast manual product refresh for the UI. Full reconciliation stays on the scheduled worker. */
    public function syncProductsQuick(): array {
        $channels=$this->db->query("SELECT * FROM channels WHERE enabled=1 AND type='woocommerce' ORDER BY id")->fetchAll();
        $settings=new SettingService($this->db);$out=[];
        foreach($channels as $ch){
            $code=(string)$ch['code'];$client=ChannelFactory::client($code);
            if(!$client instanceof WooCommerceClient){$out[$code]=['ok'=>true,'skipped'=>true,'products'=>0];continue;}
            try{
                $last=(int)$settings->get('products.last_success.'.$code,(string)(time()-86400));
                $from=max(time()-172800,$last-300);
                $r=(new ProductService($this->db))->syncWooChannelIncremental($ch,$client,gmdate('Y-m-d\TH:i:s\Z',$from),5);
                if(($r['complete']??false))$settings->set('products.last_success.'.$code,(string)time(),'runtime');
                $settings->set('products.last_poll.'.$code,(string)time(),'runtime');
                $this->log((int)$ch['id'],'success','product_sync_quick',"Actualizare rapida produse: {$r['count']} ({$r['new']} noi)");
                $out[$code]=['ok'=>true,'products'=>(int)$r['count'],'new_products'=>(int)$r['new'],'deduplicated'=>(int)($r['deduplicated']??0),'complete'=>(bool)($r['complete']??false)];
            }catch(Throwable $e){$this->log((int)$ch['id'],'error','product_sync_quick',$e->getMessage());$out[$code]=['ok'=>false,'error'=>$e->getMessage(),'products'=>0];}
        }
        return $out;
    }

    public function syncChannelOrders(array $channel,bool $auto=false): array {
        $client=ChannelFactory::client($channel['code']);
        if(!$client){
            if(!$auto)$this->log((int)$channel['id'],'info','order_sync','Integrarea este dezactivata in Setari');
            return ['ok'=>true,'skipped'=>true,'count'=>0,'new'=>0];
        }
        try {
            $prev=$this->db->prepare("SELECT 1 FROM sync_logs WHERE channel_id=? AND level='success' AND action IN ('sync','order_sync','auto_order_sync') LIMIT 1");
            $prev->execute([(int)$channel['id']]);
            $hasPreviousSuccess=(bool)$prev->fetchColumn();
            $qLocal=$this->db->prepare('SELECT 1 FROM orders WHERE channel_id=? LIMIT 1');$qLocal->execute([(int)$channel['id']]);$hasLocalOrders=(bool)$qLocal->fetchColumn();
            $settings=new SettingService($this->db);$historyDays=max(1,min(730,(int)$settings->get('orders.initial_import_days','180')));
            $backfillKey='orders.backfill_v080.'.(string)$channel['code'];
            $backfillDaysKey='orders.backfill_v080_days.'.(string)$channel['code'];
            $backfillDaysDone=(int)$settings->get($backfillDaysKey,'0');
            $bootstrap=!$hasLocalOrders || (!$auto && (!$settings->bool($backfillKey,false) || $backfillDaysDone<$historyDays));
            $count=0;$newCount=0;$complete=true;$syncUntil=time();
            $lastSuccessKey='orders.last_success.'.(string)$channel['code'];
            if($client instanceof EmagClient){
                if($bootstrap){
                    [$rows,$complete]=$this->readEmagOrdersHistory($client,$historyDays,50);
                }else{
                    $lastSuccess=(int)$settings->get($lastSuccessKey,(string)(time()-900));
                    $from=max(time()-(3*86400),$lastSuccess-120); // 72h safety window + overlap protects missed browser/cron intervals
                    [$rows,$complete]=$this->readEmagOrdersWindow($client,date('Y-m-d H:i:s',$from),date('Y-m-d H:i:s',$syncUntil),10,'modified');
                }
                foreach($rows as $row){if(is_array($row)){$result=$this->upsertEmagOrder($channel,$row,$auto?($hasLocalOrders||$hasPreviousSuccess):($hasPreviousSuccess&&!$bootstrap));$count++;if($result['new']??false)$newCount++;}}
                if(!$complete)$this->log((int)$channel['id'],'warning','order_sync','Importul eMAG a atins limita de pagini. Sincronizarea va relua fereastra la urmatoarea rulare.');
            } elseif($client instanceof WooCommerceClient) {
                if($bootstrap){
                    $hours=$historyDays*24;$after=date(DATE_ATOM,strtotime('-'.$hours.' hours'));
                    [$rows,$complete]=$this->readWooOrdersWindow($client,$after,50);$seen=[];
                    foreach($rows as $row){if(is_array($row)){$eid=(string)($row['id']??'');if($eid!=='')$seen[$eid]=true;$result=$this->upsertWooOrder($channel,$row,$auto?($hasLocalOrders||$hasPreviousSuccess):($hasPreviousSuccess&&!$bootstrap));$count++;if($result['new']??false)$newCount++;}}
                    if($complete)$this->reconcileWooOrders((int)$channel['id'],$after,array_keys($seen));
                }else{
                    $lastSuccess=(int)$settings->get($lastSuccessKey,(string)(time()-900));
                    $from=max(time()-(3*86400),$lastSuccess-120);
                    [$rows,$complete]=$this->readWooOrdersModifiedWindow($client,date(DATE_ATOM,$from),10);
                    foreach($rows as $row){if(is_array($row)){$result=$this->upsertWooOrder($channel,$row,$hasLocalOrders||$hasPreviousSuccess);$count++;if($result['new']??false)$newCount++;}}
                }
            }
            if($bootstrap && $complete){$settings->set($backfillKey,'true','app');$settings->set($backfillDaysKey,(string)$historyDays,'app');}
            if($complete)$settings->set($lastSuccessKey,(string)$syncUntil,'runtime');
            $action=$auto?'auto_order_sync':'order_sync';
            if(!$auto || $newCount>0 || $bootstrap) $this->log((int)$channel['id'],'success',$action,"Comenzi sincronizate: {$count} ({$newCount} noi)".($bootstrap?' · backfill istoric':' · incremental'));
            return ['ok'=>true,'count'=>$count,'new'=>$newCount,'backfill'=>$bootstrap,'backfill_complete'=>(bool)$complete,'incremental'=>!$bootstrap];
        } catch(Throwable $e){
            $this->log((int)$channel['id'],'error',$auto?'auto_order_sync':'order_sync',$e->getMessage());
            return ['ok'=>false,'error'=>$e->getMessage(),'count'=>0,'new'=>0];
        }
    }

    /** Import/update one eMAG order fetched by ID (used by AWB self-healing/backfill). */
    public function ingestEmagOrder(array $channel,array $order,bool $notify=false,bool $suppressNotification=false): array {
        return $this->upsertEmagOrder($channel,$order,$notify,$suppressNotification);
    }

    /** Import/update a WooCommerce order received directly from a signed webhook. */
    public function ingestWooOrder(array $channel,array $order,bool $notify=true): array {
        $result=$this->upsertWooOrder($channel,$order,$notify);
        if(($result['new']??false)) $this->log((int)$channel['id'],'success','webhook_order','Comanda noua importata instant prin webhook: '.(string)($order['id']??''));
        return $result;
    }

    public function ingestWooOrderDeleted(array $channel,string|int $externalId): void {
        if((string)$externalId==='')return;
        $st=$this->db->prepare('UPDATE orders SET remote_deleted=1,updated_at=CURRENT_TIMESTAMP WHERE channel_id=? AND external_id=?');
        $st->execute([(int)$channel['id'],(string)$externalId]);
        $this->log((int)$channel['id'],'info','webhook_order_deleted','Comanda stearsa pe WooCommerce a fost ascunsa local: '.(string)$externalId);
    }

    public function syncChannel(array $channel): array {
        $client=ChannelFactory::client($channel['code']);
        if(!$client){
            $this->log((int)$channel['id'],'info','sync','Integrarea este dezactivata in Setari');
            return ['ok'=>true,'skipped'=>true,'count'=>0,'new'=>0,'products'=>0];
        }
        try {
            $prev=$this->db->prepare("SELECT COUNT(*) FROM sync_logs WHERE channel_id=? AND level='success' AND action='sync'");
            $prev->execute([(int)$channel['id']]);
            $hasPreviousSuccess=(int)$prev->fetchColumn()>0;
            $count=0;$newCount=0;$productCount=0;$newProducts=0;

            $settings=new SettingService($this->db);$historyDays=max(1,min(730,(int)$settings->get('orders.initial_import_days','180')));
            if($client instanceof EmagClient){
                [$rows,$complete]=$this->readEmagOrdersHistory($client,$historyDays,50);
                foreach($rows as $row){if(is_array($row)){$result=$this->upsertEmagOrder($channel,$row,false);$count++;if($result['new']??false)$newCount++;}}
            } else {
                $pages=(int)$settings->get('products.sync_max_pages','100');
                $pr=(new ProductService($this->db))->syncWooChannel($channel,$client,$pages);
                $productCount=(int)$pr['count'];$newProducts=(int)$pr['new'];
                $after=date(DATE_ATOM,strtotime('-'.$historyDays.' days'));[$rows,$complete]=$this->readWooOrdersWindow($client,$after,50);$seen=[];
                foreach($rows as $row){if(is_array($row)){$eid=(string)($row['id']??'');if($eid!=='')$seen[$eid]=true;$result=$this->upsertWooOrder($channel,$row,false);$count++;if($result['new']??false)$newCount++;}}
                if($complete)$this->reconcileWooOrders((int)$channel['id'],$after,array_keys($seen));
            }
            $this->log((int)$channel['id'],'success','sync',"Sincronizare reusita: {$count} comenzi ({$newCount} noi), {$productCount} produse ({$newProducts} noi)");
            return ['ok'=>true,'count'=>$count,'new'=>$newCount,'products'=>$productCount,'new_products'=>$newProducts];
        } catch(Throwable $e){
            $this->log((int)$channel['id'],'error','sync',$e->getMessage());
            return ['ok'=>false,'error'=>$e->getMessage(),'count'=>0,'new'=>0,'products'=>0];
        }
    }


    /**
     * Read eMAG history using the official order filters createdAfter/createdBefore.
     * eMAG limits the interval between them to maximum one month, therefore a long
     * initial import is split into overlapping 29-day windows and de-duplicated.
     */
    private function readEmagOrdersHistory(EmagClient $client,int $days,int $maxPagesPerWindow=20): array {
        $days=max(1,min(730,$days));$maxPagesPerWindow=max(1,min(100,$maxPagesPerWindow));
        $from=time()-($days*86400);$now=time();$allById=[];$complete=true;$cursor=$from;$guard=0;
        while($cursor<$now && $guard++<40){
            $end=min($now,$cursor+(29*86400));
            [$rows,$windowComplete]=$this->readEmagOrdersWindow($client,date('Y-m-d H:i:s',$cursor),date('Y-m-d H:i:s',$end),$maxPagesPerWindow);
            if(!$windowComplete)$complete=false;
            foreach($rows as $row){if(!is_array($row))continue;$id=(string)($row['id']??$row['order_id']??'');$key=$id!==''?$id:sha1(json_encode($row));$allById[$key]=$row;}
            if($end>=$now)break;
            // One-minute overlap prevents a boundary order from being lost if filters are strict.
            $cursor=max($cursor+1,$end-60);
        }
        return [array_values($allById),$complete];
    }

    /** Read every eMAG order page in a <= 1 month interval. */
    private function readEmagOrdersWindow(EmagClient $client,string $from,string $to,int $maxPages=20,string $mode='created'): array {
        $all=[];$complete=false;$maxPages=max(1,min(100,$maxPages));
        $afterKey=$mode==='modified'?'modifiedAfter':'createdAfter';$beforeKey=$mode==='modified'?'modifiedBefore':'createdBefore';
        for($page=1;$page<=$maxPages;$page++){
            $res=$client->readOrders([$afterKey=>$from,$beforeKey=>$to,'currentPage'=>$page,'itemsPerPage'=>100]);
            $rows=(array)($res['results']??[]);foreach($rows as $row)if(is_array($row))$all[]=$row;
            if(count($rows)<100){$complete=true;break;}
            $pages=(int)($res['pages']??$res['totalPages']??$res['pagination']['pages']??0);if($pages>0&&$page>=$pages){$complete=true;break;}
        }
        return [$all,$complete];
    }

    /** Read every WooCommerce order page in the requested window without treating a truncated result as complete. */
    private function readWooOrdersWindow(WooCommerceClient $client,string $after,int $maxPages=10): array {
        $all=[];$complete=false;$maxPages=max(1,min(50,$maxPages));
        for($page=1;$page<=$maxPages;$page++){
            try{$rows=$client->readOrders(['after'=>$after,'page'=>$page]);}
            catch(\RuntimeException $e){if($page>1&&str_contains($e->getMessage(),'rest_post_invalid_page_number')){$complete=true;break;}throw $e;}
            if(!$rows){$complete=true;break;}
            foreach($rows as $row)if(is_array($row))$all[]=$row;
            if(count($rows)<100){$complete=true;break;}
        }
        return [$all,$complete];
    }


    private function readWooOrdersModifiedWindow(WooCommerceClient $client,string $modifiedAfter,int $maxPages=10): array {
        $all=[];$complete=false;$maxPages=max(1,min(50,$maxPages));
        for($page=1;$page<=$maxPages;$page++){
            try{$rows=$client->readOrders(['modified_after'=>$modifiedAfter,'dates_are_gmt'=>'false','page'=>$page]);}
            catch(\RuntimeException $e){if($page>1&&str_contains($e->getMessage(),'rest_post_invalid_page_number')){$complete=true;break;}throw $e;}
            if(!$rows){$complete=true;break;}
            foreach($rows as $row)if(is_array($row))$all[]=$row;
            if(count($rows)<100){$complete=true;break;}
        }
        return [$all,$complete];
    }

    private function upsertEmagOrder(array $channel,array $o,bool $notifyAllNew,bool $suppressNotification=false): array {
        $statusMap=[0=>'cancelled',1=>'new',2=>'processing',3=>'prepared',4=>'completed',5=>'returned'];
        $external=(string)($o['id']??$o['order_id']??''); if($external==='') return ['id'=>0,'new'=>false];
        $normalized=EmagOrderNormalizer::normalize($o,$channel);
        $customer=(string)$normalized['customer_name'];$email=(string)$normalized['email'];$phone=(string)$normalized['phone'];
        $billing=(array)$normalized['billing'];$shipping=(array)$normalized['shipping'];
        $total=EmagOrderNormalizer::total($o);$ordered=$o['date']??$o['created']??$o['date_created']??date('Y-m-d H:i:s');$status=$statusMap[(int)($o['status']??1)]??(string)($o['status']??'new');
        // "Stornata" is a local financial state for eMAG, not the marketplace's Returned status.
        // Preserve it across marketplace polling so a later sync does not silently undo a storno.
        $existingStatus=$this->db->prepare('SELECT status FROM orders WHERE channel_id=? AND external_id=? LIMIT 1');
        $existingStatus->execute([(int)$channel['id'],$external]);
        if((string)($existingStatus->fetchColumn()?:'')==='refunded')$status='refunded';
        $result=$this->upsertOrder((int)$channel['id'],$external,$status,(string)($o['currency']??$channel['currency']),$total,(string)$customer,(string)$email,(string)$phone,$billing,$shipping,$o,(string)$ordered);
        $this->replaceItems($result['id'],$o['products']??$o['items']??[],(int)$channel['id'],(string)$channel['code']);
        if(!$suppressNotification&&$result['new']&&($notifyAllNew||$this->isRecentOrder((string)$ordered)))(new ModuleManager($this->db))->dispatch('order.created',['order_id'=>$result['id'],'channel_id'=>(int)$channel['id'],'channel_code'=>$channel['code']]);
        return $result;
    }

    private function upsertWooOrder(array $channel,array $o,bool $notifyAllNew): array {
        $external=(string)($o['id']??'');if($external==='')return ['id'=>0,'new'=>false];$b=WooOrderNormalizer::enrichBilling((array)($o['billing']??[]),$o);$s=$o['shipping']??[];
        $name=trim(($b['first_name']??'').' '.($b['last_name']??''));if(!empty($b['company']))$name=$b['company'].' - '.$name;
        $ordered=(string)($o['date_created']??date('Y-m-d H:i:s'));
        $result=$this->upsertOrder((int)$channel['id'],$external,(string)($o['status']??'new'),(string)($o['currency']??$channel['currency']),(float)($o['total']??0),$name,(string)($b['email']??''),(string)($b['phone']??''),$b,$s,$o,$ordered);
        $this->replaceItems($result['id'],$o['line_items']??[],(int)$channel['id'],(string)$channel['code']);
        if($result['new']&&($notifyAllNew||$this->isRecentOrder($ordered)))(new ModuleManager($this->db))->dispatch('order.created',['order_id'=>$result['id'],'channel_id'=>(int)$channel['id'],'channel_code'=>$channel['code']]);
        return $result;
    }

    private function upsertOrder(int $channelId,string $external,string $status,string $currency,float $total,string $name,string $email,string $phone,array $billing,array $shipping,array $raw,string $ordered): array {
        $q=$this->db->prepare('SELECT id FROM orders WHERE channel_id=? AND external_id=?');$q->execute([$channelId,$external]);$existing=$q->fetchColumn();
        if($existing){
            $st=$this->db->prepare('UPDATE orders SET status=?,remote_deleted=0,currency=?,total=?,customer_name=?,customer_email=?,customer_phone=?,billing_json=?,shipping_json=?,raw_json=?,ordered_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $st->execute([$status,$currency,$total,$name,$email,$phone,json_encode($billing),json_encode($shipping),json_encode($raw),$ordered,$existing]);
            return ['id'=>(int)$existing,'new'=>false];
        }
        $next=(int)$this->db->query('SELECT COALESCE(MAX(id),0)+1 FROM orders')->fetchColumn();$code='AC-'.str_pad((string)$next,6,'0',STR_PAD_LEFT);
        $st=$this->db->prepare('INSERT INTO orders(code,channel_id,external_id,status,remote_deleted,currency,total,customer_name,customer_email,customer_phone,billing_json,shipping_json,raw_json,ordered_at) VALUES(?,?,?,?,0,?,?,?,?,?,?,?,?,?)');
        $st->execute([$code,$channelId,$external,$status,$currency,$total,$name,$email,$phone,json_encode($billing),json_encode($shipping),json_encode($raw),$ordered]);
        return ['id'=>(int)$this->db->lastInsertId(),'new'=>true];
    }

    private function reconcileWooOrders(int $channelId,string $after,array $seen): void {
        $seenMap=array_fill_keys(array_map('strval',$seen),true);$threshold=date('Y-m-d H:i:s',strtotime($after));
        $st=$this->db->prepare('SELECT id,external_id FROM orders WHERE channel_id=? AND remote_deleted=0 AND ordered_at>=?');$st->execute([$channelId,$threshold]);
        foreach($st->fetchAll() as $row){if(!isset($seenMap[(string)$row['external_id']]))$this->db->prepare('UPDATE orders SET remote_deleted=1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['id']]);}
    }

    private function replaceItems(int $orderId,array $items,int $channelId,string $channelCode): void {
        $isEmag=str_starts_with($channelCode,'emag_');
        // Preserve identifiers across refreshes. For eMAG we deliberately do NOT preserve
        // arbitrary image URLs from the central WooCommerce catalogue: only emag_product_media
        // is an authoritative visual source for an eMAG order.
        $mediaCache=[];
        try{
            $old=$this->db->prepare('SELECT external_item_id,sku,remote_product_id,image_url,product_url FROM order_items WHERE order_id=?');$old->execute([$orderId]);
            foreach($old->fetchAll() as $row){
                $value=['remote_product_id'=>(string)($row['remote_product_id']??''),'image_url'=>(string)($row['image_url']??''),'product_url'=>(string)($row['product_url']??'')];
                $key='id:'.(string)($row['external_item_id']??'');if($key!=='id:')$mediaCache[$key]=$value;
                $skuKey='sku:'.(string)($row['sku']??'');if($skuKey!=='sku:')$mediaCache[$skuKey]=$value;
            }
        }catch(\Throwable){}
        $this->db->prepare('DELETE FROM order_items WHERE order_id=?')->execute([$orderId]);
        $st=$this->db->prepare('INSERT INTO order_items(order_id,external_item_id,remote_product_id,product_id,sku,name,qty,unit_price,vat_rate,image_url,product_url) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        foreach($items as $i){
            if(!is_array($i))continue;
            $externalProductId=(string)($i['product_id']??$i['id']??'');
            $remoteProductId=$isEmag?trim((string)($i['product_id']??$i['product_offer_id']??$i['offer_id']??'')):'';
            $sku=$isEmag
                ? (string)($i['part_number']??$i['sku']??$externalProductId)
                : (string)($i['sku']??$i['part_number']??$externalProductId);
            $pid=null;$image='';$url='';

            // channel_products contains WooCommerce catalogue media. It must never be used as
            // an image/link fallback for an eMAG order even when the SKU happens to match.
            if(!$isEmag&&$externalProductId!==''){
                $q=$this->db->prepare('SELECT cp.product_id,cp.permalink,cp.image_json FROM channel_products cp WHERE cp.channel_id=? AND cp.external_id=? LIMIT 1');$q->execute([$channelId,$externalProductId]);$map=$q->fetch();
                if($map){$pid=(int)$map['product_id'];$url=(string)($map['permalink']??'');$imgs=json_decode((string)($map['image_json']??'[]'),true)?:[];$image=(string)($imgs[0]['src']??'');}
            }
            if(!$pid&&$sku!==''){$p=$this->db->prepare('SELECT id FROM products WHERE sku=?');$p->execute([$sku]);$pid=$p->fetchColumn()?:null;}

            $lineId=(string)($i['id']??$externalProductId);$cached=$mediaCache['id:'.$lineId]??$mediaCache['sku:'.$sku]??null;
            if(is_array($cached)){
                if($remoteProductId==='')$remoteProductId=(string)($cached['remote_product_id']??'');
                if(!$isEmag){if($image==='')$image=(string)($cached['image_url']??'');if($url==='')$url=(string)($cached['product_url']??'');}
                elseif($this->isManualEmagMediaUrl((string)($cached['image_url']??'')))$image=(string)$cached['image_url'];
            }
            if(!$isEmag&&isset($i['image']['src']))$image=(string)$i['image']['src'];
            if($url===''&&!$isEmag&&in_array($channelCode,['univera','alfamed'],true)&&$externalProductId!==''){
                $base=$channelCode==='univera'?(string)Env::get('WC_UNIVERA_BASE_URL','https://www.univera.ro'):(string)Env::get('WC_ALFAMED_BASE_URL','https://www.alfamedclinic.ro');
                $url=rtrim($base,'/').'/?post_type=product&p='.rawurlencode($externalProductId);
            }
            if($isEmag&&$remoteProductId!==''){
                // Only media whose identity/provenance can be proved is allowed back into order_items.
                // This prevents a stale neighboring-product thumbnail from reappearing during order sync.
                try{
                    $m=$this->db->prepare('SELECT local_url,source_url,product_url,last_error FROM emag_product_media WHERE channel_id=? AND remote_product_id=? LIMIT 1');$m->execute([$channelId,$remoteProductId]);$media=$m->fetch();
                    if(is_array($media)){
                        $expectedPnk='';foreach(['part_number_key','pnk','product_part_number_key','emag_part_number_key'] as $key){$v=trim((string)($i[$key]??''));if($v!==''){$expectedPnk=$v;break;}}
                        $verified=$this->verifiedEmagMedia($channelId,$media,$expectedPnk);
                        if($verified['image_url']!=='')$image=$verified['image_url'];
                        if($verified['product_url']!=='')$url=$verified['product_url'];
                    }
                }catch(\Throwable){}
            }
            $name=(string)($i['name']??$i['product_name']??('Produs '.$sku));
            if($isEmag){
                // Search pages and non-eMAG URLs are not product hyperlinks.
                $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
                $validHost=in_array($host,['www.emag.ro','emag.ro','www.emag.bg','emag.bg'],true);$validDirect=$validHost&&(bool)preg_match('#/pd/[^/]+/?#i',$path);
                if(!$validDirect)$url='';
                if($url==='')$url=$this->emagDirectProductUrl($channelCode,$i,$name);
            }
            $qty=(float)($i['quantity']??1);$vat=$isEmag?EmagOrderNormalizer::vatPercent($i['vat']??$i['vat_rate']??0):(float)($i['vat']??$i['vat_rate']??19);$price=$isEmag?EmagOrderNormalizer::grossPrice($i):(float)($i['price']??$i['sale_price']??0);
            $st->execute([$orderId,$lineId,$remoteProductId!==''?$remoteProductId:null,$pid,$sku,$name,$qty,$price,$vat,$image?:null,$url?:null]);
        }
    }

    private function emagDirectProductUrl(string $channelCode,array $item,string $name): string {
        $pnk='';
        foreach(['part_number_key','pnk','product_part_number_key','emag_part_number_key'] as $key){$v=trim((string)($item[$key]??''));if($v!==''){$pnk=$v;break;}}
        $wanted=strtoupper($pnk);$bg=$channelCode==='emag_bg';$allowed=$bg?['emag.bg','www.emag.bg']:['emag.ro','www.emag.ro'];
        $urlKeys=['product_url','emag_product_url','url','web_url','public_url','permalink'];
        foreach($urlKeys as $key){
            $candidate=trim((string)($item[$key]??''));if($candidate===''||!filter_var($candidate,FILTER_VALIDATE_URL))continue;
            $host=strtolower((string)(parse_url($candidate,PHP_URL_HOST)??''));$path=(string)(parse_url($candidate,PHP_URL_PATH)??'');
            if(!in_array($host,$allowed,true)||!preg_match('~/pd/([^/?#]+)/?~i',$path,$m))continue;
            $found=strtoupper(trim(rawurldecode((string)$m[1])));if($wanted===''||hash_equals($wanted,$found))return $candidate;
        }
        if($pnk==='')return '';
        $base=$bg?'https://www.emag.bg':'https://www.emag.ro';
        return $base.($bg?'/product/pd/':'/produs/pd/').rawurlencode($pnk).'/';
    }


    private function isLocalEmagMediaUrl(string $url): bool {
        $path=(string)(parse_url(trim($url),PHP_URL_PATH)??'');$base='/uploads/emag-media/';$pos=strpos($path,$base);if($pos===false)return false;
        $name=basename(substr($path,$pos+strlen($base)));if($name===''||$name==='.'||$name==='..')return false;
        $file=dirname(__DIR__,2).'/public/uploads/emag-media/'.$name;return is_file($file)&&filesize($file)>32;
    }

    private function isManualEmagMediaUrl(string $url): bool {
        $path=(string)(parse_url(trim($url),PHP_URL_PATH)??'');$base='/uploads/emag-media/';$pos=strpos($path,$base);if($pos===false)return false;$name=basename(substr($path,$pos+strlen($base)));return str_starts_with($name,'manual-')&&$this->isLocalEmagMediaUrl($url);
    }

    private function isOfficialEmagImageUrl(string $url): bool {
        $url=trim($url);if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
        if($host===''||!str_contains($path,'/products/'))return false;
        if((bool)preg_match('/^s\d+emagst\.akamaized\.net$/i',$host))return true;
        if(str_contains($host,'emagcdn'))return true;
        return str_ends_with($host,'.akamaized.net')&&str_contains($host,'emag');
    }

    private function extractEmagPnk(string $url): string {
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');
        return preg_match('~/pd/([^/?#]+)/?~i',$path,$m)?strtoupper(trim(rawurldecode((string)$m[1]))):'';
    }

    private function safeCatalogueLinkExists(int $channelId,string $pnk): bool {
        if($pnk==='')return false;
        try{
            $st=$this->db->prepare('SELECT mapping_source,match_reason FROM emag_product_links WHERE channel_id=? AND UPPER(pnk)=UPPER(?) LIMIT 1');$st->execute([$channelId,$pnk]);$row=$st->fetch();
            if(!is_array($row))return false;if((string)($row['mapping_source']??'')==='manual')return true;
            return in_array((string)($row['match_reason']??''),['ean','sku_ean','sku_name'],true);
        }catch(\Throwable){return false;}
    }

    /** @return array{image_url:string,product_url:string} */
    private function verifiedEmagMedia(int $channelId,array $media,string $expectedPnk=''): array {
        $productUrl=trim((string)($media['product_url']??''));if(!$this->isDirectEmagProductUrl($productUrl))return ['image_url'=>'','product_url'=>''];
        $pnk=$this->extractEmagPnk($productUrl);$expectedPnk=strtoupper(trim($expectedPnk));
        if($pnk===''||($expectedPnk!==''&&!hash_equals($expectedPnk,$pnk)))return ['image_url'=>'','product_url'=>''];
        $local=trim((string)($media['local_url']??''));$source=trim((string)($media['source_url']??''));
        if(str_starts_with((string)($media['last_error']??''),'[MANUAL_IMAGE')&&$this->isLocalEmagMediaUrl($local))return ['image_url'=>$local,'product_url'=>$productUrl];
        if(str_starts_with((string)($media['last_error']??''),'[CATALOG_EXACT]')&&$this->safeCatalogueLinkExists($channelId,$pnk)&&$this->isLocalEmagMediaUrl($local))return ['image_url'=>$local,'product_url'=>$productUrl];
        if($this->isOfficialEmagImageUrl($source)){
            if($this->isLocalEmagMediaUrl($local))return ['image_url'=>$local,'product_url'=>$productUrl];
            return ['image_url'=>$source,'product_url'=>$productUrl];
        }
        return ['image_url'=>'','product_url'=>$productUrl];
    }

    private function isTrustedEmagImageUrl(string $url): bool {
        return $this->isLocalEmagMediaUrl($url)||$this->isOfficialEmagImageUrl($url);
    }

    private function isDirectEmagProductUrl(string $url): bool {
        if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
        return in_array($host,['emag.ro','www.emag.ro','emag.bg','www.emag.bg'],true)&&(bool)preg_match('#/pd/[^/]+/?#i',$path);
    }

    private function isRecentOrder(string $value): bool { $ts=strtotime($value);return $ts!==false&&$ts>=time()-600; }
    private function log(?int $channelId,string $level,string $action,string $message,array $context=[]): void { $st=$this->db->prepare('INSERT INTO sync_logs(channel_id,level,action,message,context_json) VALUES(?,?,?,?,?)');$st->execute([$channelId,$level,$action,$message,$context?json_encode($context):null]); }
}
