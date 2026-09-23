<?php
namespace App\Services;

use App\Core\Database;
use App\Core\ModuleManager;
use PDO;
use Throwable;

/**
 * Automatic synchronizer optimized for shared hosting.
 * - CRON is the primary worker and records a heartbeat.
 * - Browser polling is only a background fallback when CRON is stale.
 * - Order refreshes are incremental (modified since last successful pass).
 * - WooCommerce product refresh is incremental; a full reconciliation runs much less often.
 */
final class AutoSyncService {
    private PDO $db;
    private SettingService $settings;
    private string $lockFile;
    private string $backgroundLockFile;

    public function __construct(){
        $this->db=Database::connection();
        $this->settings=new SettingService($this->db);
        $this->lockFile=dirname(__DIR__,2).'/storage/auto-sync.lock';
        $this->backgroundLockFile=dirname(__DIR__,2).'/storage/awb-background.lock';
    }

    public function cronRecentlyActive(int $graceSeconds=180): bool {
        $last=(int)$this->settings->get('runtime.cron_last_seen','0');
        return $last>0 && (time()-$last)<=max(60,$graceSeconds);
    }

    public function browserFallbackNeeded(int $graceSeconds=180): bool {
        return $this->settings->bool('orders.auto_sync_enabled',true) && !$this->cronRecentlyActive($graceSeconds);
    }

    public function tick(bool $force=false,bool $includeProducts=true,bool $manualOrders=false): array {
        // An explicit manual run must work even when periodic auto-sync is disabled.
        $ordersEnabled=$manualOrders || $this->settings->bool('orders.auto_sync_enabled',true);
        $productsEnabled=$this->settings->bool('products.auto_sync_enabled',true);
        if(!$ordersEnabled&&!$productsEnabled) return ['ok'=>true,'skipped'=>true,'reason'=>'disabled'];

        // AWB recovery must never depend on the main order-sync lock or on a slow/failing
        // order/read pass. Run the eMAG module worker first, under its own lock. This also
        // means a new CRON invocation can keep importing AWBs even if an older order-sync
        // process is still holding auto-sync.lock.
        $background=$ordersEnabled?$this->runEmagBackgroundModules($force):['ok'=>true,'skipped'=>true,'reason'=>'orders_disabled'];

        $fp=@fopen($this->lockFile,'c+');
        if(!$fp) return ['ok'=>false,'skipped'=>true,'reason'=>'lock_unavailable'];
        if(!@flock($fp,LOCK_EX|LOCK_NB)){fclose($fp);return ['ok'=>true,'skipped'=>true,'reason'=>'already_running'];}
        try {
            $service=new SyncService();
            $channels=$this->db->query('SELECT * FROM channels WHERE enabled=1 ORDER BY id')->fetchAll();
            $modules=new ModuleManager($this->db);
            $out=[];
            foreach($channels as $ch){
                $code=(string)$ch['code'];$out[$code]=['ok'=>true];
                if($ordersEnabled){
                    $seconds=str_starts_with($code,'emag_')?(int)$this->settings->get('orders.emag_poll_seconds','30'):(int)$this->settings->get('orders.woo_fallback_poll_seconds','60');
                    $seconds=max(15,min(3600,$seconds));$last=(int)$this->settings->get('orders.last_poll.'.$code,'0');
                    if($force || $last===0 || (time()-$last)>=$seconds){
                        $out[$code]=$service->syncChannelOrders($ch,!$manualOrders);
                        // Mark the poll after the attempt. A request killed before reaching the API must not suppress the next retry.
                        $this->settings->set('orders.last_poll.'.$code,(string)time(),'runtime');
                    }else{$out[$code]['orders_skipped']='throttled';$out[$code]['orders_next_in']=max(0,$seconds-(time()-$last));}
                }else{$out[$code]['orders_skipped']='disabled';}

                // Give enabled modules a lightweight post-order-sync hook. Modules must
                // remain fail-safe: a module listener must never block the core sync.
                if(($ch['type']??'')!=='emag'){
                    try{$modules->dispatch('autosync.channel.completed',['channel'=>$ch,'result'=>$out[$code]]);}catch(Throwable){}
                }

                // Gradual eMAG media repair: independent from page rendering and heavily throttled.
                // This repopulates old images/direct product URLs without a manual full sync.
                if(($ch['type']??'')==='emag'){
                    $mediaLast=(int)$this->settings->get('media.last_poll.'.$code,'0');
                    if($force||$mediaLast===0||(time()-$mediaLast)>=60){
                        $this->settings->set('media.last_poll.'.$code,(string)time(),'runtime');
                        try{$out[$code]['media']=(new EmagImageSyncService($this->db,$this->settings))->syncDueForChannel($ch,8,false);}catch(Throwable $e){$out[$code]['media_error']=$e->getMessage();}
                    }else{$out[$code]['media_skipped']='throttled';}
                }

                if(($ch['type']??'')==='woocommerce' && $productsEnabled){
                    if(!$includeProducts){$out[$code]['products_skipped']='browser_deferred';continue;}
                    $productSeconds=max(120,min(86400,(int)$this->settings->get('products.auto_sync_seconds','600')));
                    $productLast=(int)$this->settings->get('products.last_poll.'.$code,'0');
                    if($productLast===0 || (time()-$productLast)>=$productSeconds){
                        $this->settings->set('products.last_poll.'.$code,(string)time(),'runtime');
                        $client=ChannelFactory::client($code);
                        if($client instanceof \App\Integrations\WooCommerceClient){
                            try{
                                $productService=new ProductService($this->db);
                                $fullSeconds=max(3600,min(604800,(int)$this->settings->get('products.full_sync_seconds','21600')));
                                $lastFull=(int)$this->settings->get('products.last_full_sync.'.$code,'0');
                                $mapCountSt=$this->db->prepare('SELECT COUNT(*) FROM channel_products WHERE channel_id=?');$mapCountSt->execute([(int)$ch['id']]);$mapCount=(int)$mapCountSt->fetchColumn();
                                if($lastFull===0 && $mapCount>0){$lastFull=time();$this->settings->set('products.last_full_sync.'.$code,(string)$lastFull,'runtime');}
                                if($mapCount===0 || (time()-$lastFull)>=$fullSeconds){
                                    $pages=(int)$this->settings->get('products.sync_max_pages','100');$pr=$productService->syncWooChannel($ch,$client,$pages);$out[$code]['products']=$pr;$this->settings->set('products.last_full_sync.'.$code,(string)time(),'runtime');$this->settings->set('products.last_success.'.$code,(string)time(),'runtime');
                                }else{
                                    $lastSuccess=(int)$this->settings->get('products.last_success.'.$code,(string)(time()-max(900,$productSeconds*2)));$from=max(time()-86400,$lastSuccess-120);
                                    $pr=$productService->syncWooChannelIncremental($ch,$client,gmdate('Y-m-d\\TH:i:s\\Z',$from),10);$out[$code]['products']=$pr;
                                    if(($pr['complete']??false))$this->settings->set('products.last_success.'.$code,(string)time(),'runtime');
                                }
                            }catch(Throwable $e){$out[$code]['product_error']=$e->getMessage();}
                        }
                    }else{$out[$code]['products_skipped']='throttled';$out[$code]['products_next_in']=max(0,$productSeconds-(time()-$productLast));}
                }
            }
            $this->settings->set('orders.last_auto_sync_at',date('Y-m-d H:i:s'),'runtime');
            return ['ok'=>true,'channels'=>$out,'background'=>$background];
        }catch(Throwable $e){return ['ok'=>false,'error'=>$e->getMessage(),'background'=>$background??null];}
        finally{@flock($fp,LOCK_UN);@fclose($fp);}
    }

    /** Run eMAG background module work independently from the main order synchronizer. */
    private function runEmagBackgroundModules(bool $force=false): array {
        $fp=@fopen($this->backgroundLockFile,'c+');
        if(!$fp)return ['ok'=>false,'skipped'=>true,'reason'=>'background_lock_unavailable'];
        if(!@flock($fp,LOCK_EX|LOCK_NB)){fclose($fp);return ['ok'=>true,'skipped'=>true,'reason'=>'background_already_running'];}
        try{
            $modules=new ModuleManager($this->db);
            $st=$this->db->query("SELECT * FROM channels WHERE enabled=1 AND type='emag' ORDER BY id");
            $out=[];
            foreach($st->fetchAll() as $ch){
                $code=(string)$ch['code'];
                try{
                    $modules->dispatch('autosync.channel.completed',['channel'=>$ch,'result'=>['ok'=>true,'phase'=>'background','forced'=>$force]]);
                    $out[$code]=['ok'=>true];
                }catch(Throwable $e){$out[$code]=['ok'=>false,'error'=>$e->getMessage()];}
            }
            return ['ok'=>true,'channels'=>$out];
        }catch(Throwable $e){return ['ok'=>false,'error'=>$e->getMessage()];}
        finally{@flock($fp,LOCK_UN);@fclose($fp);}
    }

    public function force(): array {
        $this->settings->set('runtime.cron_last_seen',(string)time(),'runtime');
        $this->settings->set('runtime.cron_last_seen_at',date('Y-m-d H:i:s'),'runtime');
        return $this->tick(true,true);
    }
}
