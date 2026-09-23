<?php use App\Core\Csrf; use App\Core\View;
$isEdit=is_array($editUser);$selected=$isEdit?(json_decode((string)($editUser['permissions_json']??'[]'),true)?:[]):[];
?>
<div class="user-edit-shell">
    <div class="page-head user-edit-head"><div><a class="settings-back" href="<?=View::e(app_path('/users'))?>">← Utilizatori</a><div class="eyebrow">ADMINISTRARE CONT</div><h1><?=$isEdit?'Editeaza utilizator':'Utilizator nou'?></h1><p>Administratorul stabileste identitatea, rolul, starea contului si permisiunile disponibile.</p></div><?php if($isEdit):?><a class="button-secondary" href="<?=View::e(app_path('/users/activity?user_id='.$editUser['id']))?>">Vezi activitatea</a><?php endif;?></div>
    <form class="user-editor-pro" method="post" action="<?=View::e(app_path($isEdit?'/users/'.$editUser['id']:'/users/new'))?>">
        <?=Csrf::field()?>
        <section class="panel user-editor-section">
            <div class="user-editor-section-head"><span class="settings-section-icon">◎</span><div><h2>Date utilizator</h2><p>Informatii de autentificare si rolul principal in platforma.</p></div></div>
            <div class="form-grid two user-editor-fields">
                <label><span>Nume complet</span><input name="name" required value="<?=View::e($editUser['name']??'')?>"></label>
                <label><span>Email</span><input type="email" name="email" required value="<?=View::e($editUser['email']??'')?>"></label>
                <label><span>Functie / titlu</span><input name="job_title" value="<?=View::e($editUser['job_title']??'')?>" placeholder="Ex. Operator comenzi"></label>
                <label><span>Rol</span><select name="role" id="userRole"><?php foreach(['admin'=>'Administrator','manager'=>'Manager','operator'=>'Operator','warehouse'=>'Depozit','viewer'=>'Vizualizare'] as $v=>$label):?><option value="<?=$v?>" <?=($editUser['role']??'operator')===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label>
                <label><span>Parola <?=$isEdit?'<small>optional</small>':''?></span><input type="password" name="password" <?=$isEdit?'':'required'?> minlength="10" placeholder="<?=$isEdit?'Lasa gol pentru a pastra parola':'Minimum 10 caractere'?>"></label>
                <label class="settings-check-card user-active-card"><input type="checkbox" name="is_active" <?=!$isEdit||(bool)($editUser['is_active']??1)?'checked':''?>><span><b>Cont activ</b><small>Daca este dezactivat, utilizatorul este deconectat si nu se mai poate autentifica.</small></span></label>
            </div>
        </section>
        <section class="panel user-editor-section">
            <div class="user-editor-section-head"><span class="settings-section-icon">⌘</span><div><h2>Acces la functii</h2><p>Administratorul are acces complet. Pentru celelalte roluri poti controla exact sectiunile disponibile.</p></div></div>
            <div class="permission-grid permission-grid-pro"><?php foreach($permissionLabels as $key=>$label):?><label class="permission-card"><input type="checkbox" name="permissions[]" value="<?=View::e($key)?>" <?=in_array($key,$selected,true)?'checked':''?>><span><b><?=View::e($label)?></b><small><?=View::e($key)?></small></span></label><?php endforeach;?></div>
        </section>
        <div class="settings-sticky-actions user-editor-actions"><a class="button-secondary" href="<?=View::e(app_path('/users'))?>">Renunta</a><button class="primary"><?=$isEdit?'Salveaza utilizator':'Creeaza utilizator'?></button></div>
    </form>
</div>
