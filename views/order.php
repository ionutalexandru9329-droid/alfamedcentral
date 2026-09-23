<?php
use App\Core\View;
use App\Core\Csrf;
use App\Core\Auth;
use App\Services\EmagOrderNormalizer;

$statusLabels=['new'=>'Noua','pending'=>'In asteptare','processing'=>'In procesare','on-hold'=>'In asteptare','prepared'=>'Pregatita','completed'=>'Finalizata','cancelled'=>'Anulata','refunded'=>'Stornata','returned'=>'Returnata'];
$billing=(array)($order['billing']??[]);
$shipping=(array)($order['shipping']??[]);
$raw=(array)($order['raw']??[]);
$fullName=static function(array $address,string $fallback=''): string {
    $name=trim((string)($address['first_name']??'').' '.(string)($address['last_name']??''));
    if($name==='')$name=trim((string)($address['name']??''));
    $company=trim((string)($address['company']??''));
    if($company!==''&&$name!==''&&strcasecmp($company,$name)!==0)return $company.' · '.$name;
    return $company!==''?$company:($name!==''?$name:$fallback);
};
$addressLines=static function(array $a): array {
    $lines=[];
    foreach(['address_1','address_2'] as $key){$v=trim((string)($a[$key]??''));if($v!=='')$lines[]=$v;}
    $local=array_values(array_filter([trim((string)($a['postcode']??'')),trim((string)($a['city']??'')),trim((string)($a['state']??''))],fn($v)=>$v!==''));
    if($local)$lines[]=implode(', ',$local);
    $country=trim((string)($a['country']??''));if($country!=='')$lines[]=$country;
    if(!empty($a['locker_name']))$lines[]='Locker: '.trim((string)$a['locker_name']);
    return $lines;
};
$billingName=$fullName($billing,(string)$order['customer_name']);
$shippingName=$fullName($shipping,(string)$order['customer_name']);
$billingPhone=trim((string)($billing['phone']??$order['customer_phone']??''));
$billingEmail=trim((string)($billing['email']??$order['customer_email']??''));
$shippingPhone=trim((string)($shipping['phone']??$billingPhone));
$shippingEmail=trim((string)($shipping['email']??$billingEmail));
$fiscal=(array)($billing['_fiscal']??[]);
$fiscalExtras=(array)($billing['_fiscal_extra']??[]);
$isEmag=(string)($order['channel_type']??'')==='emag';
$canManageOrderImages=$isEmag&&Auth::can('orders.manage');
$isFbe=$isEmag&&(int)($raw['type']??3)===2;
$isEmagNew=$isEmag&&(string)($order['status']??'')==='new';
$paymentTitle=$isEmag?EmagOrderNormalizer::paymentLabel($raw):(string)($raw['payment_method_title']??$raw['payment_method']??'');
$shippingMethod=$isEmag?EmagOrderNormalizer::deliveryLabel($raw):'';
if(!$isEmag&&!empty($raw['shipping_lines'])&&is_array($raw['shipping_lines'])){
    $names=[];foreach($raw['shipping_lines'] as $line)if(is_array($line)){$v=trim((string)($line['method_title']??$line['method_id']??''));if($v!=='')$names[]=$v;}
    $shippingMethod=implode(', ',array_unique($names));
}
if($shippingMethod===''&&isset($raw['shipping_method']))$shippingMethod=(string)$raw['shipping_method'];
$shippingFee=$isEmag?(float)($raw['shipping_tax']??0):(float)($raw['shipping_total']??0)+(float)($raw['shipping_tax']??0);
$orderDate=(string)($order['ordered_at']??$order['created_at']??'');
?>
<div class="order-detail-shell" data-order-open-processing="<?=(int)$order['id']?>" data-emag-media-sync="<?=$isEmag?'1':'0'?>">
    <div class="order-detail-head">
        <div class="order-title-block">
            <a class="order-back" href="<?=View::e(app_path('/orders'))?>">← Inapoi la comenzi</a>
            <div class="order-title-line">
                <div>
                    <h1><?=View::e($order['code'])?></h1>
                    <p><span class="order-channel-chip <?= $isEmag?'emag':'woo' ?>"><?=View::e($order['channel_name'])?></span> · comanda externa <b><?=View::e($order['external_id'])?></b><?php if($orderDate!==''):?> · <?=View::e(date('d.m.Y H:i',strtotime($orderDate)?:time()))?><?php endif;?></p>
                </div>
                <span class="status big <?=View::e($order['status'])?>"><?=View::e($statusLabels[$order['status']]??$order['status'])?></span>
            </div>
        </div>
        <div class="order-total-card"><span>Total comanda</span><strong><?=number_format((float)$order['total'],2,',',' ')?> <?=View::e($order['currency'])?></strong></div>
    </div>

    <div class="order-detail-grid">
        <div class="order-main-column">
            <section class="panel order-address-panel">
                <div class="panel-title-row"><div><h2>Date client</h2><p>Facturare si livrare asa cum au fost primite din canalul de vanzare.</p></div></div>
                <div class="address-grid">
                    <article class="address-card">
                        <div class="address-card-head"><span class="address-icon">🧾</span><div><h3>Date de facturare</h3><small>Billing</small></div></div>
                        <strong><?=View::e($billingName?:'—')?></strong>
                        <?php foreach($addressLines($billing) as $line):?><span><?=View::e($line)?></span><?php endforeach;?>
                        <?php if($billingPhone!==''):?><a href="tel:<?=View::e($billingPhone)?>">📞 <?=View::e($billingPhone)?></a><?php endif;?>
                        <?php if($billingEmail!==''):?><a href="mailto:<?=View::e($billingEmail)?>">✉ <?=View::e($billingEmail)?></a><?php endif;?>
                        <?php if($fiscal):?>
                        <div class="billing-fiscal-block">
                            <div class="billing-fiscal-title"><span>🏢</span><b>Date fiscale</b><em class="customer-type-chip"><?=View::e($fiscal['person_type']??'Client')?></em></div>
                            <dl class="billing-fiscal-list">
                                <?php if(!empty($fiscal['vat_number'])):?><div><dt>CUI / CIF / TVA</dt><dd><?=View::e($fiscal['vat_number'])?></dd></div><?php endif;?>
                                <?php if(!empty($fiscal['registration_number'])):?><div><dt>Nr. Reg. Com.</dt><dd><?=View::e($fiscal['registration_number'])?></dd></div><?php endif;?>
                                <?php if(!empty($fiscal['bank'])):?><div><dt>Banca</dt><dd><?=View::e($fiscal['bank'])?></dd></div><?php endif;?>
                                <?php if(!empty($fiscal['iban'])):?><div><dt>IBAN / cont bancar</dt><dd><?=View::e($fiscal['iban'])?></dd></div><?php endif;?>
                                <?php if(!empty($fiscal['cnp'])):?><div><dt>CNP</dt><dd><?=View::e($fiscal['cnp'])?></dd></div><?php endif;?>
                                <?php foreach($fiscalExtras as $extra): if(!is_array($extra)||empty($extra['value']))continue;?><div><dt><?=View::e($extra['label']??'Camp fiscal')?></dt><dd><?=View::e($extra['value'])?></dd></div><?php endforeach;?>
                            </dl>
                        </div>
                        <?php endif;?>
                    </article>
                    <article class="address-card">
                        <div class="address-card-head"><span class="address-icon">🚚</span><div><h3>Date de livrare</h3><small>Shipping</small></div></div>
                        <strong><?=View::e($shippingName?:$billingName?:'—')?></strong>
                        <?php $shipLines=$addressLines($shipping); if(!$shipLines)$shipLines=$addressLines($billing);?>
                        <?php foreach($shipLines as $line):?><span><?=View::e($line)?></span><?php endforeach;?>
                        <?php if($shippingPhone!==''):?><a href="tel:<?=View::e($shippingPhone)?>">📞 <?=View::e($shippingPhone)?></a><?php endif;?>
                        <?php if($shippingEmail!==''):?><a href="mailto:<?=View::e($shippingEmail)?>">✉ <?=View::e($shippingEmail)?></a><?php endif;?>
                    </article>
                </div>
                <?php if($paymentTitle!==''||$shippingMethod!==''||$shippingFee>0):?>
                <div class="order-meta-strip">
                    <?php if($paymentTitle!==''):?><div><span>Plata</span><strong><?=View::e($paymentTitle)?></strong></div><?php endif;?>
                    <?php if($shippingMethod!==''):?><div><span>Livrare</span><strong><?=View::e($shippingMethod)?></strong></div><?php endif;?>
                    <?php if($isEmag):?><div><span>Transport in comanda</span><strong><?=number_format($shippingFee,2,',',' ')?> <?=View::e($order['currency'])?></strong></div><?php endif;?>
                </div>
                <?php endif;?>
                <?php if($isFbe):?><div class="channel-notice info"><b>Fulfilled by eMAG</b><span>Logistica si AWB-ul sunt administrate de eMAG; ALFAMED CENTRAL nu afiseaza actiuni seller incompatibile.</span></div><?php endif;?>
            </section>

            <section class="panel order-products-panel">
                <div class="panel-title-row order-products-head"><div><h2>Produse comandate</h2><p><?=count($order['items'])?> pozitii in comanda</p></div></div>
                <div class="table-wrap"><table class="order-products"><thead><tr><th>Preview</th><th>SKU</th><th>Produs</th><th>Cant.</th><th>Pret unitar</th><th>Subtotal</th></tr></thead><tbody>
                <?php foreach($order['items'] as $i):$subtotal=(float)$i['qty']*(float)$i['unit_price'];?><tr data-order-item-id="<?=(int)$i['id']?>" data-product-name="<?=View::e($i['name'])?>">
                    <td data-order-item-media="<?=(int)$i['id']?>"><?php if(!empty($i['image_url'])):?><button type="button" class="product-thumb-button js-image-preview" data-image="<?=View::e($i['image_url'])?>" data-caption="<?=View::e($i['name'])?>" data-order-id="<?=(int)$order['id']?>" data-order-item-id="<?=(int)$i['id']?>" data-order-image-tools="<?=$canManageOrderImages?'1':'0'?>"><img class="product-thumb" src="<?=View::e($i['image_url'])?>" alt="<?=View::e($i['name'])?>" loading="lazy"></button><?php else:?><button type="button" class="product-thumb-button js-image-preview order-image-placeholder-trigger" data-image="" data-caption="<?=View::e($i['name'])?>" data-order-id="<?=(int)$order['id']?>" data-order-item-id="<?=(int)$i['id']?>" data-order-image-tools="<?=$canManageOrderImages?'1':'0'?>"><span class="product-thumb placeholder <?=!empty($i['product_url'])?'image-pending':''?>"><?=!empty($i['product_url'])?'caut imagine':'fara imagine'?></span></button><?php endif;?></td>
                    <td><span class="sku-chip"><?=View::e($i['sku']?:'—')?></span></td>
                    <td data-order-item-product="<?=(int)$i['id']?>"><?php if(!empty($i['product_url'])):?><a class="product-source-link" target="_blank" rel="noopener" href="<?=View::e($i['product_url'])?>"><?=View::e($i['name'])?> <span aria-hidden="true">↗</span></a><?php else:?><strong><?=View::e($i['name'])?></strong><?php endif;?></td>
                    <td><?=number_format((float)$i['qty'],(float)$i['qty']===(float)(int)$i['qty']?0:2,',',' ')?></td>
                    <td><?=number_format((float)$i['unit_price'],2,',',' ')?> <?=View::e($order['currency'])?></td>
                    <td><b><?=number_format($subtotal,2,',',' ')?> <?=View::e($order['currency'])?></b></td>
                </tr><?php endforeach;?></tbody></table></div>
                <div class="order-grand-total"><span>Total</span><strong><?=number_format((float)$order['total'],2,',',' ')?> <?=View::e($order['currency'])?></strong></div>
            </section>

            <?php $modules->renderHook('order.after',['order'=>$order]);?>
        </div>

        <aside class="order-side-column">
            <section class="panel order-action-panel">
                <div class="panel-title-row"><div><h2>Actiuni comanda</h2><p>Operatiuni rapide pentru aceasta comanda.</p></div></div>
                <?php if(!empty($order['status_note'])):?><div class="order-note"><b>Motiv / nota:</b><br><?=View::e($order['status_note'])?></div><?php endif;?>
                <?php if($isEmagNew&&!$isFbe):?>
                <form method="post" action="<?=View::e(app_path('/orders/'.$order['id'].'/emag-acknowledge'))?>" class="quick-status-form emag-ack-form"><?=Csrf::field()?><button class="order-action-button primary-action" type="submit">✓ Preia comanda eMAG</button><small>Acknowledge muta comanda din Noua in In procesare.</small></form>
                <?php endif;?>
                <div class="order-status-actions">
                    <?php if(!$isFbe&&!$isEmagNew&&!in_array((string)$order['status'],['completed','cancelled','returned'],true)):?><form method="post" action="<?=View::e(app_path('/orders/'.$order['id'].'/status'))?>" class="quick-status-form" onsubmit="return confirm('Aceasta actiune va transmite statusul FINALIZATA si catre <?=View::e($order['channel_name'])?>. Continui?')"><?=Csrf::field()?><input type="hidden" name="status" value="completed"><input type="hidden" name="reason" value=""><button class="order-action-button success-action" type="submit">✓ Marcheaza finalizata</button></form><?php endif;?>
                    <?php if(!$isFbe&&!$isEmagNew&&!in_array((string)$order['status'],['completed','cancelled','returned','refunded'],true)):?><button class="order-action-button cancel-action js-order-status-dialog" type="button" data-status="cancelled">✕ Anuleaza comanda</button><?php endif;?>
                </div>
                <form method="post" action="<?=View::e(app_path('/orders/'.$order['id'].'/status'))?>" class="order-status-dialog-form" data-order-status-form hidden>
                    <?=Csrf::field()?><input type="hidden" name="status" value="">
                    <?php if($isEmag):?><label class="emag-cancel-reason" data-emag-cancel-reason hidden>Motiv anulare eMAG<select name="emag_cancellation_reason"><option value="">Alege motivul</option><option value="1">Lipsa stoc</option><option value="2">Anulata la cererea clientului</option><option value="3">Clientul nu poate fi contactat</option></select></label><?php endif;?>
                    <label>Motiv / nota<textarea name="reason" rows="3" placeholder="Ex: Clientul a solicitat anularea..."></textarea><small><?php if($isEmag):?>Pentru anularea eMAG este obligatoriu motivul Marketplace de mai sus. Anularea este transmisa catre canalul comenzii.<?php else:?>Pentru WooCommerce, mesajul este adaugat si ca nota vizibila clientului.<?php endif;?></small></label><div class="actions"><button class="primary" type="submit">Confirma</button><button type="button" data-close-status-form>Renunta</button></div>
                </form>
                <div class="module-actions-stack"><?php $modules->renderHook('order.operations',['order'=>$order]);?></div>
            </section>

            <section class="panel order-info-panel">
                <h2>Informatii comanda</h2>
                <dl class="order-info-list">
                    <div><dt>Canal</dt><dd><?=View::e($order['channel_name'])?></dd></div>
                    <div><dt>ID extern</dt><dd><?=View::e($order['external_id'])?></dd></div>
                    <div><dt>Moneda</dt><dd><?=View::e($order['currency'])?></dd></div>
                    <div><dt>Data</dt><dd><?=View::e($orderDate!==''?date('d.m.Y H:i',strtotime($orderDate)?:time()):'—')?></dd></div>
                </dl>
            </section>
        </aside>
    </div>

</div>
