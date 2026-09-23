<?php use App\Core\View; use App\Core\Csrf;
$moneyText=static function(array $values):string{$parts=[];foreach($values as $currency=>$value)$parts[]=number_format((float)$value,2,',',' ').' '.$currency;return $parts?implode(' · ',$parts):'0 RON';};
$formatChange=static function(?float $value):array{if($value===null)return ['text'=>'Nou','class'=>'neutral','arrow'=>''];if(abs($value)<0.05)return ['text'=>'0,0%','class'=>'neutral','arrow'=>''];return ['text'=>number_format($value,1,',',' ').'%','class'=>$value>0?'up':'down','arrow'=>$value>0?'↑':'↓'];};
$monthChange=$formatChange($report['comparison_month']['change_percent']);$yearChange=$formatChange($report['comparison_year']['change_percent']);
$activeFilters=(($channel??'')!==''?1:0)+(($days??30)!==(int)($defaultDays??30)?1:0);$lastUpdated=($report['last_sync_at']??'')?date('d.m.Y H:i',strtotime($report['last_sync_at'])):(($report['last_updated_at']??'')?date('d.m.Y H:i',strtotime($report['last_updated_at'])):'—');
$daily=(array)$report['daily'];$dailyMax=1;foreach($daily as $d)$dailyMax=max($dailyMax,(int)$d['orders']);$channelMax=1;foreach((array)$report['channels'] as $c)$channelMax=max($channelMax,(int)$c['orders']);
?>
<div class="statistics-shell statistics-pro-shell module-page-shell">
    <div class="page-head statistics-pro-head">
        <div><div class="eyebrow">BUSINESS INTELLIGENCE</div><h1>Statistici</h1><p><?=View::e(date('d.m.Y',strtotime($report['start'])))?> – <?=View::e(date('d.m.Y',strtotime($report['end'])))?> · indicatori calculati din comenzile sincronizate.</p></div>
        <div class="statistics-pro-head-actions"><div class="statistics-updated"><span>Ultima actualizare</span><strong><?=View::e($lastUpdated)?></strong></div><form method="post" action="<?=View::e(app_path('/statistics/sync'))?>"><?=Csrf::field()?><input type="hidden" name="days" value="<?=(int)$days?>"><input type="hidden" name="channel" value="<?=View::e($channel)?>"><button class="button-secondary" type="submit">↻ Actualizeaza</button></form></div>
    </div>

    <form class="panel statistics-pro-filters" method="get" action="<?=View::e(app_front_controller())?>">
        <input type="hidden" name="route" value="/statistics">
        <div class="statistics-filter-copy"><span class="settings-section-icon">⌁</span><div><strong>Filtre</strong><small>Alege perioada si canalul analizat.</small></div></div>
        <label><span>Perioada</span><select name="days" onchange="this.form.submit()"><?php foreach([7=>'7 zile',8=>'8 zile',14=>'14 zile',30=>'30 zile',90=>'90 zile',365=>'365 zile'] as $v=>$label):?><option value="<?=$v?>" <?=$days===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
        <label><span>Canal</span><select name="channel" onchange="this.form.submit()"><option value="">Toate canalele</option><?php foreach($channels as $c):?><option value="<?=View::e($c['code'])?>" <?=$channel===$c['code']?'selected':''?>><?=View::e($c['name'])?></option><?php endforeach;?></select></label>
        <?php if($activeFilters>0):?><a class="button-secondary statistics-reset" href="<?=View::e(app_path('/statistics'))?>">Reseteaza</a><?php endif;?>
    </form>

    <div class="statistics-pro-kpis">
        <article><span>Comenzi</span><strong><?=(int)$report['total_orders']?></strong><small><?=number_format((float)$report['avg_orders_per_day'],1,',',' ')?> / zi</small></article>
        <article><span>Venituri</span><strong class="money"><?=View::e($moneyText($report['revenue']))?></strong><small><?=$report['exclude_cancelled']?'fara anulate / returnate':'include toate'?></small></article>
        <article><span>In lucru</span><strong><?=(int)$report['active']?></strong><small>de procesat</small></article>
        <article><span>Finalizate</span><strong><?=(int)$report['completed']?></strong><small>comenzi inchise</small></article>
        <article><span>Anulate / returnate</span><strong><?=number_format((float)$report['cancellation_rate'],1,',',' ')?>%</strong><small><?=(int)$report['cancelled']?> comenzi</small></article>
    </div>

    <div class="statistics-pro-grid statistics-pro-grid-top">
        <section class="panel statistics-trend-panel">
            <div class="statistics-pro-section-head"><div><h2>Evolutie comenzi</h2><p>Numarul total de comenzi primite in fiecare zi din perioada selectata.</p></div><span class="statistics-mini-total"><?=(int)$report['total_orders']?> total</span></div>
            <?php if($daily):?><div class="statistics-simple-chart"><?php foreach($daily as $i=>$day):?><div class="statistics-simple-day"><div class="statistics-simple-value"><?=(int)$day['orders']?></div><div class="statistics-simple-track"><i style="height:<?=(int)$day['orders']>0?max(5,(int)round(((int)$day['orders']/$dailyMax)*100)):2?>%"></i></div><?php if($i===0||$i===count($daily)-1||$i%max(1,(int)ceil(count($daily)/10))===0):?><small><?=View::e($day['label'])?></small><?php else:?><small>&nbsp;</small><?php endif;?></div><?php endforeach;?></div><?php else:?><div class="empty-state">Nu exista comenzi in perioada selectata.</div><?php endif;?>
        </section>

        <section class="panel statistics-comparison-panel">
            <div class="statistics-pro-section-head"><div><h2>Comparatii</h2><p>Volumul curent fata de perioade similare.</p></div></div>
            <div class="statistics-comparison-list">
                <article><div><span>Fata de luna trecuta</span><strong><?=(int)$report['comparison_month']['current']['orders']?> comenzi</strong><small>anterior: <?=(int)$report['comparison_month']['previous']['orders']?></small></div><b class="statistics-change <?=$monthChange['class']?>"><?=View::e($monthChange['arrow'].' '.$monthChange['text'])?></b></article>
                <article><div><span>Fata de anul trecut</span><strong><?=(int)$report['comparison_year']['current']['orders']?> comenzi</strong><small>anterior: <?=(int)$report['comparison_year']['previous']['orders']?></small></div><b class="statistics-change <?=$yearChange['class']?>"><?=View::e($yearChange['arrow'].' '.$yearChange['text'])?></b></article>
            </div>
        </section>
    </div>

    <div class="statistics-pro-grid">
        <section class="panel statistics-breakdown-panel">
            <div class="statistics-pro-section-head"><div><h2>Performanta pe canale</h2><p>Comenzi si venituri pe fiecare magazin sau marketplace.</p></div></div>
            <div class="statistics-channel-pro-list"><?php foreach($report['channels'] as $c):?><article><div class="statistics-channel-pro-head"><div><strong><?=View::e($c['name'])?></strong><small><?=View::e($c['code'])?></small></div><div><b><?=(int)$c['orders']?> comenzi</b><small><?=View::e($moneyText($c['revenue']))?></small></div></div><div class="statistics-progress"><i style="width:<?=max(3,(int)round(((int)$c['orders']/$channelMax)*100))?>%"></i></div><span><?=(int)$c['cancelled']?> anulate / returnate</span></article><?php endforeach;?><?php if(!$report['channels']):?><div class="empty-state">Nu exista date pe canale.</div><?php endif;?></div>
        </section>

        <section class="panel statistics-breakdown-panel">
            <div class="statistics-pro-section-head"><div><h2>Statusuri</h2><p>Distributia operationala a comenzilor.</p></div></div>
            <div class="statistics-status-pro-list"><?php foreach($report['statuses'] as $s):?><div><div><span><?=View::e($s['status'])?></span><b><?=(int)$s['count']?></b></div><i><em style="width:<?=(int)$s['percent']?>%"></em></i></div><?php endforeach;?><?php if(!$report['statuses']):?><div class="empty-state">Nu exista date.</div><?php endif;?></div>
        </section>
    </div>

    <section class="panel statistics-products-pro-panel">
        <div class="statistics-pro-section-head"><div><h2>Top produse vandute</h2><p>Produsele cu cea mai mare cantitate vanduta in perioada filtrata.</p></div></div>
        <div class="statistics-product-list"><?php foreach($report['top_products'] as $idx=>$p):?><article><span class="statistics-rank"><?=($idx+1)?></span><div><strong><?=View::e($p['name'])?></strong><small>SKU <?=View::e($p['sku'])?></small></div><div><b><?=number_format((float)$p['qty'],0,',',' ')?> buc.</b><small><?=(int)$p['orders']?> comenzi</small></div></article><?php endforeach;?><?php if(!$report['top_products']):?><div class="empty-state">Nu exista produse vandute in perioada selectata.</div><?php endif;?></div>
    </section>
</div>
