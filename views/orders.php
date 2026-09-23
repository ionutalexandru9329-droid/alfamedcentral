<?php use App\Core\View; use App\Core\Csrf; use App\Services\OrderPresentation;
$statusLabels=['new'=>'Noua','pending'=>'In asteptare','processing'=>'In procesare','on-hold'=>'In asteptare','prepared'=>'Pregatita','completed'=>'Finalizata','cancelled'=>'Anulata','refunded'=>'Stornata','returned'=>'Returnata','failed'=>'Esuata'];
$actions=$orderActions??[];
$awbAction=(array)($actions['generate_awb']??[]);
$awbCouriers=(array)($awbAction['couriers']??[]);
$awbDefault=(string)($awbAction['default_courier']??(array_key_first($awbCouriers)??''));
$activeFilters=(($q??'')!==''?1:0)+(($channel??'')!==''?1:0)+(($status??'')!==''?1:0);
$reselectMap=array_fill_keys(array_map('intval',(array)($reselectIds??[])),true);
?>
<div class="orders-page-shell">
    <div class="page-head orders-page-head">
        <div><div class="eyebrow">CENTRU COMENZI</div><h1>Comenzi</h1><p>eMAG Romania, eMAG Bulgaria, Univera si Alfamedclinic intr-o singura lista.</p></div>
        <div class="orders-head-stats"><div><span>Total filtrat</span><strong><?=(int)($total??0)?></strong></div><div><span>Pe pagina</span><strong><?=count($orders)?></strong></div></div>
    </div>

    <form class="orders-filterbar" method="get" action="<?=View::e(app_front_controller())?>" data-orders-filter-form>
        <input type="hidden" name="route" value="/orders">
        <label class="orders-search-box"><span aria-hidden="true">⌕</span><input name="q" value="<?=View::e($q??'')?>" placeholder="Cauta dupa comanda, client, email sau telefon..." autocomplete="off" data-orders-search></label>
        <label class="orders-filter-select"><span>Canal</span><select name="channel" data-orders-auto-filter><option value="">Toate canalele</option><?php foreach($channels as $c):?><option value="<?=View::e($c['code'])?>" <?=($channel??'')===$c['code']?'selected':''?>><?=View::e($c['name'])?></option><?php endforeach;?></select></label>
        <label class="orders-filter-select"><span>Status</span><select name="status" data-orders-auto-filter><option value="">Toate statusurile</option><?php foreach($statusLabels as $s=>$label):?><option value="<?=View::e($s)?>" <?=($status??'')===$s?'selected':''?>><?=View::e($label)?></option><?php endforeach;?></select></label>
        <?php if($activeFilters>0):?><a class="orders-clear-filter" href="<?=View::e(app_path('/orders'))?>" title="Sterge filtrele">× Reseteaza</a><?php endif;?>
    </form>

    <form method="post" action="<?=View::e(app_path('/orders/actions'))?>" class="bulk-form" id="ordersBulkForm"><?=Csrf::field()?>
        <input type="hidden" name="return_q" value="<?=View::e($q??'')?>"><input type="hidden" name="return_channel" value="<?=View::e($channel??'')?>"><input type="hidden" name="return_status" value="<?=View::e($status??'')?>"><input type="hidden" name="return_page" value="<?=(int)($page??1)?>">
        <section class="panel orders-bulk-toolbar">
            <div class="bulk-toolbar-top">
                <div class="bulk-toolbar-main"><div class="bulk-icon" aria-hidden="true">✓</div><div><strong>Actiuni in masa</strong><small>Selecteaza comenzile pe care vrei sa le procesezi.</small></div><span class="selection-count" data-selection-count>0 selectate</span></div>
                <div class="bulk-controls">
                    <select name="action" data-order-action-select aria-label="Alege actiunea"><option value="">Alege actiunea...</option><?php foreach($actions as $key=>$a):?><option value="<?=View::e($key)?>"><?=View::e(($a['icon']??'').' '.($a['label']??$key))?></option><?php endforeach;?></select>
                    <button class="primary bulk-apply-button" type="submit" name="bulk_submit" value="1">Aplica</button>
                </div>
            </div>
            <?php if(isset($actions['download_awb'])):?><div class="awb-download-format-panel" data-awb-download-options hidden><div><strong>Format etichete AWB</strong><small>Se descarca o singura arhiva ZIP pentru comenzile selectate.</small></div><label><span>Format</span><select name="awb_format" data-awb-download-format><option value="A4">A4</option><option value="A6">A6</option></select></label></div><?php endif;?>
            <?php if(isset($actions['generate_awb_auto'])):?>
            <div class="awb-bulk-panel awb-bulk-auto" data-auto-awb-options hidden>
                <div class="awb-bulk-intro"><span class="awb-bulk-icon" aria-hidden="true">🚚</span><div><strong>Generare AWB automata</strong><small>eMAG: clientul, rambursul si lockerul sunt preluate automat. Easybox/FANbox primesc 1 AWB / colet; comenzile cu AWB existent sunt omise. Livrarea la domiciliu foloseste numarul de colete de mai jos in acelasi AWB.</small></div></div>
                <div class="awb-bulk-fields"><label><span>Colete pentru domiciliu</span><input type="number" min="1" max="999" step="1" name="parcels" value="1" required></label><label><span>Greutate totala / comanda</span><div class="input-suffix"><input type="number" min="0.1" step="0.1" name="weight" placeholder="implicit"><span>kg</span></div></label></div>
                <div class="awb-auto-cod"><span class="awb-auto-cod-icon">↻</span><div><b>Ramburs automat</b><small>Datele fiecarei comenzi sunt folosite individual. Selectia ramane bifata dupa generare pentru Descarca AWB(uri).</small></div></div>
            </div>
            <?php endif;?>
            <?php if($awbCouriers):?>
            <div class="awb-bulk-panel" data-courier-options hidden>
                <div class="awb-bulk-intro"><span class="awb-bulk-icon" aria-hidden="true">🚚</span><div><strong>Detalii expeditie WooCommerce</strong><small>Panoul manual este folosit doar pentru actiunea AWB WooCommerce. Actiunea „Genereaza AWB-uri automat” foloseste metoda reala din fiecare comanda.</small></div></div>
                <div class="awb-bulk-fields"><label><span>Curier</span><select name="courier" required><?php foreach($awbCouriers as $code=>$label):?><option value="<?=View::e($code)?>" <?=$code===$awbDefault?'selected':''?>><?=View::e($label)?></option><?php endforeach;?></select></label><label><span>Numar colete</span><input type="number" min="1" step="1" name="parcels" value="1" required></label><label><span>Greutate totala</span><div class="input-suffix"><input type="number" min="0.1" step="0.1" name="weight" placeholder="implicit"><span>kg</span></div></label></div>
                <div class="awb-auto-cod"><span class="awb-auto-cod-icon">↻</span><div><b>Ramburs automat</b><small>Numerar / ramburs la livrare → totalul comenzii. Card, plata online sau transfer → 0 de achitat.</small></div></div>
            </div>
            <?php endif;?>
        </section>

        <section class="panel orders-table-panel">
            <div class="table-wrap"><table class="orders-table"><thead><tr><th class="check-col"><input type="checkbox" data-select-all="orders" aria-label="Selecteaza toate"></th><th>Comanda</th><th>Canal</th><th>Externa</th><th>Client</th><th>Total</th><th>Metoda de plata</th><th>Livrare</th><th>Status</th><th class="actions-col">Actiuni</th></tr></thead><tbody>
            <?php foreach($orders as $o): $payment=OrderPresentation::paymentLabel($o);$delivery=OrderPresentation::deliveryLabel($o);?>
            <tr class="order-summary-row" data-order-row="<?=(int)$o['id']?>">
                <td class="order-select-cell"><input type="checkbox" name="order_ids[]" value="<?=(int)$o['id']?>" data-select-item="orders" <?=isset($reselectMap[(int)$o['id']])?'checked':''?> aria-label="Selecteaza <?=View::e($o['code'])?>"></td>
                <td class="order-main-cell" data-label="Comanda"><div class="order-main-top"><a class="order-code-link" href="<?=View::e(app_path('/orders/'.$o['id']))?>"><?=View::e($o['code'])?></a><span class="order-mobile-channel-inline tag"><?=View::e($o['channel_name'])?></span></div><div class="order-main-bottom"><small><?=View::e($o['ordered_at'])?></small><span class="order-mobile-status-inline status <?=View::e($o['status'])?>"><?=View::e($statusLabels[$o['status']]??$o['status'])?></span></div></td>
                <td class="order-channel-cell" data-label="Canal"><span class="tag"><?=View::e($o['channel_name'])?></span></td>
                <td class="order-mobile-secondary" data-label="ID extern"><span class="external-order-id"><?=View::e($o['external_id'])?></span></td>
                <td class="order-customer-mobile" data-label="Client"><div class="order-customer-cell"><strong><?=View::e($o['customer_name'])?></strong><?php if(!empty($o['customer_phone'])):?><small><?=View::e($o['customer_phone'])?></small><?php endif;?></div></td>
                <td class="order-total-cell" data-label="Total"><b><?=number_format((float)$o['total'],2,',',' ')?> <?=View::e($o['currency'])?></b></td>
                <td class="order-mobile-secondary" data-label="Plata"><span class="order-method-chip payment-method"><?=View::e($payment)?></span></td>
                <td class="order-mobile-secondary" data-label="Livrare"><span class="order-method-chip delivery-method <?=View::e(OrderPresentation::deliveryKind($o))?>"><?=View::e($delivery)?></span></td>
                <td class="order-status-cell" data-label="Status"><span class="status <?=View::e($o['status'])?>"><?=View::e($statusLabels[$o['status']]??$o['status'])?></span></td>
                <td class="order-actions-mobile" data-label="Actiuni"><div class="order-row-actions"><a class="icon-action order-view-action" href="<?=View::e(app_path('/orders/'.$o['id']))?>" title="Vezi comanda"><span aria-hidden="true">👁</span><span>Vezi</span></a><button type="button" class="order-details-toggle" data-order-details-toggle data-preview-url="<?=View::e(app_path('/orders/'.$o['id'].'/preview'))?>" aria-expanded="false"><span>Arata detalii</span><b aria-hidden="true">⌄</b></button></div></td>
            </tr>
            <tr class="order-details-row" data-order-details-row="<?=(int)$o['id']?>" hidden><td colspan="10"><div class="order-details-slide" data-order-details-body><div class="order-details-loading">Se incarca detaliile comenzii...</div></div></td></tr>
            <?php endforeach;?>
            <?php if(!$orders):?><tr><td colspan="10" class="empty-state"><strong>Nu exista comenzi pentru filtrele selectate.</strong><br><span>Modifica filtrele sau asteapta urmatoarea sincronizare automata.</span></td></tr><?php endif;?>
            </tbody></table></div>
        </section>
    </form>

    <?php if(($pages??1)>1): $makeOrdersPage=function(int $p) use($q,$channel,$status){$url=app_path('/orders').'&page='.$p;if(($q??'')!=='')$url.='&q='.rawurlencode((string)$q);if(($channel??'')!=='')$url.='&channel='.rawurlencode((string)$channel);if(($status??'')!=='')$url.='&status='.rawurlencode((string)$status);return $url;}; ?>
    <nav class="pagination" aria-label="Paginare comenzi"><span class="pagination-info">Afisate <?=count($orders)?> din <?=(int)($total??count($orders))?> comenzi</span><div class="pagination-links"><?php if(($page??1)>1):?><a href="<?=View::e($makeOrdersPage((int)$page-1))?>">‹ Inapoi</a><?php endif;?><?php $from=max(1,(int)$page-2);$to=min((int)$pages,(int)$page+2);for($i=$from;$i<=$to;$i++):?><a class="<?=$i===(int)$page?'active':''?>" href="<?=View::e($makeOrdersPage($i))?>"><?=$i?></a><?php endfor;?><?php if(($page??1)<($pages??1)):?><a href="<?=View::e($makeOrdersPage((int)$page+1))?>">Inainte ›</a><?php endif;?></div></nav>
    <?php endif;?>
</div>
