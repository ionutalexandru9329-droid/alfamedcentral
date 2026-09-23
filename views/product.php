<?php
use App\Core\View;
use App\Core\Csrf;
$woo=array_values(array_filter($product['channels'],fn($c)=>$c['channel_type']==='woocommerce'));
$hasUnivera=(bool)array_filter($woo,fn($c)=>$c['channel_code']==='univera');
$hasAlfamed=(bool)array_filter($woo,fn($c)=>$c['channel_code']==='alfamed');
$ref=$reference;
$taxonomies=$taxonomies??['categories'=>[],'tags'=>[],'brands'=>[],'warnings'=>[]];
$terms=function(array $rows):string{return implode(', ',array_values(array_filter(array_map(fn($x)=>(string)($x['name']??''),$rows))));};
$images=function(array $rows):string{return implode("\n",array_values(array_filter(array_map(fn($x)=>(string)($x['src']??''),$rows))));};
?>
<div class="page-head product-editor-head">
    <div class="page-head-copy">
        <a class="back-link" href="<?=View::e(app_path('/products'))?>">← Inapoi la produse</a>
        <div class="eyebrow">CATALOG PRODUSE</div>
        <h1><?=View::e($product['name'])?></h1>
        <p>SKU master <b><?=View::e($product['sku'])?></b> <span class="dot-separator">•</span> <?=count($woo)?> <?=count($woo)===1?'magazin WooCommerce mapat':'magazine WooCommerce mapate'?></p>
    </div>
    <div class="page-head-badges"><span class="soft-badge success">Editor rapid</span><span class="soft-badge">Date locale</span></div>
</div>

<?php if($woo):?>
<div class="source-tabs product-source-tabs" aria-label="Sursa pentru editor">
    <span>Sursa pentru editor</span>
    <?php foreach($woo as $cp):?><a class="<?=($ref&&$ref['channel_code']===$cp['channel_code'])?'active':''?>" href="<?=View::e(app_path('/products/'.$product['id']))?>&source=<?=rawurlencode($cp['channel_code'])?>"><?=View::e($cp['channel_name'])?></a><?php endforeach;?>
</div>
<?php endif;?>

<?php if(!empty($taxonomies['warnings'])):?><div class="notice warning compact-notice">Listele locale de termeni nu au putut fi citite complet: <?=View::e(implode(' | ',array_slice((array)$taxonomies['warnings'],0,2)))?></div><?php endif;?>

<div class="product-editor-grid product-editor-workspace">
    <section class="panel product-editor-main">
        <div class="panel-title-row product-editor-title-row">
            <div><h2>Editare produs</h2><p>Modificarile sunt pregatite local si trimise doar magazinelor selectate la salvare.</p></div>
            <?php if($ref):?><span class="soft-badge"><?=View::e($ref['channel_name']??$ref['channel_code'])?></span><?php endif;?>
        </div>
        <?php if(!$ref):?>
            <div class="empty-state">Produsul nu are inca o mapare WooCommerce. Sincronizeaza produsele din pagina Produse.</div>
        <?php else:?>
        <form method="post" action="<?=View::e(app_path('/products/'.$product['id'].'/save'))?>" class="product-form product-form-polished" enctype="multipart/form-data">
            <?=Csrf::field()?><input type="hidden" name="reference_channel" value="<?=View::e($ref['channel_code'])?>">

            <section class="editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">01</div><div><h3>Informatii principale</h3><p>Titlu, identificatori, pret si stoc.</p></div></div>
                <div class="form-grid two editor-fields">
                    <label class="full-span">Titlu produs<input name="name" value="<?=View::e($ref['name']?:$product['name'])?>" required></label>
                    <label>SKU<input name="sku" value="<?=View::e($ref['external_sku']?:$product['sku'])?>" required></label>
                    <label>EAN / GTIN<input name="ean" value="<?=View::e($product['ean'])?>"></label>
                    <label>Status<select name="status"><?php foreach(['publish'=>'Publicat','draft'=>'Ciorna','pending'=>'In asteptare','private'=>'Privat'] as $k=>$v):?><option value="<?=$k?>" <?=($ref['remote_status']??'publish')===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
                    <label>Pret normal<input type="number" step="0.01" min="0" name="regular_price" value="<?=View::e($ref['price'])?>"></label>
                    <label>Pret promotional<input type="number" step="0.01" min="0" name="sale_price" value="<?=View::e($ref['sale_price'])?>"></label>
                    <label>Stoc<input type="number" step="1" name="stock_quantity" value="<?=View::e($ref['stock'])?>"></label>
                </div>
            </section>

            <section class="editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">02</div><div><h3>Descrieri</h3><p>Continutul principal si rezumatul afisat in magazin.</p></div></div>
                <div class="editor-section">
                    <?php $richName='description';$richLabel='Descriere';$richValue=(string)$ref['description'];require __DIR__.'/partials/rich_editor.php'; ?>
                    <?php $richName='short_description';$richLabel='Descriere scurta';$richValue=(string)$ref['short_description'];require __DIR__.'/partials/rich_editor.php'; ?>
                </div>
            </section>

            <section class="editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">03</div><div><h3>Organizare produs</h3><p>Alege termeni existenti sau scrie unii noi. Listele sunt incarcate instant din catalogul local sincronizat.</p></div></div>
                <div class="form-grid two taxonomy-grid editor-fields">
                    <?php $termName='categories';$termLabel='Categorii';$termValue=$terms($ref['categories']);$termOptions=$taxonomies['categories']??[];require __DIR__.'/partials/term_editor.php'; ?>
                    <?php $termName='tags';$termLabel='Taguri';$termValue=$terms($ref['tags']);$termOptions=$taxonomies['tags']??[];require __DIR__.'/partials/term_editor.php'; ?>
                    <?php $termName='brands';$termLabel='Branduri';$termValue=$terms($ref['brands']);$termOptions=$taxonomies['brands']??[];require __DIR__.'/partials/term_editor.php'; ?>
                </div>
            </section>

            <section class="editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">04</div><div><h3>Imagini</h3><p>Foloseste linkuri publice sau incarca fisiere noi.</p></div></div>
                <?php $imageValue=$images($ref['images']);$imageMode='url';require __DIR__.'/partials/image_editor.php'; ?>
            </section>

            <section class="editor-card seo-editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">05</div><div><h3>Rank Math SEO</h3><p>General, avansat si social, sincronizate cu magazinele WooCommerce selectate.</p></div></div>
                <?php $rankMath=(array)($ref['rank_math']??[]);$seoScore=$ref['seo_score']??($rankMath['score']??null);require __DIR__.'/partials/rank_math_editor.php'; ?>
            </section>

            <section class="editor-card target-editor-card">
                <div class="editor-card-head"><div class="editor-card-icon">06</div><div><h3>Magazine destinatie</h3><p>Alege magazinele pe care vrei sa aplici modificarile.</p></div></div>
                <div class="target-box product-target-box">
                    <?php foreach($woo as $cp):?><label class="check-card"><input type="checkbox" name="targets[]" value="<?=View::e($cp['channel_code'])?>" checked><span><?=View::e($cp['channel_name'])?><small>ID <?=View::e($cp['external_id'])?></small></span></label><?php endforeach;?>
                </div>
            </section>

            <div class="form-actions sticky-form-actions product-editor-savebar">
                <div class="product-store-links"><?php $storeOrder=['alfamed'=>1,'univera'=>2];$linkChannels=$woo;usort($linkChannels,fn($a,$b)=>($storeOrder[$a['channel_code']]??9)<=>($storeOrder[$b['channel_code']]??9));foreach($linkChannels as $linkCp):?><?php if(!empty($linkCp['permalink'])):?><a class="button-secondary" target="_blank" rel="noopener" href="<?=View::e($linkCp['permalink'])?>">Vezi pe <?=View::e($linkCp['channel_name'])?> ↗</a><?php endif;?><?php endforeach;?></div>
                <button class="primary product-save-button">Salveaza si sincronizeaza</button>
            </div>
        </form>
        <?php endif;?>
    </section>

    <aside class="product-editor-aside">
        <section class="panel product-preview-panel">
            <div class="aside-panel-head"><div><span>PREVIEW</span><h2>Imagine produs</h2></div></div>
            <?php $preview=$ref['images'][0]['src']??$product['image_url']??'';?>
            <?php if($preview):?><button type="button" class="product-preview-large js-image-preview" data-image="<?=View::e($preview)?>" data-caption="<?=View::e($product['name'])?>"><img src="<?=View::e($preview)?>" alt="<?=View::e($product['name'])?>"></button><?php else:?><div class="empty-state">Fara imagine.</div><?php endif;?>
        </section>
        <section class="panel product-seo-score-panel">
            <div class="aside-panel-head"><div><span>RANK MATH</span><h2>Scor SEO</h2></div></div>
            <div class="product-seo-channel-scores">
                <?php foreach($woo as $cp):?><?php $score=$cp['seo_score']??($cp['rank_math']['score']??null);$scoreClass=$score===null?'unknown':((int)$score>=80?'good':((int)$score>=50?'medium':'low'));?><div class="product-seo-channel-row"><span><?=View::e($cp['channel_name'])?></span><b class="seo-score-chip <?=$scoreClass?>"><?=$score===null?'--':(int)$score?></b></div><?php endforeach;?>
            </div>
            <small class="muted">Scorul este cel salvat de Rank Math pe fiecare magazin si se actualizeaza la sincronizare.</small>
        </section>
        <section class="panel">
            <div class="aside-panel-head"><div><span>DISTRIBUTIE</span><h2>Prezenta pe magazine</h2></div></div>
            <?php foreach($woo as $cp):?><div class="channel-summary"><div><b><?=View::e($cp['channel_name'])?></b><small>ID <?=View::e($cp['external_id'])?></small></div><div><b><?=number_format((float)$cp['price'],2,',',' ')?> <?=View::e($cp['currency'])?></b><small>stoc <?=View::e($cp['stock'])?></small></div></div><?php endforeach;?>
        </section>
        <section class="panel">
            <div class="aside-panel-head"><div><span>TRANSFER</span><h2>Copiaza produsul</h2></div></div>
            <p class="muted">Transfera continutul produsului catre celalalt magazin WooCommerce.</p>
            <?php if($hasUnivera&&!$hasAlfamed):?><form method="post" action="<?=View::e(app_path('/products/'.$product['id'].'/transfer'))?>" onsubmit="return confirm('Creezi acest produs pe Alfamedclinic.ro?')"><?=Csrf::field()?><input type="hidden" name="source_code" value="univera"><input type="hidden" name="target_code" value="alfamed"><button class="primary full-button">Univera.ro → Alfamedclinic.ro</button></form><?php elseif($hasAlfamed&&!$hasUnivera):?><form method="post" action="<?=View::e(app_path('/products/'.$product['id'].'/transfer'))?>" onsubmit="return confirm('Creezi acest produs pe Univera.ro?')"><?=Csrf::field()?><input type="hidden" name="source_code" value="alfamed"><input type="hidden" name="target_code" value="univera"><button class="primary full-button">Alfamedclinic.ro → Univera.ro</button></form><?php elseif($hasAlfamed&&$hasUnivera):?><div class="flash success inline-flash">Produsul este deja prezent pe ambele magazine.</div><?php else:?><div class="muted">Este necesara cel putin o mapare WooCommerce.</div><?php endif;?>
        </section>
    </aside>
</div>
