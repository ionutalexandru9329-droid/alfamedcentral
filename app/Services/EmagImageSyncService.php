<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Http;
use App\Integrations\EmagClient;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Synchronizes eMAG product images through the authenticated Marketplace API.
 *
 * The order page never waits for eMAG. Images are downloaded server-side into
 * public/uploads/emag-media and order_items only point to that local cache.
 */
final class EmagImageSyncService {
    private PDO $db;
    private SettingService $settings;
    /** @var array<string,array|null> Request-local PNK offer cache. */
    private array $pnkOfferCache=[];
    /** @var array{diagnostic?:string,http_status?:int} Last public storefront fetch state. */
    private array $publicFetchDiagnostic=[];

    public function __construct(?PDO $db=null,?SettingService $settings=null){
        $this->db=$db ?: Database::connection();
        $this->settings=$settings ?: new SettingService($this->db);
    }

    /** Synchronize a small, due batch for one eMAG channel. Intended for CRON. */
    public function syncDueForChannel(array $channel,int $limit=12,bool $force=false): array {
        if((string)($channel['type']??'')!=='emag')return ['checked'=>0,'updated'=>0,'unchanged'=>0,'errors'=>0,'skipped'=>'not_emag'];
        $channelId=(int)($channel['id']??0);$code=(string)($channel['code']??'');
        if($channelId<=0||$code==='')return ['checked'=>0,'updated'=>0,'unchanged'=>0,'errors'=>1,'error'=>'Canal eMAG invalid.'];
        $client=ChannelFactory::client($code);
        if(!$client instanceof EmagClient)return ['checked'=>0,'updated'=>0,'unchanged'=>0,'errors'=>1,'error'=>'Integrarea eMAG nu este activa.'];
        $this->backfillMissingRemoteProductIds($channelId,100);
        $historyBackfill=$this->backfillMissingRemoteProductIdsFromApi($channel,$client,4,false);
        $interval=max(3600,min(604800,(int)$this->settings->get('media.emag_image_sync_seconds','21600')));$limit=max(1,min(40,$limit));
        $sql="SELECT oi.remote_product_id,MAX(o.ordered_at) latest_order,m.checked_at,m.local_url,m.source_url,m.product_url
              FROM order_items oi JOIN orders o ON o.id=oi.order_id
              LEFT JOIN emag_product_media m ON m.channel_id=o.channel_id AND m.remote_product_id=oi.remote_product_id
              WHERE o.channel_id=? AND o.remote_deleted=0 AND oi.remote_product_id IS NOT NULL AND oi.remote_product_id<>''
              GROUP BY oi.remote_product_id,m.checked_at,m.local_url,m.source_url,m.product_url
              ORDER BY CASE WHEN m.local_url IS NULL OR m.local_url='' OR m.local_url NOT LIKE '%/uploads/emag-media/%' THEN 0 ELSE 1 END,
                       CASE WHEN m.product_url IS NULL OR m.product_url='' OR m.product_url LIKE '%/search/%' OR m.product_url NOT LIKE '%/pd/%' THEN 0 ELSE 1 END,
                       CASE WHEN m.checked_at IS NULL OR m.checked_at='' THEN 0 ELSE 1 END,
                       m.checked_at ASC,latest_order DESC LIMIT 1000";
        $st=$this->db->prepare($sql);$st->execute([$channelId]);$rows=$st->fetchAll();$summary=['checked'=>0,'updated'=>0,'unchanged'=>0,'errors'=>0,'history_backfill'=>$historyBackfill];
        foreach($rows as $row){
            if($summary['checked']>=$limit)break;$remoteId=trim((string)($row['remote_product_id']??''));if($remoteId==='')continue;
            $expectedPnk=$this->extractPnk((string)($row['product_url']??''));
            // Keep the due scan cheap: exact order/API identifiers are resolved only for the
            // handful of rows that are actually due, inside syncProduct().
            $checkedAt=strtotime((string)($row['checked_at']??''));$verified=$this->mediaRowIsVerified($channelId,$row,$expectedPnk);$hasDirectLink=$this->isEmagProductUrl(trim((string)($row['product_url']??'')));
            $needsRepair=!$verified||!$hasDirectLink;$rowInterval=$needsRepair?min($interval,300):$interval;
            if(!$force&&$checkedAt!==false&&(time()-$checkedAt)<$rowInterval)continue;
            $summary['checked']++;
            try{$r=$this->syncProduct($channel,$client,$remoteId,$force);if(!empty($r['updated']))$summary['updated']++;else $summary['unchanged']++;}
            catch(Throwable $e){$summary['errors']++;$this->recordFailure($channelId,$remoteId,$e->getMessage());}
        }
        return $summary;
    }

    /** Synchronize images for one order in a background AJAX request. */
    public function syncOrder(int $orderId,bool $force=false): array {
        $st=$this->db->prepare('SELECT o.id,o.channel_id,c.code,c.type,c.country FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.id=? AND o.remote_deleted=0 LIMIT 1');$st->execute([$orderId]);$order=$st->fetch();
        if(!$order)throw new RuntimeException('Comanda nu exista.');
        if((string)$order['type']!=='emag')return ['ok'=>true,'checked'=>0,'items'=>$this->orderItemState($orderId)];
        $channel=['id'=>(int)$order['channel_id'],'code'=>(string)$order['code'],'type'=>'emag','country'=>(string)$order['country']];$client=ChannelFactory::client((string)$order['code']);
        $linkHydration=$this->hydrateKnownProductLinks($orderId,$channel,6,$force,$client instanceof EmagClient?$client:null);$this->backfillOrderRemoteProductIds($orderId);
        if(!$client instanceof EmagClient)return ['ok'=>true,'checked'=>(int)($linkHydration['checked']??0),'link_hydration'=>$linkHydration,'items'=>$this->orderItemState($orderId)];
        $recovery=null;if($this->missingRemoteProductCount($orderId)>0)$recovery=$this->recoverOrderRemoteProductIdsFromApi($orderId,$channel,$client,true);
        $interval=max(3600,min(604800,(int)$this->settings->get('media.emag_image_sync_seconds','21600')));
        $q=$this->db->prepare('SELECT DISTINCT remote_product_id FROM order_items WHERE order_id=? AND remote_product_id IS NOT NULL AND remote_product_id<>\'\'');$q->execute([$orderId]);$ids=array_values(array_filter(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN))));
        $checked=0;$errors=[];
        foreach($ids as $remoteId){
            $media=$this->mediaRow((int)$order['channel_id'],$remoteId);$last=strtotime((string)($media['checked_at']??''));$hint=$this->offerHint((int)$order['channel_id'],$remoteId);$expectedPnk=trim((string)($hint['pnk']??''));if($expectedPnk==='')$expectedPnk=$this->extractPnk((string)($media['product_url']??''));
            $verified=$this->mediaRowIsVerified((int)$order['channel_id'],$media,$expectedPnk);$hasDirect=$this->isEmagProductUrl((string)($media['product_url']??''));$rowInterval=(!$verified||!$hasDirect)?min($interval,300):$interval;
            if(!$force&&$last!==false&&(time()-$last)<$rowInterval)continue;
            try{$this->syncProduct($channel,$client,$remoteId,$force);$checked++;}catch(Throwable $e){$checked++;$errors[$remoteId]=$e->getMessage();$this->recordFailure((int)$order['channel_id'],$remoteId,$e->getMessage());}
        }
        return ['ok'=>true,'checked'=>$checked+(int)($linkHydration['checked']??0),'link_hydration'=>$linkHydration,'recovery'=>$recovery,'errors'=>$errors,'items'=>$this->orderItemState($orderId)];
    }

    /** Resolve rows that already have an exact eMAG PNK. API/order payload only; no title/catalog guessing. */
    private function hydrateKnownProductLinks(int $orderId,array $channel,int $limit=3,bool $force=false,?EmagClient $client=null): array {
        $limit=max(1,min(6,$limit));$summary=['checked'=>0,'updated'=>0,'errors'=>0,'reused'=>0,'official_api'=>0];
        $sql='SELECT oi.id,oi.sku,oi.name,oi.remote_product_id,oi.image_url item_image_url,oi.product_url item_product_url,m.local_url,m.source_url,m.product_url media_product_url,m.image_hash,m.synced_at,m.checked_at,m.last_error FROM order_items oi LEFT JOIN emag_product_media m ON m.channel_id=? AND m.remote_product_id=oi.remote_product_id WHERE oi.order_id=? ORDER BY oi.id';
        $st=$this->db->prepare($sql);$st->execute([(int)$channel['id'],$orderId]);
        foreach($st->fetchAll() as $row){
            if($summary['checked']>=$limit)break;$remoteId=trim((string)($row['remote_product_id']??''));if($remoteId!==''&&$client instanceof EmagClient)continue;
            $productUrl=trim((string)($row['media_product_url']??''));if(!$this->isEmagProductUrl($productUrl))$productUrl=trim((string)($row['item_product_url']??''));if(!$this->isEmagProductUrl($productUrl))continue;$pnk=$this->extractPnk($productUrl);if($pnk==='')continue;
            $existing=$remoteId!==''?$this->mediaRow((int)$channel['id'],$remoteId):[];$itemManual=$this->isManualLocalUrl((string)($row['item_image_url']??''));if(($remoteId!==''&&$this->isManualMediaRow($existing)&&$this->localCacheFileExists((string)($existing['local_url']??'')))||($remoteId===''&&$itemManual)){$summary['reused']++;continue;}if(!$force&&$remoteId!==''&&$this->mediaRowIsVerified((int)$channel['id'],$existing,$pnk))continue;
            $summary['checked']++;$cacheId=$remoteId!==''?$remoteId:'order-item-'.(int)$row['id'];
            try{
                if(!$force){$cached=$this->cachedPnkMedia((int)$channel['id'],$productUrl,$remoteId);if($cached){$display=(string)$cached['local_url'];$canonical=(string)($cached['product_url']??$productUrl);$source=(string)($cached['source_url']??'');if($remoteId!==''){$this->saveMedia((int)$channel['id'],$remoteId,$source,$display,(string)($cached['image_hash']??''),$canonical,(string)($cached['synced_at']??date('Y-m-d H:i:s')),date('Y-m-d H:i:s'),$this->mediaProvenanceMarker($cached));$this->propagateToOrderItems((int)$channel['id'],$remoteId,$display,$canonical);}else{$up=$this->db->prepare('UPDATE order_items SET image_url=?,product_url=? WHERE id=?');$up->execute([$display,$canonical,(int)$row['id']]);}$summary['updated']++;$summary['reused']++;continue;}}
                $source='';$canonical=$productUrl;
                if($client instanceof EmagClient){$offer=$this->offerByPnkCached($client,$pnk);if($offer&&strtoupper($this->offerPartNumberKey($offer))===strtoupper($pnk)){$source=$this->mainImageUrl($offer);$u=$this->offerPublicUrl($offer,$channel,(string)($offer['name']??''));if($this->isEmagProductUrl($u)&&strtoupper($this->extractPnk($u))===strtoupper($pnk))$canonical=$u;}if($this->isSafeCatalogueImageUrl($source))$summary['official_api']++;else $source='';}
                $provenance=null;$catalogueProductId=0;
                if($source===''){
                    $catalogue=$this->catalogueImageForExactSkuName((string)($row['sku']??''),(string)($row['name']??''));
                    $source=trim((string)($catalogue['image_url']??''));$catalogueProductId=(int)($catalogue['product_id']??0);
                    if($source!==''&&$catalogueProductId>0){$provenance='[CATALOG_EXACT]';$this->saveProductLink((int)$channel['id'],$pnk,$catalogueProductId,'auto','sku_name',(string)($row['sku']??''),(string)($row['name']??''));$this->attachProductToPnkItems((int)$channel['id'],$pnk,$catalogueProductId);}
                }
                if($source==='')continue;
                $official=$this->isOfficialEmagImageUrl($source);$download=$official?$this->downloadToLocalCache($source,(string)$channel['code'],$cacheId,$existing):$this->downloadGenericImageToLocalCache($source,(string)$channel['code'],$cacheId,$existing);$display=(string)$download['url'];$hash=(string)$download['hash'];$now=date('Y-m-d H:i:s');if($provenance===null&&!$official)$provenance='[EMAG_API_EXACT]';if($remoteId!==''){$this->saveMedia((int)$channel['id'],$remoteId,$source,$display,$hash,$canonical,$now,$now,$provenance);$this->propagateToOrderItems((int)$channel['id'],$remoteId,$display,$canonical);}else{$up=$this->db->prepare('UPDATE order_items SET image_url=?,product_url=?,product_id=COALESCE(?,product_id) WHERE id=?');$up->execute([$display,$canonical,$catalogueProductId>0?$catalogueProductId:null,(int)$row['id']]);}$summary['updated']++;
            }catch(Throwable){$summary['errors']++;}
        }
        return $summary;
    }

    private function cachedPnkMedia(int $channelId,string $productUrl,string $excludeRemoteId=''): array {
        $pnk=$this->extractPnk($productUrl);if($pnk==='')return [];
        try{
            $sql="SELECT * FROM emag_product_media WHERE channel_id=? AND local_url IS NOT NULL AND local_url<>'' AND product_url IS NOT NULL AND product_url<>''";
            $args=[$channelId];if($excludeRemoteId!==''){$sql.=" AND remote_product_id<>?";$args[]=$excludeRemoteId;}
            $sql.=" AND product_url LIKE ? ORDER BY updated_at DESC LIMIT 20";$args[]='%/pd/'.$pnk.'%';
            $st=$this->db->prepare($sql);$st->execute($args);
            foreach($st->fetchAll() as $row){
                if(strtoupper($this->extractPnk((string)($row['product_url']??'')))!==strtoupper($pnk))continue;
                if(!$this->mediaRowIsVerified($channelId,$row,$pnk))continue;
                $local=trim((string)($row['local_url']??''));if($this->localCacheFileExists($local))return $row;
            }
        }catch(Throwable){}
        return [];
    }

    private function extractPnk(string $productUrl): string {
        $path=(string)(parse_url($productUrl,PHP_URL_PATH)??'');
        if(!preg_match('~/pd/([^/?#]+)/?~i',$path,$m))return '';
        $pnk=trim(rawurldecode((string)$m[1]));
        return preg_match('/^[A-Za-z0-9_-]{3,80}$/',$pnk)?$pnk:'';
    }

    /** Free resolver: exact central product linkage -> current WooCommerce image. */
    /** Free resolver: use only a two-signal central catalogue match, never a bare order-item product_id. */
    private function catalogueImageForItem(int $itemId,int $productId,string $sku,string $name=''): array {
        $match=$this->findCentralProductMatch($sku,'',$name);$safeId=(int)($match['product_id']??0);
        if($safeId<=0)return [];
        return $this->catalogueImageForProduct($safeId)+['match_reason'=>(string)($match['reason']??''),'match_source'=>'strict_item'];
    }

    private function offerByPnkCached(EmagClient $client,string $pnk): ?array {
        $pnk=strtoupper(trim($pnk));if($pnk==='')return null;
        if(array_key_exists($pnk,$this->pnkOfferCache))return $this->pnkOfferCache[$pnk];
        try{$offer=$client->readOfferByPartNumberKey($pnk);return $this->pnkOfferCache[$pnk]=$offer;}catch(Throwable){return $this->pnkOfferCache[$pnk]=null;}
    }

    private function offerField(array $offer,string $field): string {
        $values=[];$push=static function(mixed $v) use (&$values):void{if(is_scalar($v)&&trim((string)$v)!=='')$values[]=trim((string)$v);elseif(is_array($v))foreach($v as $x)if(is_scalar($x)&&trim((string)$x)!=='')$values[]=trim((string)$x);};
        $push($offer[$field]??null);
        foreach(['product','offer','details'] as $node)if(is_array($offer[$node]??null))$push($offer[$node][$field]??null);
        return $values[0]??'';
    }

    private function normalizeProductName(string $name): string {
        $name=html_entity_decode(strip_tags($name),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $name=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        if(function_exists('iconv')){$ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);if(is_string($ascii)&&$ascii!=='')$name=$ascii;}
        $name=preg_replace('/[^a-z0-9]+/i',' ',strtolower($name))??'';
        return trim(preg_replace('/\s+/',' ',$name)??'');
    }

    /** Strong second signal for exact-SKU catalogue fallback without requiring identical marketing wording. */
    private function namesStronglyMatch(string $left,string $right): bool {
        $a=$this->normalizeProductName($left);$b=$this->normalizeProductName($right);$a=preg_replace('/\b(\d+(?:[.,]\d+)?)(kg|g|ml|l)\b/i','$1 $2',$a)??$a;$b=preg_replace('/\b(\d+(?:[.,]\d+)?)(kg|g|ml|l)\b/i','$1 $2',$b)??$b;if($a===''||$b==='')return false;if($a===$b)return true;
        $tokens=static function(string $value): array {$stop=['si'=>1,'cu'=>1,'de'=>1,'din'=>1,'pentru'=>1,'the'=>1,'and'=>1,'of'=>1,'x'=>1];$out=[];foreach(explode(' ',$value) as $t){$t=trim($t);if(strlen($t)<2||isset($stop[$t]))continue;$out[$t]=true;}return array_keys($out);};
        $ta=$tokens($a);$tb=$tokens($b);if(count($ta)<3||count($tb)<3)return false;$common=array_values(array_intersect($ta,$tb));if(count($common)<3)return false;
        $coverage=count($common)/max(1,min(count($ta),count($tb)));if($coverage<0.78)return false;
        // Numeric/package cues must not contradict each other (e.g. 500 ml vs 1 l).
        $nums=static function(string $v): array {preg_match_all('/\b\d+(?:[.,]\d+)?\b/',$v,$m);return array_values(array_unique($m[0]??[]));};
        $na=$nums($a);$nb=$nums($b);if($na&&$nb&&!array_intersect($na,$nb))return false;
        return true;
    }

    /** @return array{product_id:int,reason:string,matched_value:string} */
    private function findCentralProductMatch(string $partNumber='',string $ean='',string $name=''): array {
        $partNumber=trim($partNumber);$ean=preg_replace('/[^0-9]/','',$ean)??'';$name=trim($name);
        $skuCandidates=[];$addSku=static function(string $v) use (&$skuCandidates):void{$v=trim($v);if($v!==''&&!in_array(strtoupper($v),array_map('strtoupper',$skuCandidates),true))$skuCandidates[]=$v;};
        $addSku($partNumber);
        $prefix=trim((string)$this->settings->get('products.sku_prefix','ON'));
        if($partNumber!==''&&$prefix!==''){
            if(strncasecmp($partNumber,$prefix,strlen($prefix))===0)$addSku(substr($partNumber,strlen($prefix)));
            else $addSku($prefix.$partNumber);
        }

        // A seller SKU/part number by itself is not strong enough for media. In production
        // the same short numeric code can exist in more than one catalogue generation. Keep
        // it only as one half of a two-signal match (SKU+EAN or SKU+exact product name).
        $skuIds=[];$skuMatched='';
        foreach($skuCandidates as $candidate){
            try{
                $sql="SELECT p.id FROM products p WHERE p.archived=0 AND UPPER(p.sku)=UPPER(?) UNION SELECT cp.product_id FROM channel_products cp JOIN products p ON p.id=cp.product_id WHERE p.archived=0 AND cp.external_sku IS NOT NULL AND UPPER(cp.external_sku)=UPPER(?)";
                $st=$this->db->prepare($sql);$st->execute([$candidate,$candidate]);$ids=array_values(array_unique(array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN))));
                foreach($ids as $id)$skuIds[$id]=true;
                if(count($ids)===1&&$skuMatched==='')$skuMatched=$candidate;
            }catch(Throwable){}
        }
        $skuUnique=count($skuIds)===1?(int)array_key_first($skuIds):0;

        $eanUnique=0;
        if(strlen($ean)>=8){
            try{
                $st=$this->db->query("SELECT id,ean FROM products WHERE archived=0 AND ean IS NOT NULL AND ean<>''");$ids=[];
                foreach($st->fetchAll() as $row){$v=preg_replace('/[^0-9]/','',(string)($row['ean']??''))??'';if($v!==''&&$v===$ean)$ids[]=(int)$row['id'];}
                $ids=array_values(array_unique($ids));if(count($ids)===1)$eanUnique=(int)$ids[0];
            }catch(Throwable){}
        }
        if($eanUnique>0){
            if($skuUnique>0&&$skuUnique===$eanUnique)return ['product_id'=>$eanUnique,'reason'=>'sku_ean','matched_value'=>$skuMatched!==''?$skuMatched.' + '.$ean:$ean];
            return ['product_id'=>$eanUnique,'reason'=>'ean','matched_value'=>$ean];
        }

        $wanted=$this->normalizeProductName($name);
        if($skuUnique>0&&$wanted!==''&&strlen($wanted)>=8){
            try{
                $st=$this->db->prepare("SELECT p.name,cp.name channel_name FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id WHERE p.id=? AND p.archived=0");$st->execute([$skuUnique]);
                foreach($st->fetchAll() as $row){
                    foreach([(string)($row['name']??''),(string)($row['channel_name']??'')] as $candidate){
                        if($candidate!==''&&$this->normalizeProductName($candidate)===$wanted)return ['product_id'=>$skuUnique,'reason'=>'sku_name','matched_value'=>$skuMatched!==''?$skuMatched:$partNumber];
                        if($candidate!==''&&$this->namesStronglyMatch($candidate,$name))return ['product_id'=>$skuUnique,'reason'=>'sku_name_similar','matched_value'=>$skuMatched!==''?$skuMatched:$partNumber];
                    }
                }
            }catch(Throwable){}
        }
        return ['product_id'=>0,'reason'=>'','matched_value'=>''];
    }

    private function productLinkRow(int $channelId,string $pnk): array {
        $pnk=strtoupper(trim($pnk));if($channelId<=0||$pnk==='')return [];
        try{$st=$this->db->prepare('SELECT l.*,p.archived FROM emag_product_links l JOIN products p ON p.id=l.product_id WHERE l.channel_id=? AND UPPER(l.pnk)=UPPER(?) LIMIT 1');$st->execute([$channelId,$pnk]);$row=$st->fetch();if(is_array($row)&&(int)($row['archived']??0)===0)return $row;}catch(Throwable){}
        return [];
    }

    private function saveProductLink(int $channelId,string $pnk,int $productId,string $source,string $reason='',string $partNumber='',string $productName=''): void {
        $pnk=strtoupper(trim($pnk));if($channelId<=0||$pnk===''||$productId<=0)return;$source=$source==='manual'?'manual':'auto';
        try{
            $existing=$this->productLinkRow($channelId,$pnk);
            if($existing){if($source==='auto'&&(string)($existing['mapping_source']??'')==='manual')return;$st=$this->db->prepare('UPDATE emag_product_links SET product_id=?,part_number=?,product_name=?,mapping_source=?,match_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$st->execute([$productId,$partNumber!==''?$partNumber:null,$productName!==''?$productName:null,$source,$reason!==''?$reason:null,(int)$existing['id']]);return;}
            $st=$this->db->prepare('INSERT INTO emag_product_links(channel_id,pnk,product_id,part_number,product_name,mapping_source,match_reason) VALUES(?,?,?,?,?,?,?)');$st->execute([$channelId,$pnk,$productId,$partNumber!==''?$partNumber:null,$productName!==''?$productName:null,$source,$reason!==''?$reason:null]);
        }catch(Throwable){}
    }

    private function attachProductToPnkItems(int $channelId,string $pnk,int $productId): void {
        $pnk=strtoupper(trim($pnk));if($channelId<=0||$pnk===''||$productId<=0)return;
        try{
            $st=$this->db->prepare("SELECT oi.id,oi.product_url FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.channel_id=? AND oi.product_url IS NOT NULL AND oi.product_url<>''");$st->execute([$channelId]);$up=$this->db->prepare('UPDATE order_items SET product_id=? WHERE id=?');
            foreach($st->fetchAll() as $row){if(strtoupper($this->extractPnk((string)($row['product_url']??'')))===$pnk)$up->execute([$productId,(int)$row['id']]);}
        }catch(Throwable){}
    }

    /** Resolve an exact PNK to our own catalogue using the official Marketplace API. */
    private function catalogueImageForPnk(int $channelId,string $pnk,?EmagClient $client,string $fallbackPart='',string $fallbackEan='',string $fallbackName=''): array {
        $pnk=strtoupper(trim($pnk));if($pnk==='')return [];
        $linked=$this->productLinkRow($channelId,$pnk);
        if($linked&&$this->isSafeCatalogueLink($linked)){$img=$this->catalogueImageForProduct((int)$linked['product_id']);if($img)return $img+['match_source'=>(string)($linked['mapping_source']??'saved_link'),'match_reason'=>(string)($linked['match_reason']??'saved_link'),'pnk'=>$pnk];}
        $offer=$client?$this->offerByPnkCached($client,$pnk):null;
        $part=$offer?$this->offerField($offer,'part_number'):'';if($part==='')$part=$fallbackPart;
        $ean=$offer?$this->offerField($offer,'ean'):'';if($ean==='')$ean=$fallbackEan;
        $name=$offer?$this->offerField($offer,'name'):'';if($name==='')$name=$fallbackName;
        $match=$this->findCentralProductMatch($part,$ean,$name);$productId=(int)($match['product_id']??0);if($productId<=0)return [];
        $this->saveProductLink($channelId,$pnk,$productId,'auto',(string)$match['reason'],$part,$name);$this->attachProductToPnkItems($channelId,$pnk,$productId);
        $img=$this->catalogueImageForProduct($productId);return $img?($img+['match_source'=>'official_api','match_reason'=>(string)$match['reason'],'pnk'=>$pnk,'part_number'=>$part]):[];
    }

    /** Save a staff-approved image for an eMAG order item. Manual images always win over automatic repair. */
    public function setManualOrderItemImage(int $orderId,int $itemId,array $file,int $userId=0): array {
        $st=$this->db->prepare('SELECT oi.id,oi.remote_product_id,oi.product_url,oi.name,o.channel_id,c.type,c.code FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN channels c ON c.id=o.channel_id WHERE oi.id=? AND oi.order_id=? AND o.remote_deleted=0 LIMIT 1');
        $st->execute([$itemId,$orderId]);$item=$st->fetch();if(!$item)throw new RuntimeException('Produsul din comanda nu exista.');if((string)$item['type']!=='emag')throw new RuntimeException('Uploadul manual din preview este disponibil pentru comenzile eMAG.');
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)throw new RuntimeException('Alege o imagine.');if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Imaginea nu a putut fi incarcata.');
        $size=(int)($file['size']??0);if($size<=0||$size>10*1024*1024)throw new RuntimeException('Imaginea trebuie sa aiba maximum 10 MB.');
        $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Fisierul incarcat este invalid.');
        $mime='';if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$tmp);if(is_string($det))$mime=$det;finfo_close($f);}}
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];if(!isset($allowed[$mime]))throw new RuntimeException('Sunt acceptate doar imagini JPG, PNG, WEBP sau GIF.');
        $body=@file_get_contents($tmp);if(!is_string($body)||strlen($body)<32)throw new RuntimeException('Imaginea incarcata nu poate fi citita.');if(function_exists('getimagesizefromstring')&&!@getimagesizefromstring($body))throw new RuntimeException('Fisierul incarcat nu este o imagine valida.');
        $hash=hash('sha256',$body);$remoteId=trim((string)($item['remote_product_id']??''));$productUrl=trim((string)($item['product_url']??''));if(!$this->isEmagProductUrl($productUrl))$productUrl='';$pnk=$productUrl!==''?$this->extractPnk($productUrl):'';
        $dir=dirname(__DIR__,2).'/public/uploads/emag-media';if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Folderul pentru imaginile comenzilor nu poate fi creat.');
        $identity=$remoteId!==''?$remoteId:($pnk!==''?'pnk-'.$pnk:'item-'.$itemId);$safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim((string)$item['code'].'-'.$identity))?:sha1((string)$item['code'].'-'.$identity);$safe='manual-'.substr($safe,0,100);$filePath=$dir.'/'.$safe.'.'.$allowed[$mime];
        $temp=$filePath.'.tmp-'.bin2hex(random_bytes(4));if(@file_put_contents($temp,$body,LOCK_EX)===false)throw new RuntimeException('Imaginea nu a putut fi salvata.');if(!@rename($temp,$filePath)){@unlink($temp);throw new RuntimeException('Imaginea nu a putut fi publicata.');}
        foreach(['jpg','jpeg','png','webp','gif'] as $other){$candidate=$dir.'/'.$safe.'.'.$other;if($candidate!==$filePath&&is_file($candidate))@unlink($candidate);}
        $display=app_path('/uploads/emag-media/'.basename($filePath)).'?v='.substr($hash,0,12);$now=date('Y-m-d H:i:s');$marker='[MANUAL_IMAGE'.($userId>0?':'.$userId:'').']';
        if($remoteId!==''){$this->saveMedia((int)$item['channel_id'],$remoteId,'',$display,$hash,$productUrl!==''?$productUrl:null,$now,$now,$marker);$this->propagateToOrderItems((int)$item['channel_id'],$remoteId,$display,$productUrl);}
        else{$up=$this->db->prepare('UPDATE order_items SET image_url=? WHERE id=?');$up->execute([$display,$itemId]);if($pnk!==''){try{$like='%/pd/'.$pnk.'%';$q=$this->db->prepare('UPDATE order_items SET image_url=? WHERE id IN (SELECT oi.id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.channel_id=? AND oi.product_url LIKE ?)');$q->execute([$display,(int)$item['channel_id'],$like]);}catch(Throwable){}}}
        return ['ok'=>true,'image_url'=>$display,'manual'=>true,'item_id'=>$itemId,'remote_product_id'=>$remoteId,'pnk'=>$pnk];
    }

    /** Search central products for one-time manual eMAG PNK linking. */
    public function searchCentralProducts(string $query,int $limit=20): array {
        $query=trim($query);$limit=max(1,min(30,$limit));if(strlen($query)<2)return [];$like='%'.$query.'%';
        $sql="SELECT DISTINCT p.id,p.sku,p.ean,p.name FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id WHERE p.archived=0 AND (p.sku LIKE ? OR p.ean LIKE ? OR p.name LIKE ? OR cp.external_sku LIKE ? OR cp.name LIKE ?) ORDER BY CASE WHEN p.sku=? THEN 0 WHEN p.ean=? THEN 1 ELSE 2 END,p.name LIMIT ".$limit;
        $st=$this->db->prepare($sql);$st->execute([$like,$like,$like,$like,$like,$query,$query]);$out=[];
        foreach($st->fetchAll() as $row){$img=$this->catalogueImageForProduct((int)$row['id']);$out[]=['id'=>(int)$row['id'],'sku'=>(string)($row['sku']??''),'ean'=>(string)($row['ean']??''),'name'=>(string)($row['name']??''),'image_url'=>(string)($img['image_url']??'')];}
        return $out;
    }

    /** Manual one-time fallback: bind an eMAG PNK to the exact central product selected by staff. */
    public function linkOrderItemToProduct(int $orderId,int $itemId,int $productId): array {
        $st=$this->db->prepare('SELECT oi.*,o.channel_id,c.code,c.type,c.country FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN channels c ON c.id=o.channel_id WHERE oi.id=? AND oi.order_id=? LIMIT 1');$st->execute([$itemId,$orderId]);$item=$st->fetch();
        if(!$item||($item['type']??'')!=='emag')throw new RuntimeException('Pozitia eMAG nu exista.');
        $product=$this->db->prepare('SELECT id,sku,name,archived FROM products WHERE id=? LIMIT 1');$product->execute([$productId]);$p=$product->fetch();if(!$p||(int)($p['archived']??0)!==0)throw new RuntimeException('Produsul central selectat nu este disponibil.');
        $productUrl=trim((string)($item['product_url']??''));$pnk=$this->extractPnk($productUrl);$remoteId=trim((string)($item['remote_product_id']??''));
        if($pnk===''&&$remoteId!==''){$hint=$this->offerHint((int)$item['channel_id'],$remoteId);$pnk=trim((string)($hint['pnk']??''));if($pnk!=='')$productUrl=$this->publicUrlFromPnk($pnk,$item);}
        if($pnk==='')throw new RuntimeException('PNK-ul eMAG nu este disponibil pentru acest produs.');
        $this->saveProductLink((int)$item['channel_id'],$pnk,$productId,'manual','manual',(string)($item['sku']??''),(string)($item['name']??''));$this->attachProductToPnkItems((int)$item['channel_id'],$pnk,$productId);
        $catalogue=$this->catalogueImageForProduct($productId);$source=trim((string)($catalogue['image_url']??''));if($source==='')throw new RuntimeException('Produsul selectat nu are imagine in Alfamed/Univera.');
        $cacheId=$remoteId!==''?$remoteId:'pnk-'.$pnk;$existing=$remoteId!==''?$this->mediaRow((int)$item['channel_id'],$remoteId):[];$download=$this->downloadGenericImageToLocalCache($source,(string)$item['code'],$cacheId,$existing);$local=(string)$download['url'];$hash=(string)$download['hash'];$now=date('Y-m-d H:i:s');
        if($remoteId!==''){$this->saveMedia((int)$item['channel_id'],$remoteId,null,$local,$hash,$productUrl,$now,$now,null);$this->propagateToOrderItems((int)$item['channel_id'],$remoteId,$local,$productUrl);}
        try{$all=$this->db->prepare("SELECT oi.id,oi.remote_product_id,oi.product_url FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.channel_id=? AND oi.product_url IS NOT NULL AND oi.product_url<>''");$all->execute([(int)$item['channel_id']]);$up=$this->db->prepare('UPDATE order_items SET product_id=?,image_url=? WHERE id=?');foreach($all->fetchAll() as $row){if(strtoupper($this->extractPnk((string)$row['product_url']))!==strtoupper($pnk))continue;$up->execute([$productId,$local,(int)$row['id']]);$rid=trim((string)($row['remote_product_id']??''));if($rid!==''&&$rid!==$remoteId)$this->saveMedia((int)$item['channel_id'],$rid,null,$local,$hash,(string)$row['product_url'],$now,$now,null);}}catch(Throwable){}
        return ['ok'=>true,'pnk'=>$pnk,'product_id'=>$productId,'image_url'=>$local,'items'=>$this->orderItemState($orderId)];
    }

    /** Strict fallback for order previews: one exact SKU plus exact normalized title. */
    private function catalogueImageForExactSkuName(string $sku,string $name): array {
        $sku=trim($sku);$wanted=$this->normalizeProductName($name);if($sku===''||$wanted===''||strlen($wanted)<4)return [];
        try{
            $sql="SELECT DISTINCT p.id FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id WHERE p.archived=0 AND (UPPER(p.sku)=UPPER(?) OR (cp.external_sku IS NOT NULL AND UPPER(cp.external_sku)=UPPER(?)))";
            $st=$this->db->prepare($sql);$st->execute([$sku,$sku]);$ids=array_values(array_unique(array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN))));if(count($ids)!==1)return [];$productId=(int)$ids[0];
            $names=$this->db->prepare('SELECT p.name,cp.name channel_name FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id WHERE p.id=? AND p.archived=0');$names->execute([$productId]);$exact=false;
            foreach($names->fetchAll() as $row){foreach([(string)($row['name']??''),(string)($row['channel_name']??'')] as $candidate){if($candidate!==''&&hash_equals($wanted,$this->normalizeProductName($candidate))){$exact=true;break 2;}}}
            if(!$exact)return [];$img=$this->catalogueImageForProduct($productId);return $img?($img+['match_source'=>'catalogue_exact','match_reason'=>'sku_name','sku'=>$sku]):[];
        }catch(Throwable){return [];}
    }

    /** Free resolver for syncProduct: saved link/order linkage, then safe identifier matching. */
    /** Free resolver for syncProduct: saved exact link, then strict multi-signal identifier matching. */
    private function catalogueImageForRemote(int $channelId,string $remoteId,array $hint): array {
        $pnk=strtoupper(trim((string)($hint['pnk']??'')));
        if($pnk!==''){
            $linked=$this->productLinkRow($channelId,$pnk);
            if($linked&&$this->isSafeCatalogueLink($linked)){
                $img=$this->catalogueImageForProduct((int)$linked['product_id']);
                if($img)return $img+['match_source'=>(string)($linked['mapping_source']??'saved_link'),'match_reason'=>(string)($linked['match_reason']??'saved_link')];
            }
        }
        $match=$this->findCentralProductMatch((string)($hint['part_number']??''),(string)($hint['ean']??''),(string)($hint['name']??''));$productId=(int)($match['product_id']??0);
        if($productId>0&&$pnk!==''){$this->saveProductLink($channelId,$pnk,$productId,'auto',(string)($match['reason']??''),(string)($hint['part_number']??''),(string)($hint['name']??''));$this->attachProductToPnkItems($channelId,$pnk,$productId);}
        return $productId>0?$this->catalogueImageForProduct($productId):[];
    }

    private function catalogueImageForProduct(int $productId): array {
        if($productId<=0)return [];$candidates=[];
        try{
            $st=$this->db->prepare("SELECT p.image_url,cp.image_json,c.code FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id LEFT JOIN channels c ON c.id=cp.channel_id AND c.type='woocommerce' WHERE p.id=? AND p.archived=0 AND (cp.id IS NULL OR c.type='woocommerce') ORDER BY CASE WHEN c.code='alfamed' THEN 0 WHEN c.code='univera' THEN 1 ELSE 2 END,cp.id ASC");$st->execute([$productId]);
            foreach($st->fetchAll() as $row){
                $master=trim((string)($row['image_url']??''));if($master!=='')$candidates[]=['url'=>$master,'code'=>(string)($row['code']??'')];
                $imgs=json_decode((string)($row['image_json']??'[]'),true);if(is_array($imgs))foreach($imgs as $img){if(!is_array($img))continue;$u=trim((string)($img['src']??$img['url']??''));if($u!=='')$candidates[]=['url'=>$u,'code'=>(string)($row['code']??'')];}
            }
        }catch(Throwable){return [];}
        $seen=[];foreach($candidates as $candidate){$url=$this->normalizeHttpUrl((string)$candidate['url']);if($url===''||isset($seen[$url]))continue;$seen[$url]=true;if($this->isSafeCatalogueImageUrl($url))return ['image_url'=>$url,'product_id'=>$productId,'channel_code'=>(string)$candidate['code']];}
        return [];
    }

    /** Prevent SSRF while allowing public WooCommerce/CDN image URLs stored in our own catalogue. */
    private function isSafeCatalogueImageUrl(string $url): bool {
        $url=$this->normalizeHttpUrl($url);if($url==='')return false;$host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));if($host==='')return false;
        if($host==='localhost'||str_ends_with($host,'.local')||$host==='::1'||preg_match('/^127\./',$host)||preg_match('/^10\./',$host)||preg_match('/^192\.168\./',$host)||preg_match('/^169\.254\./',$host))return false;
        if(preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./',$host))return false;
        return true;
    }

    /** Download a trusted catalogue image to the same local eMAG-media cache. */
    private function downloadGenericImageToLocalCache(string $sourceUrl,string $channelCode,string $remoteId,array $existing): array {
        $sourceUrl=$this->normalizeHttpUrl($sourceUrl);if($sourceUrl===''||!$this->isSafeCatalogueImageUrl($sourceUrl))throw new RuntimeException('URL imagine catalog nesigur.');
        $host=(string)(parse_url($sourceUrl,PHP_URL_HOST)??'');$scheme=(string)(parse_url($sourceUrl,PHP_URL_SCHEME)??'https');$referer=$host!==''?$scheme.'://'.$host.'/':'https://www.alfamedclinic.ro/';
        $headers=['Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8','User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36','Cache-Control: no-cache','Referer: '.$referer];
        $r=Http::request('GET',$sourceUrl,$headers,null,15);$body=(string)($r['body']??'');$status=(int)($r['status']??0);if($status<200||$status>=400)throw new RuntimeException('Imagine catalog HTTP '.$status.'.');
        return $this->storeImageBody($body,$channelCode,$remoteId,$existing);
    }

    /** Read exact product URL/image already present in the saved eMAG order payload, when available. */
    private function rawOrderMediaHint(int $channelId,string $remoteId,array $channel): array {
        $remoteId=trim($remoteId);if($channelId<=0||$remoteId==='')return [];
        try{$st=$this->db->prepare('SELECT o.raw_json FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.channel_id=? AND oi.remote_product_id=? ORDER BY o.id DESC LIMIT 8');$st->execute([$channelId,$remoteId]);$rows=$st->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable){return [];}
        foreach($rows as $rawJson){$raw=json_decode((string)$rawJson,true);if(!is_array($raw))continue;foreach((array)($raw['products']??$raw['items']??[]) as $node){if(!is_array($node))continue;$rid=trim((string)($node['product_id']??$node['product_offer_id']??$node['offer_id']??''));if($rid!==$remoteId)continue;$pnk=$this->offerPartNumberKey($node);$productUrl='';
                foreach(['product_url','emag_product_url','url','web_url','public_url','permalink'] as $key){$candidate=$this->normalizeHttpUrl((string)($node[$key]??''));if($candidate!==''&&$this->isEmagProductUrl($candidate)){if($pnk===''||strtoupper($this->extractPnk($candidate))===strtoupper($pnk)){$productUrl=$candidate;break;}}}
                if($productUrl===''&&$pnk!=='')$productUrl=$this->publicUrlFromPnk($pnk,$channel);
                $image=$this->findTrustedImageInNode($node);if($image!==''&&$productUrl!==''&&$pnk!==''&&strtoupper($this->extractPnk($productUrl))!==strtoupper($pnk))$image='';
                return ['pnk'=>$pnk,'product_url'=>$productUrl,'image_url'=>$image];
            }}
        return [];
    }

    /** @return array{updated:bool,image_url:string,hash:string} */
    private function syncProduct(array $channel,EmagClient $client,string $remoteId,bool $force=false): array {
        $remoteId=trim($remoteId);if($remoteId==='')throw new RuntimeException('Lipseste product_id eMAG.');$channelId=(int)$channel['id'];$existing=$this->sanitizeExistingMedia($channelId,$remoteId,$this->mediaRow($channelId,$remoteId));
        if($this->isManualMediaRow($existing)&&$this->localCacheFileExists((string)($existing['local_url']??''))){$display=(string)$existing['local_url'];$productUrl=trim((string)($existing['product_url']??''));$this->propagateToOrderItems($channelId,$remoteId,$display,$this->isEmagProductUrl($productUrl)?$productUrl:'');return ['updated'=>false,'image_url'=>$display,'hash'=>(string)($existing['image_hash']??''),'fallback'=>true,'source'=>'manual'];}
        $hint=$this->offerHint($channelId,$remoteId);$rawHint=$this->rawOrderMediaHint($channelId,$remoteId,$channel);if(trim((string)($hint['pnk']??''))===''&&!empty($rawHint['pnk']))$hint['pnk']=(string)$rawHint['pnk'];
        $offer=$this->resolveOffer($channelId,$remoteId,$client,$hint);$productUrl=trim((string)($rawHint['product_url']??''));$sourceUrl=trim((string)($rawHint['image_url']??''));
        if($offer){$offerPnk=$this->offerPartNumberKey($offer);if(trim((string)($hint['pnk']??''))===''&&$offerPnk!=='')$hint['pnk']=$offerPnk;if(trim((string)($hint['part_number']??''))==='')$hint['part_number']=$this->offerField($offer,'part_number');if(trim((string)($hint['name']??''))==='')$hint['name']=$this->offerField($offer,'name');$offerUrl=$this->offerPublicUrl($offer,$channel,(string)($offer['name']??$offer['title']??''));if($productUrl===''&&$offerUrl!=='')$productUrl=$offerUrl;$offerImage=$this->mainImageUrl($offer);if($sourceUrl===''&&$offerImage!=='')$sourceUrl=$offerImage;}
        $expectedPnk=strtoupper(trim((string)($hint['pnk']??'')));if($expectedPnk!==''&&($productUrl===''||strtoupper($this->extractPnk($productUrl))!==$expectedPnk))$productUrl=$this->publicUrlFromPnk($expectedPnk,$channel);
        $existingProductUrl=trim((string)($existing['product_url']??''));if($productUrl===''&&$this->isEmagProductUrl($existingProductUrl)){$productUrl=$existingProductUrl;if($expectedPnk==='')$expectedPnk=strtoupper($this->extractPnk($productUrl));}if($expectedPnk===''&&$productUrl!=='')$expectedPnk=strtoupper($this->extractPnk($productUrl));
        if(!$offer&&$productUrl==='')throw new RuntimeException('Nu am putut rezolva oferta/PNK eMAG pentru product_id '.$remoteId.'.');
        if(!$this->isSafeCatalogueImageUrl($sourceUrl)||$expectedPnk===''||!$this->isEmagProductUrl($productUrl)||strtoupper($this->extractPnk($productUrl))!==$expectedPnk)$sourceUrl='';
        $existingVerified=$this->mediaRowIsVerified($channelId,$existing,$expectedPnk);
        if(!$force&&!$existingVerified&&$productUrl!==''){$cached=$this->cachedPnkMedia($channelId,$productUrl,$remoteId);if($cached){$local=(string)$cached['local_url'];$hash=(string)($cached['image_hash']??'');$canonical=(string)($cached['product_url']??$productUrl);$source=(string)($cached['source_url']??'');$now=date('Y-m-d H:i:s');$this->saveMedia($channelId,$remoteId,$source,$local,$hash,$canonical,(string)($cached['synced_at']??$now),$now,$this->mediaProvenanceMarker($cached));$this->propagateToOrderItems($channelId,$remoteId,$local,$canonical);return ['updated'=>true,'image_url'=>$local,'hash'=>$hash,'fallback'=>true,'source'=>'pnk_cache'];}}
        if($sourceUrl!==''){
            $officialCdn=$this->isOfficialEmagImageUrl($sourceUrl);
            try{$download=$officialCdn?$this->downloadToLocalCache($sourceUrl,(string)$channel['code'],$remoteId,$existing):$this->downloadGenericImageToLocalCache($sourceUrl,(string)$channel['code'],$remoteId,$existing);$hash=(string)$download['hash'];$localUrl=(string)$download['url'];$updated=$hash!==trim((string)($existing['image_hash']??''))||$sourceUrl!==trim((string)($existing['source_url']??''))||$localUrl!==trim((string)($existing['local_url']??''))||$productUrl!==trim((string)($existing['product_url']??''));$now=date('Y-m-d H:i:s');$marker=$officialCdn?null:'[EMAG_API_EXACT]';$this->saveMedia($channelId,$remoteId,$sourceUrl,$localUrl,$hash,$productUrl,$now,$now,$marker);$this->propagateToOrderItems($channelId,$remoteId,$localUrl,$productUrl);return ['updated'=>$updated,'image_url'=>$localUrl,'hash'=>$hash,'fallback'=>false,'source'=>$officialCdn?'emag_api_cdn':'emag_api_seller_image'];}
            catch(Throwable $downloadError){$display=$existingVerified?$this->mediaDisplayUrl($existing):($officialCdn?$sourceUrl:'');$now=date('Y-m-d H:i:s');$message='Imaginea exacta din API-ul eMAG a fost gasita, dar nu a putut fi salvata local: '.$downloadError->getMessage();$marker=($officialCdn?'':'[EMAG_API_EXACT] ').$message;$this->saveMedia($channelId,$remoteId,$sourceUrl,null,null,$productUrl,null,$now,$marker);$this->propagateToOrderItems($channelId,$remoteId,$display,$productUrl);return ['updated'=>true,'image_url'=>$display,'hash'=>(string)($existing['image_hash']??''),'fallback'=>false,'warning'=>$message,'source'=>'emag_api_download_error'];}
        }
        if($existingVerified){$display=$this->mediaDisplayUrl($existing);$now=date('Y-m-d H:i:s');$message='API-ul eMAG nu a retransmis imaginea acum; cache-ul verificat anterior a fost pastrat.';$storedMessage=$this->mediaProvenanceMarker($existing);if($storedMessage!==null)$storedMessage.=' '.$message;else $storedMessage=$message;$this->saveMedia($channelId,$remoteId,null,null,null,$productUrl!==''?$productUrl:null,null,$now,$storedMessage);$this->propagateToOrderItems($channelId,$remoteId,$display,$productUrl);return ['updated'=>false,'image_url'=>$display,'hash'=>(string)($existing['image_hash']??''),'fallback'=>false,'warning'=>$message,'source'=>'verified_cache'];}
        if($expectedPnk!==''&&$productUrl!==''&&strtoupper($this->extractPnk($productUrl))===$expectedPnk){
            $catalogue=$this->catalogueImageForExactSkuName((string)($hint['part_number']??''),(string)($hint['name']??''));$catalogueSource=trim((string)($catalogue['image_url']??''));$catalogueProductId=(int)($catalogue['product_id']??0);
            if($catalogueSource!==''&&$catalogueProductId>0){
                try{$download=$this->downloadGenericImageToLocalCache($catalogueSource,(string)$channel['code'],$remoteId,$existing);$local=(string)$download['url'];$hash=(string)$download['hash'];$now=date('Y-m-d H:i:s');$this->saveProductLink($channelId,$expectedPnk,$catalogueProductId,'auto','sku_name',(string)($hint['part_number']??''),(string)($hint['name']??''));$this->attachProductToPnkItems($channelId,$expectedPnk,$catalogueProductId);$this->saveMedia($channelId,$remoteId,$catalogueSource,$local,$hash,$productUrl,$now,$now,'[CATALOG_EXACT]');$this->propagateToOrderItems($channelId,$remoteId,$local,$productUrl);return ['updated'=>true,'image_url'=>$local,'hash'=>$hash,'fallback'=>true,'source'=>'catalogue_exact'];}
                catch(Throwable $catalogueError){$now=date('Y-m-d H:i:s');$message='Potrivirea exacta SKU + denumire a fost gasita in catalog, dar imaginea nu a putut fi salvata: '.$catalogueError->getMessage();$this->saveMedia($channelId,$remoteId,$catalogueSource,'','',$productUrl,null,$now,'[CATALOG_EXACT] '.$message);}
            }
        }
        $now=date('Y-m-d H:i:s');$message='Nu am gasit o imagine in eMAG si nici o potrivire exacta SKU + denumire in catalogul Alfamed/Univera. Poti incarca manual o poza din preview.';$this->saveMedia($channelId,$remoteId,'','','',$productUrl!==''?$productUrl:null,null,$now,$message);$this->propagateToOrderItems($channelId,$remoteId,'',$productUrl);return ['updated'=>true,'image_url'=>'','hash'=>'','fallback'=>false,'warning'=>$message,'source'=>'missing'];
    }

    /** Prefer display_type=1 from the exact authenticated eMAG offer.
     * product_offer/read may return the seller image URL rather than an eMAG CDN URL,
     * so exact-PNK identity is validated by the caller and the image is cached locally.
     */
    private function mainImageUrl(array $offer): string {
        $candidates=[];foreach(['images','pictures','image','image_url','main_image_url','url_image'] as $key){$v=$offer[$key]??null;if($v!==null)$candidates[]=$v;}foreach(['product','offer','details'] as $node)if(is_array($offer[$node]??null))foreach(['images','pictures','image','image_url','main_image_url','url_image'] as $key){$v=$offer[$node][$key]??null;if($v!==null)$candidates[]=$v;}
        $preferred=[];$fallback=[];$walk=function(mixed $value,bool $preferredContext=false,int $depth=0) use (&$walk,&$preferred,&$fallback): void {if($depth>8)return;if(is_string($value)){$decoded=json_decode($value,true);if(is_array($decoded)){$walk($decoded,$preferredContext,$depth+1);return;}$url=$this->normalizeHttpUrl($value);if(!$this->isSafeCatalogueImageUrl($url))return;if($preferredContext)$preferred[]=$url;else $fallback[]=$url;return;}if(!is_array($value))return;$isPreferred=$preferredContext||((int)($value['display_type']??0)===1);foreach(['url','src','image_url','contentUrl'] as $key){$v=$value[$key]??null;if(is_scalar($v))$walk((string)$v,$isPreferred,$depth+1);}foreach($value as $key=>$child){if(in_array((string)$key,['url','src','image_url','contentUrl'],true))continue;if(is_array($child)||is_string($child))$walk($child,$isPreferred,$depth+1);}};
        foreach($candidates as $candidate)$walk($candidate,false,0);foreach(array_merge($preferred,$fallback) as $url)if($this->isSafeCatalogueImageUrl($url))return $url;return '';
    }

    private function normalizeHttpUrl(string $url): string {
        $url=html_entity_decode(trim($url),ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_starts_with($url,'//'))$url='https:'.$url;
        if(!filter_var($url,FILTER_VALIDATE_URL))return '';$scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        return in_array($scheme,['http','https'],true)?$url:'';
    }

    private function offerPublicUrl(array $offer,array $channel,string $name): string {
        $country=(string)($channel['country']??'RO');$wantedHost=$country==='BG'?'www.emag.bg':'www.emag.ro';
        foreach(['product_url','emag_product_url','url','web_url','public_url','permalink'] as $key){
            $candidate=$this->normalizeHttpUrl((string)($offer[$key]??''));
            if($candidate!==''&&$this->isEmagProductUrl($candidate,$wantedHost))return $candidate;
        }
        foreach(['product','offer','details'] as $node)if(is_array($offer[$node]??null)){
            foreach(['product_url','emag_product_url','url','web_url','public_url','permalink'] as $key){
                $candidate=$this->normalizeHttpUrl((string)($offer[$node][$key]??''));
                if($candidate!==''&&$this->isEmagProductUrl($candidate,$wantedHost))return $candidate;
            }
        }
        $pnk=$this->offerPartNumberKey($offer);
        if($pnk==='')return '';
        $base=$country==='BG'?'https://www.emag.bg':'https://www.emag.ro';
        // eMAG resolves the product by part_number_key (PNK); a generic slug is
        // deliberately used so a renamed product can never generate a stale/wrong URL.
        return $base.($country==='BG'?'/product/pd/':'/produs/pd/').rawurlencode($pnk).'/';
    }

    private function offerPartNumberKey(array $offer): string {
        $keys=['part_number_key','pnk','product_part_number_key','emag_part_number_key'];
        $walk=null;$walk=function(mixed $node,int $depth=0) use (&$walk,$keys): string {
            if($depth>5||!is_array($node))return '';
            foreach($keys as $key){$v=$node[$key]??null;if(is_scalar($v)&&trim((string)$v)!=='')return trim((string)$v);}
            foreach($node as $value){if(!is_array($value))continue;$hit=$walk($value,$depth+1);if($hit!=='')return $hit;}
            return '';
        };
        $pnk=$walk($offer);if($pnk!=='')return $pnk;
        // Some API variants expose the canonical catalogue URL but omit a dedicated
        // part_number_key field. The token after /pd/ is the PNK and is authoritative.
        $urlKeys=['product_url','emag_product_url','url','web_url','public_url','permalink'];
        $findUrl=null;$findUrl=function(mixed $node,int $depth=0) use (&$findUrl,$urlKeys): string {
            if($depth>5||!is_array($node))return '';
            foreach($urlKeys as $key){$v=$node[$key]??null;if(!is_scalar($v))continue;$url=trim((string)$v);if($url==='')continue;if(preg_match('~/pd/([^/?#]+)/?~i',$url,$m))return trim((string)$m[1]);}
            foreach($node as $value){if(!is_array($value))continue;$hit=$findUrl($value,$depth+1);if($hit!=='')return $hit;}
            return '';
        };
        return $findUrl($offer);
    }

    /** Only an actual eMAG product CDN asset counts as an authoritative source image. */
    private function isOfficialEmagImageUrl(string $url): bool {
        $url=$this->normalizeHttpUrl($url);if($url==='')return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
        if($host===''||!str_contains($path,'/products/'))return false;
        if((bool)preg_match('/^s\d+emagst\.akamaized\.net$/i',$host))return true;
        if(str_contains($host,'emagcdn'))return true;
        return str_ends_with($host,'.akamaized.net')&&str_contains($host,'emag');
    }

    /** Automatic catalogue fallbacks are accepted only when they were proved by two identifiers or EAN. */
    private function isSafeCatalogueLink(array $link): bool {
        if(!$link)return false;
        if((string)($link['mapping_source']??'')==='manual')return true;
        return in_array((string)($link['match_reason']??''),['ean','sku_ean','sku_name'],true);
    }

    private function isSafeCataloguePnk(int $channelId,string $pnk): bool {
        $pnk=strtoupper(trim($pnk));if($pnk==='')return false;
        return $this->isSafeCatalogueLink($this->productLinkRow($channelId,$pnk));
    }

    private function isManualMediaRow(array $row): bool { return str_starts_with((string)($row['last_error']??''),'[MANUAL_IMAGE'); }
    private function isExactApiMediaRow(array $row): bool { return str_starts_with((string)($row['last_error']??''),'[EMAG_API_EXACT]'); }
    private function isExactCatalogueMediaRow(array $row): bool { return str_starts_with((string)($row['last_error']??''),'[CATALOG_EXACT]'); }
    private function mediaProvenanceMarker(array $row): ?string { if($this->isManualMediaRow($row))return '[MANUAL_IMAGE]';if($this->isExactApiMediaRow($row))return '[EMAG_API_EXACT]';if($this->isExactCatalogueMediaRow($row))return '[CATALOG_EXACT]';return null; }

    /** A cached thumbnail is display-safe only when its PNK is exact and its provenance is verifiable. */
    private function mediaRowIsVerified(int $channelId,array $row,string $expectedPnk=''): bool {
        if(!$row)return false;
        $productUrl=trim((string)($row['product_url']??''));if(!$this->isEmagProductUrl($productUrl))return false;
        $pnk=strtoupper($this->extractPnk($productUrl));$expectedPnk=strtoupper(trim($expectedPnk));
        if($pnk===''||($expectedPnk!==''&&!hash_equals($expectedPnk,$pnk)))return false;
        $local=trim((string)($row['local_url']??''));$source=trim((string)($row['source_url']??''));
        if($this->isManualMediaRow($row))return $this->localCacheFileExists($local);
        if($this->isOfficialEmagImageUrl($source))return $local===''||$this->localCacheFileExists($local)||$this->isTrustedEmagImageUrl($local);
        if($this->isExactApiMediaRow($row)&&$this->isSafeCatalogueImageUrl($source))return $this->localCacheFileExists($local);
        if($this->isExactCatalogueMediaRow($row)&&$this->isSafeCataloguePnk($channelId,$pnk)&&$this->isSafeCatalogueImageUrl($source))return $this->localCacheFileExists($local);
        return false;
    }

    private function mediaDisplayUrl(array $row): string {
        $local=trim((string)($row['local_url']??''));if($this->localCacheFileExists($local))return $local;
        $source=trim((string)($row['source_url']??''));return $this->isOfficialEmagImageUrl($source)?$source:'';
    }

    /** Only eMAG-owned/cache image URLs are allowed for eMAG order rows. */
    private function isTrustedEmagImageUrl(string $url): bool {
        $url=trim($url);if($url==='')return false;
        if($this->localCacheFileExists($url))return true;
        $normalized=$this->normalizeHttpUrl($url);if($normalized==='')return false;
        $host=strtolower((string)(parse_url($normalized,PHP_URL_HOST)??''));if($host==='')return false;
        if(in_array($host,['emag.ro','www.emag.ro','emag.bg','www.emag.bg'],true))return true;
        if((bool)preg_match('/^s\d+emagst\.akamaized\.net$/i',$host))return true;
        if(str_ends_with($host,'.emag.ro')||str_ends_with($host,'.emag.bg'))return true;
        if(str_contains($host,'emagcdn'))return true;
        return str_ends_with($host,'.akamaized.net')&&str_contains($host,'emag');
    }

    /** Remove stale media accidentally inherited from WooCommerce/other sources. */
    /** Remove stale or unproven media inherited from older thumbnail strategies. */
    private function sanitizeExistingMedia(int $channelId,string $remoteId,array $row): array {
        if(!$row)return [];$local=trim((string)($row['local_url']??''));$source=trim((string)($row['source_url']??''));$product=trim((string)($row['product_url']??''));$safeProduct=$this->isEmagProductUrl($product)?$product:'';$manual=$this->isManualMediaRow($row);$apiExact=$this->isExactApiMediaRow($row);$catalogueExact=$this->isExactCatalogueMediaRow($row);$pnk=strtoupper($this->extractPnk($safeProduct));$catalogueSafe=$catalogueExact&&$pnk!==''&&$this->isSafeCataloguePnk($channelId,$pnk);$safeSource=$this->isOfficialEmagImageUrl($source)?$source:((($apiExact||$catalogueSafe)&&$this->isSafeCatalogueImageUrl($source))?$source:'');$safeLocal=$this->localCacheFileExists($local)&&($manual||$safeSource!=='')?$local:'';$hash=$safeLocal!==''?trim((string)($row['image_hash']??'')):'';
        if($safeLocal!==$local||$safeSource!==$source||$safeProduct!==$product||$hash!==trim((string)($row['image_hash']??'')))try{$st=$this->db->prepare('UPDATE emag_product_media SET source_url=?,local_url=?,image_hash=?,product_url=?,updated_at=CURRENT_TIMESTAMP WHERE channel_id=? AND remote_product_id=?');$st->execute([$safeSource!==''?$safeSource:null,$safeLocal!==''?$safeLocal:null,$hash!==''?$hash:null,$safeProduct!==''?$safeProduct:null,$channelId,$remoteId]);}catch(Throwable){}
        $row['source_url']=$safeSource;$row['local_url']=$safeLocal;$row['image_hash']=$hash;$row['product_url']=$safeProduct;return $row;
    }

    private function isEmagProductUrl(string $url,string $wantedHost=''): bool {
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
        if($wantedHost!==''&&$host!==$wantedHost&&$host!==preg_replace('/^www\./','',$wantedHost))return false;
        if(!in_array($host,['www.emag.ro','emag.ro','www.emag.bg','emag.bg'],true))return false;
        return (bool)preg_match('#/pd/[^/]+/?#i',$path);
    }

    /** Resolve an offer without touching the public eMAG website. PNK is the strongest historical identifier. */
    private function offerMatchesHint(array $offer,array $hint): bool {
        $wantedPnk=strtoupper(trim((string)($hint['pnk']??'')));
        $actualPnk=strtoupper($this->offerPartNumberKey($offer));
        // When the order already gives us a PNK, the API row must prove the very same PNK.
        // Missing identity is not accepted: an image without an exact product identity is unsafe.
        if($wantedPnk!==''&&($actualPnk===''||!hash_equals($wantedPnk,$actualPnk)))return false;

        $wantedPart=strtoupper(trim((string)($hint['part_number']??'')));
        $actualPart=strtoupper(trim($this->offerField($offer,'part_number')));
        if($wantedPart!==''&&$actualPart!==''&&!hash_equals($wantedPart,$actualPart))return false;

        $wantedEan=preg_replace('/[^0-9]/','',(string)($hint['ean']??''))??'';
        $actualEan=preg_replace('/[^0-9]/','',$this->offerField($offer,'ean'))??'';
        if(strlen($wantedEan)>=8&&strlen($actualEan)>=8&&!hash_equals($wantedEan,$actualEan))return false;
        return true;
    }

    /** Resolve an offer using the strongest order identifiers first; exact remote id is the last fallback. */
    private function resolveOffer(int $channelId,string $remoteId,EmagClient $client,?array $hint=null): ?array {
        $hint=is_array($hint)?$hint:$this->offerHint($channelId,$remoteId);
        $pnk=trim((string)($hint['pnk']??''));
        if($pnk!=='')try{$offer=$this->offerByPnkCached($client,$pnk);if($offer&&$this->offerMatchesHint($offer,$hint))return $offer;}catch(Throwable){}

        $part=trim((string)($hint['part_number']??''));
        if($part!=='')try{$offer=$client->readOfferByPartNumber($part);if($offer&&$this->offerMatchesHint($offer,$hint))return $offer;}catch(Throwable){}

        $ean=trim((string)($hint['ean']??''));
        if($ean!=='')try{$offer=$client->readOfferByEan($ean);if($offer&&$this->offerMatchesHint($offer,$hint))return $offer;}catch(Throwable){}

        // product_offer/read by id is already exact in EmagClient::readOfferById(). Accept it
        // even if a historical local SKU used/omitted our prefix; when a PNK is already known,
        // still require the returned row to prove that very same PNK.
        try{
            $offer=$client->readOfferById($remoteId);
            if($offer){
                $wantedPnk=strtoupper(trim((string)($hint['pnk']??'')));$actualPnk=strtoupper($this->offerPartNumberKey($offer));
                if($wantedPnk===''||($actualPnk!==''&&hash_equals($wantedPnk,$actualPnk)))return $offer;
            }
        }catch(Throwable){}
        return null;
    }

    private function offerHint(int $channelId,string $remoteId): array {
        $part='';$ean='';$pnk='';$name='';
        $firstScalar=static function(mixed $value): string {
            if(is_scalar($value))return trim((string)$value);
            if(is_array($value))foreach($value as $entry)if(is_scalar($entry)&&trim((string)$entry)!=='')return trim((string)$entry);
            return '';
        };
        try{
            $st=$this->db->prepare('SELECT oi.sku,oi.name,oi.product_url,o.raw_json FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.channel_id=? AND oi.remote_product_id=? ORDER BY oi.id DESC LIMIT 5');$st->execute([$channelId,$remoteId]);
            foreach($st->fetchAll() as $row){
                if($part==='')$part=trim((string)($row['sku']??''));if($name==='')$name=trim((string)($row['name']??''));if($pnk==='')$pnk=$this->extractPnk((string)($row['product_url']??''));
                $raw=json_decode((string)($row['raw_json']??'{}'),true);if(!is_array($raw))continue;
                foreach((array)($raw['products']??$raw['items']??[]) as $p){
                    if(!is_array($p)||trim((string)($p['product_id']??$p['product_offer_id']??$p['offer_id']??''))!==$remoteId)continue;
                    foreach(['part_number','sku','vendor_sku'] as $key){$v=$firstScalar($p[$key]??'');if($v!==''){$part=$v;break;}}
                    foreach(['ean','barcode'] as $key){$v=$firstScalar($p[$key]??'');if($v!==''){$ean=$v;break;}}
                    foreach(['part_number_key','pnk','product_part_number_key','emag_part_number_key'] as $key){$v=$firstScalar($p[$key]??'');if($v!==''){$pnk=$v;break;}}
                    break;
                }
                if($part!==''&&$ean!==''&&$pnk!=='')break;
            }
        }catch(Throwable){}
        return ['part_number'=>$part,'ean'=>$ean,'pnk'=>$pnk,'name'=>$name];
    }

    private function publicUrlFromPnk(string $pnk,array $channel): string {
        $pnk=trim($pnk);if($pnk==='')return '';
        $country=strtoupper((string)($channel['country']??'RO'));$base=$country==='BG'?'https://www.emag.bg':'https://www.emag.ro';
        return $base.($country==='BG'?'/product/pd/':'/produs/pd/').rawurlencode($pnk).'/';
    }

    /** Best-effort browser-like cURL session: warm eMAG cookies, then open the exact hyperlink. */
    private function browserLikeProductMetadata(string $productUrl,int $timeoutSeconds=10): array {
        if(!function_exists('curl_init'))return [];$productUrl=$this->normalizeHttpUrl($productUrl);if($productUrl===''||!$this->isEmagProductUrl($productUrl))return [];
        $scheme=(string)(parse_url($productUrl,PHP_URL_SCHEME)??'https');$host=(string)(parse_url($productUrl,PHP_URL_HOST)??'');if($host==='')return [];$origin=$scheme.'://'.$host.'/';$ch=curl_init();if(!$ch)return [];$status=0;$effective=$productUrl;$body='';
        try{
            $common=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>max(6,min(15,$timeoutSeconds)),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_COOKIEFILE=>'',CURLOPT_HTTP_VERSION=>defined('CURL_HTTP_VERSION_2TLS')?CURL_HTTP_VERSION_2TLS:CURL_HTTP_VERSION_1_1,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36'];
            curl_setopt_array($ch,$common+[CURLOPT_URL=>$origin,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,*/*;q=0.8','Accept-Language: ro-RO,ro;q=0.9,en;q=0.7']]);@curl_exec($ch);
            curl_setopt_array($ch,[CURLOPT_URL=>$productUrl,CURLOPT_REFERER=>$origin,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8','Accept-Language: ro-RO,ro;q=0.9,en;q=0.7','Cache-Control: no-cache','Upgrade-Insecure-Requests: 1']]);
            $raw=curl_exec($ch);if(is_string($raw))$body=$raw;$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$effective=(string)(curl_getinfo($ch,CURLINFO_EFFECTIVE_URL)?:$productUrl);
        }catch(Throwable){}finally{curl_close($ch);}if($status<200||$status>=400)return ['_diagnostic'=>'storefront_blocked','_http_status'=>$status];
        $meta=$this->parsePublicProductHtml($body,$this->normalizeHttpUrl($effective)!==''?$this->normalizeHttpUrl($effective):$productUrl);if($this->metadataMatchesRequestedPnk($meta,$productUrl))return $meta+['source'=>'emag_cookie_session','_http_status'=>$status];
        return ['_diagnostic'=>'storefront_unreadable','_http_status'=>$status];
    }

    /** Fetch canonical URL and catalogue image using only free eMAG-owned sources. */
    /** Fetch metadata only when the returned product is provably the requested PNK. */
    private function publicProductMetadata(string $productUrl,int $timeoutSeconds=10): array {
        $this->publicFetchDiagnostic=[];$productUrl=$this->normalizeHttpUrl($productUrl);if($productUrl===''||!$this->isEmagProductUrl($productUrl))return [];static $requestCache=[];$host=strtolower((string)(parse_url($productUrl,PHP_URL_HOST)??''));$pnk=strtoupper($this->extractPnk($productUrl));$cacheKey=$host.'|'.($pnk!==''?$pnk:$productUrl);if(array_key_exists($cacheKey,$requestCache))return $requestCache[$cacheKey];$profileIndex=0;$lastStatus=0;$blocked=false;
        foreach($this->publicPageHeaderProfiles($productUrl) as $headers){try{$perTry=max(3,min(8,(int)ceil($timeoutSeconds/2)));$r=Http::request('GET',$productUrl,$headers,null,$perTry);$status=(int)($r['status']??0);$lastStatus=$status;if(in_array($status,[403,429,511],true))$blocked=true;if($status>=200&&$status<400){$effective=$this->normalizeHttpUrl((string)($r['info']['effective_url']??$productUrl));$direct=$this->parsePublicProductHtml((string)($r['body']??''),$effective!==''?$effective:$productUrl);if($this->metadataMatchesRequestedPnk($direct,$productUrl))return $requestCache[$cacheKey]=$direct+['source'=>$profileIndex===0?'emag_page':'emag_page_crawler'];}}catch(Throwable){}$profileIndex++;}
        $session=$this->browserLikeProductMetadata($productUrl,$timeoutSeconds);if($this->metadataMatchesRequestedPnk($session,$productUrl))return $requestCache[$cacheKey]=$session;$sessionStatus=(int)($session['_http_status']??0);if($sessionStatus>0)$lastStatus=$sessionStatus;if(in_array($sessionStatus,[403,429,511],true))$blocked=true;
        $search=$this->emagSearchMetadata($productUrl,max(5,min(20,$timeoutSeconds+4)));if($this->metadataMatchesRequestedPnk($search,$productUrl))return $requestCache[$cacheKey]=$search;
        $this->publicFetchDiagnostic=['diagnostic'=>$blocked?'storefront_blocked':'storefront_unreadable','http_status'=>$lastStatus];
        return $requestCache[$cacheKey]=[];
    }

    private function publicPageHeaders(string $referer=''): array { return $this->publicPageHeaderProfiles($referer)[0]; }

    /** Different legitimate crawler/browser profiles. eMAG WAF sometimes treats them differently. */
    private function publicPageHeaderProfiles(string $referer=''): array {
        $referer=$this->normalizeHttpUrl($referer);
        $base=[
            'Accept-Language: ro-RO,ro;q=0.9,en;q=0.7',
            'Cache-Control: max-age=0',
        ];
        if($referer!=='')$base[]='Referer: '.$referer;
        $profiles=[
            array_merge(['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8','User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36'],$base),
            array_merge(['Accept: text/html,application/xhtml+xml,*/*;q=0.8','User-Agent: facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],$base),
            array_merge(['Accept: text/html,application/xhtml+xml,*/*;q=0.8','User-Agent: Twitterbot/1.0'],$base),
        ];
        return $profiles;
    }

    /** Free-only resolver: public eMAG listing HTML plus best-effort sibling storefront lookup for the exact PNK. */
    private function emagSearchMetadata(string $requestedUrl,int $timeoutSeconds=14): array {
        $requestedUrl=$this->normalizeHttpUrl($requestedUrl);if($requestedUrl===''||!$this->isEmagProductUrl($requestedUrl))return [];
        $pnk=$this->extractPnk($requestedUrl);if($pnk==='')return [];
        $requestedHost=strtolower((string)(parse_url($requestedUrl,PHP_URL_HOST)??''));
        if(str_contains($requestedHost,'.bg'))$markets=['bg','ro','hu'];
        elseif(str_contains($requestedHost,'.hu'))$markets=['hu','ro','bg'];
        else $markets=['ro','bg','hu'];

        $perRequest=max(3,min(9,(int)ceil($timeoutSeconds/4)));
        foreach($markets as $index=>$market){
            $base=$this->emagStorefrontBase($market);if($base==='')continue;
            foreach($this->emagSearchPageUrls($market,$pnk) as $searchUrl){
                $profileIndex=0;
                foreach(array_slice($this->publicPageHeaderProfiles($base.'/'),0,2) as $headers){
                    try{
                        $r=Http::request('GET',$searchUrl,$headers,null,$perRequest);$status=(int)($r['status']??0);
                        if($status>=200&&$status<400){
                            $body=(string)($r['body']??'');
                            $meta=$this->parseEmagSearchHtml($body,$searchUrl,$requestedUrl);
                            if(!$this->metadataMatchesRequestedPnk($meta,$requestedUrl))$meta=$this->parseEmbeddedListingJson($body,$requestedUrl);
                            if($this->metadataMatchesRequestedPnk($meta,$requestedUrl)){
                                if($index>0)$meta['product_url']=$requestedUrl;
                                $source=$index===0?'emag_search_html':'emag_cross_market_html';
                                if($profileIndex>0)$source.='_crawler';
                                return $meta+['source'=>$source];
                            }
                        }
                    }catch(Throwable){}
                    $profileIndex++;
                }
            }
        }
        return [];
    }

    private function emagStorefrontBase(string $market): string {
        return match(strtolower(trim($market))){'ro'=>'https://www.emag.ro','bg'=>'https://www.emag.bg','hu'=>'https://www.emag.hu',default=>''};
    }

    /** Public listing URL variants only; no private or paid endpoint is required. */
    private function emagSearchPageUrls(string $market,string $pnk): array {
        $base=$this->emagStorefrontBase($market);if($base==='')return [];$q=rawurlencode($pnk);
        return [$base.'/search/'.$q,$base.'/search/'.$q.'?ref=effective_search'];
    }

    private function metadataMatchesRequestedPnk(array $meta,string $requestedUrl): bool {
        $image=trim((string)($meta['image_url']??''));if(!$this->isOfficialEmagImageUrl($image))return false;$wanted=strtoupper($this->extractPnk($requestedUrl));if($wanted==='')return false;$code=strtoupper(trim((string)($meta['product_code']??'')));$productUrl=trim((string)($meta['product_url']??''));$fromUrl=strtoupper($this->extractPnk($productUrl));if($code!==''&&!hash_equals($wanted,$code))return false;if($fromUrl!==''&&!hash_equals($wanted,$fromUrl))return false;return $code!==''||$fromUrl!=='';
    }

    private function parseEmagSearchJson(array $json,string $requestedUrl): array {
        $wanted=strtoupper($this->extractPnk($requestedUrl));if($wanted==='')return [];$matches=[];
        $walk=null;$walk=function(mixed $node,int $depth=0) use (&$walk,&$matches,$wanted): void {
            if($depth>10||!is_array($node))return;
            $nodeCode='';$nodeUrl='';
            foreach(['pnk','part_number_key','product_part_number_key','emag_part_number_key','product_code'] as $key){$v=$node[$key]??null;if(is_scalar($v)&&trim((string)$v)!==''){$nodeCode=strtoupper(trim((string)$v));break;}}
            foreach(['url','product_url','web_url','public_url','permalink','card_url'] as $key){$v=$node[$key]??null;if(!is_scalar($v))continue;$candidate=$this->normalizeEmagCandidateUrl((string)$v);if($candidate!==''&&$this->extractPnk($candidate)!==''){$nodeUrl=$candidate;break;}}
            $urlCode=strtoupper($this->extractPnk($nodeUrl));
            if(($nodeCode!==''&&hash_equals($wanted,$nodeCode))||($urlCode!==''&&hash_equals($wanted,$urlCode)))$matches[]=$node;
            foreach($node as $value)if(is_array($value))$walk($value,$depth+1);
        };
        $walk($json,0);
        foreach($matches as $node){
            $canonical=$this->findExactProductUrlInNode($node,$wanted);$image=$this->findTrustedImageInNode($node);
            if($canonical!==''){$reqHost=preg_replace('/^www\./','',strtolower((string)(parse_url($requestedUrl,PHP_URL_HOST)??'')));$canHost=preg_replace('/^www\./','',strtolower((string)(parse_url($canonical,PHP_URL_HOST)??'')));if($reqHost!==$canHost)$canonical=$requestedUrl;}
            if($image!=='')return ['image_url'=>$image,'product_url'=>$canonical!==''?$canonical:$requestedUrl,'product_code'=>$wanted];
        }
        return [];
    }

    /** eMAG may serialize listingGlobals/data.items into script tags even when cards are lazy-rendered. */
    private function parseEmbeddedListingJson(string $html,string $requestedUrl): array {
        $wanted=strtoupper($this->extractPnk($requestedUrl));if($wanted===''||$html==='')return [];
        $candidates=[];
        if(preg_match_all('~<script[^>]*>(.*?)</script>~is',$html,$scripts)){
            foreach($scripts[1] as $script){
                if(stripos($script,$wanted)===false||stripos($script,'image')===false)continue;
                $decoded=html_entity_decode((string)$script,ENT_QUOTES|ENT_HTML5,'UTF-8');
                // Pure JSON / JSON-LD style script.
                $json=json_decode(trim($decoded),true);if(is_array($json))$candidates[]=$json;
                // JS assignment: locate balanced JSON objects around listingGlobals/items payloads.
                foreach(['listingGlobals','"items"','data.items'] as $marker){
                    $pos=strpos($decoded,$marker);if($pos===false)continue;
                    $start=strrpos(substr($decoded,0,$pos),'{');if($start===false)continue;
                    $chunk=$this->balancedJsonObject(substr($decoded,$start));if($chunk==='')continue;
                    $obj=json_decode($chunk,true);if(is_array($obj))$candidates[]=$obj;
                }
            }
        }
        foreach($candidates as $json){$meta=$this->parseEmagSearchJson($json,$requestedUrl);if($this->metadataMatchesRequestedPnk($meta,$requestedUrl))return $meta;}
        return [];
    }

    /** Best-effort balanced JSON extraction from a JS assignment. */
    private function balancedJsonObject(string $value): string {
        $value=ltrim($value);if($value===''||$value[0]!=='{')return '';$depth=0;$quote='';$escape=false;$len=strlen($value);
        for($i=0;$i<$len;$i++){$c=$value[$i];if($quote!==''){if($escape){$escape=false;continue;}if($c==='\\'){$escape=true;continue;}if($c===$quote)$quote='';continue;}if($c==='"'||$c==="'"){$quote=$c;continue;}if($c==='{')$depth++;elseif($c==='}'){if(--$depth===0)return substr($value,0,$i+1);}}
        return '';
    }

    /** Parse only the card whose link contains the exact requested /pd/<PNK>/. */
    private function parseEmagSearchHtml(string $html,string $effectiveUrl,string $requestedUrl): array {
        $wanted=strtoupper($this->extractPnk($requestedUrl));
        if($wanted===''||trim($html)==='')return [];
        $canonical='';$image='';

        if(class_exists('DOMDocument')){
            $prev=libxml_use_internal_errors(true);$dom=new \DOMDocument();@$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($prev);
            $pnksInNode=function(\DOMElement $node) use ($effectiveUrl): array {
                $pnks=[];
                if(strtolower($node->tagName)==='a'){
                    $u=$this->absoluteEmagUrl((string)$node->getAttribute('href'),$effectiveUrl);$p=strtoupper($this->extractPnk($u));if($p!=='')$pnks[$p]=true;
                }
                foreach($node->getElementsByTagName('a') as $link){$u=$this->absoluteEmagUrl((string)$link->getAttribute('href'),$effectiveUrl);$p=strtoupper($this->extractPnk($u));if($p!=='')$pnks[$p]=true;}
                return array_keys($pnks);
            };
            $imageInNode=function(\DOMElement $node) use ($effectiveUrl): string {
                foreach(['img','source'] as $tag){
                    foreach($node->getElementsByTagName($tag) as $img){
                        foreach(['src','data-src','data-original','srcset','data-srcset'] as $attr){
                            $raw=trim((string)$img->getAttribute($attr));if($raw==='')continue;
                            foreach(preg_split('/\s*,\s*/',$raw)?:[] as $part){
                                $value=trim((string)(preg_split('/\s+/',trim($part))[0]??''));
                                $value=$this->absoluteEmagUrl(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),$effectiveUrl);
                                if($value!==''&&$this->isOfficialEmagImageUrl($value))return $value;
                            }
                        }
                    }
                }
                return '';
            };
            foreach($dom->getElementsByTagName('a') as $a){
                $href=$this->absoluteEmagUrl((string)$a->getAttribute('href'),$effectiveUrl);
                if($href===''||strtoupper($this->extractPnk($href))!==$wanted)continue;
                $node=$a;
                // An ancestor is eligible only while every product link inside it points to
                // the same requested PNK. The moment a grid contains a neighboring PNK we stop,
                // so its image can never be borrowed accidentally.
                for($level=0;$level<6&&$node;$level++,$node=$node->parentNode){
                    if(!$node instanceof \DOMElement)continue;
                    $seenPnks=$pnksInNode($node);if($seenPnks&&($seenPnks!==[$wanted]))break;
                    $candidate=$imageInNode($node);
                    if($candidate!==''){$canonical=$href;$image=$candidate;break 2;}
                }
            }
        }else{
            // Minimal safe fallback for hosts without ext-dom: inspect only the contents of
            // an <a> whose href itself contains the exact requested PNK.
            if(preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',$html,$anchors,PREG_SET_ORDER)){
                foreach($anchors as $anchor){
                    $href=$this->absoluteEmagUrl((string)($anchor[1]??''),$effectiveUrl);
                    if($href===''||strtoupper($this->extractPnk($href))!==$wanted)continue;
                    $inner=(string)($anchor[2]??'');
                    if(!preg_match_all('~(?:src|data-src|data-original|srcset|data-srcset)=["\']([^"\']+)["\']~i',$inner,$attrs))continue;
                    foreach($attrs[1] as $raw){
                        foreach(preg_split('/\s*,\s*/',(string)$raw)?:[] as $part){
                            $value=trim((string)(preg_split('/\s+/',trim($part))[0]??''));
                            $value=$this->absoluteEmagUrl(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),$effectiveUrl);
                            if($value!==''&&$this->isOfficialEmagImageUrl($value)){$canonical=$href;$image=$value;break 3;}
                        }
                    }
                }
            }
        }
        if($image===''||$canonical===''||strtoupper($this->extractPnk($canonical))!==$wanted)return [];
        return ['image_url'=>$image,'product_url'=>$canonical,'product_code'=>$wanted];
    }

    private function absoluteEmagUrl(string $value,string $baseUrl): string {
        $value=html_entity_decode(trim($value),ENT_QUOTES|ENT_HTML5,'UTF-8');if($value==='')return '';
        if(str_starts_with($value,'//'))return $this->normalizeHttpUrl('https:'.$value);
        if(preg_match('#^https?://#i',$value))return $this->normalizeHttpUrl($value);
        if(!str_starts_with($value,'/'))return '';
        $host=(string)(parse_url($baseUrl,PHP_URL_HOST)??'');if($host==='')return '';
        return $this->normalizeHttpUrl('https://'.$host.$value);
    }

    private function normalizeEmagCandidateUrl(string $value): string {
        $value=trim($value);if($value==='')return '';
        if(preg_match('#^https?://#i',$value)||str_starts_with($value,'//'))return $this->normalizeHttpUrl($value);
        if(str_starts_with($value,'/')){
            foreach(['https://www.emag.ro','https://www.emag.bg','https://www.emag.hu'] as $base){$u=$this->normalizeHttpUrl($base.$value);if($this->extractPnk($u)!=='')return $u;}
        }
        return '';
    }

    private function findExactProductUrlInNode(array $node,string $wanted): string {
        $result='';$walk=null;$walk=function(mixed $value,int $depth=0) use (&$walk,&$result,$wanted): void {
            if($result!==''||$depth>8)return;
            if(is_string($value)){$u=$this->normalizeEmagCandidateUrl($value);if($u!==''&&strtoupper($this->extractPnk($u))===$wanted)$result=$u;return;}
            if(!is_array($value))return;foreach($value as $child)$walk($child,$depth+1);
        };$walk($node,0);return $result;
    }

    private function findTrustedImageInNode(array $node): string {
        $preferred=[];$fallback=[];$walk=null;$walk=function(mixed $value,string $key='',int $depth=0) use (&$walk,&$preferred,&$fallback): void {
            if($depth>9)return;
            if(is_string($value)){
                $candidate=str_replace('\\/','/',$value);$candidate=$this->normalizeHttpUrl($candidate);if($candidate===''||!$this->isOfficialEmagImageUrl($candidate))return;
                $isImageKey=(bool)preg_match('/image|picture|photo|thumb|gallery|media/i',$key);if($isImageKey)$preferred[]=$candidate;else $fallback[]=$candidate;return;
            }
            if(!is_array($value))return;foreach($value as $k=>$child)$walk($child,(string)$k,$depth+1);
        };$walk($node,'',0);foreach(array_merge($preferred,$fallback) as $u)if($u!=='')return $u;return '';
    }

    /** Separated for deterministic tests; accepts only eMAG canonical URLs in production. */
    /** Separated for deterministic tests; metadata must carry its own exact product URL. */
    private function parsePublicProductHtml(string $html,string $effectiveUrl): array {
        $image='';$canonical='';$ogUrl='';$ldUrl='';
        if($html==='')return ['image_url'=>'','product_url'=>''];

        if(class_exists('DOMDocument')){
            $prev=libxml_use_internal_errors(true);$dom=new \DOMDocument();@$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($prev);
            foreach($dom->getElementsByTagName('meta') as $meta){
                $prop=strtolower(trim((string)$meta->getAttribute('property')));$name=strtolower(trim((string)$meta->getAttribute('name')));$content=trim((string)$meta->getAttribute('content'));
                if($image===''&&in_array($prop,['og:image','og:image:secure_url'],true)){$candidate=$this->normalizeHttpUrl($content);if($this->isOfficialEmagImageUrl($candidate))$image=$candidate;}
                if($ogUrl===''&&$prop==='og:url')$ogUrl=$this->normalizeHttpUrl($content);
                if($image===''&&$name==='twitter:image'){$candidate=$this->normalizeHttpUrl($content);if($this->isOfficialEmagImageUrl($candidate))$image=$candidate;}
            }
            foreach($dom->getElementsByTagName('link') as $link){
                if(strtolower(trim((string)$link->getAttribute('rel')))!=='canonical')continue;
                $canonical=$this->normalizeHttpUrl((string)$link->getAttribute('href'));if($canonical!=='')break;
            }
            foreach($dom->getElementsByTagName('script') as $script){
                if(strtolower(trim((string)$script->getAttribute('type')))!=='application/ld+json')continue;
                $json=json_decode(trim((string)$script->textContent),true);if(!is_array($json))continue;
                $ld=$this->productJsonLdMetadata($json,$effectiveUrl);
                if($image===''&&isset($ld['image_url']))$image=(string)$ld['image_url'];
                if($ldUrl===''&&isset($ld['product_url']))$ldUrl=(string)$ld['product_url'];
            }
        }else{
            if(preg_match('/<meta[^>]+(?:property|name)=["\'](?:og:image|og:image:secure_url|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m)){
                $candidate=$this->normalizeHttpUrl(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));if($this->isOfficialEmagImageUrl($candidate))$image=$candidate;
            }
            if(preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i',$html,$m))$canonical=$this->normalizeHttpUrl(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i',$html,$m))$ogUrl=$this->normalizeHttpUrl(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',$html,$scripts)){
                foreach($scripts[1] as $raw){
                    $json=json_decode(html_entity_decode(trim((string)$raw),ENT_QUOTES|ENT_HTML5,'UTF-8'),true);if(!is_array($json))continue;
                    $ld=$this->productJsonLdMetadata($json,$effectiveUrl);
                    if($image===''&&isset($ld['image_url']))$image=(string)$ld['image_url'];
                    if($ldUrl===''&&isset($ld['product_url']))$ldUrl=(string)$ld['product_url'];
                }
            }
        }

        $productUrl='';
        foreach([$canonical,$ogUrl,$ldUrl] as $candidate){if($candidate!==''&&$this->isEmagProductUrl($candidate)){$productUrl=$candidate;break;}}
        return ['image_url'=>$this->isOfficialEmagImageUrl($image)?$image:'','product_url'=>$productUrl];
    }

    /** Extract Product image/url from JSON-LD; do not infer identity from the requested URL. */
    private function productJsonLdMetadata(array $json,string $effectiveUrl): array {
        $product=null;$walk=null;$walk=function(mixed $node,int $depth=0) use (&$walk,&$product): void {if($product!==null||$depth>10||!is_array($node))return;$type=$node['@type']??null;$types=is_array($type)?$type:[$type];foreach($types as $t){if(is_string($t)&&strtolower(trim($t))==='product'){$product=$node;return;}}foreach($node as $child)if(is_array($child))$walk($child,$depth+1);};$walk($json,0);if(!is_array($product))return [];$productUrl='';foreach(['url','@id'] as $key){if(!isset($product[$key])||!is_scalar($product[$key]))continue;$u=$this->normalizeHttpUrl((string)$product[$key]);if($u!==''&&$this->isEmagProductUrl($u)){$productUrl=$u;break;}}if($productUrl==='')return [];$images=$product['image']??[];if(is_string($images))$images=[$images];elseif(is_array($images)&&isset($images['url']))$images=[$images];if(!is_array($images))$images=[];foreach($images as $entry){$value=is_array($entry)?($entry['url']??$entry['contentUrl']??''):$entry;if(!is_scalar($value))continue;$u=$this->normalizeHttpUrl(str_replace('\\/','/',(string)$value));if($this->isOfficialEmagImageUrl($u))return ['image_url'=>$u,'product_url'=>$productUrl];}return [];
    }

    private function downloadToLocalCache(string $sourceUrl,string $channelCode,string $remoteId,array $existing): array {
        $candidates=[$sourceUrl];
        if(str_starts_with(strtolower($sourceUrl),'http://'))$candidates[]='https://'.substr($sourceUrl,7);
        $errors=[];
        foreach(array_values(array_unique($candidates)) as $candidate){
            try{
                $headers=[
                    'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/152.0.0.0 Safari/537.36',
                    'Cache-Control: no-cache',
                    'Referer: '.(str_contains(strtolower($candidate),'emag.bg')?'https://www.emag.bg/':'https://www.emag.ro/'),
                ];
                $r=Http::request('GET',$candidate,$headers,null,15);
                $body=(string)($r['body']??'');$status=(int)($r['status']??0);
                if($status<200||$status>=400){$errors[]='HTTP '.$status.' la '.$candidate;continue;}
                return $this->storeImageBody($body,$channelCode,$remoteId,$existing);
            }catch(Throwable $e){$errors[]=$e->getMessage();}
        }
        throw new RuntimeException('Imaginea sursa nu a putut fi salvata local. '.implode(' | ',array_slice($errors,0,2)));
    }

    /** Separated from HTTP so hash/cache behavior can be verified deterministically. */
    private function storeImageBody(string $body,string $channelCode,string $remoteId,array $existing): array {
        if(strlen($body)<32)throw new RuntimeException('Imaginea eMAG descarcata este goala sau invalida.');
        if(strlen($body)>8*1024*1024)throw new RuntimeException('Imaginea eMAG depaseste limita de 8 MB.');
        $mime='';if(function_exists('getimagesizefromstring')){$info=@getimagesizefromstring($body);if(is_array($info))$mime=(string)($info['mime']??'');}
        $ext=match($mime){'image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/jpeg'=>'jpg',default=>''};
        if($ext==='')throw new RuntimeException('Fisierul primit de la URL-ul eMAG nu este o imagine JPG/PNG/WEBP/GIF valida.');
        $hash=hash('sha256',$body);
        $oldHash=trim((string)($existing['image_hash']??''));$oldUrl=trim((string)($existing['local_url']??''));
        if($oldHash!==''&&hash_equals($oldHash,$hash)&&$oldUrl!==''&&$this->localCacheFileExists($oldUrl))return ['url'=>$oldUrl,'hash'=>$hash];

        $dir=dirname(__DIR__,2).'/public/uploads/emag-media';
        if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Folderul public/uploads/emag-media nu poate fi creat.');
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim($channelCode.'-'.$remoteId))?:sha1($channelCode.'-'.$remoteId);$safe=substr($safe,0,110);
        $file=$dir.'/'.$safe.'.'.$ext;$tmp=$file.'.tmp-'.bin2hex(random_bytes(4));
        if(@file_put_contents($tmp,$body,LOCK_EX)===false)throw new RuntimeException('Imaginea eMAG nu a putut fi scrisa in cache.');
        if(!@rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Imaginea eMAG nu a putut fi publicata in cache.');}
        foreach(['jpg','jpeg','png','webp','gif'] as $other){$candidate=$dir.'/'.$safe.'.'.$other;if($candidate!==$file&&is_file($candidate))@unlink($candidate);}
        return ['url'=>app_path('/uploads/emag-media/'.basename($file)).'?v='.substr($hash,0,12),'hash'=>$hash];
    }

    private function isManualLocalUrl(string $url): bool {
        $path=(string)(parse_url(trim($url),PHP_URL_PATH)??'');$base='/uploads/emag-media/';$pos=strpos($path,$base);if($pos===false)return false;$name=basename(substr($path,$pos+strlen($base)));return str_starts_with($name,'manual-')&&$this->localCacheFileExists($url);
    }

    private function localCacheFileExists(string $url): bool {
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');$base='/uploads/emag-media/';$pos=strpos($path,$base);if($pos===false)return false;
        $name=basename(substr($path,$pos+strlen($base)));if($name===''||$name==='.'||$name==='..')return false;
        $file=dirname(__DIR__,2).'/public/uploads/emag-media/'.$name;return is_file($file)&&filesize($file)>32;
    }


    private function mediaRow(int $channelId,string $remoteId): array {
        $st=$this->db->prepare('SELECT * FROM emag_product_media WHERE channel_id=? AND remote_product_id=? LIMIT 1');$st->execute([$channelId,$remoteId]);$row=$st->fetch();return is_array($row)?$row:[];
    }

    private function saveMedia(int $channelId,string $remoteId,?string $sourceUrl,?string $localUrl,?string $hash,?string $productUrl,?string $syncedAt,string $checkedAt,?string $error): void {
        $existing=$this->mediaRow($channelId,$remoteId);
        if($existing){
            // NULL means "preserve existing"; an empty string means "clear stale value".
            $pick=static fn(?string $incoming,mixed $current): mixed => $incoming===null?$current:($incoming===''?null:$incoming);
            $st=$this->db->prepare('UPDATE emag_product_media SET source_url=?,local_url=?,image_hash=?,product_url=?,synced_at=?,checked_at=?,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE channel_id=? AND remote_product_id=?');
            $st->execute([$pick($sourceUrl,$existing['source_url']??null),$pick($localUrl,$existing['local_url']??null),$pick($hash,$existing['image_hash']??null),$pick($productUrl,$existing['product_url']??null),$pick($syncedAt,$existing['synced_at']??null),$checkedAt,$error,$channelId,$remoteId]);
        }else{
            $st=$this->db->prepare('INSERT INTO emag_product_media(channel_id,remote_product_id,source_url,local_url,image_hash,product_url,synced_at,checked_at,last_error) VALUES(?,?,?,?,?,?,?,?,?)');
            $st->execute([$channelId,$remoteId,$sourceUrl,$localUrl,$hash,$productUrl,$syncedAt,$checkedAt,$error]);
        }
    }

    private function recordFailure(int $channelId,string $remoteId,string $message): void {
        $message=trim($message);if(strlen($message)>1000)$message=substr($message,0,1000);
        try{$this->saveMedia($channelId,$remoteId,null,null,null,null,null,date('Y-m-d H:i:s'),$message);}catch(Throwable){}
    }

    private function propagateToOrderItems(int $channelId,string $remoteId,string $localUrl,string $productUrl): void {
        $st=$this->db->prepare('SELECT oi.id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.channel_id=? AND oi.remote_product_id=?');$st->execute([$channelId,$remoteId]);$ids=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        if(!$ids)return;$update=$this->db->prepare("UPDATE order_items SET image_url=?,product_url=CASE WHEN ?<>'' THEN ? WHEN product_url LIKE '%/search/%' THEN NULL ELSE product_url END WHERE id=?");
        foreach($ids as $id)$update->execute([$localUrl,$productUrl,$productUrl,$id]);
    }

    /** Bounded local migration for old orders whose stored raw_json already has product_id. */
    private function backfillMissingRemoteProductIds(int $channelId,int $limitOrders): void {
        $limitOrders=max(1,min(500,$limitOrders));
        $sql="SELECT DISTINCT o.id FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.channel_id=? AND o.remote_deleted=0 AND (oi.remote_product_id IS NULL OR oi.remote_product_id='') ORDER BY o.id DESC LIMIT ".$limitOrders;
        $st=$this->db->prepare($sql);$st->execute([$channelId]);
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $orderId)$this->backfillOrderRemoteProductIds((int)$orderId);
    }

    /**
     * Historical repair through order/read. Only a handful of orders are queried per worker run.
     * Failed orders rotate after a cooldown instead of blocking the rest of the history forever.
     */
    private function backfillMissingRemoteProductIdsFromApi(array $channel,EmagClient $client,int $limitOrders=6,bool $force=false): array {
        $channelId=(int)($channel['id']??0);$limitOrders=max(1,min(20,$limitOrders));$cooldown=1800;
        $sql="SELECT DISTINCT o.id,b.checked_at
              FROM orders o
              JOIN order_items oi ON oi.order_id=o.id
              LEFT JOIN emag_order_product_backfill b ON b.order_id=o.id
              WHERE o.channel_id=? AND o.remote_deleted=0
                AND (oi.remote_product_id IS NULL OR oi.remote_product_id='')
              ORDER BY CASE WHEN b.checked_at IS NULL OR b.checked_at='' THEN 0 ELSE 1 END,
                       b.checked_at ASC,o.id DESC
              LIMIT 120";
        try{$st=$this->db->prepare($sql);$st->execute([$channelId]);$rows=$st->fetchAll();}
        catch(Throwable){return ['checked'=>0,'resolved'=>0,'errors'=>0,'skipped'=>'backfill_table_unavailable'];}
        $out=['checked'=>0,'resolved'=>0,'errors'=>0];
        foreach($rows as $row){
            if($out['checked']>=$limitOrders)break;
            $last=strtotime((string)($row['checked_at']??''));
            if(!$force&&$last!==false&&(time()-$last)<$cooldown)continue;
            $out['checked']++;
            $r=$this->recoverOrderRemoteProductIdsFromApi((int)$row['id'],$channel,$client,$force);
            if(!empty($r['resolved']))$out['resolved']++;elseif(!empty($r['error']))$out['errors']++;
        }
        return $out;
    }

    /** Recover missing seller product IDs for one historical order from eMAG order/read. */
    private function recoverOrderRemoteProductIdsFromApi(int $orderId,array $channel,EmagClient $client,bool $force=false): array {
        $channelId=(int)($channel['id']??0);$missingBefore=$this->missingRemoteProductCount($orderId);
        if($missingBefore<=0){$this->saveBackfillState($orderId,$channelId,true,null);return ['resolved'=>true,'updated'=>0,'remaining'=>0];}

        $st=$this->db->prepare('SELECT external_id FROM orders WHERE id=? AND channel_id=? AND remote_deleted=0 LIMIT 1');$st->execute([$orderId,$channelId]);$externalId=trim((string)$st->fetchColumn());
        if($externalId===''){$msg='Comanda istorica nu are ID extern eMAG.';$this->saveBackfillState($orderId,$channelId,false,$msg);return ['resolved'=>false,'updated'=>0,'remaining'=>$missingBefore,'error'=>$msg];}

        if(!$force){
            try{$state=$this->db->prepare('SELECT checked_at FROM emag_order_product_backfill WHERE order_id=? LIMIT 1');$state->execute([$orderId]);$last=strtotime((string)$state->fetchColumn());if($last!==false&&(time()-$last)<1800)return ['resolved'=>false,'updated'=>0,'remaining'=>$missingBefore,'skipped'=>'cooldown'];}
            catch(Throwable){}
        }

        try{
            $fresh=$client->readOrderById($externalId);
            if(!$fresh)throw new RuntimeException('order/read nu a returnat comanda externa '.$externalId.'.');
            $products=(array)($fresh['products']??$fresh['items']??[]);
            if(!$products)throw new RuntimeException('order/read a returnat comanda, dar fara produse.');
            $updated=$this->applyRemoteProductIdsFromProducts($orderId,$products);
            $remaining=$this->missingRemoteProductCount($orderId);
            if($remaining>0){
                $msg='order/read a fost citit, dar '.(string)$remaining.' linie(i) nu au putut fi asociate cu product_id.';
                $this->saveBackfillState($orderId,$channelId,false,$msg);
                if($updated>0)$this->logBackfill($channelId,'info','Product ID eMAG recuperat partial pentru comanda '.$externalId.'.',['order_id'=>$orderId,'external_id'=>$externalId,'updated'=>$updated,'remaining'=>$remaining]);
                return ['resolved'=>false,'updated'=>$updated,'remaining'=>$remaining,'error'=>$msg];
            }
            $this->saveBackfillState($orderId,$channelId,true,null);
            if($updated>0)$this->logBackfill($channelId,'success','Product ID eMAG recuperat pentru comanda istorica '.$externalId.'.',['order_id'=>$orderId,'external_id'=>$externalId,'updated'=>$updated]);
            return ['resolved'=>true,'updated'=>$updated,'remaining'=>0];
        }catch(Throwable $e){
            $msg=$e->getMessage();$this->saveBackfillState($orderId,$channelId,false,$msg);
            $this->logBackfill($channelId,'warning','Recuperarea product_id pentru o comanda eMAG veche a esuat.',['order_id'=>$orderId,'external_id'=>$externalId,'error'=>$msg]);
            return ['resolved'=>false,'updated'=>0,'remaining'=>$missingBefore,'error'=>$msg];
        }
    }

    private function backfillOrderRemoteProductIds(int $orderId): int {
        $st=$this->db->prepare('SELECT raw_json FROM orders WHERE id=? LIMIT 1');$st->execute([$orderId]);$raw=json_decode((string)$st->fetchColumn(),true);if(!is_array($raw))return 0;
        $products=(array)($raw['products']??$raw['items']??[]);if(!$products)return 0;
        return $this->applyRemoteProductIdsFromProducts($orderId,$products);
    }

    /** Map eMAG order product rows back onto local order_items without changing prices/statuses. */
    private function applyRemoteProductIdsFromProducts(int $orderId,array $products): int {
        $byLine=[];$bySku=[];$byName=[];$allPids=[];
        $addUnique=static function(array &$map,string $key,string $pid): void {
            $key=trim($key);if($key==='')return;
            if(!array_key_exists($key,$map))$map[$key]=$pid;
            elseif($map[$key]!==$pid)$map[$key]='';
        };
        $normName=static function(string $name): string {
            $name=trim(preg_replace('/\s+/u',' ',$name)??$name);
            return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        };
        foreach($products as $p){
            if(!is_array($p))continue;
            $pid=trim((string)($p['product_id']??$p['product_offer_id']??$p['offer_id']??''));if($pid==='')continue;
            $allPids[]=$pid;
            foreach(['id','order_product_id','order_item_id'] as $key)$addUnique($byLine,(string)($p[$key]??''),$pid);
            foreach(['part_number','part_number_key','sku','vendor_sku'] as $key)$addUnique($bySku,(string)($p[$key]??''),$pid);
            $name=$normName((string)($p['name']??$p['product_name']??''));if($name!=='')$addUnique($byName,$name,$pid);
        }
        if(!$allPids)return 0;
        $items=$this->db->prepare('SELECT id,external_item_id,sku,name,remote_product_id FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);$rows=$items->fetchAll();
        $up=$this->db->prepare('UPDATE order_items SET remote_product_id=? WHERE id=?');$updated=0;$unmatched=[];
        foreach($rows as $item){
            if(trim((string)($item['remote_product_id']??''))!=='')continue;
            $line=trim((string)($item['external_item_id']??''));$sku=trim((string)($item['sku']??''));$name=$normName((string)($item['name']??''));
            $pid=($line!==''?($byLine[$line]??''):'');
            if($pid===''&&$sku!=='')$pid=$bySku[$sku]??'';
            if($pid===''&&$name!=='')$pid=$byName[$name]??'';
            // Some historical imports stored product_id itself as external_item_id.
            if($pid===''&&$line!==''&&in_array($line,$allPids,true))$pid=$line;
            if($pid!==''){$up->execute([$pid,(int)$item['id']]);$updated++;}
            else $unmatched[]=(int)$item['id'];
        }
        // Safe final fallback for the common one-product historical order.
        $uniquePids=array_values(array_unique($allPids));
        if(count($unmatched)===1&&count($uniquePids)===1){$up->execute([$uniquePids[0],$unmatched[0]]);$updated++;}
        return $updated;
    }

    private function missingRemoteProductCount(int $orderId): int {
        $st=$this->db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id=? AND (remote_product_id IS NULL OR remote_product_id='')");$st->execute([$orderId]);return (int)$st->fetchColumn();
    }

    /** Persist retry state without relying on driver-specific UPSERT syntax. */
    private function saveBackfillState(int $orderId,int $channelId,bool $resolved,?string $error): void {
        try{
            $error=$error!==null?trim($error):null;if($error!==null&&strlen($error)>1000)$error=substr($error,0,1000);$now=date('Y-m-d H:i:s');$resolvedAt=$resolved?$now:null;
            $st=$this->db->prepare('SELECT id FROM emag_order_product_backfill WHERE order_id=? LIMIT 1');$st->execute([$orderId]);$id=(int)$st->fetchColumn();
            if($id>0){$up=$this->db->prepare('UPDATE emag_order_product_backfill SET channel_id=?,checked_at=?,resolved_at=?,attempt_count=attempt_count+1,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$up->execute([$channelId,$now,$resolvedAt,$error,$id]);}
            else{$ins=$this->db->prepare('INSERT INTO emag_order_product_backfill(order_id,channel_id,checked_at,resolved_at,attempt_count,last_error) VALUES(?,?,?,?,1,?)');$ins->execute([$orderId,$channelId,$now,$resolvedAt,$error]);}
        }catch(Throwable){}
    }

    private function logBackfill(int $channelId,string $level,string $message,array $context=[]): void {
        // Media/product-id recovery is diagnostic-only and must not pollute the user Journal.
    }

    /** State used by the order page after the background synchronization finishes. */
    private function orderItemState(int $orderId): array {
        $sql='SELECT oi.id,oi.remote_product_id,oi.image_url item_image_url,oi.product_url item_product_url,o.channel_id,m.local_url,m.source_url,m.product_url,m.synced_at,m.checked_at,m.last_error FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN emag_product_media m ON m.channel_id=o.channel_id AND m.remote_product_id=oi.remote_product_id WHERE oi.order_id=? ORDER BY oi.id';
        $st=$this->db->prepare($sql);$st->execute([$orderId]);$out=[];
        foreach($st->fetchAll() as $row){$product=trim((string)($row['product_url']??''));if(!$this->isEmagProductUrl($product))$product=trim((string)($row['item_product_url']??''));if(!$this->isEmagProductUrl($product))$product='';$expectedPnk=$product!==''?$this->extractPnk($product):'';$verified=$this->mediaRowIsVerified((int)($row['channel_id']??0),$row,$expectedPnk);$display=$verified?$this->mediaDisplayUrl($row):'';if($display===''&&$this->isManualLocalUrl((string)($row['item_image_url']??'')))$display=(string)$row['item_image_url'];$error=preg_replace('/^\[(?:MANUAL_IMAGE(?::\d+)?|EMAG_API_EXACT)\]\s*/','',(string)($row['last_error']??''))??'';$out[]=['id'=>(int)$row['id'],'remote_product_id'=>(string)($row['remote_product_id']??''),'image_url'=>$display,'product_url'=>$product,'synced_at'=>(string)($row['synced_at']??''),'checked_at'=>(string)($row['checked_at']??''),'error'=>$error];}
        return $out;
    }
}
