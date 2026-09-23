<?php
use App\Core\View;
use App\Core\Csrf;
$taxonomies=$taxonomies??['categories'=>[],'tags'=>[],'brands'=>[],'warnings'=>[]];
?>
<div class="page-head product-editor-head product-new-head">
    <div class="page-head-copy">
        <a class="back-link" href="<?=View::e(app_path('/products'))?>">← Inapoi la produse</a>
        <div class="eyebrow">CATALOG PRODUSE</div>
        <h1>Adauga produs nou</h1>
        <p>Creeaza produsul o singura data si alege magazinul sau magazinele pe care vrei sa il publici.</p>
    </div>
    <div class="page-head-badges"><span class="soft-badge success">Editor rapid</span><span class="soft-badge">Responsive</span></div>
</div>
<?php if(!empty($taxonomies['warnings'])):?><div class="notice warning compact-notice">Listele locale nu au putut fi citite complet: <?=View::e(implode(' | ',array_slice((array)$taxonomies['warnings'],0,2)))?></div><?php endif;?>

<section class="panel product-create-panel product-editor-main">
    <div class="panel-title-row product-editor-title-row"><div><h2>Produs nou</h2><p>Editorul foloseste catalogul local sincronizat, astfel incat pagina se deschide fara sa astepte WooCommerce.</p></div></div>
    <form method="post" action="<?=View::e(app_path('/products/new'))?>" class="product-form product-form-polished" enctype="multipart/form-data"><?=Csrf::field()?>
        <section class="editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">01</div><div><h3>Informatii principale</h3><p>Datele de baza ale produsului.</p></div></div>
            <div class="form-grid two editor-fields">
                <label class="full-span">Titlu produs<input name="name" required autofocus></label>
                <label>SKU<input name="sku" value="<?=View::e($nextSku??'')?>" required><small>Completat automat; il poti modifica.</small></label>
                <label>EAN / GTIN<input name="ean"></label>
                <label>Status<select name="status"><option value="publish">Publicat</option><option value="draft">Ciorna</option><option value="pending">In asteptare</option><option value="private">Privat</option></select></label>
                <label>Pret normal<input type="number" step="0.01" min="0" name="regular_price"></label>
                <label>Pret promotional<input type="number" step="0.01" min="0" name="sale_price"></label>
                <label>Stoc<input type="number" step="1" name="stock_quantity" value="0"></label>
            </div>
        </section>

        <section class="editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">02</div><div><h3>Descrieri</h3><p>Descriere completa si rezumat pentru cardul produsului.</p></div></div>
            <div class="editor-section">
                <?php $richName='description';$richLabel='Descriere';$richValue='';require __DIR__.'/partials/rich_editor.php'; ?>
                <?php $richName='short_description';$richLabel='Descriere scurta';$richValue='';require __DIR__.'/partials/rich_editor.php'; ?>
            </div>
        </section>

        <section class="editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">03</div><div><h3>Organizare produs</h3><p>Categoriile, tagurile si brandurile sunt sugerate instant din baza locala. Termenii noi se creeaza la salvare.</p></div></div>
            <div class="form-grid two taxonomy-grid editor-fields">
                <?php $termName='categories';$termLabel='Categorii';$termValue='';$termOptions=$taxonomies['categories']??[];require __DIR__.'/partials/term_editor.php'; ?>
                <?php $termName='tags';$termLabel='Taguri';$termValue='';$termOptions=$taxonomies['tags']??[];require __DIR__.'/partials/term_editor.php'; ?>
                <?php $termName='brands';$termLabel='Branduri';$termValue='';$termOptions=$taxonomies['brands']??[];require __DIR__.'/partials/term_editor.php'; ?>
            </div>
        </section>

        <section class="editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">04</div><div><h3>Imagini</h3><p>Adauga imagini prin URL sau incarcare directa.</p></div></div>
            <?php $imageValue='';$imageMode='url';require __DIR__.'/partials/image_editor.php'; ?>
        </section>

        <section class="editor-card seo-editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">05</div><div><h3>Rank Math SEO</h3><p>Configureaza metadatele Rank Math o singura data si trimite-le magazinelor selectate.</p></div></div>
            <?php $rankMath=[];$seoScore=null;require __DIR__.'/partials/rank_math_editor.php'; ?>
        </section>

        <section class="editor-card target-editor-card">
            <div class="editor-card-head"><div class="editor-card-icon">06</div><div><h3>Magazine destinatie</h3><p>Selecteaza unde va fi creat produsul.</p></div></div>
            <div class="target-box product-target-box">
                <label class="check-card"><input type="checkbox" name="targets[]" value="univera" checked><span>Univera.ro<small>WooCommerce</small></span></label>
                <label class="check-card"><input type="checkbox" name="targets[]" value="alfamed"><span>Alfamedclinic.ro<small>WooCommerce</small></span></label>
            </div>
        </section>

        <div class="form-actions sticky-form-actions product-editor-savebar"><a class="button-secondary" href="<?=View::e(app_path('/products'))?>">Renunta</a><button class="primary product-save-button">Creeaza produsul</button></div>
    </form>
</section>
