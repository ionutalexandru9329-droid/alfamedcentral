<?php use App\Core\View; use App\Core\Csrf;
$activeFilters=(($q??'')!==''?1:0)+(($channel??'')!==''?1:0)+(($productStatus??'')!==''?1:0);
$statusCounts=$statusCounts??['total'=>0,'published'=>0,'pending'=>0,'trash'=>0];
$productStatusLabels=['publish'=>'Publicat','draft'=>'Ciorna','pending'=>'In asteptare','private'=>'Privat','future'=>'Programat','trash'=>'Gunoi'];
$makeStatusUrl=function(string $status='') use($q,$channel){$url=app_path('/products');if($status!=='')$url.='&product_status='.rawurlencode($status);if(($q??'')!=='')$url.='&q='.rawurlencode((string)$q);if(($channel??'')!=='')$url.='&channel='.rawurlencode((string)$channel);return $url;};
?>
<div class="products-page-shell">
    <div class="page-head products-page-head">
        <div>
            <div class="eyebrow">CATALOG PRODUSE</div>
            <h1>Produse</h1>
            <p>Catalog central sincronizat cu Univera.ro si Alfamedclinic.ro.</p>
        </div>
        <div class="products-head-side">
            <div class="products-head-stats">
                <div><span>Total filtrat</span><strong><?=(int)($total??0)?></strong></div>
                <div><span>Pe pagina</span><strong><?=count($products)?></strong></div>
            </div>
            <div class="product-page-actions product-page-actions-polished">
                <a class="primary-link product-top-action" href="<?=View::e(app_path('/products/new'))?>"><span aria-hidden="true">＋</span><span>Produs nou</span></a>
                <?php if(isset($modules))$modules->renderHook('products.actions',['page'=>'products']);?>
                <button class="button-secondary product-top-action" type="button" data-products-sync data-sync-url="<?=View::e(app_path('/api/products/sync'))?>"><span aria-hidden="true">↻</span><span data-sync-label>Sincronizeaza magazinele</span></button>
            </div>
        </div>
    </div>


    <form class="products-filterbar" method="get" action="<?=View::e(app_front_controller())?>" data-products-filter-form>
        <input type="hidden" name="route" value="/products"><?php if(($productStatus??'')!==''):?><input type="hidden" name="product_status" value="<?=View::e($productStatus)?>"><?php endif;?>
        <label class="orders-search-box products-search-box">
            <span aria-hidden="true">⌕</span>
            <input name="q" value="<?=View::e($q??'')?>" placeholder="Cauta dupa SKU, EAN sau titlu in toate magazinele..." autocomplete="off" data-products-search>
        </label>
        <label class="orders-filter-select"><span>Magazin</span><select name="channel" data-products-auto-filter><option value="">Toate magazinele</option><?php foreach($channels as $c):?><option value="<?=View::e($c['code'])?>" <?=($channel??'')===$c['code']?'selected':''?>><?=View::e($c['name'])?></option><?php endforeach;?></select></label>
        <?php if($activeFilters>0):?><a class="orders-clear-filter" href="<?=View::e(app_path('/products'))?>" title="Sterge filtrele">× Reseteaza</a><?php endif;?>
    </form>

    <form method="post" action="<?=View::e(app_path('/products/actions'))?>" id="productsBulkForm" class="bulk-form"><?=Csrf::field()?>
        <section class="panel products-bulk-toolbar">
            <div class="bulk-toolbar-top">
                <div class="bulk-toolbar-main">
                    <div class="bulk-icon" aria-hidden="true">✓</div>
                    <div><strong>Actiuni produse</strong><small>Selecteaza produsele pe care vrei sa le procesezi.</small></div>
                    <span class="selection-count" data-selection-count>0 selectate</span>
                </div>
                <div class="bulk-controls">
                    <select name="action" data-product-action-select aria-label="Alege actiunea">
                        <option value="">Alege actiunea...</option>
                        <option value="transfer">⇄ Transfera pe celalalt magazin</option>
                        <option value="delete">🗑 Sterge din magazin</option>
                        <option value="bulk_edit">✎ Editeaza in masa</option>
                    </select>
                    <button class="primary bulk-apply-button" type="submit">Aplica</button>
                </div>
            </div>

            <div class="product-bulk-detail" data-transfer-options hidden>
                <div class="product-bulk-intro"><span class="product-bulk-icon">⇄</span><div><strong>Transfer produse</strong><small>Copiaza produsele selectate dintr-un magazin in celalalt.</small></div></div>
                <div class="product-transfer-grid">
                    <label><span>Sursa</span><select name="source_code"><option value="univera">Univera.ro</option><option value="alfamed">Alfamedclinic.ro</option></select></label>
                    <span class="transfer-arrow" aria-hidden="true">→</span>
                    <label><span>Destinatie</span><select name="target_code"><option value="alfamed">Alfamedclinic.ro</option><option value="univera">Univera.ro</option></select></label>
                </div>
            </div>

            <div class="product-bulk-detail" data-delete-options hidden>
                <div class="product-bulk-intro"><span class="product-bulk-icon danger">🗑</span><div><strong>Sterge din magazin</strong><small>Alege magazinul sau magazinele din care vor fi sterse produsele selectate.</small></div></div>
                <div class="product-target-grid compact-target-grid">
                    <label class="check-card"><input type="checkbox" name="targets[]" value="univera"><span>Univera.ro<small>Stergere definitiva din WooCommerce</small></span></label>
                    <label class="check-card"><input type="checkbox" name="targets[]" value="alfamed"><span>Alfamedclinic.ro<small>Stergere definitiva din WooCommerce</small></span></label>
                </div>
            </div>

            <div class="product-bulk-detail" data-bulk-edit-options hidden>
                <div class="product-bulk-intro"><span class="product-bulk-icon">✎</span><div><strong>Editare in masa</strong><small>Campurile lasate goale raman neschimbate.</small></div></div>
                <div class="product-bulk-edit-grid">
                    <label><span>Pret nou</span><input name="regular_price" type="number" min="0" step="0.01" placeholder="Neschimbat"></label>
                    <label><span>Stoc nou</span><input name="stock_quantity" type="number" step="1" placeholder="Neschimbat"></label>
                    <label><span>Status</span><select name="status"><option value="">Neschimbat</option><option value="publish">Publicat</option><option value="draft">Ciorna</option><option value="pending">In asteptare</option></select></label>
                    <div class="product-edit-targets"><span>Actualizeaza pe</span><label><input type="checkbox" name="edit_targets[]" value="univera" checked> Univera</label><label><input type="checkbox" name="edit_targets[]" value="alfamed" checked> Alfamed</label></div>
                </div>
            </div>
        </section>

        <section class="panel product-status-panel">
            <div class="product-status-panel-head"><div><span class="eyebrow">STATUS CATALOG</span><h2>Afisare produse</h2><p>Filtreaza catalogul central dupa statusul preluat din magazine.</p></div></div>
            <nav class="product-status-nav" aria-label="Status produse">
                <a class="<?=($productStatus??'')===''?'active':''?>" href="<?=View::e($makeStatusUrl(''))?>"><span>Total</span><b><?=number_format((int)$statusCounts['total'],0,',','.')?></b></a>
                <a class="<?=($productStatus??'')==='published'?'active':''?>" href="<?=View::e($makeStatusUrl('published'))?>"><span>Publicate</span><b><?=number_format((int)$statusCounts['published'],0,',','.')?></b></a>
                <a class="<?=($productStatus??'')==='pending'?'active':''?>" href="<?=View::e($makeStatusUrl('pending'))?>"><span>In asteptare</span><b><?=number_format((int)$statusCounts['pending'],0,',','.')?></b></a>
                <a class="<?=($productStatus??'')==='trash'?'active':''?>" href="<?=View::e($makeStatusUrl('trash'))?>"><span>Gunoi</span><b><?=number_format((int)$statusCounts['trash'],0,',','.')?></b></a>
            </nav>
        </section>

        <section class="panel products-table-panel">
            <div class="table-wrap"><table class="products-table"><thead><tr><th class="check-col"><input type="checkbox" data-select-all="products" aria-label="Selecteaza toate"></th><th>Imagine</th><th>SKU</th><th>Produs</th><th>Univera.ro</th><th>Alfamedclinic.ro</th><th>Canale</th><th>SEO</th><th class="actions-col">Actiuni</th></tr></thead><tbody>
            <?php foreach($products as $p):?><tr>
                <td><input type="checkbox" name="product_ids[]" value="<?=(int)$p['id']?>" data-select-item="products" aria-label="Selecteaza <?=View::e($p['name'])?>"></td>
                <td><?php if(!empty($p['image_url'])):?><button type="button" class="product-thumb-button js-image-preview" data-image="<?=View::e($p['image_url'])?>" data-caption="<?=View::e($p['name'])?>"><img class="product-thumb" src="<?=View::e($p['image_url'])?>" alt="<?=View::e($p['name'])?>" loading="lazy"></button><?php else:?><div class="product-thumb placeholder">fara<br>imagine</div><?php endif;?></td>
                <td><a class="product-sku-link" href="<?=View::e(app_path('/products/'.$p['id']))?>"><?=View::e($p['sku'])?></a><?php if(!empty($p['ean'])):?><br><small>EAN <?=View::e($p['ean'])?></small><?php endif;?></td>
                <td><div class="product-title-cell"><strong><?=View::e($p['name'])?></strong></div></td>
                <td><?php if($p['univera_price']!==null):?><div class="store-value"><strong><?=number_format((float)$p['univera_price'],2,',',' ')?> RON</strong><small>stoc <?=View::e($p['univera_stock'])?></small><?php if(!empty($p['univera_status'])):?><span class="product-remote-status status-<?=View::e($p['univera_status'])?>"><?=View::e($productStatusLabels[$p['univera_status']]??ucfirst((string)$p['univera_status']))?></span><?php endif;?></div><?php else:?><span class="muted">—</span><?php endif;?></td>
                <td><?php if($p['alfamed_price']!==null):?><div class="store-value"><strong><?=number_format((float)$p['alfamed_price'],2,',',' ')?> RON</strong><small>stoc <?=View::e($p['alfamed_stock'])?></small><?php if(!empty($p['alfamed_status'])):?><span class="product-remote-status status-<?=View::e($p['alfamed_status'])?>"><?=View::e($productStatusLabels[$p['alfamed_status']]??ucfirst((string)$p['alfamed_status']))?></span><?php endif;?></div><?php else:?><span class="muted">—</span><?php endif;?></td>
                <td><div class="product-channel-stack"><?php if($p['alfamed_price']!==null):?><span class="tag product-channel-tag">Alfamedclinic.ro</span><?php endif;?><?php if($p['univera_price']!==null):?><span class="tag product-channel-tag">Univera.ro</span><?php endif;?><?php if($p['alfamed_price']===null&&$p['univera_price']===null):?><span class="muted">—</span><?php endif;?></div></td>
                <td><div class="product-seo-list"><?php foreach([['U',$p['univera_seo_score']??null],['A',$p['alfamed_seo_score']??null]] as $scoreRow):?><?php if($scoreRow[1]!==null):?><?php $sv=(int)$scoreRow[1];$sc=$sv>=80?'good':($sv>=50?'medium':'low');?><span class="seo-score-mini <?=$sc?>" title="<?=$scoreRow[0]==='U'?'Univera.ro':'Alfamedclinic.ro'?>"><i><?=$scoreRow[0]?></i><b><?=$sv?></b></span><?php endif;?><?php endforeach;?><?php if(($p['univera_seo_score']??null)===null&&($p['alfamed_seo_score']??null)===null):?><span class="muted">—</span><?php endif;?></div></td>
                <td class="product-actions-cell"><div class="product-row-actions">
                    <a class="icon-action compact-action" href="<?=View::e(app_path('/products/'.$p['id']))?>" title="Editeaza produsul">✎ <span>Editeaza</span></a>
                    <button type="button" class="icon-action compact-action quick-action js-quick-edit" data-id="<?=(int)$p['id']?>" data-name="<?=View::e($p['name'])?>" data-sku="<?=View::e($p['sku'])?>" data-price="<?=View::e($p['univera_price']??$p['alfamed_price']??'')?>" data-stock="<?=View::e($p['alfamed_stock']??$p['univera_stock']??'')?>" title="Editare rapida">⚡ <span>Rapid</span></button>
                    <button type="button" class="icon-action kebab-action js-product-action" data-id="<?=(int)$p['id']?>" data-name="<?=View::e($p['name'])?>" title="Mai multe actiuni" aria-label="Mai multe actiuni">⋯</button>
                </div></td>
            </tr><?php endforeach;?>
            <?php if(!$products):?><tr><td colspan="9" class="empty-state"><strong>Nu exista produse pentru filtrele selectate.</strong><br><span>Modifica filtrul sau sincronizeaza magazinele WooCommerce.</span></td></tr><?php endif;?></tbody></table></div>
        </section>
    </form>

    <?php if(($pages??1)>1): $makeProductsPage=function(int $p) use($q,$channel,$productStatus){$url=app_path('/products').'&page='.$p;if(($q??'')!=='')$url.='&q='.rawurlencode((string)$q);if(($channel??'')!=='')$url.='&channel='.rawurlencode((string)$channel);if(($productStatus??'')!=='')$url.='&product_status='.rawurlencode((string)$productStatus);return $url;}; ?>
    <nav class="pagination" aria-label="Paginare produse"><span class="pagination-info">Afisate <?=count($products)?> din <?=(int)($total??count($products))?> produse</span><div class="pagination-links"><?php if(($page??1)>1):?><a href="<?=View::e($makeProductsPage((int)$page-1))?>">‹ Inapoi</a><?php endif;?><?php $from=max(1,(int)$page-2);$to=min((int)$pages,(int)$page+2);for($i=$from;$i<=$to;$i++):?><a class="<?=$i===(int)$page?'active':''?>" href="<?=View::e($makeProductsPage($i))?>"><?=$i?></a><?php endfor;?><?php if(($page??1)<($pages??1)):?><a href="<?=View::e($makeProductsPage((int)$page+1))?>">Inainte ›</a><?php endif;?></div></nav>
    <?php endif;?>
</div>

<div id="quickEditModal" class="simple-modal product-modal" hidden><div class="simple-modal-backdrop" data-modal-close></div><div class="simple-modal-card product-modal-card"><button class="simple-modal-close" type="button" data-modal-close>×</button><div class="product-modal-head"><span class="product-modal-icon">⚡</span><div><h2>Editare rapida</h2><p>Modifica informatiile esentiale fara sa parasesti lista de produse.</p></div></div><form method="post" id="quickEditForm" action=""><?=Csrf::field()?>
    <div class="quick-edit-grid"><label class="full-span"><span>Titlu produs</span><input name="name" id="qeName"></label><label><span>SKU</span><input name="sku" id="qeSku"></label><label><span>Pret normal</span><input name="regular_price" id="qePrice" type="number" step="0.01"></label><label><span>Stoc</span><input name="stock_quantity" id="qeStock" type="number"></label><label><span>Status</span><select name="status"><option value="publish">Publicat</option><option value="draft">Ciorna</option><option value="pending">In asteptare</option></select></label></div>
    <div class="modal-section"><strong>Actualizeaza pe</strong><div class="product-target-grid compact-target-grid"><label class="check-card"><input type="checkbox" name="targets[]" value="univera" checked><span>Univera.ro<small>WooCommerce</small></span></label><label class="check-card"><input type="checkbox" name="targets[]" value="alfamed" checked><span>Alfamedclinic.ro<small>WooCommerce</small></span></label></div></div>
    <div class="modal-actions"><button type="button" class="button-secondary" data-modal-close>Renunta</button><button class="primary">Salveaza modificarile</button></div>
</form></div></div>

<div id="productActionModal" class="simple-modal product-modal" hidden><div class="simple-modal-backdrop" data-product-modal-close></div><div class="simple-modal-card product-modal-card"><button class="simple-modal-close" type="button" data-product-modal-close>×</button><div class="product-modal-head"><span class="product-modal-icon">⋯</span><div><h2>Actiune produs</h2><p id="paName" class="muted"></p></div></div><form method="post" action="<?=View::e(app_path('/products/actions'))?>"><?=Csrf::field()?><input type="hidden" name="product_ids[]" id="paId">
    <label class="modal-action-select"><span>Actiune</span><select name="action" id="paAction"><option value="transfer">⇄ Transfera pe celalalt magazin</option><option value="delete">🗑 Sterge din magazin</option></select></label>
    <div class="modal-section" data-pa-transfer><div class="section-mini-head"><strong>Transfer produs</strong><small>Alege magazinul sursa si destinatia.</small></div><div class="product-transfer-grid"><label><span>Sursa</span><select name="source_code"><option value="univera">Univera.ro</option><option value="alfamed">Alfamedclinic.ro</option></select></label><span class="transfer-arrow">→</span><label><span>Destinatie</span><select name="target_code"><option value="alfamed">Alfamedclinic.ro</option><option value="univera">Univera.ro</option></select></label></div></div>
    <div class="modal-section" data-pa-delete hidden><div class="section-mini-head"><strong>Sterge din magazin</strong><small>Alege magazinul din care produsul va fi eliminat.</small></div><div class="product-target-grid compact-target-grid"><label class="check-card"><input type="checkbox" name="targets[]" value="univera"><span>Univera.ro<small>Stergere definitiva</small></span></label><label class="check-card"><input type="checkbox" name="targets[]" value="alfamed"><span>Alfamedclinic.ro<small>Stergere definitiva</small></span></label></div></div>
    <div class="modal-actions"><button type="button" class="button-secondary" data-product-modal-close>Renunta</button><button class="primary">Aplica actiunea</button></div>
</form></div></div>
