<?php use App\Core\Csrf; use App\Core\View; ?>
<div class="profile-page-shell">
    <div class="page-head profile-page-head"><div><div class="eyebrow">CONT UTILIZATOR</div><h1>Profilul meu</h1><p>Datele contului, securitate si fotografia folosita in header si chat.</p></div></div>
    <form class="profile-pro-form" method="post" enctype="multipart/form-data" action="<?=View::e(app_path('/profile'))?>"><?=Csrf::field()?>
        <section class="panel profile-identity-card">
            <div class="profile-compact-summary">
                <div class="profile-avatar-wrap"><?php if(!empty($profile['avatar_path'])):?><img class="profile-avatar-image profile-avatar-compact" src="<?=View::e(app_path('/profile/avatar/'.$profile['id']))?>" alt="Poza <?=View::e($profile['name'])?>"><?php else:?><span class="avatar-circle profile-avatar-fallback"><?=View::e(function_exists('mb_substr')?mb_strtoupper(mb_substr((string)$profile['name'],0,1)):strtoupper(substr((string)$profile['name'],0,1)))?></span><?php endif;?></div>
                <div class="profile-identity-copy"><h2><?=View::e($profile['name'])?></h2><p><?=View::e($profile['role'])?><?php if(!empty($profile['job_title'])):?> · <?=View::e($profile['job_title'])?><?php endif;?></p></div>
                <label class="avatar-upload-button profile-upload-compact"><span>📷</span> Schimba poza<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" hidden></label>
            </div>
            <small class="profile-photo-help">JPG, PNG sau WEBP · maximum 5 MB.</small>
        </section>

        <section class="panel profile-details-card"><div class="profile-section-head"><div><h2>Date cont</h2><p>Informatiile afisate in aplicatie si in colaborarea interna.</p></div></div><div class="form-grid two"><label>Nume<input name="name" required value="<?=View::e($profile['name'])?>"></label><label>Email<input type="email" name="email" required value="<?=View::e($profile['email'])?>"></label><label>Functie / titlu<input name="job_title" value="<?=View::e($profile['job_title']??'')?>"></label><label>Ultima activitate<input value="<?=View::e($profile['last_active_at']??'')?>" disabled></label></div></section>

        <?php if(!empty($notificationModuleEnabled)||!empty($chatModuleEnabled)):?>
        <section class="panel profile-push-card">
            <div class="profile-section-head"><div><h2>Notificari push</h2><p>Alege ce notificari vrei sa primesti pe dispozitivele tale. Fiecare dispozitiv trebuie activat o singura data.</p></div><span class="push-device-count"><?= (int)($pushSubscriptions??0) ?> dispozitive</span></div>
            <div class="profile-push-preferences">
                <?php if(!empty($notificationModuleEnabled)):?>
                <label class="switchline"><input type="checkbox" name="push_orders_enabled" <?=!isset($profile['push_orders_enabled'])||(int)$profile['push_orders_enabled']===1?'checked':''?>> Comenzi noi</label>
                <label class="switchline"><input type="checkbox" name="push_stock_enabled" <?=!isset($profile['push_stock_enabled'])||(int)$profile['push_stock_enabled']===1?'checked':''?>> Stoc aproape epuizat / epuizat</label>
                <?php endif;?>
                <?php if(!empty($chatModuleEnabled)):?><label class="switchline"><input type="checkbox" name="push_chat_enabled" <?=!isset($profile['push_chat_enabled'])||(int)$profile['push_chat_enabled']===1?'checked':''?>> Mesaje noi in chat</label><?php endif;?>
            </div>
            <?php if(!empty($notificationModuleEnabled)||!empty($chatModuleEnabled)):?><div class="profile-push-device-actions"><button type="button" id="profilePushBtn" class="button-secondary"><?=!empty($pushSubscriptions)?'Notificari push active':'Activeaza push pe acest dispozitiv'?></button><button type="button" id="profilePushTestBtn" class="button-secondary">Trimite notificare test</button><small><?=!empty($pushSupported)?'Server Web Push disponibil.':'Serverul nu raporteaza suport Web Push; verifica extensia OpenSSL/PHP.'?></small></div><?php endif;?>
            <?php if(!empty($notificationModuleEnabled)):?><div class="push-sync-state <?=!empty($cronActive)?'ok':'warning'?>"><strong>Sincronizare comenzi in fundal: <?=!empty($cronActive)?'CRON activ':'CRON neconfirmat / oprit'?></strong><small><?php if(!empty($cronActive)):?>Comenzile noi pot genera push chiar daca platforma nu este deschisa.<?php else:?>Pentru push la comenzi cand browserul este inchis, ruleaza <code>bin/auto-sync.php</code> prin cPanel CRON o data pe minut.<?php endif;?><?php if(!empty($cronLastSeen)):?> Ultima confirmare: <?=View::e($cronLastSeen)?>.<?php endif;?></small></div><?php endif;?>
        </section>
        <?php endif;?>

        <section class="panel profile-security-card"><div class="profile-section-head"><div><h2>Securitate</h2><p>Lasa campurile goale daca nu doresti sa schimbi parola.</p></div></div><div class="form-grid two"><label>Parola curenta<input type="password" name="current_password" autocomplete="current-password"></label><label>Parola noua<input type="password" name="new_password" minlength="10" autocomplete="new-password"><small>Minimum 10 caractere.</small></label></div></section>
        <div class="profile-actions"><button class="primary" type="submit">Salveaza profilul</button></div>
    </form>
</div>
