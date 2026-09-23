<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Services\ChannelFactory;
use App\Integrations\EmagClient;
use AlfamedModules\Couriers\CourierService;
use AlfamedModules\Couriers\EmagFulfillmentService;

require_once __DIR__.'/src/CourierService.php';
require_once __DIR__.'/src/EmagFulfillmentService.php';

$courierService=new CourierService(Database::connection());
$configuredCouriers=$courierService->configuredCouriers();
$defaultCourier=(string)(array_key_first($configuredCouriers)??'');
$ordersActions=[];
$ordersActions['generate_awb_auto']=[
    'label'=>'Genereaza AWB-uri','icon'=>'🚚','description'=>'Genereaza automat AWB pentru comenzile selectate. eMAG preia destinatarul, lockerul/curierul si rambursul din comanda; WooCommerce foloseste metoda de livrare reala.',
    'handler'=>static function(array $ctx):array{
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($ctx['order_ids']??$ctx['ids']??[])),static fn($v)=>$v>0)));
        $payload=(array)($ctx['payload']??[]);$ok=0;$errors=[];$warnings=[];$items=[];$successIds=[];$ops=new \App\Services\OrderOperations();
        if(count($ids)>50){$warnings[]='Pentru protectia API-urilor se proceseaza maximum 50 de comenzi intr-un lot.';$ids=array_slice($ids,0,50);}
        $emagSvc=new EmagFulfillmentService(Database::connection());$wooSvc=new CourierService(Database::connection());
        foreach($ids as $id){
            try{
                $order=$ops->getOrder($id);$type=(string)($order['channel_type']??'');
                if($type==='emag')$r=$emagSvc->createAutomatic($id,$payload);
                elseif($type==='woocommerce')$r=$wooSvc->createAutomatic($id,$payload);
                else throw new \RuntimeException('Canalul comenzii nu are flux AWB automat.');
                $ok++;$successIds[]=$id;$items[]=$r;foreach((array)($r['warnings']??[]) as $w)$warnings[]='#'.$id.': '.$w;
            }catch(\Throwable $e){$message=$e->getMessage();if(str_contains(strtolower($message),'deja awb'))$warnings[]='#'.$id.': '.$message;else $errors[]='#'.$id.': '.$message;}
        }
        return ['ok'=>$ok,'errors'=>$errors,'warnings'=>$warnings,'items'=>$items,'success_ids'=>$successIds];
    },
];
if($configuredCouriers){
    $ordersActions['generate_awb']=[
        'label'=>'AWB WooCommerce - curier manual','icon'=>'📦','description'=>'Genereaza AWB pentru comenzile WooCommerce selectate cu un curier ales manual.',
        'couriers'=>$configuredCouriers,'default_courier'=>$defaultCourier,
        'handler'=>static function(array $ctx):array{
            $ids=(array)($ctx['order_ids']??$ctx['ids']??[]);$payload=(array)($ctx['payload']??[]);$courier=(string)($payload['courier']??'');
            return (new CourierService(Database::connection()))->bulkCreate($ids,$courier,$payload);
        },
    ];
}
$ordersActions['download_awb']=[
    'label'=>'Descarca AWB(uri)','icon'=>'⬇️','description'=>'Descarca toate etichetele AWB disponibile pentru comenzile selectate in format A4 sau A6.',
    'handler'=>static function(array $ctx):array{return ['ok'=>0,'errors'=>['Alege formatul A4 sau A6 din panoul Descarca AWB(uri).']];},
];
return [
    'actions'=>['orders'=>$ordersActions],
    'routes'=>[
        ['method'=>'POST','path'=>'/settings/modules/couriers/locality-search','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('settings.manage');header('Content-Type: application/json; charset=utf-8');
            try{
                $channel=(string)($_POST['channel']??'');$query=trim((string)($_POST['q']??''));
                if(!in_array($channel,['emag_ro','emag_bg'],true))throw new \RuntimeException('Canal eMAG invalid.');
                if($query===''||(function_exists('mb_strlen')?mb_strlen($query,'UTF-8'):strlen($query))<2)throw new \RuntimeException('Scrie cel putin 2 caractere din localitate.');
                $parts=array_map('trim',explode(',',$query,2));$filters=['name'=>$parts[0],'country'=>'RO','itemsPerPage'=>50,'currentPage'=>1];if(!empty($parts[1]))$filters['region2']=$parts[1];
                // The parcel sender is in Romania even for eMAG Bulgaria orders. The BG
                // Marketplace locality catalogue does not return Romanian cities, so resolve
                // the sender locality through the RO integration and keep the saved ID in the
                // separate BG sender settings.
                $lookupChannel=$channel==='emag_bg'?'emag_ro':$channel;
                $client=ChannelFactory::client($lookupChannel);if(!$client instanceof EmagClient)throw new \RuntimeException($channel==='emag_bg'?'Integrarea eMAG Romania trebuie sa fie activa pentru cautarea localitatii expeditorului din Romania.':'Integrarea eMAG nu este activa pentru acest canal.');
                $res=$client->readLocalities($filters);$out=[];foreach((array)($res['results']??[]) as $row){if(!is_array($row))continue;$id=(int)($row['emag_id']??$row['id']??0);if($id<=0)continue;$out[]=['id'=>$id,'name'=>(string)($row['name']??''),'county'=>(string)($row['region2']??$row['region1']??''),'country'=>(string)($row['country_code']??'RO')];}
                echo json_encode(['ok'=>true,'results'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }catch(\Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}exit;
        }],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/emag-awb$#','handler'=>static function(array $m):void{
            Csrf::verify();Auth::requirePermission('orders.manage');$result=(new EmagFulfillmentService(Database::connection()))->create((int)$m[1],$_POST);
            $msg='AWB eMAG generat: '.(string)($result['awb']??'');
            if(!empty($result['warning']))$msg.=' '.(string)$result['warning'];flash('success',$msg);redirect('/orders/'.$m[1]);
        }],
        ['method'=>'GET','path'=>'#^/orders/(\d+)/awb-pdf/(A4|A6)$#','handler'=>static function(array $m):void{
            Auth::requirePermission('orders.view');$order=(new \App\Services\OrderOperations())->getOrder((int)$m[1]);
            $pdf=(string)($order['channel_type']??'')==='emag'
                ? (new EmagFulfillmentService(Database::connection()))->awbPdf((int)$m[1],(string)$m[2])
                : (new CourierService(Database::connection()))->awbPdf((int)$m[1],(string)$m[2]);
            header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$pdf['filename']).'"');header('Content-Length: '.strlen((string)$pdf['body']));header('X-Content-Type-Options: nosniff');echo (string)$pdf['body'];exit;
        }],
        ['method'=>'GET','path'=>'#^/orders/(\d+)/awb-pdf/(\d+)/(A4|A6)$#','handler'=>static function(array $m):void{
            Auth::requirePermission('orders.view');$pdf=(new EmagFulfillmentService(Database::connection()))->awbPdfForShipment((int)$m[1],(int)$m[2],(string)$m[3]);
            header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$pdf['filename']).'"');header('Content-Length: '.strlen((string)$pdf['body']));header('X-Content-Type-Options: nosniff');echo (string)$pdf['body'];exit;
        }],
        ['method'=>'POST','path'=>'/orders/awbs-download','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('orders.view');if(!class_exists(\ZipArchive::class))throw new \RuntimeException('Extensia ZipArchive trebuie activata pe server pentru descarcarea bulk.');
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['order_ids']??[])),static fn($v)=>$v>0)));if(!$ids)throw new \RuntimeException('Selecteaza cel putin o comanda.');
            $format=strtoupper(trim((string)($_POST['awb_format']??'A4')));if(!in_array($format,['A4','A6'],true))throw new \RuntimeException('Format AWB invalid.');
            $tmp=tempnam(sys_get_temp_dir(),'alfamed-awb-');if($tmp===false)throw new \RuntimeException('Nu pot crea arhiva temporara.');$zipPath=$tmp.'.zip';@rename($tmp,$zipPath);
            $zip=new \ZipArchive();if($zip->open($zipPath,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Nu pot crea arhiva AWB.');
            $emagSvc=new EmagFulfillmentService(Database::connection());$directSvc=new CourierService(Database::connection());$ops=new \App\Services\OrderOperations();$added=0;$errors=[];
            foreach($ids as $id){
                try{
                    $order=$ops->getOrder($id);$type=(string)($order['channel_type']??'');$prefix=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$order['code']);
                    if($type==='emag'){
                        $bundle=$emagSvc->awbPdfs($id,$format);
                        foreach((array)$bundle['pdfs'] as $pdf){$name=$prefix.'-'.basename((string)$pdf['filename']);$zip->addFromString($name,(string)$pdf['body']);$added++;}
                        foreach((array)$bundle['errors'] as $err)$errors[]='#'.$id.': '.$err;
                    } elseif($type==='woocommerce'){
                        $pdf=$directSvc->awbPdf($id,$format);$name=$prefix.'-'.basename((string)$pdf['filename']);$zip->addFromString($name,(string)$pdf['body']);$added++;
                    } else throw new \RuntimeException('Canalul comenzii nu are descarcare AWB configurata.');
                }catch(\Throwable $e){$errors[]='#'.$id.': '.$e->getMessage();}
            }
            if($errors)$zip->addFromString('_ERORI.txt',implode("\r\n",$errors)."\r\n");$zip->close();
            if($added===0){@unlink($zipPath);throw new \RuntimeException('Nu exista AWB-uri eMAG descarcabile pentru comenzile selectate. '.implode(' | ',array_slice($errors,0,3)));}
            $name='AWB-uri-'.$format.'-'.date('Ymd-His').'.zip';header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.filesize($zipPath));header('X-Content-Type-Options: nosniff');readfile($zipPath);@unlink($zipPath);exit;
        }],
    ],
    'renderers'=>[
        'order.operations'=>static function(array $ctx) use ($configuredCouriers,$defaultCourier):void{
            if(!Auth::can('orders.manage'))return;$order=$ctx['order']??[];if(!$order)return;$db=Database::connection();
            $channelType=(string)($order['channel_type']??'');
            if($channelType==='emag'){
                $raw=(array)($order['raw']??[]);$svc=new EmagFulfillmentService($db);$deliveryMode=$svc->deliveryMode($order);$delivery=$svc->deliveryChoiceLabel($order);
                $shipQ=$db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('deleted','cancelled') ORDER BY id ASC");$shipQ->execute([(int)$order['id']]);$shipments=$shipQ->fetchAll();
                if((int)($raw['type']??3)===2){echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">📦</span><div><h3>Fulfilled by eMAG</h3><p>Logistica si AWB-ul acestei comenzi sunt administrate de eMAG.</p></div></div></div>';return;}
                if((string)($order['status']??'')==='new'&&!$shipments){echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">🚚</span><div><h3>AWB eMAG</h3><p>Preia mai intai comanda. Dupa acknowledge, generarea AWB devine disponibila.</p></div></div></div>';return;}

                $renderDialog=static function(array $order,string $deliveryMode,string $delivery,bool $additional=false):void{
                    $shipping=(array)($order['shipping']??[]);$billing=(array)($order['billing']??[]);$addr=array_filter($shipping,fn($v)=>$v!==''&&$v!==null)?$shipping:$billing;$raw=(array)($order['raw']??[]);$cod=(int)($raw['payment_mode_id']??0)===1?(float)$order['total']:0.0;
                    $dialog=($additional?'emag-awb-extra-':'emag-awb-dialog-').(int)$order['id'];
                    echo '<dialog class="emag-awb-dialog" id="'.View::e($dialog).'"><form method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/emag-awb')).'" class="emag-awb-form">'.Csrf::field();
                    if($additional)echo '<input type="hidden" name="additional_awb" value="1">';
                    echo '<input type="hidden" name="courier_account_id" value="0"><div class="emag-awb-modal-head"><div><small>eMAG '.View::e(strtoupper((string)($order['channel_country']??''))).'</small><h2>'.($additional?'AWB suplimentar':'Genereaza AWB').'</h2><p>'.View::e($order['code']).' · '.View::e($delivery).'</p></div><button type="button" class="dialog-close" onclick="this.closest(\'dialog\').close()">×</button></div>';
                    echo '<div class="emag-awb-summary"><div><span>Destinatar</span><b>'.View::e($order['customer_name']).'</b></div><div><span>Telefon</span><b>'.View::e($order['customer_phone']).'</b></div><div><span>Adresa</span><b>'.View::e(trim((string)($addr['address_1']??'')).', '.trim((string)($addr['city']??''))).'</b></div><div><span>Ramburs</span><b>'.number_format($cod,2,',',' ').' '.View::e($order['currency']).'</b></div>';
                    if(!empty($shipping['locker_name']))echo '<div class="wide"><span>Locker</span><b>'.View::e($shipping['locker_name']).'</b></div>';echo '</div>';
                    echo '<div class="emag-awb-grid">';
                    if($deliveryMode==='pickup'){
                        echo '<input type="hidden" name="parcels" value="1"><input type="hidden" name="envelopes" value="0"><div class="wide awb-logic-note"><b>Locker / easybox / FANbox</b><span>Se genereaza 1 AWB pentru 1 colet. Daca expedierea nu incape, poti crea ulterior un AWB suplimentar pentru aceeasi comanda.</span></div>';
                    } else {
                        echo '<label><span>Numar colete</span><input name="parcels" type="number" min="1" max="999" value="1" required></label><input type="hidden" name="envelopes" value="0"><div class="awb-logic-note"><b>Livrare la domiciliu</b><span>Toate coletele expeditiei sunt incluse in acelasi AWB.</span></div>';
                    }
                    echo '<label><span>Greutate totala (kg)</span><input name="weight" type="number" min="0.01" step="0.01" value="1" required></label><label><span>Valoare asigurata</span><input name="insured_value" type="number" min="0" step="0.01" value="0"></label><label class="wide checkbox-row"><input type="checkbox" name="is_oversize" value="1"><span>Colet agabaritic / oversize</span></label><label class="wide"><span>Observatii</span><textarea name="observation" rows="3" maxlength="255">ALFAMED CENTRAL '.View::e($order['code']).'</textarea></label></div><div class="emag-awb-modal-actions"><button type="button" onclick="this.closest(\'dialog\').close()">Renunta</button><button class="primary" type="submit">'.($additional?'Genereaza AWB suplimentar':'Genereaza AWB').'</button></div></form></dialog>';
                };

                if($shipments){
                    echo '<div class="module-box awb-order-box emag-shipment-card"><div class="awb-order-head"><span class="awb-order-icon">🚚</span><div><h3>Expedieri eMAG</h3><p>'.count($shipments).' AWB'.(count($shipments)===1?'':'-uri').' salvat'.(count($shipments)===1?'':'e').' pentru aceasta comanda.</p></div></div><div class="emag-shipment-list">';
                    foreach($shipments as $shipment){
                        $providerId=trim((string)($shipment['provider_awb_id']??''));$printable=$providerId!==''&&ctype_digit($providerId);
                        echo '<div class="emag-shipment-row"><div><small>AWB</small><strong>'.View::e((string)$shipment['awb']).'</strong><span>'.View::e((string)$shipment['courier']).'</span></div><div class="emag-shipment-actions">';
                        if($printable){echo '<a class="btn-link" href="'.View::e(app_path('/orders/'.(int)$order['id'].'/awb-pdf/'.(int)$shipment['id'].'/A4')).'">A4</a><a class="btn-link" href="'.View::e(app_path('/orders/'.(int)$order['id'].'/awb-pdf/'.(int)$shipment['id'].'/A6')).'">A6</a>';}else echo '<span class="muted">eticheta in Marketplace</span>';
                        echo '</div></div>';
                    }
                    echo '</div>';
                    if($deliveryMode==='pickup'){$dialog='emag-awb-extra-'.(int)$order['id'];echo '<div class="awb-card-footer"><span>Daca acest colet nu incape in locker, eMAG poate valida o expediere suplimentara pentru aceeasi comanda.</span><button class="button-secondary" type="button" onclick="document.getElementById(\''.View::e($dialog).'\').showModal()">+ AWB suplimentar</button></div>';$renderDialog($order,$deliveryMode,$delivery,true);}
                    echo '</div>';return;
                }
                if((string)($order['status']??'')==='completed')return;
                $missing=$svc->senderMissing((string)$order['channel_code']);if($missing){echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">⚙</span><div><h3>Configureaza expeditorul eMAG</h3><p>Lipsesc: '.View::e(implode(', ',$missing)).'.</p></div></div><a class="btn-link" href="'.View::e(app_path('/settings/modules/couriers')).'">Deschide setarile Curieri</a></div>';return;}
                $dialog='emag-awb-dialog-'.(int)$order['id'];echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">🚚</span><div><h3>AWB eMAG</h3><p>'.View::e($delivery).'. Datele clientului, rambursul si lockerul sunt preluate automat din comanda.</p></div></div><button class="primary awb-generate-button" type="button" onclick="document.getElementById(\''.View::e($dialog).'\').showModal()">🚚 Genereaza AWB eMAG</button></div>';$renderDialog($order,$deliveryMode,$delivery,false);return;
            }

            $activeAwb=$db->prepare("SELECT * FROM shipments WHERE order_id=? AND status NOT IN ('deleted','cancelled') ORDER BY id DESC LIMIT 1");$activeAwb->execute([(int)$order['id']]);$existingAwb=$activeAwb->fetch();
            if($existingAwb){$courierKey=strtolower((string)($existingAwb['courier']??''));$directPrintable=in_array($courierKey,['sameday','fan'],true);echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">🚚</span><div><h3>AWB activ</h3><p>'.View::e($existingAwb['awb']).' · '.View::e($existingAwb['courier']).'</p></div></div>';if($directPrintable)echo '<div class="awb-download-actions"><a class="btn-link" href="'.View::e(app_path('/orders/'.$order['id'].'/awb-pdf/A4')).'">Descarca A4</a><a class="btn-link" href="'.View::e(app_path('/orders/'.$order['id'].'/awb-pdf/A6')).'">Descarca A6</a></div>';echo '</div>';return;}
            if(!$configuredCouriers)return;$options='';foreach($configuredCouriers as $code=>$label){$selected=$code===$defaultCourier?' selected':'';$options.='<option value="'.View::e($code).'"'.$selected.'>'.View::e($label).'</option>';}
            echo '<div class="module-box awb-order-box"><div class="awb-order-head"><span class="awb-order-icon">🚚</span><div><h3>Genereaza AWB</h3><p>WooCommerce foloseste integrarile directe de curier. Rambursul se calculeaza automat din metoda de plata.</p></div></div><form method="post" action="'.View::e(app_path('/orders/actions')).'" class="awb-create-form">'.Csrf::field().'<input type="hidden" name="order_ids[]" value="'.(int)$order['id'].'"><input type="hidden" name="action" value="generate_awb"><div class="awb-order-grid"><label><span>Curier</span><select name="courier" required>'.$options.'</select></label><label><span>Numar colete</span><input type="number" min="1" step="1" name="parcels" value="1" required></label><label><span>Greutate totala</span><div class="input-suffix"><input type="number" min="0.1" step="0.1" name="weight" placeholder="implicit"><span>kg</span></div></label></div><div class="awb-auto-cod"><b>Ramburs automat</b><span>Numerar la livrare = total comanda · card / plata online = 0</span></div><button class="primary awb-generate-button" type="submit">🚚 Genereaza AWB</button></form></div>';
        },
    ],
    'settings'=>[courierSettings()],
];

function courierSettings(): array {
    $senderFields=[];
    foreach(['emag_ro'=>'eMAG Romania','emag_bg'=>'eMAG Bulgaria'] as $prefix=>$label){
        $senderFields=array_merge($senderFields,[
            ['key'=>'section_'.$prefix.'_sender','label'=>$label.' - expeditor AWB','type'=>'heading'],
            ['key'=>$prefix.'_sender_name','label'=>'Nume expeditor','type'=>'text','default'=>'ALFAMED CLINIC SRL'],
            ['key'=>$prefix.'_sender_contact','label'=>'Persoana de contact','type'=>'text','default'=>''],
            ['key'=>$prefix.'_sender_phone','label'=>'Telefon expeditor','type'=>'text','default'=>''],
            ['key'=>$prefix.'_sender_locality_id','label'=>'Localitate expeditor eMAG','type'=>'emag_locality','channel'=>$prefix,'default'=>'','help'=>'Cauta localitatea expeditorului din Romania; poti scrie de exemplu Sendreni, Galati. Inclusiv pentru eMAG Bulgaria, cautarea foloseste tara RO deoarece coletul pleaca din Romania.'],
            ['key'=>$prefix.'_sender_street','label'=>'Adresa expeditor','type'=>'text','default'=>''],
            ['key'=>$prefix.'_sender_zipcode','label'=>'Cod postal expeditor','type'=>'text','default'=>''],
        ]);
    }
    return [
        'title'=>'Curieri - WooCommerce + eMAG Marketplace',
        'description'=>'WooCommerce foloseste integrarile directe Sameday/FAN/Dragon Star. Comenzile eMAG RO/BG folosesc conturile de curier si AWB-ul din eMAG Marketplace.',
        'fields'=>array_merge([
            ['key'=>'default_weight','label'=>'Greutate implicita (kg)','type'=>'number','step'=>'0.1','min'=>0.1,'default'=>'1'],
            ['key'=>'default_length','label'=>'Lungime implicita (cm)','type'=>'number','min'=>1,'default'=>'20'],
            ['key'=>'default_width','label'=>'Latime implicita (cm)','type'=>'number','min'=>1,'default'=>'15'],
            ['key'=>'default_height','label'=>'Inaltime implicita (cm)','type'=>'number','min'=>1,'default'=>'10'],
            ['key'=>'default_woo_courier','label'=>'Curier implicit WooCommerce (fallback)','type'=>'select','default'=>'','options'=>[''=>'Automat din metoda de livrare','sameday'=>'Sameday','fan'=>'FAN Courier','dragonstar'=>'Dragon Star'],'help'=>'Este folosit doar cand metoda WooCommerce nu indica clar un curier si sunt configurati mai multi curieri.'],
        ],$senderFields,[
            ['key'=>'section_sameday','label'=>'WooCommerce - Sameday','type'=>'heading'],
            ['key'=>'sameday_base_url','label'=>'API Base URL','type'=>'text','default'=>'https://api.sameday.ro'],
            ['key'=>'sameday_username','label'=>'Utilizator API','type'=>'text','default'=>''],['key'=>'sameday_password','label'=>'Parola API','type'=>'password','default'=>''],
            ['key'=>'sameday_pickup_point','label'=>'Pickup Point ID','type'=>'number','min'=>0,'default'=>'0'],['key'=>'sameday_contact_person','label'=>'Contact Person ID (optional)','type'=>'number','min'=>0,'default'=>'0'],['key'=>'sameday_service_id','label'=>'Service ID','type'=>'number','min'=>0,'default'=>'0'],['key'=>'sameday_tracking_url','label'=>'URL tracking client','type'=>'text','default'=>'https://sameday.ro/status-colet/','help'=>'Poti folosi {{awb}} daca ai un URL direct de tracking care accepta AWB-ul.'],
            ['key'=>'section_fan','label'=>'WooCommerce - FAN Courier','type'=>'heading'],
            ['key'=>'fan_base_url','label'=>'API Base URL','type'=>'text','default'=>'https://api.fancourier.ro'],['key'=>'fan_username','label'=>'Utilizator SelfAWB','type'=>'text','default'=>''],['key'=>'fan_password','label'=>'Parola SelfAWB','type'=>'password','default'=>''],['key'=>'fan_client_id','label'=>'Client ID / sucursala','type'=>'text','default'=>''],['key'=>'fan_service','label'=>'Serviciu','type'=>'text','default'=>'Auto','help'=>'Auto foloseste Standard fara ramburs si Cont Colector cand exista ramburs.'],['key'=>'fan_payment','label'=>'Platitor transport','type'=>'select','default'=>'sender','options'=>['sender'=>'Expeditor','recipient'=>'Destinatar','Other'=>'Altul']],['key'=>'fan_bank','label'=>'Banca pentru ramburs (optional)','type'=>'text','default'=>''],['key'=>'fan_iban','label'=>'IBAN pentru ramburs (optional)','type'=>'text','default'=>''],['key'=>'fan_cost_center','label'=>'Centru cost (optional)','type'=>'text','default'=>''],['key'=>'fan_options','label'=>'Optiuni FAN separate prin virgula','type'=>'text','default'=>''],['key'=>'fan_tracking_url','label'=>'URL tracking client','type'=>'text','default'=>'https://www.fancourier.ro/awb-tracking/','help'=>'Poti folosi {{awb}} daca ai un URL direct.'],
            ['key'=>'section_dragon','label'=>'WooCommerce - Dragon Star','type'=>'heading'],
            ['key'=>'dragonstar_base_url','label'=>'API Base URL primit de la Dragon Star','type'=>'text','default'=>''],['key'=>'dragonstar_awb_path','label'=>'Endpoint creare AWB','type'=>'text','default'=>''],['key'=>'dragonstar_username','label'=>'Utilizator API','type'=>'text','default'=>''],['key'=>'dragonstar_password','label'=>'Parola API','type'=>'password','default'=>''],['key'=>'dragonstar_api_token','label'=>'Token API','type'=>'password','default'=>''],['key'=>'dragonstar_auth_header','label'=>'Header autentificare token','type'=>'text','default'=>'Authorization'],['key'=>'dragonstar_auth_prefix','label'=>'Prefix token','type'=>'text','default'=>'Bearer '],['key'=>'dragonstar_awb_response_path','label'=>'Cale AWB in raspuns','type'=>'text','default'=>'awb'],['key'=>'dragonstar_payload_template','label'=>'Sablon JSON creare AWB','type'=>'textarea','default'=>''],['key'=>'dragonstar_tracking_url','label'=>'URL tracking client','type'=>'text','default'=>'https://dragonstarcurier.ro/tracking-awb'],
        ]),
    ];
}
