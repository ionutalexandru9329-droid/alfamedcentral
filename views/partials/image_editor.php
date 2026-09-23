<?php use App\Core\View;
$imageValue=$imageValue??'';
$imageMode=$imageMode??'url';
?>
<div class="product-image-editor" data-product-image-editor>
    <div class="section-mini-head"><strong>Imagini produs</strong><small>Alege modul in care vrei sa trimiti imaginile catre WooCommerce.</small></div>
    <div class="image-mode-tabs" role="radiogroup" aria-label="Mod imagini">
        <label class="image-mode-card"><input type="radio" name="image_mode" value="url" <?=$imageMode==='url'?'checked':''?> data-image-mode><span><b>🔗 URL imagini</b><small>Cate un link public pe fiecare rand.</small></span></label>
        <label class="image-mode-card"><input type="radio" name="image_mode" value="upload" <?=$imageMode==='upload'?'checked':''?> data-image-mode><span><b>⬆ Incarca imagini</b><small>JPG, PNG, WEBP sau GIF, max. 10 MB/fisier.</small></span></label>
    </div>
    <div class="image-mode-panel" data-image-panel="url" <?=$imageMode==='url'?'':'hidden'?>>
        <label><span>Link-uri imagini</span><textarea name="images" rows="6" placeholder="https://.../produs-1.jpg&#10;https://.../produs-2.jpg"><?=View::e($imageValue)?></textarea></label>
    </div>
    <div class="image-mode-panel upload-panel" data-image-panel="upload" <?=$imageMode==='upload'?'':'hidden'?>>
        <label class="product-upload-drop"><input type="file" name="product_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-product-images-input><span class="upload-icon">⬆</span><b>Alege imaginile</b><small>Poti selecta mai multe fisiere simultan.</small></label>
        <div class="product-upload-list" data-product-upload-list><span class="muted">Nicio imagine selectata.</span></div>
        <p class="form-help">WooCommerce trebuie sa poata accesa URL-ul public al aplicatiei pentru a prelua imaginile incarcate. Pe localhost foloseste varianta URL imagini.</p>
    </div>
</div>
