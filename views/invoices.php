<?php use App\Core\View; use App\Core\Csrf; ?>
<div class="page-head"><div><h1>Facturi</h1><p>Modul separat pentru facturi Oblio si documente PDF incarcate manual.</p></div></div>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Document</th><th>Comanda</th><th>Canal</th><th>Client</th><th>Agent</th><th>Total</th><th>Sincronizare</th><th>Status</th><th>Data</th><th>Actiuni</th></tr></thead><tbody>
<?php foreach($invoices as $i):?>
<?php $meta=json_decode((string)($i['raw_json']??'{}'),true)?:[];$agent=trim((string)($meta['agent']??''));$sync=(array)($meta['channel_sync']??[]);$syncState=(string)($sync['state']??''); ?>
<tr>
<td><b><?=View::e(trim(strtoupper((string)$i['type']).' '.(string)$i['series'].' '.(string)$i['number']))?></b></td>
<td><a href="<?=View::e(app_path('/orders/'.$i['order_id']))?>"><?=View::e($i['code'])?></a></td>
<td><span class="tag"><?=View::e($i['channel_name'])?></span></td>
<td><?=View::e($i['customer_name'])?></td>
<td><?=View::e($agent?:'—')?></td>
<td><?=number_format((float)$i['total'],2,',',' ')?> <?=View::e($i['currency'])?></td>
<td><?php if(($i['type']??'')!=='invoice'):?>—<?php elseif($syncState==='sent'):?><span class="tag">Trimisa · <?=View::e((string)($sync['channel']??'canal'))?></span><?php elseif($syncState==='error'):?><span class="tag danger" title="<?=View::e((string)($sync['message']??''))?>">Eroare</span><?php else:?>In asteptare<?php endif;?></td>
<td><?=View::e($i['status'])?></td><td><?=View::e($i['created_at'])?></td>
<td><div class="actions"><?php if(!empty($i['link'])):?><a class="btn-link" target="_blank" rel="noopener" href="<?=View::e($i['link'])?>">Deschide</a><?php endif;?><?php if(($i['type']??'')==='uploaded'):?><form class="inline" method="post" action="<?=View::e(app_path('/orders/'.$i['order_id'].'/invoice-delete/'.$i['id']))?>" onsubmit="return confirm('Stergi factura PDF incarcata?')"><?=Csrf::field()?><button class="small danger-outline">Sterge</button></form><?php endif;?><a class="button-secondary small" href="<?=View::e(app_path('/orders/'.$i['order_id']))?>">Comanda</a></div></td>
</tr>
<?php endforeach;?>
<?php if(!$invoices):?><tr><td colspan="10" class="empty-state">Nu exista documente in modul.</td></tr><?php endif;?>
</tbody></table></div></section>
