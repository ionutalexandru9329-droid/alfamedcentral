<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\View;
use App\Services\SettingService;
use Modules\InvoicesDocuments\InvoiceService;

require_once __DIR__.'/src/InvoiceService.php';

return [
    'nav'=>[['label'=>'Facturi','url'=>'/invoices','permission'=>'invoices.view']],
    'listeners'=>[
        'autosync.channel.completed'=>static function(array $ctx):void{
            try{(new InvoiceService())->maybeAutoSyncCatalogue();}catch(\Throwable){}
        },
    ],
    'actions'=>[
        'orders'=>[
            'create_invoice'=>['label'=>'Creeaza factura','icon'=>'🧾','available'=>static fn()=>(new InvoiceService())->isConfigured(),'handler'=>static fn(array $ctx)=>(new InvoiceService())->bulkCreate((array)($ctx['order_ids']??$ctx['ids']??[]),'invoice')],
            'create_storno'=>['label'=>'Creeaza storno Oblio','icon'=>'↩️','available'=>static fn()=>(new InvoiceService())->isConfigured(),'handler'=>static fn(array $ctx)=>(new InvoiceService())->bulkStorno((array)($ctx['order_ids']??$ctx['ids']??[]))],
            'create_proforma'=>['label'=>'Creeaza proforma','icon'=>'📄','available'=>static fn()=>(new InvoiceService())->isConfigured(),'handler'=>static fn(array $ctx)=>(new InvoiceService())->bulkCreate((array)($ctx['order_ids']??$ctx['ids']??[]),'proforma')],
            'create_notice'=>['label'=>'Creeaza aviz','icon'=>'📋','available'=>static fn()=>(new InvoiceService())->isConfigured(),'handler'=>static fn(array $ctx)=>(new InvoiceService())->bulkCreate((array)($ctx['order_ids']??$ctx['ids']??[]),'notice')],
        ],
    ],
    'routes'=>[
        ['method'=>'GET','path'=>'#^/customer-document/([a-f0-9]{48})$#','public'=>true,'handler'=>static function(array $m):void{
            $file=(new InvoiceService())->documentPath((string)$m[1]);if(!$file){http_response_code(404);echo 'Document inexistent.';return;}
            header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="factura.pdf"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');readfile($file);
        }],
        ['method'=>'GET','path'=>'/invoices','handler'=>static function():void{
            Auth::requirePermission('invoices.view');$db=Database::connection();
            $invoices=$db->query("SELECT i.*,o.code,o.customer_name,c.name channel_name FROM invoices i JOIN orders o ON o.id=i.order_id JOIN channels c ON c.id=o.channel_id WHERE i.status<>'hidden' ORDER BY i.id DESC LIMIT 500")->fetchAll();
            View::render('invoices',compact('invoices')+['title'=>'Facturi']);
        }],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/(invoice|proforma|notice)$#','handler'=>static function(array $m):void{
            Csrf::verify();$labels=['invoice'=>'Factura','proforma'=>'Proforma','notice'=>'Aviz'];$service=new InvoiceService();
            try{$result=$service->createDocument((int)$m[1],(string)$m[2]);$warnings=(array)($result['_alfamed']['warnings']??[]);$message=$labels[$m[2]].' a fost emis(a) in Oblio si salvata in ALFAMED CENTRAL.';if($m[2]==='invoice'&&!$warnings)$message.=' Documentul a fost sincronizat cu canalul comenzii.';if($warnings)$message.=' Atentie: '.implode(' | ',array_slice($warnings,0,2));flash($warnings?'error':'success',$message);}
            catch(\Throwable $e){$service->logFailure((int)$m[1],(string)$m[2],$e);flash('error','Oblio: '.$service->safeMessage($e->getMessage()));}
            redirect('/orders/'.$m[1]);
        }],
        ['method'=>'POST','path'=>'/settings/modules/invoices-documents/test','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('settings.manage');$service=new InvoiceService();
            try{$r=$service->diagnose();$series=implode(', ',(array)($r['invoice_series']??[]));$locations=implode(', ',(array)($r['stock_locations']??[]));$ctx=(array)($r['stock_context']??[]);$selected=!empty($ctx['enabled'])?trim(((string)($ctx['workStation']??'')!==''?(string)$ctx['workStation'].' / ':'').(string)($ctx['management']??'')):'stocuri inactive';flash('success','Conexiune Oblio OK. Serii factura: '.($series!==''?$series:'—').'. Gestiune folosita: '.$selected.'. Disponibile: '.($locations!==''?$locations:'fara gestiuni').'.');}
            catch(\Throwable $e){$service->logFailure(0,'api_test',$e);flash('error','Oblio: '.$service->safeMessage($e->getMessage()));}
            redirect('/settings/modules/invoices-documents');
        }],
        ['method'=>'POST','path'=>'/settings/modules/invoices-documents/catalogue-sync','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('settings.manage');$service=new InvoiceService();
            try{$r=$service->syncOblioCatalogue(true);if(!empty($r['skipped']))flash('success','Sincronizarea nomenclatorului Oblio ruleaza deja.');else{$auto=(array)($r['auto_mappings']??[]);flash('success','Nomenclator Oblio sincronizat: '.(int)($r['count']??0).' produse · '.(int)($r['created']??0).' noi · '.(int)($r['updated']??0).' actualizate · '.(int)($auto['mapped']??0).' echivalari automate actualizate din '.(int)($auto['source_products']??0).' produse de canal analizate.');}}
            catch(\Throwable $e){$service->logFailure(0,'catalogue_sync',$e);flash('error','Oblio: '.$service->safeMessage($e->getMessage()));}
            redirect('/settings/modules/invoices-documents');
        }],
        ['method'=>'POST','path'=>'/settings/modules/invoices-documents/product-search','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('settings.manage');header('Content-Type: application/json; charset=utf-8');
            try{$q=trim((string)($_POST['q']??''));if(strlen($q)<1)throw new \RuntimeException('Scrie un cod sau o denumire.');$rows=(new InvoiceService())->searchOblioProducts($q);echo json_encode(['ok'=>true,'results'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
            catch(\Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}exit;
        }],
        ['method'=>'POST','path'=>'/settings/modules/invoices-documents/product-mapping','handler'=>static function():void{
            Csrf::verify();Auth::requirePermission('settings.manage');(new InvoiceService())->saveMapping((string)($_POST['channel_code']??''),(string)($_POST['source_code']??''),(string)($_POST['oblio_code']??''),(string)($_POST['oblio_name']??''));flash('success','Echivalarea codului a fost salvata.');redirect('/settings/modules/invoices-documents');
        }],
        ['method'=>'POST','path'=>'#^/settings/modules/invoices-documents/product-mapping/(\d+)/delete$#','handler'=>static function(array $m):void{
            Csrf::verify();Auth::requirePermission('settings.manage');(new InvoiceService())->deleteMapping((int)$m[1]);flash('success','Echivalarea codului a fost stearsa.');redirect('/settings/modules/invoices-documents');
        }],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/storno-auto$#','handler'=>static function(array $m):void{Csrf::verify();$r=(new InvoiceService())->stornoLatest((int)$m[1]);$warnings=(array)($r['_alfamed']['warnings']??[]);flash($warnings?'error':'success','Factura storno a fost emisa in Oblio si sincronizata cu canalul comenzii.'.($warnings?' '.implode(' | ',array_slice($warnings,0,2)):''));redirect('/orders/'.$m[1]);}],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/storno/(\d+)$#','handler'=>static function(array $m):void{Csrf::verify();(new InvoiceService())->storno((int)$m[1],(int)$m[2]);flash('success','Factura storno a fost emisa.');redirect('/orders/'.$m[1]);}],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/invoice-sync/(\d+)$#','handler'=>static function(array $m):void{Csrf::verify();(new InvoiceService())->resyncInvoice((int)$m[1],(int)$m[2]);flash('success','Factura a fost retrimisa catre canalul comenzii.');redirect('/orders/'.$m[1]);}],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/invoice-upload$#','handler'=>static function(array $m):void{
            Csrf::verify();$send=isset($_POST['send_channel']);$r=(new InvoiceService())->upload((int)$m[1],$_FILES['invoice_pdf']??[],'','',$send);$warnings=(array)($r['warnings']??[]);$msg='Factura PDF a fost incarcata.'.($send?' Sincronizarea cu canalul a fost solicitata.':'');if($warnings)$msg.=' '.implode(' | ',array_slice($warnings,0,2));flash($warnings?'error':'success',$msg);redirect('/orders/'.$m[1]);
        }],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/invoice-delete/(\d+)$#','handler'=>static function(array $m):void{Csrf::verify();(new InvoiceService())->deleteUploaded((int)$m[1],(int)$m[2]);flash('success','Factura incarcata local a fost stearsa.');redirect('/orders/'.$m[1]);}],
        ['method'=>'POST','path'=>'#^/orders/(\d+)/marketplace-invoice-hide/(\d+)$#','handler'=>static function(array $m):void{Csrf::verify();(new InvoiceService())->hideMarketplaceAttachment((int)$m[1],(int)$m[2]);flash('success','Documentul Marketplace a fost ascuns din ALFAMED CENTRAL. Fisierul original ramane in Marketplace.');redirect('/orders/'.$m[1]);}],
    ],
    'renderers'=>[
        'order.operations'=>static function(array $ctx):void{
            if(!Auth::can('orders.manage'))return;$order=$ctx['order']??[];if(!$order)return;$service=new InvoiceService();
            echo '<div class="module-box oblio-quick-card"><div class="oblio-quick-head"><span class="oblio-quick-icon">🧾</span><div><h3>Documente Oblio</h3><p>Emite documentul potrivit direct pentru aceasta comanda.</p></div></div>';
            if(!$service->isConfigured()){echo '<p class="muted">Modulul Oblio nu este configurat complet.</p>';if(Auth::can('settings.manage'))echo '<a class="btn-link" href="'.View::e(app_path('/settings/modules/invoices-documents')).'">Configureaza Oblio</a>';echo '</div>';return;}
            $buttons=[
                'invoice'=>['icon'=>'🧾','label'=>'Factura','hint'=>'Factura fiscala'],
                'proforma'=>['icon'=>'📄','label'=>'Proforma','hint'=>'Document proforma'],
                'notice'=>['icon'=>'📋','label'=>'Aviz','hint'=>'Aviz de insotire'],
            ];
            echo '<div class="oblio-action-grid">';
            foreach($buttons as $type=>$meta){echo '<form method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/'.$type)).'">'.Csrf::field().'<button class="oblio-action-tile" type="submit"><span class="oblio-action-icon">'.View::e($meta['icon']).'</span><span><b>'.View::e($meta['label']).'</b><small>'.View::e($meta['hint']).'</small></span></button></form>';}
            if($service->canStornoOrder((int)$order['id']))echo '<form method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/storno-auto')).'" onsubmit="return confirm(\'Emiti storno total pentru factura Oblio a acestei comenzi? Produsele vor fi repuse in gestiune daca stocurile Oblio sunt active.\')">'.Csrf::field().'<button class="oblio-action-tile storno" type="submit"><span class="oblio-action-icon">↩️</span><span><b>Storno</b><small>Storneaza factura originala</small></span></button></form>';
            echo '</div></div>';
        },
        'order.after'=>static function(array $ctx):void{
            $order=$ctx['order']??[];if(!$order)return;$service=new InvoiceService();$invoices=$service->listForOrder((int)$order['id']);$settings=new SettingService();$sendDefault=$settings->bool('module.invoices-documents.send_customer_default',true);$channelType=(string)($order['channel_type']??'');
            echo '<section class="panel"><div class="panel-title-row"><div><h2>Facturi / documente</h2><p class="muted">Documente Oblio, PDF-uri locale si facturile/proformele deja atasate comenzii eMAG.</p></div></div>';
            if(!$invoices)echo '<p>Niciun document.</p>';
            foreach($invoices as $inv){
                $meta=json_decode((string)($inv['raw_json']??'{}'),true)?:[];$agent=trim((string)($meta['agent']??''));$sync=(array)($meta['channel_sync']??[]);$syncState=(string)($sync['state']??'');
                $isMarketplace=(string)$inv['type']==='marketplace';$label=$isMarketplace?'FACTURA MARKETPLACE':trim(strtoupper((string)$inv['type']).' '.(string)$inv['series'].' '.(string)$inv['number']);
                if($isMarketplace){$a=(array)($meta['marketplace_attachment']??[]);$label=((int)($a['type']??1)===11?'PROFORMA MARKETPLACE':'FACTURA MARKETPLACE').' '.trim((string)($a['name']??''));}
                echo '<div class="rowline document-row"><span><b>'.View::e($label?:'Document').'</b> · '.View::e($inv['status']);
                if($agent!=='')echo ' · Agent: <b>'.View::e($agent).'</b>';
                if(in_array((string)$inv['type'],['invoice','storno'],true)&&$syncState==='sent')echo ' · <span class="tag">sincronizata '.View::e((string)($sync['channel']??'canal')).'</span>';
                if(in_array((string)$inv['type'],['invoice','storno','uploaded'],true)&&$syncState==='error')echo ' · <span class="tag danger">sincronizare esuata</span>';
                if($isMarketplace)echo ' · <span class="tag">preluata din eMAG</span>';
                echo '</span><span class="actions">';
                if(!empty($inv['link']))echo '<a class="btn-link" target="_blank" rel="noopener" href="'.View::e($inv['link']).'">Descarca / deschide</a>';
                if(in_array((string)$inv['type'],['invoice','storno'],true)&&$syncState==='error')echo '<form class="inline" method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/invoice-sync/'.$inv['id'])).'">'.Csrf::field().'<button class="small">Retrimite la canal</button></form>';
                if((string)$inv['type']==='invoice'&&$service->canStornoOrder((int)$order['id']))echo '<form class="inline" method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/storno/'.$inv['id'])).'" onsubmit="return confirm(\'Emiti storno total? Produsele vor fi repuse in gestiune daca stocurile Oblio sunt active.\')">'.Csrf::field().'<button class="small danger-outline">Storneaza</button></form>';
                if((string)$inv['type']==='uploaded')echo '<form class="inline" method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/invoice-delete/'.$inv['id'])).'" onsubmit="return confirm(\'Stergi copia locala a facturii PDF?\')">'.Csrf::field().'<button class="small danger-outline">Sterge</button></form>';
                if($isMarketplace)echo '<form class="inline" method="post" action="'.View::e(app_path('/orders/'.$order['id'].'/marketplace-invoice-hide/'.$inv['id'])).'" onsubmit="return confirm(\'Ascunzi documentul din ALFAMED CENTRAL? Documentul original nu va fi sters din Marketplace.\')">'.Csrf::field().'<button class="small danger-outline">Sterge din lista</button></form>';
                echo '</span></div>';
            }
            echo '<details class="upload-details invoice-upload-details"><summary><span class="invoice-upload-summary-icon">＋</span><span>Incarca alta factura PDF</span></summary><form method="post" enctype="multipart/form-data" action="'.View::e(app_path('/orders/'.$order['id'].'/invoice-upload')).'" class="invoice-upload-form">'.Csrf::field();
            echo '<label class="invoice-upload-file"><span>Factura PDF</span><input type="file" name="invoice_pdf" accept="application/pdf,.pdf" required><small>Alege un fisier PDF de pe dispozitiv.</small></label>';
            $label=$channelType==='emag'?'Trimite automat si in eMAG Marketplace':'Ataseaza automat clientului WooCommerce';
            $help=$channelType==='emag'?'Documentele deja existente sunt preluate automat din Marketplace. Originalul se administreaza in eMAG.':'PDF-ul poate fi atasat automat clientului in magazinul WooCommerce.';
            echo '<label class="invoice-upload-sync"><span class="invoice-upload-sync-top"><input type="checkbox" name="send_channel" '.($sendDefault?'checked':'').'><b>'.View::e($label).'</b></span><small>'.View::e($help).'</small></label>';
            echo '<div class="invoice-upload-actions"><button class="primary" type="submit">Incarca factura</button></div></form></details></section>';
        },
    ],
    'settings'=>[[
        'title'=>'Conectare Oblio',
        'description'=>'Credentialele Oblio sunt administrate exclusiv aici. Valorile vechi din .env raman fallback pentru upgrade, dar nu mai exista o integrare Oblio separata.',
        'fields'=>[
            ['key'=>'oblio_enabled','label'=>'Activeaza emiterea prin Oblio','type'=>'checkbox','default'=>Env::bool('OBLIO_ENABLED',false)],
            ['key'=>'heading_auth','label'=>'Credentiale API','type'=>'heading'],
            ['key'=>'client_id','label'=>'Client ID / email','type'=>'text','default'=>(string)Env::get('OBLIO_CLIENT_ID','')],
            ['key'=>'client_secret','label'=>'Client Secret','type'=>'password','default'=>'','help'=>'Lasa gol pentru a pastra secretul deja salvat. Daca nu exista in baza, se foloseste temporar valoarea veche din .env.'],
            ['key'=>'cif','label'=>'CIF emitent','type'=>'text','default'=>(string)Env::get('OBLIO_CIF','')],
            ['key'=>'heading_docs','label'=>'Documente si gestiune','type'=>'heading'],
            ['key'=>'invoice_series','label'=>'Serie factura','type'=>'text','default'=>(string)Env::get('OBLIO_SERIES','FCT')],
            ['key'=>'proforma_series','label'=>'Serie proforma','type'=>'text','default'=>'PR'],
            ['key'=>'notice_series','label'=>'Serie aviz','type'=>'text','default'=>'AV'],
            ['key'=>'management','label'=>'Gestiune (optional)','type'=>'text','default'=>(string)Env::get('OBLIO_MANAGEMENT',''),'help'=>'Lasa gol daca nu folosesti stocurile in Oblio. Daca stocurile sunt active, completeaza exact numele gestiunii din Oblio.'],
            ['key'=>'work_station','label'=>'Punct de lucru (optional)','type'=>'text','default'=>(string)Env::get('OBLIO_WORKSTATION',''),'help'=>'Ex: Sediu sau Depozit. Daca gestiunea exista intr-un singur punct de lucru, ALFAMED CENTRAL il detecteaza automat. Completeaza-l explicit daca aceeasi gestiune exista in mai multe puncte de lucru.'],
            ['key'=>'issuer_name','label'=>'Nume emitent','type'=>'text','default'=>(string)Env::get('OBLIO_ISSUER_NAME','ALFAMED CLINIC SRL')],
            ['key'=>'heading_catalogue','label'=>'Nomenclator produse','type'=>'heading'],
            ['key'=>'catalogue_auto_sync','label'=>'Sincronizeaza automat nomenclatorul Oblio','type'=>'checkbox','default'=>'true','help'=>'Ruleaza in fundal prin CRON si actualizeaza cache-ul local fara sa incetineasca emiterea facturilor.'],
            ['key'=>'catalogue_sync_hours','label'=>'Interval sincronizare nomenclator (ore)','type'=>'number','min'=>1,'max'=>168,'default'=>'12','help'=>'Recomandat: 12 ore. Poti forta oricand o sincronizare din butonul dedicat de mai jos.'],
            ['key'=>'heading_agent','label'=>'Agent','type'=>'heading'],
            ['key'=>'agent_current_user','label'=>'Agent = utilizatorul autentificat','type'=>'checkbox','default'=>'true','help'=>'La emitere, numele utilizatorului ALFAMED CENTRAL care factureaza este trimis in campul Agent vanzari din Oblio.'],
            ['key'=>'agent_default','label'=>'Agent implicit / fallback','type'=>'text','default'=>'','help'=>'Se foloseste daca optiunea de mai sus este dezactivata sau utilizatorul nu are nume configurat.'],
            ['key'=>'heading_upload','label'=>'PDF si sincronizare canal','type'=>'heading'],
            ['key'=>'max_upload_mb','label'=>'Dimensiune maxima PDF (MB)','type'=>'number','min'=>1,'max'=>25,'default'=>'8'],
            ['key'=>'send_customer_default','label'=>'Sincronizeaza implicit PDF-ul cu canalul comenzii','type'=>'checkbox','default'=>'true'],
        ],
    ]],
];
