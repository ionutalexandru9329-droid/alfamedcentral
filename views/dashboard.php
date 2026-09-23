<?php use App\Core\Csrf; use App\Core\View; $kpiIcons=['↗','◷','▦','◎']; ?>
<div class="dashboard-shell dashboard-pro-shell">
    <div class="page-head dashboard-hero dashboard-pro-hero">
        <div><div class="eyebrow">CENTRU OPERATIONAL</div><h1>Dashboard</h1><p>Comenzi, vanzari si activitatea echipei intr-o imagine simpla si usor de urmarit.</p></div>
        <form method="post" action="<?=View::e(app_path('/sync'))?>"><?=Csrf::field()?><button class="button-secondary dashboard-sync-button" type="submit">↻ Sincronizeaza</button></form>
    </div>

    <div class="dashboard-kpi-grid dashboard-kpi-grid-pro"><?php foreach($stats as $i=>$s):?><article class="dashboard-kpi dashboard-kpi-pro"><span class="dashboard-kpi-icon"><?=$kpiIcons[$i]??'•'?></span><div><small><?=View::e($s['label'])?></small><strong><?=View::e($s['value'])?></strong><span><?=View::e($s['sub'])?></span></div></article><?php endforeach;?></div>

    <?php if(isset($modules))$modules->renderHook('dashboard.analytics',['stats'=>$stats]);?>

    <div class="dashboard-pro-grid">
        <section class="panel dashboard-orders-panel dashboard-pro-panel">
            <div class="dashboard-section-head"><div><h2>Comenzi recente</h2><p>Ultimele comenzi primite din toate canalele.</p></div><a href="<?=View::e(app_path('/orders'))?>">Vezi toate →</a></div>
            <div class="dashboard-order-list">
                <?php foreach($recent as $o):?><a class="dashboard-order-row" href="<?=View::e(app_path('/orders/'.$o['id']))?>"><div><strong><?=View::e($o['code'])?></strong><span><?=View::e($o['customer_name']?:'Client fara nume')?></span></div><span class="tag"><?=View::e($o['channel_name'])?></span><b><?=number_format((float)$o['total'],2,',',' ')?> <?=View::e($o['currency'])?></b><span class="status <?=View::e($o['status'])?>"><?=View::e($o['status'])?></span></a><?php endforeach;?>
                <?php if(!$recent):?><div class="empty-state">Nu exista comenzi sincronizate.</div><?php endif;?>
            </div>
        </section>

        <aside class="dashboard-pro-side">
            <section class="panel dashboard-team-activity dashboard-pro-panel">
                <div class="dashboard-section-head"><div><h2>Activitate echipa</h2><p>Ultimele actiuni operationale.</p></div><?php if(\App\Core\Auth::isAdmin()):?><a href="<?=View::e(app_path('/users/activity'))?>">Jurnal →</a><?php endif;?></div>
                <div class="dashboard-activity-list">
                    <?php foreach(($userActivity??[]) as $a):?><div class="dashboard-activity-item"><span class="dashboard-activity-avatar"><?=View::e(function_exists('mb_substr')?mb_strtoupper(mb_substr((string)$a['user_name'],0,1)):strtoupper(substr((string)$a['user_name'],0,1)))?></span><div><p><b><?=View::e($a['user_name'])?></b> <?=View::e($a['action_label'])?></p><small><?=View::e(date('d.m H:i',strtotime((string)$a['created_at'])))?></small></div></div><?php endforeach;?>
                    <?php if(empty($userActivity)):?><div class="empty-state">Inca nu exista activitate operationala inregistrata.</div><?php endif;?>
                </div>
            </section>
            <section class="panel dashboard-channel-panel dashboard-pro-panel"><div class="dashboard-section-head"><div><h2>Canale</h2><p>Conexiuni active.</p></div></div><div class="dashboard-channel-list dashboard-channel-list-pro"><?php foreach($channelState as $c):?><div><span class="channel-health <?=$c['enabled']?'on':'off'?>"></span><div><strong><?=View::e($c['name'])?></strong><small><?=View::e(strtoupper($c['country']))?> · <?=View::e($c['currency'])?></small></div><b><?=$c['enabled']?'Activ':'Inactiv'?></b></div><?php endforeach;?></div></section>
        </aside>
    </div>

    <?php if(\App\Core\Auth::can('settings.manage')):?><section class="dashboard-system-link"><span>Ai nevoie de detalii tehnice?</span><a href="<?=View::e(app_path('/settings'))?>&tab=logs">Deschide Jurnalul de sincronizare →</a></section><?php endif;?>
</div>
