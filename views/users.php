<?php use App\Core\Auth; use App\Core\Csrf; use App\Core\View;
$currentId=(int)(Auth::user()['id']??0);
$roleLabels=['admin'=>'Administrator','manager'=>'Manager','operator'=>'Operator','warehouse'=>'Depozit','viewer'=>'Vizualizare'];
$summary=$summary??['total'=>count($users),'active'=>0,'online'=>0,'suspended'=>0];
?>
<div class="users-admin-shell">
    <div class="page-head users-admin-head">
        <div><div class="eyebrow">ADMINISTRARE ECHIPA</div><h1>Utilizatori</h1><p>Conturi interne, roluri, acces, stare si activitate in ALFAMED CENTRAL.</p></div>
        <div class="users-admin-head-actions"><a class="button-secondary" href="<?=View::e(app_path('/users/activity'))?>">Jurnal activitate</a><a class="primary-link" href="<?=View::e(app_path('/users/new'))?>">+ Utilizator nou</a></div>
    </div>

    <div class="users-admin-kpis">
        <article><span>Total conturi</span><strong><?=(int)$summary['total']?></strong><small>utilizatori vizibili</small></article>
        <article><span>Active</span><strong><?=(int)$summary['active']?></strong><small>pot folosi platforma</small></article>
        <article><span>Online acum</span><strong><?=(int)$summary['online']?></strong><small>activi in ultimele 90 sec.</small></article>
        <article><span>Suspendate</span><strong><?=(int)$summary['suspended']?></strong><small>acces blocat</small></article>
    </div>

    <section class="panel users-admin-panel">
        <div class="users-panel-head"><div><h2>Echipa ALFAMED CENTRAL</h2><p>Doar administratorii pot vedea si administra aceasta pagina.</p></div><span class="admin-only-chip">Acces administrator</span></div>
        <?php if(!$users):?><div class="empty-state">Nu exista utilizatori.</div><?php else:?>
        <div class="users-admin-grid">
            <?php foreach($users as $u):?>
            <?php $ts=!empty($u['last_active_at'])?strtotime((string)$u['last_active_at']):false;$online=(bool)$u['is_active']&&$ts!==false&&(time()-$ts)<=90;$isSelf=(int)$u['id']===$currentId;$initial=function_exists('mb_substr')?mb_strtoupper(mb_substr((string)$u['name'],0,1)):strtoupper(substr((string)$u['name'],0,1)); ?>
            <article class="user-admin-card <?=$isSelf?'is-self':''?>">
                <div class="user-admin-card-top">
                    <div class="user-admin-avatar-wrap">
                        <?php if(!empty($u['avatar_path'])):?><img class="user-admin-avatar" src="<?=View::e(app_path('/profile/avatar/'.$u['id']))?>" alt=""><?php else:?><span class="user-admin-avatar user-admin-avatar-fallback"><?=View::e($initial)?></span><?php endif;?>
                        <i class="user-presence-dot <?=$online?'online':((bool)$u['is_active']?'offline':'suspended')?>"></i>
                    </div>
                    <div class="user-admin-identity"><div class="user-admin-name-row"><h3><?=View::e($u['name'])?></h3><?php if($isSelf):?><span class="you-chip">Tu</span><?php endif;?></div><p><?=View::e($u['email'])?></p><?php if(!empty($u['job_title'])):?><span><?=View::e($u['job_title'])?></span><?php endif;?></div>
                    <span class="user-state-chip <?=!(bool)$u['is_active']?'suspended':($online?'online':'offline')?>"><?=!(bool)$u['is_active']?'Suspendat':($online?'Online':'Offline')?></span>
                </div>
                <div class="user-admin-meta">
                    <div><span>Rol</span><strong><?=View::e($roleLabels[$u['role']]??$u['role'])?></strong></div>
                    <div><span>Ultima activitate</span><strong><?=View::e($u['last_active_at']?date('d.m.Y H:i',strtotime((string)$u['last_active_at'])):'—')?></strong></div>
                    <div><span>Creat</span><strong><?=View::e(!empty($u['created_at'])?date('d.m.Y',strtotime((string)$u['created_at'])):'—')?></strong></div>
                </div>
                <div class="user-admin-actions">
                    <a class="button-secondary" href="<?=View::e(app_path('/users/'.$u['id']))?>">Editeaza</a>
                    <a class="button-secondary" href="<?=View::e(app_path('/users/activity?user_id='.$u['id']))?>">Activitate</a>
                    <?php if(!$isSelf):?>
                    <form method="post" action="<?=View::e(app_path('/users/'.$u['id'].'/toggle'))?>" onsubmit="return confirm('<?=((bool)$u['is_active'])?'Suspendi acest utilizator? Accesul lui va fi blocat imediat.':'Reactivezi acest utilizator?'?>')"><?=Csrf::field()?><input type="hidden" name="set_active" value="<?=(bool)$u['is_active']?0:1?>"><button class="<?=((bool)$u['is_active'])?'warning-outline':'success-outline'?>" type="submit"><?=((bool)$u['is_active'])?'Suspenda':'Reactiveaza'?></button></form>
                    <form method="post" action="<?=View::e(app_path('/users/'.$u['id'].'/delete'))?>" onsubmit="return confirm('Stergi acest utilizator? Contul nu va mai putea fi folosit, dar jurnalul de activitate ramane pastrat.')"><?=Csrf::field()?><button class="danger-outline" type="submit">Sterge</button></form>
                    <?php endif;?>
                </div>
            </article>
            <?php endforeach;?>
        </div>
        <?php endif;?>
    </section>
</div>
