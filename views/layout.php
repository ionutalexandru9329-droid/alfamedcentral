<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\ModuleManager;
use App\Core\View;
use App\Services\SettingService;
$title=$title??'ALFAMED CENTRAL';
$moduleNav=[];$moduleManager=null;$notificationModuleEnabled=false;$teamModuleEnabled=false;$headerModuleAllow=null;$headerNavAllow=null;
if(Auth::check()){
    try{
        $db=Database::connection();$moduleManager=new ModuleManager($db);$moduleNav=$moduleManager->navItems();
        $settingService=new SettingService($db);
        $headerLinksRaw=(string)$settingService->get('ui.header_links','');
        if($headerLinksRaw!=='')$headerNavAllow=array_values(array_filter(explode(',',$headerLinksRaw)));
        else{$headerRaw=(string)$settingService->get('ui.header_modules','*');$headerModuleAllow=$headerRaw==='*'?null:array_values(array_filter(explode(',',$headerRaw)));}
        $st=$db->query("SELECT slug,enabled FROM modules WHERE slug IN ('new-order-popup','team-chat')");foreach($st->fetchAll() as $mr){if($mr['slug']==='new-order-popup')$notificationModuleEnabled=(bool)$mr['enabled'];if($mr['slug']==='team-chat')$teamModuleEnabled=(bool)$mr['enabled'];}
    }catch(Throwable){}
}
$appName=(string)Env::get('APP_NAME','ALFAMED CENTRAL');
$assetVersion=trim((string)@file_get_contents(dirname(__DIR__).'/VERSION'));
if($assetVersion==='')$assetVersion='dev';
?>
<!doctype html>
<html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#121a2d"><link rel="manifest" href="<?=View::e(app_path('/manifest.webmanifest'))?>"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="csrf-token" content="<?=View::e(Csrf::token())?>"><title><?=View::e($title)?> · <?=View::e($appName)?></title><link rel="stylesheet" href="<?=View::e(app_path('/assets/app.css').'?v='.rawurlencode($assetVersion))?>"></head>
<body data-authenticated="<?=Auth::check()?'1':'0'?>" data-router-url="<?=View::e(app_front_controller())?>" data-service-worker-url="<?=View::e(app_path('/push-sw.js'))?>">
<?php if(Auth::check()):?>
<header class="app-header">
    <a class="brand" href="<?=View::e(app_path('/'))?>">ALFAMED <span>CENTRAL</span></a>
    <button type="button" class="header-menu-toggle" id="headerMenuToggle" aria-controls="headerCollapse" aria-expanded="false" aria-label="Deschide meniul"><span></span><span></span><span></span></button>
    <div class="header-collapse" id="headerCollapse">
        <nav class="app-nav" aria-label="Navigare principala">
            <?php if(Auth::can('dashboard.view')):?><a href="<?=View::e(app_path('/'))?>">Dashboard</a><?php endif;?>
            <?php if(Auth::can('orders.view')):?><a href="<?=View::e(app_path('/orders'))?>">Comenzi</a><?php endif;?>
            <?php if(Auth::can('products.view')):?><a href="<?=View::e(app_path('/products'))?>">Produse</a><?php endif;?>
            <?php if(Auth::can('modules.manage')):?><a href="<?=View::e(app_path('/modules'))?>">Module</a><?php endif;?>
            <?php foreach($moduleNav as $layoutNavItem):?><?php $layoutModuleSlug=(string)($layoutNavItem['_module_slug']??'');$layoutNavKey=$layoutModuleSlug.'|'.(string)($layoutNavItem['url']??'');if($headerNavAllow!==null&&!in_array($layoutNavKey,$headerNavAllow,true))continue;if($headerNavAllow===null&&$headerModuleAllow!==null&&!in_array($layoutModuleSlug,$headerModuleAllow,true))continue;$layoutPermission=(string)($layoutNavItem['permission']??'');$layoutAllowed=$layoutPermission===''||($layoutPermission==='admin'?Auth::isAdmin():Auth::can($layoutPermission));if(!$layoutAllowed)continue;?><a href="<?=View::e(app_path($layoutNavItem['url']??'#'))?>"><?=View::e($layoutNavItem['label']??'Modul')?></a><?php endforeach;?>
            <?php if(Auth::can('settings.manage')):?><a href="<?=View::e(app_path('/settings'))?>">Setari</a><?php endif;?>
        </nav>
        <div class="header-tools">
            <?php if($notificationModuleEnabled):?><div class="notification-center"><button type="button" id="notificationBell" class="notification-bell" title="Notificari" aria-expanded="false"><span aria-hidden="true">🔔</span><b id="notificationBadge" class="notification-badge" hidden>0</b></button><section id="notificationDropdown" class="notification-dropdown" hidden><div class="notification-dropdown-head"><div><strong>Notificari</strong><small id="notificationUnreadText">Nicio notificare noua</small></div><button type="button" id="notificationMarkAll" class="text-button">Marcheaza toate</button></div><div id="notificationList" class="notification-list"><div class="notification-empty">Se incarca...</div></div><div class="notification-dropdown-foot"><button type="button" id="notificationDesktopBtn" class="text-button">Activeaza notificari push</button><?php if(Auth::can('settings.manage')):?><a href="<?=View::e(app_path('/settings/modules/new-order-popup'))?>">Setari</a><?php endif;?></div></section></div><?php endif;?>
            <?php if($teamModuleEnabled):?><a class="profile-link" href="<?=View::e(app_path('/profile'))?>" title="Profilul meu"><?php if(!empty(Auth::user()['avatar_path'])):?><img class="avatar-mini avatar-mini-image" src="<?=View::e(app_path('/profile/avatar/'.(int)Auth::user()['id']))?>" alt=""><?php else:?><span class="avatar-mini"><?=View::e(function_exists('mb_substr')?mb_strtoupper(mb_substr((string)(Auth::user()['name']??'U'),0,1)):strtoupper(substr((string)(Auth::user()['name']??'U'),0,1)))?></span><?php endif;?><span><?=View::e(Auth::user()['name']??'Profil')?></span></a><?php endif;?>
            <form method="post" action="<?=View::e(app_path('/logout'))?>" class="logout"><?=Csrf::field()?><button>Logout</button></form>
        </div>
    </div>
</header>
<?php endif;?>
<main class="<?=Auth::check()?'':'auth-main'?>">
    <?php if($m=flash('success')):?><div class="flash success"><?=View::e($m)?></div><?php endif;?>
    <?php if($m=flash('error')):?><div class="flash error"><?=View::e($m)?></div><?php endif;?>
    <?php require $viewFile;?>
</main>
<?php if(Auth::check() && $notificationModuleEnabled):?>
<div id="orderNotification" class="order-notification" hidden><div class="notification-top"><span class="notification-dot"></span><strong id="notificationTitle">Notificare</strong><button type="button" id="notificationClose" aria-label="Inchide">×</button></div><div id="notificationMessage" class="notification-message"></div><div class="notification-actions"><button type="button" id="notificationDismiss">Marcheaza citita</button><a id="notificationOpen" class="primary-link" href="#">Deschide</a></div></div>
<?php endif;?>
<?php if(Auth::check() && $moduleManager):?><?php try{$moduleManager->renderHook('layout.footer',['user'=>Auth::user()]);}catch(Throwable){}?><?php endif;?>
<div id="imagePreviewModal" class="image-modal" hidden aria-hidden="true">
    <div class="image-modal-backdrop"></div>
    <div class="image-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="imagePreviewCaption">
        <div class="image-modal-head">
            <div class="image-modal-heading"><span class="image-modal-kicker">PREVIEW PRODUS</span></div>
            <button type="button" class="image-modal-close" aria-label="Inchide">×</button>
        </div>
        <div class="image-modal-body">
            <div id="imagePreviewCaption" class="image-modal-caption"></div>
            <div class="image-modal-media" id="imagePreviewDropzone"><img id="imagePreviewFull" src="" alt="Imagine produs"><div id="imagePreviewEmpty" class="image-modal-empty" hidden><span class="image-modal-empty-icon" aria-hidden="true">▧</span><strong>Imagine indisponibila</strong><small>ALFAMED CENTRAL verifica automat datele eMAG. Daca imaginea lipseste, o poti incarca manual.</small></div></div>
        </div>
        <div id="imagePreviewTools" class="image-modal-tools" hidden>
            <div class="image-modal-actions">
                <button type="button" id="imagePreviewResync" class="image-modal-action image-modal-action-secondary"><span aria-hidden="true">↻</span><span>Resincronizeaza</span></button>
                <label class="image-modal-action image-modal-upload"><span aria-hidden="true">＋</span><span>Incarca poza</span><input type="file" id="imagePreviewUpload" accept="image/jpeg,image/png,image/webp,image/gif" hidden></label>
            </div>
            <span id="imagePreviewStatus" class="image-modal-status" aria-live="polite"></span>
        </div>
    </div>
</div>
<script src="<?=View::e(app_path('/assets/app.js').'?v='.rawurlencode($assetVersion))?>"></script></body></html>
