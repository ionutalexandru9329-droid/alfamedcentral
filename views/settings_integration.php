<?php use App\Core\Csrf; use App\Core\Env; use App\Core\View;
$icons=['emag_ro'=>'🇷🇴','emag_bg'=>'🇧🇬','univera'=>'U','alfamed'=>'A'];
$isEnabled=Env::bool($integration['enabled']);
?>
<div class="settings-detail-shell">
    <div class="settings-detail-hero">
        <div class="settings-detail-title">
            <a class="settings-back" href="<?=View::e(app_path('/settings'))?>&tab=integrations">← Integrari</a>
            <div class="settings-detail-title-row"><span class="settings-detail-icon"><?=View::e($icons[$key]??'↔')?></span><div><div class="eyebrow">INTEGRARE CANAL</div><h1><?=View::e($integration['title'])?></h1><p><?=View::e($integration['description'])?></p></div></div>
        </div>
        <span class="integration-state large <?=$isEnabled?'on':'off'?>"><?=$isEnabled?'ACTIV':'INACTIV'?></span>
    </div>

    <section class="panel settings-pro-section settings-detail-card">
        <form method="post" action="<?=View::e(app_path('/settings/integrations/'.$key))?>">
            <?=Csrf::field()?>
            <div class="settings-pro-section-head with-toggle"><span class="settings-section-icon">⌁</span><div><h2>Conectare API</h2><p>Credentialele sensibile nu sunt afisate. Lasa parola/secretul gol pentru a pastra valoarea existenta.</p></div><label class="toggle"><input type="checkbox" name="enabled" <?=$isEnabled?'checked':''?>><span>Activ</span></label></div>
            <div class="settings-grid two integration-fields-pro">
                <?php foreach($integration['fields'] as $field): $type=$field['type']??'text';$secret=!empty($field['secret']);?>
                <label><?=View::e($field['label'])?><input type="<?=View::e($type)?>" name="<?=View::e($field['key'])?>" value="<?=$secret?'':View::e(Env::get($field['key'],$field['default']??''))?>" <?=$secret?'placeholder="Lasa gol pentru a pastra valoarea existenta"':''?>><?php if(!empty($field['help'])):?><small><?=View::e($field['help'])?></small><?php endif;?></label>
                <?php endforeach;?>
            </div>
            <div class="settings-actions"><button class="primary">Salveaza configurarea</button></div>
        </form>
    </section>

    <?php if(in_array($key,['emag_ro','emag_bg'],true)): $d=$emagDiagnostic??null; $diagOk=!empty($d['ok']);
        $diagLabels=['ok'=>'Conexiune OK','config_missing'=>'Configurare incompleta','ip_not_allowed'=>'IP neautorizat','auth_failed'=>'Autentificare respinsa','api_rights_denied'=>'Acces API refuzat','access_denied'=>'Acces refuzat','endpoint_invalid'=>'Endpoint invalid','endpoint_country_mismatch'=>'Endpoint tara gresit','network_error'=>'Eroare retea','emag_unavailable'=>'eMAG indisponibil','api_error'=>'Eroare API','http_error'=>'Eroare HTTP'];
        $diagCode=(string)($d['code']??''); ?>
    <section class="panel settings-pro-section settings-detail-card emag-diagnostic-card">
        <div class="settings-pro-section-head emag-diagnostic-head">
            <span class="settings-section-icon">⌁</span>
            <div><h2>Diagnostic eMAG API</h2><p>Verifica separat endpoint-ul, autentificarea, drepturile API si whitelist-ul IP. Testul este read-only si cere cel mult o comanda prin <code>order/read</code>.</p></div>
            <?php if($d):?><span class="emag-diag-status <?=$diagOk?'ok':'bad'?>"><?=View::e($diagLabels[$diagCode]??strtoupper($diagCode?:'NECUNOSCUT'))?></span><?php endif;?>
        </div>

        <div class="emag-diagnostic-grid">
            <div class="emag-diag-item wide"><span>Endpoint API</span><strong><?=View::e($emagEndpoint?:'Nedefinit')?></strong></div>
            <div class="emag-diag-item"><span>Tara endpoint</span><strong><?=!empty($d['endpoint_country_ok'])?'Corecta':'De verificat'?></strong></div>
            <div class="emag-diag-item"><span>IP public server</span><strong><?=View::e((string)($d['public_ip']??'' ) ?: 'Se detecteaza la test')?></strong></div>
            <div class="emag-diag-item"><span>Credentiale</span><strong><?=!empty($d['username_configured'])&&!empty($d['password_configured'])?'Salvate':'Incomplete'?></strong></div>
            <div class="emag-diag-item"><span>API Code</span><strong><?=!empty($d['api_code_configured'])?'Salvat (informativ)':'Nesalvat'?></strong></div>
            <div class="emag-diag-item"><span>HTTP</span><strong><?=!empty($d['http_status'])?(int)$d['http_status']:'—'?></strong></div>
            <div class="emag-diag-item"><span>Ultimul test</span><strong><?=View::e((string)($d['tested_at']??'Nu a fost testat'))?></strong></div>
            <div class="emag-diag-item"><span>Timp raspuns</span><strong><?=isset($d['network']['duration_ms'])&&$d['network']['duration_ms']!==0?(int)$d['network']['duration_ms'].' ms':'—'?></strong></div>
        </div>

        <?php if($d):?>
        <div class="emag-diagnostic-result <?=$diagOk?'ok':'bad'?>">
            <div class="emag-diagnostic-result-icon"><?=$diagOk?'✓':'!'?></div>
            <div>
                <strong><?=View::e((string)($d['message']??''))?></strong>
                <?php if(!empty($d['api_message'])):?><p><b>Mesaj eMAG:</b> <?=View::e((string)$d['api_message'])?></p><?php endif;?>
                <?php if($diagCode==='api_rights_denied'):?><p>Raspunsul eMAG <b>"You are not allowed to use this API"</b> nu separa sigur credentialele gresite de drepturile API lipsa. Verifica mai intai username/parola, apoi drepturile din <b>My Account → Profile → Permissions / Technical details</b>. Login-ul normal in Marketplace nu garanteaza acces API.</p><?php endif;?>
                <?php if($diagCode==='endpoint_country_mismatch'):?><p>Endpoint-ul configurat nu corespunde tarii acestei integrari. Pentru Romania trebuie domeniul <b>marketplace-api.emag.ro</b>, iar pentru Bulgaria <b>marketplace-api.emag.bg</b>.</p><?php endif;?>
                <?php if($diagCode==='ip_not_allowed'):?><p>Adauga in whitelist IP-ul public afisat mai sus. Pe hosting shared, acesta poate fi diferit de IP-ul afisat in cPanel ca Shared IP.</p><?php endif;?>
                <?php if($diagCode==='auth_failed'):?><p>Verifica utilizatorul si parola API salvate pentru aceasta tara. Credentialele eMAG Romania si eMAG Bulgaria trebuie validate separat.</p><?php endif;?>
            </div>
        </div>
        <?php else:?><div class="settings-info-strip compact"><span>i</span><p>Nu exista inca un test de conexiune. Salveaza credentialele, apoi ruleaza diagnosticul.</p></div><?php endif;?>

        <div class="emag-diagnostic-actions">
            <form method="post" action="<?=View::e(app_path('/settings/integrations/'.$key.'/test'))?>"><?=Csrf::field()?><button class="primary" type="submit">Testeaza conexiunea eMAG</button></form>
            <p>Nu sunt salvate si nu sunt afisate parola sau headerul Authorization. Rezultatul tehnic este inregistrat si in <b>Setari → Jurnal</b>.</p>
        </div>
    </section>
    <?php endif;?>

    <?php if($webhookInfo!==null):?>
    <section class="panel settings-pro-section settings-detail-card">
        <div class="settings-pro-section-head"><span class="settings-section-icon">⚡</span><div><h2>Webhook-uri automate</h2><p>Comenzile si produsele noi, actualizate sau sterse ajung imediat in ALFAMED CENTRAL.</p></div></div>
        <div class="settings-grid two"><label>Endpoint webhook<input readonly value="<?=View::e($webhookInfo['url'])?>"></label><label>Ultima configurare<input readonly value="<?=View::e($webhookInfo['configured_at']?:'Nu a fost configurat')?>"></label></div>
        <form method="post" action="<?=View::e(app_path('/settings/integrations/'.$key.'/webhooks'))?>" class="settings-actions"><?=Csrf::field()?><button class="primary">Configureaza / actualizeaza webhook-urile</button></form>
        <div class="settings-info-strip compact"><span>i</span><p>Necesita APP_URL public HTTPS. Pe localhost se foloseste sincronizarea automata periodica.</p></div>
    </section>
    <?php endif;?>
</div>
