<?php
namespace App\Services;

use App\Core\Database;
use App\Integrations\EmagClient;
use App\Integrations\WooCommerceClient;
use RuntimeException;
use PDO;

final class OrderOperations {
    private PDO $db;
    public function __construct(?PDO $db=null){ $this->db=$db ?: Database::connection(); }

    public function getOrder(int $id): array {
        $st=$this->db->prepare('SELECT o.*,c.code channel_code,c.name channel_name,c.type channel_type,c.country channel_country FROM orders o JOIN channels c ON c.id=o.channel_id WHERE o.id=? AND o.remote_deleted=0');
        $st->execute([$id]);$o=$st->fetch();if(!$o)throw new RuntimeException('Comanda nu exista sau a fost stearsa din platforma sursa.');
        $x=$this->db->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $x->execute([$id]);
        $o['items']=$x->fetchAll();
        $o['billing']=json_decode((string)($o['billing_json']??'{}'),true)?:[];
        $o['shipping']=json_decode((string)($o['shipping_json']??'{}'),true)?:[];
        $o['raw']=json_decode((string)($o['raw_json']??'{}'),true)?:[];
        if((string)($o['channel_type']??'')==='woocommerce') $o['billing']=WooOrderNormalizer::enrichBilling((array)$o['billing'],(array)$o['raw']);
        elseif((string)($o['channel_type']??'')==='emag'){
            $normalized=EmagOrderNormalizer::normalize((array)$o['raw'],['country'=>$o['channel_country']??'RO']);
            $o['billing']=(array)$normalized['billing'];$o['shipping']=(array)$normalized['shipping'];
            if(trim((string)$normalized['customer_name'])!=='')$o['customer_name']=(string)$normalized['customer_name'];
            if(trim((string)$normalized['email'])!=='')$o['customer_email']=(string)$normalized['email'];
            if(trim((string)$normalized['phone'])!=='')$o['customer_phone']=(string)$normalized['phone'];
            $o['total']=EmagOrderNormalizer::total((array)$o['raw']);
            $rawProducts=[];foreach((array)($o['raw']['products']??[]) as $rp)if(is_array($rp)){$key=(string)($rp['id']??$rp['product_id']??'');if($key!=='')$rawProducts[$key]=$rp;}
            foreach($o['items'] as &$item){$rp=$rawProducts[(string)($item['external_item_id']??'')]??null;if(is_array($rp)){$item['unit_price']=EmagOrderNormalizer::grossPrice($rp);$item['vat_rate']=EmagOrderNormalizer::vatPercent($rp['vat']??$rp['vat_rate']??0);}}unset($item);
            $this->enrichEmagItemMedia($o);
            foreach($o['items'] as &$item){if(str_contains((string)($item['product_url']??''),'/search/'))$item['product_url']='';}unset($item);
        }
        return $o;
    }

    /**
     * Attach only local eMAG cache metadata. This method performs no network request.
     * Media is optional: a missing/partially upgraded cache must NEVER break order preview.
     */
    private function enrichEmagItemMedia(array &$order): void {
        /*
         * Fail closed for eMAG thumbnails. A product picture is shown only when the media
         * row proves the same exact PNK as the order and has authoritative provenance.
         */
        $country=strtoupper((string)($order['channel_country']??'RO'));
        $expectedHosts=$country==='BG'?['emag.bg','www.emag.bg']:['emag.ro','www.emag.ro'];
        $expectedPnkByRemote=[];
        foreach((array)($order['raw']['products']??$order['raw']['items']??[]) as $rp){
            if(!is_array($rp))continue;
            $rid=trim((string)($rp['product_id']??$rp['product_offer_id']??$rp['offer_id']??''));if($rid==='')continue;
            foreach(['part_number_key','pnk','product_part_number_key','emag_part_number_key'] as $key){$v=trim((string)($rp[$key]??''));if($v!==''){$expectedPnkByRemote[$rid]=strtoupper($v);break;}}
        }
        foreach($order['items'] as &$item){
            $storedUrl=trim((string)($item['product_url']??''));$rid=trim((string)($item['remote_product_id']??''));$expected=$expectedPnkByRemote[$rid]??'';$storedImage=trim((string)($item['image_url']??''));
            $item['image_url']=$this->isManualEmagMediaUrl($storedImage)?$storedImage:'';
            if($this->isDirectEmagProductUrl($storedUrl,$expectedHosts)){
                $pnk=$this->extractEmagPnk($storedUrl);
                $item['product_url']=$expected===''||hash_equals($expected,$pnk)?$storedUrl:'';
            }else $item['product_url']='';
        }unset($item);
        try{
            $remoteIds=[];
            foreach((array)($order['items']??[]) as $item){$id=trim((string)($item['remote_product_id']??''));if($id!=='')$remoteIds[$id]=true;}
            if(!$remoteIds)return;
            $ids=array_keys($remoteIds);$ph=implode(',',array_fill(0,count($ids),'?'));
            $params=array_merge([(int)$order['channel_id']],$ids);
            $st=$this->db->prepare('SELECT remote_product_id,source_url,local_url,product_url,synced_at,checked_at,last_error FROM emag_product_media WHERE channel_id=? AND remote_product_id IN ('.$ph.')');
            $st->execute($params);$map=[];
            foreach($st->fetchAll() as $row)$map[(string)$row['remote_product_id']]=$row;
            foreach($order['items'] as &$item){
                $rid=trim((string)($item['remote_product_id']??''));$media=$map[$rid]??null;if(!is_array($media))continue;
                $verified=$this->verifiedEmagMedia((int)$order['channel_id'],$media,$expectedPnkByRemote[$rid]??'',$expectedHosts);
                if($verified['image_url']!=='')$item['image_url']=$verified['image_url'];elseif(!$this->isManualEmagMediaUrl((string)($item['image_url']??'')))$item['image_url']='';
                if($verified['product_url']!=='')$item['product_url']=$verified['product_url'];
                $item['image_synced_at']=(string)($media['synced_at']??'');$item['image_checked_at']=(string)($media['checked_at']??'');$item['image_sync_error']=(string)($media['last_error']??'');
            }unset($item);
        }catch(\Throwable){
            // Blank is safer than showing the wrong product to warehouse staff.
            return;
        }
    }

    private function isDirectEmagProductUrl(string $url,array $allowedHosts): bool {
        if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return false;
        $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));$path=(string)(parse_url($url,PHP_URL_PATH)??'');
        return in_array($host,$allowedHosts,true)&&(bool)preg_match('#/pd/[^/]+/?#i',$path);
    }

    private function extractEmagPnk(string $url): string {
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');
        return preg_match('~/pd/([^/?#]+)/?~i',$path,$m)?strtoupper(trim(rawurldecode((string)$m[1]))):'';
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

    private function safeCatalogueLinkExists(int $channelId,string $pnk): bool {
        if($pnk==='')return false;
        try{
            $st=$this->db->prepare('SELECT mapping_source,match_reason FROM emag_product_links WHERE channel_id=? AND UPPER(pnk)=UPPER(?) LIMIT 1');$st->execute([$channelId,$pnk]);$row=$st->fetch();
            if(!is_array($row))return false;if((string)($row['mapping_source']??'')==='manual')return true;
            return in_array((string)($row['match_reason']??''),['ean','sku_ean','sku_name'],true);
        }catch(\Throwable){return false;}
    }

    /** @return array{image_url:string,product_url:string} */
    private function verifiedEmagMedia(int $channelId,array $media,string $expectedPnk,array $allowedHosts): array {
        $productUrl=trim((string)($media['product_url']??''));if(!$this->isDirectEmagProductUrl($productUrl,$allowedHosts))return ['image_url'=>'','product_url'=>''];
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

    private function isAllowedEmagMediaUrl(string $url,array $allowedHosts): bool {
        return $this->isLocalEmagMediaUrl($url)||$this->isOfficialEmagImageUrl($url);
    }

    public function setStatus(int $id,string $status,string $reason='',?int $emagCancellationReason=null): void {
        $allowed=['completed','cancelled','refunded'];if(!in_array($status,$allowed,true))throw new RuntimeException('Status invalid.');$o=$this->getOrder($id);$client=ChannelFactory::client($o['channel_code']);
        if($client instanceof WooCommerceClient){$remote=$status==='refunded'?'refunded':$status;$client->updateOrder($o['external_id'],['status'=>$remote]);if($reason!=='')$client->createOrderNote($o['external_id'],$reason,true);}
        elseif($client instanceof EmagClient){
            $raw=(array)($o['raw']??[]);
            if((int)($raw['type']??3)===2 && in_array($status,['cancelled','completed'],true)) throw new RuntimeException('Comanda este Fulfilled by eMAG; statusul logistic este administrat de eMAG.');
            if($status==='cancelled'){
                if((string)$o['status']==='new') throw new RuntimeException('Comanda eMAG este inca Noua. Nu o poti anula din seller inainte de acknowledge; verifica mai intai statusul actual in Marketplace.');
                if($emagCancellationReason===null || !in_array($emagCancellationReason,[1,2,3],true)) throw new RuntimeException('Selecteaza un motiv de anulare eMAG valid.');
                $client->updateOrderStatus($o['external_id'],0,['cancellation_reason'=>$emagCancellationReason]);
            } elseif($status==='completed'){
                if((string)$o['status']==='new') throw new RuntimeException('Preia mai intai comanda eMAG.');
                $client->updateOrderStatus($o['external_id'],4);
            }
            /* Stornata este stare financiara locala; nu o transformam automat in eMAG Returned. */
        }
        else throw new RuntimeException('Integrarea canalului este dezactivata; statusul nu poate fi actualizat extern.');
        $this->db->prepare('UPDATE orders SET status=?,status_note=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,$reason?:null,$id]);
    }


    /**
     * Operational behavior from v0.11.6: opening a new order marks it as being processed.
     * Called only from a detached background request so the details UI never waits for marketplace latency.
     */
    public function markOpenedProcessing(int $id): string {
        $o=$this->getOrder($id);$status=(string)($o['status']??'');
        if((string)($o['channel_type']??'')==='emag'){
            $raw=(array)($o['raw']??[]);if((int)($raw['type']??3)===2)return $status;
            if($status==='new'||(int)($raw['status']??-1)===1){$this->acknowledgeEmag($id);return 'processing';}
            return $status;
        }
        if((string)($o['channel_type']??'')==='woocommerce' && in_array($status,['new','pending'],true)){
            $client=ChannelFactory::client((string)$o['channel_code']);if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea WooCommerce este dezactivata.');
            $client->updateOrder($o['external_id'],['status'=>'processing']);$raw=(array)($o['raw']??[]);$raw['status']='processing';
            $this->db->prepare("UPDATE orders SET status='processing',raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
            return 'processing';
        }
        return $status;
    }

    public function acknowledgeEmag(int $id): void {
        $o=$this->getOrder($id);
        if((string)($o['channel_type']??'')!=='emag') throw new RuntimeException('Aceasta actiune este disponibila doar pentru comenzile eMAG.');
        $raw=(array)($o['raw']??[]);
        if((int)($raw['type']??3)===2) throw new RuntimeException('Comanda este Fulfilled by eMAG; procesarea logistica este administrata de eMAG.');
        if((string)$o['status']!=='new' && (int)($raw['status']??-1)!==1) return;
        if((int)($raw['status']??-1)>1){
            $this->db->prepare("UPDATE orders SET status='processing',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
            return;
        }
        $client=ChannelFactory::client((string)$o['channel_code']);
        if(!$client instanceof EmagClient) throw new RuntimeException('Integrarea eMAG pentru aceasta tara este dezactivata.');
        $client->acknowledge($o['external_id']);
        $raw=(array)($o['raw']??[]);$raw['status']=2;
        $this->db->prepare("UPDATE orders SET status='processing',raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
    }

    public function cancelOrder(int $id,string $reason=''): void {$this->setStatus($id,'cancelled',$reason);}

    public function setStatusBulk(array $ids,string $status,string $reason=''): array {
        $ok=0;$errors=[];foreach(array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0))) as $id){try{$this->setStatus($id,$status,$reason);$ok++;}catch(\Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}}return ['ok'=>$ok,'errors'=>$errors];
    }
}
