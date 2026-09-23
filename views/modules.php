<?php use App\Core\Csrf; use App\Core\View; ?>
<?php $icons=['ai-product-import'=>'✦','awb-scan'=>'▣','couriers'=>'🚚','invoices-documents'=>'🧾','new-order-popup'=>'🔔','statistics'=>'▥','team-chat'=>'💬']; ?>
<div class="modules-page-shell">
    <div class="page-head modules-page-head">
        <div><div class="eyebrow">EXTENSII APLICATIE</div><h1>Module</h1><p>Activeaza, dezactiveaza si configureaza extensiile ALFAMED CENTRAL.</p></div>
    </div>

    <section class="panel modules-upload-panel">
        <div class="modules-section-head"><span class="settings-section-icon">＋</span><div><h2>Instaleaza un modul</h2><p>Incarca un ZIP compatibil. Modulul este instalat dezactivat si poate fi verificat inainte de activare.</p></div></div>
        <?php if($zipAvailable):?><form method="post" action="<?=View::e(app_path('/modules/upload'))?>" enctype="multipart/form-data" class="modules-upload-form"><?=Csrf::field()?><label class="modules-file-field"><span>Fisier ZIP</span><input type="file" name="module_zip" accept=".zip,application/zip" required></label><button class="primary">Incarca modul</button></form><?php else:?><div class="flash error">Extensia PHP ZipArchive nu este activa. Poti instala module copiind folderul in <code>/modules</code>.</div><?php endif;?>
    </section>

    <section class="panel modules-list-panel">
        <div class="modules-section-head"><span class="settings-section-icon">▦</span><div><h2>Module disponibile</h2><p>Fiecare modul are status, versiune si actiunile lui intr-un singur card.</p></div></div>
        <?php if($moduleList):?><div class="modules-pro-grid">
            <?php foreach($moduleList as $m):?><article class="module-pro-card">
                <div class="module-pro-head">
                    <span class="module-pro-icon"><?=View::e($icons[$m['slug']]??'▦')?></span>
                    <div class="module-pro-heading"><div class="module-pro-name-row"><h3><?=View::e($m['name'])?></h3><span class="module-version">v<?=View::e($m['version'])?></span></div><p><?=View::e($m['description'])?></p></div>
                    <span class="module-state-badge <?=$m['enabled']?'on':'off'?>"><i></i><?=$m['enabled']?'Activ':'Inactiv'?></span>
                </div>
                <div class="module-pro-foot"><div class="module-pro-meta-inline"><span><?=View::e($m['author']?:'Modul local')?></span><span>·</span><code><?=View::e($m['slug'])?></code></div><div class="module-pro-buttons"><?php if(!$m['installed']):?><form method="post" action="<?=View::e(app_path('/modules/'.$m['slug'].'/install'))?>"><?=Csrf::field()?><button>Instaleaza</button></form><?php elseif($m['enabled']):?><form method="post" action="<?=View::e(app_path('/modules/'.$m['slug'].'/disable'))?>"><?=Csrf::field()?><button>Dezactiveaza</button></form><?php else:?><form method="post" action="<?=View::e(app_path('/modules/'.$m['slug'].'/enable'))?>"><?=Csrf::field()?><button class="primary">Activeaza</button></form><?php endif;?><?php if($m['enabled']):?><a class="button-secondary" href="<?=View::e(app_path('/settings/modules/'.$m['slug']))?>">Setari</a><?php endif;?></div></div>
            </article><?php endforeach;?>
        </div><?php else:?><div class="empty-state">Nu exista module in folderul <code>/modules</code>.</div><?php endif;?>
    </section>
</div>
