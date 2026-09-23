<?php use App\Core\View; $moneyParts=[];foreach((array)$report['revenue'] as $currency=>$value)$moneyParts[]=number_format((float)$value,2,',',' ').' '.$currency;$daily=(array)$report['daily'];$chartMax=1;foreach($daily as $d)$chartMax=max($chartMax,(int)$d['orders']); ?>
<section class="panel dashboard-sales-overview">
    <div class="dashboard-section-head"><div><span class="eyebrow">PERFORMANTA</span><h2>Vanzari · ultimele <?=(int)$report['days']?> zile</h2><p>Rezumat clar al comenzilor si veniturilor.</p></div><a class="button-secondary" href="<?=View::e(app_path('/statistics'))?>">Statistici detaliate →</a></div>
    <div class="dashboard-sales-summary">
        <div><span>Comenzi</span><strong><?=(int)$report['total_orders']?></strong><small><?=number_format((float)$report['avg_orders_per_day'],1,',',' ')?> / zi</small></div>
        <div><span>In lucru</span><strong><?=(int)$report['active']?></strong><small>nefinalizate</small></div>
        <div><span>Finalizate</span><strong><?=(int)$report['completed']?></strong><small>inchise</small></div>
        <div><span>Venituri</span><strong><?=View::e($moneyParts?implode(' · ',$moneyParts):'0 RON')?></strong><small>fara anulate<?=!empty($report['exclude_cancelled'])?' / returnate':''?></small></div>
    </div>
    <div class="dashboard-sales-chart" aria-label="Comenzi pe zile"><?php foreach($daily as $i=>$day):?><div class="dashboard-sales-day"><i style="height:<?=(int)$day['orders']>0?max(8,(int)round(((int)$day['orders']/$chartMax)*100)):2?>%" title="<?=View::e($day['label'])?> · <?=(int)$day['orders']?> comenzi"></i><?php if($i===0||$i===count($daily)-1||$i%max(1,(int)ceil(count($daily)/7))===0):?><small><?=View::e($day['label'])?></small><?php endif;?></div><?php endforeach;?></div>
</section>
