<?php
/** @var string $termName */
/** @var string $termLabel */
/** @var string $termValue */
/** @var array $termOptions */
use App\Core\View;
$listId='terms_'.preg_replace('/[^a-z0-9_]+/i','_',($termName??'term')).'_'.substr(sha1(($termName??'').($termLabel??'')),0,6);
?>
<label class="term-field"><span class="field-label"><?=View::e($termLabel??'Termeni')?></span>
<div class="term-editor" data-term-editor>
    <input type="hidden" name="<?=View::e($termName)?>" value="<?=View::e($termValue??'')?>" data-term-value>
    <div class="term-chips" data-term-chips></div>
    <div class="term-input-row"><input type="text" list="<?=View::e($listId)?>" placeholder="Alege sau scrie un termen nou..." data-term-input><button type="button" data-term-add>Adauga</button></div>
    <datalist id="<?=View::e($listId)?>"><?php foreach(($termOptions??[]) as $opt):?><option value="<?=View::e($opt['name']??'')?>" label="<?=View::e(implode(' + ',array_map(fn($x)=>$x==='univera'?'Univera':'Alfamed',(array)($opt['channels']??[]))))?>"><?php endforeach;?></datalist>
</div>
<small><?=View::e($termHelp??'Poti selecta din lista existenta sau scrie un termen nou. Daca nu exista pe magazinul ales, va fi creat automat.')?></small>
</label>
