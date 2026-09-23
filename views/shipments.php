<?php use App\Core\Auth; use App\Core\Csrf; use App\Core\View; ?>
<div class="page-shell awb-page-shell">
    <div class="page-head awb-page-head">
        <div>
            <div class="eyebrow">LOGISTICA</div>
            <h1>AWB / Expedieri</h1>
            <p>AWB-urile salvate local, tracking si acces rapid la comenzile asociate.</p>
        </div>
        <a class="button-secondary" href="<?=View::e(app_path('/scan'))?>">▣ Scanare colet</a>
    </div>

    <section class="panel awb-list-panel">
        <div class="panel-title-row">
            <div><h2>AWB-uri salvate</h2><p><?=count($shipments)?> expedieri disponibile local.</p></div>
        </div>
        <div class="table-wrap"><table class="awb-table"><thead><tr><th>AWB</th><th>Curier</th><th>Comanda</th><th>Client</th><th>Canal</th><th>Status</th><?php if(Auth::can('orders.manage')):?><th>Actiuni</th><?php endif;?></tr></thead><tbody>
        <?php foreach($shipments as $s): $deleted=in_array((string)$s['status'],['deleted','cancelled'],true);?><tr class="<?=$deleted?'awb-row-deleted':''?>">
            <td><b><?=View::e($s['awb'])?></b><?php if(!empty($s['awb_barcode'])&&$s['awb_barcode']!==$s['awb']):?><br><small><?=View::e($s['awb_barcode'])?></small><?php endif;?><?php if(!empty($s['tracking_url'])):?><br><a class="mini-link" target="_blank" rel="noopener" href="<?=View::e($s['tracking_url'])?>">tracking ↗</a><?php endif;?></td>
            <td><?=View::e($s['courier'])?></td>
            <td><a href="<?=View::e(app_path('/orders/'.$s['order_id']))?>"><?=View::e($s['code'])?></a></td>
            <td><?=View::e($s['customer_name'])?></td>
            <td><span class="tag"><?=View::e($s['channel_name'])?></span></td>
            <td><span class="status <?=$deleted?'cancelled':'processing'?>"><?=$deleted?'sters':View::e($s['status'])?></span></td>
            <?php if(Auth::can('orders.manage')):?><td><?php if(!$deleted && ($s['channel_type']??'')!=='emag'):?><form method="post" action="<?=View::e(app_path('/awb/'.$s['id'].'/delete'))?>" onsubmit="return confirm('Stergi AWB-ul <?=View::e($s['awb'])?>? Vei putea genera unul nou pentru aceasta comanda.')"><?=Csrf::field()?><input type="hidden" name="back" value="awb"><button class="danger small" type="submit">Sterge AWB</button></form><?php elseif(!$deleted && ($s['channel_type']??'')==='emag'):?><span class="muted">administrat eMAG</span><?php else:?><span class="muted">—</span><?php endif;?></td><?php endif;?>
        </tr><?php endforeach;?>
        <?php if(!$shipments):?><tr><td colspan="7" class="empty-state">Nu exista expedieri salvate.</td></tr><?php endif;?></tbody></table></div>
        <div class="info-strip"><span>i</span><p>AWB-urile generate din ALFAMED CENTRAL devin imediat disponibile pentru scanare si pentru actiunile de descarcare.</p></div>
    </section>
</div>
