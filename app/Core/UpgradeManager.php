<?php
namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Small, idempotent schema upgrader used both by the web front controller and CLI.
 *
 * v0.4.1 intentionally creates the module/notification support tables without
 * MySQL foreign keys. Older or shared databases can contain a users table whose
 * integer type/engine differs from a fresh ALFAMED CENTRAL install; MySQL then
 * raises errno 150 while creating notification_reads. The application does not
 * depend on DB-level cascades for these auxiliary tables, so FK-free support
 * tables are safer and fully compatible with both fresh installs and upgrades.
 */
final class UpgradeManager {
    public static function currentVersion(): string {
        $root=dirname(__DIR__,2);
        return trim((string)@file_get_contents($root.'/VERSION')) ?: '0.0.0';
    }

    public static function installedVersion(): string {
        $root=dirname(__DIR__,2);
        $lock=json_decode((string)@file_get_contents($root.'/storage/installed.lock'),true);
        return is_array($lock) && !empty($lock['version']) ? (string)$lock['version'] : '0.0.0';
    }

    public static function runIfNeeded(?PDO $db=null): bool {
        $current=self::currentVersion();
        $installed=self::installedVersion();
        if(version_compare($installed,$current,'>=')) return false;
        self::run($db ?: Database::connection());
        return true;
    }

    public static function run(?PDO $db=null): void {
        $root=dirname(__DIR__,2);
        $db=$db ?: Database::connection();
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if(!in_array($driver,['mysql','sqlite'],true)) throw new RuntimeException('Driver DB nesuportat pentru upgrade: '.$driver);

        self::ensureModuleSupportTables($db,$driver);
        self::ensureRememberTokensTable($db,$driver);
        self::ensureActivityLogTable($db,$driver);

        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,value LONGTEXT NULL,scope VARCHAR(100) NOT NULL DEFAULT 'app',updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_settings_scope(scope)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT,setting_key TEXT NOT NULL UNIQUE,value TEXT,scope TEXT NOT NULL DEFAULT 'app',updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            $db->exec('CREATE INDEX IF NOT EXISTS idx_settings_scope ON settings(scope)');
        }

        self::addColumn($db,'users','permissions_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'users','is_active','TINYINT(1) NOT NULL DEFAULT 1','INTEGER NOT NULL DEFAULT 1');
        self::addColumn($db,'users','job_title','VARCHAR(150) NULL','TEXT');
        self::addColumn($db,'users','avatar_path','TEXT NULL','TEXT');
        self::addColumn($db,'users','last_active_at','DATETIME NULL','TEXT');
        self::addColumn($db,'users','push_orders_enabled','TINYINT(1) NOT NULL DEFAULT 1','INTEGER NOT NULL DEFAULT 1');
        self::addColumn($db,'users','push_stock_enabled','TINYINT(1) NOT NULL DEFAULT 1','INTEGER NOT NULL DEFAULT 1');
        self::addColumn($db,'users','push_chat_enabled','TINYINT(1) NOT NULL DEFAULT 1','INTEGER NOT NULL DEFAULT 1');
        self::addColumn($db,'users','updated_at','TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP','TEXT');
        self::addColumn($db,'users','deleted_at','DATETIME NULL','TEXT');
        self::addColumn($db,'notifications','product_id','BIGINT UNSIGNED NULL','INTEGER');
        self::addColumn($db,'notifications','severity',"VARCHAR(20) NOT NULL DEFAULT 'info'","TEXT NOT NULL DEFAULT 'info'");
        self::addColumn($db,'products','description','LONGTEXT NULL','TEXT');
        self::addColumn($db,'products','short_description','LONGTEXT NULL','TEXT');
        self::addColumn($db,'products','image_url','TEXT NULL','TEXT');
        self::addColumn($db,'products','archived','TINYINT(1) NOT NULL DEFAULT 0','INTEGER NOT NULL DEFAULT 0');
        self::addColumn($db,'orders','status_note','TEXT NULL','TEXT');
        self::addColumn($db,'orders','remote_deleted','TINYINT(1) NOT NULL DEFAULT 0','INTEGER NOT NULL DEFAULT 0');
        // Fiscal document relations used by Oblio storno. Older installations may predate this column.
        self::addColumn($db,'invoices','parent_invoice_id','BIGINT UNSIGNED NULL','INTEGER');
        self::addColumn($db,'channel_products','sale_price','VARCHAR(60) NULL','TEXT');
        self::addColumn($db,'channel_products','remote_status','VARCHAR(40) NULL','TEXT');
        self::addColumn($db,'channel_products','permalink','TEXT NULL','TEXT');
        self::addColumn($db,'channel_products','name','VARCHAR(255) NULL','TEXT');
        self::addColumn($db,'channel_products','description','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','short_description','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','image_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','categories_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','tags_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','brands_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'channel_products','seo_title','TEXT NULL','TEXT');
        self::addColumn($db,'channel_products','seo_description','TEXT NULL','TEXT');
        self::addColumn($db,'channel_products','seo_focus_keyword','TEXT NULL','TEXT');
        self::addColumn($db,'channel_products','seo_score','INT NULL','INTEGER');
        self::backfillRankMathSeoScores($db);
        self::addColumn($db,'channel_products','raw_json','LONGTEXT NULL','TEXT');
        self::addColumn($db,'order_items','image_url','TEXT NULL','TEXT');
        self::addColumn($db,'order_items','product_url','TEXT NULL','TEXT');
        self::addColumn($db,'order_items','remote_product_id','VARCHAR(190) NULL','TEXT');
        self::addColumn($db,'shipments','awb_barcode','VARCHAR(190) NULL','TEXT');
        self::addColumn($db,'shipments','provider_awb_id','VARCHAR(80) NULL','TEXT');
        self::ensureEmagProductMediaTable($db,$driver);
        self::ensureEmagProductLinksTable($db,$driver);
        self::ensureEmagOrderBackfillTable($db,$driver);

        // Hot-path indexes used by order lists, AWB/documents and analytics.
        // They are idempotent and are also present in fresh-install schemas.
        self::ensureIndex($db,'orders','idx_orders_created_at',['created_at']);
        self::ensureIndex($db,'orders','idx_orders_active_ordered',['remote_deleted','ordered_at','id']);
        self::ensureIndex($db,'orders','idx_orders_active_status_ordered',['remote_deleted','status','ordered_at','id']);
        self::ensureIndex($db,'orders','idx_orders_active_channel_ordered',['remote_deleted','channel_id','ordered_at','id']);
        self::ensureIndex($db,'order_items','idx_order_items_remote_product',['remote_product_id']);
        if($driver==='sqlite'){
            self::ensureIndex($db,'invoices','idx_invoice_order',['order_id']);
            self::ensureIndex($db,'shipments','idx_shipment_order',['order_id']);
        }
        self::ensureIndex($db,'shipments','idx_shipment_awb_barcode',['awb_barcode']);
        self::ensureIndex($db,'shipments','idx_shipment_provider_awb',['provider_awb_id']);
        self::ensureIndex($db,'sync_logs','idx_sync_log_level_action_created',['level','action','created_at']);
        self::ensureIndex($db,'sync_logs','idx_sync_log_channel_created',['channel_id','created_at']);

        // v0.10.4/0.10.5 could retain the successful order/read response in diagnostics.
        // Successful responses may contain customer PII, so scrub those legacy diagnostic payloads during upgrade.
        self::scrubEmagDiagnosticPayloads($db);

        // v0.11.7-v0.11.9 could write one Journal warning per missing eMAG image.
        // Those entries are operational noise, not actionable order-sync failures.
        self::cleanupLegacyEmagMediaLogs($db);

        // v0.11.16 restarts the AWB history cursors once. Earlier builds could mark
        // the history pass complete before older Marketplace/WooCommerce AWBs had
        // actually been associated with their local orders. Runtime cursors are
        // disposable and are rebuilt silently by the background worker.
        self::resetLegacyAwbSyncCursors($db);

        // v0.11.19 invalidates stale eMAG media and restarts a complete Marketplace AWB mirror.
        self::resetEmagRecoveryV01119($db);

        // v0.11.20 restarts the stricter AWB/media recovery introduced after real Marketplace validation.
        self::resetEmagRecoveryV01120($db);

        // v0.11.22 switches scanarea to the local AWB mirror and refreshes both pagination
        // edges on every pass. Restart only disposable AWB cursors so existing shipments stay.
        self::resetEmagAwbMirrorV01122($db);
        self::resetEmagAwbOrderRefreshV01123($db);
        self::resetEmagAwbFeedV01124($db);

        // v0.11.32 removes the experimental eMAG AWB discovery/backfill workers. The
        // Marketplace API cannot reliably discover an already-issued AWB by order id, so
        // discard obsolete automatic eMAG AWB discovery runtime state.
        self::cleanupObsoleteEmagAwbAutomationV01132($db);

        // v0.11.49 invalidates every automatically learned eMAG thumbnail/link from older
        // builds. Wrong media is presentation cache only; it is rebuilt from exact PNKs.
        // Manual PNK -> central product links are preserved because staff explicitly approved them.
        self::resetEmagImageTrustV01149($db);

        // v0.11.52 simplifies order thumbnails to exact eMAG API/order media or manual upload only.
        // Remove old catalogue-derived automatic images so they cannot reappear after upgrade.
        self::resetEmagImagesApiManualOnlyV01152($db);

        // v0.11.54 re-enables only the safe local-catalog fallback: exact SKU + exact normalized title.
        // Retry previously missing thumbnails immediately and discard any older fuzzy auto-links.
        self::enableExactCatalogueImagesV01154($db);

        // Chat v2: edit/delete window and per-room mute preferences. Older installs may
        // not have the team-chat module yet, so only alter these module tables when present.
        if(self::hasTable($db,'chat_messages')){
            self::addColumn($db,'chat_messages','edited_at','DATETIME NULL','TEXT');
            self::addColumn($db,'chat_messages','deleted_at','DATETIME NULL','TEXT');
        }
        if(self::hasTable($db,'chat_room_members')){
            self::addColumn($db,'chat_room_members','muted','TINYINT(1) NOT NULL DEFAULT 0','INTEGER NOT NULL DEFAULT 0');
        }

        $manager=new ModuleManager($db);
        foreach($manager->discover() as $slug=>$manifest){
            // Keep an administrator's enabled/disabled choice for modules that were already
            // installed. Only a newly discovered bundled module receives default_enabled.
            $exists=$db->prepare('SELECT id FROM modules WHERE slug=? LIMIT 1');
            $exists->execute([$slug]);
            $manager->install($slug,$exists->fetchColumn()?false:(bool)($manifest['default_enabled']??false));
        }

        // v0.11.37 distinguishes operator-maintained mappings from equivalences learned
        // automatically from the Oblio nomenclature. Existing rows are intentionally marked
        // manual so a background sync can never overwrite a choice already made by staff.
        if(self::hasTable($db,'oblio_product_mappings')){
            self::addColumn($db,'oblio_product_mappings','mapping_source',"VARCHAR(30) NOT NULL DEFAULT 'manual'","TEXT NOT NULL DEFAULT 'manual'");
            self::addColumn($db,'oblio_product_mappings','match_reason','VARCHAR(60) NULL','TEXT');
            self::ensureIndex($db,'oblio_product_mappings','idx_oblio_mapping_source',['mapping_source']);
        }

        $lockPath=$root.'/storage/installed.lock';
        $existing=json_decode((string)@file_get_contents($lockPath),true);
        if(!is_array($existing)) $existing=[];
        $existing['version']=self::currentVersion();
        $existing['upgraded_at']=date(DATE_ATOM);
        if(!isset($existing['installed_at'])) $existing['installed_at']=date(DATE_ATOM);
        if(file_put_contents($lockPath,json_encode($existing,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)===false){
            throw new RuntimeException('Schema a fost actualizata, dar nu am putut actualiza storage/installed.lock.');
        }
    }


    private static function backfillRankMathSeoScores(PDO $db): void {
        try{
            $rows=$db->query("SELECT id,raw_json FROM channel_products WHERE seo_score IS NULL AND raw_json IS NOT NULL AND raw_json<>''")->fetchAll();
            if(!$rows)return;$st=$db->prepare('UPDATE channel_products SET seo_score=? WHERE id=?');
            foreach($rows as $row){$raw=json_decode((string)($row['raw_json']??''),true);if(!is_array($raw))continue;$score=null;foreach((array)($raw['meta_data']??[]) as $meta){if(!is_array($meta)||($meta['key']??'')!=='rank_math_seo_score')continue;$value=$meta['value']??null;if(is_numeric($value))$score=max(0,min(100,(int)round((float)$value)));break;}if($score!==null)$st->execute([$score,(int)$row['id']]);}
        }catch(Throwable){}
    }


    /** Persistent browser-login tokens. Only SHA-256 validator hashes are stored server-side. */
    private static function ensureRememberTokensTable(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                selector CHAR(24) NOT NULL UNIQUE,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_auth_remember_user(user_id),
                KEY idx_auth_remember_expires(expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try{$db->exec('DELETE FROM auth_remember_tokens WHERE expires_at<CURRENT_TIMESTAMP');}catch(Throwable){}
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            selector TEXT NOT NULL UNIQUE,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            last_used_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_auth_remember_user ON auth_remember_tokens(user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_auth_remember_expires ON auth_remember_tokens(expires_at)');
        try{$db->exec('DELETE FROM auth_remember_tokens WHERE expires_at<CURRENT_TIMESTAMP');}catch(Throwable){}
    }

    private static function cleanupLegacyEmagMediaLogs(PDO $db): void {
        try{
            $db->exec("DELETE FROM sync_logs WHERE action IN ('emag_image_sync','emag_order_product_backfill')");
            $db->exec("DELETE FROM settings WHERE setting_key IN ('media.emag_image_sync_seconds','module.awb-scan.auto_open_order')");
        }catch(Throwable){}
    }

    private static function resetLegacyAwbSyncCursors(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.awb_sync_v01116']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.woo_awb_sync.%'");
            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.awb_sync_v01116','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.awb_sync_v01116']);
            }
        }catch(Throwable){}
    }


    /**
     * v0.11.19 recovery reset. Older builds could keep WooCommerce-derived thumbnails on
     * eMAG order_items and could finish an AWB backfill before all Marketplace AWBs were
     * associated. Clear only disposable caches/cursors; orders, invoices and valid local
     * shipments are preserved.
     */
    private static function resetEmagRecoveryV01119(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_recovery_v01119']);
            if((string)($check->fetchColumn()?:'')==='1')return;

            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");

            // Presentation cache only. This guarantees that an eMAG order can no longer
            // display a stale WooCommerce image/link retained from an older SKU fallback.
            try{$db->exec("UPDATE order_items SET image_url=NULL,product_url=NULL WHERE order_id IN (SELECT o.id FROM orders o JOIN channels c ON c.id=o.channel_id WHERE c.type='emag')");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_media'))$db->exec('DELETE FROM emag_product_media');}catch(Throwable){}
            try{if(self::hasTable($db,'emag_order_product_backfill'))$db->exec('DELETE FROM emag_order_product_backfill');}catch(Throwable){}

            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_recovery_v01119','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_recovery_v01119']);
            }
        }catch(Throwable){}
    }


    /**
     * v0.11.20 clean recovery pass. v0.11.19 may already be marked as migrated on
     * production, so this separate marker deliberately restarts only disposable eMAG
     * media/AWB cursors. Business documents and locally associated shipments stay intact.
     */
    private static function resetEmagRecoveryV01120(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_recovery_v01120']);
            if((string)($check->fetchColumn()?:'')==='1')return;

            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");
            try{$db->exec("UPDATE order_items SET image_url=NULL,product_url=NULL WHERE order_id IN (SELECT o.id FROM orders o JOIN channels c ON c.id=o.channel_id WHERE c.type='emag')");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_media'))$db->exec('DELETE FROM emag_product_media');}catch(Throwable){}
            try{if(self::hasTable($db,'emag_order_product_backfill'))$db->exec('DELETE FROM emag_order_product_backfill');}catch(Throwable){}

            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_recovery_v01120','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_recovery_v01120']);
            }
        }catch(Throwable){}
    }

    /** Restart only disposable eMAG AWB mirror state for v0.11.22. */
    private static function resetEmagAwbMirrorV01122(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_awb_mirror_v01122']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");
            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_awb_mirror_v01122','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_awb_mirror_v01122']);
            }
        }catch(Throwable){}
    }


    /** Restart disposable AWB reconciliation cursors for v0.11.23. */
    private static function resetEmagAwbOrderRefreshV01123(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_awb_refresh_v01123']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");
            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_awb_refresh_v01123','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_awb_refresh_v01123']);
            }
        }catch(Throwable){}
    }

    /** Restart AWB feed/tail discovery state after the v0.11.24 parser/search rewrite. */
    private static function resetEmagAwbFeedV01124(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_awb_feed_v01124']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_search.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");
            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_awb_feed_v01124','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_awb_feed_v01124']);
            }
        }catch(Throwable){}
    }


    /** Remove only obsolete eMAG AWB discovery state; WooCommerce tracking cursors stay. */
    private static function cleanupObsoleteEmagAwbAutomationV01132(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.remove_emag_awb_auto_v01132']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            $db->exec("DELETE FROM settings WHERE setting_key LIKE 'runtime.awb_sync.%' OR setting_key LIKE 'runtime.awb_order_reconcile.%' OR setting_key LIKE 'runtime.awb_order_search.%' OR setting_key LIKE 'runtime.awb_order_probe.%'");
            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.remove_emag_awb_auto_v01132','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.remove_emag_awb_auto_v01132']);
            }
        }catch(Throwable){}
    }


    /**
     * v0.11.49 thumbnail trust reset. Older releases could persist a neighboring eMAG image
     * after a public-page redirect or a weak catalogue match. Clear only disposable media and
     * automatic links; orders, products, invoices, AWBs and operator-approved manual links stay.
     */
    private static function resetEmagImageTrustV01149(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');
            $check->execute(['migration.emag_image_trust_v01149']);
            if((string)($check->fetchColumn()?:'')==='1')return;

            try{$db->exec("UPDATE order_items SET image_url=NULL,product_url=NULL WHERE order_id IN (SELECT o.id FROM orders o JOIN channels c ON c.id=o.channel_id WHERE c.type='emag')");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_media'))$db->exec('DELETE FROM emag_product_media');}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_links'))$db->exec("DELETE FROM emag_product_links WHERE mapping_source IS NULL OR mapping_source<>'manual'");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_order_product_backfill'))$db->exec('DELETE FROM emag_order_product_backfill');}catch(Throwable){}

            try{
                $ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");
                $ins->execute(['migration.emag_image_trust_v01149','1','runtime']);
            }catch(Throwable){
                $up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');
                $up->execute(['1','runtime','migration.emag_image_trust_v01149']);
            }
        }catch(Throwable){}
    }


    /** v0.11.52: keep only staff uploads and exact eMAG CDN media; discard catalogue/public-page fallbacks. */
    private static function resetEmagImagesApiManualOnlyV01152(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');$check->execute(['migration.emag_api_manual_only_v01152']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            try{$db->exec("UPDATE order_items SET image_url=NULL WHERE order_id IN (SELECT o.id FROM orders o JOIN channels c ON c.id=o.channel_id WHERE c.type='emag') AND (image_url IS NULL OR image_url NOT LIKE '%/uploads/emag-media/manual-%')");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_media'))$db->exec("DELETE FROM emag_product_media WHERE COALESCE(last_error,'') NOT LIKE '[MANUAL_IMAGE%' AND (source_url IS NULL OR source_url='' OR (source_url NOT LIKE '%akamaized.net/products/%' AND source_url NOT LIKE '%emagcdn%/products/%'))");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_links'))$db->exec("DELETE FROM emag_product_links WHERE mapping_source IS NULL OR mapping_source<>'manual'");}catch(Throwable){}
            try{$ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");$ins->execute(['migration.emag_api_manual_only_v01152','1','runtime']);}
            catch(Throwable){$up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');$up->execute(['1','runtime','migration.emag_api_manual_only_v01152']);}
        }catch(Throwable){}
    }

    /** v0.11.54: retry missing eMAG thumbnails and keep catalogue fallback strictly exact. */
    private static function enableExactCatalogueImagesV01154(PDO $db): void {
        try{
            $check=$db->prepare('SELECT value FROM settings WHERE setting_key=? LIMIT 1');$check->execute(['migration.emag_catalog_exact_v01154']);
            if((string)($check->fetchColumn()?:'')==='1')return;
            try{if(self::hasTable($db,'emag_product_links'))$db->exec("DELETE FROM emag_product_links WHERE mapping_source<>'manual' AND COALESCE(match_reason,'')='sku_name_similar'");}catch(Throwable){}
            try{if(self::hasTable($db,'emag_product_media'))$db->exec("UPDATE emag_product_media SET checked_at=NULL WHERE COALESCE(local_url,'')='' AND COALESCE(last_error,'') NOT LIKE '[MANUAL_IMAGE%'");}catch(Throwable){}
            try{$ins=$db->prepare("INSERT INTO settings(setting_key,value,scope) VALUES(?,?,?)");$ins->execute(['migration.emag_catalog_exact_v01154','1','runtime']);}
            catch(Throwable){$up=$db->prepare('UPDATE settings SET value=?,scope=? WHERE setting_key=?');$up->execute(['1','runtime','migration.emag_catalog_exact_v01154']);}
        }catch(Throwable){}
    }

    /** Retry state for recovering product_id on historical eMAG orders (v0.11.9). */
    private static function ensureEmagOrderBackfillTable(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS emag_order_product_backfill (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                channel_id BIGINT UNSIGNED NOT NULL,
                checked_at DATETIME NULL,
                resolved_at DATETIME NULL,
                attempt_count INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_emag_order_backfill_order(order_id),
                KEY idx_emag_order_backfill_due(channel_id,resolved_at,checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS emag_order_product_backfill (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL UNIQUE,
            channel_id INTEGER NOT NULL,
            checked_at TEXT,
            resolved_at TEXT,
            attempt_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_emag_order_backfill_due ON emag_order_product_backfill(channel_id,resolved_at,checked_at)');
    }


    private static function ensureActivityLogTable(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS user_activity_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                user_name VARCHAR(150) NOT NULL,
                user_email VARCHAR(190) NULL,
                user_role VARCHAR(30) NULL,
                action_key VARCHAR(100) NOT NULL,
                action_label VARCHAR(500) NOT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'system',
                entity_type VARCHAR(50) NULL,
                entity_id VARCHAR(190) NULL,
                route VARCHAR(500) NULL,
                method VARCHAR(15) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'success',
                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(500) NULL,
                context_json LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_activity_created(created_at),
                KEY idx_activity_user_created(user_id,created_at),
                KEY idx_activity_category_created(category,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            user_name TEXT NOT NULL,
            user_email TEXT NULL,
            user_role TEXT NULL,
            action_key TEXT NOT NULL,
            action_label TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'system',
            entity_type TEXT NULL,
            entity_id TEXT NULL,
            route TEXT NULL,
            method TEXT NULL,
            status TEXT NOT NULL DEFAULT 'success',
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            context_json TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_activity_created ON user_activity_logs(created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_activity_user_created ON user_activity_logs(user_id,created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_activity_category_created ON user_activity_logs(category,created_at)');
    }

    /** Stable PNK -> central product links. Auto-matched when safe, manually overridable from an order. */
    private static function ensureEmagProductLinksTable(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS emag_product_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                channel_id BIGINT UNSIGNED NOT NULL,
                pnk VARCHAR(100) NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                part_number VARCHAR(190) NULL,
                product_name VARCHAR(500) NULL,
                mapping_source VARCHAR(30) NOT NULL DEFAULT 'auto',
                match_reason VARCHAR(80) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_emag_product_link_channel_pnk(channel_id,pnk),
                KEY idx_emag_product_link_product(product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS emag_product_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER NOT NULL,
            pnk TEXT NOT NULL,
            product_id INTEGER NOT NULL,
            part_number TEXT,
            product_name TEXT,
            mapping_source TEXT NOT NULL DEFAULT 'auto',
            match_reason TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(channel_id,pnk)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_emag_product_link_product ON emag_product_links(product_id)');
    }

    /** Persistent API/cache metadata for eMAG product images (v0.11.7). */
    private static function ensureEmagProductMediaTable(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS emag_product_media (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                channel_id BIGINT UNSIGNED NOT NULL,
                remote_product_id VARCHAR(190) NOT NULL,
                source_url TEXT NULL,
                local_url TEXT NULL,
                image_hash CHAR(64) NULL,
                product_url TEXT NULL,
                synced_at DATETIME NULL,
                checked_at DATETIME NULL,
                last_error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_emag_media_channel_product(channel_id,remote_product_id),
                KEY idx_emag_media_checked(channel_id,checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }
        $db->exec("CREATE TABLE IF NOT EXISTS emag_product_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER NOT NULL,
            remote_product_id TEXT NOT NULL,
            source_url TEXT,
            local_url TEXT,
            image_hash TEXT,
            product_url TEXT,
            synced_at TEXT,
            checked_at TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(channel_id,remote_product_id)
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_emag_media_checked ON emag_product_media(channel_id,checked_at)');
    }

    /**
     * Create support tables one-by-one so a failed v0.4.0 migration can resume.
     * MySQL version deliberately avoids FK constraints on notification tables.
     */
    private static function ensureModuleSupportTables(PDO $db,string $driver): void {
        if($driver==='mysql'){
            $db->exec("CREATE TABLE IF NOT EXISTS modules (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(190) NOT NULL,
                version VARCHAR(40) NOT NULL,
                description TEXT,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->exec("CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(60) NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                order_id BIGINT UNSIGNED NULL,
                channel_id BIGINT UNSIGNED NULL,
                data_json LONGTEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_notification_order_type(type,order_id),
                KEY idx_notification_created(created_at),
                KEY idx_notification_order(order_id),
                KEY idx_notification_channel(channel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // v0.4.0 could stop here with MySQL errno 150. CREATE IF NOT EXISTS
            // makes this safe to run repeatedly after that partial upgrade.
            $db->exec("CREATE TABLE IF NOT EXISTS notification_reads (
                user_id BIGINT UNSIGNED NOT NULL,
                notification_id BIGINT UNSIGNED NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id,notification_id),
                KEY idx_nr_notification(notification_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return;
        }

        // SQLite does not have the MySQL/MariaDB integer/engine compatibility issue.
        $old=dirname(__DIR__,2).'/database/migrations/002_modules_notifications_sqlite.sql';
        if(is_file($old)) $db->exec((string)file_get_contents($old));
    }

    private static function hasTable(PDO $db,string $table): bool {
        if(!preg_match('/^[a-z0-9_]+$/i',$table)) return false;
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        }
        $st=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    }

    private static function hasColumn(PDO $db,string $table,string $column): bool {
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            if(!preg_match('/^[a-z0-9_]+$/i',$table)||!preg_match('/^[a-z0-9_]+$/i',$column)) return false;
            // MariaDB/MySQL do not reliably accept parameter markers in SHOW COLUMNS ... LIKE.
            // INFORMATION_SCHEMA supports prepared values and works on XAMPP/MariaDB and MySQL hosting.
            $st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
            $st->execute([$table,$column]);
            return (bool)$st->fetchColumn();
        }
        if(!preg_match('/^[a-z0-9_]+$/i',$table)) return false;
        foreach($db->query("PRAGMA table_info({$table})")->fetchAll() as $r) if(($r['name']??'')===$column) return true;
        return false;
    }

    private static function ensureIndex(PDO $db,string $table,string $name,array $columns): void {
        if(!self::hasTable($db,$table)) return;
        foreach(array_merge([$table,$name],$columns) as $identifier){
            if(!is_string($identifier)||!preg_match('/^[a-z0-9_]+$/i',$identifier)) throw new RuntimeException('Identificator index invalid.');
        }
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql'){
            $st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
            $st->execute([$table,$name]);
            if($st->fetchColumn()) return;
        } else {
            $st=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=? LIMIT 1");
            $st->execute([$name]);
            if($st->fetchColumn()) return;
        }
        $quotedColumns=implode(',',array_map(static fn(string $column)=>'`'.$column.'`',$columns));
        try { $db->exec("CREATE INDEX `{$name}` ON `{$table}` ({$quotedColumns})"); }
        catch(Throwable $e){
            if($driver==='mysql'){
                $st=$db->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
                $st->execute([$table,$name]);
                if($st->fetchColumn()) return;
            } else {
                $st=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=? LIMIT 1");
                $st->execute([$name]);
                if($st->fetchColumn()) return;
            }
            throw $e;
        }
    }


    private static function scrubEmagDiagnosticPayloads(PDO $db): void {
        try{
            if(self::hasTable($db,'settings')){
                $st=$db->query("SELECT id,value FROM settings WHERE setting_key IN ('diagnostic.emag_ro.last_result','diagnostic.emag_bg.last_result')");
                foreach($st->fetchAll() as $row){
                    $data=json_decode((string)($row['value']??''),true);
                    if(!is_array($data)||empty($data['ok'])||empty($data['api_message']))continue;
                    $data['api_message']='';
                    $up=$db->prepare('UPDATE settings SET value=? WHERE id=?');
                    $up->execute([json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$row['id']]);
                }
            }
            if(self::hasTable($db,'sync_logs')){
                $st=$db->query("SELECT id,context_json FROM sync_logs WHERE action='emag_api_test' AND level='success'");
                foreach($st->fetchAll() as $row){
                    $ctx=json_decode((string)($row['context_json']??''),true);
                    if(!is_array($ctx)||empty($ctx['api_message']))continue;
                    $ctx['api_message']='';
                    $up=$db->prepare('UPDATE sync_logs SET context_json=? WHERE id=?');
                    $up->execute([json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$row['id']]);
                }
            }
        }catch(Throwable){}
    }

    private static function addColumn(PDO $db,string $table,string $column,string $mysqlType,string $sqliteType): void {
        if(self::hasColumn($db,$table,$column)) return;
        if(!preg_match('/^[a-z0-9_]+$/i',$table)||!preg_match('/^[a-z0-9_]+$/i',$column)) throw new RuntimeException('Identificator DB invalid.');
        $driver=(string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $type=$driver==='mysql'?$mysqlType:$sqliteType;
        try { $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$type}"); }
        catch(Throwable $e){ if(!self::hasColumn($db,$table,$column)) throw $e; }
    }
}
