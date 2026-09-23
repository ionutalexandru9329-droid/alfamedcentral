<?php use App\Core\Csrf; use App\Core\Env; use App\Core\View;
$integrationCount=count($integrations??[]);
$integrationActive=count(array_filter($integrations??[],fn($i)=>!empty($i['enabled'])));
$moduleCount=count($moduleCards??[]);
$tab=$tab??'general';
$tabMeta=[
    'general'=>['icon'=>'⚙','label'=>'General','hint'=>'Aplicatie si sincronizare'],
    'integrations'=>['icon'=>'↔','label'=>'Integrari','hint'=>'Marketplace si magazine'],
    'modules'=>['icon'=>'▦','label'=>'Module','hint'=>'Functii extensibile'],
    'logs'=>['icon'=>'≡','label'=>'Jurnal','hint'=>'Sincronizari si erori'],
];
?>
<div class="settings-page-shell">
    <div class="page-head settings-page-head">
        <div>
            <div class="eyebrow">CONTROL APLICATIE</div>
            <h1>Setari</h1>
            <p>Configurare centrala pentru aplicatie, canale, module si automatizari.</p>
        </div>
        <div class="settings-head-stats">
            <div><span>Integrari active</span><strong><?=$integrationActive?> / <?=$integrationCount?></strong></div>
            <div><span>Module configurabile</span><strong><?=$moduleCount?></strong></div>
        </div>
    </div>

    <nav class="settings-pro-nav" aria-label="Sectiuni setari">
        <?php foreach($tabMeta as $key=>$meta):?>
        <a class="settings-pro-tab <?=$tab===$key?'active':''?>" href="<?=View::e(app_path('/settings'))?>&tab=<?=View::e($key)?>">
            <span class="settings-pro-tab-icon"><?=$meta['icon']?></span>
            <span><b><?=View::e($meta['label'])?></b><small><?=View::e($meta['hint'])?></small></span>
        </a>
        <?php endforeach;?>
    </nav>

    <?php if($tab==='general'):?>
    <form method="post" action="<?=View::e(app_path('/settings/general'))?>" class="settings-pro-form">
        <?=Csrf::field()?>
        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head"><span class="settings-section-icon">⚙</span><div><h2>Aplicatie</h2><p>Identitatea si adresa publica a platformei.</p></div></div>
            <div class="settings-grid three">
                <label>Nume aplicatie<input name="APP_NAME" value="<?=View::e(Env::get('APP_NAME','ALFAMED CENTRAL'))?>" required></label>
                <label>URL public aplicatie<input type="url" name="APP_URL" value="<?=View::e(Env::get('APP_URL',''))?>" required><small>Pe hosting foloseste URL HTTPS.</small></label>
                <label>Fus orar<input name="APP_TIMEZONE" value="<?=View::e(Env::get('APP_TIMEZONE','Europe/Bucharest'))?>" required></label>
            </div>
            <div class="settings-inline-option"><label class="switchline"><input type="checkbox" name="APP_DEBUG" <?=Env::bool('APP_DEBUG')?'checked':''?>> Mod debug</label><span>Activeaza doar temporar pentru diagnostic.</span></div>
        </section>

        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head"><span class="settings-section-icon">▤</span><div><h2>Afisare liste</h2><p>Controleaza cate inregistrari sunt incarcate simultan.</p></div></div>
            <div class="settings-grid two">
                <label>Comenzi pe pagina<input type="number" min="10" max="200" name="ORDERS_PER_PAGE" value="<?=View::e($settings->get('ui.orders_per_page',$settings->get('ui.rows_per_page','50')))?>"></label>
                <label>Produse pe pagina<input type="number" min="10" max="200" name="PRODUCTS_PER_PAGE" value="<?=View::e($settings->get('ui.products_per_page',$settings->get('ui.rows_per_page','50')))?>"></label>
            </div>
        </section>

        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head"><span class="settings-section-icon">☰</span><div><h2>Header si navigare</h2><p>Alege separat fiecare scurtatura din header. Pe telefon si tableta, aceleasi optiuni apar in meniul hamburger.</p></div></div>
            <?php if(empty($headerLinkOptions)):?><div class="empty-state">Nu exista scurtaturi de module disponibile in header.</div><?php else:?><div class="header-link-settings-grid"><?php foreach($headerLinkOptions as $item):?><label class="settings-check-card header-link-choice"><input type="checkbox" name="HEADER_LINKS[]" value="<?=View::e($item['key'])?>" <?=in_array($item['key'],$headerSelectedLinks??[],true)?'checked':''?>><span><b><?=View::e($item['label'])?></b><small><?=View::e($item['module_name'])?> · <?=View::e($item['url'])?></small></span></label><?php endforeach;?></div><?php endif;?>
        </section>

        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head"><span class="settings-section-icon">#</span><div><h2>SKU produse</h2><p>Numerotarea automata folosita pentru produsele create sau importate in ALFAMED CENTRAL.</p></div></div>
            <div class="settings-grid two"><label>Prefix SKU automat<input name="PRODUCT_SKU_PREFIX" maxlength="20" value="<?=View::e($settings->get('products.sku_prefix','ON'))?>"><small>Exemplu: daca ultimul SKU este ON500, urmatorul va fi ON501.</small></label></div>
        </section>

        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head with-toggle"><span class="settings-section-icon">↻</span><div><h2>Sincronizare comenzi</h2><p>Import initial, apoi actualizare automata pentru eMAG si WooCommerce.</p></div><label class="toggle"><input type="checkbox" name="ORDER_AUTO_SYNC_ENABLED" <?=$settings->bool('orders.auto_sync_enabled',true)?'checked':''?>><span>Activ</span></label></div>
            <div class="settings-grid three">
                <label>Istoric initial (zile)<input type="number" min="1" max="730" name="ORDER_INITIAL_IMPORT_DAYS" value="<?=View::e($settings->get('orders.initial_import_days','180'))?>"><small>Marirea valorii extinde istoricul importat.</small></label>
                <label>eMAG - interval secunde<input type="number" min="15" max="3600" name="EMAG_POLL_SECONDS" value="<?=View::e($settings->get('orders.emag_poll_seconds','30'))?>"></label>
                <label>WooCommerce fallback - secunde<input type="number" min="15" max="3600" name="WOO_POLL_SECONDS" value="<?=View::e($settings->get('orders.woo_fallback_poll_seconds','60'))?>"></label>
            </div>
            <div class="settings-footnote">Ultima rulare: <b><?=View::e($settings->get('orders.last_auto_sync_at','niciodata'))?></b> · CRON: <b><?=((time()-(int)$settings->get('runtime.cron_last_seen','0'))<=180)?'activ':'neconfirmat / oprit'?></b><?php if($settings->get('runtime.cron_last_seen_at','')):?> (<?=View::e($settings->get('runtime.cron_last_seen_at',''))?>)<?php endif;?></div>
        </section>

        <section class="panel settings-pro-section">
            <div class="settings-pro-section-head with-toggle"><span class="settings-section-icon">◫</span><div><h2>Sincronizare produse</h2><p>Detecteaza automat produse noi, actualizate sau sterse.</p></div><label class="toggle"><input type="checkbox" name="PRODUCT_AUTO_SYNC_ENABLED" <?=$settings->bool('products.auto_sync_enabled',true)?'checked':''?>><span>Activ</span></label></div>
            <div class="settings-grid three">
                <label>Interval incremental (secunde)<input type="number" min="120" max="86400" name="PRODUCT_POLL_SECONDS" value="<?=View::e($settings->get('products.auto_sync_seconds','600'))?>"><small>Verifica doar produsele modificate de la ultima rulare.</small></label>
                <label>Reconciliere completa (ore)<input type="number" min="1" max="168" name="PRODUCT_FULL_SYNC_HOURS" value="<?=View::e((int)ceil(((int)$settings->get('products.full_sync_seconds','21600'))/3600))?>"><small>Implicit 6 ore; folosita pentru stergeri si verificare completa.</small></label>
                <label>Pagini maxime / magazin<input type="number" min="1" max="250" name="PRODUCT_SYNC_MAX_PAGES" value="<?=View::e($settings->get('products.sync_max_pages','100'))?>"><small>Doar pentru reconcilierea completa/manuala.</small></label>
            </div>
        </section>

        <div class="settings-sticky-actions"><button class="primary">Salveaza setarile generale</button></div>
    </form>

    <?php elseif($tab==='integrations'):?>
    <section class="panel settings-pro-section">
        <div class="settings-pro-section-head"><span class="settings-section-icon">↔</span><div><h2>Integrari canale</h2><p>Aici raman doar marketplace-urile si magazinele care schimba date cu ALFAMED CENTRAL.</p></div></div>
        <div class="settings-card-grid settings-card-grid-pro">
            <?php foreach($integrations as $i): $icons=['emag_ro'=>'🇷🇴','emag_bg'=>'🇧🇬','univera'=>'U','alfamed'=>'A']; ?>
            <a class="settings-nav-card settings-nav-card-pro" href="<?=View::e(app_path('/settings/integrations/'.$i['key']))?>">
                <span class="settings-card-icon"><?=View::e($icons[$i['key']]??'↔')?></span>
                <div class="settings-card-copy"><h3><?=View::e($i['name'])?></h3><p><?=View::e($i['hint'])?></p></div>
                <span class="integration-state <?=$i['enabled']?'on':'off'?>"><?=$i['enabled']?'ACTIV':'INACTIV'?></span>
                <span class="card-arrow">→</span>
            </a>
            <?php endforeach;?>
        </div>
        <div class="settings-info-strip"><span>i</span><p><b>Testarea eMAG ramane in fiecare integrare separat.</b> In fiecare pagina de integrare ai deja buton dedicat de test, iar documentele Oblio se configureaza din <a href="<?=View::e(app_path('/settings/modules/invoices-documents'))?>">Setari → Module → Oblio - Facturi & documente</a>.</p></div>
    </section>

    <?php elseif($tab==='modules'):?>
    <section class="panel settings-pro-section">
        <div class="settings-pro-section-head"><span class="settings-section-icon">▦</span><div><h2>Setari module</h2><p>Fiecare modul activ isi pastreaza setarile intr-o pagina separata.</p></div><a class="button-secondary" href="<?=View::e(app_path('/modules'))?>">Administreaza module</a></div>
        <?php if(!$moduleCards):?><div class="empty-state">Niciun modul activ nu declara setari.</div><?php else:?>
        <div class="settings-card-grid settings-card-grid-pro">
            <?php $icons=['invoices-documents'=>'🧾','couriers'=>'🚚','awb-scan'=>'▣','new-order-popup'=>'🔔','team-chat'=>'💬','ai-product-import'=>'✦','statistics'=>'▥']; foreach($moduleCards as $m):?>
            <a class="settings-nav-card settings-nav-card-pro" href="<?=View::e(app_path('/settings/modules/'.$m['slug']))?>">
                <span class="settings-card-icon"><?=$icons[$m['slug']]??'▦'?></span>
                <div class="settings-card-copy"><h3><?=View::e($m['name'])?></h3><p><?=View::e($m['description'])?></p></div>
                <span class="integration-state on">ACTIV</span><span class="card-arrow">→</span>
            </a>
            <?php endforeach;?>
        </div>
        <?php endif;?>
    </section>

    <?php else:?>
    <section class="panel settings-pro-section">
        <div class="settings-pro-section-head"><span class="settings-section-icon">≡</span><div><h2>Jurnal sincronizare</h2><p>Ultimele operatiuni API, sincronizari si erori.</p></div></div>
        <div class="table-wrap settings-log-table"><table><thead><tr><th>Data</th><th>Canal</th><th>Nivel</th><th>Actiune</th><th>Mesaj</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=View::e($l['created_at'])?></td><td><?=View::e($l['channel_name']??'Sistem')?></td><td><span class="log-level <?=View::e($l['level'])?>"><?=View::e(strtoupper($l['level']))?></span></td><td><?=View::e($l['action'])?></td><td><?=View::e($l['message'])?></td></tr><?php endforeach;?></tbody></table></div>
    </section>
    <?php endif;?>
</div>
