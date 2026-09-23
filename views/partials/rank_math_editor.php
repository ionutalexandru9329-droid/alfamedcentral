<?php
use App\Core\View;
$rankMath=is_array($rankMath??null)?$rankMath:[];
$seoScore=isset($seoScore)&&$seoScore!==''&&$seoScore!==null?(int)$seoScore:null;
$robots=array_values(array_filter(array_map('strval',(array)($rankMath['robots']??[]))));if(!$robots)$robots=['index'];
$scoreClass=$seoScore===null?'unknown':($seoScore>=80?'good':($seoScore>=50?'medium':'low'));
?>
<div class="rankmath-editor" data-rankmath-editor>
    <div class="rankmath-summary">
        <div class="rankmath-score <?=$scoreClass?>"><span><?=($seoScore===null?'--':(int)$seoScore)?></span><small>SEO score</small></div>
        <div><strong>Rank Math SEO</strong><p>Scorul este preluat din magazinul sursa. Campurile modificate aici sunt trimise magazinelor selectate la salvare.</p></div>
    </div>
    <div class="rankmath-tabs" role="tablist">
        <button type="button" class="active" data-rank-tab="general">General</button>
        <button type="button" data-rank-tab="advanced">Avansat</button>
        <button type="button" data-rank-tab="social">Social</button>
    </div>
    <div class="rankmath-tab-panel active" data-rank-panel="general">
        <div class="form-grid two editor-fields">
            <label>Titlu SEO<input name="seo_title" value="<?=View::e((string)($rankMath['title']??''))?>"><small>Meta title Rank Math.</small></label>
            <label>Focus keyword<input name="seo_focus_keyword" value="<?=View::e((string)($rankMath['focus_keyword']??''))?>"><small>Poti separa mai multe expresii prin virgula.</small></label>
            <label class="full-span">Meta descriere<textarea name="seo_description" rows="4"><?=View::e((string)($rankMath['description']??''))?></textarea></label>
        </div>
    </div>
    <div class="rankmath-tab-panel" data-rank-panel="advanced">
        <div class="form-grid two editor-fields">
            <label class="full-span">URL canonical<input type="url" name="seo_canonical_url" value="<?=View::e((string)($rankMath['canonical_url']??''))?>" placeholder="https://..."></label>
            <label class="settings-check-card"><input type="checkbox" name="seo_pillar_content" <?=((string)($rankMath['pillar_content']??'')==='on')?'checked':''?>><span><b>Continut pilon</b><small>Marcheaza produsul ca Pillar Content in Rank Math.</small></span></label>
            <div class="rankmath-robots full-span"><span>Robots meta</span><div class="rankmath-check-grid">
                <?php foreach(['index'=>'Index','noindex'=>'NoIndex','nofollow'=>'NoFollow','noarchive'=>'NoArchive','nosnippet'=>'NoSnippet','noimageindex'=>'NoImageIndex'] as $key=>$label):?><label><input type="checkbox" name="seo_robots[]" value="<?=View::e($key)?>" <?=in_array($key,$robots,true)?'checked':''?>> <span><?=View::e($label)?></span></label><?php endforeach;?>
            </div></div>
        </div>
    </div>
    <div class="rankmath-tab-panel" data-rank-panel="social">
        <div class="rankmath-social-grid">
            <section><h4>Facebook / OpenGraph</h4><label>Titlu<input name="seo_facebook_title" value="<?=View::e((string)($rankMath['facebook_title']??''))?>"></label><label>Descriere<textarea name="seo_facebook_description" rows="3"><?=View::e((string)($rankMath['facebook_description']??''))?></textarea></label><label>Imagine sociala<input type="url" name="seo_facebook_image" value="<?=View::e((string)($rankMath['facebook_image']??''))?>" placeholder="https://..."></label></section>
            <section><h4>X / Twitter</h4><label class="settings-check-card rankmath-use-facebook"><input type="checkbox" name="seo_twitter_use_facebook" <?=((string)($rankMath['twitter_use_facebook']??'')==='on')?'checked':''?>><span><b>Foloseste datele Facebook</b><small>Pastreaza aceeasi prezentare sociala.</small></span></label><label>Tip card<select name="seo_twitter_card_type"><?php foreach(['summary_large_image'=>'Summary large image','summary_card'=>'Summary'] as $key=>$label):?><option value="<?=$key?>" <?=((string)($rankMath['twitter_card_type']??'summary_large_image')===$key)?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><label>Titlu<input name="seo_twitter_title" value="<?=View::e((string)($rankMath['twitter_title']??''))?>"></label><label>Descriere<textarea name="seo_twitter_description" rows="3"><?=View::e((string)($rankMath['twitter_description']??''))?></textarea></label><label>Imagine sociala<input type="url" name="seo_twitter_image" value="<?=View::e((string)($rankMath['twitter_image']??''))?>" placeholder="https://..."></label></section>
        </div>
    </div>
</div>
