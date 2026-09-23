<?php
namespace App\Services;

use App\Core\Database;
use App\Core\ModuleManager;
use App\Integrations\WooCommerceClient;
use PDO;
use RuntimeException;

final class ProductService {
    private PDO $db;
    public function __construct(?PDO $db=null){ $this->db=$db ?: Database::connection(); }

    public function syncWooChannel(array $channel,WooCommerceClient $client,int $maxPages=100): array {
        $count=0;$new=0;$page=1;$maxPages=max(1,min(250,$maxPages));$seen=[];$complete=false;$trashVisible=true;
        while($page<=$maxPages){
            try {$pageData=$this->readWooProductPage($client,['page'=>$page,'per_page'=>100,'orderby'=>'id','order'=>'asc']);$rows=$pageData['rows'];$trashVisible=($trashVisible??true)&&$pageData['includes_trash'];}
            catch(RuntimeException $e){if($page>1&&str_contains($e->getMessage(),'rest_post_invalid_page_number')){$complete=true;break;}throw $e;}
            if(!$rows){$complete=true;break;}
            foreach($rows as $row){if(!is_array($row))continue;$external=(string)($row['id']??'');if($external!=='')$seen[$external]=true;$r=$this->upsertWooProduct($channel,$row);$count++;if($r['new_mapping'])$new++;}
            if(count($rows)<100){$complete=true;break;}$page++;
        }
        $removed=0;
        if($complete){$removed=$this->reconcileChannelProducts((int)$channel['id'],array_keys($seen),!$trashVisible);}
        $dedupe=$this->reconcileWooDuplicates();
        return ['count'=>$count,'new'=>$new,'removed'=>$removed,'pages'=>$page,'trash_supported'=>$trashVisible,'deduplicated'=>(int)($dedupe['merged']??0)];
    }


    /** Lightweight automatic WooCommerce product refresh: only products modified since the last successful pass. */
    public function syncWooChannelIncremental(array $channel,WooCommerceClient $client,string $modifiedAfter,int $maxPages=10): array {
        $count=0;$new=0;$page=1;$maxPages=max(1,min(50,$maxPages));$complete=false;$trashVisible=true;
        while($page<=$maxPages){
            try{$pageData=$this->readWooProductPage($client,['page'=>$page,'per_page'=>100,'orderby'=>'modified','order'=>'asc','modified_after'=>$modifiedAfter,'dates_are_gmt'=>'true']);$rows=$pageData['rows'];$trashVisible=$trashVisible&&$pageData['includes_trash'];}
            catch(RuntimeException $e){if($page>1&&str_contains($e->getMessage(),'rest_post_invalid_page_number')){$complete=true;break;}throw $e;}
            if(!$rows){$complete=true;break;}
            foreach($rows as $row){if(!is_array($row))continue;$r=$this->upsertWooProduct($channel,$row);$count++;if($r['new_mapping'])$new++;}
            if(count($rows)<100){$complete=true;break;}$page++;
        }
        $dedupe=$this->reconcileWooDuplicates();return ['count'=>$count,'new'=>$new,'removed'=>0,'pages'=>$page,'incremental'=>true,'complete'=>$complete,'trash_supported'=>$trashVisible,'deduplicated'=>(int)($dedupe['merged']??0)];
    }

    public function upsertWooProduct(array $channel,array $p): array {
        $external=(string)($p['id']??'');if($external==='')throw new RuntimeException('Produs WooCommerce fara ID.');
        $sku=trim((string)($p['sku']??''));$masterSku=$sku!==''?$sku:'WC-'.strtoupper((string)$channel['code']).'-'.$external;
        $name=trim((string)($p['name']??('Produs '.$external)));$ean=trim((string)($p['global_unique_id']??''));if($ean==='')$ean=$this->metaValue($p,'_global_unique_id');
        $description=(string)($p['description']??'');$short=(string)($p['short_description']??'');$stock=(int)($p['stock_quantity']??0);$image=$this->firstImage($p);
        $q=$this->db->prepare('SELECT id,product_id,stock FROM channel_products WHERE channel_id=? AND external_id=?');$q->execute([(int)$channel['id'],$external]);$mapping=$q->fetch();$newMapping=!$mapping;$oldStock=$mapping?(int)$mapping['stock']:null;$productId=$mapping?(int)$mapping['product_id']:0;
        if(!$productId&&$sku!==''){$x=$this->db->prepare('SELECT id FROM products WHERE sku=? AND archived=0');$x->execute([$sku]);$productId=(int)($x->fetchColumn()?:0);}
        if(!$productId&&$ean!==''){$x=$this->db->prepare('SELECT id FROM products WHERE ean=? AND archived=0 ORDER BY id ASC LIMIT 2');$x->execute([$ean]);$eanMatches=array_values(array_unique(array_map('intval',$x->fetchAll(PDO::FETCH_COLUMN))));if(count($eanMatches)===1)$productId=(int)$eanMatches[0];}
        if(!$productId){$unique=$masterSku;$n=1;while(true){$x=$this->db->prepare('SELECT id FROM products WHERE sku=?');$x->execute([$unique]);if(!$x->fetchColumn())break;$unique=$masterSku.'-'.$n++;}$st=$this->db->prepare('INSERT INTO products(sku,ean,name,description,short_description,image_url,stock,archived,cost_price) VALUES(?,?,?,?,?,?,?,0,NULL)');$st->execute([$unique,$ean?:null,$name,$description,$short,$image?:null,$stock]);$productId=(int)$this->db->lastInsertId();}
        else {$st=$this->db->prepare('UPDATE products SET ean=COALESCE(NULLIF(?,\'\'),ean),name=?,description=?,short_description=?,image_url=COALESCE(NULLIF(?,\'\'),image_url),archived=0,updated_at=CURRENT_TIMESTAMP WHERE id=?');$st->execute([$ean,$name,$description,$short,$image,$productId]);}
        $images=$this->normalizeImages($p['images']??[]);$categories=$this->normalizeTerms($p['categories']??[]);$tags=$this->normalizeTerms($p['tags']??[]);$brands=$this->normalizeTerms($p['brands']??[]);
        $seoTitle=$this->metaValue($p,'rank_math_title');$seoDescription=$this->metaValue($p,'rank_math_description');$seoFocus=$this->metaValue($p,'rank_math_focus_keyword');$seoScore=$this->metaInt($p,'rank_math_seo_score');
        $regular=(string)($p['regular_price']??'');$price=(float)($regular!==''?$regular:($p['price']??0));$sale=(string)($p['sale_price']??'');$currency=(string)($channel['currency']??'RON');
        $data=['product_id'=>$productId,'external_sku'=>$sku,'price'=>$price,'sale_price'=>$sale,'currency'=>$currency,'stock'=>$stock,'status'=>in_array((string)($p['status']??'publish'),['publish','private'],true)?1:0,'remote_status'=>(string)($p['status']??'publish'),'permalink'=>(string)($p['permalink']??''),'name'=>$name,'description'=>$description,'short_description'=>$short,'image_json'=>json_encode($images,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'categories_json'=>json_encode($categories,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'tags_json'=>json_encode($tags,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'brands_json'=>json_encode($brands,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'seo_title'=>$seoTitle,'seo_description'=>$seoDescription,'seo_focus_keyword'=>$seoFocus,'seo_score'=>$seoScore,'raw_json'=>json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if(!$mapping){$sql='INSERT INTO channel_products(channel_id,product_id,external_id,external_sku,price,sale_price,currency,stock,status,remote_status,permalink,name,description,short_description,image_json,categories_json,tags_json,brands_json,seo_title,seo_description,seo_focus_keyword,seo_score,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';$st=$this->db->prepare($sql);$st->execute([(int)$channel['id'],$productId,$external,$data['external_sku'],$data['price'],$data['sale_price'],$data['currency'],$data['stock'],$data['status'],$data['remote_status'],$data['permalink'],$data['name'],$data['description'],$data['short_description'],$data['image_json'],$data['categories_json'],$data['tags_json'],$data['brands_json'],$data['seo_title'],$data['seo_description'],$data['seo_focus_keyword'],$data['seo_score'],$data['raw_json']]);}
        else {$st=$this->db->prepare('UPDATE channel_products SET product_id=?,external_sku=?,price=?,sale_price=?,currency=?,stock=?,status=?,remote_status=?,permalink=?,name=?,description=?,short_description=?,image_json=?,categories_json=?,tags_json=?,brands_json=?,seo_title=?,seo_description=?,seo_focus_keyword=?,seo_score=?,raw_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$st->execute([$productId,$data['external_sku'],$data['price'],$data['sale_price'],$data['currency'],$data['stock'],$data['status'],$data['remote_status'],$data['permalink'],$data['name'],$data['description'],$data['short_description'],$data['image_json'],$data['categories_json'],$data['tags_json'],$data['brands_json'],$data['seo_title'],$data['seo_description'],$data['seo_focus_keyword'],$data['seo_score'],$data['raw_json'],(int)$mapping['id']]);}
        $this->refreshMasterStock($productId);
        if($newMapping || $oldStock!==$stock){
            (new ModuleManager($this->db))->dispatch('product.stock_changed',[
                'product_id'=>$productId,'channel_id'=>(int)$channel['id'],'channel_code'=>(string)$channel['code'],'channel_name'=>(string)$channel['name'],
                'product_name'=>$name,'old_stock'=>$oldStock,'new_stock'=>$stock,'new_mapping'=>$newMapping,'external_id'=>$external
            ]);
        }
        return ['product_id'=>$productId,'new_mapping'=>$newMapping];
    }

    public function markRemoteDeleted(string $channelCode,string|int $externalId): void {
        $channel=$this->channelByCode($channelCode);$st=$this->db->prepare('SELECT product_id FROM channel_products WHERE channel_id=? AND external_id=?');$st->execute([(int)$channel['id'],(string)$externalId]);$productId=(int)($st->fetchColumn()?:0);
        if(!$productId)return;$this->db->prepare('DELETE FROM channel_products WHERE channel_id=? AND external_id=?')->execute([(int)$channel['id'],(string)$externalId]);$this->archiveIfOrphan($productId);
    }

    private function reconcileChannelProducts(int $channelId,array $seen,bool $preserveTrash=false): int {
        $q=$this->db->prepare('SELECT id,product_id,external_id,remote_status FROM channel_products WHERE channel_id=?');$q->execute([$channelId]);$removed=0;$seenMap=array_fill_keys(array_map('strval',$seen),true);
        foreach($q->fetchAll() as $row){if(isset($seenMap[(string)$row['external_id']]))continue;if($preserveTrash&&(string)($row['remote_status']??'')==='trash')continue;$this->db->prepare('DELETE FROM channel_products WHERE id=?')->execute([(int)$row['id']]);$this->archiveIfOrphan((int)$row['product_id']);$removed++;}
        return $removed;
    }

    public function getProduct(int $id): array {
        $st=$this->db->prepare('SELECT * FROM products WHERE id=?');$st->execute([$id]);$p=$st->fetch();if(!$p)throw new RuntimeException('Produsul nu exista.');
        $st=$this->db->prepare('SELECT cp.*,c.code channel_code,c.name channel_name,c.type channel_type,c.currency channel_currency FROM channel_products cp JOIN channels c ON c.id=cp.channel_id WHERE cp.product_id=? ORDER BY c.name');$st->execute([$id]);$p['channels']=$st->fetchAll();
        foreach($p['channels'] as &$cp){$cp['images']=json_decode((string)($cp['image_json']??'[]'),true)?:[];$cp['categories']=json_decode((string)($cp['categories_json']??'[]'),true)?:[];$cp['tags']=json_decode((string)($cp['tags_json']??'[]'),true)?:[];$cp['brands']=json_decode((string)($cp['brands_json']??'[]'),true)?:[];$raw=json_decode((string)($cp['raw_json']??'{}'),true)?:[];$cp['rank_math']=$this->rankMathData($raw);if(($cp['seo_score']??null)===null&&isset($cp['rank_math']['score']))$cp['seo_score']=$cp['rank_math']['score'];}unset($cp);return $p;
    }

    public function updateFromForm(int $productId,array $post,array $files=[]): array {
        $product=$this->getProduct($productId);$targets=array_values(array_filter((array)($post['targets']??[]),fn($x)=>is_string($x)&&$x!==''));if(!$targets)throw new RuntimeException('Selecteaza cel putin un magazin.');
        $payloadBase=$this->payloadBase($post);$categoryNames=$this->parseNames((string)($post['categories']??''));$tagNames=$this->parseNames((string)($post['tags']??''));$brandNames=$this->parseNames((string)($post['brands']??''));$imageUrls=$this->resolveImageUrls($post,$files,$product);$seo=$this->rankMathPayload($post);$results=[];
        foreach($product['channels'] as $cp){if($cp['channel_type']!=='woocommerce'||!in_array($cp['channel_code'],$targets,true))continue;$client=ChannelFactory::client($cp['channel_code']);if(!$client instanceof WooCommerceClient)throw new RuntimeException($cp['channel_name'].' este dezactivat in Setari.');$payload=$this->decoratePayload($client,$payloadBase,$categoryNames,$tagNames,$brandNames,$imageUrls,$seo);$updated=$client->updateProduct($cp['external_id'],$payload);$this->upsertWooProduct($this->channelByCode($cp['channel_code']),$updated);$results[$cp['channel_code']]=$updated;}
        if(!$results)throw new RuntimeException('Produsul nu este mapat pe niciunul dintre magazinele selectate.');$this->db->prepare('UPDATE products SET sku=?,ean=?,name=?,description=?,short_description=?,image_url=?,stock=?,archived=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$payloadBase['sku'],$payloadBase['global_unique_id']?:null,$payloadBase['name'],$payloadBase['description'],$payloadBase['short_description'],$imageUrls[0]??null,$payloadBase['stock_quantity'],$productId]);return $results;
    }

    public function quickUpdate(int $productId,array $post): array {
        $product=$this->getProduct($productId);$targets=array_values(array_filter((array)($post['targets']??[])));if(!$targets)$targets=array_column(array_filter($product['channels'],fn($c)=>$c['channel_type']==='woocommerce'),'channel_code');
        $payload=[];foreach(['name','sku','regular_price','sale_price'] as $k){if(array_key_exists($k,$post)&&trim((string)$post[$k])!=='')$payload[$k]=trim((string)$post[$k]);}
        if(isset($post['stock_quantity'])&&$post['stock_quantity']!==''){$payload['manage_stock']=true;$payload['stock_quantity']=(int)$post['stock_quantity'];}
        if(isset($post['status'])&&in_array($post['status'],['publish','draft','pending','private'],true))$payload['status']=$post['status'];
        if(!$payload)throw new RuntimeException('Nu ai completat nicio modificare.');$out=[];
        foreach($product['channels'] as $cp){if($cp['channel_type']!=='woocommerce'||!in_array($cp['channel_code'],$targets,true))continue;$client=ChannelFactory::client($cp['channel_code']);if(!$client instanceof WooCommerceClient)continue;$updated=$client->updateProduct($cp['external_id'],$payload);$this->upsertWooProduct($this->channelByCode($cp['channel_code']),$updated);$out[$cp['channel_code']]=$updated;}
        if(!$out)throw new RuntimeException('Niciun magazin selectat nu contine produsul.');
        $sets=[];$vals=[];if(isset($payload['name'])){$sets[]='name=?';$vals[]=$payload['name'];}if(isset($payload['sku'])){$sets[]='sku=?';$vals[]=$payload['sku'];}if(isset($payload['stock_quantity'])){$sets[]='stock=?';$vals[]=(int)$payload['stock_quantity'];}if($sets){$sets[]='updated_at=CURRENT_TIMESTAMP';$vals[]=$productId;$this->db->prepare('UPDATE products SET '.implode(',',$sets).' WHERE id=?')->execute($vals);}return $out;
    }

    public function bulkUpdate(array $ids,array $post): array {$ok=0;$errors=[];foreach($this->cleanIds($ids) as $id){try{$this->quickUpdate($id,$post);$ok++;}catch(\Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}}return ['ok'=>$ok,'errors'=>$errors];}
    public function bulkTransfer(array $ids,string $source,string $target): array {$ok=0;$errors=[];foreach($this->cleanIds($ids) as $id){try{$this->transfer($id,$source,$target);$ok++;}catch(\Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}}return ['ok'=>$ok,'errors'=>$errors];}

    public function deleteProducts(array $ids,array $targets): array {
        $ok=0;$errors=[];$targets=array_values(array_intersect(['univera','alfamed'],$targets));if(!$targets)throw new RuntimeException('Selecteaza cel putin un magazin din care sa stergi produsele.');
        foreach($this->cleanIds($ids) as $id){try{$p=$this->getProduct($id);$affected=false;foreach($p['channels'] as $cp){if(!in_array($cp['channel_code'],$targets,true)||$cp['channel_type']!=='woocommerce')continue;$client=ChannelFactory::client($cp['channel_code']);if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea '.$cp['channel_name'].' nu este activa.');$client->deleteProduct($cp['external_id'],true);$this->db->prepare('DELETE FROM channel_products WHERE id=?')->execute([(int)$cp['id']]);$affected=true;}if($affected){$this->archiveIfOrphan($id);$ok++;}else$errors[]='#'.$id.': produsul nu exista in magazinele selectate.';}catch(\Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}}
        return ['ok'=>$ok,'errors'=>$errors];
    }

    public function createFromForm(array $post,array $files=[]): array {
        $targets=array_values(array_intersect(['univera','alfamed'],(array)($post['targets']??[])));if(!$targets)throw new RuntimeException('Selecteaza cel putin un magazin destinatie.');
        if(filter_var($post['_auto_sku']??false,FILTER_VALIDATE_BOOL) || trim((string)($post['sku']??''))==='') $post['sku']=$this->nextSku();
        $base=$this->payloadBase($post);$cats=$this->parseNames((string)($post['categories']??''));$tags=$this->parseNames((string)($post['tags']??''));$brands=$this->parseNames((string)($post['brands']??''));$images=$this->resolveImageUrls($post,$files,null);$seo=$this->rankMathPayload($post);$created=[];
        if(filter_var($post['_auto_unique_sku']??false,FILTER_VALIDATE_BOOL)){
            $base['sku']=$this->uniqueSkuForTargets($base['sku'],$targets,(string)($post['_sku_seed']??$base['name']));
        }
        foreach($targets as $code){$client=ChannelFactory::client($code);if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea '.$code.' nu este activa.');$payload=$this->decoratePayload($client,$base,$cats,$tags,$brands,$images,$seo);$remote=$client->createProduct($payload);$r=$this->upsertWooProduct($this->channelByCode($code),$remote);$created[$code]=['remote'=>$remote,'product_id'=>$r['product_id'],'sku'=>$base['sku']];}
        return $created;
    }

    /** Generate the next sequential catalogue SKU (for example ON501 after ON500). */
    public function nextSku(?string $prefix=null): string {
        if($prefix===null)$prefix=(string)(new SettingService($this->db))->get('products.sku_prefix','ON');
        $prefix=strtoupper(trim((string)(preg_replace('/[^A-Za-z0-9_-]+/','',$prefix)??'')));
        if($prefix==='')$prefix='ON';
        $max=0;$values=[];
        $st=$this->db->prepare('SELECT sku FROM products WHERE sku LIKE ?');$st->execute([$prefix.'%']);$values=array_merge($values,$st->fetchAll(PDO::FETCH_COLUMN));
        $st=$this->db->prepare("SELECT external_sku FROM channel_products WHERE external_sku IS NOT NULL AND external_sku<>'' AND external_sku LIKE ?");$st->execute([$prefix.'%']);$values=array_merge($values,$st->fetchAll(PDO::FETCH_COLUMN));
        $pattern='/^'.preg_quote($prefix,'/').'(\d+)$/i';
        foreach($values as $value)if(preg_match($pattern,trim((string)$value),$m))$max=max($max,(int)$m[1]);
        $next=max(1,$max+1);
        while(true){$candidate=$prefix.$next;$q=$this->db->prepare("SELECT 1 FROM products WHERE sku=? UNION SELECT 1 FROM channel_products WHERE external_sku=? LIMIT 1");$q->execute([$candidate,$candidate]);if(!$q->fetchColumn())return $candidate;$next++;}
    }

    /**
     * Return a SKU that is accepted and currently unused on every selected WooCommerce store.
     * Auto-imported products often expose manufacturer SKUs already used in the catalogue;
     * using one shared unique SKU keeps the same central product mapped to both stores.
     */
    public function uniqueSkuForTargets(string $preferred,array $targets,string $seed=''): string {
        $targets=array_values(array_intersect(['univera','alfamed'],$targets));
        if(!$targets)throw new RuntimeException('Selecteaza cel putin un magazin destinatie.');
        $base=preg_replace('/[^A-Za-z0-9._-]+/','-',trim($preferred))??'';
        $base=trim($base,'-_.');
        $fingerprint=strtoupper(substr(sha1($seed!==''?$seed:($preferred.uniqid('',true))),0,6));
        if($base==='')$base='AI-'.$fingerprint;
        else $base=substr($base,0,70).'-'.$fingerprint;
        $base=substr($base,0,80);
        $clients=[];foreach($targets as $code){$client=ChannelFactory::client($code);if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea '.$code.' nu este activa.');$clients[$code]=$client;}
        for($i=0;$i<100;$i++){
            $suffix=$i===0?'':'-AI'.($i+1);$candidate=substr($base,0,max(1,100-strlen($suffix))).$suffix;$free=true;
            foreach($clients as $client){$found=$client->readProducts(['sku'=>$candidate,'per_page'=>1,'status'=>'any']);if(!empty($found)){$free=false;break;}}
            if($free)return $candidate;
        }
        return 'AI-'.strtoupper(substr(sha1($base.$seed.microtime(true)),0,16));
    }

    /**
     * Fast editor taxonomy catalogue built from the local WooCommerce mirror.
     *
     * Older builds loaded every category/tag/brand page from both stores whenever the
     * create/edit screen opened (potentially dozens of HTTP requests). Product forms must
     * render from local data only; missing terms can still be typed and are created on save.
     */
    public function taxonomyCatalog(array $codes=['univera','alfamed']): array {
        $codes=array_values(array_intersect(['univera','alfamed'],array_values(array_unique(array_map('strval',$codes)))));
        $result=['categories'=>[],'tags'=>[],'brands'=>[],'by_channel'=>[],'warnings'=>[]];
        foreach($codes as $code)$result['by_channel'][$code]=['categories'=>[],'tags'=>[],'brands'=>[]];
        if(!$codes)return $result;
        $ph=implode(',',array_fill(0,count($codes),'?'));
        try{
            $st=$this->db->prepare("SELECT c.code,cp.categories_json,cp.tags_json,cp.brands_json FROM channel_products cp JOIN channels c ON c.id=cp.channel_id WHERE c.type='woocommerce' AND c.code IN ($ph)");
            $st->execute($codes);
            $perChannel=[];$global=['categories'=>[],'tags'=>[],'brands'=>[]];
            foreach($codes as $code)$perChannel[$code]=['categories'=>[],'tags'=>[],'brands'=>[]];
            foreach($st->fetchAll() as $row){
                $code=(string)$row['code'];if(!isset($perChannel[$code]))continue;
                foreach(['categories'=>'categories_json','tags'=>'tags_json','brands'=>'brands_json'] as $kind=>$column){
                    $terms=json_decode((string)($row[$column]??'[]'),true);if(!is_array($terms))continue;
                    foreach($terms as $term){
                        if(!is_array($term))continue;$name=trim((string)($term['name']??''));if($name==='')continue;
                        $key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
                        if(!isset($perChannel[$code][$kind][$key]))$perChannel[$code][$kind][$key]=['id'=>(int)($term['id']??0),'name'=>$name,'slug'=>(string)($term['slug']??''),'parent'=>(int)($term['parent']??0)];
                        if(!isset($global[$kind][$key]))$global[$kind][$key]=['name'=>$name,'channels'=>[]];
                        if(!in_array($code,$global[$kind][$key]['channels'],true))$global[$kind][$key]['channels'][]=$code;
                    }
                }
            }
            foreach($codes as $code){foreach(['categories','tags','brands'] as $kind){$rows=array_values($perChannel[$code][$kind]);usort($rows,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));$result['by_channel'][$code][$kind]=$rows;}}
            foreach(['categories','tags','brands'] as $kind){$rows=array_values($global[$kind]);usort($rows,fn($a,$b)=>strnatcasecmp((string)$a['name'],(string)$b['name']));$result[$kind]=$rows;}
        }catch(\Throwable $e){$result['warnings'][]='Listele locale nu au putut fi citite: '.$e->getMessage();}
        return $result;
    }

    public function transfer(int $productId,string $sourceCode,string $targetCode): array {
        if($sourceCode===$targetCode)throw new RuntimeException('Magazinul sursa si destinatie trebuie sa fie diferite.');$product=$this->getProduct($productId);$source=null;$targetExisting=null;foreach($product['channels'] as $cp){if($cp['channel_code']===$sourceCode)$source=$cp;if($cp['channel_code']===$targetCode)$targetExisting=$cp;}
        if(!$source||$source['channel_type']!=='woocommerce')throw new RuntimeException('Produsul nu exista pe magazinul sursa.');if($targetExisting)throw new RuntimeException('Produsul este deja prezent pe magazinul destinatie.');$sourceClient=ChannelFactory::client($sourceCode);$targetClient=ChannelFactory::client($targetCode);if(!$sourceClient instanceof WooCommerceClient||!$targetClient instanceof WooCommerceClient)throw new RuntimeException('Activeaza ambele integrari WooCommerce in Setari.');$remote=$sourceClient->readProduct($source['external_id']);$remoteType=(string)($remote['type']??'simple');
        $payload=['name'=>(string)($remote['name']??$product['name']),'type'=>in_array($remoteType,['simple','external'],true)?$remoteType:'simple','status'=>(string)($remote['status']??'publish'),'sku'=>(string)($remote['sku']??$product['sku']),'global_unique_id'=>(string)($remote['global_unique_id']??$product['ean']??''),'regular_price'=>(string)($remote['regular_price']??''),'sale_price'=>(string)($remote['sale_price']??''),'description'=>(string)($remote['description']??''),'short_description'=>(string)($remote['short_description']??''),'manage_stock'=>(bool)($remote['manage_stock']??true),'stock_quantity'=>(int)($remote['stock_quantity']??0),'virtual'=>(bool)($remote['virtual']??false),'featured'=>(bool)($remote['featured']??false),'catalog_visibility'=>(string)($remote['catalog_visibility']??'visible'),'images'=>array_values(array_map(fn($i)=>['src'=>(string)$i['src'],'alt'=>(string)($i['alt']??'')],array_filter((array)($remote['images']??[]),fn($i)=>is_array($i)&&!empty($i['src'])))),'categories'=>$this->termPayload($targetClient,'categories',array_column((array)($remote['categories']??[]),'name')),'tags'=>$this->termPayload($targetClient,'tags',array_column((array)($remote['tags']??[]),'name'))];$brandNames=array_column((array)($remote['brands']??[]),'name');if($brandNames)$payload['brands']=$this->termPayload($targetClient,'brands',$brandNames);$seo=[];foreach(['rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_canonical_url','rank_math_pillar_content','rank_math_robots','rank_math_facebook_title','rank_math_facebook_description','rank_math_facebook_image','rank_math_twitter_title','rank_math_twitter_description','rank_math_twitter_image','rank_math_twitter_use_facebook','rank_math_twitter_card_type'] as $key){$value=$this->metaRaw($remote,$key);if($value!==null)$seo[]=['key'=>$key,'value'=>$value];}$payload['meta_data']=$seo;$created=$targetClient->createProduct($payload);$this->upsertWooProduct($this->channelByCode($targetCode),$created);return $created;
    }

    public function listRows(string $q='',string $channel='',string $productStatus='',int $limit=50,int $offset=0): array {
        [$where,$params]=$this->listWhere($q,$channel,$productStatus);
        $limit=max(1,min(500,$limit));$offset=max(0,$offset);
        $sql="SELECT p.*,
            (SELECT cp2.price FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='univera' LIMIT 1) univera_price,
            (SELECT cp2.stock FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='univera' LIMIT 1) univera_stock,
            (SELECT cp2.remote_status FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='univera' LIMIT 1) univera_status,
            (SELECT cp2.seo_score FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='univera' LIMIT 1) univera_seo_score,
            (SELECT cp2.price FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='alfamed' LIMIT 1) alfamed_price,
            (SELECT cp2.stock FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='alfamed' LIMIT 1) alfamed_stock,
            (SELECT cp2.remote_status FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='alfamed' LIMIT 1) alfamed_status,
            (SELECT cp2.seo_score FROM channel_products cp2 JOIN channels c2 ON c2.id=cp2.channel_id WHERE cp2.product_id=p.id AND c2.code='alfamed' LIMIT 1) alfamed_seo_score,
            COALESCE(GROUP_CONCAT(DISTINCT c.name),'-') mapped_channels
            FROM products p LEFT JOIN channel_products cp ON cp.product_id=p.id LEFT JOIN channels c ON c.id=cp.channel_id
            WHERE ".implode(' AND ',$where)." GROUP BY p.id ORDER BY p.created_at ASC,p.id ASC LIMIT {$limit} OFFSET {$offset}";
        $st=$this->db->prepare($sql);$st->execute($params);return $st->fetchAll();
    }

    public function countRows(string $q='',string $channel='',string $productStatus=''): int {
        [$where,$params]=$this->listWhere($q,$channel,$productStatus);
        $sql='SELECT COUNT(*) FROM products p WHERE '.implode(' AND ',$where);
        $st=$this->db->prepare($sql);$st->execute($params);return (int)$st->fetchColumn();
    }

    public function statusCounts(string $q='',string $channel=''): array {
        return [
            'total'=>$this->countRows($q,$channel,''),
            'published'=>$this->countRows($q,$channel,'published'),
            'pending'=>$this->countRows($q,$channel,'pending'),
            'trash'=>$this->countRows($q,$channel,'trash'),
        ];
    }

    private function listWhere(string $q,string $channel,string $productStatus=''): array {
        $where=['p.archived=0'];$params=[];
        if($q!==''){$like='%'.$q.'%';$where[]='(p.sku LIKE ? OR p.ean LIKE ? OR p.name LIKE ? OR EXISTS(SELECT 1 FROM channel_products sq WHERE sq.product_id=p.id AND (sq.external_sku LIKE ? OR sq.name LIKE ?)))';$params=[$like,$like,$like,$like,$like];}
        if($channel!==''){$where[]='EXISTS(SELECT 1 FROM channel_products cx JOIN channels cc ON cc.id=cx.channel_id WHERE cx.product_id=p.id AND cc.code=?)';$params[]=$channel;}
        if(in_array($productStatus,['published','pending','trash'],true)){
            $scope=function(string $alias,string $channelAlias) use($channel,&$params): string {
                if($channel==='')return '';$params[]=$channel;return " AND {$channelAlias}.code=?";
            };
            if($productStatus==='published'){
                $where[]="EXISTS(SELECT 1 FROM channel_products ps JOIN channels pcs ON pcs.id=ps.channel_id WHERE ps.product_id=p.id".$scope('ps','pcs')." AND COALESCE(ps.remote_status,'publish')='publish')";
            }elseif($productStatus==='trash'){
                $where[]="EXISTS(SELECT 1 FROM channel_products ts JOIN channels tcs ON tcs.id=ts.channel_id WHERE ts.product_id=p.id".$scope('ts','tcs').")";
                $where[]="NOT EXISTS(SELECT 1 FROM channel_products tn JOIN channels tcn ON tcn.id=tn.channel_id WHERE tn.product_id=p.id".$scope('tn','tcn')." AND COALESCE(tn.remote_status,'publish')<>'trash')";
            }else{
                $where[]="NOT EXISTS(SELECT 1 FROM channel_products pp JOIN channels ppc ON ppc.id=pp.channel_id WHERE pp.product_id=p.id".$scope('pp','ppc')." AND COALESCE(pp.remote_status,'publish')='publish')";
                $where[]="EXISTS(SELECT 1 FROM channel_products pn JOIN channels pnc ON pnc.id=pn.channel_id WHERE pn.product_id=p.id".$scope('pn','pnc')." AND COALESCE(pn.remote_status,'publish')<>'trash')";
            }
        }
        return [$where,$params];
    }

    /** Fetch every WooCommerce product status, including Trash when supported by the store. */
    private function readWooProductPage(WooCommerceClient $client,array $query): array {
        static $extended=[];$key=$client->baseUrl();
        if(($extended[$key]??null)!==false){
            try{$rows=$client->readProducts($query+['include_status'=>'publish,draft,pending,private,future,trash']);$extended[$key]=true;return ['rows'=>$rows,'includes_trash'=>true];}
            catch(RuntimeException $e){$extended[$key]=false;}
        }
        $rows=$client->readProducts($query+['status'=>'any']);
        return ['rows'=>$rows,'includes_trash'=>false];
    }

    /** Merge only safe WooCommerce duplicates (same SKU or same EAN across different stores). */
    public function reconcileWooDuplicates(): array {
        $rows=$this->db->query("SELECT cp.id cp_id,cp.product_id,cp.channel_id,cp.external_sku,p.sku,p.ean,p.name,p.image_url,p.created_at FROM channel_products cp JOIN channels c ON c.id=cp.channel_id JOIN products p ON p.id=cp.product_id WHERE c.type='woocommerce' AND p.archived=0 ORDER BY p.id,cp.id")->fetchAll();
        $groups=[];
        foreach($rows as $row){
            $sku=trim((string)($row['external_sku']??''));$ean=trim((string)($row['ean']??''));
            if($sku!=='')$groups['sku:'.strtolower($sku)][]=$row;
            if($ean!=='')$groups['ean:'.strtolower($ean)][]=$row;
        }
        $merged=0;$handled=[];
        foreach($groups as $key=>$group){
            $productIds=array_values(array_unique(array_map(fn($r)=>(int)$r['product_id'],$group)));if(count($productIds)<2)continue;
            $channels=array_map(fn($r)=>(int)$r['channel_id'],$group);if(count($channels)!==count(array_unique($channels)))continue;
            sort($productIds);$canonical=(int)$productIds[0];
            foreach(array_slice($productIds,1) as $duplicate){
                if(isset($handled[$duplicate]))continue;
                $conflict=$this->db->prepare('SELECT COUNT(*) FROM channel_products a JOIN channel_products b ON b.channel_id=a.channel_id WHERE a.product_id=? AND b.product_id=?');$conflict->execute([$canonical,$duplicate]);if((int)$conflict->fetchColumn()>0)continue;
                $this->db->prepare('UPDATE channel_products SET product_id=? WHERE product_id=?')->execute([$canonical,$duplicate]);
                $this->db->prepare('UPDATE order_items SET product_id=? WHERE product_id=?')->execute([$canonical,$duplicate]);
                try{$this->db->prepare('UPDATE inventory_movements SET product_id=? WHERE product_id=?')->execute([$canonical,$duplicate]);}catch(\Throwable){}
                $dup=$this->db->prepare('SELECT ean,image_url,description,short_description FROM products WHERE id=?');$dup->execute([$duplicate]);$d=$dup->fetch()?:[];
                $this->db->prepare("UPDATE products SET ean=COALESCE(NULLIF(ean,''),?),image_url=COALESCE(NULLIF(image_url,''),?),description=CASE WHEN COALESCE(description,'')='' THEN ? ELSE description END,short_description=CASE WHEN COALESCE(short_description,'')='' THEN ? ELSE short_description END,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(string)($d['ean']??''),(string)($d['image_url']??''),(string)($d['description']??''),(string)($d['short_description']??''),$canonical]);
                $this->db->prepare('UPDATE products SET archived=1,stock=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$duplicate]);
                $handled[$duplicate]=true;$merged++;
            }
            $this->refreshMasterStock($canonical);
        }
        return ['merged'=>$merged];
    }

    private function payloadBase(array $post): array {$payload=['name'=>trim((string)($post['name']??'')),'sku'=>trim((string)($post['sku']??'')),'global_unique_id'=>trim((string)($post['ean']??'')),'regular_price'=>trim((string)($post['regular_price']??'')),'sale_price'=>trim((string)($post['sale_price']??'')),'description'=>(string)($post['description']??''),'short_description'=>(string)($post['short_description']??''),'status'=>in_array((string)($post['status']??'publish'),['publish','draft','pending','private'],true)?(string)$post['status']:'publish','stock_quantity'=>(int)($post['stock_quantity']??0),'manage_stock'=>true];if($payload['name']==='')throw new RuntimeException('Titlul produsului este obligatoriu.');if($payload['sku']==='')throw new RuntimeException('SKU este obligatoriu.');return $payload;}
    private function decoratePayload(WooCommerceClient $client,array $base,array $cats,array $tags,array $brands,array $images,array $seo): array {$p=$base;$p['categories']=$this->termPayload($client,'categories',$cats);$p['tags']=$this->termPayload($client,'tags',$tags);if($brands)$p['brands']=$this->termPayload($client,'brands',$brands);$p['images']=array_map(fn($url)=>['src'=>$url],$images);$p['meta_data']=array_map(fn($k,$v)=>['key'=>$k,'value'=>$v],array_keys($seo),array_values($seo));return $p;}
    private function cleanIds(array $ids): array {return array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));}
    private function archiveIfOrphan(int $productId): void {$st=$this->db->prepare('SELECT COUNT(*) FROM channel_products WHERE product_id=?');$st->execute([$productId]);if((int)$st->fetchColumn()===0)$this->db->prepare('UPDATE products SET archived=1,stock=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$productId]);else$this->refreshMasterStock($productId);}
    private function termPayload(WooCommerceClient $client,string $kind,array $names): array {$out=[];foreach(array_values(array_unique(array_filter(array_map(fn($n)=>trim((string)$n),$names)))) as $name){$found=$client->readTerms($kind,['search'=>$name,'hide_empty'=>'false']);$id=0;foreach($found as $term){if(!is_array($term))continue;$termName=trim((string)($term['name']??''));$left=function_exists('mb_strtolower')?mb_strtolower($termName,'UTF-8'):strtolower($termName);$right=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);if($left===$right){$id=(int)$term['id'];break;}}if(!$id){$created=$client->createTerm($kind,$name);$id=(int)($created['id']??0);}if($id)$out[]=['id'=>$id];}return $out;}
    private function channelByCode(string $code): array {$st=$this->db->prepare('SELECT * FROM channels WHERE code=?');$st->execute([$code]);$c=$st->fetch();if(!$c)throw new RuntimeException('Canal necunoscut: '.$code);return $c;}
    private function refreshMasterStock(int $productId): void {$st=$this->db->prepare('SELECT COALESCE(MAX(stock),0) FROM channel_products WHERE product_id=?');$st->execute([$productId]);$stock=(int)$st->fetchColumn();$this->db->prepare('UPDATE products SET stock=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$stock,$productId]);}
    private function metaRaw(array $p,string $key): mixed {foreach((array)($p['meta_data']??[]) as $m)if(is_array($m)&&($m['key']??'')===$key)return $m['value']??null;return null;}
    private function metaValue(array $p,string $key): string {$v=$this->metaRaw($p,$key);return is_scalar($v)?(string)$v:'';}
    private function metaInt(array $p,string $key): ?int {$v=$this->metaRaw($p,$key);if($v===null||$v===''||!is_numeric($v))return null;return max(0,min(100,(int)round((float)$v)));}
    private function rankMathData(array $p): array {
        $robots=$this->metaRaw($p,'rank_math_robots');if(is_string($robots)){if(str_starts_with($robots,'a:')){$decoded=@unserialize($robots);if(is_array($decoded))$robots=$decoded;}elseif($robots!=='')$robots=array_values(array_filter(array_map('trim',explode(',',$robots))));}
        if(!is_array($robots))$robots=[];
        return [
            'score'=>$this->metaInt($p,'rank_math_seo_score'),
            'contentai_score'=>$this->metaInt($p,'rank_math_contentai_score'),
            'title'=>$this->metaValue($p,'rank_math_title'),
            'description'=>$this->metaValue($p,'rank_math_description'),
            'focus_keyword'=>$this->metaValue($p,'rank_math_focus_keyword'),
            'canonical_url'=>$this->metaValue($p,'rank_math_canonical_url'),
            'pillar_content'=>$this->metaValue($p,'rank_math_pillar_content'),
            'robots'=>array_values(array_filter(array_map('strval',$robots))),
            'facebook_title'=>$this->metaValue($p,'rank_math_facebook_title'),
            'facebook_description'=>$this->metaValue($p,'rank_math_facebook_description'),
            'facebook_image'=>$this->metaValue($p,'rank_math_facebook_image'),
            'twitter_title'=>$this->metaValue($p,'rank_math_twitter_title'),
            'twitter_description'=>$this->metaValue($p,'rank_math_twitter_description'),
            'twitter_image'=>$this->metaValue($p,'rank_math_twitter_image'),
            'twitter_use_facebook'=>$this->metaValue($p,'rank_math_twitter_use_facebook'),
            'twitter_card_type'=>$this->metaValue($p,'rank_math_twitter_card_type'),
        ];
    }
    private function rankMathPayload(array $post): array {
        $robots=array_values(array_intersect(['index','noindex','nofollow','noarchive','nosnippet','noimageindex'],array_map('strval',(array)($post['seo_robots']??[]))));
        if(in_array('noindex',$robots,true))$robots=array_values(array_filter($robots,fn($v)=>$v!=='index'));elseif(!in_array('index',$robots,true))array_unshift($robots,'index');
        $seo=[
            'rank_math_title'=>(string)($post['seo_title']??''),
            'rank_math_description'=>(string)($post['seo_description']??''),
            'rank_math_focus_keyword'=>(string)($post['seo_focus_keyword']??''),
            'rank_math_canonical_url'=>(string)($post['seo_canonical_url']??''),
            'rank_math_pillar_content'=>isset($post['seo_pillar_content'])?'on':'off',
            'rank_math_robots'=>$robots,
            'rank_math_facebook_title'=>(string)($post['seo_facebook_title']??''),
            'rank_math_facebook_description'=>(string)($post['seo_facebook_description']??''),
            'rank_math_facebook_image'=>(string)($post['seo_facebook_image']??''),
            'rank_math_twitter_title'=>(string)($post['seo_twitter_title']??''),
            'rank_math_twitter_description'=>(string)($post['seo_twitter_description']??''),
            'rank_math_twitter_image'=>(string)($post['seo_twitter_image']??''),
            'rank_math_twitter_use_facebook'=>isset($post['seo_twitter_use_facebook'])?'on':'off',
            'rank_math_twitter_card_type'=>(string)($post['seo_twitter_card_type']??'summary_large_image'),
        ];
        return $seo;
    }
    private function normalizeTerms(array $terms): array {$out=[];foreach($terms as $t)if(is_array($t))$out[]=['id'=>(int)($t['id']??0),'name'=>(string)($t['name']??''),'slug'=>(string)($t['slug']??'')];return $out;}
    private function normalizeImages(array $images): array {$out=[];foreach($images as $i)if(is_array($i)&&!empty($i['src']))$out[]=['id'=>(int)($i['id']??0),'src'=>(string)$i['src'],'name'=>(string)($i['name']??''),'alt'=>(string)($i['alt']??'')];return $out;}
    private function firstImage(array $p): string {foreach((array)($p['images']??[]) as $i)if(is_array($i)&&!empty($i['src']))return (string)$i['src'];return '';}
    /** Resolve product images from URL mode or uploaded files. Uploaded images are stored under public/uploads/products. */
    private function resolveImageUrls(array $post,array $files,?array $existingProduct): array {
        $mode=(string)($post['image_mode']??'url');
        if($mode==='upload'){
            $uploaded=$this->storeProductUploads((array)($files['product_images']??[]));
            if($uploaded)return $uploaded;
            // Editing without choosing new files must not erase the current gallery.
            if($existingProduct){
                $source=(string)($post['reference_channel']??'');
                foreach((array)($existingProduct['channels']??[]) as $cp){
                    if(($cp['channel_type']??'')!=='woocommerce')continue;
                    if($source!==''&&($cp['channel_code']??'')!==$source)continue;
                    $urls=[];foreach((array)($cp['images']??[]) as $img)if(is_array($img)&&!empty($img['src']))$urls[]=(string)$img['src'];
                    if($urls)return array_values(array_unique($urls));
                }
            }
            return [];
        }
        return $this->parseLines((string)($post['images']??''));
    }

    private function storeProductUploads(array $files): array {
        if(!$files||!isset($files['name']))return [];
        $names=is_array($files['name'])?$files['name']:[$files['name']];
        $tmps=is_array($files['tmp_name']??null)?$files['tmp_name']:[$files['tmp_name']??''];
        $errors=is_array($files['error']??null)?$files['error']:[$files['error']??UPLOAD_ERR_NO_FILE];
        $sizes=is_array($files['size']??null)?$files['size']:[$files['size']??0];
        $hasFile=false;foreach($errors as $e)if((int)$e!==UPLOAD_ERR_NO_FILE){$hasFile=true;break;}if(!$hasFile)return [];
        $probe=\app_absolute_path('/uploads/products/probe.jpg');$host=strtolower((string)(parse_url($probe,PHP_URL_HOST)??''));
        if(in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Imaginile incarcate local nu pot fi descarcate de WooCommerce. Pentru upload direct foloseste aplicatia pe un URL public HTTPS sau alege varianta URL imagini.');
        $publicRoot=dirname(__DIR__,2).'/public/uploads/products';
        if(!is_dir($publicRoot)&&!mkdir($publicRoot,0775,true)&&!is_dir($publicRoot))throw new RuntimeException('Nu pot crea folderul pentru imaginile produselor.');
        $urls=[];$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        foreach($names as $i=>$original){
            $error=(int)($errors[$i]??UPLOAD_ERR_NO_FILE);if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Una dintre imaginile incarcate nu a putut fi procesata.');
            $size=(int)($sizes[$i]??0);if($size<=0||$size>10*1024*1024)throw new RuntimeException('Fiecare imagine poate avea maximum 10 MB.');
            $tmp=(string)($tmps[$i]??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Fisier imagine temporar invalid.');
            $mime='';if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$det=finfo_file($f,$tmp);if(is_string($det))$mime=$det;finfo_close($f);}}
            if(!isset($allowed[$mime]))throw new RuntimeException('Sunt acceptate doar imagini JPG, PNG, WEBP sau GIF.');
            $name=bin2hex(random_bytes(18)).'.'.$allowed[$mime];$dest=$publicRoot.'/'.$name;if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Imaginea nu a putut fi salvata.');
            $urls[]=\app_absolute_path('/uploads/products/'.$name);
        }
        return array_values(array_unique($urls));
    }

    private function parseNames(string $v): array {return array_values(array_unique(array_filter(array_map('trim',preg_split('/[,\r\n]+/u',$v)?:[]))));}
    private function parseLines(string $v): array {return array_values(array_unique(array_filter(array_map('trim',preg_split('/[\r\n]+/u',$v)?:[]))));}
}
