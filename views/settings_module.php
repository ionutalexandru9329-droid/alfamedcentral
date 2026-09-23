<?php use App\Core\Csrf; use App\Core\View;
$settingsModuleSlug=(string)($moduleSlug??'');
if($settingsModuleSlug===''||!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/',$settingsModuleSlug)) throw new RuntimeException('Slug modul invalid in pagina de setari.');
$name=(string)($sections[0]['_module_name']??$settingsModuleSlug);
$icons=['invoices-documents'=>'🧾','couriers'=>'🚚','awb-scan'=>'▣','new-order-popup'=>'🔔','team-chat'=>'💬','ai-product-import'=>'✦'];
$idify=static function(string $value):string{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9]+/','-',$value)??'';
    return trim($value,'-')?:'sectiune';
};
$moduleSectionBlocks=[];$fieldCount=0;$checkboxCount=0;$subsectionCount=0;
foreach(array_values($sections) as $sectionIndex=>$section){
    $rawFields=array_values(array_filter((array)($section['fields']??[]),static fn($field)=>is_array($field)));
    $groups=[];
    $current=['title'=>'Configurare','description'=>'','fields'=>[],'is_default'=>true];
    foreach($rawFields as $field){
        $type=(string)($field['type']??'text');
        if($type==='heading'){
            if($current['fields'])$groups[]=$current;
            $current=[
                'title'=>(string)($field['label']??'Sectiune'),
                'description'=>(string)($field['help']??''),
                'fields'=>[],
                'is_default'=>false,
            ];
            continue;
        }
        $current['fields'][]=$field;
        $fieldCount++;
        if($type==='checkbox')$checkboxCount++;
    }
    if($current['fields'])$groups[]=$current;
    if(!$groups){
        $groups[]=['title'=>'Configurare','description'=>'','fields'=>[],'is_default'=>true];
    }
    $subsectionCount+=count($groups);
    $visibleFields=0;foreach($groups as $group)$visibleFields+=count((array)$group['fields']);
    $title=(string)($section['title']??$name);
    $moduleSectionBlocks[]=[
        'id'=>'module-section-'.$sectionIndex.'-'.$idify($title),
        'title'=>$title,
        'description'=>(string)($section['description']??''),
        'groups'=>$groups,
        'field_count'=>$visibleFields,
        'group_count'=>count($groups),
    ];
}
?>
<div class="settings-detail-shell settings-module-shell-pro">
    <div class="settings-detail-hero module-settings-hero">
        <div class="settings-detail-title">
            <a class="settings-back" href="<?=View::e(app_path('/settings'))?>&tab=modules">← Module</a>
            <div class="settings-detail-title-row"><span class="settings-detail-icon"><?=$icons[$settingsModuleSlug]??'▦'?></span><div><div class="eyebrow">SETARI MODUL</div><h1><?=View::e($name)?></h1><p>Interfata reorganizata pentru configurare mai clara, mai compacta si mai usor de parcurs pe toate modulele.</p></div></div>
        </div>
        <div class="module-hero-side"><span class="integration-state large on">MODUL ACTIV</span><div class="module-settings-summary"><?php if($fieldCount>0):?><span><b><?=number_format($fieldCount,0,',','.')?></b> campuri</span><?php endif;?><span><b><?=number_format(count($moduleSectionBlocks),0,',','.')?></b> sectiuni</span><?php if($checkboxCount>0):?><span><b><?=number_format($checkboxCount,0,',','.')?></b> optiuni rapide</span><?php endif;?></div></div>
    </div>

    <div class="module-settings-layout">
        <aside class="module-settings-sidebar">
            <section class="panel module-sidebar-card">
                <div class="module-sidebar-card-head"><span class="settings-section-icon"><?=$icons[$settingsModuleSlug]??'▦'?></span><div><h2>Rezumat configurare</h2><p>Toate modulele folosesc acum aceeasi structura vizuala, cu sectiuni si campuri mai ordonate.</p></div></div>
                <div class="module-sidebar-stats">
                    <div><span>Sectiuni</span><strong><?=number_format(count($moduleSectionBlocks),0,',','.')?></strong></div>
                    <div><span>Subsectiuni</span><strong><?=number_format($subsectionCount,0,',','.')?></strong></div>
                    <div><span>Campuri totale</span><strong><?=number_format($fieldCount,0,',','.')?></strong></div>
                    <div><span>Switch-uri</span><strong><?=number_format($checkboxCount,0,',','.')?></strong></div>
                </div>
            </section>
            <nav class="panel module-sidebar-card module-sidebar-nav" aria-label="Navigare rapida sectiuni">
                <div class="module-sidebar-nav-head"><h2>Sectiuni</h2><p>Sari rapid la zona pe care vrei sa o modifici.</p></div>
                <div class="module-sidebar-links">
                    <?php foreach($moduleSectionBlocks as $index=>$block):?>
                    <a href="#<?=View::e($block['id'])?>"><span><?=str_pad((string)($index+1),2,'0',STR_PAD_LEFT)?></span><b><?=View::e($block['title'])?></b><small><?=number_format((int)$block['field_count'],0,',','.')?> campuri</small></a>
                    <?php endforeach;?>
                </div>
            </nav>
        </aside>

        <div class="module-settings-main">
            <form method="post" action="<?=View::e(app_path('/settings/modules/'.$settingsModuleSlug))?>" class="settings-module-form">
                <?=Csrf::field()?>
                <input type="hidden" name="_module_slug" value="<?=View::e($settingsModuleSlug)?>">
                <?php foreach($moduleSectionBlocks as $sectionIndex=>$sectionBlock):?>
                <section id="<?=View::e($sectionBlock['id'])?>" class="panel settings-pro-section settings-detail-card module-settings-section-pro">
                    <div class="settings-pro-section-head module-section-head-pro">
                        <span class="settings-section-icon"><?=$icons[$settingsModuleSlug]??'▦'?></span>
                        <div class="module-section-copy">
                            <div class="module-section-copy-top"><h2><?=View::e($sectionBlock['title'])?></h2><div class="module-section-meta"><span><?=number_format((int)$sectionBlock['field_count'],0,',','.')?> campuri</span><span><?=number_format((int)$sectionBlock['group_count'],0,',','.')?> blocuri</span></div></div>
                            <?php if(trim((string)$sectionBlock['description'])!==''):?><p><?=View::e($sectionBlock['description'])?></p><?php else:?><p>Campurile sunt grupate logic pentru a fi mai usor de parcurs si de salvat.</p><?php endif;?>
                        </div>
                    </div>
                    <div class="module-settings-groups">
                        <?php foreach((array)$sectionBlock['groups'] as $groupIndex=>$group):?>
                        <div class="module-settings-group <?=!empty($group['is_default'])&&count((array)$sectionBlock['groups'])===1?'is-single':''?>">
                            <?php if(!( !empty($group['is_default']) && count((array)$sectionBlock['groups'])===1)):?>
                            <div class="module-settings-group-head">
                                <div>
                                    <h3><?=View::e($group['title']??'Configurare')?></h3>
                                    <?php if(trim((string)($group['description']??''))!==''):?><p><?=View::e((string)$group['description'])?></p><?php else:?><p><?=count((array)($group['fields']??[]))?> campuri in acest grup.</p><?php endif;?>
                                </div>
                                <span class="module-group-count"><?=number_format(count((array)($group['fields']??[])),0,',','.')?> campuri</span>
                            </div>
                            <?php endif;?>
                            <div class="settings-grid two module-fields-pro module-settings-fields-grid">
                                <?php foreach((array)($group['fields']??[]) as $field):?>
                                <?php $key=(string)($field['key']??'');$type=(string)($field['type']??'text');$value=(string)$settings->get('module.'.$settingsModuleSlug.'.'.$key,(string)($field['default']??''));$help=(string)($field['help']??'');?>
                                <?php if($type==='checkbox'):?>
                                    <label class="settings-check-card module-setting-card module-setting-card--checkbox"><input type="checkbox" name="module[<?=View::e($key)?>]" <?=$settings->bool('module.'.$settingsModuleSlug.'.'.$key,filter_var($field['default']??false,FILTER_VALIDATE_BOOL))?'checked':''?>><span class="settings-check-layout"><span class="settings-check-copy"><b><?=View::e($field['label']??$key)?></b><?php if($help!==''):?><small><?=View::e($help)?></small><?php else:?><small>Activeaza sau dezactiveaza aceasta optiune.</small><?php endif;?></span><span class="settings-check-switch" aria-hidden="true"><i></i></span></span></label>
                                <?php elseif($type==='select'):?>
                                    <label class="module-setting-card module-setting-card--select"><span class="module-field-label"><?=View::e($field['label']??$key)?></span><select name="module[<?=View::e($key)?>]"><?php foreach((array)($field['options']??[]) as $opt=>$label):?><option value="<?=View::e($opt)?>" <?=$value===(string)$opt?'selected':''?>><?=View::e($label)?></option><?php endforeach;?></select><?php if($help!==''):?><small class="module-field-help"><?=View::e($help)?></small><?php endif;?></label>
                                <?php elseif($type==='textarea'):?>
                                    <label class="module-setting-card module-setting-card--textarea"><span class="module-field-label"><?=View::e($field['label']??$key)?></span><textarea name="module[<?=View::e($key)?>]" rows="6"><?=View::e($value)?></textarea><?php if($help!==''):?><small class="module-field-help"><?=View::e($help)?></small><?php endif;?></label>
                                <?php elseif($type==='emag_locality'):?>
                                    <label class="module-setting-card module-setting-card--emag emag-locality-field" data-channel="<?=View::e((string)($field['channel']??''))?>"><span class="module-field-label"><?=View::e($field['label']??$key)?></span><div class="emag-locality-search-row"><input type="text" class="emag-locality-query" placeholder="Ex: Sendreni, Galati"><button type="button" class="button-secondary emag-locality-search">Cauta in eMAG</button></div><input type="hidden" class="emag-locality-id" name="module[<?=View::e($key)?>]" value="<?=View::e($value)?>"><div class="emag-locality-current"><?=trim($value)!==''?'Locality ID salvat: <b>'.View::e($value).'</b>':'Nicio localitate selectata.'?></div><div class="emag-locality-results"></div><?php if($help!==''):?><small class="module-field-help"><?=View::e($help)?></small><?php endif;?></label>
                                <?php else:?>
                                    <label class="module-setting-card module-setting-card--input"><span class="module-field-label"><?=View::e($field['label']??$key)?></span><input type="<?=View::e($type)?>" name="module[<?=View::e($key)?>]" value="<?=$type==='password'?'':View::e($value)?>" <?=isset($field['min'])?'min="'.View::e($field['min']).'"':''?> <?=isset($field['max'])?'max="'.View::e($field['max']).'"':''?> <?=isset($field['step'])?'step="'.View::e($field['step']).'"':''?> <?=$type==='password'?'placeholder="Lasa gol pentru a pastra valoarea existenta"':''?>><?php if($help!==''):?><small class="module-field-help"><?=View::e($help)?></small><?php elseif($type==='password'):?><small class="module-field-help">Lasa gol pentru a pastra valoarea deja salvata.</small><?php endif;?></label>
                                <?php endif;?>
                                <?php endforeach;?>
                            </div>
                        </div>
                        <?php endforeach;?>
                    </div>
                </section>
                <?php endforeach;?>
                <div class="settings-sticky-actions module-sticky-actions"><a class="button-secondary" href="<?=View::e(app_path('/settings'))?>&tab=modules">Inapoi la module</a><button class="primary" type="submit">Salveaza setarile modulului</button></div>
            </form>

<?php if($settingsModuleSlug==='invoices-documents'):?>
<form method="post" action="<?=View::e(app_path('/settings/modules/invoices-documents/test'))?>" class="settings-secondary-test"><?=Csrf::field()?><button type="submit" class="button-secondary">Testeaza conexiunea Oblio</button><small>Verifica autentificarea, CIF-ul, seria facturii si gestiunea fara sa emita documente.</small></form>
<?php $mappingService=new \Modules\InvoicesDocuments\InvoiceService();$oblioMappings=$mappingService->mappings();$oblioCatalogue=$mappingService->catalogueStatus();?>
<section class="panel settings-pro-section oblio-catalogue-panel">
  <div class="settings-pro-section-head oblio-catalogue-head"><span class="settings-section-icon">↻</span><div><h2>Nomenclator produse Oblio</h2><p>Oblio este sursa pentru codurile fiscale ale produselor. La fiecare sincronizare, ALFAMED CENTRAL actualizeaza catalogul local si reconstruieste echivalarile sigure folosind codul Oblio, coduri alternative/EAN, legatura cu produsul central si denumirea exacta unica, fara sa suprascrie maparile introduse manual.</p></div></div>
  <div class="oblio-catalogue-stats">
    <div><span>Produse active</span><strong><?=number_format((int)($oblioCatalogue['active']??0),0,',','.')?></strong></div>
    <div><span>Echivalari auto</span><strong><?=number_format((int)($oblioCatalogue['auto_mappings']??0),0,',','.')?></strong></div>
    <div><span>Ultima sincronizare</span><strong><?=trim((string)($oblioCatalogue['last_sync_at']??''))!==''?View::e((string)$oblioCatalogue['last_sync_at']):'Nesincronizat'?></strong></div>
    <div><span>Sincronizare automata</span><strong><?=!empty($oblioCatalogue['auto_sync'])?'La '.(int)($oblioCatalogue['sync_hours']??12).' ore':'Dezactivata'?></strong></div>
  </div>
  <?php if(trim((string)($oblioCatalogue['last_error']??''))!==''):?><div class="settings-info-strip oblio-catalogue-error"><span>!</span><p><b>Ultima eroare:</b> <?=View::e((string)$oblioCatalogue['last_error'])?></p></div><?php endif;?>
  <?php if((int)($oblioCatalogue['last_auto_source_products']??0)>0):?><div class="settings-info-strip compact oblio-catalogue-diagnostic"><span>i</span><p><b>Ultima analiza:</b> <?=number_format((int)$oblioCatalogue['last_auto_source_products'],0,',','.')?> produse din canale · <?=number_format((int)($oblioCatalogue['last_auto_direct_code']??0),0,',','.')?> cod direct · <?=number_format((int)($oblioCatalogue['last_auto_bridge']??0),0,',','.')?> prin produs central · <?=number_format((int)($oblioCatalogue['last_auto_alternate']??0),0,',','.')?> cod alternativ/EAN · <?=number_format((int)($oblioCatalogue['last_auto_exact_name']??0),0,',','.')?> denumire exacta · <?=number_format((int)($oblioCatalogue['last_auto_ambiguous']??0),0,',','.')?> ambigue · <?=number_format((int)($oblioCatalogue['last_auto_unmatched']??0),0,',','.')?> fara potrivire.</p></div><?php endif;?>
  <div class="oblio-catalogue-actions"><form method="post" action="<?=View::e(app_path('/settings/modules/invoices-documents/catalogue-sync'))?>"><?=Csrf::field()?><button type="submit" class="primary">↻ Sincronizeaza acum din Oblio</button></form><small>Sincronizarea citeste nomenclatorul in pagini de maximum 250 produse, actualizeaza cache-ul local si reconstruieste echivalarile automate sigure. Produsele din Oblio nu sunt modificate, iar echivalarile manuale au intotdeauna prioritate.</small></div>
</section>

<details class="panel settings-pro-section oblio-mapping-panel oblio-mapping-fallback">
  <summary><span class="settings-section-icon">⇄</span><span><b>Echivalari produse</b><small>Maparile Auto Oblio sunt reconstruite la sincronizare. Cele Manuale sunt fallback si nu sunt suprascrise niciodata automat.</small></span><span class="oblio-mapping-count"><?=count($oblioMappings)?> total · <?=number_format((int)($oblioCatalogue['auto_mappings']??0),0,',','.')?> auto</span></summary>
  <div class="oblio-mapping-body">
  <form method="post" action="<?=View::e(app_path('/settings/modules/invoices-documents/product-mapping'))?>" class="oblio-mapping-form"><?=Csrf::field()?>
    <label><span>Canal</span><select name="channel_code"><option value="emag_ro">eMAG Romania</option><option value="emag_bg">eMAG Bulgaria</option><option value="univera">Univera.ro</option><option value="alfamed">Alfamedclinic.ro</option></select></label>
    <label><span>Cod din comanda</span><input name="source_code" placeholder="Ex: 323" required></label>
    <label class="oblio-product-search-field"><span>Cauta produs in Oblio</span><div class="oblio-product-search-row"><input type="text" data-oblio-product-query placeholder="Cod sau denumire"><button type="button" class="button-secondary" data-oblio-product-search>Cauta</button></div><div class="oblio-product-search-results" data-oblio-product-results></div></label>
    <label><span>Cod Oblio</span><input name="oblio_code" data-oblio-code placeholder="Ex: 121" required></label>
    <input type="hidden" name="oblio_name" data-oblio-name>
    <div class="actions"><button class="primary" type="submit">Salveaza echivalarea</button></div>
  </form>
  <?php if($oblioMappings):?><div class="table-wrap oblio-mapping-table"><table><thead><tr><th>Canal</th><th>Cod canal</th><th>Cod Oblio</th><th>Produs Oblio</th><th>Sursa</th><th>Actiune</th></tr></thead><tbody><?php foreach($oblioMappings as $map):?><?php $isManual=((string)($map['mapping_source']??'manual')==='manual');$reason=(string)($map['match_reason']??'');?><tr><td><?=View::e(['emag_ro'=>'eMAG Romania','emag_bg'=>'eMAG Bulgaria','univera'=>'Univera.ro','alfamed'=>'Alfamedclinic.ro'][$map['channel_code']]??$map['channel_code'])?></td><td><b><?=View::e($map['source_code'])?></b></td><td><b><?=View::e($map['oblio_code'])?></b></td><td><?=View::e($map['oblio_name']??'')?></td><td><span class="oblio-map-source <?=$isManual?'manual':'auto'?>"><?=$isManual?'Manual':'Auto Oblio'?></span><?php if(!$isManual&&$reason!==''):?><small class="oblio-map-reason"><?=View::e(str_replace(['sync_','oblio_','invoice_learned_'],['sincronizare · ','','factura · '],$reason))?></small><?php endif;?></td><td><form method="post" action="<?=View::e(app_path('/settings/modules/invoices-documents/product-mapping/'.$map['id'].'/delete'))?>" onsubmit="return confirm('Stergi aceasta echivalare?<?=!$isManual?' Daca provine din Oblio, poate reaparea la urmatoarea sincronizare.':''?>')"><?=Csrf::field()?><button class="small danger-outline">Sterge</button></form></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="muted">Nu exista echivalari salvate. Dupa prima sincronizare Oblio, cele care pot fi determinate sigur vor aparea automat aici.</p><?php endif;?>
  </div>
</details>
<script>
(function(){
  var btn=document.querySelector('[data-oblio-product-search]'),q=document.querySelector('[data-oblio-product-query]'),box=document.querySelector('[data-oblio-product-results]'),code=document.querySelector('[data-oblio-code]'),name=document.querySelector('[data-oblio-name]');if(!btn||!q||!box||!code)return;
  var esc=function(s){return String(s||'').replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});};
  btn.addEventListener('click',async function(){var term=q.value.trim();if(!term){box.textContent='Scrie un cod sau o denumire.';return;}btn.disabled=true;box.textContent='Caut in nomenclatorul Oblio...';try{var d=new URLSearchParams();d.set('_csrf','<?=View::e(\App\Core\Csrf::token())?>');d.set('q',term);var r=await fetch('<?=View::e(app_path('/settings/modules/invoices-documents/product-search'))?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:d.toString(),credentials:'same-origin'});var j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Cautarea a esuat.');box.innerHTML='';(j.results||[]).forEach(function(item){var b=document.createElement('button');b.type='button';b.className='oblio-product-result';b.innerHTML='<b>'+esc(item.code||'—')+'</b><span>'+esc(item.name||'')+'</span>';b.addEventListener('click',function(){code.value=item.code||'';if(name)name.value=item.name||'';q.value=(item.code||'')+' · '+(item.name||'');box.innerHTML='';});box.appendChild(b);});if(!(j.results||[]).length)box.textContent='Nu am gasit produse. Incearca un cod exact sau o parte din denumire.';}catch(e){box.textContent=e.message||'Cautarea a esuat.';}finally{btn.disabled=false;}});
})();
</script>
<?php endif;?>
<?php if($settingsModuleSlug==='couriers'):?>
<script>
(function(){
  document.querySelectorAll('.emag-locality-field').forEach(function(box){
    var button=box.querySelector('.emag-locality-search'), query=box.querySelector('.emag-locality-query'), results=box.querySelector('.emag-locality-results'), hidden=box.querySelector('.emag-locality-id'), current=box.querySelector('.emag-locality-current');
    if(!button||!query||!results||!hidden)return;
    button.addEventListener('click',async function(){
      var q=query.value.trim(); if(q.length<2){results.textContent='Scrie numele localitatii.';return;}
      button.disabled=true;results.textContent='Caut...';
      try{
        var data=new URLSearchParams();data.set('_csrf','<?=View::e(\App\Core\Csrf::token())?>');data.set('channel',box.dataset.channel||'');data.set('q',q);
        var r=await fetch('<?=View::e(app_path('/settings/modules/couriers/locality-search'))?>',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:data.toString(),credentials:'same-origin'});var j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Cautarea a esuat.');
        results.innerHTML='';if(!j.results||!j.results.length){results.textContent='Nu am gasit localitati. Incearca numele exact sau forma Localitate, Judet.';return;}
        j.results.forEach(function(item){var b=document.createElement('button');b.type='button';b.className='emag-locality-result';b.textContent=item.name+(item.county?' · '+item.county:'')+' · ID '+item.id;b.addEventListener('click',function(){hidden.value=item.id;current.innerHTML='Selectat: <b>'+escapeHtml(item.name)+(item.county?' · '+escapeHtml(item.county):'')+'</b> · Locality ID <b>'+item.id+'</b>';results.innerHTML='';});results.appendChild(b);});
      }catch(e){results.textContent=e.message||'Cautarea a esuat.';}finally{button.disabled=false;}
    });
  });
  function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
})();
</script>
<?php endif;?>

        </div>
    </div>
</div>
