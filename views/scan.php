<?php use App\Core\View; ?>
<div class="scan-page-shell" data-scan-page data-camera-enabled="<?=!empty($cameraEnabled)?'1':'0'?>" data-camera-facing="<?=View::e($cameraFacing??'environment')?>">
    <div class="page-head scan-page-head">
        <div>
            <div class="eyebrow">SCANARE COLETE</div>
            <h1>Scanare colete</h1>
            <p>Foloseste camera, scannerul USB sau introdu manual codul. Comanda se deschide imediat dupa identificare.</p>
        </div>
    </div>

    <div class="scan-pro-grid">
        <section class="panel scan-camera-card">
            <div class="scan-card-head"><div class="scan-card-icon">▣</div><div><h2>Scanare cu camera</h2><p>Incadreaza codul AWB sau QR. Comanda asociata se deschide automat.</p></div></div>
            <?php if(!empty($cameraEnabled)):?>
            <div class="scan-video-shell" data-scan-video-shell>
                <video id="scanVideo" playsinline muted></video>
                <div class="scan-frame"><span></span></div>
                <div class="scan-video-placeholder" id="scanVideoPlaceholder"><b>Camera este oprita</b><small>Apasa Porneste camera pentru scanare.</small></div>
            </div>
            <div class="scan-camera-actions">
                <button type="button" class="primary" id="scanCameraStart">Porneste camera</button>
                <button type="button" class="button-secondary" id="scanCameraStop" hidden>Opreste camera</button>
            </div>
            <div id="scanCameraStatus" class="scan-camera-status">Camera nu a fost pornita.</div>
            <?php else:?>
            <div class="empty-state">Scanarea cu camera este dezactivata din Setari → Module → AWB & Scanare colete.</div>
            <?php endif;?>
        </section>

        <section class="panel scan-manual-card">
            <div class="scan-card-head"><div class="scan-card-icon">⌨</div><div><h2>Scanner USB sau introducere manuala</h2><p>Scaneaza cu cititorul USB sau apasa in camp pentru a introduce codul manual.</p></div></div>
            <div class="scan-manual-form">
                <label class="scan-input-label"><span>AWB / comanda / ID extern / factura</span><input id="scanInput" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" placeholder="Scaneaza sau introdu codul..."></label>
                <button type="button" class="primary scan-manual-button" id="scanManualButton">Cauta comanda</button>
            </div>
            <div id="scanResult" class="scan-result scan-result-pro"><span class="scan-result-idle">Astept un cod pentru scanare.</span></div>
        </section>
    </div>

    <div class="scan-help-strip"><span>i</span><p>Pe telefon si tableta campul manual nu este selectat automat, astfel tastatura ramane inchisa pana cand apesi tu in camp.</p></div>
</div>
