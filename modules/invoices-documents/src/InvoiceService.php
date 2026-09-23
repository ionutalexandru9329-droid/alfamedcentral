<?php
namespace Modules\InvoicesDocuments;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use App\Core\Http;
use App\Integrations\EmagClient;
use App\Integrations\WooCommerceClient;
use App\Services\ChannelFactory;
use App\Services\OrderOperations;
use App\Services\SettingService;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/OblioClient.php';

final class InvoiceService {
    private PDO $db;
    private string $root;
    private ?array $oblioCatalogueIndex=null;

    public function __construct(){
        $this->db=Database::connection();
        $this->root=dirname(__DIR__,3);
    }

    public function listForOrder(int $orderId): array {
        try{$this->syncMarketplaceAttachments($orderId);}catch(Throwable $e){$this->log('warning','invoice_marketplace_import',$this->safeMessage($e->getMessage()),['order_id'=>$orderId]);}
        $st=$this->db->prepare("SELECT * FROM invoices WHERE order_id=? AND status<>'hidden' ORDER BY id DESC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public function mappings(): array {
        try{return $this->db->query("SELECT * FROM oblio_product_mappings ORDER BY CASE WHEN mapping_source='manual' THEN 0 ELSE 1 END,channel_code,source_code")->fetchAll();}
        catch(Throwable){return [];}
    }

    public function catalogueStatus(): array {
        $settings=new SettingService($this->db);
        try{
            $active=(int)$this->db->query('SELECT COUNT(*) FROM oblio_products_cache WHERE active=1')->fetchColumn();
            $total=(int)$this->db->query('SELECT COUNT(*) FROM oblio_products_cache')->fetchColumn();
        }catch(Throwable){$active=0;$total=0;}
        try{
            $manualMappings=(int)$this->db->query("SELECT COUNT(*) FROM oblio_product_mappings WHERE mapping_source='manual'")->fetchColumn();
            $autoMappings=(int)$this->db->query("SELECT COUNT(*) FROM oblio_product_mappings WHERE mapping_source<>'manual'")->fetchColumn();
        }catch(Throwable){$manualMappings=0;$autoMappings=0;}
        return [
            'active'=>$active,
            'total'=>$total,
            'manual_mappings'=>$manualMappings,
            'auto_mappings'=>$autoMappings,
            'last_auto_mappings'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_mappings','0'),
            'last_auto_candidates'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_candidates','0'),
            'last_auto_source_products'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_source_products','0'),
            'last_auto_direct_code'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_direct_code','0'),
            'last_auto_alternate'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_alternate','0'),
            'last_auto_bridge'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_bridge','0'),
            'last_auto_exact_name'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_exact_name','0'),
            'last_auto_ambiguous'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_ambiguous','0'),
            'last_auto_unmatched'=>(int)$settings->get('runtime.oblio_catalogue_last_auto_unmatched','0'),
            'last_sync_at'=>(string)$settings->get('runtime.oblio_catalogue_last_sync_at',''),
            'last_sync_ts'=>(int)$settings->get('runtime.oblio_catalogue_last_sync','0'),
            'last_error'=>(string)$settings->get('runtime.oblio_catalogue_last_error',''),
            'last_pages'=>(int)$settings->get('runtime.oblio_catalogue_last_pages','0'),
            'auto_sync'=>$settings->bool('module.invoices-documents.catalogue_auto_sync',true),
            'sync_hours'=>max(1,min(168,(int)$settings->get('module.invoices-documents.catalogue_sync_hours','12'))),
        ];
    }

    public function syncOblioCatalogue(bool $manual=true): array {
        if(!$this->isConfigured())throw new RuntimeException('Configureaza mai intai conexiunea Oblio.');
        $lockPath=$this->root.'/storage/oblio-catalogue.lock';$fp=@fopen($lockPath,'c+');
        if(!$fp)throw new RuntimeException('Nu pot obtine lock-ul pentru sincronizarea nomenclatorului Oblio.');
        if(!@flock($fp,LOCK_EX|LOCK_NB)){fclose($fp);return ['ok'=>true,'skipped'=>true,'reason'=>'already_running'];}
        $settings=new SettingService($this->db);
        try{
            $settings->set('runtime.oblio_catalogue_last_attempt',(string)time(),'runtime');
            $remote=$this->oblio($this->currentAgent())->allProducts(50000);
            $rows=array_values(array_filter((array)($remote['rows']??[]),'is_array'));
            if(!$rows)throw new RuntimeException('Oblio nu a returnat produse. Cache-ul local nu a fost modificat.');
            if(empty($remote['complete']))throw new RuntimeException('Nomenclatorul Oblio a depasit limita de siguranta a sincronizarii. Cache-ul local nu a fost modificat; mareste controlat limita inainte de o sincronizare completa.');
            $driver=(string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $existing=[];try{foreach($this->db->query('SELECT source_key FROM oblio_products_cache')->fetchAll(PDO::FETCH_COLUMN) as $k)$existing[(string)$k]=true;}catch(Throwable){}
            $this->db->beginTransaction();
            $this->db->exec('UPDATE oblio_products_cache SET active=0');
            if($driver==='mysql'){
                $sql='INSERT INTO oblio_products_cache(source_key,oblio_code,name,code_client,code_ean,description,measuring_unit,product_type,price,currency,vat_name,vat_percentage,vat_included,stock_json,raw_json,active,last_synced_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE oblio_code=VALUES(oblio_code),name=VALUES(name),code_client=VALUES(code_client),code_ean=VALUES(code_ean),description=VALUES(description),measuring_unit=VALUES(measuring_unit),product_type=VALUES(product_type),price=VALUES(price),currency=VALUES(currency),vat_name=VALUES(vat_name),vat_percentage=VALUES(vat_percentage),vat_included=VALUES(vat_included),stock_json=VALUES(stock_json),raw_json=VALUES(raw_json),active=1,last_synced_at=CURRENT_TIMESTAMP';
            }else{
                $sql='INSERT INTO oblio_products_cache(source_key,oblio_code,name,code_client,code_ean,description,measuring_unit,product_type,price,currency,vat_name,vat_percentage,vat_included,stock_json,raw_json,active,last_synced_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(source_key) DO UPDATE SET oblio_code=excluded.oblio_code,name=excluded.name,code_client=excluded.code_client,code_ean=excluded.code_ean,description=excluded.description,measuring_unit=excluded.measuring_unit,product_type=excluded.product_type,price=excluded.price,currency=excluded.currency,vat_name=excluded.vat_name,vat_percentage=excluded.vat_percentage,vat_included=excluded.vat_included,stock_json=excluded.stock_json,raw_json=excluded.raw_json,active=1,last_synced_at=CURRENT_TIMESTAMP';
            }
            $st=$this->db->prepare($sql);$seen=[];$imported=0;$created=0;$updated=0;
            foreach($rows as $row){
                $name=trim((string)($row['name']??''));$code=trim((string)($row['code']??''));$clientCode=trim((string)($row['codeClient']??$row['code_client']??''));$ean=trim((string)($row['codeEAN']??$row['ean']??$row['eanCode']??''));
                if($name===''&&$code==='')continue;
                $base=$code!==''?'code:'.$this->normalizeProductKey($code):'product:'.$this->normalizeProductName($name).'|'.$this->normalizeProductKey((string)($row['productType']??'')).'|'.$this->normalizeProductKey($ean).'|'.$this->normalizeProductKey($clientCode);
                $sourceKey=sha1($base);if(isset($seen[$sourceKey]))continue;$seen[$sourceKey]=true;
                $params=[
                    $sourceKey,$code!==''?$code:null,$name,$clientCode!==''?$clientCode:null,$ean!==''?$ean:null,
                    trim((string)($row['description']??''))?:null,trim((string)($row['measuringUnit']??$row['measuring_unit']??''))?:null,trim((string)($row['productType']??''))?:null,
                    isset($row['price'])&&is_numeric($row['price'])?(float)$row['price']:null,trim((string)($row['currency']??''))?:null,trim((string)($row['vatName']??''))?:null,
                    isset($row['vatPercentage'])&&is_numeric($row['vatPercentage'])?(float)$row['vatPercentage']:null,isset($row['vatIncluded'])?(int)(bool)$row['vatIncluded']:null,
                    json_encode((array)($row['stock']??[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),1,
                ];
                $st->execute($params);$imported++;if(isset($existing[$sourceKey]))$updated++;else $created++;
            }
            $autoMap=$this->syncAutoMappingsFromCatalogue($rows);
            $this->db->commit();$this->oblioCatalogueIndex=null;
            $now=time();$settings->set('runtime.oblio_catalogue_last_sync',(string)$now,'runtime');$settings->set('runtime.oblio_catalogue_last_sync_at',date('Y-m-d H:i:s',$now),'runtime');$settings->set('runtime.oblio_catalogue_last_error','','runtime');$settings->set('runtime.oblio_catalogue_last_pages',(string)(int)($remote['pages']??0),'runtime');$settings->set('runtime.oblio_catalogue_count',(string)$imported,'runtime');$settings->set('runtime.oblio_catalogue_last_auto_mappings',(string)(int)($autoMap['mapped']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_candidates',(string)(int)($autoMap['candidates']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_source_products',(string)(int)($autoMap['source_products']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_direct_code',(string)(int)($autoMap['direct_code']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_alternate',(string)(int)($autoMap['alternate']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_bridge',(string)(int)($autoMap['bridge']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_exact_name',(string)(int)($autoMap['exact_name']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_ambiguous',(string)(int)($autoMap['ambiguous']??0),'runtime');$settings->set('runtime.oblio_catalogue_last_auto_unmatched',(string)(int)($autoMap['unmatched']??0),'runtime');
            $this->log('success','oblio_catalogue_sync','Nomenclator Oblio sincronizat: '.$imported.' produse ('.$created.' noi, '.$updated.' actualizate), '.(int)($autoMap['mapped']??0).' echivalari automate.',['manual'=>$manual,'pages'=>(int)($remote['pages']??0),'auto_mappings'=>$autoMap]);
            return ['ok'=>true,'count'=>$imported,'created'=>$created,'updated'=>$updated,'pages'=>(int)($remote['pages']??0),'complete'=>(bool)($remote['complete']??false),'auto_mappings'=>$autoMap];
        }catch(Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();$settings->set('runtime.oblio_catalogue_last_error',$this->safeMessage($e->getMessage()),'runtime');$this->log('error','oblio_catalogue_sync',$this->safeMessage($e->getMessage()),['manual'=>$manual]);throw $e;
        }finally{@flock($fp,LOCK_UN);@fclose($fp);}
    }

    public function maybeAutoSyncCatalogue(): array {
        if(!$this->isConfigured())return ['ok'=>true,'skipped'=>true,'reason'=>'not_configured'];
        $settings=new SettingService($this->db);if(!$settings->bool('module.invoices-documents.catalogue_auto_sync',true))return ['ok'=>true,'skipped'=>true,'reason'=>'disabled'];
        $hours=max(1,min(168,(int)$settings->get('module.invoices-documents.catalogue_sync_hours','12')));$last=(int)$settings->get('runtime.oblio_catalogue_last_sync','0');
        if($last>0&&(time()-$last)<$hours*3600)return ['ok'=>true,'skipped'=>true,'reason'=>'fresh'];
        $lastAttempt=(int)$settings->get('runtime.oblio_catalogue_last_attempt','0');$lastError=trim((string)$settings->get('runtime.oblio_catalogue_last_error',''));if($lastError!==''&&$lastAttempt>0&&(time()-$lastAttempt)<900)return ['ok'=>true,'skipped'=>true,'reason'=>'retry_cooldown'];
        try{return $this->syncOblioCatalogue(false);}catch(Throwable $e){return ['ok'=>false,'error'=>$this->safeMessage($e->getMessage())];}
    }

    public function saveMapping(string $channelCode,string $sourceCode,string $oblioCode,string $oblioName=''): void {
        $this->upsertMapping($channelCode,$sourceCode,$oblioCode,$oblioName,'manual','manual',true);
    }

    private function saveAutoMapping(string $channelCode,string $sourceCode,string $oblioCode,string $oblioName='',string $reason='oblio_auto'): bool {
        return $this->upsertMapping($channelCode,$sourceCode,$oblioCode,$oblioName,'oblio_auto',$reason,false);
    }

    private function upsertMapping(string $channelCode,string $sourceCode,string $oblioCode,string $oblioName,string $mappingSource,string $reason,bool $overwriteManual): bool {
        $channelCode=trim($channelCode);$sourceCode=trim($sourceCode);$oblioCode=trim($oblioCode);$oblioName=trim($oblioName);$mappingSource=trim($mappingSource)?:'manual';$reason=trim($reason);
        if(!in_array($channelCode,['emag_ro','emag_bg','univera','alfamed'],true))throw new RuntimeException('Canal invalid pentru echivalarea codurilor.');
        if($sourceCode===''||$oblioCode==='')throw new RuntimeException('Completeaza codul din canal si codul produsului Oblio.');
        $existing=$this->db->prepare('SELECT id,mapping_source,oblio_code,oblio_name FROM oblio_product_mappings WHERE channel_code=? AND source_code=? LIMIT 1');$existing->execute([$channelCode,$sourceCode]);$row=$existing->fetch();
        if($row&& !$overwriteManual && (string)($row['mapping_source']??'manual')==='manual')return false;
        if($row){
            $st=$this->db->prepare('UPDATE oblio_product_mappings SET oblio_code=?,oblio_name=?,mapping_source=?,match_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $st->execute([$oblioCode,$oblioName!==''?$oblioName:null,$mappingSource,$reason!==''?$reason:null,(int)$row['id']]);
            return true;
        }
        $st=$this->db->prepare('INSERT INTO oblio_product_mappings(channel_code,source_code,oblio_code,oblio_name,mapping_source,match_reason) VALUES(?,?,?,?,?,?)');
        $st->execute([$channelCode,$sourceCode,$oblioCode,$oblioName!==''?$oblioName:null,$mappingSource,$reason!==''?$reason:null]);
        return true;
    }

    /**
     * Build only safe, useful channel mappings from identifiers explicitly supplied by Oblio.
     * codeClient/codeEAN have no channel field in the public API, so an equivalence is created
     * only when the same identifier is already known on that concrete ALFAMED CENTRAL channel.
     * Manual rows always win and are never changed by this routine.
     */
    private function syncAutoMappingsFromCatalogue(array $rows): array {
        $known=$this->knownSourceProductsByChannel();
        $mapped=0;$manualPreserved=0;$seen=[];$sourceProducts=0;$directCode=0;$alternate=0;$bridge=0;$exactName=0;$ambiguous=0;$unmatched=0;$candidates=0;

        // Rebuild only mappings produced by catalogue synchronization. Mappings learned safely
        // during invoicing stay available, while operator-maintained rows always have priority.
        try{$this->db->exec("DELETE FROM oblio_product_mappings WHERE mapping_source='oblio_auto' AND (match_reason LIKE 'sync_%' OR match_reason IN ('oblio_codeClient','oblio_codeEAN'))");}catch(Throwable){}
        $manual=[];try{$q=$this->db->query("SELECT channel_code,source_code FROM oblio_product_mappings WHERE mapping_source='manual'");foreach($q->fetchAll() as $m)$manual[(string)$m['channel_code'].'|'.$this->normalizeProductKey((string)$m['source_code'])]=true;}catch(Throwable){}

        $byCode=[];$byAlt=[];$byName=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $oblioCode=trim((string)($row['code']??''));$name=trim((string)($row['name']??''));if($oblioCode==='')continue;
            $codeKey=$this->normalizeProductKey($oblioCode);if($codeKey!=='')$byCode[$codeKey][]=$row;
            foreach([$row['codeClient']??$row['code_client']??'', $row['codeEAN']??$row['code_ean']??$row['ean']??$row['eanCode']??''] as $value){$key=$this->normalizeProductKey((string)$value);if($key!==''&&$key!==$codeKey)$byAlt[$key][]=$row;}
            $nameKey=$this->normalizeProductName($name);if($nameKey!=='')$byName[$nameKey][]=$row;
        }

        // Exact-name matching is accepted only when that exact normalized title identifies one
        // source product on the concrete channel and one Oblio product. This prevents bundles or
        // similarly-named variants from being linked accidentally.
        $sourceNameCounts=[];
        foreach($known as $channel=>$products){
            foreach($products as $product){
                foreach(array_keys((array)($product['names']??[])) as $nameKey){
                    if($nameKey==='')continue;$sourceNameCounts[$channel][$nameKey]=($sourceNameCounts[$channel][$nameKey]??0)+1;
                }
            }
        }

        $uniqueRow=function(array $rows): ?array {
            $rows=array_values(array_filter($rows,'is_array'));if(!$rows)return null;
            $by=[];foreach($rows as $row){$code=$this->normalizeProductKey((string)($row['code']??''));if($code!=='')$by[$code]=$row;}
            return count($by)===1?array_values($by)[0]:null;
        };

        foreach($known as $channel=>$products){
            foreach($products as $product){
                $sourceCode=trim((string)($product['source_code']??''));if($sourceCode==='')continue;$sourceProducts++;
                $manualKey=$channel.'|'.$this->normalizeProductKey($sourceCode);if(isset($manual[$manualKey])){$manualPreserved++;continue;}

                $matches=[];$reasons=[];
                $offer=function(?array $row,string $reason) use (&$matches,&$reasons):void {
                    if(!$row)return;$code=trim((string)($row['code']??''));if($code==='')return;$key=$this->normalizeProductKey($code);$matches[$key]=$row;$reasons[$key][]=$reason;
                };

                $sourceKey=$this->normalizeProductKey($sourceCode);
                if($sourceKey!==''){
                    $r=$uniqueRow((array)($byCode[$sourceKey]??[]));if($r){$offer($r,'sync_direct_code');$directCode++;}
                    $r=$uniqueRow((array)($byAlt[$sourceKey]??[]));if($r){$offer($r,'sync_alternate_code');$alternate++;}
                }

                foreach((array)($product['bridge_codes']??[]) as $bridgeCode){
                    $key=$this->normalizeProductKey((string)$bridgeCode);if($key==='')continue;
                    $r=$uniqueRow((array)($byCode[$key]??[]));if($r){$offer($r,'sync_master_code');$bridge++;}
                    $r=$uniqueRow((array)($byAlt[$key]??[]));if($r){$offer($r,'sync_master_alternate');$bridge++;}
                }

                foreach(array_keys((array)($product['names']??[])) as $nameKey){
                    if($nameKey===''||(($sourceNameCounts[$channel][$nameKey]??0)!==1))continue;
                    $r=$uniqueRow((array)($byName[$nameKey]??[]));if($r){$offer($r,'sync_exact_name');$exactName++;}
                }

                if(!$matches){$unmatched++;continue;}
                $candidates++;
                if(count($matches)!==1){$ambiguous++;continue;}
                $row=array_values($matches)[0];$matchKey=array_key_first($matches);$reasonList=array_values(array_unique((array)($reasons[$matchKey]??[])));
                // Prefer the strongest evidence when several methods point to the same product.
                $priority=['sync_direct_code','sync_alternate_code','sync_master_code','sync_master_alternate','sync_exact_name'];$reason='sync_exact_name';
                foreach($priority as $candidateReason){if(in_array($candidateReason,$reasonList,true)){$reason=$candidateReason;break;}}
                $oblioCode=trim((string)($row['code']??''));$name=trim((string)($row['name']??''));$dedupe=$channel.'|'.$this->normalizeProductKey($sourceCode).'|'.$this->normalizeProductKey($oblioCode);if(isset($seen[$dedupe]))continue;$seen[$dedupe]=true;
                try{if($this->saveAutoMapping($channel,$sourceCode,$oblioCode,$name,$reason))$mapped++;}catch(Throwable $e){$this->log('warning','oblio_auto_mapping',$this->safeMessage($e->getMessage()),['channel'=>$channel,'source_code'=>$sourceCode,'oblio_code'=>$oblioCode,'reason'=>$reason]);}
            }
        }
        return [
            'candidates'=>$candidates,'mapped'=>$mapped,'manual_preserved'=>$manualPreserved,'source_products'=>$sourceProducts,
            'direct_code'=>$directCode,'alternate'=>$alternate,'bridge'=>$bridge,'exact_name'=>$exactName,'ambiguous'=>$ambiguous,'unmatched'=>$unmatched,
        ];
    }

    /**
     * Return channel products with their relationship to the central catalogue. Unlike the old
     * flat code list, this keeps source code, exact title and master SKU/EAN together, allowing a
     * safe bridge even when Oblio does not expose codeClient/codeEAN in nomenclature responses.
     */
    private function knownSourceProductsByChannel(): array {
        $allowed=['emag_ro','emag_bg','univera','alfamed'];$out=[];foreach($allowed as $code)$out[$code]=[];
        $add=function(string $channel,$sourceCode,$name='',array $bridgeCodes=[]) use (&$out):void {
            if(!isset($out[$channel]))return;$sourceCode=trim((string)$sourceCode);if($sourceCode==='')return;$key=$this->normalizeProductKey($sourceCode);if($key==='')return;
            if(!isset($out[$channel][$key]))$out[$channel][$key]=['source_code'=>$sourceCode,'names'=>[],'bridge_codes'=>[]];
            $nameKey=$this->normalizeProductName((string)$name);if($nameKey!=='')$out[$channel][$key]['names'][$nameKey]=true;
            foreach($bridgeCodes as $bridgeCode){$bridgeCode=trim((string)$bridgeCode);$bridgeKey=$this->normalizeProductKey($bridgeCode);if($bridgeKey!==''&&!isset($out[$channel][$key]['bridge_codes'][$bridgeKey]))$out[$channel][$key]['bridge_codes'][$bridgeKey]=$bridgeCode;}
        };
        try{
            $sql="SELECT c.code channel_code,oi.sku source_code,oi.name source_name,p.sku master_sku,p.ean master_ean FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN channels c ON c.id=o.channel_id LEFT JOIN products p ON p.id=oi.product_id WHERE c.code IN ('emag_ro','emag_bg','univera','alfamed') AND oi.sku IS NOT NULL AND oi.sku<>''";
            $q=$this->db->query($sql);while($row=$q->fetch())$add((string)$row['channel_code'],$row['source_code']??'',$row['source_name']??'',[$row['master_sku']??'',$row['master_ean']??'']);
        }catch(Throwable){}
        try{
            $sql="SELECT c.code channel_code,cp.external_sku source_code,COALESCE(NULLIF(cp.name,''),p.name) source_name,p.sku master_sku,p.ean master_ean FROM channel_products cp JOIN channels c ON c.id=cp.channel_id LEFT JOIN products p ON p.id=cp.product_id WHERE c.code IN ('emag_ro','emag_bg','univera','alfamed') AND cp.external_sku IS NOT NULL AND cp.external_sku<>''";
            $q=$this->db->query($sql);while($row=$q->fetch())$add((string)$row['channel_code'],$row['source_code']??'',$row['source_name']??'',[$row['master_sku']??'',$row['master_ean']??'']);
        }catch(Throwable){}
        // Convert bridge maps to simple values for deterministic processing.
        foreach($out as $channel=>&$products)foreach($products as &$product)$product['bridge_codes']=array_values((array)$product['bridge_codes']);unset($products,$product);
        return $out;
    }

    public function deleteMapping(int $id): void {
        $this->db->prepare('DELETE FROM oblio_product_mappings WHERE id=?')->execute([$id]);
    }

    public function searchOblioProducts(string $query): array {
        if(!$this->isConfigured())throw new RuntimeException('Configureaza mai intai conexiunea Oblio.');
        return $this->oblio($this->currentAgent())->searchProducts($query,30);
    }

    private function normalizeProductKey(string $value): string {
        $value=trim($value);return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    private function normalizeProductName(string $value): string {
        $value=$this->normalizeProductKey($value);
        $value=strtr($value,['ă'=>'a','â'=>'a','î'=>'i','ș'=>'s','ş'=>'s','ț'=>'t','ţ'=>'t']);
        $value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value)??$value;
        return trim(preg_replace('/\s+/u',' ',$value)??$value);
    }

    private function cachedCatalogueIndex(): array {
        if($this->oblioCatalogueIndex!==null)return $this->oblioCatalogueIndex;
        $byCode=[];$byAlt=[];$byName=[];
        try{$rows=$this->db->query('SELECT * FROM oblio_products_cache WHERE active=1 ORDER BY id')->fetchAll();}catch(Throwable){$rows=[];}
        foreach($rows as $row){
            if(!is_array($row))continue;
            $row['code']=(string)($row['oblio_code']??'');$row['codeClient']=(string)($row['code_client']??'');$row['codeEAN']=(string)($row['code_ean']??'');$row['measuringUnit']=(string)($row['measuring_unit']??'');$row['productType']=(string)($row['product_type']??'');$row['vatName']=(string)($row['vat_name']??'');$row['vatPercentage']=$row['vat_percentage']??null;$row['vatIncluded']=$row['vat_included']??null;$row['stock']=json_decode((string)($row['stock_json']??'[]'),true)?:[];
            $codeKey=$this->normalizeProductKey((string)$row['code']);if($codeKey!==''&&!isset($byCode[$codeKey]))$byCode[$codeKey]=$row;
            foreach(['codeClient','codeEAN'] as $field){$key=$this->normalizeProductKey((string)($row[$field]??''));if($key!=='')$byAlt[$key][]=$row;}
            $nameKey=$this->normalizeProductName((string)($row['name']??''));if($nameKey!=='')$byName[$nameKey][]=$row;
        }
        return $this->oblioCatalogueIndex=['by_code'=>$byCode,'by_alt'=>$byAlt,'by_name'=>$byName,'count'=>count($rows)];
    }

    private function uniqueCachedCandidate(array $rows): ?array {
        $rows=array_values(array_filter($rows,'is_array'));if(count($rows)===1)return $rows[0];if(!$rows)return null;
        $cfg=$this->oblioConfig();$management=trim((string)$cfg['management']);$workStation=trim((string)$cfg['work_station']);if($management==='')return null;
        $withStock=[];foreach($rows as $row){foreach((array)($row['stock']??[]) as $stock){if(!is_array($stock))continue;$m=trim((string)($stock['management']??''));$w=trim((string)($stock['workStation']??''));$q=(float)($stock['quantity']??0);if($m===$management&&($workStation===''||$w===$workStation)&&$q>0.000001){$withStock[]=$row;break;}}}
        return count($withStock)===1?$withStock[0]:null;
    }

    private function resolveCachedOblioProduct(array $sourceCodes,string $name): ?array {
        $index=$this->cachedCatalogueIndex();if((int)($index['count']??0)<=0)return null;
        foreach($sourceCodes as $sourceCode){$sourceCode=trim((string)$sourceCode);$key=$this->normalizeProductKey($sourceCode);if($key==='')continue;if(isset($index['by_code'][$key]))return $index['by_code'][$key]+['_match_source_code'=>$sourceCode,'_match_reason'=>'local_oblio_code'];if(isset($index['by_alt'][$key])){$candidate=$this->uniqueCachedCandidate((array)$index['by_alt'][$key]);if($candidate!==null)return $candidate+['_match_source_code'=>$sourceCode,'_match_reason'=>'local_oblio_alternate_code'];}}
        $nameKey=$this->normalizeProductName($name);if($nameKey!==''){$candidate=$this->uniqueCachedCandidate((array)($index['by_name'][$nameKey]??[]));if($candidate!==null)return $candidate+['_match_source_code'=>trim((string)($sourceCodes[0]??'')),'_match_reason'=>'local_oblio_exact_name'];}
        return null;
    }

    public function hideMarketplaceAttachment(int $orderId,int $invoiceId): void {
        $inv=$this->invoice($orderId,$invoiceId);
        if((string)$inv['type']!=='marketplace')throw new RuntimeException('Doar documentele importate din Marketplace pot fi ascunse astfel.');
        $this->db->prepare("UPDATE invoices SET status='hidden' WHERE id=? AND order_id=?")->execute([$invoiceId,$orderId]);
    }

    private function mappedItems(array $order,OblioClient $oblio): array {
        $items=(array)($order['items']??[]);$channel=(string)($order['channel_code']??'');if(!$items||$channel==='')return $items;
        try{$st=$this->db->prepare('SELECT source_code,oblio_code,oblio_name FROM oblio_product_mappings WHERE channel_code=?');$st->execute([$channel]);$rows=$st->fetchAll();}
        catch(Throwable){$rows=[];}
        $map=[];foreach($rows as $row)$map[(string)$row['source_code']]=$row;
        foreach($items as &$item){
            $originalSku=trim((string)($item['sku']??''));$item['_channel_code']=$originalSku;$item['_source_ean']=$this->sourceEanForItem($order,$item);$item['_require_oblio_catalogue']=((string)($order['channel_type']??'')==='emag')?1:0;
            $codes=$this->sourceCodesForItem($order,$item);$local=null;$source='';
            foreach($codes as $code){if(isset($map[$code])){$local=$map[$code];$source=$code;break;}}
            if($local!==null){
                $cached=$this->resolveCachedOblioProduct([(string)$local['oblio_code']],(string)($local['oblio_name']??$item['name']??''));
                $item['_source_sku']=$source;$item['_oblio_resolved']=1;$item['_oblio_match_reason']='saved_mapping';$item['sku']=(string)$local['oblio_code'];if(trim((string)($local['oblio_name']??''))!=='')$item['name']=(string)$local['oblio_name'];if($cached)$item['_oblio_catalogue_row']=$cached;continue;
            }
            // Resolve against the real Oblio catalogue. Besides the native product code, the
            // Oblio line model supports client code and EAN; OblioClient indexes these alternate
            // identifiers so eMAG part_number/EAN can resolve to the authoritative Oblio code.
            $resolved=$this->resolveCachedOblioProduct($codes,(string)($item['name']??''));
            if(!$resolved)$resolved=$oblio->resolveEquivalentProduct($codes,(string)($item['name']??''));
            if(!$resolved){$item['_oblio_resolved']=0;continue;}
            $oblioCode=trim((string)($resolved['code']??''));if($oblioCode===''){$item['_oblio_resolved']=0;continue;}
            $oblioName=trim((string)($resolved['name']??''));$matchedSource=trim((string)($resolved['_match_source_code']??($codes[0]??'')));
            // Persist the eMAG seller SKU as well, even when the successful match came from
            // EAN. That makes the next invoice O(1) and keeps the marketplace-to-Oblio code
            // relationship explicit in ALFAMED CENTRAL.
            $persistCodes=array_values(array_unique(array_filter([$matchedSource,$originalSku,(string)($item['_source_ean']??'')],static fn($v)=>trim((string)$v)!=='')));
            foreach($persistCodes as $persistCode){if(strcasecmp((string)$persistCode,$oblioCode)===0)continue;try{$this->saveAutoMapping($channel,(string)$persistCode,$oblioCode,$oblioName,'invoice_learned_'.(string)($resolved['_match_reason']??'auto'));$map[(string)$persistCode]=['source_code'=>(string)$persistCode,'oblio_code'=>$oblioCode,'oblio_name'=>$oblioName,'mapping_source'=>'oblio_auto'];}catch(Throwable){/* Persistenta maparii este optionala; facturarea poate continua cu produsul deja rezolvat. */}}
            $item['_source_sku']=$matchedSource!==''?$matchedSource:$originalSku;$item['_oblio_resolved']=1;$item['_oblio_match_reason']=(string)($resolved['_match_reason']??'auto');$item['_oblio_catalogue_row']=$resolved;$item['sku']=$oblioCode;if($oblioName!=='')$item['name']=$oblioName;
        }unset($item);
        return $items;
    }

    private function sourceCodesForItem(array $order,array $item): array {
        $codes=[];$push=static function($value) use (&$codes):void{$value=trim((string)$value);if($value!==''&&!in_array($value,$codes,true))$codes[]=$value;};
        // Start with the seller SKU/part number retained on the local order item.
        $push($item['sku']??'');
        $raw=(array)($order['raw']??[]);$external=trim((string)($item['external_item_id']??''));$remote=trim((string)($item['remote_product_id']??''));
        $needles=array_values(array_unique(array_filter([$external,$remote],static fn($v)=>$v!=='')));
        if((string)($order['channel_type']??'')==='emag'){
            foreach((array)($raw['products']??[]) as $rp){
                if(!is_array($rp))continue;$rpIds=[];foreach(['id','product_id','product_offer_id','offer_id'] as $key){$v=trim((string)($rp[$key]??''));if($v!=='')$rpIds[]=$v;}
                if($needles&&!array_intersect($needles,$rpIds))continue;
                foreach(['part_number','sku','vendor_sku','ean','ean_code','barcode'] as $key)$push($rp[$key]??'');
                if($needles)break;
            }
        }elseif((string)($order['channel_type']??'')==='woocommerce'){
            foreach((array)($raw['line_items']??[]) as $rp){
                if(!is_array($rp))continue;$rpIds=[];foreach(['id','product_id','variation_id'] as $key){$v=trim((string)($rp[$key]??''));if($v!=='')$rpIds[]=$v;}
                if($needles&&!array_intersect($needles,$rpIds))continue;
                foreach(['sku','ean','global_unique_id'] as $key)$push($rp[$key]??'');
                if($needles)break;
            }
        }

        // If this marketplace line is already linked with the central product catalogue, reuse
        // every known internal/channel SKU and EAN as additional safe matching candidates.
        $productId=(int)($item['product_id']??0);
        if($productId>0){
            try{
                $p=$this->db->prepare('SELECT sku,ean FROM products WHERE id=? LIMIT 1');$p->execute([$productId]);$master=$p->fetch();
                if($master){$push($master['sku']??'');$push($master['ean']??'');}
                $cp=$this->db->prepare("SELECT external_sku FROM channel_products WHERE product_id=? AND external_sku IS NOT NULL AND external_sku<>'' ORDER BY id");$cp->execute([$productId]);foreach($cp->fetchAll(PDO::FETCH_COLUMN) as $v)$push($v);
            }catch(Throwable){}
        }
        // Internal eMAG product/offer id is a last-resort identifier, never preferred over seller
        // SKU or EAN. Order-line ids are deliberately excluded: they are not product codes.
        $push($item['remote_product_id']??'');
        return $codes;
    }

    private function sourceEanForItem(array $order,array $item): string {
        $raw=(array)($order['raw']??[]);$external=trim((string)($item['external_item_id']??''));$remote=trim((string)($item['remote_product_id']??''));
        $needles=array_values(array_unique(array_filter([$external,$remote],static fn($v)=>$v!=='')));
        $rows=(string)($order['channel_type']??'')==='emag'?(array)($raw['products']??[]):(array)($raw['line_items']??[]);
        foreach($rows as $rp){
            if(!is_array($rp))continue;$rpIds=[];foreach(['id','product_id','product_offer_id','offer_id','variation_id'] as $key){$v=trim((string)($rp[$key]??''));if($v!=='')$rpIds[]=$v;}
            if($needles&&!array_intersect($needles,$rpIds))continue;
            foreach(['ean','ean_code','barcode','global_unique_id'] as $key){$v=preg_replace('/\s+/','',trim((string)($rp[$key]??'')))??'';if($v!==''&&preg_match('/^[0-9]{8,14}$/',$v))return $v;}
            if($needles)break;
        }
        $productId=(int)($item['product_id']??0);if($productId>0){try{$q=$this->db->prepare("SELECT ean FROM products WHERE id=? AND ean IS NOT NULL AND ean<>'' LIMIT 1");$q->execute([$productId]);$v=preg_replace('/\s+/','',trim((string)($q->fetchColumn()?:'')))??'';if($v!==''&&preg_match('/^[0-9]{8,14}$/',$v))return $v;}catch(Throwable){}}
        return '';
    }

    private function syncMarketplaceAttachments(int $orderId): void {
        $order=(new OrderOperations())->getOrder($orderId);if((string)($order['channel_type']??'')!=='emag')return;
        $attachments=(array)($order['raw']['attachments']??[]);
        if(!$attachments){
            try{
                $client=ChannelFactory::client((string)$order['channel_code']);
                if($client instanceof EmagClient){$live=$client->readOrderById($order['external_id']);if(is_array($live))$attachments=(array)($live['attachments']??[]);}
            }catch(Throwable $e){$this->log('warning','invoice_marketplace_refresh',$this->safeMessage($e->getMessage()),['order_id'=>$orderId]);}
        }
        if(!$attachments)return;
        $known=[];$localLinks=[];$localLabels=[];$st=$this->db->prepare('SELECT id,type,series,number,link,raw_json FROM invoices WHERE order_id=?');$st->execute([$orderId]);
        foreach($st->fetchAll() as $row){
            $m=json_decode((string)($row['raw_json']??'{}'),true)?:[];$key=(string)($m['marketplace_attachment']['remote_key']??'');if($key!=='')$known[$key]=true;
            $existingLink=trim((string)($row['link']??''));if($existingLink!=='')$localLinks[$existingLink]=true;
            $series=trim((string)($row['series']??''));$number=trim((string)($row['number']??''));if($series!==''||$number!==''){$localLabels[strtolower(trim('Factura '.$series.' '.$number))]=true;$localLabels[strtolower(trim($series.' '.$number))]=true;}
        }
        $ins=$this->db->prepare('INSERT INTO invoices(order_id,type,series,number,status,total,currency,link,raw_json) VALUES(?,?,?,?,?,?,?,?,?)');
        foreach($attachments as $a){
            if(!is_array($a))continue;$type=(int)($a['type']??$a['attachment_type']??0);if(!in_array($type,[1,11],true))continue;
            $url=trim((string)($a['url']??$a['file_url']??$a['download_url']??''));$name=trim((string)($a['name']??$a['file_name']??($type===1?'Factura Marketplace':'Proforma Marketplace')));
            if($url===''||!filter_var($url,FILTER_VALIDATE_URL))continue;$remoteKey=hash('sha256',$type.'|'.$url.'|'.$name);if(isset($known[$remoteKey]))continue;
            $normalizedName=strtolower(trim($name));if(isset($localLinks[$url])||($normalizedName!==''&&isset($localLabels[$normalizedName])))continue;
            $meta=['marketplace_attachment'=>['remote_key'=>$remoteKey,'type'=>$type,'name'=>$name,'url'=>$url,'raw'=>$a],'channel_sync'=>['state'=>'source']];
            $ins->execute([$orderId,'marketplace','',$name,'imported',(float)$order['total'],(string)$order['currency'],$url,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$known[$remoteKey]=true;
        }
    }

    public function isConfigured(): bool {
        if(!$this->moduleEnabled()) return false;
        $cfg=$this->oblioConfig();
        return $cfg['enabled'] && $cfg['client_id']!=='' && $cfg['client_secret']!=='' && $cfg['cif']!=='' && $cfg['invoice_series']!=='';
    }

    public function configurationMissing(): array {
        $cfg=$this->oblioConfig();
        $missing=[];
        if(!$this->moduleEnabled()) $missing[]='modulul este dezactivat';
        if(!$cfg['enabled']) $missing[]='emiterea prin Oblio este dezactivata';
        if($cfg['client_id']==='') $missing[]='Client ID / email';
        if($cfg['client_secret']==='') $missing[]='Client Secret';
        if($cfg['cif']==='') $missing[]='CIF';
        if($cfg['invoice_series']==='') $missing[]='seria facturii';
        return $missing;
    }

    public function createDocument(int $orderId,string $type): array {
        $map=['invoice'=>'Factura','proforma'=>'Proforma','notice'=>'Aviz'];
        if(!isset($map[$type])) throw new RuntimeException('Tip document Oblio invalid.');
        $o=(new OrderOperations())->getOrder($orderId);
        $agent=$this->currentAgent();
        $oblio=$this->oblio($agent);
        foreach($this->listForOrder($orderId) as $inv){
            if($inv['type']===$type && $inv['status']==='issued') throw new RuntimeException($map[$type].' exista deja pentru aceasta comanda.');
        }
        $settings=new SettingService($this->db);
        $items=$this->mappedItems($o,$oblio);
        $res=match($type){
            'invoice'=>$oblio->createInvoice($o,$items),
            'proforma'=>$oblio->createProforma($o,$items,(string)$settings->get('module.invoices-documents.proforma_series','PR')),
            'notice'=>$oblio->createNotice($o,$items,(string)$settings->get('module.invoices-documents.notice_series','AV')),
        };
        $d=$res['data']??[];
        $sourceLink=trim((string)($d['link']??''));
        $stored=$sourceLink!==''?$this->mirrorRemotePdf($sourceLink):[];
        $documentLink=(string)($stored['link']??$sourceLink);
        $meta=[
            'oblio'=>$res,
            'agent'=>$agent,
            'source_link'=>$sourceLink,
            'file'=>$stored['file']??null,
            'token'=>$stored['token']??null,
            'channel_sync'=>['state'=>'not_applicable'],
            'warnings'=>(array)($stored['warnings']??[]),
        ];
        $st=$this->db->prepare('INSERT INTO invoices(order_id,type,series,number,status,total,currency,link,raw_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$orderId,$type,$d['seriesName']??'',(string)($d['number']??''),'issued',$o['total'],$o['currency'],$documentLink?:null,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $invoiceId=(int)$this->db->lastInsertId();
        if($type==='invoice'){
            try{$meta['channel_sync']=$this->syncInvoiceToSource($o,['id'=>$invoiceId,'series'=>(string)($d['seriesName']??''),'number'=>(string)($d['number']??'')],$documentLink);}
            catch(Throwable $e){$meta['channel_sync']=['state'=>'error','message'=>$e->getMessage()];$meta['warnings'][]='Factura a fost emisa in Oblio si salvata in ALFAMED CENTRAL, dar trimiterea catre canal a esuat: '.$e->getMessage();}
            $this->db->prepare('UPDATE invoices SET raw_json=? WHERE id=?')->execute([json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId]);
        }
        $res['_alfamed']=['invoice_id'=>$invoiceId,'agent'=>$agent,'link'=>$documentLink,'channel_sync'=>$meta['channel_sync'],'warnings'=>$meta['warnings']];
        return $res;
    }

    public function emitOblio(int $orderId): array { return $this->createDocument($orderId,'invoice'); }


    public function diagnose(): array {
        $missing=$this->configurationMissing();
        if($missing) throw new RuntimeException('Configurare Oblio incompleta: '.implode(', ',$missing).'.');
        $result=$this->oblio($this->currentAgent())->diagnose();
        $this->log('success','oblio_api_test','Conexiune Oblio OK. Serii factura: '.implode(', ',(array)($result['invoice_series']??[])).'.',[]);
        return $result;
    }

    public function logFailure(int $orderId,string $operation,Throwable $e): void {
        $message=$this->safeMessage($e->getMessage());
        $this->log('error','oblio_'.$operation,$message,['order_id'=>$orderId]);
    }

    public function safeMessage(string $message): string {
        $message=preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i','Bearer [redacted]',$message)??$message;
        $message=preg_replace('/("?(?:client_secret|password|authorization)"?\s*[:=]\s*)[^,\s}]+/i','$1[redacted]',$message)??$message;
        $message=trim(strip_tags($message));
        return function_exists('mb_substr')?mb_substr($message,0,900,'UTF-8'):substr($message,0,900);
    }

    public function bulkCreate(array $orderIds,string $type): array {
        $ok=0;$errors=[];$warnings=[];$ids=$this->cleanIds($orderIds);$limit=25;
        if(count($ids)>$limit){
            $errors[]='Pentru protectia rate-limit-ului Oblio, se proceseaza maximum '.$limit.' documente intr-o singura actiune. Selecteaza restul comenzilor intr-un al doilea lot.';
            $ids=array_slice($ids,0,$limit);
        }
        foreach($ids as $id){
            try{
                // Factura bulk este idempotenta: daca documentul fiscal exista deja, nu
                // emitem o dublura. Daca sincronizarea catre canal a esuat anterior (de
                // exemplu din cauza contractului order/attachments/save), o reluam.
                if($type==='invoice'&&($existing=$this->latestFiscalInvoice($id))){
                    $meta=json_decode((string)($existing['raw_json']??'{}'),true)?:[];$state=(string)($meta['channel_sync']['state']??'');
                    if($state!=='sent'){$this->resyncInvoice($id,(int)$existing['id']);$warnings[]='#'.$id.': Factura exista deja; sincronizarea catre canal a fost reluata fara emiterea unei facturi noi.';}
                    else $warnings[]='#'.$id.': Factura exista deja si este deja sincronizata; nu s-a emis o dublura.';
                    $ok++;continue;
                }
                $res=$this->createDocument($id,$type);$ok++;foreach((array)($res['_alfamed']['warnings']??[]) as $warning)$warnings[]='#'.$id.': '.$warning;
            }catch(Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}
        }
        return ['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings];
    }

    public function canStornoOrder(int $orderId): bool {
        try{$inv=$this->latestFiscalInvoice($orderId);if(!$inv)return false;$st=$this->db->prepare("SELECT 1 FROM invoices WHERE order_id=? AND parent_invoice_id=? AND type='storno' AND status='issued' LIMIT 1");$st->execute([$orderId,(int)$inv['id']]);return !$st->fetchColumn();}catch(Throwable){return false;}
    }

    public function stornoLatest(int $orderId): array {
        $inv=$this->latestFiscalInvoice($orderId);if(!$inv)throw new RuntimeException('Comanda nu are o factura Oblio emisa care sa poata fi stornata.');
        return $this->storno($orderId,(int)$inv['id']);
    }

    public function bulkStorno(array $orderIds): array {
        $ok=0;$errors=[];$warnings=[];$ids=$this->cleanIds($orderIds);$limit=25;
        if(count($ids)>$limit){$errors[]='Pentru protectia rate-limit-ului Oblio, se proceseaza maximum '.$limit.' stornari intr-un lot.';$ids=array_slice($ids,0,$limit);}
        foreach($ids as $id){try{$r=$this->stornoLatest($id);$ok++;foreach((array)($r['_alfamed']['warnings']??[]) as $w)$warnings[]='#'.$id.': '.$w;}catch(Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}}
        return ['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings];
    }

    public function storno(int $orderId,int $invoiceId): array {
        $inv=$this->invoice($orderId,$invoiceId);
        if((string)$inv['type']!=='invoice'||(string)$inv['status']!=='issued') throw new RuntimeException('Doar o factura Oblio emisa poate fi stornata.');
        $dup=$this->db->prepare("SELECT id FROM invoices WHERE order_id=? AND parent_invoice_id=? AND type='storno' AND status='issued' LIMIT 1");$dup->execute([$orderId,$invoiceId]);if($dup->fetchColumn())throw new RuntimeException('Factura are deja un document storno emis in ALFAMED CENTRAL.');
        $order=(new OrderOperations())->getOrder($orderId);$agent=$this->currentAgent();$oblio=$this->oblio($agent);
        $res=$oblio->storno((string)$inv['series'],(string)$inv['number']);$d=(array)($res['data']??[]);
        $sourceLink=trim((string)($d['link']??''));$stored=$sourceLink!==''?$this->mirrorRemotePdf($sourceLink):[];$documentLink=(string)($stored['link']??$sourceLink);
        $meta=['oblio'=>$res,'agent'=>$agent,'original_invoice'=>['id'=>$invoiceId,'series'=>(string)$inv['series'],'number'=>(string)$inv['number']],'source_link'=>$sourceLink,'file'=>$stored['file']??null,'token'=>$stored['token']??null,'channel_sync'=>['state'=>'not_applicable'],'warnings'=>(array)($stored['warnings']??[])];
        $st=$this->db->prepare('INSERT INTO invoices(order_id,parent_invoice_id,type,series,number,status,total,currency,link,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$orderId,$invoiceId,'storno',$d['seriesName']??'',(string)($d['number']??''),'issued',-abs((float)$inv['total']),(string)$inv['currency'],$documentLink?:null,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$stornoId=(int)$this->db->lastInsertId();
        try{$meta['channel_sync']=$this->syncDocumentToSource($order,['id'=>$stornoId,'series'=>(string)($d['seriesName']??''),'number'=>(string)($d['number']??'')],$documentLink,'storno');}
        catch(Throwable $e){$meta['channel_sync']=['state'=>'error','message'=>$e->getMessage()];$meta['warnings'][]='Storno a fost emis in Oblio si salvat in ALFAMED CENTRAL, dar trimiterea catre canal a esuat: '.$e->getMessage();}
        $this->db->prepare('UPDATE invoices SET raw_json=? WHERE id=?')->execute([json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$stornoId]);
        $res['_alfamed']=['invoice_id'=>$stornoId,'agent'=>$agent,'link'=>$documentLink,'channel_sync'=>$meta['channel_sync'],'warnings'=>$meta['warnings']];
        return $res;
    }

    public function upload(int $orderId,array $file,string $series,string $number,bool $sendCustomer): array {
        $order=(new OrderOperations())->getOrder($orderId);
        $settings=new SettingService($this->db);
        $max=max(1,min(25,(int)$settings->get('module.invoices-documents.max_upload_mb','8')))*1024*1024;
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecteaza un fisier PDF valid.');
        if((int)($file['size']??0)<=0 || (int)$file['size']>$max) throw new RuntimeException('Factura PDF depaseste limita configurata.');
        $tmp=(string)($file['tmp_name']??'');
        if($tmp==='' || !is_file($tmp)) throw new RuntimeException('Fisier temporar invalid.');
        $mime=function_exists('finfo_open')?(new \finfo(FILEINFO_MIME_TYPE))->file($tmp):'';
        $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
        if($mime!=='application/pdf' && $ext!=='pdf') throw new RuntimeException('Sunt acceptate doar facturi PDF.');
        $token=bin2hex(random_bytes(24));
        $dir=$this->root.'/public/uploads/invoices';
        if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('Nu pot crea folderul pentru facturi.');
        $dest=$dir.'/'.$token.'.pdf';
        if(!move_uploaded_file($tmp,$dest) && !rename($tmp,$dest)) throw new RuntimeException('Nu pot salva factura incarcata.');
        @chmod($dest,0644);
        $link=app_absolute_path('/uploads/invoices/'.$token.'.pdf');
        $channelSync=['state'=>'not_requested'];$warnings=[];
        if($sendCustomer){
            try{$channelSync=$this->syncInvoiceToSource($order,['series'=>'','number'=>''],$link);}
            catch(Throwable $e){$channelSync=['state'=>'error','message'=>$e->getMessage()];$warnings[]='PDF-ul a fost salvat local, dar sincronizarea cu canalul a esuat: '.$e->getMessage();}
        }
        $meta=['file'=>$dest,'token'=>$token,'sent_to_channel'=>$sendCustomer,'channel_sync'=>$channelSync,'warnings'=>$warnings];
        $st=$this->db->prepare('INSERT INTO invoices(order_id,type,series,number,status,total,currency,link,raw_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $st->execute([$orderId,'uploaded','','','issued',$order['total'],$order['currency'],$link,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        return ['id'=>(int)$this->db->lastInsertId(),'link'=>$link,'sent'=>$sendCustomer,'channel_sync'=>$channelSync,'warnings'=>$warnings];
    }

    public function deleteUploaded(int $orderId,int $invoiceId): void {
        $order=(new OrderOperations())->getOrder($orderId);
        $inv=$this->invoice($orderId,$invoiceId);
        if($inv['type']!=='uploaded') throw new RuntimeException('Facturile emise fiscal nu se sterg. Pentru ele foloseste storno.');
        $meta=json_decode((string)($inv['raw_json']??'{}'),true)?:[];
        $file=(string)($meta['file']??'');
        if($file!=='' && is_file($file) && str_starts_with(realpath($file)?:'',realpath($this->root.'/public/uploads/invoices')?:'__never__')) @unlink($file);
        $noteId=$meta['customer_note_id']??($meta['channel_sync']['customer_note_id']??null);
        if($noteId && $order['channel_type']==='woocommerce'){
            try{$client=ChannelFactory::client($order['channel_code']);if($client instanceof WooCommerceClient)$client->deleteOrderNote($order['external_id'],$noteId);}catch(Throwable){}
        }
        $this->db->prepare('DELETE FROM invoices WHERE id=? AND order_id=?')->execute([$invoiceId,$orderId]);
    }

    public function documentPath(string $token): ?string {
        if(!preg_match('/^[a-f0-9]{48}$/',$token)) return null;
        $file=$this->root.'/public/uploads/invoices/'.$token.'.pdf';
        return is_file($file)?$file:null;
    }

    public function resyncInvoice(int $orderId,int $invoiceId): array {
        $order=(new OrderOperations())->getOrder($orderId);$inv=$this->invoice($orderId,$invoiceId);$kind=(string)$inv['type'];
        if(!in_array($kind,['invoice','storno'],true)) throw new RuntimeException('Doar factura sau storno pot fi retrimise catre canal.');
        $link=trim((string)($inv['link']??''));if($link===''){$meta=json_decode((string)($inv['raw_json']??'{}'),true)?:[];$link=trim((string)($meta['source_link']??$meta['oblio']['data']['link']??''));}
        if($link==='') throw new RuntimeException('Documentul nu are un URL disponibil pentru sincronizare.');
        $sync=$this->syncDocumentToSource($order,$inv,$link,$kind);$meta=json_decode((string)($inv['raw_json']??'{}'),true)?:[];$meta['channel_sync']=$sync;
        $this->db->prepare('UPDATE invoices SET raw_json=? WHERE id=? AND order_id=?')->execute([json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$invoiceId,$orderId]);return $sync;
    }

    private function oblio(string $agent=''): OblioClient {
        $missing=$this->configurationMissing();
        if($missing) throw new RuntimeException('Oblio nu este configurat complet in Setari > Module > Oblio - Facturi & documente: '.implode(', ',$missing).'.');
        $cfg=$this->oblioConfig();
        return new OblioClient($cfg['client_id'],$cfg['client_secret'],$cfg['cif'],$cfg['invoice_series'],$cfg['management'],$cfg['work_station'],$cfg['issuer_name'],$agent);
    }

    private function oblioConfig(): array {
        $settings=new SettingService($this->db);
        $prefix='module.invoices-documents.';
        return [
            'enabled'=>$settings->bool($prefix.'oblio_enabled',Env::bool('OBLIO_ENABLED',false)),
            'client_id'=>trim((string)$settings->get($prefix.'client_id',(string)Env::get('OBLIO_CLIENT_ID',''))),
            'client_secret'=>trim((string)$settings->get($prefix.'client_secret',(string)Env::get('OBLIO_CLIENT_SECRET',''))),
            'cif'=>trim((string)$settings->get($prefix.'cif',(string)Env::get('OBLIO_CIF',''))),
            'invoice_series'=>trim((string)$settings->get($prefix.'invoice_series',(string)Env::get('OBLIO_SERIES','FCT'))),
            'management'=>trim((string)$settings->get($prefix.'management',(string)Env::get('OBLIO_MANAGEMENT',''))),
            'work_station'=>trim((string)$settings->get($prefix.'work_station',(string)Env::get('OBLIO_WORKSTATION',''))),
            'issuer_name'=>trim((string)$settings->get($prefix.'issuer_name',(string)Env::get('OBLIO_ISSUER_NAME','ALFAMED CLINIC SRL'))),
            'agent_current_user'=>$settings->bool($prefix.'agent_current_user',true),
            'agent_default'=>trim((string)$settings->get($prefix.'agent_default','')),
        ];
    }

    private function currentAgent(): string {
        $cfg=$this->oblioConfig();
        if($cfg['agent_current_user']){
            $name=trim((string)(Auth::user()['name']??''));
            if($name!=='') return $name;
        }
        return (string)$cfg['agent_default'];
    }

    private function mirrorRemotePdf(string $url): array {
        $warnings=[];
        if(!filter_var($url,FILTER_VALIDATE_URL)) return ['warnings'=>['Oblio nu a returnat un URL PDF valid; documentul ramane inregistrat local prin datele fiscale.']];
        try{
            $r=Http::request('GET',$url,['Accept: application/pdf'],null,60);
            $body=(string)($r['body']??'');
            if((int)($r['status']??0)<200 || (int)($r['status']??0)>=300 || !str_starts_with(ltrim($body),'%PDF')) throw new RuntimeException('raspunsul Oblio nu este un PDF descarcabil');
            $token=bin2hex(random_bytes(24));$dir=$this->root.'/public/uploads/invoices';
            if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('nu pot crea folderul local pentru facturi');
            $file=$dir.'/'.$token.'.pdf';if(file_put_contents($file,$body,LOCK_EX)===false)throw new RuntimeException('nu pot salva PDF-ul local');@chmod($file,0644);
            return ['file'=>$file,'token'=>$token,'link'=>app_absolute_path('/uploads/invoices/'.$token.'.pdf'),'warnings'=>[]];
        }catch(Throwable $e){$warnings[]='PDF-ul Oblio nu a putut fi copiat local: '.$e->getMessage().'. Se foloseste linkul Oblio ca fallback.';}
        return ['warnings'=>$warnings];
    }

    private function syncInvoiceToSource(array $order,array $invoice,string $link): array {return $this->syncDocumentToSource($order,$invoice,$link,'invoice');}

    private function syncDocumentToSource(array $order,array $document,string $link,string $kind='invoice'): array {
        if(!filter_var($link,FILTER_VALIDATE_URL)) throw new RuntimeException('URL-ul documentului nu este valid.');
        $host=strtolower((string)(parse_url($link,PHP_URL_HOST)??''));if($host===''||in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('APP_URL trebuie sa fie public pentru a publica documentul pe canalul extern.');
        $series=trim((string)($document['series']??''));$number=trim((string)($document['number']??''));$isStorno=$kind==='storno';$label=trim(($isStorno?'Factura storno ':'Factura ').$series.' '.$number);$client=ChannelFactory::client((string)$order['channel_code']);
        if($order['channel_type']==='emag'){
            if(!$client instanceof EmagClient)throw new RuntimeException('Integrarea eMAG pentru aceasta tara este dezactivata.');
            $orderType=(int)($order['raw']['type']??3);if(!in_array($orderType,[2,3],true))$orderType=3;
            $response=$client->saveOrderAttachment($order['external_id'],$label,$link,1,true,$orderType);
            return ['state'=>'sent','channel'=>'emag','document'=>$kind,'sent_at'=>date('c'),'response'=>$this->compactRemoteResponse($response)];
        }
        if($order['channel_type']==='woocommerce'){
            if(!$client instanceof WooCommerceClient)throw new RuntimeException('Integrarea WooCommerce este dezactivata.');
            $prefix=$isStorno?'_alfamed_storno':'_alfamed_invoice';
            $client->updateOrder($order['external_id'],['meta_data'=>[['key'=>$prefix.'_url','value'=>$link],['key'=>$prefix.'_number','value'=>trim($series.' '.$number)]]]);
            $note=($isStorno?'Factura storno ':'Factura ').$series.' '.$number.' este disponibila aici: '.$link;$noteRes=$client->createOrderNote($order['external_id'],$note,true);
            return ['state'=>'sent','channel'=>'woocommerce','document'=>$kind,'sent_at'=>date('c'),'customer_note_id'=>$noteRes['id']??null];
        }
        return ['state'=>'not_applicable'];
    }

    private function compactRemoteResponse(array $response): array {
        $out=[];foreach(['isError','messages'] as $key)if(array_key_exists($key,$response))$out[$key]=$response[$key];
        if(isset($response['results'])&&is_array($response['results']))$out['results_count']=count($response['results']);
        return $out;
    }

    private function log(string $level,string $action,string $message,array $context=[]): void {
        try{$st=$this->db->prepare('INSERT INTO sync_logs(channel_id,level,action,message,context_json) VALUES(NULL,?,?,?,?)');$st->execute([$level,$action,$message,$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);}catch(Throwable){}
    }

    private function moduleEnabled(): bool {
        try{
            $st=$this->db->prepare("SELECT enabled FROM modules WHERE slug='invoices-documents' LIMIT 1");
            $st->execute();
            return (bool)$st->fetchColumn();
        }catch(Throwable){return false;}
    }

    private function latestFiscalInvoice(int $orderId): ?array {
        $st=$this->db->prepare("SELECT * FROM invoices WHERE order_id=? AND type='invoice' AND status='issued' ORDER BY id DESC LIMIT 1");$st->execute([$orderId]);$row=$st->fetch();return $row?:null;
    }

    private function invoice(int $orderId,int $invoiceId): array {
        $st=$this->db->prepare('SELECT * FROM invoices WHERE id=? AND order_id=?');
        $st->execute([$invoiceId,$orderId]);
        $inv=$st->fetch();
        if(!$inv) throw new RuntimeException('Factura nu exista.');
        return $inv;
    }

    private function cleanIds(array $ids): array {
        return array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));
    }
}
