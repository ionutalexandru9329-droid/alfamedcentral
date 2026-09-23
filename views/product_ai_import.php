<?php use App\Core\View; use App\Core\Csrf; ?>
<div class="page-head"><div><h1>Auto Import AI produse</h1><p>Lipeste URL-uri publice de produs sau categorie. Modulul citeste datele structurate si pregateste produse in status <b>Ciorna</b>.</p></div><a class="button-secondary" href="<?=View::e(app_path('/products'))?>">← Produse</a></div>
<section class="panel smart-import-panel">
<form method="post" action="<?=View::e(app_path('/products/ai-import/preview'))?>"><?=Csrf::field()?>
<label>URL-uri sursa<textarea name="urls" rows="6" required placeholder="https://magazin.ro/produs/...&#10;https://alt-magazin.ro/produs/..."><?=View::e((string)($_POST['urls']??''))?></textarea><small>Cate un URL pe linie. Sunt acceptate numai pagini publice HTTP/HTTPS. Site-urile care blocheaza boții sau incarca datele exclusiv prin JavaScript pot necesita completare manuala.</small></label>
<div class="settings-actions"><button class="primary" type="submit">✨ Analizeaza URL-urile</button></div>
</form>
</section>
<?php if(is_array($preview)): ?>
<section class="panel"><div class="section-heading"><div><h2>Previzualizare</h2><p>Verifica informatiile gasite. La import, toate produsele vor fi create ca <b>Draft/Ciorna</b>.</p></div></div>
<?php if(!empty($preview['errors'])):?><div class="notice warning"><strong>Unele URL-uri nu au putut fi procesate:</strong><br><?=View::e(implode(' | ',array_slice((array)$preview['errors'],0,5)))?></div><?php endif;?>
<form method="post" action="<?=View::e(app_path('/products/ai-import/create'))?>"><?=Csrf::field()?><input type="hidden" name="token" value="<?=View::e($token)?>">
<div class="smart-import-targets"><strong>Trimite ca ciorna pe:</strong><label><input type="checkbox" name="targets[]" value="univera" <?=$settings->bool('module.ai-product-import.default_univera',true)?'checked':''?>> Univera.ro</label><label><input type="checkbox" name="targets[]" value="alfamed" <?=$settings->bool('module.ai-product-import.default_alfamed',true)?'checked':''?>> Alfamedclinic.ro</label></div>
<div class="table-wrap"><table><thead><tr><th><input type="checkbox" data-select-all="ai-products" checked></th><th>Imagine</th><th>Produs</th><th>SKU / EAN</th><th>Pret</th><th>Sursa</th></tr></thead><tbody><?php foreach((array)$preview['products'] as $i=>$p):?><tr><td><input type="checkbox" name="selected[]" value="<?=(int)$i?>" data-select-item="ai-products" checked></td><td><?php if(!empty($p['images'][0])):?><img class="product-thumb" src="<?=View::e($p['images'][0])?>" alt="" loading="lazy"><?php else:?><span class="muted">—</span><?php endif;?></td><td><b><?=View::e($p['name']??'')?></b><br><small><?=View::e($p['brand']??'')?></small><br><small><?=View::e($p['short_description']??'')?></small></td><td><?=View::e($p['sku']??'')?><?php if(!empty($p['ean'])):?><br><small>EAN <?=View::e($p['ean'])?></small><?php endif;?></td><td><?=View::e($p['price']??'')?> <?=View::e($p['currency']??'')?></td><td><a href="<?=View::e($p['source_url']??'#')?>" target="_blank" rel="noopener noreferrer">Deschide sursa ↗</a></td></tr><?php endforeach;?></tbody></table></div>
<p class="muted"><small>Important: foloseste importul doar pentru continut si imagini pe care ai dreptul sa le reutilizezi. Operatorul ramane responsabil de verificarea titlului, pretului, descrierii, imaginilor si SEO inainte de publicare.</small></p>
<div class="settings-actions"><button class="primary" type="submit">Creeaza ciornele selectate</button></div>
</form></section>
<?php endif; ?>
