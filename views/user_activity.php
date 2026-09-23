<?php use App\Core\View;
$categoryLabels=['orders'=>'Comenzi','products'=>'Produse','documents'=>'Documente','awb'=>'AWB','users'=>'Utilizatori','settings'=>'Setari','chat'=>'Chat','auth'=>'Autentificare','navigation'=>'Navigare','system'=>'Sistem'];
$categoryIcons=['orders'=>'▤','products'=>'▦','documents'=>'🧾','awb'=>'▣','users'=>'◎','settings'=>'⚙','chat'=>'💬','auth'=>'↪','navigation'=>'→','system'=>'•'];
$buildUrl=static function(array $overrides=[]) use($filters):string{$q=array_merge($filters,$overrides);foreach($q as $k=>$v)if($v===''||$v===0||$v===null)unset($q[$k]);return app_path('/users/activity').($q?'&'.http_build_query($q):'');};
?>
<div class="activity-admin-shell">
    <div class="page-head activity-admin-head">
        <div><a class="settings-back" href="<?=View::e(app_path('/users'))?>">← Utilizatori</a><div class="eyebrow">AUDIT ADMINISTRATOR</div><h1>Jurnal activitate</h1><p>Istoric centralizat al actiunilor efectuate de utilizatori in ALFAMED CENTRAL.</p></div>
        <div class="activity-total-box"><span>Inregistrari filtrate</span><strong><?=number_format((int)$total,0,',','.')?></strong></div>
    </div>

    <form class="panel activity-filter-panel" method="get" action="<?=View::e(app_front_controller())?>">
        <input type="hidden" name="route" value="/users/activity">
        <label class="activity-filter-search"><span>Cauta</span><input name="q" value="<?=View::e($filters['q']??'')?>" placeholder="Utilizator, actiune, comanda..."></label>
        <label><span>Utilizator</span><select name="user_id"><option value="0">Toti utilizatorii</option><?php foreach($activityUsers as $u):?><option value="<?=(int)$u['id']?>" <?=(int)($filters['user_id']??0)===(int)$u['id']?'selected':''?>><?=View::e($u['name'])?><?=!empty($u['deleted_at'])?' (sters)':''?></option><?php endforeach;?></select></label>
        <label><span>Categorie</span><select name="category"><option value="">Toate categoriile</option><?php foreach($categoryLabels as $key=>$label):?><option value="<?=View::e($key)?>" <?=($filters['category']??'')===$key?'selected':''?>><?=View::e($label)?></option><?php endforeach;?></select></label>
        <label><span>Perioada</span><select name="days"><?php foreach([1=>'Astazi',7=>'7 zile',30=>'30 zile',90=>'90 zile',365=>'1 an',0=>'Tot istoricul'] as $v=>$label):?><option value="<?=$v?>" <?=(int)($filters['days']??30)===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
        <label><span>Rezultat</span><select name="status"><option value="">Toate</option><option value="success" <?=($filters['status']??'')==='success'?'selected':''?>>Reusite</option><option value="failed" <?=($filters['status']??'')==='failed'?'selected':''?>>Esuate</option></select></label>
        <div class="activity-filter-actions"><button class="primary" type="submit">Filtreaza</button><a class="button-secondary" href="<?=View::e(app_path('/users/activity'))?>">Reseteaza</a></div>
    </form>

    <section class="panel activity-log-panel">
        <div class="activity-log-head"><div><h2>Activitate utilizatori</h2><p>Jurnalul nu afiseaza parole, token-uri sau continutul mesajelor din chat.</p></div><span class="admin-only-chip">Doar administrator</span></div>
        <?php if(!$rows):?><div class="empty-state">Nu exista activitate pentru filtrele selectate.</div><?php else:?><div class="activity-timeline">
        <?php foreach($rows as $row):?><?php $cat=(string)($row['category']??'system');$failed=(string)($row['status']??'success')==='failed';$ctx=json_decode((string)($row['context_json']??''),true)?:[]; ?>
            <article class="activity-row <?=$failed?'is-failed':''?>">
                <span class="activity-icon <?=View::e($cat)?>"><?=$categoryIcons[$cat]??'•'?></span>
                <div class="activity-row-main"><div class="activity-row-title"><strong><?=View::e($row['user_name'])?></strong><span><?=View::e($row['action_label'])?></span><?php if($failed):?><b class="activity-failed-chip">Esuat</b><?php endif;?></div><div class="activity-row-meta"><span><?=View::e($categoryLabels[$cat]??ucfirst($cat))?></span><span><?=View::e(date('d.m.Y H:i:s',strtotime((string)$row['created_at'])))?></span><?php if(!empty($row['ip_address'])):?><span>IP <?=View::e($row['ip_address'])?></span><?php endif;?></div></div>
                <div class="activity-row-side"><span><?=View::e(strtoupper((string)($row['method']??'')))?></span><?php if(!empty($row['entity_id'])):?><small><?=View::e((string)$row['entity_type'])?> #<?=View::e((string)$row['entity_id'])?></small><?php endif;?></div>
            </article>
        <?php endforeach;?>
        </div><?php endif;?>
        <?php if($pages>1):?><div class="pagination activity-pagination"><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):?><a class="<?=$p===$page?'active':''?>" href="<?=View::e($buildUrl(['page'=>$p]))?>"><?=$p?></a><?php endfor;?></div><?php endif;?>
    </section>
</div>
